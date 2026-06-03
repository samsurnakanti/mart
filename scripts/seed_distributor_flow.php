<?php
declare(strict_types=1);

require __DIR__ . '/../includes/functions.php';

$pdo = db();
$password = password_hash('demo123', PASSWORD_DEFAULT);
$product = $pdo->query("SELECT * FROM products WHERE is_active = 1 AND bv_points > 0 ORDER BY bv_points DESC, id LIMIT 1")->fetch();
if (!$product) {
    throw new RuntimeException('No active product with BV points was found.');
}

$pdo->beginTransaction();
try {
    $findUser = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $createUser = $pdo->prepare('
        INSERT INTO users
            (name,email,phone,password_hash,role,sponsor_distributor_id,pan_number,bank_account_number,bank_ifsc,id_proof_type,gst_number,kyc_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $updateSponsor = $pdo->prepare('UPDATE users SET sponsor_distributor_id = ?, role = ?, kyc_status = ? WHERE id = ?');

    $ensureUser = function (array $data) use ($pdo, $password, $findUser, $createUser, $updateSponsor): array {
        $findUser->execute([$data['email']]);
        $user = $findUser->fetch();
        if ($user) {
            $updateSponsor->execute([
                $data['sponsor_id'],
                $data['role'],
                $data['role'] === 'distributor' ? 'approved' : ($user['kyc_status'] ?? 'not_submitted'),
                $user['id'],
            ]);
            if ($data['role'] === 'distributor') {
                assign_distributor_uid((int)$user['id']);
            }
            $findUser->execute([$data['email']]);
            return $findUser->fetch();
        }

        $createUser->execute([
            $data['name'],
            $data['email'],
            $data['phone'],
            $password,
            $data['role'],
            $data['sponsor_id'],
            'ABCDE' . str_pad((string)$data['no'], 4, '0', STR_PAD_LEFT) . 'F',
            '90000000' . str_pad((string)$data['no'], 4, '0', STR_PAD_LEFT),
            'VMCM0001234',
            'Aadhaar Card',
            '',
            $data['role'] === 'distributor' ? 'approved' : 'not_submitted',
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($data['role'] === 'distributor') {
            assign_distributor_uid($id);
        }
        $findUser->execute([$data['email']]);
        return $findUser->fetch();
    };

    $created = [];
    $sponsorId = null;
    for ($i = 1; $i <= 8; $i++) {
        $user = $ensureUser([
            'no' => $i,
            'name' => 'Demo Distributor Level ' . $i,
            'email' => 'demo.distributor' . $i . '@vmcmarts.local',
            'phone' => '90010000' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
            'role' => 'distributor',
            'sponsor_id' => $sponsorId,
        ]);
        $created[] = $user;
        $sponsorId = (int)$user['id'];
    }

    for ($i = 9; $i <= 20; $i++) {
        $sponsorIndex = (($i - 9) % 8);
        $created[] = $ensureUser([
            'no' => $i,
            'name' => 'Demo Customer ' . $i,
            'email' => 'demo.customer' . $i . '@vmcmarts.local',
            'phone' => '90010000' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
            'role' => 'user',
            'sponsor_id' => (int)$created[$sponsorIndex]['id'],
        ]);
    }

    $findOrder = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? AND shipping_address LIKE '%Distributor demo flow%' LIMIT 1");
    $insertOrder = $pdo->prepare('
        INSERT INTO orders
            (user_id,subtotal,tax_total,redeemable_points,points_used,grand_total,points_earned,status,shipping_name,shipping_phone,shipping_address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ');
    $insertItem = $pdo->prepare('
        INSERT INTO order_items
            (order_id,product_id,product_name,qty,unit_price,tax_amount,points_value,product_type,bv_points)
        VALUES (?,?,?,?,?,?,?,?,?)
    ');

    $completedOrders = 0;
    foreach ($created as $user) {
        $findOrder->execute([(int)$user['id']]);
        if ($findOrder->fetch()) {
            continue;
        }

        $qty = 1;
        $subtotal = (float)$product['selling_price'] * $qty;
        $insertOrder->execute([
            (int)$user['id'],
            $subtotal,
            0,
            0,
            0,
            $subtotal,
            0,
            'Completed',
            $user['name'],
            $user['phone'],
            'Distributor demo flow, Khammam',
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $insertItem->execute([
            $orderId,
            (int)$product['id'],
            $product['name'],
            $qty,
            $product['selling_price'],
            0,
            (int)($product['discount_points'] ?? 0),
            $product['product_type'],
            (int)$product['bv_points'],
        ]);
        $order = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        credit_order_bv($pdo, $order->fetch());
        $completedOrders++;
    }

    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    throw $ex;
}

$summary = $pdo->query("
    SELECT
        COUNT(*) AS demo_users,
        SUM(role = 'distributor') AS demo_distributors,
        SUM(role = 'user') AS demo_customers
    FROM users
    WHERE email LIKE 'demo.%@vmcmarts.local'
")->fetch();

$earnings = $pdo->query("
    SELECT u.name, u.distributor_uid, COALESCE(SUM(b.commission_amount),0) AS earnings, COALESCE(SUM(b.points),0) AS bv
    FROM users u
    LEFT JOIN bv_transactions b ON b.distributor_id = u.id
    WHERE u.email LIKE 'demo.distributor%@vmcmarts.local'
    GROUP BY u.id
    ORDER BY u.id
")->fetchAll();

echo 'Demo users: ' . (int)$summary['demo_users'] . PHP_EOL;
echo 'Demo distributors: ' . (int)$summary['demo_distributors'] . PHP_EOL;
echo 'Demo customers: ' . (int)$summary['demo_customers'] . PHP_EOL;
echo 'New completed orders this run: ' . $completedOrders . PHP_EOL;
foreach ($earnings as $row) {
    echo $row['name'] . ' | ' . $row['distributor_uid'] . ' | BV ' . (int)$row['bv'] . ' | Earnings ' . number_format((float)$row['earnings'], 2) . PHP_EOL;
}
