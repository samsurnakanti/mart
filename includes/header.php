<?php $user = current_user(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VMCmarts - Fresh Groceries, Smart Savings</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/style.css">
<script defer src="assets/app.js"></script>
</head>
<body>
<div class="announce-bar">
    <span>Service available only in Khammam</span>
    <span>Admin ships orders manually</span>
    <span>Redeem wallet points during checkout with an active discount card</span>
</div>
<header class="mobile-store-header">
    <div class="mobile-topline">
        <a class="mobile-logo" href="index.php">VMC<span>marts</span></a>
        <div class="mobile-actions">
            <?php if ($user): ?>
                <a href="index.php?page=profile" aria-label="Wallet">Wallet</a>
                <a href="index.php?page=cart" aria-label="Cart">Cart <b><?= cart_count() ?></b></a>
            <?php else: ?>
                <a href="index.php?page=login" aria-label="Login">Login</a>
                <a href="index.php?page=signup" aria-label="Signup">Signup</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mobile-location">
        <span>Delivering to</span>
        <b>Khammam only</b>
    </div>
    <form class="mobile-search" method="get">
        <input type="hidden" name="page" value="products">
        <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search groceries, cards, daily needs">
        <button>Search</button>
    </form>
    <div class="mobile-chip-row">
        <a href="index.php?page=products">Products</a>
        <a href="index.php?page=products&category=Discount+Cards">Discount Cards</a>
        <a href="index.php?page=cart">Cart</a>
        <?php if (!$user): ?><a href="index.php?page=signup">Create Account</a><?php endif; ?>
        <?php if ($user && in_array($user['role'], ['admin', 'super_admin'], true)): ?><a href="index.php?page=admin">Admin</a><?php endif; ?>
    </div>
</header>
<nav class="desktop-nav">
    <div class="nav-inner">
        <a class="logo" href="index.php">VMC<span>marts</span><small>mart smart</small></a>
        <form class="search-bar" method="get">
            <input type="hidden" name="page" value="products">
            <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search products, categories, discount cards">
            <button class="s-btn">Search</button>
        </form>
        <div class="nav-actions">
            <a class="nav-btn" href="index.php?page=cart">Cart <span class="badge"><?= cart_count() ?></span></a>
            <?php if ($user): ?>
                <a class="nav-btn" href="index.php?page=profile">Wallet <span class="badge"><?= (int)$user['wallet_points'] ?></span></a>
                <?php if (in_array($user['role'], ['admin', 'super_admin'], true)): ?><a class="nav-btn" href="index.php?page=admin">Admin</a><?php endif; ?>
                <?php if ($user['role'] === 'super_admin'): ?><a class="nav-btn" href="index.php?page=super_admin">Super Admin</a><?php endif; ?>
                <a class="nav-btn" href="index.php?action=logout">Logout</a>
            <?php else: ?>
                <a class="nav-btn" href="index.php?page=login">Login</a>
                <a class="pill-btn" href="index.php?page=signup">Signup</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php $headerCategories = array_slice(categories(true), 0, 10); ?>
<div class="category-rail">
    <div class="category-rail-inner">
        <a href="index.php?page=products" class="rail-link">All Products</a>
        <?php foreach ($headerCategories as $cat): ?>
            <a href="index.php?page=products&category=<?= urlencode($cat['name']) ?>" class="rail-link"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
        <a href="index.php?page=profile" class="rail-link rail-wallet">My Wallet</a>
    </div>
</div>
<?php if ($messages = flashes()): ?>
<div class="flash"><?php foreach ($messages as $m): ?><div class="<?= e($m['type']) ?>"><?= e($m['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php $activePage = $_GET['page'] ?? 'home'; ?>
<nav class="mobile-bottom-nav" aria-label="Mobile navigation">
    <a class="<?= $activePage === 'home' ? 'active' : '' ?>" href="index.php"><i>Home</i><span>Home</span></a>
    <a class="<?= $activePage === 'products' || $activePage === 'product' ? 'active' : '' ?>" href="index.php?page=products"><i>Shop</i><span>Shop</span></a>
    <a class="<?= $activePage === 'cart' ? 'active' : '' ?>" href="index.php?page=cart"><i>Cart</i><span>Cart</span><b><?= cart_count() ?></b></a>
    <a class="<?= $activePage === 'profile' ? 'active' : '' ?>" href="index.php?page=profile"><i>Wallet</i><span>Account</span></a>
</nav>
<main class="page-wrap">
