-- Adds company_table to site_settings: the one shared data table (rows, colors, position, sizing)
-- built in admin.php's Table Settings page and used by every frame that opts in via its own
-- table_data.enabled flag, replacing each frame having to build its own separate table.
-- Safe to run once against a live database; it only adds a column, nothing is dropped.
-- Existing companies start with company_table NULL (no shared table built yet) — any frame that
-- already has a full per-frame table saved keeps that JSON in its own table_data until an admin
-- rebuilds it under Table Settings; that per-frame JSON simply won't be read for rendering once
-- this column exists, since rendering now always looks at company_table instead.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_company_table.sql

USE NexShoW_db;

ALTER TABLE site_settings
  ADD COLUMN company_table TEXT NULL AFTER screen_height;
