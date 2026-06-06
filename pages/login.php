<?php $isDistributorLogin = ($_GET['type'] ?? '') === 'distributor'; ?>
<div class="grid-2">
    <section class="panel">
        <h1 class="section-title"><?= $isDistributorLogin ? 'Distributor Login' : 'Login' ?></h1><br>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="<?= $isDistributorLogin ? 'distributor_login' : 'login' ?>">
            <div class="field full"><label><?= $isDistributorLogin ? 'Distributor ID / Mobile Number' : 'Mobile Number' ?></label><input name="login" inputmode="text" placeholder="<?= $isDistributorLogin ? 'Enter distributor ID or mobile number' : 'Enter mobile number' ?>" required></div>
            <div class="field full"><label>Password</label><input type="password" name="password" required></div>
            <button class="pill-btn full">Login</button>
        </form>
        <p class="small" style="margin-top:12px">Don't have an account? <a href="index.php?page=signup<?= $isDistributorLogin ? '&type=distributor' : '' ?>">Create account / Signup now</a></p>
        <p class="small" style="margin-top:8px">
            <?php if ($isDistributorLogin): ?>
                <a href="index.php?page=login">Customer login</a>
            <?php else: ?>
                <a href="index.php?page=login&type=distributor">Distributor login</a>
            <?php endif; ?>
        </p>
    </section>
    <section class="wallet-card">
        <p><?= $isDistributorLogin ? 'VMCmarts Distributor' : 'VMCmarts Discount Wallet' ?></p>
        <strong><?= $isDistributorLogin ? 'Team' : 'Points' ?></strong>
        <p><?= $isDistributorLogin ? 'Login to manage KYC, profile documents, referral ID and team members.' : 'Users can buy discount cards, then admin activates them after confirming the order.' ?></p>
    </section>
</div>
