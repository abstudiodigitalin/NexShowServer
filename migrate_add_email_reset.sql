-- Adds "forgot password" support to an existing NexShoW_db (created by an earlier
-- schema.sql / migrate_to_companies.sql that predates the email/reset-token columns).
-- Safe to run once against a live database; it only adds columns, nothing is dropped.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_email_reset.sql

USE NexShoW_db;

ALTER TABLE users
  ADD COLUMN email VARCHAR(190) NULL AFTER username,
  ADD COLUMN reset_token_hash VARCHAR(64) NULL,
  ADD COLUMN reset_token_expires DATETIME NULL,
  ADD KEY idx_reset_token (reset_token_hash);

-- Existing users have no email yet, so "forgot password" won't be usable for them until
-- they (or an admin, on their behalf) set one from the admin.php Settings page.
