<?php
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product): ?>
    <section class="panel">Product not found. <a class="see-all-btn" href="index.php?page=products">Back to products</a></section>
<?php else:
    $images = product_gallery_images($product);
    $relatedStmt = db()->prepare('SELECT * FROM products WHERE is_active = 1 AND category = ? AND id <> ? ORDER BY created_at DESC LIMIT 4');
    $relatedStmt->execute([$product['category'], $product['id']]);
    $related = $relatedStmt->fetchAll();
?>
<section class="product-view">
    <div class="product-gallery" data-product-gallery>
        <div class="gallery-main">
            <?php foreach ($images as $i => $img): ?>
                <img class="gallery-image <?= $i === 0 ? 'active' : '' ?>" src="<?= e($img) ?>" alt="<?= e($product['name']) ?> image <?= $i + 1 ?>">
            <?php endforeach; ?>
        </div>
        <div class="gallery-thumbs">
            <?php foreach ($images as $i => $img): ?>
                <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-gallery-thumb="<?= $i ?>">
                    <img src="<?= e($img) ?>" alt="Thumbnail <?= $i + 1 ?>">
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="product-info">
        <div class="breadcrumb"><a href="index.php?page=products">Products</a> / <a href="index.php?page=products&category=<?= urlencode($product['category']) ?>"><?= e($product['category']) ?></a></div>
        <h1><?= e($product['name']) ?></h1>
        <div class="rating-row"><span>4.6</span><b>Customer loved product</b><em><?= (int)$product['stock'] ?> in stock</em></div>
        <div class="detail-price">
            <strong><?= money($product['selling_price']) ?></strong>
        </div>
        <p class="tax-note">Selling price includes <?= e($product['tax_percent']) ?>% GST.</p>
        <?php if ($product['product_type'] === 'discount_points'): ?>
            <div class="points-box">
                <b><?= (int)$product['discount_points'] ?> points</b>
                <span>This card becomes active after admin confirms the card order.</span>
            </div>
        <?php endif; ?>
        <form method="post" class="detail-actions">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="back" value="product">
            <label>Qty <input type="number" name="qty" value="1" min="1" max="<?= max(1, (int)$product['stock']) ?>"></label>
            <button name="action" value="add_to_cart" class="add-btn">Add to Cart</button>
            <button name="action" value="buy_now" class="buy-btn">Buy Now</button>
        </form>
        <div class="delivery-card">
            <div><b>Fast delivery</b><span>Local delivery with order tracking.</span></div>
            <div><b>Wallet savings</b><span>Use available points at checkout.</span></div>
            <div><b>Admin controlled</b><span>Price, tax and stock are managed from backend.</span></div>
        </div>
    </div>
</section>

<section class="product-description panel">
    <h2>Description</h2>
    <p><?= nl2br(e($product['description'] ?: 'A quality VMCmarts product with transparent MRP, selling price and tax.')) ?></p>
    <div class="spec-grid">
        <div><span>Category</span><b><?= e($product['category']) ?></b></div>
        <div><span>Product Type</span><b><?= e(str_replace('_', ' ', $product['product_type'])) ?></b></div>
        <div><span>Tax</span><b><?= e($product['tax_percent']) ?>%</b></div>
        <div><span>Stock</span><b><?= (int)$product['stock'] ?></b></div>
    </div>
</section>

<?php if ($related): ?>
<section class="section">
    <div class="section-head">
        <h2 class="section-title">Related Products</h2>
        <a class="see-all-btn" href="index.php?page=products&category=<?= urlencode($product['category']) ?>">View More</a>
    </div>
    <div class="prod-grid">
        <?php foreach ($related as $p) render_product_card($p); ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
