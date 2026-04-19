<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo CSRF::metaTag(); ?>
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
