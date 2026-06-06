<?php
$admin = require_admin();
$module = $_GET['module'] ?? 'dashboard';
$allowedModules = ['dashboard', 'sliders', 'categories', 'products', 'inventory', 'orders', 'distributors', 'bv', 'reports', 'settings'];
if (!in_array($module, $allowedModules, true)) {
    $module = 'dashboard';
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$editCategory = null;
if (isset($_GET['edit_category'])) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int)$_GET['edit_category']]);
    $editCategory = $stmt->fetch();
}
$editSlider = null;
if (isset($_GET['edit_slider'])) {
    $stmt = db()->prepare('SELECT * FROM sliders WHERE id = ?');
    $stmt->execute([(int)$_GET['edit_slider']]);
    $editSlider = $stmt->fetch();
}

$products = db()->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
$categories = categories();
$sliders = sliders();
$distributors = db()->query("SELECT * FROM users WHERE role = 'distributor' ORDER BY name")->fetchAll();
$selectedBvDistributorId = (int)($_GET['bv_distributor'] ?? 0);
$selectedBvMonth = valid_bv_month($_GET['bv_month'] ?? '', date('Y-m'));
$selectedBvDistributor = null;
foreach ($distributors as $d) {
    if ((int)$d['id'] === $selectedBvDistributorId) {
        $selectedBvDistributor = $d;
        break;
    }
}
$selectedBranchRows = $selectedBvDistributor ? distributor_branch_bv_rows($selectedBvDistributorId, $selectedBvMonth) : [];
$bvRows = db()->query("
    SELECT b.*, d.name AS distributor_name, d.distributor_uid, s.name AS source_name
    FROM bv_transactions b
    JOIN users d ON d.id = b.distributor_id
    LEFT JOIN users s ON s.id = b.source_user_id
    ORDER BY b.id DESC
    LIMIT 100
")->fetchAll();
$activeCategories = array_values(array_filter($categories, fn($c) => (int)$c['is_active'] === 1));
$orders = db()->query("SELECT o.*, u.name, u.email,
    u.wallet_points AS wallet_balance,
    (SELECT COALESCE(SUM(remaining_points),0) FROM user_cards uc WHERE uc.user_id = o.user_id AND uc.status = 'active') AS card_capacity_left
    FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 100")->fetchAll();
$orderItemsByOrder = [];
if ($orders) {
    $orderIds = array_map(fn($o) => (int)$o['id'], $orders);
    $itemStmt = db()->prepare('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ') ORDER BY order_id DESC, id');
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll() as $itemRow) {
        $orderItemsByOrder[(int)$itemRow['order_id']][] = $itemRow;
    }
}
$stats = [
    'products' => (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'active_products' => (int)db()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn(),
    'low_stock' => (int)db()->query('SELECT COUNT(*) FROM products WHERE stock <= 5')->fetchColumn(),
    'orders' => (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'sales' => (float)db()->query('SELECT COALESCE(SUM(grand_total),0) FROM orders')->fetchColumn(),
    'points' => (int)db()->query('SELECT COALESCE(SUM(total_points),0) FROM user_cards')->fetchColumn(),
    'bv' => (int)db()->query('SELECT COALESCE(SUM(points),0) FROM bv_transactions')->fetchColumn(),
    'bv_month' => (int)db()->query("SELECT COALESCE(SUM(points),0) FROM bv_transactions WHERE share_month = '" . date('Y-m') . "'")->fetchColumn(),
];
$topProducts = db()->query('SELECT product_name, SUM(qty) qty, SUM(unit_price * qty) total FROM order_items GROUP BY product_name ORDER BY qty DESC LIMIT 8')->fetchAll();
?>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">VMC<span>marts</span><small>Admin</small></div>
        <a class="<?= $module === 'dashboard' ? 'active' : '' ?>" href="index.php?page=admin&module=dashboard">Dashboard</a>
        <a class="<?= $module === 'sliders' ? 'active' : '' ?>" href="index.php?page=admin&module=sliders">Sliders</a>
        <a class="<?= $module === 'categories' ? 'active' : '' ?>" href="index.php?page=admin&module=categories">Categories</a>
        <a class="<?= $module === 'products' ? 'active' : '' ?>" href="index.php?page=admin&module=products">Products</a>
        <a class="<?= $module === 'inventory' ? 'active' : '' ?>" href="index.php?page=admin&module=inventory">Inventory</a>
        <a class="<?= $module === 'orders' ? 'active' : '' ?>" href="index.php?page=admin&module=orders">Orders</a>
        <a class="<?= $module === 'distributors' ? 'active' : '' ?>" href="index.php?page=admin&module=distributors">Distributors</a>
        <a class="<?= $module === 'bv' ? 'active' : '' ?>" href="index.php?page=admin&module=bv">BV Share</a>
        <a class="<?= $module === 'reports' ? 'active' : '' ?>" href="index.php?page=admin&module=reports">Reports</a>
        <a class="<?= $module === 'settings' ? 'active' : '' ?>" href="index.php?page=admin&module=settings">Set Password</a>
        <?php if ($admin['role'] === 'super_admin'): ?><a href="index.php?page=super_admin">Super Admin</a><?php endif; ?>
        <a href="index.php">View Store</a>
    </aside>

    <section class="admin-content">
        <div class="admin-topbar">
            <div>
                <span><?= e($admin['role']) ?></span>
                <h1><?= e(ucwords(str_replace('_', ' ', $module))) ?></h1>
            </div>
            <a class="primary-cta" href="index.php?page=admin&module=<?= $module === 'distributors' ? 'distributors' : 'products' ?>"><?= $module === 'distributors' ? 'Create Distributor' : 'Add Product' ?></a>
        </div>

        <?php if ($module === 'dashboard'): ?>
            <div class="grid-3">
                <div class="stats">Products<b><?= $stats['products'] ?></b></div>
                <div class="stats">Orders<b><?= $stats['orders'] ?></b></div>
                <div class="stats">Sales<b><?= money($stats['sales']) ?></b></div>
            </div><br>
            <div class="grid-3">
                <div class="stats">Active Products<b><?= $stats['active_products'] ?></b></div>
                <div class="stats">Low Stock<b><?= $stats['low_stock'] ?></b></div>
                <div class="stats">BV Points<b><?= $stats['bv'] ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Recent Orders</h2><br>
                <table class="table">
                    <tr><th>Order</th><th>User</th><th>Total</th><th>Status</th><th>Date</th></tr>
                    <?php foreach (array_slice($orders, 0, 8) as $o): ?>
                        <tr><td>#<?= (int)$o['id'] ?></td><td><?= e($o['name']) ?></td><td><?= money($o['grand_total']) ?></td><td><?= e($o['status']) ?></td><td><?= e($o['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'products'): ?>
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2 class="section-title"><?= $edit ? 'Edit Product' : 'Add Product' ?></h2>
                        <p class="section-kicker">Upload main image and 3-4 gallery images for product detail sliders.</p>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                    <div class="field"><label>Product Name</label><input name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
                    <div class="field"><label>Category</label><select name="category" required><?php foreach ($activeCategories as $cat): ?><option value="<?= e($cat['name']) ?>" <?= (($edit['category'] ?? '') === $cat['name']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>MRP</label><input type="number" step="0.01" name="mrp" value="<?= e($edit['mrp'] ?? '0') ?>"></div>
                    <div class="field"><label>Selling Price</label><input type="number" step="0.01" name="selling_price" value="<?= e($edit['selling_price'] ?? '0') ?>" required></div>
                    <div class="field"><label>Tax Percent</label><input type="number" step="0.01" name="tax_percent" value="<?= e($edit['tax_percent'] ?? '0') ?>"></div>
                    <div class="field"><label>Discount Points</label><input type="number" name="discount_points" value="<?= e($edit['discount_points'] ?? '0') ?>"></div>
                    <div class="field"><label>BV Points</label><input type="number" name="bv_points" value="<?= e($edit['bv_points'] ?? '0') ?>"></div>
                    <div class="field"><label>Stock</label><input type="number" name="stock" value="<?= e($edit['stock'] ?? '0') ?>"></div>
                    <div class="field"><label>Type</label><select name="product_type"><option value="regular" <?= (($edit['product_type'] ?? '') === 'regular') ? 'selected' : '' ?>>Regular Product</option><option value="discount_points" <?= (($edit['product_type'] ?? '') === 'discount_points') ? 'selected' : '' ?>>Discount Points Product</option></select></div>
                    <div class="field full"><label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
                    <div class="field"><label>Main Image</label><input type="file" name="image" accept="image/*"></div>
                    <div class="field"><label>Gallery Images (3-4 recommended)</label><input type="file" name="images[]" accept="image/*" multiple></div>
                    <div class="field"><label>Active</label><label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="is_active" <?= !isset($edit) || (int)$edit['is_active'] ? 'checked' : '' ?>> Show on frontend</label></div>
                    <button class="pill-btn full"><?= $edit ? 'Update Product' : 'Add Product' ?></button>
                </form>
                <?php if ($edit): $gallery = product_extra_images((int)$edit['id']); ?>
                    <div class="admin-image-grid">
                        <?php if (product_image($edit['image_path'])): ?><div><img src="<?= e($edit['image_path']) ?>" alt=""><span>Main image</span></div><?php endif; ?>
                        <?php foreach ($gallery as $img): ?>
                            <div><img src="<?= e($img['image_path']) ?>" alt=""><a class="danger-link" href="index.php?action=delete_product_image&id=<?= (int)$img['id'] ?>&product_id=<?= (int)$edit['id'] ?>">Remove</a></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Product List</h2><br>
                <table class="table">
                    <tr><th>ID</th><th>Product</th><th>Type</th><th>MRP</th><th>Price</th><th>Tax</th><th>Points</th><th>BV</th><th>Stock</th><th>Action</th></tr>
                    <?php foreach ($products as $p): ?>
                        <tr><td><?= (int)$p['id'] ?></td><td><?= e($p['name']) ?><br><span class="small"><?= e($p['category']) ?></span></td><td><?= e($p['product_type']) ?></td><td><?= money($p['mrp']) ?></td><td><?= money($p['selling_price']) ?></td><td><?= e($p['tax_percent']) ?>%</td><td><?= (int)$p['discount_points'] ?></td><td><?= (int)$p['bv_points'] ?></td><td><?= (int)$p['stock'] ?></td><td><a class="see-all-btn" href="index.php?page=admin&module=products&edit=<?= (int)$p['id'] ?>">Edit</a> <a class="danger-link" href="index.php?action=delete_product&id=<?= (int)$p['id'] ?>">Disable</a></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'categories'): ?>
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2 class="section-title"><?= $editCategory ? 'Edit Category' : 'Add Category' ?></h2>
                        <p class="section-kicker">Each category gets its own image and products are assigned under it.</p>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="id" value="<?= (int)($editCategory['id'] ?? 0) ?>">
                    <div class="field"><label>Category Name</label><input name="name" value="<?= e($editCategory['name'] ?? '') ?>" required></div>
                    <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= e($editCategory['sort_order'] ?? 0) ?>"></div>
                    <div class="field"><label>Category Image</label><input type="file" name="image" accept="image/*"></div>
                    <div class="field"><label>Active</label><label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="is_active" <?= !isset($editCategory) || (int)$editCategory['is_active'] ? 'checked' : '' ?>> Show on frontend</label></div>
                    <button class="pill-btn full"><?= $editCategory ? 'Update Category' : 'Add Category' ?></button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Category List</h2><br>
                <table class="table">
                    <tr><th>Image</th><th>Category</th><th>Products</th><th>Status</th><th>Order</th><th>Action</th></tr>
                    <?php foreach ($categories as $cat): ?>
                        <?php $countStmt = db()->prepare('SELECT COUNT(*) FROM products WHERE category = ?'); $countStmt->execute([$cat['name']]); ?>
                        <tr>
                            <td><?php if (product_image($cat['image_path'])): ?><img style="width:54px;height:54px;object-fit:cover;border-radius:12px" src="<?= e($cat['image_path']) ?>" alt=""><?php endif; ?></td>
                            <td><?= e($cat['name']) ?></td>
                            <td><?= (int)$countStmt->fetchColumn() ?></td>
                            <td><?= (int)$cat['is_active'] ? 'Active' : 'Hidden' ?></td>
                            <td><?= (int)$cat['sort_order'] ?></td>
                            <td><a class="see-all-btn" href="index.php?page=admin&module=categories&edit_category=<?= (int)$cat['id'] ?>">Edit</a> <a class="danger-link" href="index.php?action=delete_category&id=<?= (int)$cat['id'] ?>">Disable</a></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'sliders'): ?>
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2 class="section-title"><?= $editSlider ? 'Edit Slider' : 'Add Slider' ?></h2>
                        <p class="section-kicker">These images appear on the homepage hero slider.</p>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="action" value="save_slider">
                    <input type="hidden" name="id" value="<?= (int)($editSlider['id'] ?? 0) ?>">
                    <div class="field"><label>Title</label><input name="title" value="<?= e($editSlider['title'] ?? '') ?>" required></div>
                    <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= e($editSlider['sort_order'] ?? 0) ?>"></div>
                    <div class="field full"><label>Subtitle</label><textarea name="subtitle"><?= e($editSlider['subtitle'] ?? '') ?></textarea></div>
                    <div class="field"><label>Button Text</label><input name="button_text" value="<?= e($editSlider['button_text'] ?? '') ?>"></div>
                    <div class="field"><label>Button Link</label><input name="button_link" value="<?= e($editSlider['button_link'] ?? '') ?>"></div>
                    <div class="field"><label>Image</label><input type="file" name="image" accept="image/*"></div>
                    <div class="field"><label>Active</label><label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="is_active" <?= !isset($editSlider) || (int)$editSlider['is_active'] ? 'checked' : '' ?>> Show on frontend</label></div>
                    <button class="pill-btn full"><?= $editSlider ? 'Update Slider' : 'Add Slider' ?></button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Slider List</h2><br>
                <table class="table">
                    <tr><th>Image</th><th>Title</th><th>Status</th><th>Order</th><th>Action</th></tr>
                    <?php foreach ($sliders as $slide): ?>
                        <tr>
                            <td><img style="width:90px;height:54px;object-fit:cover;border-radius:12px" src="<?= e($slide['image_path']) ?>" alt=""></td>
                            <td><?= e($slide['title']) ?></td>
                            <td><?= (int)$slide['is_active'] ? 'Active' : 'Hidden' ?></td>
                            <td><?= (int)$slide['sort_order'] ?></td>
                            <td><a class="see-all-btn" href="index.php?page=admin&module=sliders&edit_slider=<?= (int)$slide['id'] ?>">Edit</a> <a class="danger-link" href="index.php?action=delete_slider&id=<?= (int)$slide['id'] ?>">Disable</a></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'inventory'): ?>
            <section class="panel">
                <h2 class="section-title">Inventory Module</h2><br>
                <form method="post">
                    <input type="hidden" name="action" value="update_inventory">
                    <table class="table">
                        <tr><th>Product</th><th>Status</th><th>Current Stock</th><th>Update Stock</th></tr>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?= e($p['name']) ?><br><span class="small"><?= e($p['category']) ?></span></td>
                                <td><?= (int)$p['stock'] <= 5 ? '<span class="stock-low">Low Stock</span>' : '<span class="stock-ok">Available</span>' ?></td>
                                <td><?= (int)$p['stock'] ?></td>
                                <td><input style="width:90px" type="number" min="0" name="stock[<?= (int)$p['id'] ?>]" value="<?= (int)$p['stock'] ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </table><br>
                    <button class="pill-btn">Update Inventory</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($module === 'orders'): ?>
            <section class="panel">
                <h2 class="section-title">Order Management</h2><br>
                <table class="table">
                    <tr><th>Order</th><th>User</th><th>Products</th><th>Total</th><th>Card Points</th><th>Status</th><th>Address</th></tr>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>#<?= (int)$o['id'] ?><br><span class="small"><?= e($o['created_at']) ?></span></td>
                            <td><?= e($o['name']) ?><br><span class="small"><?= e($o['email']) ?></span></td>
                            <td>
                                <details class="order-items-view">
                                    <summary class="see-all-btn">View Products</summary>
                                    <div class="order-items-popover">
                                        <?php foreach ($orderItemsByOrder[(int)$o['id']] ?? [] as $item): ?>
                                            <div><?= e($item['product_name']) ?> × <?= (int)$item['qty'] ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </td>
                            <td><?= money($o['grand_total']) ?></td>
                            <td>
                                Reward earned <?= (int)$o['points_earned'] ?><br>
                                Used <?= (int)$o['points_used'] ?><br>
                                <span class="small">Wallet <?= (int)$o['wallet_balance'] ?> / Card capacity <?= (int)$o['card_capacity_left'] ?></span>
                            </td>
                            <td>
                                <?php if ($o['status'] === 'Placed'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="complete_order">
                                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                        <input style="width:100px" type="number" min="0" max="<?= max(0, (int)$o['card_capacity_left'] - (int)$o['wallet_balance']) ?>" name="points_to_allot" value="0" title="Reward points to add to customer wallet">
                                        <button class="see-all-btn">Complete Order</button>
                                    </form>
                                <?php else: ?>
                                    <?= e($o['status']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($o['shipping_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'distributors'): ?>
            <div class="grid-3">
                <div class="stats">Distributors<b><?= count($distributors) ?></b></div>
                <div class="stats">KYC Submitted<b><?= (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'distributor' AND kyc_status = 'submitted'")->fetchColumn() ?></b></div>
                <div class="stats">KYC Approved<b><?= (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'distributor' AND kyc_status = 'approved'")->fetchColumn() ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Create Distributor</h2>
                <p class="section-kicker">Admin creates the account, distributor ID is generated automatically, and password is shown once after creation.</p><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="create_distributor">
                    <div class="field"><label>Name</label><input name="name" required></div>
                    <div class="field"><label>Mobile Number</label><input name="phone" inputmode="tel" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email" required></div>
                    <div class="field"><label>Password</label><input type="text" name="password" placeholder="Leave blank for auto password"></div>
                    <div class="field full"><label>Sponsor Distributor ID</label><input name="referral_id" placeholder="Optional"></div>
                    <button class="pill-btn full">Create Distributor</button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Distributor Accounts</h2><br>
                <table class="table">
                    <tr><th>Distributor ID</th><th>Name</th><th>Mobile</th><th>Email</th><th>Sponsor</th><th>KYC</th><th>Joined</th></tr>
                    <?php foreach ($distributors as $d): ?>
                        <?php
                        $sponsorName = '-';
                        if (!empty($d['sponsor_distributor_id'])) {
                            $sponsorStmt = db()->prepare('SELECT name, distributor_uid FROM users WHERE id = ?');
                            $sponsorStmt->execute([(int)$d['sponsor_distributor_id']]);
                            $sponsor = $sponsorStmt->fetch();
                            $sponsorName = $sponsor ? $sponsor['name'] . ' - ' . $sponsor['distributor_uid'] : '-';
                        }
                        ?>
                        <tr>
                            <td><b><?= e($d['distributor_uid']) ?></b></td>
                            <td><?= e($d['name']) ?></td>
                            <td><?= e($d['phone']) ?></td>
                            <td><?= e($d['email']) ?></td>
                            <td><?= e($sponsorName) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $d['kyc_status']))) ?></td>
                            <td><?= e($d['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$distributors): ?><tr><td colspan="7">No distributors yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'bv'): ?>
            <div class="grid-3">
                <div class="stats">Total BV<b><?= $stats['bv'] ?></b></div>
                <div class="stats">Distributors<b><?= count($distributors) ?></b></div>
                <div class="stats">This Month<b><?= $stats['bv_month'] ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Monthly BV Share</h2>
                <p class="section-kicker">Admin can decide monthly individual, team or group BV share for distributors.</p><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="add_monthly_bv_share">
                    <div class="field"><label>Distributor</label><select name="distributor_id" required>
                        <option value="">Select distributor</option>
                        <?php foreach ($distributors as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?> - <?= e($d['distributor_uid']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Month</label><input type="month" name="share_month" value="<?= e(date('Y-m')) ?>" required></div>
                    <div class="field"><label>Share Type</label><select name="share_type">
                        <option value="individual">Individual Share</option>
                        <option value="team">Team Share</option>
                        <option value="group">Group Share</option>
                        <option value="monthly_share">Monthly Share</option>
                    </select></div>
                    <div class="field"><label>BV Points</label><input type="number" min="1" name="points" required></div>
                    <div class="field full"><label>Note</label><input name="note" placeholder="Optional note"></div>
                    <button class="pill-btn full">Add BV Share</button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Distributor Monthly BV Points</h2><br>
                <table class="table">
                    <tr><th>Distributor</th><th>Current PBV</th><th>Current GBV</th><th>Current TBV</th><th>Current Earnings</th><th>Previous PBV</th><th>Previous GBV</th><th>Previous TBV</th><th>Previous Earnings</th></tr>
                    <?php foreach ($distributors as $d): ?>
                        <?php $summary = distributor_monthly_bv_summary((int)$d['id']); ?>
                        <tr>
                            <td><?= e($d['name']) ?><br><span class="small"><?= e($d['distributor_uid']) ?></span></td>
                            <td><?= $summary['current']['pbv'] ?></td>
                            <td><?= $summary['current']['gbv'] ?></td>
                            <td><a class="see-all-btn" href="index.php?page=admin&module=bv&bv_distributor=<?= (int)$d['id'] ?>&bv_month=<?= e($summary['current_month']) ?>"><?= $summary['current']['tbv'] ?></a></td>
                            <td><?= money($summary['current']['earnings']) ?></td>
                            <td><?= $summary['previous']['pbv'] ?></td>
                            <td><?= $summary['previous']['gbv'] ?></td>
                            <td><a class="see-all-btn" href="index.php?page=admin&module=bv&bv_distributor=<?= (int)$d['id'] ?>&bv_month=<?= e($summary['previous_month']) ?>"><?= $summary['previous']['tbv'] ?></a></td>
                            <td><?= money($summary['previous']['earnings']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$distributors): ?><tr><td colspan="9">No distributors yet.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <?php if ($selectedBvDistributor): ?>
                <section class="panel">
                    <h2 class="section-title">Team BV Details - <?= e($selectedBvDistributor['name']) ?> / <?= e($selectedBvMonth) ?></h2>
                    <p class="section-kicker">Direct member earned BV, group BV under that member, and total business for the selected month.</p><br>
                    <table class="table">
                        <tr><th>Direct Member</th><th>Earned BV</th><th>Group BV</th><th>Total BV</th><th>Role</th></tr>
                        <?php foreach ($selectedBranchRows as $row): ?>
                            <tr>
                                <td><?= e($row['member']['name']) ?><br><span class="small"><?= e($row['member']['email']) ?></span></td>
                                <td><?= (int)$row['pbv'] ?></td>
                                <td><?= (int)$row['gbv'] ?></td>
                                <td><?= (int)$row['tbv'] ?></td>
                                <td><?= e($row['member']['role']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$selectedBranchRows): ?><tr><td colspan="5">No team BV found for this month.</td></tr><?php endif; ?>
                    </table>
                </section><br>
            <?php endif; ?>
            <section class="panel">
                <h2 class="section-title">BV Ledger</h2><br>
                <table class="table">
                    <tr><th>Distributor</th><th>Type</th><th>Level</th><th>BV</th><th>Rate</th><th>Earning</th><th>Month</th><th>Source</th><th>Note</th><th>Date</th></tr>
                    <?php foreach ($bvRows as $row): ?>
                        <tr>
                            <td><?= e($row['distributor_name']) ?><br><span class="small"><?= e($row['distributor_uid']) ?></span></td>
                            <td><?= e(str_replace('_', ' ', $row['type'])) ?></td>
                            <td><?= $row['level_no'] ? 'L' . (int)$row['level_no'] : '-' ?></td>
                            <td><?= (int)$row['points'] ?></td>
                            <td><?= $row['commission_percent'] !== null ? e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) . '%' : '-' ?></td>
                            <td><?= money((float)$row['commission_amount']) ?></td>
                            <td><?= e($row['share_month']) ?></td>
                            <td><?= e($row['source_name'] ?? 'Admin') ?></td>
                            <td><?= e($row['note']) ?></td>
                            <td><?= e($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bvRows): ?><tr><td colspan="10">No BV transactions yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'reports'): ?>
            <div class="grid-3">
                <div class="stats">Gross Sales<b><?= money($stats['sales']) ?></b></div>
                <div class="stats">Orders<b><?= $stats['orders'] ?></b></div>
                <div class="stats">Points Issued<b><?= $stats['points'] ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Top Products Report</h2><br>
                <table class="table">
                    <tr><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr>
                    <?php foreach ($topProducts as $row): ?>
                        <tr><td><?= e($row['product_name']) ?></td><td><?= (int)$row['qty'] ?></td><td><?= money($row['total']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'settings'): ?>
            <section class="panel">
                <h2 class="section-title">Set Password</h2><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="change_own_password">
                    <div class="field"><label>Current Password</label><input type="password" name="current_password" required></div>
                    <div class="field"><label>New Password</label><input type="password" name="new_password" required></div>
                    <button class="pill-btn full">Update Password</button>
                </form>
            </section>
        <?php endif; ?>
    </section>
</div>
