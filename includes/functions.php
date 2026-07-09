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
    $user = $stmt->fetch() ?: null;
    if ($user && $user['role'] === 'distributor' && empty($user['distributor_uid'])) {
        assign_distributor_uid((int)$user['id']);
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: $user;
    }
    return $user;
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

function require_distributor(): array
{
    $user = require_login();
    if ($user['role'] !== 'distributor') {
        throw new RuntimeException('Distributor access required.');
    }
    if (empty($user['distributor_uid'])) {
        assign_distributor_uid((int)$user['id']);
        $user = current_user() ?: $user;
    }
    return $user;
}

function require_stock_pointer(): array
{
    $user = require_login();
    if ($user['role'] !== 'stock_pointer') {
        throw new RuntimeException('Stock pointer access required.');
    }
    return $user;
}

function normalize_whatsapp_phone(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        throw new RuntimeException('Enter a valid mobile number.');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10) {
        return '+91' . $digits;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return '+' . $digits;
    }
    if (str_starts_with($phone, '+') && strlen($digits) >= 10) {
        return '+' . $digits;
    }
    throw new RuntimeException('Enter a valid WhatsApp mobile number.');
}

function make_otp(): string
{
    return (string)random_int(100000, 999999);
}

function send_whatsapp_otp(string $phone, string $otp): void
{
    if (ARKLYTICS_WHATSAPP_API_KEY === '') {
        throw new RuntimeException('WhatsApp API key is not configured.');
    }

    $payload = json_encode([
        'kind' => 'authentication',
        'template_name' => 'login_otp',
        'language' => 'en_US',
        'to' => normalize_whatsapp_phone($phone),
        'otp' => $otp,
    ], JSON_THROW_ON_ERROR);
    $headers = [
        'Authorization: Bearer ' . ARKLYTICS_WHATSAPP_API_KEY,
        'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init(ARKLYTICS_WHATSAPP_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Could not send WhatsApp OTP. ' . ($error ?: 'Please try again.'));
        }
        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents(ARKLYTICS_WHATSAPP_ENDPOINT, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($body === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Could not send WhatsApp OTP. Please try again.');
    }
}

function request_signup_otp(array $data): void
{
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '');
    $password = (string)($data['password'] ?? '');
    $role = ($data['account_type'] ?? 'user') === 'distributor' ? 'distributor' : 'user';
    $referralId = trim($data['referral_id'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        throw new RuntimeException('Enter name, valid email, and 6 character password.');
    }
    normalize_whatsapp_phone($phone);
    sponsor_id_from_ref($referralId);

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('This email is already registered.');
    }

    $otp = make_otp();
    $_SESSION['pending_signup'] = [
        'data' => [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'account_type' => $role,
            'referral_id' => $referralId,
        ],
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
    ];
    send_whatsapp_otp($phone, $otp);
    flash('ok', 'OTP sent on WhatsApp. Enter it to complete signup.');
}

function signup(array $data): void
{
    $pending = $_SESSION['pending_signup'] ?? null;
    if (!$pending || empty($pending['data']) || empty($pending['otp_hash']) || (int)($pending['expires_at'] ?? 0) < time()) {
        unset($_SESSION['pending_signup']);
        throw new RuntimeException('Signup OTP expired. Please request a new OTP.');
    }
    $otp = trim($data['otp'] ?? '');
    if (!password_verify($otp, (string)$pending['otp_hash'])) {
        throw new RuntimeException('Invalid OTP.');
    }

    $signupData = $pending['data'];
    $name = trim($signupData['name'] ?? '');
    $email = strtolower(trim($signupData['email'] ?? ''));
    $phone = trim($signupData['phone'] ?? '');
    $passwordHash = (string)($signupData['password_hash'] ?? '');
    $role = ($signupData['account_type'] ?? 'user') === 'distributor' ? 'distributor' : 'user';
    $sponsorId = sponsor_id_from_ref(trim($signupData['referral_id'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $passwordHash === '') {
        throw new RuntimeException('Enter name, valid email, and 6 character password.');
    }
    $stmt = db()->prepare('INSERT INTO users (name,email,phone,password_hash,role,sponsor_distributor_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$name, $email, $phone, $passwordHash, $role, $sponsorId]);
    $userId = (int)db()->lastInsertId();
    if ($role === 'distributor') {
        assign_distributor_uid($userId);
    }
    unset($_SESSION['pending_signup']);
    $_SESSION['user_id'] = $userId;
    flash('ok', 'Welcome to VMCmarts.');
}

function request_password_reset_otp(array $data): void
{
    $login = trim($data['login'] ?? '');
    $stmt = db()->prepare('SELECT id, phone FROM users WHERE phone = ? OR email = ? OR distributor_uid = ? LIMIT 1');
    $stmt->execute([$login, strtolower($login), strtoupper($login)]);
    $user = $stmt->fetch();
    if (!$user || trim((string)$user['phone']) === '') {
        throw new RuntimeException('No account with WhatsApp mobile number was found.');
    }

    $otp = make_otp();
    $_SESSION['password_reset'] = [
        'user_id' => (int)$user['id'],
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
    ];
    send_whatsapp_otp((string)$user['phone'], $otp);
    flash('ok', 'OTP sent on WhatsApp. Enter it with your new password.');
}

function reset_password_with_otp(array $data): void
{
    $pending = $_SESSION['password_reset'] ?? null;
    if (!$pending || (int)($pending['expires_at'] ?? 0) < time()) {
        unset($_SESSION['password_reset']);
        throw new RuntimeException('Password reset OTP expired. Please request a new OTP.');
    }
    $otp = trim($data['otp'] ?? '');
    $password = (string)($data['password'] ?? '');
    if (!password_verify($otp, (string)$pending['otp_hash'])) {
        throw new RuntimeException('Invalid OTP.');
    }
    if (strlen($password) < 6) {
        throw new RuntimeException('New password must be at least 6 characters.');
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$pending['user_id']]);
    unset($_SESSION['password_reset']);
    flash('ok', 'Password updated. You can login now.');
}

function login(string $login, string $password, ?string $requiredRole = null): void
{
    $login = trim($login);
    $stmt = db()->prepare('SELECT * FROM users WHERE phone = ? OR email = ? OR distributor_uid = ?');
    $stmt->execute([$login, strtolower($login), strtoupper($login)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Invalid login ID or password.');
    }
    if ($requiredRole !== null && $user['role'] !== $requiredRole) {
        throw new RuntimeException('Use the correct login for this account type.');
    }
    $_SESSION['user_id'] = (int)$user['id'];
    if ($user['role'] === 'distributor' && empty($user['distributor_uid'])) {
        assign_distributor_uid((int)$user['id']);
    }
    flash('ok', 'Logged in successfully.');
}

function assign_distributor_uid(int $userId): string
{
    $stmt = db()->prepare('SELECT distributor_uid FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $existing = (string)($stmt->fetchColumn() ?: '');
    if ($existing !== '') {
        return $existing;
    }
    $uid = 'VMC-D' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE users SET distributor_uid = ? WHERE id = ?')->execute([$uid, $userId]);
    return $uid;
}

function sponsor_id_from_ref(string $ref): ?int
{
    $ref = strtoupper($ref);
    if ($ref === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT id FROM users WHERE distributor_uid = ? AND role = 'distributor' LIMIT 1");
    $stmt->execute([$ref]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        throw new RuntimeException('Referral distributor ID was not found.');
    }
    return (int)$id;
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

function update_distributor_kyc(array $data, array $files): void
{
    $user = require_distributor();
    $pan = strtoupper(trim($data['pan_number'] ?? ''));
    $bankAccount = trim($data['bank_account_number'] ?? '');
    $bankIfsc = strtoupper(trim($data['bank_ifsc'] ?? ''));
    $idProofType = trim($data['id_proof_type'] ?? '');
    $gst = strtoupper(trim($data['gst_number'] ?? ''));
    if ($pan === '' || $bankAccount === '' || $bankIfsc === '' || $idProofType === '') {
        throw new RuntimeException('PAN, bank account, IFSC and ID proof type are required.');
    }
    $paths = [];
    foreach (['pan_file', 'bank_file', 'id_proof_file', 'gst_file'] as $key) {
        $paths[$key] = upload_document($files[$key] ?? []);
    }
    foreach (['pan_file' => 'PAN document', 'bank_file' => 'bank proof', 'id_proof_file' => 'ID proof'] as $key => $label) {
        if (empty($user[$key]) && $paths[$key] === null) {
            throw new RuntimeException('Upload ' . $label . '.');
        }
    }
    db()->prepare('
        UPDATE users
        SET pan_number = ?, bank_account_number = ?, bank_ifsc = ?, id_proof_type = ?, gst_number = ?,
            pan_file = COALESCE(?, pan_file), bank_file = COALESCE(?, bank_file),
            id_proof_file = COALESCE(?, id_proof_file), gst_file = COALESCE(?, gst_file),
            kyc_status = ?
        WHERE id = ?
    ')->execute([
        $pan,
        $bankAccount,
        $bankIfsc,
        $idProofType,
        $gst,
        $paths['pan_file'],
        $paths['bank_file'],
        $paths['id_proof_file'],
        $paths['gst_file'],
        'submitted',
        $user['id'],
    ]);
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

    $item = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,qty,unit_price,tax_amount,points_value,bv_points,product_type) VALUES (?,?,?,?,?,?,?,?,?)');
    $stock = $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?');
    foreach ($rows as $row) {
        $item->execute([$orderId, $row['id'], $row['name'], $row['qty'], $row['selling_price'], $row['line_tax'], $row['discount_points'], $row['bv_points'] ?? 0, $row['product_type']]);
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

function active_products_for_distributor_order(): array
{
    return db()->query("
        SELECT *
        FROM products
        WHERE is_active = 1
        ORDER BY category, name
    ")->fetchAll();
}

function stock_pointer_users(): array
{
    return db()->query("SELECT * FROM users WHERE role = 'stock_pointer' ORDER BY name")->fetchAll();
}

function allocate_stock_to_pointer(array $data): void
{
    $admin = require_super_admin();
    $stockPointerId = (int)($data['stock_pointer_id'] ?? 0);
    $productId = (int)($data['product_id'] ?? 0);
    $qty = max(0, (int)($data['qty'] ?? 0));
    $note = trim($data['note'] ?? '');
    if ($stockPointerId <= 0 || $productId <= 0 || $qty <= 0) {
        throw new RuntimeException('Select stock pointer, product and quantity.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'stock_pointer' LIMIT 1");
    $userStmt->execute([$stockPointerId]);
    if (!$userStmt->fetch()) {
        throw new RuntimeException('Stock pointer account was not found.');
    }

    $productStmt = $pdo->prepare('SELECT id, stock, name FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product) {
        throw new RuntimeException('Product was not found.');
    }
    if ($qty > (int)$product['stock']) {
        throw new RuntimeException($product['name'] . ' has only ' . (int)$product['stock'] . ' in central stock.');
    }

    $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$qty, $productId]);
    $pdo->prepare('
        INSERT INTO stock_pointer_inventory (stock_pointer_id, product_id, qty)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ')->execute([$stockPointerId, $productId, $qty]);
    $pdo->prepare('INSERT INTO stock_pointer_transfers (stock_pointer_id,product_id,qty,created_by,note) VALUES (?,?,?,?,?)')
        ->execute([$stockPointerId, $productId, $qty, $admin['id'], $note]);
    $pdo->commit();
}

function stock_pointer_inventory(int $stockPointerId): array
{
    $stmt = db()->prepare('
        SELECT spi.*, p.name, p.category, p.selling_price, p.tax_percent, p.product_type, p.is_active
        FROM stock_pointer_inventory spi
        JOIN products p ON p.id = spi.product_id
        WHERE spi.stock_pointer_id = ?
        ORDER BY p.category, p.name
    ');
    $stmt->execute([$stockPointerId]);
    return $stmt->fetchAll();
}

function stock_pointer_transfer_history(int $stockPointerId = 0, int $limit = 80): array
{
    $sql = '
        SELECT spt.*, sp.name AS stock_pointer_name, p.name AS product_name, u.name AS admin_name
        FROM stock_pointer_transfers spt
        JOIN users sp ON sp.id = spt.stock_pointer_id
        JOIN products p ON p.id = spt.product_id
        JOIN users u ON u.id = spt.created_by
    ';
    $params = [];
    if ($stockPointerId > 0) {
        $sql .= ' WHERE spt.stock_pointer_id = ?';
        $params[] = $stockPointerId;
    }
    $sql .= ' ORDER BY spt.id DESC LIMIT ' . max(1, $limit);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pos_buyers(string $type): array
{
    $role = $type === 'distributor' ? 'distributor' : 'user';
    $stmt = db()->prepare('SELECT id, name, phone, email, distributor_uid FROM users WHERE role = ? ORDER BY name');
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

function stock_pointer_sales(int $stockPointerId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM pos_sales WHERE stock_pointer_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $stmt->execute([$stockPointerId]);
    return $stmt->fetchAll();
}

function stock_pointer_sales_report(int $stockPointerId = 0, int $limit = 100): array
{
    $sql = '
        SELECT ps.*, sp.name AS stock_pointer_name, sp.email AS stock_pointer_email, sp.phone AS stock_pointer_phone
        FROM pos_sales ps
        JOIN users sp ON sp.id = ps.stock_pointer_id
    ';
    $params = [];
    if ($stockPointerId > 0) {
        $sql .= ' WHERE ps.stock_pointer_id = ?';
        $params[] = $stockPointerId;
    }
    $sql .= ' ORDER BY ps.id DESC LIMIT ' . max(1, $limit);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function stock_pointer_report_summary(int $stockPointerId = 0): array
{
    $whereSales = $stockPointerId > 0 ? ' WHERE stock_pointer_id = ?' : '';
    $whereTransfers = $stockPointerId > 0 ? ' WHERE stock_pointer_id = ?' : '';
    $salesStmt = db()->prepare("
        SELECT COUNT(*) AS sales_count, COALESCE(SUM(grand_total),0) AS revenue, COALESCE(SUM(tax_total),0) AS tax
        FROM pos_sales
        $whereSales
    ");
    $salesStmt->execute($stockPointerId > 0 ? [$stockPointerId] : []);
    $sales = $salesStmt->fetch() ?: [];

    $transferStmt = db()->prepare("
        SELECT COUNT(*) AS transfer_count, COALESCE(SUM(qty),0) AS imported_units
        FROM stock_pointer_transfers
        $whereTransfers
    ");
    $transferStmt->execute($stockPointerId > 0 ? [$stockPointerId] : []);
    $transfers = $transferStmt->fetch() ?: [];

    $stockStmt = db()->prepare('
        SELECT COALESCE(SUM(qty),0)
        FROM stock_pointer_inventory
        ' . ($stockPointerId > 0 ? 'WHERE stock_pointer_id = ?' : '')
    );
    $stockStmt->execute($stockPointerId > 0 ? [$stockPointerId] : []);

    return [
        'sales_count' => (int)($sales['sales_count'] ?? 0),
        'revenue' => (float)($sales['revenue'] ?? 0),
        'tax' => (float)($sales['tax'] ?? 0),
        'transfer_count' => (int)($transfers['transfer_count'] ?? 0),
        'imported_units' => (int)($transfers['imported_units'] ?? 0),
        'available_units' => (int)$stockStmt->fetchColumn(),
    ];
}

function pos_sale_for_invoice(int $saleId): array
{
    $user = require_login();
    $stmt = db()->prepare('
        SELECT ps.*, sp.name AS stock_pointer_name, sp.email AS stock_pointer_email, sp.phone AS stock_pointer_phone
        FROM pos_sales ps
        JOIN users sp ON sp.id = ps.stock_pointer_id
        WHERE ps.id = ?
    ');
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) {
        throw new RuntimeException('POS invoice not found.');
    }
    if ((int)$sale['stock_pointer_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin', 'super_admin'], true)) {
        throw new RuntimeException('POS invoice access denied.');
    }
    $items = db()->prepare('SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY id');
    $items->execute([$saleId]);
    $sale['items'] = $items->fetchAll();
    return $sale;
}

function stock_transfer_for_invoice(int $transferId): array
{
    $user = require_login();
    $stmt = db()->prepare('
        SELECT spt.*, sp.name AS stock_pointer_name, sp.email AS stock_pointer_email, sp.phone AS stock_pointer_phone,
               p.name AS product_name, p.category, p.selling_price, p.tax_percent, u.name AS admin_name
        FROM stock_pointer_transfers spt
        JOIN users sp ON sp.id = spt.stock_pointer_id
        JOIN products p ON p.id = spt.product_id
        JOIN users u ON u.id = spt.created_by
        WHERE spt.id = ?
    ');
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer) {
        throw new RuntimeException('Stock transfer bill not found.');
    }
    if ((int)$transfer['stock_pointer_id'] !== (int)$user['id'] && !in_array($user['role'], ['admin', 'super_admin'], true)) {
        throw new RuntimeException('Stock transfer bill access denied.');
    }
    $lineTotal = (float)$transfer['selling_price'] * (int)$transfer['qty'];
    $taxRate = (float)$transfer['tax_percent'];
    $transfer['line_tax'] = $taxRate > 0 ? $lineTotal * ($taxRate / (100 + $taxRate)) : 0.0;
    $transfer['line_subtotal'] = $lineTotal - (float)$transfer['line_tax'];
    $transfer['line_total'] = $lineTotal;
    return $transfer;
}

function render_pos_invoice_document(array $sale): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Invoice #<?= (int)$sale['id'] ?></title>
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
            <h1>VMCmarts POS</h1>
            <div class="muted">Stock Pointer Invoice</div>
        </div>
        <div class="meta">
            <b>Invoice #POS-<?= (int)$sale['id'] ?></b><br>
            <span class="muted"><?= e($sale['created_at']) ?></span>
        </div>
    </div>
    <p>
        <b>Sold By:</b> <?= e($sale['stock_pointer_name']) ?><br>
        <?= e($sale['stock_pointer_phone'] ?: $sale['stock_pointer_email']) ?><br><br>
        <b>Bill To:</b> <?= e($sale['buyer_name']) ?><br>
        <?= e($sale['buyer_phone'] ?: '-') ?><br>
        <?= e(ucwords($sale['customer_type'])) ?>
    </p>
    <table>
        <tr><th>Item</th><th>Qty</th><th>GST incl. price</th><th>GST</th><th>Total</th></tr>
        <?php foreach ($sale['items'] as $item): ?>
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
        <div><span>Taxable value</span><b><?= money($sale['subtotal']) ?></b></div>
        <div><span>GST included</span><b><?= money($sale['tax_total']) ?></b></div>
        <div class="grand"><span>Grand total</span><b><?= money($sale['grand_total']) ?></b></div>
    </div>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function render_stock_transfer_document(array $transfer): string
{
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Bill #<?= (int)$transfer['id'] ?></title>
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
<div class="actions"><button onclick="window.print()">Print Bill</button></div>
<main class="invoice">
    <div class="head">
        <div>
            <h1>VMCmarts</h1>
            <div class="muted">Stock Transfer Bill</div>
        </div>
        <div class="meta">
            <b>Bill #STK-<?= (int)$transfer['id'] ?></b><br>
            <span class="muted"><?= e($transfer['created_at']) ?></span>
        </div>
    </div>
    <p>
        <b>Issued By:</b> <?= e($transfer['admin_name']) ?><br>
        <b>Issued To:</b> <?= e($transfer['stock_pointer_name']) ?><br>
        <?= e($transfer['stock_pointer_phone'] ?: $transfer['stock_pointer_email']) ?><br>
        <?php if (!empty($transfer['note'])): ?><b>Note:</b> <?= e($transfer['note']) ?><?php endif; ?>
    </p>
    <table>
        <tr><th>Item</th><th>Category</th><th>Qty</th><th>GST incl. price</th><th>GST</th><th>Total</th></tr>
        <tr>
            <td><?= e($transfer['product_name']) ?></td>
            <td><?= e($transfer['category']) ?></td>
            <td><?= (int)$transfer['qty'] ?></td>
            <td><?= money($transfer['selling_price']) ?></td>
            <td><?= money($transfer['line_tax']) ?></td>
            <td><?= money($transfer['line_total']) ?></td>
        </tr>
    </table>
    <div class="totals">
        <div><span>Taxable value</span><b><?= money($transfer['line_subtotal']) ?></b></div>
        <div><span>GST included</span><b><?= money($transfer['line_tax']) ?></b></div>
        <div class="grand"><span>Stock value</span><b><?= money($transfer['line_total']) ?></b></div>
    </div>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function submit_stock_pointer_pos_sale(array $data): int
{
    $stockPointer = require_stock_pointer();
    $qtyRows = $data['qty'] ?? [];
    if (!is_array($qtyRows)) {
        throw new RuntimeException('Invalid POS quantity data.');
    }

    $requested = [];
    foreach ($qtyRows as $productId => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $requested[(int)$productId] = $qty;
        }
    }
    if (!$requested) {
        throw new RuntimeException('Enter quantity for at least one POS item.');
    }

    $customerType = ($data['customer_type'] ?? 'customer') === 'distributor' ? 'distributor' : 'customer';
    $buyerUserId = max(0, (int)($data['buyer_user_id'] ?? 0));
    $buyerName = trim($data['buyer_name'] ?? '');
    $buyerPhone = trim($data['buyer_phone'] ?? '');
    if ($buyerUserId > 0) {
        $buyerStmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role IN ('user','distributor') LIMIT 1");
        $buyerStmt->execute([$buyerUserId]);
        $buyer = $buyerStmt->fetch();
        if (!$buyer) {
            throw new RuntimeException('Selected buyer was not found.');
        }
        $customerType = $buyer['role'] === 'distributor' ? 'distributor' : 'customer';
        $buyerName = $buyer['name'];
        $buyerPhone = $buyer['phone'];
    }
    if ($buyerName === '') {
        throw new RuntimeException('Enter buyer name or select a registered buyer.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $ids = array_keys($requested);
    $stmt = $pdo->prepare('
        SELECT spi.product_id, spi.qty AS pointer_qty, p.*
        FROM stock_pointer_inventory spi
        JOIN products p ON p.id = spi.product_id
        WHERE spi.stock_pointer_id = ? AND spi.product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND p.is_active = 1
        FOR UPDATE
    ');
    $stmt->execute(array_merge([(int)$stockPointer['id']], $ids));
    $products = $stmt->fetchAll();
    if (count($products) !== count($requested)) {
        throw new RuntimeException('One or more selected products are not in your stock pointer inventory.');
    }

    $rows = [];
    foreach ($products as $product) {
        $qty = $requested[(int)$product['product_id']];
        if ($qty > (int)$product['pointer_qty']) {
            throw new RuntimeException($product['name'] . ' has only ' . (int)$product['pointer_qty'] . ' in your stock.');
        }
        $lineTotal = (float)$product['selling_price'] * $qty;
        $taxRate = (float)$product['tax_percent'];
        $lineTax = $taxRate > 0 ? $lineTotal * ($taxRate / (100 + $taxRate)) : 0.0;
        $rows[] = [
            'product' => $product,
            'qty' => $qty,
            'line_tax' => $lineTax,
            'line_subtotal' => $lineTotal - $lineTax,
            'line_total' => $lineTotal,
        ];
    }

    $subtotal = array_sum(array_column($rows, 'line_subtotal'));
    $tax = array_sum(array_column($rows, 'line_tax'));
    $sale = $pdo->prepare('INSERT INTO pos_sales (stock_pointer_id,buyer_user_id,customer_type,buyer_name,buyer_phone,subtotal,tax_total,grand_total) VALUES (?,?,?,?,?,?,?,?)');
    $sale->execute([
        $stockPointer['id'],
        $buyerUserId > 0 ? $buyerUserId : null,
        $customerType,
        $buyerName,
        $buyerPhone,
        $subtotal,
        $tax,
        $subtotal + $tax,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $item = $pdo->prepare('INSERT INTO pos_sale_items (sale_id,product_id,product_name,qty,unit_price,tax_amount,product_type) VALUES (?,?,?,?,?,?,?)');
    $stock = $pdo->prepare('UPDATE stock_pointer_inventory SET qty = qty - ? WHERE stock_pointer_id = ? AND product_id = ?');
    foreach ($rows as $row) {
        $product = $row['product'];
        $item->execute([
            $saleId,
            $product['product_id'],
            $product['name'],
            $row['qty'],
            $product['selling_price'],
            $row['line_tax'],
            $product['product_type'],
        ]);
        $stock->execute([$row['qty'], $stockPointer['id'], $product['product_id']]);
    }
    $pdo->commit();
    return $saleId;
}

function submit_distributor_order(array $data): int
{
    $user = require_distributor();
    $qtyRows = $data['qty'] ?? [];
    if (!is_array($qtyRows)) {
        throw new RuntimeException('Invalid order quantity data.');
    }

    $requested = [];
    foreach ($qtyRows as $productId => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $requested[(int)$productId] = $qty;
        }
    }
    if (!$requested) {
        throw new RuntimeException('Enter quantity for at least one product.');
    }

    $ids = array_keys($requested);
    $stmt = db()->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND is_active = 1 ORDER BY category, name');
    $stmt->execute($ids);
    $products = $stmt->fetchAll();
    if (count($products) !== count($requested)) {
        throw new RuntimeException('One or more selected products are not available.');
    }

    $rows = [];
    foreach ($products as $product) {
        $qty = $requested[(int)$product['id']];
        if ($qty > (int)$product['stock']) {
            throw new RuntimeException($product['name'] . ' has only ' . (int)$product['stock'] . ' in stock.');
        }
        if ($qty <= 0) {
            continue;
        }
        $lineTotal = (float)$product['selling_price'] * $qty;
        $taxRate = (float)$product['tax_percent'];
        $rows[] = [
            'product' => $product,
            'qty' => $qty,
            'line_tax' => $taxRate > 0 ? $lineTotal * ($taxRate / (100 + $taxRate)) : 0.0,
            'line_subtotal' => $taxRate > 0 ? $lineTotal - ($lineTotal * ($taxRate / (100 + $taxRate))) : $lineTotal,
            'line_total' => $lineTotal,
        ];
    }
    if (!$rows) {
        throw new RuntimeException('Selected products are out of stock.');
    }

    $subtotal = array_sum(array_column($rows, 'line_subtotal'));
    $tax = array_sum(array_column($rows, 'line_tax'));
    $address = trim($data['shipping_address'] ?? '');
    if ($address === '') {
        $address = 'Distributor order request, Khammam';
    } elseif (stripos($address, 'khammam') === false) {
        $address .= ', Khammam';
    }

    $pdo = db();
    $pdo->beginTransaction();
    $order = $pdo->prepare('INSERT INTO orders (user_id,subtotal,tax_total,points_used,grand_total,points_earned,shipping_name,shipping_phone,shipping_address) VALUES (?,?,?,?,?,?,?,?,?)');
    $order->execute([
        $user['id'],
        $subtotal,
        $tax,
        0,
        $subtotal + $tax,
        0,
        $user['name'],
        $user['phone'],
        $address,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $item = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,qty,unit_price,tax_amount,points_value,bv_points,product_type) VALUES (?,?,?,?,?,?,?,?,?)');
    $stock = $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?');
    foreach ($rows as $row) {
        $product = $row['product'];
        $item->execute([
            $orderId,
            $product['id'],
            $product['name'],
            $row['qty'],
            $product['selling_price'],
            $row['line_tax'],
            $product['discount_points'],
            $product['bv_points'] ?? 0,
            $product['product_type'],
        ]);
        $stock->execute([$row['qty'], $product['id']]);
    }
    $pdo->commit();
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

function upload_document(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Document upload failed.');
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, or PDF documents are allowed.');
    }
    $dir = __DIR__ . '/../uploads/kyc';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'uploads/kyc/doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
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
        max(0, (int)($data['bv_points'] ?? 0)),
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
            db()->prepare('UPDATE products SET name=?,category=?,description=?,mrp=?,selling_price=?,tax_percent=?,discount_points=?,bv_points=?,stock=?,product_type=?,is_active=?,image_path=? WHERE id=?')
                ->execute([...$values, $image, $id]);
        } else {
            db()->prepare('UPDATE products SET name=?,category=?,description=?,mrp=?,selling_price=?,tax_percent=?,discount_points=?,bv_points=?,stock=?,product_type=?,is_active=? WHERE id=?')
                ->execute([...$values, $id]);
        }
        save_product_gallery_images($id, $files['images'] ?? []);
        return;
    }

    db()->prepare('INSERT INTO products (name,category,description,mrp,selling_price,tax_percent,discount_points,bv_points,stock,product_type,is_active,image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
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
    $role = in_array($data['role'] ?? 'user', ['user', 'distributor', 'stock_pointer', 'admin', 'super_admin'], true) ? $data['role'] : 'user';
    $points = max(0, (int)($data['wallet_points'] ?? 0));
    if ($id <= 0) {
        $password = (string)($data['password'] ?? '');
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        db()->prepare('INSERT INTO users (name,email,phone,password_hash,role,wallet_points) VALUES (?,?,?,?,?,?)')
            ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), password_hash($password, PASSWORD_DEFAULT), $role, $points]);
        if ($role === 'distributor') {
            assign_distributor_uid((int)db()->lastInsertId());
        }
        return;
    }
    $password = (string)($data['password'] ?? '');
    if ($password !== '') {
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, wallet_points=?, password_hash=? WHERE id=?')
            ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), $role, $points, password_hash($password, PASSWORD_DEFAULT), $id]);
        if ($role === 'distributor') {
            assign_distributor_uid($id);
        }
        return;
    }
    db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, wallet_points=? WHERE id=?')
        ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), $role, $points, $id]);
    if ($role === 'distributor') {
        assign_distributor_uid($id);
    }
}

function generated_account_password(int $length = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function create_distributor_by_admin(array $data): array
{
    require_admin();
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '');
    $sponsorId = sponsor_id_from_ref(trim($data['referral_id'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($password === '') {
        $password = generated_account_password();
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
        throw new RuntimeException('Enter distributor name, mobile number and valid email.');
    }
    if (strlen($password) < 6) {
        throw new RuntimeException('Password must be at least 6 characters.');
    }
    $exists = db()->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1');
    $exists->execute([$email, $phone]);
    if ($exists->fetch()) {
        throw new RuntimeException('A user with this email or mobile number already exists.');
    }
    $stmt = db()->prepare('INSERT INTO users (name,email,phone,password_hash,role,sponsor_distributor_id,kyc_status) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'distributor', $sponsorId, 'not_submitted']);
    $userId = (int)db()->lastInsertId();
    return [
        'distributor_uid' => assign_distributor_uid($userId),
        'password' => $password,
    ];
}

function distributor_team(int $distributorId): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE sponsor_distributor_id = ? ORDER BY id DESC');
    $stmt->execute([$distributorId]);
    return $stmt->fetchAll();
}

function distributor_direct_downline(int $distributorId): array
{
    $stmt = db()->prepare('
        SELECT u.*,
            COALESCE((SELECT SUM(points) FROM bv_transactions b WHERE b.distributor_id = u.id),0) AS bv_total
        FROM users u
        WHERE u.sponsor_distributor_id = ?
        ORDER BY u.created_at DESC, u.id DESC
    ');
    $stmt->execute([$distributorId]);
    return $stmt->fetchAll();
}

function distributor_bv_total(int $distributorId, ?string $month = null): int
{
    $sql = 'SELECT COALESCE(SUM(points),0) FROM bv_transactions WHERE distributor_id = ?';
    $params = [$distributorId];
    if ($month !== null && $month !== '') {
        $sql .= ' AND share_month = ?';
        $params[] = $month;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function distributor_commission_total(int $distributorId, ?string $month = null): float
{
    $sql = 'SELECT COALESCE(SUM(commission_amount),0) FROM bv_transactions WHERE distributor_id = ?';
    $params = [$distributorId];
    if ($month !== null && $month !== '') {
        $sql .= ' AND share_month = ?';
        $params[] = $month;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function bv_months(): array
{
    $now = new DateTimeImmutable('first day of this month');
    return [
        'current' => $now->format('Y-m'),
        'previous' => $now->modify('-1 month')->format('Y-m'),
    ];
}

function distributor_bv_breakdown(int $distributorId, ?string $month = null): array
{
    $sql = "
        SELECT
            COALESCE(SUM(CASE WHEN type IN ('self','individual') THEN points ELSE 0 END),0) AS pbv,
            COALESCE(SUM(CASE WHEN type NOT IN ('self','individual') THEN points ELSE 0 END),0) AS gbv,
            COALESCE(SUM(points),0) AS tbv,
            COALESCE(SUM(commission_amount),0) AS earnings
        FROM bv_transactions
        WHERE distributor_id = ?
    ";
    $params = [$distributorId];
    if ($month !== null && $month !== '') {
        $sql .= ' AND share_month = ?';
        $params[] = $month;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'pbv' => (int)($row['pbv'] ?? 0),
        'gbv' => (int)($row['gbv'] ?? 0),
        'tbv' => (int)($row['tbv'] ?? 0),
        'earnings' => (float)($row['earnings'] ?? 0),
    ];
}

function distributor_monthly_bv_summary(int $distributorId): array
{
    $months = bv_months();
    return [
        'current_month' => $months['current'],
        'previous_month' => $months['previous'],
        'current' => distributor_bv_breakdown($distributorId, $months['current']),
        'previous' => distributor_bv_breakdown($distributorId, $months['previous']),
    ];
}

function valid_bv_month(string $month, ?string $fallback = null): string
{
    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        return $month;
    }
    return $fallback ?: bv_months()['current'];
}

function direct_member_bv_generated_for_sponsor(int $sponsorId, int $memberId, string $month): int
{
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(points),0)
        FROM bv_transactions
        WHERE distributor_id = ?
          AND source_user_id = ?
          AND type = 'direct_downline'
          AND share_month = ?
    ");
    $stmt->execute([$sponsorId, $memberId, $month]);
    return (int)$stmt->fetchColumn();
}

function distributor_branch_bv_rows(int $distributorId, string $month): array
{
    $month = valid_bv_month($month);
    $rows = [];
    foreach (distributor_direct_downline($distributorId) as $member) {
        $directGenerated = direct_member_bv_generated_for_sponsor($distributorId, (int)$member['id'], $month);
        $memberSummary = $member['role'] === 'distributor'
            ? distributor_bv_breakdown((int)$member['id'], $month)
            : ['pbv' => $directGenerated, 'gbv' => 0, 'tbv' => $directGenerated];
        $rows[] = [
            'member' => $member,
            'direct_generated' => $directGenerated,
            'pbv' => $memberSummary['pbv'],
            'gbv' => $memberSummary['gbv'],
            'tbv' => $memberSummary['tbv'],
        ];
    }
    return $rows;
}

function distributor_bv_transactions(int $distributorId, int $limit = 50): array
{
    $stmt = db()->prepare('
        SELECT b.*, u.name AS source_name
        FROM bv_transactions b
        LEFT JOIN users u ON u.id = b.source_user_id
        WHERE b.distributor_id = ?
        ORDER BY b.id DESC
        LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$distributorId]);
    return $stmt->fetchAll();
}

function distributor_genealogy_tree(int $distributorId, int $depth = 4): array
{
    $rootStmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $rootStmt->execute([$distributorId]);
    $root = $rootStmt->fetch();
    if (!$root) {
        return [];
    }
    $root['children'] = genealogy_children($distributorId, $depth);
    return $root;
}

function genealogy_children(int $parentId, int $depth): array
{
    if ($depth <= 0) {
        return [];
    }
    $children = distributor_direct_downline($parentId);
    foreach ($children as &$child) {
        $child['children'] = $child['role'] === 'distributor' ? genealogy_children((int)$child['id'], $depth - 1) : [];
    }
    return $children;
}

function render_genealogy_node(array $node): void
{
    ?>
    <li>
        <div class="tree-node">
            <b><?= e($node['name']) ?></b>
            <span><?= e($node['role']) ?><?= !empty($node['distributor_uid']) ? ' - ' . e($node['distributor_uid']) : '' ?></span>
            <em><?= (int)($node['bv_total'] ?? distributor_bv_total((int)$node['id'])) ?> BV</em>
        </div>
        <?php if (!empty($node['children'])): ?>
            <ul><?php foreach ($node['children'] as $child) render_genealogy_node($child); ?></ul>
        <?php endif; ?>
    </li>
    <?php
}

function add_monthly_bv_share(array $data): void
{
    require_admin();
    $distributorId = (int)($data['distributor_id'] ?? 0);
    $points = max(0, (int)($data['points'] ?? 0));
    $month = trim($data['share_month'] ?? date('Y-m'));
    $type = in_array($data['share_type'] ?? 'monthly_share', ['individual', 'team', 'group', 'monthly_share'], true) ? $data['share_type'] : 'monthly_share';
    $note = trim($data['note'] ?? '');
    if ($distributorId <= 0 || $points <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new RuntimeException('Select user, month and BV points.');
    }
    $stmt = db()->prepare("SELECT id FROM users WHERE id = ? AND role IN ('user','distributor')");
    $stmt->execute([$distributorId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('User not found.');
    }
    db()->prepare('INSERT INTO bv_transactions (distributor_id,points,type,share_month,note) VALUES (?,?,?,?,?)')
        ->execute([$distributorId, $points, $type, $month, $note !== '' ? $note : 'Monthly BV share by admin']);
}

function bv_level_commission_rates(): array
{
    return [
        1 => 18.0,
        2 => 5.0,
        3 => 2.0,
        4 => 2.0,
        5 => 1.0,
        6 => 1.0,
        7 => 1.0,
    ];
}

function bv_level_commission_points(int $points): array
{
    $target = (int)round($points * 0.30);
    $shares = [];
    $remainders = [];
    foreach (bv_level_commission_rates() as $level => $percent) {
        $raw = $points * ($percent / 100);
        $shares[$level] = (int)floor($raw);
        $remainders[$level] = $raw - $shares[$level];
    }

    $remaining = max(0, $target - array_sum($shares));
    arsort($remainders);
    foreach (array_keys($remainders) as $level) {
        if ($remaining <= 0) {
            break;
        }
        $shares[$level]++;
        $remaining--;
    }
    ksort($shares);
    return $shares;
}

function credit_order_bv(PDO $pdo, array $order): void
{
    $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? AND bv_points > 0');
    $items->execute([$order['id']]);
    $rows = $items->fetchAll();
    if (!$rows) {
        return;
    }
    $buyerStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $buyerStmt->execute([$order['user_id']]);
    $buyer = $buyerStmt->fetch();
    if (!$buyer) {
        return;
    }
    $insert = $pdo->prepare('
        INSERT INTO bv_transactions
            (distributor_id,source_user_id,order_id,source_order_item_id,points,commission_amount,commission_percent,level_no,type,share_month,note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ');
    $exists = $pdo->prepare('SELECT id FROM bv_transactions WHERE distributor_id = ? AND source_order_item_id = ? AND level_no <=> ? AND type = ? LIMIT 1');
    foreach ($rows as $item) {
        $points = (int)$item['bv_points'] * (int)$item['qty'];
        if ($points <= 0) {
            continue;
        }
        $commissionPointShares = bv_level_commission_points($points);
        if ($buyer['role'] === 'distributor') {
            $selfPercent = bv_level_commission_rates()[1] ?? 18.0;
            $selfCommission = round($points * ($selfPercent / 100), 2);
            insert_bv_once(
                $exists,
                $insert,
                (int)$buyer['id'],
                (int)$buyer['id'],
                (int)$order['id'],
                (int)$item['id'],
                $points,
                $selfCommission,
                $selfPercent,
                1,
                'self',
                'Level 1 self commission from ' . $points . ' BV at ' . rtrim(rtrim((string)$selfPercent, '0'), '.') . '%'
            );
        }
        $uplineId = (int)($buyer['sponsor_distributor_id'] ?? 0);
        foreach (bv_level_commission_rates() as $level => $percent) {
            if ($level === 1) {
                continue;
            }
            if ($uplineId <= 0) {
                break;
            }
            $commission = round($points * ($percent / 100), 2);
            $commissionPoints = $commissionPointShares[$level] ?? 0;
            $type = $level === 2 ? 'direct_downline' : 'team';
            insert_bv_once(
                $exists,
                $insert,
                $uplineId,
                (int)$buyer['id'],
                (int)$order['id'],
                (int)$item['id'],
                $commissionPoints,
                $commission,
                $percent,
                (int)$level,
                $type,
                'Level ' . $level . ' commission from ' . $points . ' BV at ' . rtrim(rtrim((string)$percent, '0'), '.') . '%'
            );
            $uplineId = sponsor_distributor_id($pdo, $uplineId);
        }
    }
}

function sponsor_distributor_id(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT sponsor_distributor_id FROM users WHERE id = ? AND role = 'distributor' LIMIT 1");
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function insert_bv_once(PDOStatement $exists, PDOStatement $insert, int $distributorId, int $sourceUserId, int $orderId, int $itemId, int $points, float $commissionAmount, ?float $commissionPercent, ?int $levelNo, string $type, string $note): void
{
    $exists->execute([$distributorId, $itemId, $levelNo, $type]);
    if ($exists->fetch()) {
        return;
    }
    $insert->execute([$distributorId, $sourceUserId, $orderId, $itemId, $points, $commissionAmount, $commissionPercent, $levelNo, $type, date('Y-m'), $note]);
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
    credit_order_bv($pdo, $order);
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
