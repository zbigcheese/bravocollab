<?php
require_once __DIR__ . '/../../core/GoogleCalendar.php';

if (!Auth::isLoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}

// $_REQUEST covers both response_mode=query (default; params arrive via GET)
// and response_mode=form_post (params arrive via POST body). We use form_post
// to bypass host WAFs that reject "https://" in query values.
$code  = $_REQUEST['code']  ?? '';
$state = $_REQUEST['state'] ?? '';
$error = $_REQUEST['error'] ?? '';

if ($error) {
    header('Location: index.php?page=settings_calendar&error=' . urlencode($error));
    exit;
}
if (!$code || !$state) {
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
