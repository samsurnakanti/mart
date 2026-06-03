<?php $isDistributorSignup = ($_GET['type'] ?? '') === 'distributor'; ?>
<section class="panel">
    <h1 class="section-title"><?= $isDistributorSignup ? 'Create Distributor Account' : 'Create Account' ?></h1><br>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="signup">
        <input type="hidden" name="account_type" value="<?= $isDistributorSignup ? 'distributor' : 'user' ?>">
        <div class="field"><label>Name</label><input name="name" required></div>
        <div class="field"><label>Mobile Number</label><input name="phone" inputmode="tel" required></div>
        <div class="field full"><label>Email</label><input type="email" name="email" required></div>
        <div class="field full"><label>Password</label><input type="password" name="password" minlength="6" required></div>
        <div class="field full"><label>Referral Distributor ID</label><input name="referral_id" value="<?= e($_GET['ref'] ?? '') ?>" placeholder="Optional"></div>
        <button class="pill-btn full"><?= $isDistributorSignup ? 'Signup as Distributor' : 'Signup' ?></button>
    </form>
    <p class="small" style="margin-top:12px">
        <?php if ($isDistributorSignup): ?>
            <a href="index.php?page=signup">Customer signup</a>
        <?php else: ?>
            <a href="index.php?page=signup&type=distributor">Become a distributor</a>
        <?php endif; ?>
    </p>
</section>
