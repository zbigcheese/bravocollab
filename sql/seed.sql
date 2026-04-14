-- BravoCollab Seed Data
-- RECOMMENDED: Use setup.php instead to create the admin user with a proper password hash.
-- This file is provided as a fallback only.
--
-- If you must use this file directly, the default credentials are:
--   Email: admin@bravo.org
--   Password: BravoAdmin2024!
--
-- CHANGE THE PASSWORD IMMEDIATELY after first login.

INSERT INTO `users` (`email`, `password_hash`, `display_name`, `role`, `is_active`) VALUES
('admin@bravo.org', '$2y$12$qF8G7C5xJzVxKp0nMkXJ8OQJ5K.Z9D4CjvPmwAz8oHBxWxjQaKmIy', 'Admin', 'admin', 1);
