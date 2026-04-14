<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="public/img/favicon.png">
    <title>Forgot Password - BravoCollab</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>BravoCollab</h1>
                <p>Reset your password</p>
            </div>
            <div id="requestForm">
                <form id="forgotForm" class="auth-form">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required autofocus placeholder="your@email.com">
                    </div>
                    <div class="form-error" id="forgotError" style="display:none;"></div>
                    <button type="submit" class="btn btn-primary btn-block" id="forgotBtn">Send Reset Link</button>
                </form>
                <p style="text-align:center;margin-top:16px;font-size:13px;">
                    <a href="index.php?page=login" style="color:var(--color-text-light);">Back to sign in</a>
                </p>
            </div>
            <div id="successMessage" style="display:none;">
                <div style="background:#E3FCEF;color:#006644;padding:16px;border-radius:4px;text-align:center;margin-bottom:16px;">
                    <p style="font-weight:600;margin-bottom:4px;">Check your email</p>
                    <p style="font-size:13px;">If an account with that email exists, we've sent a password reset link.</p>
                </div>
                <p style="text-align:center;">
                    <a href="index.php?page=login" class="btn btn-primary">Back to Sign In</a>
                </p>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('forgotForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('forgotBtn');
            const errorEl = document.getElementById('forgotError');
            errorEl.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Sending...';

            try {
                const res = await fetch('api.php?action=auth.forgot_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: document.getElementById('email').value,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    document.getElementById('requestForm').style.display = 'none';
                    document.getElementById('successMessage').style.display = 'block';
                } else {
                    errorEl.textContent = data.error || 'Something went wrong';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Connection error. Please try again.';
                errorEl.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Send Reset Link';
            }
        });
    </script>
</body>
</html>
