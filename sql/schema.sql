-- BravoCollab Database Schema
-- Run this in phpMyAdmin or MySQL CLI to set up the database

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- USERS & AUTH
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`         VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name`  VARCHAR(100) NOT NULL,
    `role`          ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    `avatar_path`   VARCHAR(255) DEFAULT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`         VARCHAR(255) NOT NULL,
    `token`         VARCHAR(64) NOT NULL UNIQUE,
    `invited_by`    INT UNSIGNED NOT NULL,
    `role`          ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    `accepted_at`   DATETIME DEFAULT NULL,
    `expires_at`    DATETIME NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitation_boards` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invitation_id` INT UNSIGNED NOT NULL,
    `board_id`      INT UNSIGNED NOT NULL,
    FOREIGN KEY (`invitation_id`) REFERENCES `invitations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_invitation_board` (`invitation_id`, `board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `token`      VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token` (`token`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address`    VARCHAR(45) NOT NULL,
    `email`         VARCHAR(255) DEFAULT NULL,
    `attempted_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `selector`       CHAR(32) NOT NULL,
    `validator_hash` CHAR(64) NOT NULL,
    `expires_at`     DATETIME NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_selector` (`selector`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOARDS
-- ============================================================

CREATE TABLE IF NOT EXISTS `boards` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(255) NOT NULL,
    `description`      TEXT,
    `background_color` VARCHAR(7) DEFAULT '#0079BF',
    `created_by`       INT UNSIGNED NOT NULL,
    `is_archived`      TINYINT(1) NOT NULL DEFAULT 0,
    `is_personal`      TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_personal` (`created_by`, `is_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `board_members` (
    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id`  INT UNSIGNED NOT NULL,
    `user_id`   INT UNSIGNED NOT NULL,
    `role`      ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_board_user` (`board_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LISTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `lists` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id`    INT UNSIGNED NOT NULL,
    `title`       VARCHAR(255) NOT NULL,
    `position`    INT UNSIGNED NOT NULL DEFAULT 0,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    INDEX `idx_board_position` (`board_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CARDS
-- ============================================================

CREATE TABLE IF NOT EXISTS `cards` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `list_id`        INT UNSIGNED NOT NULL,
    `title`          VARCHAR(500) NOT NULL,
    `description`    TEXT,
    `position`       INT UNSIGNED NOT NULL DEFAULT 0,
    `due_date`       DATETIME DEFAULT NULL,
    `start_date`     DATE DEFAULT NULL,
    `due_complete`   TINYINT(1) NOT NULL DEFAULT 0,
    `cover_image_id` INT UNSIGNED DEFAULT NULL,
    `coordinator_id` INT UNSIGNED DEFAULT NULL,
    `is_archived`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`     INT UNSIGNED NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`list_id`) REFERENCES `lists`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`coordinator_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_list_position` (`list_id`, `position`),
    INDEX `idx_coordinator` (`coordinator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_assignments` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`     INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_card_user` (`card_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_watchers` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_card_watcher` (`card_id`, `user_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LABELS
-- ============================================================

CREATE TABLE IF NOT EXISTS `labels` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id`   INT UNSIGNED NOT NULL,
    `name`       VARCHAR(100) DEFAULT NULL,
    `color`      VARCHAR(7) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    INDEX `idx_board` (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_labels` (
    `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`  INT UNSIGNED NOT NULL,
    `label_id` INT UNSIGNED NOT NULL,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`label_id`) REFERENCES `labels`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_card_label` (`card_id`, `label_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- COMMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `comments` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `parent_id`  INT UNSIGNED DEFAULT NULL,
    `body`       TEXT NOT NULL,
    `is_edited`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE,
    INDEX `idx_card` (`card_id`),
    INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ATTACHMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `attachments` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`        INT UNSIGNED NOT NULL,
    `user_id`        INT UNSIGNED NOT NULL,
    `original_name`  VARCHAR(255) NOT NULL,
    `stored_name`    VARCHAR(255) NOT NULL,
    `file_size`      INT UNSIGNED NOT NULL,
    `mime_type`      VARCHAR(100) NOT NULL,
    `is_image`       TINYINT(1) NOT NULL DEFAULT 0,
    `thumbnail_path` VARCHAR(255) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_card` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CHECKLISTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `checklists` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL DEFAULT 'Checklist',
    `position`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`id`) ON DELETE CASCADE,
    INDEX `idx_card` (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `checklist_items` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `checklist_id` INT UNSIGNED NOT NULL,
    `content`      VARCHAR(500) NOT NULL,
    `is_checked`   TINYINT(1) NOT NULL DEFAULT 0,
    `position`     INT UNSIGNED NOT NULL DEFAULT 0,
    `assigned_to`  INT UNSIGNED DEFAULT NULL,
    `due_date`     DATETIME DEFAULT NULL,
    `checked_by`   INT UNSIGNED DEFAULT NULL,
    `checked_at`   DATETIME DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`checklist_id`) REFERENCES `checklists`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`checked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_checklist` (`checklist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `type`       VARCHAR(50) NOT NULL,
    `data`       JSON NOT NULL,
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `emailed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_read` (`user_id`, `is_read`),
    INDEX `idx_user_created` (`user_id`, `created_at`),
    INDEX `idx_digest_queue` (`is_read`, `emailed_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTIVITY LOG
-- ============================================================

CREATE TABLE IF NOT EXISTS `activities` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id`   INT UNSIGNED NOT NULL,
    `card_id`    INT UNSIGNED DEFAULT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `action`     VARCHAR(50) NOT NULL,
    `detail`     JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_board_created` (`board_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CRON RUN LOG
-- ============================================================

CREATE TABLE IF NOT EXISTS `cron_runs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `started_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME DEFAULT NULL,
    `status`      ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    `summary`     TEXT DEFAULT NULL,
    INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Web Push subscriptions — one row per (user, browser endpoint). The same
-- user can have multiple devices/browsers subscribed; we send to all rows.
-- A 410 Gone or 404 from the push service means the subscription is dead
-- and gets deleted automatically on the next send attempt.
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `endpoint`     VARCHAR(500) NOT NULL,
    `p256dh`       VARCHAR(255) NOT NULL,
    `auth`         VARCHAR(255) NOT NULL,
    `user_agent`   VARCHAR(500) DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uk_user_endpoint` (`user_id`, `endpoint`(190)),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user opt-in/out preferences for notifications, the daily recap, and
-- coordinator-relevant events. Absence of a row is treated as "all defaults"
-- (notify_coordinator_cards = 0, both email toggles = 1) so existing users
-- don't have to opt in to email behaviour they've always had.
CREATE TABLE IF NOT EXISTS `user_preferences` (
    `user_id`                  INT UNSIGNED PRIMARY KEY,
    `notify_coordinator_cards` TINYINT(1) NOT NULL DEFAULT 0,
    `email_notifications`      TINYINT(1) NOT NULL DEFAULT 1,
    `daily_recap_email`        TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user dedupe for the daily 8am CET "what's next" digest. The composite PK
-- enforces "at most one row per (user, date)" so concurrent cron runs can't
-- send the email twice. Date is stored in CET to match the email's perspective.
CREATE TABLE IF NOT EXISTS `whats_next_sent` (
    `user_id`   INT UNSIGNED NOT NULL,
    `sent_date` DATE NOT NULL,
    `sent_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `sent_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GOOGLE CALENDAR INTEGRATION
-- ============================================================

-- One row per user with an active OAuth connection to Google Calendar.
-- access_token expires after ~1h and is refreshed using refresh_token.
-- calendar_id is the dedicated "BravoCollab" calendar we create at connect
-- time so we never touch the user's primary calendar.
CREATE TABLE IF NOT EXISTS `google_calendar_accounts` (
    `user_id`        INT UNSIGNED PRIMARY KEY,
    `access_token`   TEXT NOT NULL,
    `refresh_token`  TEXT NOT NULL,
    `expires_at`     DATETIME NOT NULL,
    `calendar_id`    VARCHAR(255) NOT NULL,
    `connected_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps each (user, BravoCollab entity) pair to its Google event id so
-- subsequent syncs can update or delete the right event. UNIQUE prevents
-- duplicate events for the same entity.
CREATE TABLE IF NOT EXISTS `google_calendar_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SSE EVENTS QUEUE
-- ============================================================

CREATE TABLE IF NOT EXISTS `sse_events` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id`   INT UNSIGNED NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `payload`    JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `boards`(`id`) ON DELETE CASCADE,
    INDEX `idx_board_id` (`board_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
