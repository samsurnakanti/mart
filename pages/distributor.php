<?php
$user = require_distributor();
$teamRows = distributor_team((int)$user['id']);
$orders = db()->prepare('
    SELECT o.*
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE u.sponsor_distributor_id = ?
    ORDER BY o.id DESC
    LIMIT 20
');
$orders->execute([$user['id']]);
$teamOrders = $orders->fetchAll();
$refLink = 'index.php?page=signup&type=distributor&ref=' . urlencode((string)$user['distributor_uid']);
$bvRows = distributor_bv_transactions((int)$user['id'], 20);
$bvTotal = distributor_bv_total((int)$user['id']);
$commissionTotal = distributor_commission_total((int)$user['id']);
$bvSummary = distributor_monthly_bv_summary((int)$user['id']);
$selectedBvMonth = valid_bv_month($_GET['bv_month'] ?? '', $bvSummary['current_month']);
$branchRows = distributor_branch_bv_rows((int)$user['id'], $selectedBvMonth);
$tree = distributor_genealogy_tree((int)$user['id']);
?>
<section class="account-hero distributor-hero">
    <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
    <div>
        <span>Distributor Dashboard</span>
        <h1><?= e($user['name']) ?></h1>
        <p>ID <?= e($user['distributor_uid']) ?> - KYC <?= e(str_replace('_', ' ', $user['kyc_status'])) ?></p>
    </div>
</section>

<div class="grid-3">
    <div class="stats">Team Members<b><?= count($teamRows) ?></b></div>
    <div class="stats">Current TBV<b><?= $bvSummary['current']['tbv'] ?></b></div>
    <div class="stats">Commission Earned<b><?= money($commissionTotal) ?></b></div>
</div><br>

<div class="grid-2">
    <section class="panel">
        <h2 class="section-title">Referral ID</h2><br>
        <div class="ref-box"><?= e($user['distributor_uid']) ?></div>
        <p class="small">Share this ID or signup link so new members join under your team.</p><br>
        <a class="see-all-btn" href="<?= e($refLink) ?>">Open Referral Signup</a>
    </section>
    <section class="panel">
        <h2 class="section-title">Quick Actions</h2><br>
        <div class="doc-actions">
            <a class="see-all-btn" href="index.php?page=profile&tab=kyc">Update KYC</a>
            <a class="see-all-btn" href="index.php?page=profile&tab=documents">Welcome Letter</a>
            <a class="see-all-btn" href="index.php?page=profile&tab=team">View Team</a>
            <a class="see-all-btn" href="index.php?page=profile&tab=bv">BV Ledger</a>
            <a class="see-all-btn" href="index.php?page=profile&tab=genealogy">Genealogy Tree</a>
        </div>
    </section>
</div><br>

<section class="panel">
    <h2 class="section-title">Monthly BV Points</h2><br>
    <table class="table">
        <tr><th>Month</th><th>PBV</th><th>GBV</th><th>TBV</th><th>Earnings</th><th>Details</th></tr>
        <tr><td>Current Month (<?= e($bvSummary['current_month']) ?>)</td><td><?= $bvSummary['current']['pbv'] ?></td><td><?= $bvSummary['current']['gbv'] ?></td><td><?= $bvSummary['current']['tbv'] ?></td><td><?= money($bvSummary['current']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&bv_month=<?= e($bvSummary['current_month']) ?>">Show</a></td></tr>
        <tr><td>Previous Month (<?= e($bvSummary['previous_month']) ?>)</td><td><?= $bvSummary['previous']['pbv'] ?></td><td><?= $bvSummary['previous']['gbv'] ?></td><td><?= $bvSummary['previous']['tbv'] ?></td><td><?= money($bvSummary['previous']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=distributor&bv_month=<?= e($bvSummary['previous_month']) ?>">Show</a></td></tr>
    </table>
</section><br>

<section class="panel">
    <h2 class="section-title">Team BV Details - <?= e($selectedBvMonth) ?></h2>
    <p class="section-kicker">Each direct member row shows their earned BV and the group business created under them.</p><br>
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

<div class="grid-2">
    <section class="panel">
        <h2 class="section-title">Direct Downline</h2><br>
        <table class="table">
            <tr><th>Name</th><th>Current TBV</th><th>Previous TBV</th><th>Joined</th></tr>
            <?php foreach (array_slice($teamRows, 0, 8) as $member): ?>
                <?php $memberBv = distributor_monthly_bv_summary((int)$member['id']); ?>
                <tr><td><?= e($member['name']) ?><br><span class="small"><?= e($member['email']) ?></span></td><td><?= $memberBv['current']['tbv'] ?></td><td><?= $memberBv['previous']['tbv'] ?></td><td><?= e($member['created_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$teamRows): ?><tr><td colspan="4">No team members yet.</td></tr><?php endif; ?>
        </table>
    </section>
    <section class="panel">
        <h2 class="section-title">Recent BV Ledger</h2><br>
        <table class="table">
            <tr><th>Type</th><th>Level</th><th>BV</th><th>Rate</th><th>Earning</th><th>Source</th><th>Month</th></tr>
            <?php foreach ($bvRows as $row): ?>
                <tr><td><?= e(str_replace('_', ' ', $row['type'])) ?></td><td><?= $row['level_no'] ? 'L' . (int)$row['level_no'] : '-' ?></td><td><?= (int)$row['points'] ?></td><td><?= $row['commission_percent'] !== null ? e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) . '%' : '-' ?></td><td><?= money((float)$row['commission_amount']) ?></td><td><?= e($row['source_name'] ?? 'Admin') ?></td><td><?= e($row['share_month']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$bvRows): ?><tr><td colspan="7">No BV points yet.</td></tr><?php endif; ?>
        </table>
    </section>
</div><br>

<section class="panel">
    <h2 class="section-title">Genealogy Tree</h2><br>
    <ul class="genealogy-tree"><?php if ($tree) render_genealogy_node($tree); ?></ul>
</section>
