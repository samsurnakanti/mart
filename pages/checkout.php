<?php
$user = require_login();
$rows = cart_products();
$totals = cart_totals($rows);
$activeCardBalance = active_card_balance((int)$user['id']);
$maxRedeemable = min((int)$user['wallet_points'], $activeCardBalance, (int)floor($totals['total']));
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
            <section class="redeem-card full" data-redeem-box data-order-total="<?= e(number_format($totals['total'], 2, '.', '')) ?>" data-max-redeem="<?= $maxRedeemable ?>">
                <div class="redeem-copy">
                    <span>Wallet savings</span>
                    <h2>Redeem reward points</h2>
                    <p><?= (int)$user['wallet_points'] ?> points available · <?= $activeCardBalance ?> card capacity left</p>
                </div>
                <div class="redeem-entry">
                    <label for="points_to_use">Use points</label>
                    <div>
                        <input id="points_to_use" type="number" min="0" max="<?= $maxRedeemable ?>" name="points_to_use" value="0" data-redeem-input>
                        <button type="button" data-use-max>Use max</button>
                    </div>
                </div>
                <div class="redeem-preview">
                    <span>You save <b data-redeem-saving><?= money(0) ?></b></span>
                    <span>Total after points <b data-redeem-total><?= money($totals['total']) ?></b></span>
                </div>
                <?php if ($activeCardBalance <= 0): ?>
                    <p class="redeem-note">Buy a discount card before redeeming wallet points.</p>
                <?php elseif ((int)$user['wallet_points'] <= 0): ?>
                    <p class="redeem-note">No reward points available yet. Admin-added points will appear here for your next order.</p>
                <?php endif; ?>
            </section>
            <div class="field full"><label>Khammam Delivery Address</label><textarea name="shipping_address" placeholder="House no, street, area, landmark, Khammam" required></textarea></div>
            <button class="pill-btn full">Place Order</button>
        </form>
    </section>
    <section class="wallet-card">
        <p>Order Total</p>
        <strong><?= money($totals['total']) ?></strong>
        <p>
            Taxable value: <?= money($totals['subtotal']) ?><br>
            GST included: <?= money($totals['tax']) ?><br>
            Ready to redeem: <?= (int)$user['wallet_points'] ?> points<br>
            Active discount card balance: <?= $activeCardBalance ?> points
        </p>
        <?php if ($activeCardBalance <= 0): ?>
            <p class="small">Buy a discount card before redeeming wallet points.</p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
