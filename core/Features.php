<?php
/**
 * Features — per-user opt-in feature flags, managed by site admins.
 *
 * Only for features that should be visible to a hand-picked set of users
 * (paid API usage, experiments). Everything else ships to everyone.
 * Admins are NOT granted automatically: an admin who wants a feature grants
 * it to themselves like anyone else, so the gate is always explicit.
 *
 * To add a feature: add a key to CATALOG, then gate the server code with
 * Features::has() and the UI with App.hasFeature() (fed by the
 * <meta name="user-features"> tag in views/layout/header.php).
 */

require_once __DIR__ . '/../config/database.php';

class Features
{
    public const INFOGRAPHIC_VIDEO = 'infographic_video';

    public const CATALOG = [
        self::INFOGRAPHIC_VIDEO => [
            'label'       => 'Infographic video (AI)',
            'description' => 'Generate animated 9:16 infographic videos from a card. Uses the Anthropic API (paid).',
        ],
    ];

    /** Per-request memo: userId => [featureKey => true]. */
    private static array $cache = [];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        Database::get()->exec(
            "CREATE TABLE IF NOT EXISTS `user_features` (
                `user_id`     INT UNSIGNED NOT NULL,
                `feature_key` VARCHAR(64)  NOT NULL,
                `granted_by`  INT UNSIGNED DEFAULT NULL,
                `granted_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `feature_key`),
                INDEX `idx_feature` (`feature_key`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }

    /** @return string[] feature keys enabled for the user (only known ones). */
    public static function forUser(int $userId): array
    {
        if (!isset(self::$cache[$userId])) {
            self::$cache[$userId] = [];
            try {
                self::ensureSchema();
                $stmt = Database::get()->prepare(
                    'SELECT feature_key FROM user_features WHERE user_id = :uid'
                );
                $stmt->execute(['uid' => $userId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    if (self::isKnown($key)) self::$cache[$userId][$key] = true;
                }
            } catch (Throwable $e) {
                error_log('Features::forUser failed: ' . $e->getMessage());
            }
        }
        return array_keys(self::$cache[$userId]);
    }

    public static function has(int $userId, string $key): bool
    {
        return in_array($key, self::forUser($userId), true);
    }

    public static function set(int $userId, string $key, bool $enabled, ?int $grantedBy): void
    {
        self::ensureSchema();
        $db = Database::get();
        if ($enabled) {
            $db->prepare(
                'INSERT IGNORE INTO user_features (user_id, feature_key, granted_by)
                 VALUES (:uid, :key, :by)'
            )->execute(['uid' => $userId, 'key' => $key, 'by' => $grantedBy]);
        } else {
            $db->prepare(
                'DELETE FROM user_features WHERE user_id = :uid AND feature_key = :key'
            )->execute(['uid' => $userId, 'key' => $key]);
        }
        unset(self::$cache[$userId]);
    }

    /** @return array<int, string[]> userId => feature keys, for the admin matrix. */
    public static function allGrants(): array
    {
        self::ensureSchema();
        $out = [];
        foreach (Database::get()->query('SELECT user_id, feature_key FROM user_features')->fetchAll() as $row) {
            if (self::isKnown($row['feature_key'])) {
                $out[(int) $row['user_id']][] = $row['feature_key'];
            }
        }
        return $out;
    }
}
