<?php
/**
 * GoogleCalendar — service that handles the OAuth dance with Google,
 * persists tokens, and reconciles BravoCollab assignments to a dedicated
 * "BravoCollab" calendar in the user's Google account.
 *
 * Sync model is "cron-driven reconciliation": every cron run, for each
 * connected user, we figure out the desired set of events (assigned cards
 * with due_date + assigned checklist items with due_date), diff it against
 * what we've already pushed (google_calendar_events table), and bring the
 * Google calendar in line. Idempotent — same desired state always yields
 * the same Google state.
 *
 * Completion is reflected by updating the event's STATUS to "cancelled"
 * (Google strikes through cancelled events). Archive / unassign / delete
 * removes the event entirely.
 */

require_once __DIR__ . '/../config/database.php';

class GoogleCalendar
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    private const API_BASE    = 'https://www.googleapis.com/calendar/v3';
    private const SCOPE       = 'https://www.googleapis.com/auth/calendar';
    private const CAL_NAME    = 'BravoCollab';

    /** Self-heal: create our two tables if they don't exist yet. */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        $db = Database::get();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `google_calendar_accounts` (
                `user_id`       INT UNSIGNED PRIMARY KEY,
                `access_token`  TEXT NOT NULL,
                `refresh_token` TEXT NOT NULL,
                `expires_at`    DATETIME NOT NULL,
                `calendar_id`   VARCHAR(255) NOT NULL,
                `connected_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `google_calendar_events` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`         INT UNSIGNED NOT NULL,
                `entity_type`     ENUM('card', 'item') NOT NULL,
                `entity_id`       INT UNSIGNED NOT NULL,
                `google_event_id` VARCHAR(255) NOT NULL,
                `payload_hash`    CHAR(40) DEFAULT NULL,
                `synced_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_user_entity` (`user_id`, `entity_type`, `entity_id`),
                INDEX `idx_user` (`user_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $checked = true;
    }

    public static function isConfigured(): bool
    {
        $config = self::config();
        return !empty($config['google_client_id']) && !empty($config['google_client_secret']);
    }

    public static function isConnected(int $userId): bool
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare(
            'SELECT 1 FROM google_calendar_accounts WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        return (bool) $stmt->fetch();
    }

    public static function getAccount(int $userId): ?array
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare(
            'SELECT * FROM google_calendar_accounts WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Build the Google OAuth consent URL. State is HMAC-bound to the user
     * so the callback can verify the round-trip wasn't tampered with.
     */
    public static function authorizationUrl(int $userId): string
    {
        $config = self::config();
        $state = self::makeState($userId);
        $params = [
            'client_id'     => $config['google_client_id'],
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent', // always re-issue refresh_token
            'state'         => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange the OAuth code for tokens, then create our dedicated calendar
     * and store everything. Returns true on success.
     */
    public static function handleCallback(int $userId, string $code, string $state): bool
    {
        if (!self::verifyState($userId, $state)) {
            throw new RuntimeException('Invalid OAuth state');
        }

        self::ensureSchema();
        $config = self::config();

        $tokenRes = self::http('POST', self::TOKEN_URL, [
            'client_id'     => $config['google_client_id'],
            'client_secret' => $config['google_client_secret'],
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => self::redirectUri(),
        ], false);

        if (empty($tokenRes['access_token']) || empty($tokenRes['refresh_token'])) {
            throw new RuntimeException('Token exchange failed: ' . json_encode($tokenRes));
        }

        $accessToken  = $tokenRes['access_token'];
        $refreshToken = $tokenRes['refresh_token'];
        $expiresIn    = (int) ($tokenRes['expires_in'] ?? 3600);
        $expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn - 60);

        // Create the dedicated calendar so we never touch the user's primary.
        $calRes = self::apiCallRaw($accessToken, 'POST', '/calendars', [
            'summary' => self::CAL_NAME,
            'timeZone' => 'Europe/Belgrade',
            'description' => 'Cards and tasks assigned to you in BravoCollab.',
        ]);
        if (empty($calRes['id'])) {
            throw new RuntimeException('Calendar creation failed: ' . json_encode($calRes));
        }
        $calendarId = $calRes['id'];

        // Persist (REPLACE so a re-connect cleanly overwrites stale tokens).
        Database::get()->prepare(
            'REPLACE INTO google_calendar_accounts
                (user_id, access_token, refresh_token, expires_at, calendar_id)
             VALUES (:uid, :at, :rt, :exp, :cid)'
        )->execute([
            'uid' => $userId, 'at' => $accessToken, 'rt' => $refreshToken,
            'exp' => $expiresAt, 'cid' => $calendarId,
        ]);

        // Wipe any stale event-id mappings from a previous connection so the
        // first reconciliation starts fresh.
        Database::get()->prepare(
            'DELETE FROM google_calendar_events WHERE user_id = :uid'
        )->execute(['uid' => $userId]);

        // Initial bulk push so the user sees their data immediately rather
        // than waiting up to 15 min for the next cron pass.
        try { self::syncUser($userId); } catch (Throwable $e) { /* best-effort */ }

        return true;
    }

    /**
     * Disconnect: revoke at Google, delete the calendar (which removes all
     * events server-side), drop our local rows.
     */
    public static function disconnect(int $userId): bool
    {
        self::ensureSchema();
        $account = self::getAccount($userId);
        if (!$account) return true;

        // Try to delete the calendar (Google removes all events with it).
        try {
            $token = self::validAccessToken($userId);
            self::apiCallRaw(
                $token,
                'DELETE',
                '/calendars/' . rawurlencode($account['calendar_id'])
            );
        } catch (Throwable $e) {
            // Best-effort — keep going so local cleanup still happens.
        }

        // Revoke the token so the user sees the app removed in their Google
        // account permissions.
        try {
            self::http('POST', self::REVOKE_URL, ['token' => $account['refresh_token']], false);
        } catch (Throwable $e) { /* best-effort */ }

        $db = Database::get();
        $db->prepare('DELETE FROM google_calendar_events WHERE user_id = :uid')
           ->execute(['uid' => $userId]);
        $db->prepare('DELETE FROM google_calendar_accounts WHERE user_id = :uid')
           ->execute(['uid' => $userId]);
        return true;
    }

    /**
     * Reconcile a single user's Google calendar against the current set of
     * assigned cards and items with due dates. Idempotent.
     */
    public static function syncUser(int $userId): array
    {
        self::ensureSchema();
        $account = self::getAccount($userId);
        if (!$account) return ['skipped' => 'not connected'];

        $token = self::validAccessToken($userId);
        $calendarId = $account['calendar_id'];

        // Desired events (what SHOULD be in the calendar).
        $desired = self::desiredEntities($userId);

        // Existing mapped events.
        $existingStmt = Database::get()->prepare(
            'SELECT * FROM google_calendar_events WHERE user_id = :uid'
        );
        $existingStmt->execute(['uid' => $userId]);
        $existing = [];
        foreach ($existingStmt->fetchAll() as $row) {
            $key = $row['entity_type'] . ':' . (int) $row['entity_id'];
            $existing[$key] = $row;
        }

        $created = 0; $updated = 0; $deleted = 0; $unchanged = 0;

        // Push: create or update.
        foreach ($desired as $entity) {
            $key = $entity['entity_type'] . ':' . $entity['entity_id'];
            $hash = self::hashEntity($entity);
            $eventBody = self::entityToEventBody($entity);

            if (!isset($existing[$key])) {
                $res = self::apiCallRaw(
                    $token,
                    'POST',
                    '/calendars/' . rawurlencode($calendarId) . '/events',
                    $eventBody
                );
                if (!empty($res['id'])) {
                    Database::get()->prepare(
                        'INSERT INTO google_calendar_events
                            (user_id, entity_type, entity_id, google_event_id, payload_hash)
                         VALUES (:uid, :et, :eid, :geid, :hash)'
                    )->execute([
                        'uid' => $userId, 'et' => $entity['entity_type'],
                        'eid' => $entity['entity_id'], 'geid' => $res['id'], 'hash' => $hash,
                    ]);
                    $created++;
                }
            } elseif ($existing[$key]['payload_hash'] !== $hash) {
                $eventId = $existing[$key]['google_event_id'];
                $res = self::apiCallRaw(
                    $token,
                    'PATCH',
                    '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
                    $eventBody
                );
                if (!empty($res['id'])) {
                    Database::get()->prepare(
                        'UPDATE google_calendar_events
                         SET payload_hash = :hash WHERE id = :id'
                    )->execute(['hash' => $hash, 'id' => $existing[$key]['id']]);
                    $updated++;
                }
            } else {
                $unchanged++;
            }
            unset($existing[$key]);
        }

        // Anything left in $existing is no longer desired — delete.
        foreach ($existing as $row) {
            try {
                self::apiCallRaw(
                    $token,
                    'DELETE',
                    '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($row['google_event_id'])
                );
            } catch (Throwable $e) { /* probably already deleted on Google side */ }
            Database::get()->prepare('DELETE FROM google_calendar_events WHERE id = :id')
               ->execute(['id' => $row['id']]);
            $deleted++;
        }

        return compact('created', 'updated', 'deleted', 'unchanged');
    }

    /**
     * Real-time sync entry point. Reconciles a single card across every
     * user who currently holds, or recently held, an event for it. Called
     * from the card controllers right after the data mutation. Idempotent
     * and per-user-isolated — one user's failure can't break another's
     * sync.
     */
    public static function syncCardForAll(int $cardId): void
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare(
            'SELECT DISTINCT u.user_id FROM (
                SELECT ca.user_id FROM card_assignments ca WHERE ca.card_id = :cid_a
                UNION
                SELECT b.created_by AS user_id
                FROM cards c
                JOIN lists l ON c.list_id = l.id
                JOIN boards b ON b.id = l.board_id
                WHERE c.id = :cid_b AND b.is_personal = 1
                UNION
                SELECT user_id FROM google_calendar_events
                WHERE entity_type = "card" AND entity_id = :cid_e
             ) u
             JOIN google_calendar_accounts gca ON gca.user_id = u.user_id'
        );
        $stmt->execute(['cid_a' => $cardId, 'cid_b' => $cardId, 'cid_e' => $cardId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            try {
                self::syncEntityForUser((int) $uid, 'card', $cardId);
            } catch (Throwable $e) {
                error_log("Google sync card {$cardId} failed for user {$uid}: " . $e->getMessage());
            }
        }
    }

    /** Same shape as syncCardForAll but for a single checklist item. */
    public static function syncItemForAll(int $itemId): void
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare(
            'SELECT DISTINCT u.user_id FROM (
                SELECT ci.assigned_to AS user_id
                FROM checklist_items ci
                WHERE ci.id = :iid_a AND ci.assigned_to IS NOT NULL
                UNION
                SELECT b.created_by AS user_id
                FROM checklist_items ci
                JOIN checklists ch ON ci.checklist_id = ch.id
                JOIN cards c ON ch.card_id = c.id
                JOIN lists l ON c.list_id = l.id
                JOIN boards b ON b.id = l.board_id
                WHERE ci.id = :iid_b AND b.is_personal = 1
                UNION
                SELECT user_id FROM google_calendar_events
                WHERE entity_type = "item" AND entity_id = :iid_e
             ) u
             JOIN google_calendar_accounts gca ON gca.user_id = u.user_id'
        );
        $stmt->execute(['iid_a' => $itemId, 'iid_b' => $itemId, 'iid_e' => $itemId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            try {
                self::syncEntityForUser((int) $uid, 'item', $itemId);
            } catch (Throwable $e) {
                error_log("Google sync item {$itemId} failed for user {$uid}: " . $e->getMessage());
            }
        }
    }

    /**
     * Reconcile a single entity for a single user. Used by both the cron
     * pass (via syncUser) and the real-time hooks (via syncCardForAll /
     * syncItemForAll). Decides between create / patch / delete / no-op
     * based on the desired state vs. the existing event mapping.
     */
    public static function syncEntityForUser(int $userId, string $entityType, int $entityId): void
    {
        $account = self::getAccount($userId);
        if (!$account) return;

        $token = self::validAccessToken($userId);
        $calendarId = $account['calendar_id'];

        $desired = self::desiredEntityFor($userId, $entityType, $entityId);

        $stmt = Database::get()->prepare(
            'SELECT * FROM google_calendar_events
             WHERE user_id = :uid AND entity_type = :et AND entity_id = :eid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'et' => $entityType, 'eid' => $entityId]);
        $existing = $stmt->fetch();

        if ($desired === null) {
            // Should NOT be in calendar — delete event if mapped.
            if ($existing) {
                try {
                    self::apiCallRaw(
                        $token, 'DELETE',
                        '/calendars/' . rawurlencode($calendarId)
                            . '/events/' . rawurlencode($existing['google_event_id'])
                    );
                } catch (Throwable $e) { /* probably already gone */ }
                Database::get()->prepare('DELETE FROM google_calendar_events WHERE id = :id')
                   ->execute(['id' => $existing['id']]);
            }
            return;
        }

        $hash = self::hashEntity($desired);
        $body = self::entityToEventBody($desired);

        if (!$existing) {
            $res = self::apiCallRaw(
                $token, 'POST',
                '/calendars/' . rawurlencode($calendarId) . '/events',
                $body
            );
            if (!empty($res['id'])) {
                Database::get()->prepare(
                    'INSERT INTO google_calendar_events
                     (user_id, entity_type, entity_id, google_event_id, payload_hash)
                     VALUES (:uid, :et, :eid, :geid, :hash)'
                )->execute([
                    'uid' => $userId, 'et' => $entityType, 'eid' => $entityId,
                    'geid' => $res['id'], 'hash' => $hash,
                ]);
            }
            return;
        }

        if ($existing['payload_hash'] === $hash) return; // no-op

        $res = self::apiCallRaw(
            $token, 'PATCH',
            '/calendars/' . rawurlencode($calendarId)
                . '/events/' . rawurlencode($existing['google_event_id']),
            $body
        );
        if (!empty($res['id'])) {
            Database::get()->prepare(
                'UPDATE google_calendar_events SET payload_hash = :hash WHERE id = :id'
            )->execute(['hash' => $hash, 'id' => $existing['id']]);
        }
    }

    /**
     * Returns the desired-state row for a single (user, entity) pair, or
     * null if the entity should NOT be in the user's calendar (no due_date,
     * archived, unassigned, etc.).
     */
    private static function desiredEntityFor(int $userId, string $type, int $entityId): ?array
    {
        $db = Database::get();

        if ($type === 'card') {
            $stmt = $db->prepare(
                "SELECT c.id, c.title, c.description, c.due_date,
                        c.due_complete, c.is_archived, l.board_id, b.title AS board_title
                 FROM cards c
                 JOIN lists l ON c.list_id = l.id
                 JOIN boards b ON b.id = l.board_id
                 LEFT JOIN card_assignments ca ON ca.card_id = c.id AND ca.user_id = :uid_a
                 WHERE c.id = :cid
                   AND c.due_date IS NOT NULL
                   AND c.is_archived = 0
                   AND b.is_archived = 0
                   AND (ca.user_id = :uid_a2
                        OR (b.is_personal = 1 AND b.created_by = :uid_p))
                 LIMIT 1"
            );
            $stmt->execute([
                'cid' => $entityId, 'uid_a' => $userId,
                'uid_a2' => $userId, 'uid_p' => $userId,
            ]);
            $r = $stmt->fetch();
            if (!$r) return null;
            return [
                'entity_type' => 'card',
                'entity_id'   => (int) $r['id'],
                'title'       => $r['title'],
                'description' => $r['description'] ?? '',
                'due_date'    => $r['due_date'],
                'completed'   => (int) $r['due_complete'] === 1,
                'board_id'    => (int) $r['board_id'],
                'board_title' => $r['board_title'],
                'card_id'     => (int) $r['id'],
            ];
        }

        // item
        $stmt = $db->prepare(
            "SELECT ci.id, ci.content, ci.due_date, ci.is_checked,
                    ch.card_id, c.title AS card_title,
                    l.board_id, b.title AS board_title
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE ci.id = :iid
               AND ci.due_date IS NOT NULL
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND (ci.assigned_to = :uid_a
                    OR (b.is_personal = 1 AND b.created_by = :uid_p))
             LIMIT 1"
        );
        $stmt->execute(['iid' => $entityId, 'uid_a' => $userId, 'uid_p' => $userId]);
        $r = $stmt->fetch();
        if (!$r) return null;
        return [
            'entity_type' => 'item',
            'entity_id'   => (int) $r['id'],
            'title'       => $r['content'],
            'description' => '',
            'due_date'    => $r['due_date'],
            'completed'   => (int) $r['is_checked'] === 1,
            'board_id'    => (int) $r['board_id'],
            'board_title' => $r['board_title'],
            'card_id'     => (int) $r['card_id'],
            'card_title'  => $r['card_title'],
        ];
    }

    /**
     * Desired set of (entity_type, entity_id, due_date, completed, …) for
     * the user. Same filters as the daily digest plus personal-board cards.
     */
    private static function desiredEntities(int $userId): array
    {
        $db = Database::get();
        $out = [];

        // Cards: assigned OR on user's personal board, with a due_date.
        // Archived cards drop out (event removed from calendar). Completed
        // cards stay but get STATUS=cancelled.
        $cardStmt = $db->prepare(
            "SELECT DISTINCT c.id, c.title, c.description, c.due_date,
                    c.due_complete, c.is_archived, l.board_id, b.title AS board_title
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             LEFT JOIN card_assignments ca ON ca.card_id = c.id AND ca.user_id = :uid_a
             WHERE c.due_date IS NOT NULL
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND (ca.user_id = :uid_a2
                    OR (b.is_personal = 1 AND b.created_by = :uid_p))"
        );
        $cardStmt->execute(['uid_a' => $userId, 'uid_a2' => $userId, 'uid_p' => $userId]);
        foreach ($cardStmt->fetchAll() as $r) {
            $out[] = [
                'entity_type'  => 'card',
                'entity_id'    => (int) $r['id'],
                'title'        => $r['title'],
                'description'  => $r['description'] ?? '',
                'due_date'     => $r['due_date'],
                'completed'    => (int) $r['due_complete'] === 1,
                'board_id'     => (int) $r['board_id'],
                'board_title'  => $r['board_title'],
                'card_id'      => (int) $r['id'],
            ];
        }

        // Checklist items: assigned to user (or sitting on personal board).
        $itemStmt = $db->prepare(
            "SELECT DISTINCT ci.id, ci.content, ci.due_date, ci.is_checked,
                    ch.card_id, c.title AS card_title, c.is_archived AS card_archived,
                    l.board_id, b.title AS board_title
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE ci.due_date IS NOT NULL
               AND c.is_archived = 0
               AND b.is_archived = 0
               AND (ci.assigned_to = :uid_a
                    OR (b.is_personal = 1 AND b.created_by = :uid_p))"
        );
        $itemStmt->execute(['uid_a' => $userId, 'uid_p' => $userId]);
        foreach ($itemStmt->fetchAll() as $r) {
            $out[] = [
                'entity_type'  => 'item',
                'entity_id'    => (int) $r['id'],
                'title'        => $r['content'],
                'description'  => '',
                'due_date'     => $r['due_date'],
                'completed'    => (int) $r['is_checked'] === 1,
                'board_id'     => (int) $r['board_id'],
                'board_title'  => $r['board_title'],
                'card_id'      => (int) $r['card_id'],
                'card_title'   => $r['card_title'],
            ];
        }

        return $out;
    }

    private static function entityToEventBody(array $entity): array
    {
        $config  = self::config();
        $baseUrl = rtrim($config['base_url'], '/');
        $cardUrl = $baseUrl . '/index.php?page=board&id=' . (int) $entity['board_id']
                 . '&card=' . (int) $entity['card_id'];

        // All-day events. Google's "end" for all-day events is exclusive,
        // so for a 1-day event end = start + 1 day.
        $startDate = substr($entity['due_date'], 0, 10);
        $end = (new DateTime($startDate))->modify('+1 day')->format('Y-m-d');

        $title = $entity['title'];
        if ($entity['entity_type'] === 'item') {
            $title = '☑ ' . $title; // distinguishes tasks from cards visually
        }

        $description = '';
        if ($entity['entity_type'] === 'item') {
            $description .= "Checklist item on card: " . ($entity['card_title'] ?? '') . "\n";
        }
        $description .= "Board: " . $entity['board_title'] . "\n\n";
        if (!empty($entity['description'])) {
            $description .= $entity['description'] . "\n\n";
        }
        $description .= 'Open in BravoCollab: ' . $cardUrl;

        return [
            'summary'     => $title,
            'description' => $description,
            'start'       => ['date' => $startDate],
            'end'         => ['date' => $end],
            'status'      => $entity['completed'] ? 'cancelled' : 'confirmed',
            'source'      => ['title' => 'BravoCollab', 'url' => $cardUrl],
        ];
    }

    private static function hashEntity(array $entity): string
    {
        return sha1(json_encode([
            $entity['title'], $entity['description'] ?? '',
            substr($entity['due_date'], 0, 10),
            $entity['completed'] ? 1 : 0,
            $entity['board_title'],
            $entity['card_title'] ?? null,
        ]));
    }

    /**
     * Returns a non-expired access token, refreshing via refresh_token if
     * needed and persisting the new expiry.
     */
    private static function validAccessToken(int $userId): string
    {
        $account = self::getAccount($userId);
        if (!$account) throw new RuntimeException('No Google connection for user');

        if (strtotime($account['expires_at']) > time() + 30) {
            return $account['access_token'];
        }

        $config = self::config();
        $res = self::http('POST', self::TOKEN_URL, [
            'client_id'     => $config['google_client_id'],
            'client_secret' => $config['google_client_secret'],
            'refresh_token' => $account['refresh_token'],
            'grant_type'    => 'refresh_token',
        ], false);

        if (empty($res['access_token'])) {
            throw new RuntimeException('Token refresh failed: ' . json_encode($res));
        }
        $expiresIn = (int) ($res['expires_in'] ?? 3600);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);

        Database::get()->prepare(
            'UPDATE google_calendar_accounts
             SET access_token = :at, expires_at = :exp WHERE user_id = :uid'
        )->execute(['at' => $res['access_token'], 'exp' => $expiresAt, 'uid' => $userId]);

        return $res['access_token'];
    }

    /** Calendar API call with bearer token. */
    private static function apiCallRaw(string $accessToken, string $method, string $path, array $body = null): array
    {
        $url = self::API_BASE . $path;
        $headers = ['Authorization: Bearer ' . $accessToken];
        return self::http($method, $url, $body, true, $headers);
    }

    /**
     * Tiny cURL wrapper. $bodyAsJson=true sends JSON, false sends form
     * (for the OAuth token endpoint). $extraHeaders are appended.
     */
    private static function http(string $method, string $url, ?array $body, bool $bodyAsJson, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $headers = $extraHeaders;
        if ($body !== null) {
            if ($bodyAsJson) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            } else {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("HTTP error: {$error}");
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP {$code}: " . substr($response, 0, 500));
        }

        // Some calls (DELETE, calendar deletion) return empty body on success.
        if ($response === '' || $response === null) return [];
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function redirectUri(): string
    {
        $config = self::config();
        return rtrim($config['base_url'], '/') . '/index.php?page=google_callback';
    }

    private static function config(): array
    {
        static $config;
        if ($config === null) {
            $config = require __DIR__ . '/../config/config.php';
        }
        return $config;
    }

    /**
     * Sign user_id with the app's CSRF secret so the OAuth callback can
     * verify the round-trip wasn't tampered with cross-account. We also
     * include a short random nonce to prevent replay across requests.
     */
    private static function makeState(int $userId): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $nonce = bin2hex(random_bytes(8));
        $_SESSION['google_oauth_state'] = $nonce;
        $payload = $userId . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload, self::stateKey());
        return base64_encode($payload . '.' . $sig);
    }

    private static function verifyState(int $userId, string $state): bool
    {
        $raw = base64_decode($state, true);
        if ($raw === false) return false;
        $parts = explode('.', $raw);
        if (count($parts) !== 3) return false;
        [$uid, $nonce, $sig] = $parts;
        if ((int) $uid !== $userId) return false;
        $expected = hash_hmac('sha256', $uid . '.' . $nonce, self::stateKey());
        if (!hash_equals($expected, $sig)) return false;
        $sessionNonce = $_SESSION['google_oauth_state'] ?? null;
        unset($_SESSION['google_oauth_state']);
        return $sessionNonce && hash_equals($sessionNonce, $nonce);
    }

    private static function stateKey(): string
    {
        $config = self::config();
        return ($config['db_pass'] ?? '') . '|' . ($config['google_client_secret'] ?? '');
    }
}
