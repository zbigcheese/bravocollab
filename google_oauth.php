<?php
/**
 * Standalone Google OAuth callback handler.
 *
 * Lives outside the index.php router so a <Files> directive in .htaccess
 * can disable ModSecurity for just this single endpoint. The host doesn't
 * permit <If> blocks in .htaccess, but <Files> is universally supported.
 *
 * Redirect URI registered with Google must be:
 *   https://collab.bravo.org.rs/google_oauth.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/CSRF.php';
require_once __DIR__ . '/core/GoogleCalendar.php';

Auth::init();

if (!Auth::isLoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error !== '') {
    header('Location: index.php?page=settings_calendar&error=' . urlencode($error));
    exit;
}
if ($code === '' || $state === '') {
    header('Location: index.php?page=settings_calendar&error=missing_code');
    exit;
}

try {
    GoogleCalendar::handleCallback(Auth::userId(), $code, $state);
    header('Location: index.php?page=settings_calendar&connected=1');
    exit;
} catch (Throwable $e) {
    error_log('Google callback failed: ' . $e->getMessage());
    header('Location: index.php?page=settings_calendar&error=' . urlencode($e->getMessage()));
    exit;
}
