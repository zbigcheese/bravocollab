<?php
$token = $_GET['token'] ?? '';
$valid = false;

if (!empty($token)) {
    $db = Database::get();
    try {
        $stmt = $db->prepare(
            'SELECT 1 FROM `password_resets` WHERE `token` = :token AND `used_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $valid = (bool) $stmt->fetch();
    } catch (PDOException $e) {
        $valid = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="public/img/favicon.png">
    <title>Reset Password - BravoCollab</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>BravoCollab</h1>
                <p>Choose a new password</p>
            </div>
            <?php if (!$valid): ?>
                <div class="form-error" style="display:block;">This reset link is invalid or has expired.</div>
                <p style="text-align:center;margin-top:16px;">
                    <a href="index.php?page=forgot_password" class="btn btn-primary">Request New Link</a>
                </p>
            <?php else: ?>
                <div id="resetFormContainer">
                    <form id="resetForm" class="auth-form">
                        <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" required autofocus placeholder="Min 8 chars, 1 uppercase, 1 number" minlength="8">
                        </div>
                        <div class="form-group">
                            <label for="password_confirm">Confirm Password</label>
                            <input type="password" id="password_confirm" name="password_confirm" required placeholder="Repeat new password">
                        </div>
                        <div class="form-error" id="resetError" style="display:none;"></div>
                        <button type="submit" class="btn btn-primary btn-block" id="resetBtn">Reset Password</button>
                    </form>
                </div>
                <div id="resetSuccess" style="display:none;">
                    <div style="background:#E3FCEF;color:#006644;padding:16px;border-radius:4px;text-align:center;margin-bottom:16px;">
                        <p style="font-weight:600;">Password reset successfully!</p>
                    </div>
                    <p style="text-align:center;">
                        <a href="index.php?page=login" class="btn btn-primary">Sign In</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($valid): ?>
    <script>
        document.getElementById('resetForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('resetBtn');
            const errorEl = document.getElementById('resetError');
            errorEl.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Resetting...';

            try {
                const res = await fetch('api.php?action=auth.reset_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        token: document.getElementById('token').value,
                        password: document.getElementById('password').value,
                        password_confirm: document.getElementById('password_confirm').value,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    document.getElementById('resetFormContainer').style.display = 'none';
                    document.getElementById('resetSuccess').style.display = 'block';
                } else {
                    errorEl.textContent = data.error || 'Something went wrong';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Connection error. Please try again.';
                errorEl.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Reset Password';
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
