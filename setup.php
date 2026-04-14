<?php
/**
 * BravoCollab - Setup Script
 * Run this once after importing schema.sql to create the initial admin user.
 * DELETE THIS FILE after setup is complete.
 *
 * Usage:
 *   Visit: https://yourdomain.com/setup.php
 *   Or CLI: php setup.php
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

$isCli = php_sapi_name() === 'cli';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isCli) {
    $email = $isCli ? ($argv[1] ?? 'admin@bravo.org') : trim($_POST['email'] ?? '');
    $password = $isCli ? ($argv[2] ?? '') : ($_POST['password'] ?? '');
    $name = $isCli ? ($argv[3] ?? 'Admin') : trim($_POST['display_name'] ?? 'Admin');

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
        if ($isCli) {
            echo "Usage: php setup.php <email> <password> [display_name]\n";
            exit(1);
        }
    } else {
        try {
            $db = Database::get();

            // Check if any users exist
            $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                $error = 'Setup already completed. Users exist in the database. Delete this file.';
                if ($isCli) { echo $error . "\n"; exit(1); }
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare(
                    'INSERT INTO users (email, password_hash, display_name, role, is_active)
                     VALUES (:email, :hash, :name, :role, 1)'
                )->execute([
                    'email' => $email,
                    'hash'  => $hash,
                    'name'  => $name,
                    'role'  => ROLE_ADMIN,
                ]);

                $message = "Admin user created successfully!\nEmail: {$email}\n\nPlease DELETE this setup.php file now.";
                if ($isCli) { echo $message . "\n"; exit(0); }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            if ($isCli) { echo $error . "\n"; exit(1); }
        }
    }
}

if (!$isCli):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BravoCollab Setup</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>BravoCollab</h1>
                <p>Initial Setup</p>
            </div>

            <?php if ($message): ?>
                <div style="background:#E3FCEF;color:#006644;padding:12px;border-radius:4px;margin-bottom:16px;">
                    <?php echo nl2br(htmlspecialchars($message)); ?>
                </div>
                <p style="text-align:center;"><a href="index.php?page=login" class="btn btn-primary">Go to Login</a></p>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="form-error" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" class="auth-form">
                    <div class="form-group">
                        <label>Admin Email</label>
                        <input type="email" name="email" value="admin@bravo.org" required>
                    </div>
                    <div class="form-group">
                        <label>Admin Password</label>
                        <input type="password" name="password" required placeholder="Min 8 characters">
                    </div>
                    <div class="form-group">
                        <label>Display Name</label>
                        <input type="text" name="display_name" value="Admin" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Create Admin Account</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php endif; ?>
