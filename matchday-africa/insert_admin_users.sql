-- Insert Admin and User accounts for Matchday Africa
-- Run this in phpMyAdmin or MySQL command line

-- Insert Admin User
INSERT INTO `users` (`name`, `email`, `email_verified_at`, `password`, `role`, `is_admin`, `created_at`, `updated_at`) 
VALUES (
    'Admin User', 
    'admin@matchday-africa.com', 
    NOW(), 
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'admin', 
    1, 
    NOW(), 
    NOW()
) ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`),
    `password` = VALUES(`password`),
    `role` = VALUES(`role`),
    `is_admin` = VALUES(`is_admin`),
    `updated_at` = NOW();

-- Insert Regular User
INSERT INTO `users` (`name`, `email`, `email_verified_at`, `password`, `role`, `is_admin`, `created_at`, `updated_at`) 
VALUES (
    'Regular User', 
    'user@matchday-africa.com', 
    NOW(), 
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'user', 
    0, 
    NOW(), 
    NOW()
) ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`),
    `password` = VALUES(`password`),
    `role` = VALUES(`role`),
    `is_admin` = VALUES(`is_admin`),
    `updated_at` = NOW();

-- Verify the users were created
SELECT id, name, email, role, is_admin, created_at FROM users WHERE email IN ('admin@matchday-africa.com', 'user@matchday-africa.com');
