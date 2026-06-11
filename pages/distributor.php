<?php
$user = require_distributor();
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard', 'welcome-letter', 'id-card', 'view-profile', 'update-profile', 'kyc', 'downline', 'genealogy', 'register-member', 'orders', 'order-history', 'bv', 'earnings'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}

$teamRows = distributor_team((int)$user['id']);
$downlineIds = [];
$pendingSponsorIds = [(int)$user['id']];
while ($pendingSponsorIds) {
    $placeholders = implode(',', array_fill(0, count($pendingSponsorIds), '?'));
    $stmt = db()->prepare("SELECT id FROM users WHERE sponsor_distributor_id IN ($placeholders)");
    $stmt->execute($pendingSponsorIds);
    $nextIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $nextIds = array_values(array_diff($nextIds, $downlineIds));
    $downlineIds = array_values(array_unique(array_merge($downlineIds, $nextIds)));
    $pendingSponsorIds = $nextIds;
}

$orders = db()->prepare('
    SELECT *
    FROM orders
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 50
');
$orders->execute([$user['id']]);
$ownOrders = $orders->fetchAll();
$orderProducts = active_products_for_distributor_order();
$refLink = 'index.php?page=signup&type=distributor&ref=' . urlencode((string)$user['distributor_uid']);
$bvRows = distributor_bv_transactions((int)$user['id'], 50);
$earningRows = array_values(array_filter($bvRows, fn($row) => (float)$row['commission_amount'] > 0));
$levelStmt = db()->prepare('
    SELECT level_no, commission_percent, COUNT(*) AS entries, COALESCE(SUM(points),0) AS points, COALESCE(SUM(commission_amount),0) AS earnings
    FROM bv_transactions
    WHERE distributor_id = ? AND commission_amount > 0 AND level_no IS NOT NULL
    GROUP BY level_no, commission_percent
    ORDER BY level_no
');
$levelStmt->execute([(int)$user['id']]);
$levelCommissionRows = $levelStmt->fetchAll();
$bvTotal = distributor_bv_total((int)$user['id']);
$commissionTotal = distributor_commission_total((int)$user['id']);
$bvSummary = distributor_monthly_bv_summary((int)$user['id']);
$selectedBvMonth = valid_bv_month($_GET['bv_month'] ?? '', $bvSummary['current_month']);
$branchRows = distributor_branch_bv_rows((int)$user['id'], $selectedBvMonth);
$tree = distributor_genealogy_tree((int)$user['id']);
$sectionTitles = [
    'dashboard' => 'Dashboard',
    'welcome-letter' => 'Welcome Letter',
    'id-card' => 'ID Card',
    'view-profile' => 'View Profile',
    'update-profile' => 'Update Profile',
    'kyc' => 'KYC',
    'downline' => 'Downline',
    'genealogy' => 'Genealogy',
    'register-member' => 'New Member Register',
    'orders' => 'Place Order',
    'order-history' => 'Order History',
    'bv' => 'BVs',
    'earnings' => 'Earnings',
];
$navGroups = [
    'Dashboard' => [
        'dashboard' => 'Dashboard',
        'welcome-letter' => 'Welcome Letter',
        'id-card' => 'ID Card',
    ],
    'Profile' => [
        'view-profile' => 'View Profile',
        'update-profile' => 'Update Profile',
        'kyc' => 'KYC',
    ],
    'Team' => [
        'downline' => 'Downline',
        'genealogy' => 'Genealogy',
        'register-member' => 'New Member Register',
    ],
    'Orders' => [
        'orders' => 'Place Order',
        'order-history' => 'Order History',
    ],
    'BVs and Earnings' => [
        'bv' => 'BV Ledger',
        'earnings' => 'Earnings',
    ],
];
$openGroups = [
    'Dashboard' => in_array($section, ['dashboard', 'welcome-letter', 'id-card'], true),
    'Profile' => in_array($section, ['view-profile', 'update-profile', 'kyc'], true),
    'Team' => in_array($section, ['downline', 'genealogy', 'register-member'], true),
    'Orders' => in_array($section, ['orders', 'order-history'], true),
    'BVs and Earnings' => in_array($section, ['bv', 'earnings'], true),
];
?>
<div class="distributor-shell">
    <aside class="distributor-sidebar" aria-label="Distributor navigation">
        <button class="distributor-drawer-close" type="button" aria-label="Close distributor menu" data-distributor-menu-close>Close</button>
        <div class="distributor-brand">
            <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
            <div>
                <b><?= e($user['name']) ?></b>
                <span><?= e($user['distributor_uid']) ?></span>
            </div>
        </div>
        <nav class="distributor-menu">
            <?php foreach ($navGroups as $group => $links): ?>
                <details <?= $openGroups[$group] ? 'open' : '' ?>>
                    <summary><?= e($group) ?></summary>
                    <div>
                        <?php foreach ($links as $key => $label): ?>
                            <a class="<?= $section === $key ? 'active' : '' ?>" href="index.php?page=distributor&section=<?= e($key) ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            <a class="distributor-logout" href="index.php?action=logout">Logout</a>
        </nav>
    </aside>
    <button class="distributor-drawer-backdrop" type="button" aria-label="Close distributor menu" data-distributor-menu-close></button>

    <section class="distributor-main">
        <section class="account-hero distributor-hero distributor-topbar">
            <div>
                <span>Distributor <?= e($sectionTitles[$section]) ?></span>
                <h1><?= e($sectionTitles[$section]) ?></h1>
                <p>ID <?= e($user['distributor_uid']) ?> - KYC <?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></p>
            </div>
            <a class="see-all-btn" href="<?= e($refLink) ?>">Register Member</a>
        </section>

        <?php if ($section === 'dashboard'): ?>
            <div class="grid-3">
                <div class="stats">Total Team Members<b><?= count($downlineIds) ?></b></div>
                <div class="stats">Direct Downline<b><?= count($teamRows) ?></b></div>
                <div class="stats">Your Orders<b><?= count($ownOrders) ?></b></div>
            </div><br>
            <div class="grid-3">
                <div class="stats">Current TBV<b><?= $bvSummary['current']['tbv'] ?></b></div>
                <div class="stats">Previous TBV<b><?= $bvSummary['previous']['tbv'] ?></b></div>
                <div class="stats">Commission Earned<b><?= money($commissionTotal) ?></b></div>
            </div><br>
            <div class="grid-2">
                <section class="panel">
                    <h2 class="section-title">Referral ID</h2><br>
                    <div class="ref-box"><?= e($user['distributor_uid']) ?></div>
                    <p class="small">Share this ID or signup link so new members join under your team.</p><br>
                    <a class="see-all-btn" href="<?= e($refLink) ?>">Open Referral Signup</a>
                </section>
                <section class="panel distributor-overview-list">
                    <h2 class="section-title">Quick Overview</h2><br>
                    <p><b>Current Self BV</b><span><?= $bvSummary['current']['pbv'] ?></span></p>
                    <p><b>Current Group BV</b><span><?= $bvSummary['current']['gbv'] ?></span></p>
                    <p><b>Current Earnings</b><span><?= money($bvSummary['current']['earnings']) ?></span></p>
                    <p><b>Previous Earnings</b><span><?= money($bvSummary['previous']['earnings']) ?></span></p>
                </section>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Monthly BV Points</h2><br>
                <table class="table">
                    <tr><th>Month</th><th>Self BV</th><th>Group BV</th><th>Total BV</th><th>Earnings</th><th>Details</th></tr>
                    <tr><td>Current Month (<?= e($bvSummary['current_month']) ?>)</td><td><?= $bvSummary['current']['pbv'] ?></td><td><?= $bvSummary['current']['gbv'] ?></td><td><?= $bvSummary['current']['tbv'] ?></td><td><?= money($bvSummary['current']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&section=bv&bv_month=<?= e($bvSummary['current_month']) ?>">Show</a></td></tr>
                    <tr><td>Previous Month (<?= e($bvSummary['previous_month']) ?>)</td><td><?= $bvSummary['previous']['pbv'] ?></td><td><?= $bvSummary['previous']['gbv'] ?></td><td><?= $bvSummary['previous']['tbv'] ?></td><td><?= money($bvSummary['previous']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&section=bv&bv_month=<?= e($bvSummary['previous_month']) ?>">Show</a></td></tr>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'welcome-letter'): ?>
            <section class="panel letter-doc">
                <span>Welcome Letter</span>
                <h2>Welcome to VMCmarts Distributor Network</h2>
                <p>Dear <?= e($user['name']) ?>, your distributor account has been created successfully.</p>
                <p><b>Distributor ID:</b> <?= e($user['distributor_uid']) ?></p>
                <p><b>KYC Status:</b> <?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></p>
                <p>Use your distributor dashboard to register new members, track downline activity, and review monthly BV and commission earnings.</p>
            </section>
        <?php endif; ?>

        <?php if ($section === 'id-card'): ?>
            <section class="panel id-card-doc distributor-id-card">
                <span>VMCmarts</span>
                <h2>Distributor ID Card</h2>
                <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
                <p><b><?= e($user['name']) ?></b></p>
                <p>ID: <?= e($user['distributor_uid']) ?></p>
                <p>Phone: <?= e($user['phone']) ?></p>
                <p>KYC: <?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($section === 'view-profile'): ?>
            <div class="grid-2">
                <section class="panel">
                    <h2 class="section-title">Profile Details</h2><br>
                    <p><b>Name:</b> <?= e($user['name']) ?></p>
                    <p><b>Email:</b> <?= e($user['email']) ?></p>
                    <p><b>Phone:</b> <?= e($user['phone']) ?></p>
                    <p><b>Distributor ID:</b> <?= e($user['distributor_uid']) ?></p>
                    <p><b>Joined:</b> <?= e($user['created_at']) ?></p>
                </section>
                <section class="panel">
                    <h2 class="section-title">KYC Details</h2><br>
                    <p><b>Status:</b> <?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></p>
                    <p><b>PAN:</b> <?= e($user['pan_number'] ?: '-') ?></p>
                    <p><b>Bank IFSC:</b> <?= e($user['bank_ifsc'] ?: '-') ?></p>
                    <p><b>GST:</b> <?= e($user['gst_number'] ?: '-') ?></p>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'update-profile'): ?>
            <section class="panel">
                <h2 class="section-title">Update Profile</h2><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="back" value="distributor&section=update-profile">
                    <div class="field"><label>Name</label><input name="name" value="<?= e($user['name']) ?>" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($user['email']) ?>" required></div>
                    <div class="field full"><label>Phone</label><input name="phone" value="<?= e($user['phone']) ?>"></div>
                    <button class="pill-btn full">Save Profile</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($section === 'kyc'): ?>
            <section class="panel">
                <h2 class="section-title">Distributor KYC</h2>
                <p class="section-kicker">Upload PAN, bank proof, ID proof and optional GST document.</p><br>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="action" value="update_distributor_kyc">
                    <input type="hidden" name="back" value="distributor&section=kyc">
                    <div class="field"><label>PAN Number</label><input name="pan_number" value="<?= e($user['pan_number']) ?>" required></div>
                    <div class="field"><label>PAN Upload</label><input type="file" name="pan_file" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                    <div class="field"><label>Bank Account Number</label><input name="bank_account_number" value="<?= e($user['bank_account_number']) ?>" required></div>
                    <div class="field"><label>Bank IFSC</label><input name="bank_ifsc" value="<?= e($user['bank_ifsc']) ?>" required></div>
                    <div class="field full"><label>Bank Proof Upload</label><input type="file" name="bank_file" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                    <div class="field"><label>ID Proof Type</label><select name="id_proof_type" required>
                        <?php foreach (['Aadhaar Card', 'Voter ID', 'Driving Licence', 'Passport'] as $type): ?>
                            <option value="<?= e($type) ?>" <?= $user['id_proof_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>ID Proof Upload</label><input type="file" name="id_proof_file" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                    <div class="field"><label>GST Number</label><input name="gst_number" value="<?= e($user['gst_number']) ?>" placeholder="Optional"></div>
                    <div class="field"><label>GST Upload</label><input type="file" name="gst_file" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                    <button class="pill-btn full">Submit KYC</button>
                </form><br>
                <div class="doc-actions">
                    <?php foreach (['PAN' => 'pan_file', 'Bank Proof' => 'bank_file', 'ID Proof' => 'id_proof_file', 'GST' => 'gst_file'] as $label => $key): ?>
                        <?php if (!empty($user[$key])): ?><a class="see-all-btn" href="<?= e($user[$key]) ?>" target="_blank"><?= e($label) ?></a><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($section === 'downline'): ?>
            <section class="panel">
                <h2 class="section-title">Direct Downline</h2><br>
                <table class="table">
                    <tr><th>Name</th><th>Email</th><th>Phone</th><th>Current TBV</th><th>Previous TBV</th><th>Joined</th></tr>
                    <?php foreach ($teamRows as $member): ?>
                        <?php $memberBv = distributor_monthly_bv_summary((int)$member['id']); ?>
                        <tr><td><?= e($member['name']) ?></td><td><?= e($member['email']) ?></td><td><?= e($member['phone']) ?></td><td><?= $memberBv['current']['tbv'] ?></td><td><?= $memberBv['previous']['tbv'] ?></td><td><?= e($member['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$teamRows): ?><tr><td colspan="6">No team members yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'genealogy'): ?>
            <section class="panel">
                <h2 class="section-title">Genealogy Tree</h2>
                <p class="section-kicker">Direct downline and group structure under your distributor ID.</p><br>
                <ul class="genealogy-tree"><?php if ($tree) render_genealogy_node($tree); ?></ul>
            </section>
        <?php endif; ?>

        <?php if ($section === 'register-member'): ?>
            <section class="panel">
                <h2 class="section-title">New Member Register</h2><br>
                <div class="ref-box"><?= e($user['distributor_uid']) ?></div>
                <p class="small">Send this signup link to register a new member directly under your distributor ID.</p><br>
                <div class="doc-actions">
                    <a class="see-all-btn" href="<?= e($refLink) ?>">Open Registration Form</a>
                    <a class="see-all-btn" href="index.php?page=signup&type=distributor&ref=<?= urlencode((string)$user['distributor_uid']) ?>">Distributor Signup</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($section === 'order-history'): ?>
            <section class="panel">
                <h2 class="section-title">Order Request History</h2><br>
                <table class="table">
                    <tr><th>Order</th><th>Total</th><th>Status</th><th>Products</th><th>Invoice</th><th>Date</th></tr>
                    <?php foreach ($ownOrders as $o): ?>
                        <tr>
                            <td>#<?= (int)$o['id'] ?></td>
                            <td><?= money($o['grand_total']) ?></td>
                            <td><?= e($o['status']) ?></td>
                            <td><span class="small">Admin can view products in Orders.</span></td>
                            <td>
                                <?php if ($o['status'] === 'Completed'): ?>
                                    <a class="see-all-btn" href="index.php?page=invoice&id=<?= (int)$o['id'] ?>">View</a>
                                    <a class="see-all-btn" href="index.php?action=download_invoice&id=<?= (int)$o['id'] ?>">Download</a>
                                <?php else: ?>
                                    <span class="small">After admin approval</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($o['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ownOrders): ?><tr><td colspan="6">No order requests yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'orders'): ?>
            <section class="panel">
                <h2 class="section-title">Distributor Product Order</h2>
                <p class="section-kicker">Enter quantities beside products and submit one request to admin.</p><br>
                <form method="post">
                    <input type="hidden" name="action" value="submit_distributor_order">
                    <table class="table">
                        <tr><th>Product</th><th>Category</th><th>Price</th><th>Tax</th><th>BV</th><th>Stock</th><th>Quantity</th></tr>
                        <?php foreach ($orderProducts as $p): ?>
                            <tr>
                                <td><?= e($p['name']) ?></td>
                                <td><?= e($p['category']) ?></td>
                                <td><?= money($p['selling_price']) ?></td>
                                <td><?= e($p['tax_percent']) ?>%</td>
                                <td><?= (int)$p['bv_points'] ?></td>
                                <td><?= (int)$p['stock'] ?></td>
                                <td><input style="width:90px" type="number" min="0" max="<?= (int)$p['stock'] ?>" name="qty[<?= (int)$p['id'] ?>]" value="0"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orderProducts): ?><tr><td colspan="7">No products available.</td></tr><?php endif; ?>
                    </table><br>
                    <div class="field full"><label>Delivery / Note</label><textarea name="shipping_address" placeholder="Optional delivery address or note for admin"></textarea></div><br>
                    <button class="pill-btn">Submit Order Request</button>
                </form>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Recent Requests</h2><br>
                <table class="table">
                    <tr><th>Order</th><th>Total</th><th>Status</th><th>Date</th></tr>
                    <?php foreach ($ownOrders as $o): ?>
                        <tr>
                            <td>#<?= (int)$o['id'] ?></td>
                            <td><?= money($o['grand_total']) ?></td>
                            <td><?= e($o['status']) ?></td>
                            <td><?= e($o['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ownOrders): ?><tr><td colspan="4">No order requests yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'bv'): ?>
            <div class="grid-3">
                <div class="stats">Current Month BV<b><?= $bvSummary['current']['tbv'] ?></b></div>
                <div class="stats">Previous Month BV<b><?= $bvSummary['previous']['tbv'] ?></b></div>
                <div class="stats">Total BV<b><?= $bvTotal ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Monthly BV Share</h2>
                <p class="section-kicker">Your self BV, team/group BV, total BV and earnings for current and previous month.</p><br>
                <table class="table">
                    <tr><th>Month</th><th>Self BV</th><th>Group BV</th><th>Total BV</th><th>Earnings</th><th>Details</th></tr>
                    <tr><td>Current Month (<?= e($bvSummary['current_month']) ?>)</td><td><?= $bvSummary['current']['pbv'] ?></td><td><?= $bvSummary['current']['gbv'] ?></td><td><?= $bvSummary['current']['tbv'] ?></td><td><?= money($bvSummary['current']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&section=bv&bv_month=<?= e($bvSummary['current_month']) ?>">Show</a></td></tr>
                    <tr><td>Previous Month (<?= e($bvSummary['previous_month']) ?>)</td><td><?= $bvSummary['previous']['pbv'] ?></td><td><?= $bvSummary['previous']['gbv'] ?></td><td><?= $bvSummary['previous']['tbv'] ?></td><td><?= money($bvSummary['previous']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&section=bv&bv_month=<?= e($bvSummary['previous_month']) ?>">Show</a></td></tr>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Team BV Details - <?= e($selectedBvMonth) ?></h2>
                <p class="section-kicker">Direct member earned BV, group BV under that member, and total business for the selected month.</p><br>
                <table class="table">
                    <tr><th>Direct Member</th><th>Earned BV</th><th>Group BV</th><th>Total BV</th><th>Role</th></tr>
                    <?php foreach ($branchRows as $row): ?>
                        <tr>
                            <td><?= e($row['member']['name']) ?><br><span class="small"><?= e($row['member']['email']) ?></span></td>
                            <td><?= (int)$row['pbv'] ?></td>
                            <td><?= (int)$row['gbv'] ?></td>
                            <td><?= (int)$row['tbv'] ?></td>
                            <td><?= e($row['member']['role']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$branchRows): ?><tr><td colspan="5">No team BV found for this month.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">BV Ledger</h2><br>
                <table class="table">
                    <tr><th>Type</th><th>Level</th><th>BV</th><th>Rate</th><th>Earning</th><th>Source</th><th>Month</th></tr>
                    <?php foreach ($bvRows as $row): ?>
                        <tr><td><?= e(str_replace('_', ' ', $row['type'])) ?></td><td><?= $row['level_no'] ? 'L' . (int)$row['level_no'] : '-' ?></td><td><?= (int)$row['points'] ?></td><td><?= $row['commission_percent'] !== null ? e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) . '%' : '-' ?></td><td><?= money((float)$row['commission_amount']) ?></td><td><?= e($row['source_name'] ?? 'Admin') ?></td><td><?= e($row['share_month']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$bvRows): ?><tr><td colspan="7">No BV points yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($section === 'earnings'): ?>
            <div class="grid-3">
                <div class="stats">Total Earnings<b><?= money($commissionTotal) ?></b></div>
                <div class="stats">Current Month<b><?= money($bvSummary['current']['earnings']) ?></b></div>
                <div class="stats">Previous Month<b><?= money($bvSummary['previous']['earnings']) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Level-wise Commission</h2><br>
                <table class="table">
                    <tr><th>Level</th><th>Rate</th><th>Entries</th><th>BV Points</th><th>Earnings</th></tr>
                    <?php foreach ($levelCommissionRows as $row): ?>
                        <tr>
                            <td>L<?= (int)$row['level_no'] ?></td>
                            <td><?= e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) ?>%</td>
                            <td><?= (int)$row['entries'] ?></td>
                            <td><?= (int)$row['points'] ?></td>
                            <td><?= money((float)$row['earnings']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$levelCommissionRows): ?><tr><td colspan="5">No level-wise commission yet.</td></tr><?php endif; ?>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Commission Earnings</h2><br>
                <table class="table">
                    <tr><th>Level</th><th>Rate</th><th>BV Points</th><th>Earning</th><th>Source</th><th>Month</th><th>Note</th></tr>
                    <?php foreach ($earningRows as $row): ?>
                        <tr><td><?= $row['level_no'] ? 'L' . (int)$row['level_no'] : '-' ?></td><td><?= $row['commission_percent'] !== null ? e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) . '%' : '-' ?></td><td><?= (int)$row['points'] ?></td><td><?= money((float)$row['commission_amount']) ?></td><td><?= e($row['source_name'] ?? 'Admin') ?></td><td><?= e($row['share_month']) ?></td><td><?= e($row['note']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$earningRows): ?><tr><td colspan="7">No earnings yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>
    </section>
</div>
