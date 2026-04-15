<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="public/img/favicon.png">
    <title>Login - BravoCollab</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>BravoCollab</h1>
                <p>Sign in to your account</p>
            </div>
            <form id="loginForm" class="auth-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autofocus placeholder="your@email.com">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Your password">
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;margin-bottom:12px;">
                    <input type="checkbox" id="remember" style="width:16px;height:16px;accent-color:var(--color-primary);">
                    Keep me signed in
                </label>
                <div class="form-error" id="loginError" style="display:none;"></div>
                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">Sign In</button>
            </form>
            <p style="text-align:center;margin-top:16px;font-size:13px;">
                <a href="index.php?page=forgot_password" style="color:var(--color-text-light);">Forgot your password?</a>
            </p>
        </div>
    </div>
    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const errorEl = document.getElementById('loginError');
            errorEl.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Signing in...';

            try {
                const res = await fetch('api.php?action=auth.login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: document.getElementById('email').value,
                        password: document.getElementById('password').value,
                        remember: document.getElementById('remember').checked,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    window.location.href = 'index.php?page=dashboard';
                } else {
                    errorEl.textContent = data.error || 'Login failed';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Connection error. Please try again.';
                errorEl.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Sign In';
            }
        });
    </script>
</body>
</html>
