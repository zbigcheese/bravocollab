<?php

require_once __DIR__ . '/../config/database.php';

class Auth
{
    private const REMEMBER_COOKIE    = 'bravo_remember';
    private const REMEMBER_DURATION  = 30 * 24 * 3600; // 30 days

    public static function init(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $config = require __DIR__ . '/../config/config.php';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // Idle timeout — skip while remember-me is active on this session.
        if (!empty($_SESSION['user_id'])
            && empty($_SESSION['remember_me'])
            && isset($_SESSION['last_activity'])
            && (time() - $_SESSION['last_activity']) > $config['session_lifetime']
        ) {
            // Wipe identity but keep the session container open so the
            // remember-me path below can re-establish auth in this request.
            $_SESSION = [];
        }

        // If we have no active user, try to restore via remember-me cookie.
        // This is what survives server-side session GC on shared hosting.
        if (empty($_SESSION['user_id'])) {
            self::attemptRememberLogin();
        }

        if (!empty($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
        }
    }

    public static function login(string $email, string $password, bool $remember = false): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM `users` WHERE `email` = :email AND `is_active` = 1 LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['display_name'];
        $_SESSION['last_activity'] = time();

        if ($remember) {
            $_SESSION['remember_me'] = true;
            self::issueRememberToken((int) $user['id']);
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $db->prepare('UPDATE `users` SET `last_login_at` = NOW() WHERE `id` = :id')
           ->execute(['id' => $user['id']]);

        return $user;
    }

    public static function logout(): void
    {
        // Invalidate the current remember-me token (if any) before tearing down the session.
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            if (preg_match('/^([a-f0-9]{32}):/', $_COOKIE[self::REMEMBER_COOKIE], $m)) {
                try {
                    Database::get()
                        ->prepare('DELETE FROM `remember_tokens` WHERE `selector` = :sel')
                        ->execute(['sel' => $m[1]]);
                } catch (Throwable $e) {
                    // Swallow — logout must not fail if DB is unavailable.
                }
            }
            self::clearRememberCookie();
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'] ?? '',
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['user_role'] ?? '') === ROLE_ADMIN;
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function userName(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    public static function currentUser(): ?array
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        $db = Database::get();
        $stmt = $db->prepare('SELECT `id`, `email`, `display_name`, `role`, `avatar_path` FROM `users` WHERE `id` = :id');
        $stmt->execute(['id' => self::userId()]);
        return $stmt->fetch() ?: null;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ------------------------------------------------------------
    // Remember-me token handling
    // ------------------------------------------------------------

    private static function issueRememberToken(int $userId): void
    {
        $selector     = bin2hex(random_bytes(16)); // 32 hex chars
        $validatorRaw = random_bytes(32);
        $validatorHex = bin2hex($validatorRaw);    // 64 hex chars
        $hash         = hash('sha256', $validatorRaw);

        Database::get()->prepare(
            'INSERT INTO `remember_tokens` (`user_id`, `selector`, `validator_hash`, `expires_at`)
             VALUES (:uid, :sel, :hash, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        )->execute(['uid' => $userId, 'sel' => $selector, 'hash' => $hash]);

        self::setRememberCookie($selector . ':' . $validatorHex, time() + self::REMEMBER_DURATION);
    }

    private static function attemptRememberLogin(): void
    {
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }
        if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/', $_COOKIE[self::REMEMBER_COOKIE], $m)) {
            self::clearRememberCookie();
            return;
        }
        $selector     = $m[1];
        $validatorHex = $m[2];

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT rt.id, rt.user_id, rt.validator_hash, u.role, u.display_name, u.is_active
             FROM `remember_tokens` rt
             JOIN `users` u ON u.id = rt.user_id
             WHERE rt.selector = :sel AND rt.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['sel' => $selector]);
        $row = $stmt->fetch();

        if (!$row || !$row['is_active']) {
            self::clearRememberCookie();
            return;
        }

        $computed = hash('sha256', hex2bin($validatorHex));
        if (!hash_equals($row['validator_hash'], $computed)) {
            // Selector matched but validator didn't — potential token theft.
            // Invalidate every remember token for this user.
            $db->prepare('DELETE FROM `remember_tokens` WHERE `user_id` = :uid')
               ->execute(['uid' => $row['user_id']]);
            self::clearRememberCookie();
            return;
        }

        // Establish a fresh authenticated session.
        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $row['user_id'];
        $_SESSION['user_role']     = $row['role'];
        $_SESSION['user_name']     = $row['display_name'];
        $_SESSION['remember_me']   = true;
        $_SESSION['last_activity'] = time();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Rotate the token (one-time use) so a stolen cookie stops working
        // as soon as the real user authenticates again.
        $db->prepare('DELETE FROM `remember_tokens` WHERE `id` = :id')
           ->execute(['id' => $row['id']]);
        self::issueRememberToken((int) $row['user_id']);
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax', // Lax so external-link navigations auto-auth.
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }
}
