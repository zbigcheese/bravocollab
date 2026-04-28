<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo CSRF::metaTag(); ?>
    <meta name="user-id" content="<?php echo (int) (Auth::userId() ?? 0); ?>">
    <link rel="icon" type="image/png" href="public/img/favicon.png">
    <title>BravoCollab</title>
    <link rel="stylesheet" href="public/css/app.css">
    <link rel="stylesheet" href="public/css/board.css">
    <link rel="stylesheet" href="public/css/modal.css">
</head>
<?php
$navUser   = Auth::currentUser() ?: [];
$userEmail = $navUser['email'] ?? '';
$userRole  = $navUser['role']  ?? 'member';
$roleLabel = $userRole === 'admin' ? 'Administrator' : 'Member';

// Calendar-sync status — drives the navbar icon's active/inactive state and
// the dropdown that opens on click. Server-rendered so first paint is correct
// without an extra fetch.
require_once __DIR__ . '/../../core/GoogleCalendar.php';
$_gcalConfigured = GoogleCalendar::isConfigured();
$_gcalConnected  = $_gcalConfigured && Auth::isLoggedIn() && GoogleCalendar::isConnected(Auth::userId());
?>
<body>
    <nav class="navbar">
        <div class="navbar-left">
            <a href="index.php?page=dashboard" class="navbar-brand">
                <img src="public/img/logo_white.png" alt="" class="navbar-brand-logo">
                <span>BravoCollab</span>
            </a>
        </div>
        <div class="navbar-right">
            <?php if ($_gcalConfigured): ?>
            <div class="calendar-menu<?php echo $_gcalConnected ? ' is-connected' : ''; ?>" id="calendarMenu">
                <button type="button" class="calendar-menu-trigger" id="calendarMenuTrigger"
                        aria-haspopup="true" aria-expanded="false"
                        title="<?php echo $_gcalConnected ? 'Google Calendar connected' : 'Google Calendar — not connected'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8"  y1="2" x2="8"  y2="6"/>
                        <line x1="3"  y1="10" x2="21" y2="10"/>
                    </svg>
                </button>
                <div class="calendar-menu-dropdown" id="calendarMenuDropdown" role="menu">
                    <div class="calendar-menu-status">
                        <span class="calendar-menu-dot"></span>
                        <span><?php echo $_gcalConnected ? 'Connected to Google Calendar' : 'Not connected'; ?></span>
                    </div>
                    <?php if ($_gcalConnected): ?>
                        <button type="button" class="calendar-menu-item" id="calMenuSyncNow">Sync now</button>
                        <button type="button" class="calendar-menu-item" id="calMenuDisconnect">Disconnect</button>
                    <?php else: ?>
                        <a href="index.php?page=google_connect" class="calendar-menu-item primary">Connect Google Calendar</a>
                    <?php endif; ?>
                    <a href="index.php?page=settings_calendar" class="calendar-menu-item subtle">Open settings…</a>
                </div>
            </div>
            <?php endif; ?>
            <div class="user-menu" id="userMenu">
                <button type="button" class="user-menu-trigger" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false">
                    <svg class="user-menu-avatar" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="user-name"><?php echo htmlspecialchars(Auth::userName()); ?></span>
                    <svg class="user-menu-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="user-menu-dropdown" id="userMenuDropdown" role="menu">
                    <div class="user-menu-identity">
                        <div class="user-menu-name"><?php echo htmlspecialchars(Auth::userName()); ?></div>
                        <div class="user-menu-email" title="<?php echo htmlspecialchars($userEmail); ?>"><?php echo htmlspecialchars($userEmail); ?></div>
                        <span class="user-menu-role user-menu-role-<?php echo htmlspecialchars($userRole); ?>"><?php echo $roleLabel; ?></span>
                    </div>
                    <?php if ($_gcalConfigured): ?>
                    <div class="user-menu-mobile-cal" role="group" aria-label="Calendar sync">
                        <div class="user-menu-mobile-cal-header">
                            <span class="calendar-menu-dot <?php echo $_gcalConnected ? 'is-on' : ''; ?>"></span>
                            <?php echo $_gcalConnected ? 'Calendar connected' : 'Calendar not connected'; ?>
                        </div>
                        <?php if ($_gcalConnected): ?>
                            <button type="button" class="user-menu-link" id="calMenuSyncNowMobile">Sync calendar now</button>
                            <button type="button" class="user-menu-link" id="calMenuDisconnectMobile">Disconnect calendar</button>
                        <?php else: ?>
                            <a href="index.php?page=google_connect" class="user-menu-link">Connect Google Calendar</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <a href="index.php?page=settings_preferences" class="user-menu-link">Settings</a>
                    <?php if (Auth::isAdmin()): ?>
                    <a href="index.php?page=admin_users" class="user-menu-link">Manage users</a>
                    <?php endif; ?>
                    <button type="button" class="user-menu-logout" id="logoutBtn">Sign out</button>
                </div>
            </div>
            <div class="notification-bell" id="notificationBell">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span class="notification-badge" id="notificationBadge" style="display:none;">0</span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h3>Notifications</h3>
                        <button id="markAllRead" class="btn-text">Mark all read</button>
                    </div>
                    <div class="notification-list" id="notificationList">
                        <p class="notification-empty">No notifications</p>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <main class="main-content">
