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
        $line = (float)$row['selling_price'] * (int)$row['qty'];
        $row['line_subtotal'] = $line;
        $row['line_tax'] = $line * ((float)$row['tax_percent'] / 100);
        $row['line_total'] = $row['line_subtotal'] + $row['line_tax'];
    }
    return $rows;
}

function cart_totals(array $rows): array
{
    $subtotal = 0.0;
    $tax = 0.0;
    $points = 0;
    foreach ($rows as $row) {
        $subtotal += (float)$row['line_subtotal'];
        $tax += (float)$row['line_tax'];
        $points += (int)$row['discount_points'] * (int)$row['qty'];
    }
    return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax, 'points' => $points];
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
    $pointsUsed = max(0, (int)($data['points_used'] ?? 0));
    $pointsUsed = min($pointsUsed, (int)$user['wallet_points'], (int)floor($totals['total']));
    $grand = max(0, $totals['total'] - $pointsUsed);
    $pointsEarned = $totals['points'];

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
            ->execute([$user['id'], $orderId, $pointsUsed, 'debit', 'Redeemed on order']);
    }
    if ($pointsEarned > 0) {
        $pdo->prepare('INSERT INTO wallet_transactions (user_id,order_id,points,type,note) VALUES (?,?,?,?,?)')
            ->execute([$user['id'], $orderId, $pointsEarned, 'credit', 'Earned from purchased products / discount cards']);
    }

    $pdo->prepare('UPDATE users SET wallet_points = wallet_points - ? + ? WHERE id = ?')->execute([$pointsUsed, $pointsEarned, $user['id']]);
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
    db()->prepare('UPDATE users SET name=?, email=?, phone=?, role=?, wallet_points=? WHERE id=?')
        ->execute([trim($data['name'] ?? ''), strtolower(trim($data['email'] ?? '')), trim($data['phone'] ?? ''), $role, $points, $id]);
}

function update_order_status(int $orderId, string $status): void
{
    $allowed = ['Placed', 'Packed', 'Shipped', 'Delivered', 'Cancelled'];
    if (!in_array($status, $allowed, true)) {
        $status = 'Placed';
    }
    db()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
}

function render_product_card(array $p): void
{
    $off = (float)$p['mrp'] > 0 ? max(0, round((((float)$p['mrp'] - (float)$p['selling_price']) / (float)$p['mrp']) * 100)) : 0;
    ?>
    <article class="prod-card">
        <a class="prod-img-wrap" href="index.php?page=product&id=<?= (int)$p['id'] ?>">
            <?php if ($off > 0): ?><span class="prod-badge"><?= e($off) ?>% OFF</span><?php endif; ?>
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
                <?php if ((float)$p['mrp'] > (float)$p['selling_price']): ?><span class="price-was"><?= money($p['mrp']) ?></span><?php endif; ?>
            </div>
            <div class="points-line">Earn <?= (int)$p['discount_points'] ?> discount points</div>
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
