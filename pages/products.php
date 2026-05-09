<?php
$search = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort = $_GET['sort'] ?? 'new';
$categories = db()->query('SELECT DISTINCT category FROM products WHERE is_active = 1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

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
$orderBy = match ($sort) {
    'price_low' => 'selling_price ASC',
    'price_high' => 'selling_price DESC',
    'points' => 'discount_points DESC',
    default => 'created_at DESC',
};
$sql .= ' ORDER BY ' . $orderBy;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>
<section class="catalog-hero">
    <div>
        <span>VMCmarts Catalog</span>
        <h1>Browse all products</h1>
        <p>View MRP, offers, tax-ready pricing, stock, descriptions and wallet discount points before buying.</p>
    </div>
</section>

<section class="catalog-layout">
    <aside class="catalog-filter">
        <h2>Filters</h2>
        <a class="<?= $category === '' ? 'active' : '' ?>" href="index.php?page=products">All Categories</a>
        <?php foreach ($categories as $cat): ?>
            <a class="<?= $category === $cat ? 'active' : '' ?>" href="index.php?page=products&category=<?= urlencode($cat) ?>"><?= e($cat) ?></a>
        <?php endforeach; ?>
    </aside>
    <div class="catalog-main">
        <form class="catalog-toolbar" method="get">
            <input type="hidden" name="page" value="products">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search in VMCmarts">
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
            <select name="sort">
                <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
                <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                <option value="points" <?= $sort === 'points' ? 'selected' : '' ?>>Most Points</option>
            </select>
            <button class="primary-cta">Apply</button>
        </form>
        <div class="section-head">
            <div>
                <h2 class="section-title"><?= $category ? e($category) : 'All Products' ?></h2>
                <p class="section-kicker"><?= count($products) ?> products found</p>
            </div>
        </div>
        <div class="prod-grid">
            <?php foreach ($products as $p) render_product_card($p); ?>
            <?php if (!$products): ?><div class="panel">No products found.</div><?php endif; ?>
        </div>
    </div>
</section>
