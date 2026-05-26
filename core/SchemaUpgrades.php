<?php
/**
 * SchemaUpgrades — idempotent additive schema migrations applied on first
 * authenticated page load per request. Mirrors the self-heal pattern used by
 * UserPreferences::ensureSchema() so a deployment that just runs `git pull`
 * doesn't need a manual phpMyAdmin step to pick up new columns / tables.
 *
 * Each method is safe to call repeatedly — column-add wrapped in a try/catch
 * because MySQL <8 doesn't support IF NOT EXISTS on ALTER TABLE ADD COLUMN.
 */

require_once __DIR__ . '/../config/database.php';

class SchemaUpgrades
{
    /** Run every additive upgrade. Cheap second-call (static memo). */
    public static function ensureAll(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try { self::ensureChecklistAcceptance(); } catch (Throwable $e) {
            error_log('ensureChecklistAcceptance failed: ' . $e->getMessage());
        }
        try { self::ensureTaskTables(); } catch (Throwable $e) {
            error_log('ensureTaskTables failed: ' . $e->getMessage());
        }
        try { self::ensureTaskCommentAttachments(); } catch (Throwable $e) {
            error_log('ensureTaskCommentAttachments failed: ' . $e->getMessage());
        }
    }

    /** Add accepted_at to checklist_items if missing. */
    public static function ensureChecklistAcceptance(): void
    {
        $db = Database::get();
        $col = $db->query(
            "SHOW COLUMNS FROM `checklist_items` LIKE 'accepted_at'"
        )->fetch();
        if (!$col) {
            $db->exec(
                "ALTER TABLE `checklist_items`
                 ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `checked_at`"
            );
        }
    }

    /**
     * Adds a nullable `task_comment_id` column to `task_attachments` so the
     * same table can carry both task-level attachments (task_comment_id NULL)
     * and per-comment attachments on task comments. Mirrors the `attachments`
     * table's nullable `comment_id`. FK is best-effort — wrapped so older
     * MySQL versions / shared hosts that reject mid-life ALTER…ADD CONSTRAINT
     * still get the column.
     */
    public static function ensureTaskCommentAttachments(): void
    {
        $db = Database::get();

        // Skip entirely if the parent table doesn't exist yet — ensureTaskTables()
        // will have run first if everything is in order, but during partial
        // rollouts we don't want a missing-table fatal here.
        $tbl = $db->query("SHOW TABLES LIKE 'task_attachments'")->fetch();
        if (!$tbl) return;

        $col = $db->query(
            "SHOW COLUMNS FROM `task_attachments` LIKE 'task_comment_id'"
        )->fetch();
        if (!$col) {
            $db->exec(
                "ALTER TABLE `task_attachments`
                 ADD COLUMN `task_comment_id` INT UNSIGNED DEFAULT NULL AFTER `task_id`,
                 ADD INDEX `idx_task_comment` (`task_comment_id`)"
            );
            // FK is a nice-to-have for automatic cascade — the app also
            // cleans up explicitly when a comment is deleted, so failure here
            // doesn't break behaviour.
            try {
                $db->exec(
                    "ALTER TABLE `task_attachments`
                     ADD CONSTRAINT `fk_task_attachments_task_comment`
                         FOREIGN KEY (`task_comment_id`)
                         REFERENCES `task_comments`(`id`)
                         ON DELETE CASCADE"
                );
            } catch (Throwable $e) {
                error_log('Adding FK fk_task_attachments_task_comment skipped: ' . $e->getMessage());
            }
        }
    }

    /** Create the tasks/* tables and supporting indexes if missing. */
    public static function ensureTaskTables(): void
    {
        $db = Database::get();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `tasks` (
                `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `card_id`        INT UNSIGNED NOT NULL,
                `parent_task_id` INT UNSIGNED DEFAULT NULL,
                `title`          VARCHAR(500) NOT NULL,
                `description`    TEXT,
                `position`       INT UNSIGNED NOT NULL DEFAULT 0,
                `due_date`       DATETIME DEFAULT NULL,
                `is_complete`    TINYINT(1) NOT NULL DEFAULT 0,
                `completed_by`   INT UNSIGNED DEFAULT NULL,
                `completed_at`   DATETIME DEFAULT NULL,
                `created_by`     INT UNSIGNED NOT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`parent_task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_card` (`card_id`),
                INDEX `idx_parent` (`parent_task_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `task_assignments` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_id`     INT UNSIGNED NOT NULL,
                `user_id`     INT UNSIGNED NOT NULL,
                `accepted_at` DATETIME DEFAULT NULL,
                `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_task_user` (`task_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `task_comments` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_id`    INT UNSIGNED NOT NULL,
                `user_id`    INT UNSIGNED NOT NULL,
                `body`       TEXT NOT NULL,
                `is_edited`  TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_task` (`task_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `task_attachments` (
                `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_id`         INT UNSIGNED NOT NULL,
                `task_comment_id` INT UNSIGNED DEFAULT NULL,
                `user_id`         INT UNSIGNED NOT NULL,
                `original_name`   VARCHAR(255) NOT NULL,
                `stored_name`     VARCHAR(255) NOT NULL,
                `file_size`       INT UNSIGNED NOT NULL,
                `mime_type`       VARCHAR(100) NOT NULL,
                `is_image`        TINYINT(1) NOT NULL DEFAULT 0,
                `thumbnail_path`  VARCHAR(255) DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`task_id`)         REFERENCES `tasks`(`id`)         ON DELETE CASCADE,
                FOREIGN KEY (`task_comment_id`) REFERENCES `task_comments`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`)         REFERENCES `users`(`id`)         ON DELETE CASCADE,
                INDEX `idx_task`         (`task_id`),
                INDEX `idx_task_comment` (`task_comment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
