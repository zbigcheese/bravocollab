<?php
/**
 * UserPreferences — read/write per-user settings.
 *
 * Defaults are applied when no row exists, so a brand-new user gets the
 * same behaviour as before this feature shipped (email notifications on,
 * daily recap on, coordinator notifications off). The first save creates
 * the row and any subsequent reads use the stored values.
 */

require_once __DIR__ . '/../config/database.php';

class UserPreferences
{
    public const DEFAULTS = [
        'notify_coordinator_cards' => 0,
        'email_notifications'      => 1,
        'daily_recap_email'        => 1,
    ];

    /** Self-heal so deployments without manual schema migration still work. */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        Database::get()->exec(
            "CREATE TABLE IF NOT EXISTS `user_preferences` (
                `user_id`                  INT UNSIGNED PRIMARY KEY,
                `notify_coordinator_cards` TINYINT(1) NOT NULL DEFAULT 0,
                `email_notifications`      TINYINT(1) NOT NULL DEFAULT 1,
                `daily_recap_email`        TINYINT(1) NOT NULL DEFAULT 1,
                `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $checked = true;
    }

    /** Returns prefs as ['notify_coordinator_cards'=>0|1, ...] with defaults. */
    public static function get(int $userId): array
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare(
            'SELECT notify_coordinator_cards, email_notifications, daily_recap_email
             FROM user_preferences WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return self::DEFAULTS;
        return [
            'notify_coordinator_cards' => (int) $row['notify_coordinator_cards'],
            'email_notifications'      => (int) $row['email_notifications'],
            'daily_recap_email'        => (int) $row['daily_recap_email'],
        ];
    }

    /** Update one or more preferences for the user. Unknown keys are ignored. */
    public static function update(int $userId, array $changes): array
    {
        self::ensureSchema();
        $current = self::get($userId);
        $allowed = array_keys(self::DEFAULTS);

        $next = $current;
        foreach ($allowed as $key) {
            if (array_key_exists($key, $changes)) {
                $next[$key] = $changes[$key] ? 1 : 0;
            }
        }

        Database::get()->prepare(
            'INSERT INTO user_preferences
                (user_id, notify_coordinator_cards, email_notifications, daily_recap_email)
             VALUES (:uid, :ncc, :en, :dre)
             ON DUPLICATE KEY UPDATE
                notify_coordinator_cards = VALUES(notify_coordinator_cards),
                email_notifications      = VALUES(email_notifications),
                daily_recap_email        = VALUES(daily_recap_email)'
        )->execute([
            'uid' => $userId,
            'ncc' => $next['notify_coordinator_cards'],
            'en'  => $next['email_notifications'],
            'dre' => $next['daily_recap_email'],
        ]);

        return $next;
    }

    /**
     * SQL-friendly UNION fragment that returns every user_id whose
     * `notify_coordinator_cards` evaluates to ON. Use it inline as a derived
     * table when joining against coordinator IDs in other queries.
     * (Not currently used — kept for reference / future SQL-only paths.)
     */
    public static function coordinatorOptInsSql(): string
    {
        return '(SELECT user_id FROM user_preferences WHERE notify_coordinator_cards = 1)';
    }
}
