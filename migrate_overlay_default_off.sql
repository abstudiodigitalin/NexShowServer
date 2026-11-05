-- Changes the "Shade the photo" default for new frames from on to off. Only changes the column's
-- default (what a brand-new frame's checkbox starts as) — existing frames keep whatever overlay
-- value they already have, nothing here touches existing rows.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_overlay_default.sql

USE NexShoW_db;

ALTER TABLE frames
  ALTER COLUMN overlay SET DEFAULT 0;
