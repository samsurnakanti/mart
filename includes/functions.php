<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/database.php';

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float|string $amount): string
{
    return 'Rs ' . number_format((float)$amount, 2);
}

function redirect_to(string $page = ''): never
{
    if (str_contains($page, '&') || str_contains($page, '=')) {
        header('Location: index.php?page=' . $page);
    } else {
        header('Location: index.php' . ($page !== '' ? '?page=' . urlencode($page) : ''));
    }
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    sync_reward_wallet_points(db(), (int)$_SESSION['user_id']);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please login first.');
        redirect_to('login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
        throw new RuntimeException('Admin access required.');
    }
    return $user;
}

function require_super_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'super_admin') {
        throw new RuntimeException('Super admin access required.');
    }
    return $user;
}

function signup(array $data): void
{
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '');
    $password = (string)($data['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        throw new RuntimeException('Enter name, valid email, and 6 character password.');
    }
    $stmt = db()->prepare('INSERT INTO users (name,email,phone,password_hash) VALUES (?,?,?,?)');
    $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
    $_SESSION['user_id'] = (int)db()->lastInsertId();
    flash('ok', 'Welcome to VMCmarts.');
}

function login(string $login, string $password): void
{
    $login = trim($login);
    $stmt = db()->prepare('SELECT * FROM users WHERE phone = ? OR email = ?');
    $stmt->execute([$login, strtolower($login)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Invalid mobile number or password.');
    }
    $_SESSION['user_id'] = (int)$user['id'];
    flash('ok', 'Logged in successfully.');
}

function update_profile(array $data): void
{
    $user = require_login();
    $name = trim($data['name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid name and email.');
    }
    db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')->execute([$name, $email, $phone, $user['id']]);
}

function cart(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(array_map('intval', cart()));
}

function add_to_cart(int $productId, int $qty = 1): void
{
    $stmt = db()->prepare('SELECT id, stock FROM products WHERE id = ? AND is_active = 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    if ((int)$product['stock'] <= 0) {
        throw new RuntimeException('This product is out of stock.');
    }
    $_SESSION['cart'][$productId] = min((int)$product['stock'], ($_SESSION['cart'][$productId] ?? 0) + $qty);
}

function update_cart(array $qtyRows): void
{
    foreach ($qtyRows as $id => $qty) {
        $id = (int)$id;
        $qty = (int)$qty;
        if ($qty <= 0) {
            unset($_SESSION['cart'][$id]);
        } else {
            $_SESSION['cart'][$id] = min(99, $qty);
        }
    }
}

function remove_from_cart(int $id): void
{
    unset($_SESSION['cart'][$id]);
}

function cart_products(): array
{
    $items = cart();
    if (!$items) {
        return [];
    }
    $ids = array_keys($items);
    $stmt = db()->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['qty'] = (int)$items[$row['id']];
        $lineTotal = (float)$row['selling_price'] * (int)$row['qty'];
        $taxRate = (float)$row['tax_percent'];
        $row['line_tax'] = $taxRate > 0 ? $lineTotal * ($taxRate / (100 + $taxRate)) : 0.0;
        $row['line_subtotal'] = $lineTotal - $row['line_tax'];
        $row['line_total'] = $lineTotal;
    }
    return $rows;
}

function cart_totals(array $rows): array
{
    $subtotal = 0.0;
    $tax = 0.0;
    foreach ($rows as $row) {
        $subtotal += (float)$row['line_subtotal'];
        $tax += (float)$row['line_tax'];
    }
    return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
}

function place_order(array $data): int
{
    $user = require_login();
    $rows = cart_products();
    if (!$rows) {
        throw new RuntimeException('Your cart is empty.');
    }

    $city = trim($data['shipping_city'] ?? 'Khammam');
    $address = trim($data['shipping_address'] ?? '');
    if (strcasecmp($city, 'Khammam') !== 0) {
        throw new RuntimeException('VMCmarts delivery is currently available only in Khammam.');
    }
    if ($address === '') {
        throw new RuntimeException('Enter your Khammam delivery address.');
    }
    if (stripos($address, 'khammam') === false) {
        $address .= ', Khammam';
    }

    $totals = cart_totals($rows);
    $pointsUsed = max(0, (int)($data['points_to_use'] ?? 0));
    $walletBalance = reward_wallet_balance((int)$user['id']);
    $cardCapacity = active_card_balance((int)$user['id']);
    $maxUsablePoints = min((int)floor($totals['total']), $walletBalance, $cardCapacity);
    if ($pointsUsed > $maxUsablePoints) {
        throw new RuntimeException('You cannot redeem more points than your wallet balance, active discount card balance, or order total.');
    }
    $grand = max(0, $totals['total'] - $pointsUsed);
    $pointsEarned = 0;

    $pdo = db();
    $pdo->beginTransaction();

    $order = $pdo->prepare('INSERT INTO orders (user_id,subtotal,tax_total,points_used,grand_total,points_earned,shipping_name,shipping_phone,shipping_address) VALUES (?,?,?,?,?,?,?,?,?)');
    $order->execute([
        $user['id'],
        $totals['subtotal'],
        $totals['tax'],
        $pointsUsed,
        $grand,
        $pointsEarned,
        trim($data['shipping_name'] ?? $user['name']),
        trim($data['shipping_phone'] ?? $user['phone']),
        $address,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $item = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,qty,unit_price,tax_amount,points_value,product_type) VALUES (?,?,?,?,?,?,?,?)');
    $stock = $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?');
    foreach ($rows as $row) {
        $item->execute([$orderId, $row['id'], $row['name'], $row['qty'], $row['selling_price'], $row['line_tax'], $row['discount_points'], $row['product_type']]);
        $stock->execute([$row['qty'], $row['id']]);
    }

    if ($pointsUsed > 0) {
        $pdo->prepare('INSERT INTO wallet_transactions (user_id,order_id,points,type,note) VALUES (?,?,?,?,?)')
            ->execute([$user['id'], $orderId, $pointsUsed, 'debit', 'Points redeemed at checkout']);
        deduct_card_capacity($pdo, (int)$user['id'], $pointsUsed);
    }

    sync_reward_wallet_points($pdo, (int)$user['id']);
    $pdo->commit();
    $_SESSION['cart'] = [];
    return $orderId;
}

function product_image(?string $path): string
{
    return $path && is_file(__DIR__ . '/../' . $path) ? $path : '';
}

function upload_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, or GIF images are allowed.');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'uploads/product_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    move_uploaded_file($file['tmp_name'], __DIR__ . '/../' . $name);
    return $name;
}

function categories(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
    return db()->query($sql)->fetchAll();
}

function sliders(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM sliders' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id';
    return db()->query($sql)->fetchAll();
}

function save_category(array $data, array $files): void
{
    $id = (int)($data['id'] ?? 0);
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Category name is required.');
    }
    $image = upload_image($files['image'] ?? []);
    $sortOrder = max(0, (int)($data['sort_order'] ?? 0));
    $isActive = isset($data['is_active']) ? 1 : 0;

    if ($id > 0) {
        $old = db()->prepare('SELECT name FROM categories WHERE id = ?');
        $old->execute([$id]);
        $oldName = (string)($old->fetchColumn() ?: '');
        if ($image) {
            db()->prepare('UPDATE categories SET name=?, image_path=?, sort_order=?, is_active=? WHERE id=?')
                ->execute([$name, $image, $sortOrder, $isActive, $id]);
        } else {
            db()->prepare('UPDATE categories SET name=?, sort_order=?, is_active=? WHERE id=?')
                ->execute([$name, $sortOrder, $isActive, $id]);
        }
        if ($oldName !== '' && $oldName !== $name) {
            db()->prepare('UPDATE products SET category = ? WHERE category = ?')->execute([$name, $oldName]);
        }
        return;
    }

    db()->prepare('INSERT INTO categories (name,image_path,sort_order,is_active) VALUES (?,?,?,?)')
        ->execute([$name, $image, $sortOrder, $isActive]);
}

function set_category_active(int $id, int $active): void
{
    db()->prepare('UPDATE categories SET is_active = ? WHERE id = ?')->execute([$active, $id]);
}

function save_slider(array $data, array $files): void
{
    $id = (int)($data['id'] ?? 0);
    $title = trim($data['title'] ?? '');
    $image = upload_image($files['image'] ?? []);
    if ($title === '') {
        throw new RuntimeException('Slider title is required.');
    }
    if ($id <= 0 && !$image) {
        throw new RuntimeException('Slider image is required.');
    }
    $values = [
        $title,
        trim($data['subtitle'] ?? ''),
        trim($data['button_text'] ?? ''),
        trim($data['button_link'] ?? ''),
        max(0, (int)($data['sort_order'] ?? 0)),
        isset($data['is_active']) ? 1 : 0,
    ];
    if ($id > 0) {
        if ($image) {
            db()->prepare('UPDATE sliders SET title=?,subtitle=?,button_text=?,button_link=?,sort_order=?,is_active=?,image_path=? WHERE id=?')
                ->execute([...$values, $image, $id]);
        } else {
            db()->prepare('UPDATE sliders SET title=?,subtitle=?,button_text=?,button_link=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([...$values, $id]);
        }
        return;
    }
    db()->prepare('INSERT INTO sliders (title,subtitle,button_text,button_link,sort_order,is_active,image_path) VALUES (?,?,?,?,?,?,?)')
        ->execute([...$values, $image]);
}

function set_slider_active(int $id, int $active): void
{
    db()->prepare('UPDATE sliders SET is_active = ? WHERE id = ?')->execute([$active, $id]);
}

function save_product(array $data, array $files): void
{
    $id = (int)($data['id'] ?? 0);
    $image = upload_image($files['image'] ?? []);
    $values = [
        trim($data['name'] ?? ''),
        trim($data['category'] ?? ''),
        trim($data['description'] ?? ''),
        (float)($data['mrp'] ?? 0),
        (float)($data['selling_price'] ?? 0),
        (float)($data['tax_percent'] ?? 0),
        (int)($data['discount_points'] ?? 0),
        (int)($data['stock'] ?? 0),
        ($data['product_type'] ?? 'regular') === 'discount_points' ? 'discount_points' : 'regular',
        isset($data['is_active']) ? 1 : 0,
    ];
    if ($values[0] === '' || $values[1] === '' || $values[4] < 0) {
        throw new RuntimeException('Product name, category and selling price are required.');
    }
    $categoryCheck = db()->prepare('SELECT id FROM categories WHERE name = ? AND is_active = 1 LIMIT 1');
    $categoryCheck->execute([$values[1]]);
    if (!$categoryCheck->fetch()) {
        throw new RuntimeException('Choose an active category.');
    }

    if ($id > 0) {
        if ($image) {
            db()->prepare('UPDATE products SET name=?,category=?,description=?,mrp=?,selling_price=?,tax_percent=?,discount_points=?,stock=?,product_type=?,is_active=?,image_path=? WHERE id=?')
                ->execute([...$values, $image, $id]);
        } else {
            db()->prepare('UPDATE products SET name=?,category=?,description=?,mrp=?,selling_price=?,tax_percent=?,discount_points=?,stock=?,product_type=?,is_active=? WHERE id=?')
                ->execute([...$values, $id]);
        }
        save_product_gallery_images($id, $files['images'] ?? []);
        return;
    }

    db()->prepare('INSERT INTO products (name,category,description,mrp,selling_price,tax_percent,discount_points,stock,product_type,is_active,image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([...$values, $image]);
    $newId = (int)db()->lastInsertId();
    save_product_gallery_images($newId, $files['images'] ?? []);
}

function set_product_active(int $id, int $active): void
{
    db()->prepare('UPDATE products SET is_active = ? WHERE id = ?')->execute([$active, $id]);
}

function save_product_gallery_images(int $productId, array $files): void
{
    if (empty($files['name']) || !is_array($files['name'])) {
        return;
    }
    $count = count($files['name']);
    $sort = (int)db()->query('SELECT COUNT(*) FROM product_images WHERE product_id = ' . $productId)->fetchColumn();
    for ($i = 0; $i < $count; $i++) {
        $single = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        $path = upload_image($single);
        if ($path) {
            db()->prepare('INSERT INTO product_images (product_id,image_path,sort_order) VALUES (?,?,?)')->execute([$productId, $path, $sort++]);
        }
    }
}

function product_extra_images(int $productId): array
{
    $stmt = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function delete_product_image(int $id): void
{
    $stmt = db()->prepare('SELECT image_path FROM product_images WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && is_file(__DIR__ . '/../' . $row['image_path'])) {
        @unlink(__DIR__ . '/../' . $row['image_path']);
    }
    db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$id]);
}

function update_inventory(array $stockRows): void
{
    $stmt = db()->prepare('UPDATE products SET stock = ? WHERE id = ?');
    foreach ($stockRows as $id => $stock) {
        $stmt->execute([max(0, (int)$stock), (int)$id]);
    }
}

function save_user(array $data): void
{
    $id = (int)($data['id'] ?? 0);
    $role = in_array($data['role'] ?? 'user', ['user', 'admin', 'super_admin'], true) ? $data['role'] : 'user';
    $points = max(0, (int)($data['wallet_points'] ?? 0));
    if ($id <= 0) {
        $password = (string)($data['password'] ?? '');
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        db()->prepare('INSERT INTO users (name,email,phone,password_hash,role,wallet_points) VALUES (?,?,?,?,?,?)')
            ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), password_hash($password, PASSWORD_DEFAULT), $role, $points]);
        return;
    }
    $password = (string)($data['password'] ?? '');
    if ($password !== '') {
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, wallet_points=?, password_hash=? WHERE id=?')
            ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), $role, $points, password_hash($password, PASSWORD_DEFAULT), $id]);
        return;
    }
    db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, wallet_points=? WHERE id=?')
        ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), $role, $points, $id]);
}

function change_own_password(array $data): void
{
    $user = require_login();
    $current = (string)($data['current_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        throw new RuntimeException('Current password is incorrect.');
    }
    if (strlen($new) < 6) {
        throw new RuntimeException('New password must be at least 6 characters.');
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
}

function active_card_balance(int $userId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(remaining_points),0) FROM user_cards WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function reward_wallet_balance(int $userId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(points_earned - points_used),0) FROM orders WHERE user_id = ?');
    $stmt->execute([$userId]);
    return max(0, (int)$stmt->fetchColumn());
}

function sync_reward_wallet_points(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(points_earned - points_used),0) FROM orders WHERE user_id = ?');
    $stmt->execute([$userId]);
    $pdo->prepare('UPDATE users SET wallet_points = ? WHERE id = ?')->execute([max(0, (int)$stmt->fetchColumn()), $userId]);
}

function active_cards(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM user_cards WHERE user_id = ? AND status = 'active' ORDER BY activated_at, id");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function card_totals(int $userId): array
{
    $stmt = db()->prepare("
        SELECT
            COALESCE(SUM(total_points),0) AS total_points,
            COALESCE(SUM(total_points - remaining_points),0) AS used_points,
            COALESCE(SUM(remaining_points),0) AS remaining_points
        FROM user_cards
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return [
        'total_points' => (int)($row['total_points'] ?? 0),
        'used_points' => (int)($row['used_points'] ?? 0),
        'remaining_points' => (int)($row['remaining_points'] ?? 0),
    ];
}

function activate_cards_for_order(PDO $pdo, int $orderId, int $userId): void
{
    $stmt = $pdo->prepare("
        SELECT oi.*
        FROM order_items oi
        LEFT JOIN user_cards uc ON uc.source_order_item_id = oi.id
        WHERE oi.order_id = ? AND oi.product_type = 'discount_points' AND uc.id IS NULL
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    $insert = $pdo->prepare('INSERT INTO user_cards (user_id,source_order_id,source_order_item_id,card_name,total_points,remaining_points) VALUES (?,?,?,?,?,?)');
    foreach ($items as $item) {
        $points = (int)$item['points_value'] * (int)$item['qty'];
        $insert->execute([$userId, $orderId, $item['id'], $item['product_name'], $points, $points]);
    }
}

function deduct_card_capacity(PDO $pdo, int $userId, int $points): void
{
    if ($points <= 0) {
        return;
    }

    $remaining = $points;
    $cards = active_cards($userId);
    $updateCard = $pdo->prepare("UPDATE user_cards SET remaining_points = ?, status = ? WHERE id = ?");
    foreach ($cards as $card) {
        if ($remaining <= 0) {
            break;
        }
        $deduct = min($remaining, (int)$card['remaining_points']);
        $left = (int)$card['remaining_points'] - $deduct;
        $updateCard->execute([$left, $left === 0 ? 'exhausted' : 'active', $card['id']]);
        $remaining -= $deduct;
    }
}

function complete_order(int $orderId, int $pointsToAllot): void
{
    require_admin();
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if ($order['status'] !== 'Placed') {
        throw new RuntimeException('Only placed orders can be completed.');
    }

    $rewardPoints = max(0, $pointsToAllot);
    $cardCapacityLeft = active_card_balance((int)$order['user_id']);
    $walletBalance = reward_wallet_balance((int)$order['user_id']);
    $rewardRoom = max(0, $cardCapacityLeft - $walletBalance);
    if ($rewardPoints > $rewardRoom) {
        throw new RuntimeException('Reward points cannot exceed unused active discount card capacity.');
    }

    $pdo->prepare('UPDATE orders SET points_earned = ? WHERE id = ?')->execute([$rewardPoints, $orderId]);
    if ($rewardPoints > 0) {
        $pdo->prepare('INSERT INTO wallet_transactions (user_id,order_id,points,type,note) VALUES (?,?,?,?,?)')
            ->execute([$order['user_id'], $orderId, $rewardPoints, 'credit', 'Reward points granted by admin']);
    }
    activate_cards_for_order($pdo, $orderId, (int)$order['user_id']);
    sync_reward_wallet_points($pdo, (int)$order['user_id']);
    $pdo->prepare("UPDATE orders SET status = 'Completed' WHERE id = ?")->execute([$orderId]);
    $pdo->commit();
}

function update_order_status(int $orderId, string $status): void
{
    $allowed = ['Completed', 'Cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid order status.');
    }
    db()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
}

function order_for_invoice(int $orderId): array
{
    $user = require_login();
    $stmt = db()->prepare('
        SELECT o.*, u.name AS customer_name, u.email AS customer_email
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Invoice not found.');
    }
    if ((int)$order['user_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin', 'super_admin'], true)) {
        throw new RuntimeException('Invoice access denied.');
    }
    if ($order['status'] !== 'Completed') {
        throw new RuntimeException('Invoice is available after admin completes the order.');
    }
    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $items->execute([$orderId]);
    $order['items'] = $items->fetchAll();
    return $order;
}

function render_invoice_document(array $order): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice #<?= (int)$order['id'] ?></title>
<style>
body{font-family:Arial,sans-serif;color:#17202a;margin:0;background:#f6f7f8}
.invoice{max-width:820px;margin:28px auto;background:#fff;padding:28px;border-radius:16px}
.head{display:flex;justify-content:space-between;gap:20px;border-bottom:1px solid #ddd;padding-bottom:18px}
h1{margin:0 0 5px}.muted{color:#667085}.meta{text-align:right}
table{width:100%;border-collapse:collapse;margin-top:22px}th,td{padding:12px;border-bottom:1px solid #eee;text-align:left}th{text-transform:uppercase;font-size:12px;color:#667085}
.totals{margin-left:auto;margin-top:18px;width:280px}.totals div{display:flex;justify-content:space-between;padding:7px 0}.grand{font-size:20px;font-weight:bold;border-top:1px solid #ddd;margin-top:8px;padding-top:12px!important}
.actions{max-width:820px;margin:0 auto 20px;text-align:right}.actions button{padding:10px 14px;border:0;border-radius:10px;background:#17202a;color:#fff;font-weight:bold}
@media print{body{background:#fff}.actions{display:none}.invoice{box-shadow:none;margin:0;max-width:none;border-radius:0}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Print Invoice</button></div>
<main class="invoice">
    <div class="head">
        <div>
            <h1>VMCmarts</h1>
            <div class="muted">Tax Invoice</div>
        </div>
        <div class="meta">
            <b>Invoice #<?= (int)$order['id'] ?></b><br>
            <span class="muted"><?= e($order['created_at']) ?></span>
        </div>
    </div>
    <p>
        <b>Bill To:</b> <?= e($order['shipping_name']) ?><br>
        <?= e($order['shipping_phone']) ?><br>
        <?= e($order['shipping_address']) ?>
    </p>
    <table>
        <tr><th>Item</th><th>Qty</th><th>GST incl. price</th><th>GST</th><th>Total</th></tr>
        <?php foreach ($order['items'] as $item): ?>
            <?php $lineTotal = (float)$item['unit_price'] * (int)$item['qty']; ?>
            <tr>
                <td><?= e($item['product_name']) ?></td>
                <td><?= (int)$item['qty'] ?></td>
                <td><?= money($item['unit_price']) ?></td>
                <td><?= money($item['tax_amount']) ?></td>
                <td><?= money($lineTotal) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <div class="totals">
        <div><span>Taxable value</span><b><?= money($order['subtotal']) ?></b></div>
        <div><span>GST included</span><b><?= money($order['tax_total']) ?></b></div>
        <div><span>Points redeemed</span><b><?= (int)$order['points_used'] ?></b></div>
        <div class="grand"><span>Grand total</span><b><?= money($order['grand_total']) ?></b></div>
    </div>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function render_product_card(array $p): void
{
    ?>
    <article class="prod-card">
        <a class="prod-img-wrap" href="index.php?page=product&id=<?= (int)$p['id'] ?>">
            <?php if ($p['product_type'] === 'discount_points'): ?><span class="prod-badge orange">CARD</span><?php endif; ?>
            <?php if (product_image($p['image_path'])): ?>
                <img class="real-img" src="<?= e($p['image_path']) ?>" alt="<?= e($p['name']) ?>">
            <?php else: ?>
                <img class="real-img fallback-real" src="<?= e(default_product_image($p)) ?>" alt="<?= e($p['name']) ?>">
            <?php endif; ?>
        </a>
        <div class="prod-body">
            <div class="prod-offer"><?= e($p['category']) ?></div>
            <a class="prod-name" href="index.php?page=product&id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
            <p class="prod-qty"><?= e($p['description'] ?: 'Tax ' . $p['tax_percent'] . '%') ?></p>
            <div class="price-row">
                <span class="price-now"><?= money($p['selling_price']) ?></span>
            </div>
            <?php if ($p['product_type'] === 'discount_points'): ?><div class="points-line">Card value <?= (int)$p['discount_points'] ?> points</div><?php endif; ?>
            <form method="post" class="buy-actions">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="back" value="<?= e($_GET['page'] ?? '') ?>">
                <button name="action" value="add_to_cart" class="add-btn">Add to Cart</button>
                <button name="action" value="buy_now" class="buy-btn">Buy Now</button>
            </form>
        </div>
    </article>
    <?php
}

function product_gallery_images(array $product): array
{
    $images = [];
    if (product_image($product['image_path'])) {
        $images[] = $product['image_path'];
    }
    foreach (product_extra_images((int)$product['id']) as $img) {
        if (product_image($img['image_path'])) {
            $images[] = $img['image_path'];
        }
    }
    $images[] = default_product_image($product);
    $images[] = $product['product_type'] === 'discount_points' ? 'assets/slider-wallet.svg' : 'assets/slider-grocery.svg';
    return array_values(array_unique($images));
}

function default_product_image(array $product): string
{
    if (($product['product_type'] ?? '') === 'discount_points') {
        return 'assets/slider-wallet.svg';
    }
    $category = strtolower((string)($product['category'] ?? ''));
    if (str_contains($category, 'tea') || str_contains($category, 'coffee') || str_contains($category, 'home')) {
        return 'assets/slider-grocery.svg';
    }
    return 'assets/slider-fresh.svg';
}
