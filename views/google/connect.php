<?php
require_once __DIR__ . '/../../core/GoogleCalendar.php';

if (!Auth::isLoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}
if (!GoogleCalendar::isConfigured()) {
    header('Location: index.php?page=settings_calendar&error=not_configured');
    exit;
}

header('Location: ' . GoogleCalendar::authorizationUrl(Auth::userId()));
exit;
