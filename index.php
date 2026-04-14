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

Auth::init();

$page = $_GET['page'] ?? '';

// Public pages (no auth required)
$publicPages = ['login', 'register', 'forgot_password', 'reset_password'];

// Redirect to login if not authenticated
if (!Auth::isLoggedIn() && !in_array($page, $publicPages, true)) {
    header('Location: index.php?page=login');
    exit;
}

// Redirect to dashboard if logged in and visiting login
if (Auth::isLoggedIn() && ($page === 'login' || $page === '')) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Page routing
$pageMap = [
    'login'           => 'auth/login.php',
    'register'        => 'auth/register.php',
    'forgot_password' => 'auth/forgot_password.php',
    'reset_password'  => 'auth/reset_password.php',
    'dashboard'       => 'dashboard/index.php',
    'board'           => 'board/view.php',
    'admin_users'     => 'admin/users.php',
];

if (!isset($pageMap[$page])) {
    $page = Auth::isLoggedIn() ? 'dashboard' : 'login';
}

$viewFile = __DIR__ . '/views/' . $pageMap[$page];

// For authenticated pages, wrap in layout
if (in_array($page, $publicPages, true)) {
    require $viewFile;
} else {
    require __DIR__ . '/views/layout/header.php';
    require $viewFile;
    require __DIR__ . '/views/layout/footer.php';
}
