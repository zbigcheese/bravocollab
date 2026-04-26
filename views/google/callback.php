<?php
require_once __DIR__ . '/../../core/GoogleCalendar.php';

if (!Auth::isLoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

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
