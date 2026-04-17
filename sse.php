<?php
/**
 * BravoCollab - Server-Sent Events Endpoint
 * Usage: sse.php?board_id=X
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';

Auth::init();

// Release session lock immediately so other requests aren't blocked
$userId = Auth::userId();
$isLoggedIn = Auth::isLoggedIn();
session_write_close();

if (!$isLoggedIn) {
    http_response_code(401);
    exit('Unauthorized');
}

$boardId = (int) ($_GET['board_id'] ?? 0);
if (!$boardId) {
    http_response_code(400);
    exit('Board ID required');
}

// Verify board access
$db = Database::get();
$stmt = $db->prepare(
    'SELECT 1 FROM board_members WHERE board_id = :bid AND user_id = :uid
     UNION SELECT 1 FROM users WHERE id = :uid2 AND role = "admin"'
);
$stmt->execute(['bid' => $boardId, 'uid' => $userId, 'uid2' => $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    exit('Access denied');
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 0);

// Set long timeout (5 minutes, client will reconnect)
set_time_limit(300);
ignore_user_abort(false);

// Get last event ID for reconnection
$lastEventId = null;
if (isset($_SERVER['HTTP_LAST_EVENT_ID']) && $_SERVER['HTTP_LAST_EVENT_ID'] !== '') {
    $lastEventId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (isset($_GET['last_event_id']) && $_GET['last_event_id'] !== '') {
    $lastEventId = (int) $_GET['last_event_id'];
}

// Fresh connect: skip history — boards.get already returned current state,
// so replaying past events (card_created for moved cards, etc.) would cause drift
if ($lastEventId === null) {
    $stmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM sse_events WHERE board_id = :bid');
    $stmt->execute(['bid' => $boardId]);
    $lastEventId = (int) $stmt->fetchColumn();
}

// Send initial connection event
echo "event: connected\ndata: {\"status\":\"ok\"}\n\n";
if (ob_get_level()) ob_flush();
flush();

$lastNotifCheck = time();

// Event loop
while (!connection_aborted()) {
    // 1. Check for board SSE events
    $stmt = $db->prepare(
        'SELECT * FROM sse_events WHERE board_id = :bid AND id > :last_id ORDER BY id ASC LIMIT 50'
    );
    $stmt->execute(['bid' => $boardId, 'last_id' => $lastEventId]);
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        $lastEventId = (int) $event['id'];
        echo "id: {$lastEventId}\n";
        echo "event: {$event['event_type']}\n";
        echo "data: {$event['payload']}\n\n";
    }

    // 2. Check for user notifications (every 5 seconds)
    if (time() - $lastNotifCheck >= 5) {
        $lastNotifCheck = time();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute(['uid' => $userId]);
        $count = (int) $stmt->fetchColumn();

        echo "event: notification_count\n";
        echo "data: {\"count\":{$count}}\n\n";
    }

    if (ob_get_level()) ob_flush();
    flush();

    // Sleep 2 seconds between polls
    sleep(2);
}
