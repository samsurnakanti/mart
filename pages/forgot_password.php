<?php $isVerifyingReset = ($_GET['verify'] ?? '') === '1' && !empty($_SESSION['password_reset']); ?>
<section class="panel">
    <h1 class="section-title">Forgot Password</h1><br>
    <?php if ($isVerifyingReset): ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="reset_password">
            <div class="field full"><label>WhatsApp OTP</label><input name="otp" inputmode="numeric" maxlength="6" required></div>
            <div class="field full"><label>New Password</label><input type="password" name="password" minlength="6" required></div>
            <button class="pill-btn full">Set New Password</button>
        </form>
        <p class="small" style="margin-top:12px"><a href="index.php?page=forgot_password">Request a new OTP</a></p>
    <?php else: ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="request_password_reset_otp">
            <div class="field full"><label>Mobile Number / Email / Distributor ID</label><input name="login" inputmode="text" required></div>
            <button class="pill-btn full">Send WhatsApp OTP</button>
        </form>
        <p class="small" style="margin-top:12px"><a href="index.php?page=login">Back to login</a></p>
    <?php endif; ?>
</section>
