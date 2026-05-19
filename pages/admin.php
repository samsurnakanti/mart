<?php
$admin = require_admin();
$module = $_GET['module'] ?? 'dashboard';
$allowedModules = ['dashboard', 'sliders', 'categories', 'products', 'inventory', 'orders', 'reports', 'settings'];
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
            <a class="primary-cta" href="index.php?page=admin&module=products">Add Product</a>
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
                <div class="stats">Points Issued<b><?= $stats['points'] ?></b></div>
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
                    <tr><th>ID</th><th>Product</th><th>Type</th><th>MRP</th><th>Price</th><th>Tax</th><th>Points</th><th>Stock</th><th>Action</th></tr>
                    <?php foreach ($products as $p): ?>
                        <tr><td><?= (int)$p['id'] ?></td><td><?= e($p['name']) ?><br><span class="small"><?= e($p['category']) ?></span></td><td><?= e($p['product_type']) ?></td><td><?= money($p['mrp']) ?></td><td><?= money($p['selling_price']) ?></td><td><?= e($p['tax_percent']) ?>%</td><td><?= (int)$p['discount_points'] ?></td><td><?= (int)$p['stock'] ?></td><td><a class="see-all-btn" href="index.php?page=admin&module=products&edit=<?= (int)$p['id'] ?>">Edit</a> <a class="danger-link" href="index.php?action=delete_product&id=<?= (int)$p['id'] ?>">Disable</a></td></tr>
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
