<section class="panel">
    <h1 class="section-title">Create Account</h1><br>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="signup">
        <div class="field"><label>Name</label><input name="name" required></div>
        <div class="field"><label>Mobile Number</label><input name="phone" inputmode="tel" required></div>
        <div class="field full"><label>Email</label><input type="email" name="email" required></div>
        <div class="field full"><label>Password</label><input type="password" name="password" minlength="6" required></div>
        <button class="pill-btn full">Signup</button>
    </form>
</section>
