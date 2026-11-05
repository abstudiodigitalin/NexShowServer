-- Adds rate_config to site_settings: the standard rate + per-purity percentage entries for the
-- "Rate" page, which computes PURITY/SALERATE/URRATE/EXCHANGERATE 1-10 tags from them.
-- Safe to run once against a live database; it only adds a column, nothing is dropped.
-- Existing companies start with rate_config NULL (nothing entered yet) until an admin visits the
-- Rate page and saves it.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_rate_config.sql

USE NexShoW_db;

ALTER TABLE site_settings
  ADD COLUMN rate_config TEXT NULL;
