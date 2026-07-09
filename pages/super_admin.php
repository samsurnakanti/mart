<?php
$super = require_super_admin();
$module = $_GET['module'] ?? 'overview';
$allowedModules = ['overview', 'users', 'roles', 'wallets', 'stock_pointers', 'system'];
if (!in_array($module, $allowedModules, true)) {
    $module = 'overview';
}
$editUser = null;
if (isset($_GET['edit_user'])) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['edit_user']]);
    $editUser = $stmt->fetch();
    $module = 'users';
}
$users = db()->query('SELECT * FROM users ORDER BY id DESC')->fetchAll();
$orders = (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$products = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$wallet = (int)db()->query('SELECT COALESCE(SUM(wallet_points),0) FROM users')->fetchColumn();
$admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn();
$distributors = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'distributor'")->fetchColumn();
$stockPointers = stock_pointer_users();
$stockPointerTransfers = stock_pointer_transfer_history(0, 60);
$activeProducts = db()->query("SELECT * FROM products WHERE is_active = 1 ORDER BY category, name")->fetchAll();
?>
<div class="admin-shell">
    <aside class="admin-sidebar super">
        <div class="admin-brand">VMC<span>marts</span><small>Super Admin</small></div>
        <a class="<?= $module === 'overview' ? 'active' : '' ?>" href="index.php?page=super_admin&module=overview">Overview</a>
        <a class="<?= $module === 'users' ? 'active' : '' ?>" href="index.php?page=super_admin&module=users">Users</a>
        <a class="<?= $module === 'roles' ? 'active' : '' ?>" href="index.php?page=super_admin&module=roles">Roles</a>
        <a class="<?= $module === 'wallets' ? 'active' : '' ?>" href="index.php?page=super_admin&module=wallets">Wallets</a>
        <a class="<?= $module === 'stock_pointers' ? 'active' : '' ?>" href="index.php?page=super_admin&module=stock_pointers">Stock Pointers</a>
        <a class="<?= $module === 'system' ? 'active' : '' ?>" href="index.php?page=super_admin&module=system">System</a>
        <a href="index.php?page=admin">Admin Panel</a>
        <a href="index.php">View Store</a>
    </aside>

    <section class="admin-content">
        <div class="admin-topbar">
            <div>
                <span>owner access</span>
                <h1><?= e(ucwords($module)) ?></h1>
            </div>
            <a class="primary-cta" href="index.php?page=super_admin&module=users">Create User</a>
        </div>

        <?php if ($module === 'overview'): ?>
            <div class="grid-3">
                <div class="stats">Users<b><?= count($users) ?></b></div>
                <div class="stats">Distributors<b><?= $distributors ?></b></div>
                <div class="stats">Wallet Points<b><?= $wallet ?></b></div>
            </div><br>
            <div class="grid-3">
                <div class="stats">Products<b><?= $products ?></b></div>
                <div class="stats">Orders<b><?= $orders ?></b></div>
                <div class="stats">Stock Pointers<b><?= count($stockPointers) ?></b></div>
            </div>
        <?php endif; ?>

        <?php if ($module === 'users' || $module === 'roles' || $module === 'wallets'): ?>
            <section class="panel">
                <h2 class="section-title"><?= $editUser ? 'Edit User' : 'Create User' ?></h2><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="id" value="<?= (int)($editUser['id'] ?? 0) ?>">
                    <div class="field"><label>Name</label><input name="name" value="<?= e($editUser['name'] ?? '') ?>" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($editUser['email'] ?? '') ?>" required></div>
                    <div class="field"><label>Phone</label><input name="phone" value="<?= e($editUser['phone'] ?? '') ?>"></div>
                    <div class="field"><label>Role</label><select name="role"><?php foreach (['user','distributor','stock_pointer','admin','super_admin'] as $role): ?><option value="<?= e($role) ?>" <?= (($editUser['role'] ?? 'user') === $role) ? 'selected' : '' ?>><?= e($role) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Wallet Points</label><input type="number" name="wallet_points" value="<?= e($editUser['wallet_points'] ?? 0) ?>"></div>
                    <div class="field"><label><?= $editUser ? 'Set New Password' : 'Password for New User' ?></label><input type="password" name="password"></div>
                    <button class="pill-btn full"><?= $editUser ? 'Update User' : 'Create User' ?></button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">All Users</h2><br>
                <table class="table">
                    <tr><th>ID</th><th>Distributor ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Wallet</th><th>Action</th></tr>
                    <?php foreach ($users as $u): ?>
                        <tr><td><?= (int)$u['id'] ?></td><td><?= e($u['distributor_uid'] ?: '-') ?></td><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['phone']) ?></td><td><span class="role-pill"><?= e($u['role']) ?></span></td><td><?= (int)$u['wallet_points'] ?></td><td><a class="see-all-btn" href="index.php?page=super_admin&edit_user=<?= (int)$u['id'] ?>">Edit</a></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'stock_pointers'): ?>
            <div class="grid-3">
                <div class="stats">Stock Pointers<b><?= count($stockPointers) ?></b></div>
                <div class="stats">Central Products<b><?= count($activeProducts) ?></b></div>
                <div class="stats">Transfers<b><?= count($stockPointerTransfers) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Allocate Stock</h2>
                <p class="section-kicker">Move stock from super admin inventory into a stock pointer POS inventory.</p><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="allocate_stock_to_pointer">
                    <div class="field"><label>Stock Pointer</label><select name="stock_pointer_id" required>
                        <option value="">Select stock pointer</option>
                        <?php foreach ($stockPointers as $sp): ?>
                            <option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?> - <?= e($sp['phone'] ?: $sp['email']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Product</label><select name="product_id" required>
                        <option value="">Select product</option>
                        <?php foreach ($activeProducts as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> / <?= e($p['category']) ?> - Central <?= (int)$p['stock'] ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Quantity</label><input type="number" min="1" name="qty" required></div>
                    <div class="field"><label>Note</label><input name="note" placeholder="Optional"></div>
                    <button class="pill-btn full">Allocate Stock</button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Stock Pointer Accounts</h2><br>
                <table class="table">
                    <tr><th>Name</th><th>Email</th><th>Phone</th><th>Current POS Items</th><th>Action</th></tr>
                    <?php foreach ($stockPointers as $sp): ?>
                        <?php $spInventory = stock_pointer_inventory((int)$sp['id']); ?>
                        <tr>
                            <td><?= e($sp['name']) ?></td>
                            <td><?= e($sp['email']) ?></td>
                            <td><?= e($sp['phone']) ?></td>
                            <td><?= array_sum(array_map(fn($row) => (int)$row['qty'], $spInventory)) ?> units</td>
                            <td><a class="see-all-btn" href="index.php?page=super_admin&edit_user=<?= (int)$sp['id'] ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$stockPointers): ?><tr><td colspan="5">Create a user with role stock_pointer first.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Transfer History</h2><br>
                <table class="table">
                    <tr><th>Date</th><th>Stock Pointer</th><th>Product</th><th>Qty</th><th>By</th><th>Note</th></tr>
                    <?php foreach ($stockPointerTransfers as $row): ?>
                        <tr><td><?= e($row['created_at']) ?></td><td><?= e($row['stock_pointer_name']) ?></td><td><?= e($row['product_name']) ?></td><td><?= (int)$row['qty'] ?></td><td><?= e($row['admin_name']) ?></td><td><?= e($row['note'] ?: '-') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$stockPointerTransfers): ?><tr><td colspan="6">No stock allocated yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($module === 'system'): ?>
            <section class="panel">
                <h2 class="section-title">System Structure</h2><br>
                <div class="spec-grid">
                    <div><span>Storefront</span><b>Products, Cart, Buy Now, Checkout</b></div>
                    <div><span>Admin</span><b>Products, Inventory, Orders, Reports</b></div>
                    <div><span>Super Admin</span><b>Users, Roles, Wallet Control</b></div>
                    <div><span>Database</span><b>vmcmarts on MySQL <?= e(DB_PORT) ?></b></div>
                </div>
            </section><br>
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
