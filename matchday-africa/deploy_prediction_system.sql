-- =====================================================
-- MATCHDAY AFRICA - PREDICTION SYSTEM DEPLOYMENT
-- =====================================================
-- This file contains all database changes needed to deploy
-- the prediction system to matchday.africa
-- 
-- IMPORTANT: Run these commands in order on your production server
-- =====================================================

-- 1. Add role fields to users table
-- =====================================================
ALTER TABLE `users` 
ADD COLUMN `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER `email`,
ADD COLUMN `is_admin` BOOLEAN NOT NULL DEFAULT FALSE AFTER `role`,
ADD INDEX `idx_users_role_admin` (`role`, `is_admin`);

-- 2. Add prediction fields to matches table
-- =====================================================
ALTER TABLE `matches` 
ADD COLUMN `is_prediction_eligible` BOOLEAN NOT NULL DEFAULT FALSE AFTER `has_preview`,
ADD COLUMN `prediction_deadline` DATETIME NULL AFTER `is_prediction_eligible`,
ADD COLUMN `prediction_types_enabled` JSON NULL AFTER `prediction_deadline`;

-- 3. Create prediction_sets table
-- =====================================================
CREATE TABLE `prediction_sets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `admin_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('draft', 'active', 'closed', 'archived') NOT NULL DEFAULT 'draft',
    `prediction_deadline` DATETIME NOT NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `prediction_sets_admin_id_foreign` (`admin_id`),
    KEY `prediction_sets_status_index` (`status`),
    KEY `prediction_sets_prediction_deadline_index` (`prediction_deadline`),
    CONSTRAINT `prediction_sets_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create prediction_set_matches table
-- =====================================================
CREATE TABLE `prediction_set_matches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prediction_set_id` BIGINT UNSIGNED NOT NULL,
    `match_id` BIGINT UNSIGNED NOT NULL,
    `prediction_type` ENUM('result', 'score', 'goalscorer', 'total_goals') NOT NULL DEFAULT 'result',
    `points_value` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `prediction_set_matches_prediction_set_id_foreign` (`prediction_set_id`),
    KEY `prediction_set_matches_match_id_foreign` (`match_id`),
    KEY `prediction_set_matches_prediction_type_index` (`prediction_type`),
    CONSTRAINT `prediction_set_matches_prediction_set_id_foreign` FOREIGN KEY (`prediction_set_id`) REFERENCES `prediction_sets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `prediction_set_matches_match_id_foreign` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create user_predictions table
-- =====================================================
CREATE TABLE `user_predictions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `prediction_set_id` BIGINT UNSIGNED NOT NULL,
    `match_id` BIGINT UNSIGNED NOT NULL,
    `prediction_type` ENUM('result', 'score', 'goalscorer', 'total_goals') NOT NULL,
    `prediction_value` VARCHAR(255) NOT NULL,
    `points_earned` INT NOT NULL DEFAULT 0,
    `is_correct` BOOLEAN NULL,
    `submitted_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `user_predictions_user_id_foreign` (`user_id`),
    KEY `user_predictions_prediction_set_id_foreign` (`prediction_set_id`),
    KEY `user_predictions_match_id_foreign` (`match_id`),
    KEY `user_predictions_prediction_type_index` (`prediction_type`),
    KEY `user_predictions_submitted_at_index` (`submitted_at`),
    UNIQUE KEY `user_pred_unique` (`user_id`, `prediction_set_id`, `match_id`, `prediction_type`),
    CONSTRAINT `user_predictions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `user_predictions_prediction_set_id_foreign` FOREIGN KEY (`prediction_set_id`) REFERENCES `prediction_sets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `user_predictions_match_id_foreign` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Create prediction_leaderboards table
-- =====================================================
CREATE TABLE `prediction_leaderboards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prediction_set_id` BIGINT UNSIGNED NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `total_points` INT NOT NULL DEFAULT 0,
    `correct_predictions` INT NOT NULL DEFAULT 0,
    `total_predictions` INT NOT NULL DEFAULT 0,
    `accuracy_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `rank` INT NULL,
    `period` ENUM('daily', 'weekly', 'monthly', 'all_time') NOT NULL DEFAULT 'all_time',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `prediction_leaderboards_prediction_set_id_foreign` (`prediction_set_id`),
    KEY `prediction_leaderboards_user_id_foreign` (`user_id`),
    KEY `prediction_leaderboards_period_index` (`period`),
    KEY `prediction_leaderboards_rank_index` (`rank`),
    CONSTRAINT `prediction_leaderboards_prediction_set_id_foreign` FOREIGN KEY (`prediction_set_id`) REFERENCES `prediction_sets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `prediction_leaderboards_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Create test admin and user accounts
-- =====================================================
INSERT INTO `users` (`name`, `email`, `email_verified_at`, `password`, `role`, `is_admin`, `created_at`, `updated_at`) VALUES
('Admin User', 'admin@matchday-africa.com', NOW(), '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', TRUE, NOW(), NOW()),
('Regular User', 'user@matchday-africa.com', NOW(), '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', FALSE, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
`role` = VALUES(`role`), 
`is_admin` = VALUES(`is_admin`),
`updated_at` = NOW();

-- 8. Update some existing matches to be prediction eligible
-- =====================================================
UPDATE `matches` 
SET `is_prediction_eligible` = TRUE 
WHERE `match_date` > NOW() 
AND `status` IN ('SCHEDULED', 'TIMED', 'NS') 
AND `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `matches` 
        WHERE `match_date` > NOW() 
        AND `status` IN ('SCHEDULED', 'TIMED', 'NS')
        ORDER BY `id` 
        LIMIT 25
    ) AS temp
);

-- 9. Create indexes for better performance
-- =====================================================
CREATE INDEX `idx_matches_prediction_eligible` ON `matches` (`is_prediction_eligible`, `match_date`);
CREATE INDEX `idx_matches_league_date` ON `matches` (`league_id`, `match_date`);
CREATE INDEX `idx_user_predictions_user_set` ON `user_predictions` (`user_id`, `prediction_set_id`);
CREATE INDEX `idx_prediction_leaderboards_user_period` ON `prediction_leaderboards` (`user_id`, `period`);

-- =====================================================
-- DEPLOYMENT COMPLETE
-- =====================================================
-- After running this SQL file, you need to upload the following files:
-- 
-- 1. All PHP files from app/ directory
-- 2. All view files from resources/views/ directory  
-- 3. Updated routes/web.php
-- 4. Updated bootstrap/app.php
-- 5. Updated app/Console/Kernel.php
-- 6. All new service files
-- 7. All new model files
-- 8. All new controller files
-- 9. All new middleware files
-- 10. All new console command files
-- 
-- Then run: php artisan config:clear && php artisan view:clear && php artisan cache:clear
-- =====================================================
