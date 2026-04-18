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
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
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
    `due_date`     DATE DEFAULT NULL,
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
