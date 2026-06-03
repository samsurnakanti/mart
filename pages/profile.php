<?php
$user = require_login();
$tab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'edit', 'wallet', 'discount_card', 'orders', 'terms', 'privacy'];
if ($user['role'] === 'distributor') {
    $allowedTabs = array_merge($allowedTabs, ['kyc', 'documents', 'team', 'bv', 'genealogy']);
}
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}
$orders = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
$orders->execute([$user['id']]);
$orderRows = $orders->fetchAll();
$tx = db()->prepare('SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$tx->execute([$user['id']]);
$txRows = $tx->fetchAll();
$cardProducts = db()->query("SELECT * FROM products WHERE product_type = 'discount_points' AND is_active = 1 ORDER BY selling_price ASC LIMIT 5")->fetchAll();
$activeCards = active_cards((int)$user['id']);
$cardTotals = card_totals((int)$user['id']);
$teamRows = $user['role'] === 'distributor' ? distributor_team((int)$user['id']) : [];
$refLink = $user['role'] === 'distributor' ? 'index.php?page=signup&type=distributor&ref=' . urlencode((string)$user['distributor_uid']) : '';
$bvRows = $user['role'] === 'distributor' ? distributor_bv_transactions((int)$user['id']) : [];
$bvTotal = $user['role'] === 'distributor' ? distributor_bv_total((int)$user['id']) : 0;
$commissionTotal = $user['role'] === 'distributor' ? distributor_commission_total((int)$user['id']) : 0;
$bvSummary = $user['role'] === 'distributor' ? distributor_monthly_bv_summary((int)$user['id']) : [];
$selectedBvMonth = $user['role'] === 'distributor' ? valid_bv_month($_GET['bv_month'] ?? '', $bvSummary['current_month']) : '';
$branchRows = $user['role'] === 'distributor' ? distributor_branch_bv_rows((int)$user['id'], $selectedBvMonth) : [];
$tree = $user['role'] === 'distributor' ? distributor_genealogy_tree((int)$user['id']) : [];
?>
<section class="account-hero">
    <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
    <div>
        <span>VMCmarts Account</span>
        <h1><?= e($user['name']) ?></h1>
        <p><?= e($user['email']) ?> · <?= (int)$user['wallet_points'] ?> ready to redeem points</p>
    </div>
</section>

<div class="account-layout">
    <aside class="account-menu">
        <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="index.php?page=profile&tab=overview">Overview</a>
        <a class="<?= $tab === 'edit' ? 'active' : '' ?>" href="index.php?page=profile&tab=edit">Edit Profile</a>
        <a class="<?= $tab === 'wallet' ? 'active' : '' ?>" href="index.php?page=profile&tab=wallet">My Wallet</a>
        <a class="<?= $tab === 'discount_card' ? 'active' : '' ?>" href="index.php?page=profile&tab=discount_card">Discount Card</a>
        <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="index.php?page=profile&tab=orders">Orders</a>
        <?php if ($user['role'] === 'distributor'): ?>
            <a class="<?= $tab === 'kyc' ? 'active' : '' ?>" href="index.php?page=profile&tab=kyc">Distributor KYC</a>
            <a class="<?= $tab === 'documents' ? 'active' : '' ?>" href="index.php?page=profile&tab=documents">Letters & ID</a>
            <a class="<?= $tab === 'team' ? 'active' : '' ?>" href="index.php?page=profile&tab=team">My Team</a>
            <a class="<?= $tab === 'bv' ? 'active' : '' ?>" href="index.php?page=profile&tab=bv">BV Points</a>
            <a class="<?= $tab === 'genealogy' ? 'active' : '' ?>" href="index.php?page=profile&tab=genealogy">Genealogy</a>
        <?php endif; ?>
        <a class="<?= $tab === 'terms' ? 'active' : '' ?>" href="index.php?page=profile&tab=terms">Terms</a>
        <a class="<?= $tab === 'privacy' ? 'active' : '' ?>" href="index.php?page=profile&tab=privacy">Privacy</a>
        <a class="danger-link" href="index.php?action=logout">Logout</a>
    </aside>

    <section class="account-content">
        <?php if ($tab === 'overview'): ?>
            <div class="grid-3">
                <div class="stats">Ready to Redeem<b><?= (int)$user['wallet_points'] ?></b></div>
                <div class="stats">Reward Points Earned<b><?= array_sum(array_map(fn($o) => (int)$o['points_earned'], $orderRows)) ?></b></div>
                <div class="stats">Card Capacity Left<b><?= $cardTotals['remaining_points'] ?></b></div>
            </div><br>
            <div class="grid-2">
                <div class="stats">Orders<b><?= count($orderRows) ?></b></div>
                <div class="stats">Role<b><?= e($user['role']) ?></b></div>
            </div><br>
            <?php if ($user['role'] === 'distributor'): ?>
                <div class="grid-3">
                    <div class="stats">Distributor ID<b><?= e($user['distributor_uid']) ?></b></div>
                    <div class="stats">KYC Status<b><?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></b></div>
                    <div class="stats">BV Points<b><?= $bvTotal ?></b></div>
                </div><br>
            <?php endif; ?>
            <div class="grid-2">
                <section class="wallet-card">
                    <p>Reward Wallet</p>
                    <strong><?= (int)$user['wallet_points'] ?> pts</strong>
                    <p>Earned reward points ready to redeem</p>
                </section>
                <section class="panel">
                    <h2 class="section-title">Account Details</h2><br>
                    <p><b>Name:</b> <?= e($user['name']) ?></p>
                    <p><b>Email:</b> <?= e($user['email']) ?></p>
                    <p><b>Phone:</b> <?= e($user['phone']) ?></p>
                    <br><a class="see-all-btn" href="index.php?page=profile&tab=edit">Edit Profile</a>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'edit'): ?>
            <section class="panel">
                <h2 class="section-title">Edit Profile</h2><br>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="field"><label>Name</label><input name="name" value="<?= e($user['name']) ?>" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($user['email']) ?>" required></div>
                    <div class="field full"><label>Phone</label><input name="phone" value="<?= e($user['phone']) ?>"></div>
                    <button class="pill-btn full">Save Profile</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'wallet'): ?>
            <section class="wallet-card">
                <p>My Wallet</p>
                <strong><?= (int)$user['wallet_points'] ?> pts</strong>
                <p>Earned reward points ready to redeem. Active discount card capacity left: <?= $cardTotals['remaining_points'] ?> points.</p>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Wallet Transactions</h2><br>
                <table class="table">
                    <tr><th>Type</th><th>Points</th><th>Note</th><th>Date</th></tr>
                    <?php foreach ($txRows as $row): ?>
                        <tr><td><?= e($row['type']) ?></td><td><?= (int)$row['points'] ?></td><td><?= e($row['note']) ?></td><td><?= e($row['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'discount_card'): ?>
            <section class="panel">
                <h2 class="section-title">VMC Discount Card</h2>
                <p class="section-kicker">Discount cards do not add wallet balance. They define the maximum reward points that can be held and redeemed.</p><br>
                <h3>My Active Cards</h3><br>
                <?php if ($activeCards): ?>
                    <table class="table">
                        <tr><th>Card</th><th>Used / Total Points</th><th>Remaining Points</th><th>Activated</th></tr>
                        <?php foreach ($activeCards as $card): ?>
                            <tr><td><?= e($card['card_name']) ?></td><td><?= (int)$card['total_points'] - (int)$card['remaining_points'] ?> / <?= (int)$card['total_points'] ?></td><td><?= (int)$card['remaining_points'] ?></td><td><?= e($card['activated_at']) ?></td></tr>
                        <?php endforeach; ?>
                    </table><br>
                <?php else: ?>
                    <p class="small">No active card yet.</p><br>
                <?php endif; ?>
                <div class="prod-grid">
                    <?php foreach ($cardProducts as $p) render_product_card($p); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'orders'): ?>
            <section class="panel">
                <h2 class="section-title">My Orders</h2><br>
                <table class="table">
                    <tr><th>Order</th><th>Total</th><th>Reward Earned</th><th>Points Used</th><th>Status</th><th>Invoice</th><th>Date</th></tr>
                    <?php foreach ($orderRows as $o): ?>
                        <tr>
                            <td>#<?= (int)$o['id'] ?></td>
                            <td><?= money($o['grand_total']) ?></td>
                            <td><?= (int)$o['points_earned'] ?></td>
                            <td><?= (int)$o['points_used'] ?></td>
                            <td><?= e($o['status']) ?></td>
                            <td>
                                <?php if ($o['status'] === 'Completed'): ?>
                                    <a class="see-all-btn" href="index.php?page=invoice&id=<?= (int)$o['id'] ?>">View</a>
                                    <a class="see-all-btn" href="index.php?action=download_invoice&id=<?= (int)$o['id'] ?>">Download</a>
                                <?php else: ?>
                                    <span class="small">After approval</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($o['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'kyc' && $user['role'] === 'distributor'): ?>
            <section class="panel">
                <h2 class="section-title">Distributor KYC</h2>
                <p class="section-kicker">Upload PAN, bank proof, ID proof and optional GST document.</p><br>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="action" value="update_distributor_kyc">
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

        <?php if ($tab === 'documents' && $user['role'] === 'distributor'): ?>
            <div class="grid-2">
                <section class="panel letter-doc">
                    <span>Welcome Letter</span>
                    <h2>Welcome to VMCmarts Distributor Network</h2>
                    <p>Dear <?= e($user['name']) ?>, your distributor account has been created successfully.</p>
                    <p><b>Distributor ID:</b> <?= e($user['distributor_uid']) ?></p>
                    <p><b>KYC Status:</b> <?= e(ucwords(str_replace('_', ' ', $user['kyc_status']))) ?></p>
                </section>
                <section class="panel id-card-doc">
                    <span>VMCmarts</span>
                    <h2>Distributor ID Card</h2>
                    <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
                    <p><b><?= e($user['name']) ?></b></p>
                    <p>ID: <?= e($user['distributor_uid']) ?></p>
                    <p>Phone: <?= e($user['phone']) ?></p>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'team' && $user['role'] === 'distributor'): ?>
            <section class="panel">
                <h2 class="section-title">My Team</h2><br>
                <div class="ref-box"><?= e($user['distributor_uid']) ?></div>
                <p class="small">Referral signup link: <a href="<?= e($refLink) ?>"><?= e($refLink) ?></a></p><br>
                <table class="table">
                    <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Joined</th></tr>
                    <?php foreach ($teamRows as $member): ?>
                        <tr><td><?= e($member['name']) ?></td><td><?= e($member['email']) ?></td><td><?= e($member['phone']) ?></td><td><?= e($member['role']) ?></td><td><?= e($member['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$teamRows): ?><tr><td colspan="5">No team members yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'bv' && $user['role'] === 'distributor'): ?>
            <div class="grid-3">
                <div class="stats">Total BV Points<b><?= $bvTotal ?></b></div>
                <div class="stats">Current TBV<b><?= $bvSummary['current']['tbv'] ?></b></div>
                <div class="stats">Commission Earned<b><?= money($commissionTotal) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">Monthly BV Points</h2><br>
                <table class="table">
                    <tr><th>Month</th><th>PBV</th><th>GBV</th><th>TBV</th><th>Earnings</th><th>Details</th></tr>
                    <tr><td>Current Month (<?= e($bvSummary['current_month']) ?>)</td><td><?= $bvSummary['current']['pbv'] ?></td><td><?= $bvSummary['current']['gbv'] ?></td><td><?= $bvSummary['current']['tbv'] ?></td><td><?= money($bvSummary['current']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=profile&tab=bv&bv_month=<?= e($bvSummary['current_month']) ?>">Show</a></td></tr>
                    <tr><td>Previous Month (<?= e($bvSummary['previous_month']) ?>)</td><td><?= $bvSummary['previous']['pbv'] ?></td><td><?= $bvSummary['previous']['gbv'] ?></td><td><?= $bvSummary['previous']['tbv'] ?></td><td><?= money($bvSummary['previous']['earnings']) ?></td><td><a class="see-all-btn" href="index.php?page=profile&tab=bv&bv_month=<?= e($bvSummary['previous_month']) ?>">Show</a></td></tr>
                </table>
            </section><br>
            <section class="panel">
                <h2 class="section-title">Team BV Details - <?= e($selectedBvMonth) ?></h2>
                <p class="section-kicker">Direct member earned BV, their group BV, and their total business for the selected month.</p><br>
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
            <div class="grid-3">
                <div class="stats">PBV<b><?= $bvSummary['current']['pbv'] ?></b></div>
                <div class="stats">GBV<b><?= $bvSummary['current']['gbv'] ?></b></div>
                <div class="stats">Direct Downline<b><?= count($teamRows) ?></b></div>
            </div><br>
            <section class="panel">
                <h2 class="section-title">BV Points Ledger</h2><br>
                <table class="table">
                    <tr><th>Type</th><th>Level</th><th>BV Points</th><th>Rate</th><th>Earning</th><th>Source</th><th>Month</th><th>Note</th><th>Date</th></tr>
                    <?php foreach ($bvRows as $row): ?>
                        <tr>
                            <td><?= e(str_replace('_', ' ', $row['type'])) ?></td>
                            <td><?= $row['level_no'] ? 'L' . (int)$row['level_no'] : '-' ?></td>
                            <td><?= (int)$row['points'] ?></td>
                            <td><?= $row['commission_percent'] !== null ? e(rtrim(rtrim((string)$row['commission_percent'], '0'), '.')) . '%' : '-' ?></td>
                            <td><?= money((float)$row['commission_amount']) ?></td>
                            <td><?= e($row['source_name'] ?? 'Admin') ?></td>
                            <td><?= e($row['share_month']) ?></td>
                            <td><?= e($row['note']) ?></td>
                            <td><?= e($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bvRows): ?><tr><td colspan="9">No BV points yet.</td></tr><?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'genealogy' && $user['role'] === 'distributor'): ?>
            <section class="panel">
                <h2 class="section-title">Genealogy Tree</h2>
                <p class="section-kicker">Direct downline and group structure under your distributor ID.</p><br>
                <ul class="genealogy-tree"><?php if ($tree) render_genealogy_node($tree); ?></ul>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'terms'): ?>
            <section class="panel policy-panel">
                <h2 class="section-title">Terms & Conditions</h2>
                <p>By using VMCmarts, customers agree to provide accurate account, delivery and contact information.</p>
                <p>Product prices, MRP, tax, stock and offers can change based on admin updates. Orders are accepted subject to stock availability.</p>
                <p>Reward points are added to the wallet after admin completes an order, up to the active discount card capacity. Customers may redeem only the reward points they have actually earned.</p>
                <p>Discount card purchases become active only after admin order confirmation.</p>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'privacy'): ?>
            <section class="panel policy-panel">
                <h2 class="section-title">Privacy Policy</h2>
                <p>VMCmarts stores basic account details like name, email, phone, order history and wallet transactions for ecommerce operations.</p>
                <p>Your information is used for login, delivery, order management, wallet points and customer support.</p>
                <p>Admin and super admin users can view required operational information to manage the store.</p>
                <p>Do not share your password. Keep your login details safe.</p>
            </section>
        <?php endif; ?>
    </section>
</div>
