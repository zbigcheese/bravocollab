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
<body>
    <nav class="navbar">
        <div class="navbar-left">
            <a href="index.php?page=dashboard" class="navbar-brand">
                <img src="public/img/favicon.png" alt="" class="navbar-brand-logo">
                <span>BravoCollab</span>
            </a>
        </div>
        <div class="navbar-right">
            <?php if (Auth::isAdmin()): ?>
            <a href="index.php?page=admin_users" class="nav-link">Users</a>
            <?php endif; ?>
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
            <div class="user-menu">
                <span class="user-name"><?php echo htmlspecialchars(Auth::userName()); ?></span>
                <button class="btn-text" id="logoutBtn">Logout</button>
            </div>
        </div>
    </nav>
    <main class="main-content">
