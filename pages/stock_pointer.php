<?php
$user = require_stock_pointer();
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard', 'inventory', 'pos', 'sales'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}

$inventory = stock_pointer_inventory((int)$user['id']);
$sales = stock_pointer_sales((int)$user['id'], 60);
$transfers = stock_pointer_transfer_history((int)$user['id'], 30);
$customerBuyers = pos_buyers('customer');
$distributorBuyers = pos_buyers('distributor');
$saleItemsBySale = [];
if ($sales) {
    $saleIds = array_map(fn($sale) => (int)$sale['id'], $sales);
    $items = db()->prepare('SELECT * FROM pos_sale_items WHERE sale_id IN (' . implode(',', array_fill(0, count($saleIds), '?')) . ') ORDER BY sale_id DESC, id');
    $items->execute($saleIds);
    foreach ($items->fetchAll() as $item) {
        $saleItemsBySale[(int)$item['sale_id']][] = $item;
    }
}
$totalStock = array_sum(array_map(fn($row) => (int)$row['qty'], $inventory));
$totalSales = array_sum(array_map(fn($row) => (float)$row['grand_total'], $sales));
$sectionTitles = [
    'dashboard' => 'Dashboard',
    'inventory' => 'Inventory',
    'pos' => 'POS',
    'sales' => 'Sales',
];
?>
<div class="admin-shell stock-pointer-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">VMC<span>marts</span><small>Stock Pointer</small></div>
        <a class="<?= $section === 'dashboard' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=dashboard">Dashboard</a>
        <a class="<?= $section === 'inventory' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=inventory">Imported Stock</a>
        <a class="<?= $section === 'pos' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=pos">Sell POS</a>
        <a class="<?= $section === 'sales' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=sales">Sales History</a>
        <a href="index.php?action=logout">Logout</a>
    </aside>

    <section class="admin-content">
        <div class="admin-topbar">
            <div>
                <span><?= e($user['name']) ?></span>
                <h1><?= e($sectionTitles[$section]) ?></h1>
            </div>
            <a class="primary-cta" href="index.php?page=stock_pointer&section=pos">New POS Sale</a>
        </div>

        <?php if ($section === 'dashboard'): ?>
            <div class="grid-3">
                <div class="stats">Imported Units<b><?= $totalStock ?></b></div>
                <div class="stats">POS Sales<b><?= count($sales) ?></b></div>
                <div class="stats">Revenue<b><?= money($totalSales) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Recent Imported Stock</h2><br>
                <table class="table">
                    <tr><th>Date</th><th>Product</th><th>Qty</th><th>Note</th></tr>
                    <?php foreach ($transfers as $row): ?>
                        <tr><td><?= e($row['created_at']) ?></td><td><?= e($row['product_name']) ?></td><td><?= (int)$row['qty'] ?></td><td><?= e($row['note'] ?: '-') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$transfers): ?><tr><td colspan="4">No stock imported yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'inventory'): ?>
            <section class="panel">
                <h2 class="section-title">Imported Stock Inventory</h2><br>
                <table class="table">
                    <tr><th>Product</th><th>Category</th><th>Price</th><th>Tax</th><th>Available</th><th>Status</th></tr>
                    <?php foreach ($inventory as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?></td>
                            <td><?= e($row['category']) ?></td>
                            <td><?= money($row['selling_price']) ?></td>
                            <td><?= e($row['tax_percent']) ?>%</td>
                            <td><?= (int)$row['qty'] ?></td>
                            <td><?= (int)$row['qty'] <= 5 ? '<span class="stock-low">Low Stock</span>' : '<span class="stock-ok">Available</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$inventory): ?><tr><td colspan="6">Super admin has not allocated stock to you yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'pos'): ?>
            <section class="panel">
                <h2 class="section-title">Sell POS</h2>
                <p class="section-kicker">Sell only from stock imported into your stock pointer inventory.</p><br>
                <form method="post">
                    <input type="hidden" name="action" value="submit_stock_pointer_pos_sale">
                    <div class="form-grid">
                        <div class="field"><label>Buyer Type</label><select name="customer_type">
                            <option value="customer">Customer</option>
                            <option value="distributor">Distributor</option>
                        </select></div>
                        <div class="field"><label>Registered Customer</label><select name="buyer_user_id">
                            <option value="">Walk-in / manual entry</option>
                            <optgroup label="Customers">
                                <?php foreach ($customerBuyers as $buyer): ?>
                                    <option value="<?= (int)$buyer['id'] ?>"><?= e($buyer['name']) ?> - <?= e($buyer['phone'] ?: $buyer['email']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Distributors">
                                <?php foreach ($distributorBuyers as $buyer): ?>
                                    <option value="<?= (int)$buyer['id'] ?>"><?= e($buyer['name']) ?> - <?= e($buyer['distributor_uid'] ?: $buyer['phone']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select></div>
                        <div class="field"><label>Buyer Name</label><input name="buyer_name" placeholder="Required for walk-in"></div>
                        <div class="field"><label>Buyer Phone</label><input name="buyer_phone" inputmode="tel"></div>
                    </div><br>
                    <table class="table">
                        <tr><th>Product</th><th>Category</th><th>Price</th><th>Available</th><th>Qty</th></tr>
                        <?php foreach ($inventory as $row): ?>
                            <?php if ((int)$row['qty'] <= 0) continue; ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td><?= e($row['category']) ?></td>
                                <td><?= money($row['selling_price']) ?></td>
                                <td><?= (int)$row['qty'] ?></td>
                                <td><input style="width:90px" type="number" min="0" max="<?= (int)$row['qty'] ?>" name="qty[<?= (int)$row['product_id'] ?>]" value="0"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$inventory || $totalStock <= 0): ?><tr><td colspan="5">No available stock for POS sale.</td></tr><?php endif; ?>
                    </table><br>
                    <button class="pill-btn">Complete POS Sale</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($section === 'sales'): ?>
            <section class="panel">
                <h2 class="section-title">POS Sales History</h2><br>
                <table class="table">
                    <tr><th>Sale</th><th>Buyer</th><th>Products</th><th>Total</th><th>Date</th></tr>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td>#<?= (int)$sale['id'] ?><br><span class="small"><?= e($sale['customer_type']) ?></span></td>
                            <td><?= e($sale['buyer_name']) ?><br><span class="small"><?= e($sale['buyer_phone'] ?: '-') ?></span></td>
                            <td>
                                <details class="order-items-view">
                                    <summary class="see-all-btn">View Products</summary>
                                    <div class="order-items-popover">
                                        <?php foreach ($saleItemsBySale[(int)$sale['id']] ?? [] as $item): ?>
                                            <div><?= e($item['product_name']) ?> x <?= (int)$item['qty'] ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </td>
                            <td><?= money($sale['grand_total']) ?></td>
                            <td><?= e($sale['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="5">No POS sales yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>
    </section>
</div>
