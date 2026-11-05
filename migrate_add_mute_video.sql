-- Adds a per-frame "mute video audio" flag. Existing frames default to 0 (unmuted, same
-- behaviour as before this column existed) and only the admin's checkbox changes it per frame.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_mute_video.sql

USE NexShoW_db;

ALTER TABLE frames
  ADD COLUMN mute_video TINYINT(1) NOT NULL DEFAULT 0 AFTER ken_burns;
