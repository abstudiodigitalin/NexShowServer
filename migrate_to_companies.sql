-- Upgrades a database created by the OLD (single-tenant) schema.sql to the new
-- multi-company one. Safe to run once against a live NexShoW_db that already has
-- real frames/settings/tags — it moves your existing data into company #1 rather
-- than discarding it. Do not run this against a database already on the new schema.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_companies.sql
--
-- Your existing single admin password (site_settings.password_hash) becomes the
-- password for a new user named 'admin' (role 'admin') in company #1. The bcrypt
-- hash is copied as-is, so the same password still works after this runs — you
-- are not asked to re-enter it. This same login also works for the NexShow.php
-- device menu now — there is no separate device account. If your old database
-- still has device_username/device_password_hash columns, they're dropped below;
-- set up the device menu again by signing in with a `users` login instead.

USE NexShoW_db;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS companies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  username      VARCHAR(80)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  expires_at    DATETIME NULL, -- 'user' role only; admins never expire
  device_session_token VARCHAR(64) NULL, -- single-session enforcement for the device login
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_username (username),
  KEY idx_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- Company #1 = your existing single deployment. Named after today's page_title.
INSERT INTO companies (id, name)
  SELECT 1, COALESCE(NULLIF(page_title, ''), 'NexShoW') FROM site_settings WHERE id = 1;

-- Your existing admin.php password becomes company #1's admin user, username 'admin'.
-- Admins never expire (expires_at stays NULL). If site_settings.password_hash was never set
-- (no admin account existed yet), this inserts nothing — register normally through admin.php.
INSERT INTO users (company_id, username, password_hash, role)
  SELECT 1, 'admin', password_hash, 'admin' FROM site_settings WHERE id = 1 AND password_hash IS NOT NULL;

-- site_settings: drop the old singleton lock, password_hash (now in `users`), and the old
-- device_username/device_password_hash columns (the device menu now logs in via `users` too).
ALTER TABLE site_settings DROP CHECK chk_site_settings_singleton;
ALTER TABLE site_settings ADD COLUMN company_id INT UNSIGNED NULL AFTER id;
UPDATE site_settings SET company_id = 1 WHERE id = 1;
ALTER TABLE site_settings MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE site_settings DROP COLUMN password_hash;
ALTER TABLE site_settings DROP COLUMN device_username;
ALTER TABLE site_settings DROP COLUMN device_password_hash;
ALTER TABLE site_settings MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE site_settings
  ADD UNIQUE KEY uniq_company (company_id),
  ADD CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id);

-- frames: add company_id, re-key to (company_id, id) since slugs only need to be
-- unique within a company now.
ALTER TABLE frames ADD COLUMN company_id INT UNSIGNED NULL FIRST;
UPDATE frames SET company_id = 1;
ALTER TABLE frames MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE frames DROP PRIMARY KEY, ADD PRIMARY KEY (company_id, id);
ALTER TABLE frames DROP INDEX idx_sort_order, ADD INDEX idx_company_sort (company_id, sort_order);
ALTER TABLE frames ADD CONSTRAINT fk_frames_company FOREIGN KEY (company_id) REFERENCES companies(id);

-- tags: same re-keying.
ALTER TABLE tags ADD COLUMN company_id INT UNSIGNED NULL FIRST;
UPDATE tags SET company_id = 1;
ALTER TABLE tags MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE tags DROP PRIMARY KEY, ADD PRIMARY KEY (company_id, name);
ALTER TABLE tags ADD CONSTRAINT fk_tags_company FOREIGN KEY (company_id) REFERENCES companies(id);

COMMIT;

-- Verify before trusting it:
--   SELECT * FROM companies;
--   SELECT id, company_id, username, role FROM users;
--   SELECT company_id, COUNT(*) FROM frames GROUP BY company_id;
-- If `users` came back empty (no admin account existed before), register a fresh
-- admin account through admin.php — it will detect company #1 already has no users
-- and offer the normal "Register your company" flow with a new company instead, so
-- do that registration manually in MySQL if you specifically want to keep using
-- company #1 with a brand-new password:
--   INSERT INTO users (company_id, username, password_hash, role)
--     VALUES (1, 'admin', '<output of password_hash() for your chosen password>', 'admin');
