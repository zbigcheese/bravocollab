<?php

require_once __DIR__ . '/../config/database.php';

class Auth
{
    private const REMEMBER_DURATION = 30 * 24 * 3600; // 30 days

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $config = require __DIR__ . '/../config/config.php';
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly'  => true,
                'samesite'=> 'Strict',
            ]);
            session_start();

            // Check idle timeout (skip if "remember me" is active)
            if (isset($_SESSION['last_activity']) && empty($_SESSION['remember_me'])) {
                $elapsed = time() - $_SESSION['last_activity'];
                if ($elapsed > $config['session_lifetime']) {
                    self::logout();
                    return;
                }
            }
            $_SESSION['last_activity'] = time();

            // Extend cookie if remember me is active
            if (!empty($_SESSION['remember_me']) && !empty($_SESSION['user_id'])) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + self::REMEMBER_DURATION,
                    'path'     => $params['path'],
                    'secure'   => $params['secure'],
                    'httponly'  => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]);
                ini_set('session.gc_maxlifetime', self::REMEMBER_DURATION);
            }
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
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + self::REMEMBER_DURATION,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly'  => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            ini_set('session.gc_maxlifetime', self::REMEMBER_DURATION);
        }

        // Generate CSRF token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Update last login
        $db->prepare('UPDATE `users` SET `last_login_at` = NOW() WHERE `id` = :id')
           ->execute(['id' => $user['id']]);

        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
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
}
