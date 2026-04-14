<?php
$token = $_GET['token'] ?? '';
$invitation = null;
$error = '';

if (!empty($token)) {
    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT * FROM `invitations` WHERE `token` = :token AND `accepted_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $invitation = $stmt->fetch();
}

if (!$invitation) {
    $error = 'This invitation link is invalid or has expired. Please contact your administrator for a new invitation.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="public/img/favicon.png">
    <title>Register - BravoCollab</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>BravoCollab</h1>
                <p>Create your account</p>
            </div>
            <?php if ($error): ?>
                <div class="form-error" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
                <p style="text-align:center;margin-top:16px;">
                    <a href="index.php?page=login" class="btn btn-primary">Go to Login</a>
                </p>
            <?php else: ?>
                <form id="registerForm" class="auth-form">
                    <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" value="<?php echo htmlspecialchars($invitation['email']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label for="display_name">Display Name</label>
                        <input type="text" id="display_name" name="display_name" required autofocus placeholder="Your name" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required placeholder="Min 8 chars, 1 uppercase, 1 number" minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" required placeholder="Repeat password">
                    </div>
                    <div class="form-error" id="registerError" style="display:none;"></div>
                    <button type="submit" class="btn btn-primary btn-block" id="registerBtn">Create Account</button>
                </form>
                <p style="text-align:center;margin-top:16px;font-size:14px;color:#6b778c;">
                    Already have an account? <a href="index.php?page=login">Sign in</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($invitation): ?>
    <script>
        document.getElementById('registerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('registerBtn');
            const errorEl = document.getElementById('registerError');
            errorEl.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Creating account...';

            try {
                const res = await fetch('api.php?action=auth.register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        token: document.getElementById('token').value,
                        display_name: document.getElementById('display_name').value,
                        password: document.getElementById('password').value,
                        password_confirm: document.getElementById('password_confirm').value,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    window.location.href = 'index.php?page=dashboard';
                } else {
                    errorEl.textContent = data.error || 'Registration failed';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Connection error. Please try again.';
                errorEl.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Create Account';
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
