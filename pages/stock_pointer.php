<?php
$user = require_stock_pointer();
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard', 'inventory', 'pos', 'sales', 'reports', 'invoices'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}

$inventory = stock_pointer_inventory((int)$user['id']);
$sales = stock_pointer_sales((int)$user['id'], 60);
$salesReport = stock_pointer_sales_report((int)$user['id'], 100);
$reportSummary = stock_pointer_report_summary((int)$user['id']);
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
    'reports' => 'Reports',
    'invoices' => 'Invoices',
];
?>
<div class="admin-shell stock-pointer-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">VMC<span>marts</span><small>Stock Pointer</small></div>
        <a class="<?= $section === 'dashboard' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=dashboard">Dashboard</a>
        <a class="<?= $section === 'inventory' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=inventory">Imported Stock</a>
        <a class="<?= $section === 'pos' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=pos">Sell POS</a>
        <a class="<?= $section === 'sales' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=sales">Sales History</a>
        <a class="<?= $section === 'reports' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=reports">Reports</a>
        <a class="<?= $section === 'invoices' ? 'active' : '' ?>" href="index.php?page=stock_pointer&section=invoices">Invoices</a>
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
                    <tr><th>Date</th><th>Product</th><th>Qty</th><th>Bill</th><th>Note</th></tr>
                    <?php foreach ($transfers as $row): ?>
                        <tr><td><?= e($row['created_at']) ?></td><td><?= e($row['product_name']) ?></td><td><?= (int)$row['qty'] ?></td><td><a class="see-all-btn" href="index.php?page=stock_transfer_bill&id=<?= (int)$row['id'] ?>" target="_blank">View</a></td><td><?= e($row['note'] ?: '-') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$transfers): ?><tr><td colspan="5">No stock imported yet.</td></tr><?php endif; ?>
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
                    <tr><th>Sale</th><th>Buyer</th><th>Products</th><th>Total</th><th>Invoice</th><th>Date</th></tr>
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
                            <td><a class="see-all-btn" href="index.php?page=pos_invoice&id=<?= (int)$sale['id'] ?>" target="_blank">View</a> <a class="see-all-btn" href="index.php?action=download_pos_invoice&id=<?= (int)$sale['id'] ?>">Download</a></td>
                            <td><?= e($sale['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="6">No POS sales yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'reports'): ?>
            <div class="grid-3">
                <div class="stats">Imported Units<b><?= $reportSummary['imported_units'] ?></b></div>
                <div class="stats">Available Units<b><?= $reportSummary['available_units'] ?></b></div>
                <div class="stats">POS Revenue<b><?= money($reportSummary['revenue']) ?></b></div>
            </div><br>
            <div class="grid-3">
                <div class="stats">Stock Transfers<b><?= $reportSummary['transfer_count'] ?></b></div>
                <div class="stats">POS Sales<b><?= $reportSummary['sales_count'] ?></b></div>
                <div class="stats">GST in POS<b><?= money($reportSummary['tax']) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Sales Report</h2><br>
                <table class="table">
                    <tr><th>Invoice</th><th>Buyer</th><th>Type</th><th>Tax</th><th>Total</th><th>Date</th><th>Action</th></tr>
                    <?php foreach ($salesReport as $sale): ?>
                        <tr>
                            <td>#POS-<?= (int)$sale['id'] ?></td>
                            <td><?= e($sale['buyer_name']) ?><br><span class="small"><?= e($sale['buyer_phone'] ?: '-') ?></span></td>
                            <td><?= e($sale['customer_type']) ?></td>
                            <td><?= money($sale['tax_total']) ?></td>
                            <td><?= money($sale['grand_total']) ?></td>
                            <td><?= e($sale['created_at']) ?></td>
                            <td><a class="see-all-btn" href="index.php?page=pos_invoice&id=<?= (int)$sale['id'] ?>" target="_blank">View</a> <a class="see-all-btn" href="index.php?action=download_pos_invoice&id=<?= (int)$sale['id'] ?>">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$salesReport): ?><tr><td colspan="7">No sales report yet.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Imported Stock Report</h2><br>
                <table class="table">
                    <tr><th>Bill</th><th>Product</th><th>Qty</th><th>By</th><th>Date</th><th>Action</th></tr>
                    <?php foreach ($transfers as $row): ?>
                        <tr>
                            <td>#STK-<?= (int)$row['id'] ?></td>
                            <td><?= e($row['product_name']) ?></td>
                            <td><?= (int)$row['qty'] ?></td>
                            <td><?= e($row['admin_name']) ?></td>
                            <td><?= e($row['created_at']) ?></td>
                            <td><a class="see-all-btn" href="index.php?page=stock_transfer_bill&id=<?= (int)$row['id'] ?>" target="_blank">View</a> <a class="see-all-btn" href="index.php?action=download_stock_transfer_bill&id=<?= (int)$row['id'] ?>">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transfers): ?><tr><td colspan="6">No imported stock report yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'invoices'): ?>
            <section class="panel">
                <h2 class="section-title">POS Invoices</h2><br>
                <table class="table">
                    <tr><th>Invoice</th><th>Buyer</th><th>Total</th><th>Date</th><th>Action</th></tr>
                    <?php foreach ($salesReport as $sale): ?>
                        <tr>
                            <td>#POS-<?= (int)$sale['id'] ?></td>
                            <td><?= e($sale['buyer_name']) ?><br><span class="small"><?= e($sale['buyer_phone'] ?: '-') ?></span></td>
                            <td><?= money($sale['grand_total']) ?></td>
                            <td><?= e($sale['created_at']) ?></td>
                            <td><a class="see-all-btn" href="index.php?page=pos_invoice&id=<?= (int)$sale['id'] ?>" target="_blank">View</a> <a class="see-all-btn" href="index.php?action=download_pos_invoice&id=<?= (int)$sale['id'] ?>">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$salesReport): ?><tr><td colspan="5">No POS invoices yet.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Stock Transfer Bills</h2><br>
                <table class="table">
                    <tr><th>Bill</th><th>Product</th><th>Qty</th><th>Date</th><th>Action</th></tr>
                    <?php foreach ($transfers as $row): ?>
                        <tr>
                            <td>#STK-<?= (int)$row['id'] ?></td>
                            <td><?= e($row['product_name']) ?></td>
                            <td><?= (int)$row['qty'] ?></td>
                            <td><?= e($row['created_at']) ?></td>
                            <td><a class="see-all-btn" href="index.php?page=stock_transfer_bill&id=<?= (int)$row['id'] ?>" target="_blank">View</a> <a class="see-all-btn" href="index.php?action=download_stock_transfer_bill&id=<?= (int)$row['id'] ?>">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transfers): ?><tr><td colspan="5">No stock transfer bills yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>
    </section>
</div>
