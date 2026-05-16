<?php
$user = require_login();
$tab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'edit', 'wallet', 'discount_card', 'orders', 'terms', 'privacy'];
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
?>
<section class="account-hero">
    <div class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
    <div>
        <span>VMCmarts Account</span>
        <h1><?= e($user['name']) ?></h1>
        <p><?= e($user['email']) ?> · <?= $cardTotals['remaining_points'] ?> remaining points</p>
    </div>
</section>

<div class="account-layout">
    <aside class="account-menu">
        <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="index.php?page=profile&tab=overview">Overview</a>
        <a class="<?= $tab === 'edit' ? 'active' : '' ?>" href="index.php?page=profile&tab=edit">Edit Profile</a>
        <a class="<?= $tab === 'wallet' ? 'active' : '' ?>" href="index.php?page=profile&tab=wallet">My Wallet</a>
        <a class="<?= $tab === 'discount_card' ? 'active' : '' ?>" href="index.php?page=profile&tab=discount_card">Discount Card</a>
        <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="index.php?page=profile&tab=orders">Orders</a>
        <a class="<?= $tab === 'terms' ? 'active' : '' ?>" href="index.php?page=profile&tab=terms">Terms</a>
        <a class="<?= $tab === 'privacy' ? 'active' : '' ?>" href="index.php?page=profile&tab=privacy">Privacy</a>
        <a class="danger-link" href="index.php?action=logout">Logout</a>
    </aside>

    <section class="account-content">
        <?php if ($tab === 'overview'): ?>
            <div class="grid-3">
                <div class="stats">Total Card Points<b><?= $cardTotals['total_points'] ?></b></div>
                <div class="stats">Used Points<b><?= $cardTotals['used_points'] ?></b></div>
                <div class="stats">Remaining Points<b><?= $cardTotals['remaining_points'] ?></b></div>
            </div><br>
            <div class="grid-2">
                <div class="stats">Orders<b><?= count($orderRows) ?></b></div>
                <div class="stats">Role<b><?= e($user['role']) ?></b></div>
            </div><br>
            <div class="grid-2">
                <section class="wallet-card">
                    <p>Discount Card Wallet</p>
                    <strong><?= $cardTotals['remaining_points'] ?> pts</strong>
                    <p>Total <?= $cardTotals['total_points'] ?> / Used <?= $cardTotals['used_points'] ?> / Remaining <?= $cardTotals['remaining_points'] ?></p>
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
                <strong><?= $cardTotals['remaining_points'] ?> pts</strong>
                <p>Total <?= $cardTotals['total_points'] ?> points · Used <?= $cardTotals['used_points'] ?> points · Remaining <?= $cardTotals['remaining_points'] ?> points</p>
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
                <p class="section-kicker">Discount cards must be purchased first. After admin confirms the card order, it appears below as an active card.</p><br>
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
                    <tr><th>Order</th><th>Total</th><th>Card Points Used</th><th>Status</th><th>Date</th></tr>
                    <?php foreach ($orderRows as $o): ?>
                        <tr><td>#<?= (int)$o['id'] ?></td><td><?= money($o['grand_total']) ?></td><td><?= (int)$o['points_used'] ?></td><td><?= e($o['status']) ?></td><td><?= e($o['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($tab === 'terms'): ?>
            <section class="panel policy-panel">
                <h2 class="section-title">Terms & Conditions</h2>
                <p>By using VMCmarts, customers agree to provide accurate account, delivery and contact information.</p>
                <p>Product prices, MRP, tax, stock and offers can change based on admin updates. Orders are accepted subject to stock availability.</p>
                <p>Card points are promotional discount value and are allotted to orders only by admin.</p>
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
