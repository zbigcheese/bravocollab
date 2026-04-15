<?php
/**
 * BravoCollab - Cron Job
 * Run every 15 minutes: */15 * * * * /usr/bin/php /path/to/cron.php
 *
 * Tasks:
 * 1. Clean up old SSE events (older than 1 hour)
 * 2. Clean up expired invitations
 * 3. Clean up old login attempts
 * 4. Send due-date reminders (due within 24 hours)
 */

// Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

$db = Database::get();

// 1. Clean up old SSE events (>10 min old — supersedable events are already pruned at insert time)
$deleted = $db->exec("DELETE FROM sse_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
echo "Cleaned up {$deleted} old SSE events.\n";

// 1b. Hard cap: keep max 1000 events per board to prevent unbounded growth
$boards = $db->query("SELECT DISTINCT board_id FROM sse_events")->fetchAll(PDO::FETCH_COLUMN);
foreach ($boards as $bid) {
    $db->exec("DELETE FROM sse_events WHERE board_id = {$bid} AND id NOT IN (SELECT id FROM (SELECT id FROM sse_events WHERE board_id = {$bid} ORDER BY id DESC LIMIT 1000) AS keep)");
}

// 1c. Clean up old password resets
$deleted = $db->exec("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
echo "Cleaned up {$deleted} old password resets.\n";

// 2. Clean up expired invitations (older than 30 days past expiry)
$deleted = $db->exec("DELETE FROM invitations WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
echo "Cleaned up {$deleted} expired invitations.\n";

// 3. Clean up old login attempts (older than 1 hour)
$deleted = $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
echo "Cleaned up {$deleted} old login attempts.\n";

// 4. Due-date reminders - cards due within 24 hours that haven't been notified yet
$stmt = $db->query(
    "SELECT c.id, c.title, c.due_date, l.board_id
     FROM cards c
     JOIN lists l ON c.list_id = l.id
     WHERE c.due_date IS NOT NULL
       AND c.due_complete = 0
       AND c.is_archived = 0
       AND c.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
       AND c.id NOT IN (
           SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.card_id'))
           FROM notifications
           WHERE type = 'due_soon'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
       )"
);
$dueSoonCards = $stmt->fetchAll();

$notifStmt = $db->prepare(
    "INSERT INTO notifications (user_id, type, data) VALUES (:uid, :type, :data)"
);

$count = 0;
foreach ($dueSoonCards as $card) {
    // Notify all assignees
    $assignees = $db->prepare('SELECT user_id FROM card_assignments WHERE card_id = :cid');
    $assignees->execute(['cid' => $card['id']]);

    foreach ($assignees->fetchAll() as $assignee) {
        $notifStmt->execute([
            'uid'  => $assignee['user_id'],
            'type' => NOTIF_DUE_SOON,
            'data' => json_encode([
                'board_id'   => $card['board_id'],
                'card_id'    => $card['id'],
                'card_title' => $card['title'],
                'due_date'   => $card['due_date'],
            ]),
        ]);
        $count++;
    }
}
echo "Sent {$count} due-date reminder notifications.\n";

echo "Cron job complete.\n";
