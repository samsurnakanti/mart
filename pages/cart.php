<?php $rows = cart_products(); $totals = cart_totals($rows); ?>
<section class="panel">
    <div class="section-head">
        <h1 class="section-title">Shopping Cart</h1>
        <a class="see-all-btn" href="index.php">Continue Shopping</a>
    </div>
    <?php if (!$rows): ?>
        <p>Your cart is empty.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="update_cart">
            <table class="table">
                <tr><th>Product</th><th>Qty</th><th>MRP</th><th>Price</th><th>Tax</th><th>Total</th><th></th></tr>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?><br><span class="small"><?= e($row['product_type']) ?> | Earn <?= (int)$row['discount_points'] ?> points each</span></td>
                        <td><input style="width:70px" type="number" min="0" name="qty[<?= (int)$row['id'] ?>]" value="<?= (int)$row['qty'] ?>"></td>
                        <td><?= money($row['mrp']) ?></td>
                        <td><?= money($row['selling_price']) ?></td>
                        <td><?= money($row['line_tax']) ?></td>
                        <td><?= money($row['line_total']) ?></td>
                        <td><a class="danger-link" href="index.php?action=remove_cart&id=<?= (int)$row['id'] ?>">Remove</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <br><button class="see-all-btn">Update Cart</button>
        </form>
        <br>
        <div class="grid-3">
            <div class="stats">Subtotal<b><?= money($totals['subtotal']) ?></b></div>
            <div class="stats">Tax<b><?= money($totals['tax']) ?></b></div>
            <div class="stats">Earn Points<b><?= (int)$totals['points'] ?></b></div>
        </div>
        <br><a class="pill-btn" href="index.php?page=checkout">Checkout</a>
    <?php endif; ?>
</section>
