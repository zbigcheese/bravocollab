<?php
// BravoCollab - Cron Job
// Run every 15 minutes via cPanel: "/15 * * * *" /usr/bin/php /path/to/cron.php
// (the crontab "*/15" notation is omitted from this comment because
//  PHP's block-comment terminator "*/" would close the comment early.)
//
// Tasks:
//   1. Clean up old SSE events (older than 10 minutes)
//   2. Clean up expired invitations
//   3. Clean up old login attempts
//   4. Send due-date reminders (due within 24 hours)
//   5. Send notification-digest emails for unread notifications > 1 hour old
//   6. Log this run and prune runs older than 10 days

// Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Mailer.php';

$db = Database::get();

// Self-heal: ensure the user_preferences table exists. The notification /
// digest / recap queries below all reference it, so the cron must be safe
// to run on a deployment that hasn't applied the schema migration yet.
require_once __DIR__ . '/core/UserPreferences.php';
UserPreferences::ensureSchema();

// Self-heal: ensure the whats_next_sent table exists for the daily 8am CET
// digest. Idempotent; lets fresh `git pull` deployments run without a manual
// schema migration step.
$db->exec(
    "CREATE TABLE IF NOT EXISTS `whats_next_sent` (
        `user_id`   INT UNSIGNED NOT NULL,
        `sent_date` DATE NOT NULL,
        `sent_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`, `sent_date`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Log the start of this cron run; status will be updated at the end.
$db->exec("INSERT INTO cron_runs (started_at, status) VALUES (NOW(), 'running')");
$cronRunId = (int) $db->lastInsertId();

// Buffer output so we can both print it for the operator AND store it in the log.
ob_start();
$cronStatus = 'success';
try {

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

// 3b. Clean up expired remember-me tokens
$deleted = $db->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
echo "Cleaned up {$deleted} expired remember tokens.\n";

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
    // Notify assignees + watchers + opted-in coordinators (union).
    // Distinct placeholder names — emulated PDO prepares disallow reusing the same name.
    $recipients = $db->prepare(
        'SELECT user_id FROM card_assignments WHERE card_id = :cid_a
         UNION
         SELECT user_id FROM card_watchers WHERE card_id = :cid_w
         UNION
         SELECT c.coordinator_id AS user_id
         FROM cards c
         LEFT JOIN user_preferences up ON up.user_id = c.coordinator_id
         WHERE c.id = :cid_c
           AND c.coordinator_id IS NOT NULL
           AND COALESCE(up.notify_coordinator_cards, 0) = 1'
    );
    $recipients->execute(['cid_a' => $card['id'], 'cid_w' => $card['id'], 'cid_c' => $card['id']]);

    foreach ($recipients->fetchAll() as $r) {
        $notifStmt->execute([
            'uid'  => $r['user_id'],
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

// 5. Email digest for users whose oldest unread, not-yet-emailed notification is >= 1 hour old.
//    When a user qualifies, we send ALL currently-unread, not-yet-emailed notifications in one email.
//    Users who turned off email_notifications in their preferences are excluded — their
//    notifications still pile up in-app, just not in email form.
$digestUsers = $db->query(
    "SELECT n.user_id
     FROM notifications n
     LEFT JOIN user_preferences up ON up.user_id = n.user_id
     WHERE n.is_read = 0 AND n.emailed_at IS NULL
       AND COALESCE(up.email_notifications, 1) = 1
     GROUP BY n.user_id
     HAVING MIN(n.created_at) <= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
)->fetchAll(PDO::FETCH_COLUMN);

$digestSent = 0;
foreach ($digestUsers as $uid) {
    $userStmt = $db->prepare('SELECT email, display_name FROM users WHERE id = :id AND is_active = 1');
    $userStmt->execute(['id' => $uid]);
    $user = $userStmt->fetch();
    if (!$user) continue;

    $notifStmt = $db->prepare(
        'SELECT id, type, data, created_at
         FROM notifications
         WHERE user_id = :uid AND is_read = 0 AND emailed_at IS NULL
         ORDER BY created_at ASC'
    );
    $notifStmt->execute(['uid' => $uid]);
    $notifs = $notifStmt->fetchAll();
    if (empty($notifs)) continue;

    if (Mailer::sendNotificationDigest($user['email'], $user['display_name'], $notifs)) {
        $ids = array_column($notifs, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE notifications SET emailed_at = NOW() WHERE id IN ($ph)")
           ->execute($ids);
        $digestSent++;
    }
}
echo "Sent {$digestSent} notification digest emails.\n";

// 6. Daily "What's next" overview at 8am CET, one per user per day.
//    Triggers only when the user has at least one assignment (card or task)
//    due today / tomorrow / day-after-tomorrow. Email body covers an 8-day
//    window: today + next 7 days. Cron fires every 15min so during the
//    08:00–08:59 CET hour we get up to 4 retry attempts; INSERT IGNORE on
//    (user_id, sent_date) prevents duplicate sends.
$cetTz   = new DateTimeZone('Europe/Belgrade');
$cetNow  = new DateTime('now', $cetTz);
$cetHour = (int) $cetNow->format('G');
$cetDate = $cetNow->format('Y-m-d');
$wnSent  = 0;

if ($cetHour === 8) {
    // Today / tomorrow / day after — anchors for the trigger guard.
    $nearTermDates = [];
    for ($i = 0; $i < 3; $i++) {
        $nearTermDates[] = (clone $cetNow)->setTime(0, 0, 0)->modify("+{$i} day")->format('Y-m-d');
    }

    // Skip users who turned off the daily recap. COALESCE keeps users
    // without a preferences row on the default (on).
    $users = $db->query(
        'SELECT u.id, u.email, u.display_name
         FROM users u
         LEFT JOIN user_preferences up ON up.user_id = u.id
         WHERE u.is_active = 1
           AND COALESCE(up.daily_recap_email, 1) = 1'
    )->fetchAll();

    $alreadySent = $db->prepare(
        'SELECT 1 FROM whats_next_sent WHERE user_id = :uid AND sent_date = :sd'
    );
    $markSent = $db->prepare(
        'INSERT IGNORE INTO whats_next_sent (user_id, sent_date) VALUES (:uid, :sd)'
    );

    foreach ($users as $user) {
        $uid = (int) $user['id'];

        $alreadySent->execute(['uid' => $uid, 'sd' => $cetDate]);
        if ($alreadySent->fetch()) continue;

        $sections = Mailer::buildWhatsNextSectionsForUser($db, $uid, $cetNow);
        if (empty($sections)) continue;

        // Trigger guard: must have something within today / tomorrow / day-after.
        // Section labels start with the formatted date; we instead check the
        // underlying card/item dates against the near-term anchor list.
        $hasNearTerm = false;
        foreach ($sections as $sec) {
            foreach ($sec['cards'] as $c) {
                if (in_array(substr($c['due_date'], 0, 10), $nearTermDates, true)) {
                    $hasNearTerm = true; break 2;
                }
            }
            foreach ($sec['items'] as $it) {
                if (in_array($it['due_date'], $nearTermDates, true)) {
                    $hasNearTerm = true; break 2;
                }
            }
        }
        if (!$hasNearTerm) continue;

        if (Mailer::sendWhatsNext($user['email'], $user['display_name'], $sections)) {
            $markSent->execute(['uid' => $uid, 'sd' => $cetDate]);
            $wnSent++;
        }
    }
}
echo "Sent {$wnSent} 'what's next' digest emails.\n";

// 7. Google Calendar sync — push assigned cards/items with due dates to
//    each connected user's dedicated BravoCollab calendar. Cron-driven so
//    we never block a user-facing action on a Google API call. Failures
//    per-user are isolated; we log and move on.
require_once __DIR__ . '/core/GoogleCalendar.php';
GoogleCalendar::ensureSchema();
$gcalAccounts = $db->query('SELECT user_id FROM google_calendar_accounts')->fetchAll(PDO::FETCH_COLUMN);
$gcalSummary = ['users' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0];
foreach ($gcalAccounts as $gUid) {
    $gcalSummary['users']++;
    try {
        $r = GoogleCalendar::syncUser((int) $gUid);
        $gcalSummary['created']  += (int) ($r['created']  ?? 0);
        $gcalSummary['updated']  += (int) ($r['updated']  ?? 0);
        $gcalSummary['deleted']  += (int) ($r['deleted']  ?? 0);
    } catch (Throwable $e) {
        $gcalSummary['failed']++;
        echo "Google sync failed for user {$gUid}: " . $e->getMessage() . "\n";
    }
}
echo "Google Calendar: synced {$gcalSummary['users']} user(s) — "
   . "{$gcalSummary['created']} created, {$gcalSummary['updated']} updated, "
   . "{$gcalSummary['deleted']} removed, {$gcalSummary['failed']} failed.\n";

echo "Cron job complete.\n";

} catch (Throwable $e) {
    $cronStatus = 'failed';
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
}

$cronOutput = ob_get_clean();
echo $cronOutput; // preserve visibility for anyone watching CLI output

// Update the log row with outcome + captured output.
$stmt = $db->prepare(
    "UPDATE cron_runs SET finished_at = NOW(), status = :status, summary = :summary WHERE id = :id"
);
$stmt->execute([
    'status'  => $cronStatus,
    'summary' => mb_substr($cronOutput, 0, 65000),
    'id'      => $cronRunId,
]);

// Prune log entries older than 10 days so the table never grows unbounded.
$db->exec("DELETE FROM cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 10 DAY)");
