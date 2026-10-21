-- Adds screen_width/screen_height to site_settings, so the device-menu login screen (NexShow.php)
-- can record the real screen.width/screen.height of the device it's running on, and admin.php's
-- frame edit preview can size its iframe to match that real screen instead of a hardcoded
-- 1920x1080 guess.
-- Safe to run once against a live database; it only adds columns, nothing is dropped.
-- Existing companies start with both columns NULL (no resolution recorded yet) until that
-- company's device next logs in through the device menu.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_screen_resolution.sql

USE NexShoW_db;

ALTER TABLE site_settings
  ADD COLUMN screen_width  INT UNSIGNED NULL AFTER orientation,
  ADD COLUMN screen_height INT UNSIGNED NULL AFTER screen_width;
