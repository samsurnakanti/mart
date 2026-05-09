<?php
$user = require_login();
$rows = cart_products();
$totals = cart_totals($rows);
if (!$rows): ?>
    <section class="panel">Your cart is empty. <a class="see-all-btn" href="index.php">Shop now</a></section>
<?php else: ?>
<div class="grid-2">
    <section class="panel">
        <h1 class="section-title">Checkout</h1><br>
        <div class="service-note">Delivery service is currently available only in <b>Khammam</b>. Admin will manually pack, ship and update your order status.</div><br>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="place_order">
            <div class="field"><label>Name</label><input name="shipping_name" value="<?= e($user['name']) ?>" required></div>
            <div class="field"><label>Phone</label><input name="shipping_phone" value="<?= e($user['phone']) ?>" required></div>
            <div class="field"><label>City</label><input name="shipping_city" value="Khammam" readonly></div>
            <div class="field full"><label>Khammam Delivery Address</label><textarea name="shipping_address" placeholder="House no, street, area, landmark, Khammam" required></textarea></div>
            <div class="field full"><label>Use Wallet Discount Points</label><input type="number" name="points_used" min="0" max="<?= (int)$user['wallet_points'] ?>" value="0"></div>
            <button class="pill-btn full">Place Order</button>
        </form>
    </section>
    <section class="wallet-card">
        <p>Order Total</p>
        <strong><?= money($totals['total']) ?></strong>
        <p>Subtotal: <?= money($totals['subtotal']) ?><br>Tax: <?= money($totals['tax']) ?><br>Wallet: <?= (int)$user['wallet_points'] ?> points<br>Will earn: <?= (int)$totals['points'] ?> points</p>
    </section>
</div>
<?php endif; ?>
