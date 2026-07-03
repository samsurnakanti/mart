<?php
$isDistributorSignup = ($_GET['type'] ?? '') === 'distributor';
$pendingSignup = $_SESSION['pending_signup']['data'] ?? null;
$isVerifyingSignup = ($_GET['verify'] ?? '') === '1' && $pendingSignup;
if ($pendingSignup) {
    $isDistributorSignup = ($pendingSignup['account_type'] ?? 'user') === 'distributor';
}
?>
<section class="panel">
    <h1 class="section-title"><?= $isDistributorSignup ? 'Create Distributor Account' : 'Create Account' ?></h1><br>
    <?php if ($isVerifyingSignup): ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="signup">
            <div class="field full"><label>WhatsApp OTP</label><input name="otp" inputmode="numeric" maxlength="6" required></div>
            <button class="pill-btn full">Verify OTP & Create Account</button>
        </form>
        <p class="small" style="margin-top:12px">OTP sent to <?= e($pendingSignup['phone'] ?? '') ?>. <a href="index.php?page=signup<?= $isDistributorSignup ? '&type=distributor' : '' ?>">Start again</a></p>
    <?php else: ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="request_signup_otp">
            <input type="hidden" name="account_type" value="<?= $isDistributorSignup ? 'distributor' : 'user' ?>">
            <div class="field"><label>Name</label><input name="name" required></div>
            <div class="field"><label>WhatsApp Mobile Number</label><input name="phone" inputmode="tel" required></div>
            <div class="field full"><label>Email</label><input type="email" name="email" required></div>
            <div class="field full"><label>Password</label><input type="password" name="password" minlength="6" required></div>
            <div class="field full"><label>Referral Distributor ID</label><input name="referral_id" value="<?= e($_GET['ref'] ?? '') ?>" placeholder="Optional"></div>
            <button class="pill-btn full">Send WhatsApp OTP</button>
        </form>
        <p class="small" style="margin-top:12px">
            <?php if ($isDistributorSignup): ?>
                <a href="index.php?page=signup">Customer signup</a>
            <?php else: ?>
                <a href="index.php?page=signup&type=distributor">Become a distributor</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>
