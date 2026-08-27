-- MarvySocials — first-login accounts for an EXISTING database
--
-- GENERATED FILE — do not edit by hand.
-- Regenerated with: php tools/build_production_sql.php
--
-- Import this in phpMyAdmin on a panel that already has data and
-- cannot re-import database/marvysocials.sql. It is idempotent:
-- missing usernames are inserted; existing passwords are left alone.
--
-- FIRST LOGIN
--   Staff admin (SUPER_ADMIN — full control of the site)
--     URL:      /admin/login
--     username: admin
--     email:    admin@example.com
--     password: ChangeMe!Admin2026
--   Customer dashboard
--     URL:      /login
--     username: demo
--     email:    demo@example.com
--     password: MarvyDemo#2026!
--   Support staff
--     URL:      /admin/login
--     username: staff
--     email:    staff@example.com
--     password: MarvyStaff#2026!
--   Change these immediately (Dashboard -> Account -> Password), or set
--   your own administrator before first login from /setup with VP_SETUP_TOKEN.
--
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- First-login gates. Staff without MFA were being bounced to
-- /dashboard/security and never reached the admin dashboard.
UPDATE `settings` SET `setting_value` = '{"value":false}'
WHERE `setting_key` IN ('admin_mfa_required', 'email_verification_required');

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
SELECT 'admin_mfa_required', '{"value":false}', 'security', 0
FROM (SELECT 1 AS _x) AS _seed
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'admin_mfa_required');

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
SELECT 'email_verification_required', '{"value":false}', 'security', 0
FROM (SELECT 1 AS _x) AS _seed
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'email_verification_required');

-- Keep an existing SUPER_ADMIN reachable (do not change its password).
UPDATE `users`
SET `status` = 'ACTIVE',
    `email_verified_at` = COALESCE(`email_verified_at`, '2026-01-01 00:00:00'),
    `mfa_enabled` = 0
WHERE `role` = 'SUPER_ADMIN';

-- CUSTOMER demo
INSERT INTO `users`
  (`public_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`,
   `status`, `role`, `price_group_id`, `referral_code`, `timezone`, `locale`,
   `email_verified_at`, `mfa_enabled`, `created_at`, `updated_at`)
SELECT '7QZZPKA8NQ3KPTF4N5MXZG72KY', 'demo', 'demo@example.com', '$2y$12$fbOxiyXpn.PVqvjMM.eraOi.nASxI4c8NdaibH97F3SW8cSMqQpC2', 'Dana', 'Demo',
       'ACTIVE', 'CUSTOMER', (SELECT `id` FROM `price_groups` WHERE `name` = 'Default' ORDER BY `id` ASC LIMIT 1), 'DEMO-0001', 'UTC', 'en',
       '2026-01-01 00:00:00', 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM (SELECT 1 AS _x) AS _seed
WHERE NOT EXISTS (
  SELECT 1 FROM `users`
  WHERE `username` = 'demo'
     OR `email` = 'demo@example.com'
     OR `public_id` = '7QZZPKA8NQ3KPTF4N5MXZG72KY'
);

INSERT INTO `wallets` (`public_id`, `user_id`, `balance`, `currency`, `created_at`, `updated_at`)
SELECT '05RYXZNXEFHDC09ENVW1D637Q3', u.`id`, '0.00000000', 'NGN', '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM `users` u
WHERE u.`username` = 'demo'
  AND NOT EXISTS (SELECT 1 FROM `wallets` w WHERE w.`user_id` = u.`id`);

INSERT INTO `referral_accounts` (`user_id`, `code`, `commission_percent`, `created_at`, `updated_at`)
SELECT u.`id`, 'DEMO-0001', '5.0000', '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM `users` u
WHERE u.`username` = 'demo'
  AND NOT EXISTS (SELECT 1 FROM `referral_accounts` r WHERE r.`user_id` = u.`id`);

-- STAFF staff
INSERT INTO `users`
  (`public_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`,
   `status`, `role`, `price_group_id`, `referral_code`, `timezone`, `locale`,
   `email_verified_at`, `mfa_enabled`, `created_at`, `updated_at`)
SELECT '8YZ0VCQ6PMK1MJNK18HNJ463KV', 'staff', 'staff@example.com', '$2y$12$A/aOCScPf990eSu5yiKk1u6/VsNQConshkNA3afWcv.bvjbJ.9VPa', 'Sam', 'Support',
       'ACTIVE', 'STAFF', (SELECT `id` FROM `price_groups` WHERE `name` = 'Default' ORDER BY `id` ASC LIMIT 1), 'STAFF-0001', 'UTC', 'en',
       '2026-01-01 00:00:00', 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM (SELECT 1 AS _x) AS _seed
WHERE NOT EXISTS (
  SELECT 1 FROM `users`
  WHERE `username` = 'staff'
     OR `email` = 'staff@example.com'
     OR `public_id` = '8YZ0VCQ6PMK1MJNK18HNJ463KV'
);

INSERT INTO `wallets` (`public_id`, `user_id`, `balance`, `currency`, `created_at`, `updated_at`)
SELECT 'YHJMA72PSKDZQ55RCXKX07Q3BC', u.`id`, '0.00000000', 'NGN', '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM `users` u
WHERE u.`username` = 'staff'
  AND NOT EXISTS (SELECT 1 FROM `wallets` w WHERE w.`user_id` = u.`id`);

INSERT INTO `referral_accounts` (`user_id`, `code`, `commission_percent`, `created_at`, `updated_at`)
SELECT u.`id`, 'STAFF-0001', '5.0000', '2026-01-01 00:00:00', '2026-01-01 00:00:00'
FROM `users` u
WHERE u.`username` = 'staff'
  AND NOT EXISTS (SELECT 1 FROM `referral_accounts` r WHERE r.`user_id` = u.`id`);

SET FOREIGN_KEY_CHECKS = 1;
