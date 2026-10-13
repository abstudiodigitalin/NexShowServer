-- Adds the per-user activation-fee gate to an existing NexShoW_db. Safe to run once against a
-- live database — it only adds a column, nothing is dropped, and every existing user defaults
-- to payment_status 'none' (not subject to the gate), so no one gets locked out by this.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_payment_gate.sql

USE NexShoW_db;

ALTER TABLE users
  ADD COLUMN payment_status ENUM('none','pending','paid') NOT NULL DEFAULT 'none' AFTER status;

-- From here on, a user created via admin.php's "Add user" (Settings) starts as
-- status='inactive', payment_status='pending' and stays locked out of both admin.php and the
-- NexShow device login until you run this yourself after confirming payment on WhatsApp:
--
--   UPDATE users SET status = 'active', payment_status = 'paid'
--     WHERE company_id = <their company id> AND username = '<their username>';
