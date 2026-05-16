<?php
$search = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$categories = categories(true);
$homeSliders = sliders(true);
$sql = 'SELECT * FROM products WHERE is_active = 1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR category LIKE ? OR description LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}
if ($category !== '') {
    $sql .= ' AND category = ?';
    $params[] = $category;
}
$sql .= ' ORDER BY product_type DESC, created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$discountCards = array_values(array_filter($products, fn($p) => $p['product_type'] === 'discount_points'));
$regularProducts = array_values(array_filter($products, fn($p) => $p['product_type'] !== 'discount_points'));
?>

<?php if ($homeSliders): ?>
<section class="storefront storefront-clean">
    <div class="hero-slider" data-slider>
        <?php foreach ($homeSliders as $i => $slide): ?>
            <article class="hero-slide <?= $i === 0 ? 'active' : '' ?>">
                <div class="hero-copy">
                    <h1><?= e($slide['title']) ?></h1>
                    <?php if ($slide['subtitle']): ?><p><?= e($slide['subtitle']) ?></p><?php endif; ?>
                    <?php if ($slide['button_text'] && $slide['button_link']): ?>
                        <div class="hero-actions">
                            <a class="primary-cta" href="<?= e($slide['button_link']) ?>"><?= e($slide['button_text']) ?></a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="hero-banner-image">
                    <img src="<?= e($slide['image_path']) ?>" alt="<?= e($slide['title']) ?>">
                </div>
            </article>
        <?php endforeach; ?>
        <div class="slider-dots" data-slider-dots></div>
    </div>
</section>
<?php endif; ?>

<?php if ($discountCards): ?>
<section class="section" id="discount-cards">
    <div class="section-head">
        <div>
            <h2 class="section-title">Discount Cards</h2>
            <p class="section-kicker">Buy cards first; admin activates them after confirming the order.</p>
        </div>
        <a class="see-all-btn" href="index.php?page=products&category=Discount+Cards">View Cards</a>
    </div>
    <div class="product-carousel">
        <?php foreach ($discountCards as $p) render_product_card($p); ?>
    </div>
</section>
<?php endif; ?>

<section class="section" id="products">
    <div class="section-head">
        <div>
            <h2 class="section-title"><?= $search || $category ? 'Matching Products' : 'Products' ?></h2>
        </div>
        <a class="see-all-btn" href="index.php?page=products">View All</a>
    </div>
    <div class="prod-grid">
        <?php foreach (($search || $category ? $products : $regularProducts) as $p) render_product_card($p); ?>
        <?php if (!$products): ?><div class="panel">No products found.</div><?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Categories</h2>
    </div>
    <div class="category-card-grid">
        <?php foreach ($categories as $cat): ?>
            <a class="category-card" href="index.php?page=products&category=<?= urlencode($cat['name']) ?>">
                <?php if (product_image($cat['image_path'])): ?><img src="<?= e($cat['image_path']) ?>" alt="<?= e($cat['name']) ?>"><?php endif; ?>
                <b><?= e($cat['name']) ?></b>
            </a>
        <?php endforeach; ?>
    </div>
</section>
