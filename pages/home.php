<?php
$search = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
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
$sql .= ' ORDER BY product_type DESC, created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$discountCards = array_values(array_filter($products, fn($p) => $p['product_type'] === 'discount_points'));
$regularProducts = array_values(array_filter($products, fn($p) => $p['product_type'] !== 'discount_points'));
?>
<section class="storefront">
    <div class="hero-slider" data-slider>
        <article class="hero-slide active">
            <div class="hero-copy">
                <div class="hero-tag">VMCmarts daily essentials</div>
                <h1>Fresh groceries with smart wallet savings.</h1>
                <p>Shop household needs in Khammam, add products to cart, buy instantly, and earn discount points on every eligible purchase.</p>
                <div class="hero-actions">
                    <a class="primary-cta" href="#products">Shop Products</a>
                    <a class="secondary-cta" href="#discount-cards">Buy Discount Card</a>
                </div>
            </div>
            <div class="hero-visual">
                <div class="basket-card main">Fresh<br><b>Vegetables</b><span>Up to 35% off</span></div>
                <div class="basket-card side top">Grocery<br><b>Kitchen</b></div>
                <div class="basket-card side bottom">Wallet<br><b>Points</b></div>
            </div>
        </article>
        <article class="hero-slide">
            <div class="hero-copy">
                <div class="hero-tag">Discount card marketplace</div>
                <h1>Buy points like a product. Redeem them at checkout.</h1>
                <p>VMC Discount Cards are products in your store. Once the order is placed, points are credited to the user wallet.</p>
                <div class="hero-actions">
                    <a class="primary-cta" href="#discount-cards">View Cards</a>
                    <a class="secondary-cta" href="index.php?page=profile">My Wallet</a>
                </div>
            </div>
            <div class="hero-visual card-visual">
                <div class="vmc-card">
                    <span>VMCmarts</span>
                    <strong>Discount Card</strong>
                    <em>Use points like wallet balance</em>
                </div>
            </div>
        </article>
        <article class="hero-slide">
            <div class="hero-copy">
                <div class="hero-tag">Production ready flow</div>
                <h1>Admin-managed prices, tax, stock and images.</h1>
                <p>Super admin controls users and roles. Admin manages products, orders, stock and fulfillment status.</p>
                <div class="hero-actions">
                    <a class="primary-cta" href="index.php?page=admin">Admin Panel</a>
                    <a class="secondary-cta" href="index.php?page=super_admin">Super Admin</a>
                </div>
            </div>
            <div class="hero-visual">
                <div class="ops-panel"><b>Live Store</b><span>Products</span><span>Orders</span><span>Wallet</span></div>
            </div>
        </article>
        <div class="slider-dots" data-slider-dots></div>
    </div>
    <aside class="deal-stack">
        <div class="deal-card warm">
            <span>Weekend Saver</span>
            <b>MRP deals with visible tax</b>
            <a href="#products">Shop now</a>
        </div>
        <div class="deal-card cool">
            <span>Wallet Boost</span>
            <b>Buy cards, earn points instantly</b>
            <a href="#discount-cards">Explore</a>
        </div>
    </aside>
</section>

<section class="trust-row">
    <div><b>Fast Delivery</b><span>Local orders handled quickly</span></div>
    <div><b>Secure Login</b><span>User accounts and profiles</span></div>
    <div><b>Wallet Points</b><span>Earn and redeem discounts</span></div>
    <div><b>Admin Control</b><span>Products, stock and orders</span></div>
</section>

<section class="section image-showcase">
    <div class="section-head">
        <div>
            <h2 class="section-title">Fresh Deals Gallery</h2>
            <p class="section-kicker">Swipe product stories, seasonal offers and wallet promotions.</p>
        </div>
        <div class="image-slider-controls">
            <button type="button" data-image-prev aria-label="Previous image">Prev</button>
            <button type="button" data-image-next aria-label="Next image">Next</button>
        </div>
    </div>
    <div class="image-slider" data-image-slider>
        <article class="image-slide active">
            <img src="assets/slider-fresh.svg" alt="Fresh vegetables and groceries">
            <div class="image-slide-copy">
                <span>Farm Fresh</span>
                <h3>Vegetables, fruits and kitchen essentials</h3>
                <a href="#products">Shop Fresh</a>
            </div>
        </article>
        <article class="image-slide">
            <img src="assets/slider-grocery.svg" alt="Grocery shelves with packaged goods">
            <div class="image-slide-copy">
                <span>Daily Staples</span>
                <h3>MRP, tax and discounts managed from admin</h3>
                <a href="index.php?page=admin">Manage Store</a>
            </div>
        </article>
        <article class="image-slide">
            <img src="assets/slider-wallet.svg" alt="Wallet discount card">
            <div class="image-slide-copy">
                <span>Wallet Rewards</span>
                <h3>Buy discount cards and redeem points later</h3>
                <a href="#discount-cards">Buy Card</a>
            </div>
        </article>
        <div class="image-slider-dots" data-image-dots></div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Shop by Category</h2>
        <a class="see-all-btn" href="index.php">All Products</a>
    </div>
    <div class="cats-scroll">
        <?php foreach ($categories as $cat): ?>
            <a class="cat-chip" href="index.php?category=<?= urlencode($cat) ?>"><?= e($cat) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($discountCards): ?>
<section class="section" id="discount-cards">
    <div class="section-head">
        <div>
            <h2 class="section-title">VMC Discount Cards</h2>
            <p class="section-kicker">Treat discount points like wallet value after purchase.</p>
        </div>
        <a class="see-all-btn" href="index.php?category=Discount+Cards">View Cards</a>
    </div>
    <div class="product-carousel">
        <?php foreach ($discountCards as $p) render_product_card($p); ?>
    </div>
</section>
<?php endif; ?>

<section class="section promo-band">
    <div>
        <span>Professional ecommerce flow</span>
        <h2>Cart, Buy Now, Checkout, Wallet and Order Tracking are connected.</h2>
    </div>
    <a class="primary-cta" href="index.php?page=cart">Review Cart</a>
</section>

<section class="section" id="products">
    <div class="section-head">
        <div>
            <h2 class="section-title"><?= $search || $category ? 'Matching Products' : 'Featured Products' ?></h2>
            <p class="section-kicker">Modern product grid with MRP, price, tax-ready checkout and discount points.</p>
        </div>
        <a class="see-all-btn" href="index.php?page=cart">Open Cart</a>
    </div>
    <div class="prod-grid">
        <?php foreach (($search || $category ? $products : $regularProducts) as $p) render_product_card($p); ?>
        <?php if (!$products): ?><div class="panel">No products found.</div><?php endif; ?>
    </div>
</section>
