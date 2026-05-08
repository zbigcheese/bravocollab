<?php
/**
 * BravoCollab Page Router
 * Serves HTML pages: index.php?page=dashboard, index.php?page=board&id=5, etc.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/CSRF.php';
require_once __DIR__ . '/core/Validator.php';
require_once __DIR__ . '/core/Helpers.php';

Auth::init();

$page = $_GET['page'] ?? '';

// Public pages (no auth required)
$publicPages = ['login', 'register', 'forgot_password', 'reset_password'];

// Validate a `next` URL: must be a relative `index.php?...` path
// (prevents open-redirect to external domains).
$safeNext = static function (?string $candidate): ?string {
    if (!$candidate) return null;
    if (preg_match('/^index\.php\?[A-Za-z0-9_=&\-.%]+$/', $candidate)) {
        return $candidate;
    }
    return null;
};

// Redirect to login if not authenticated — preserve where they were headed.
if (!Auth::isLoggedIn() && !in_array($page, $publicPages, true)) {
    $loginUrl = 'index.php?page=login';
    if (!empty($_GET)) {
        $next = 'index.php?' . http_build_query($_GET);
        if ($safeNext($next)) {
            $loginUrl .= '&next=' . urlencode($next);
        }
    }
    header('Location: ' . $loginUrl);
    exit;
}

// Already logged in and visiting login — honor `next` so email links
// still work for users who happen to be signed in already.
if (Auth::isLoggedIn() && ($page === 'login' || $page === '')) {
    $next = $safeNext($_GET['next'] ?? null);
    header('Location: ' . ($next ?: 'index.php?page=dashboard'));
    exit;
}

// Self-heal: every authenticated page load makes sure the current user has
// their personal board AND that the user_preferences table exists (queries
// in CommentController / Mailer / GoogleCalendar all reference it).
// Idempotent and wrapped so a failure here can never block the page.
if (Auth::isLoggedIn()) {
    try {
        require_once __DIR__ . '/core/Model.php';
        require_once __DIR__ . '/models/Board.php';
        (new Board())->ensurePersonalBoard(Auth::userId());
    } catch (Throwable $e) {
        error_log('ensurePersonalBoard failed: ' . $e->getMessage());
    }
    try {
        require_once __DIR__ . '/core/UserPreferences.php';
        UserPreferences::ensureSchema();
    } catch (Throwable $e) {
        error_log('UserPreferences ensureSchema failed: ' . $e->getMessage());
    }
}

// Page routing
$pageMap = [
    'login'             => 'auth/login.php',
    'register'          => 'auth/register.php',
    'forgot_password'   => 'auth/forgot_password.php',
    'reset_password'    => 'auth/reset_password.php',
    'dashboard'         => 'dashboard/index.php',
    'board'             => 'board/view.php',
    'admin_users'         => 'admin/users.php',
    'settings_preferences' => 'settings/preferences.php',
    'settings_calendar'   => 'settings/calendar.php',
    'whats_next'          => 'whats_next/index.php',
    'google_connect'    => 'google/connect.php',
    'google_callback'   => 'google/callback.php',
];

if (!isset($pageMap[$page])) {
    $page = Auth::isLoggedIn() ? 'dashboard' : 'login';
}

$viewFile = __DIR__ . '/views/' . $pageMap[$page];

// Pages that perform a server-side redirect (HTTP 302) and must NOT have
// the HTML layout flushed first — once any output is emitted, header() calls
// silently fail.
$layoutLessPages = ['google_connect', 'google_callback'];

// For authenticated pages, wrap in layout
if (in_array($page, $publicPages, true) || in_array($page, $layoutLessPages, true)) {
    require $viewFile;
} else {
    require __DIR__ . '/views/layout/header.php';
    require $viewFile;
    require __DIR__ . '/views/layout/footer.php';
}
