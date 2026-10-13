-- NexShoW slider database. Separate from any other project's database.
-- Multi-tenant: every company's frames, settings and tags are isolated by company_id.
CREATE DATABASE IF NOT EXISTS NexShoW_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE NexShoW_db;

-- One row per company (tenant). Created at registration (admin.php's "Register your company").
CREATE TABLE IF NOT EXISTS companies (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- admin.php logins. Each user belongs to exactly one company; a company can have several users.
-- username is globally unique (login has no separate "company" field — the user's own row tells
-- us their company_id, which is the only value ever trusted for scoping queries).
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  username      VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NULL, -- optional; used only to email "forgot password" reset links
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  expires_at    DATETIME NULL, -- 'user' role: 1 year from creation, checked at login and on every request after. 'admin' role: NULL, never expires.
  device_session_token VARCHAR(64) NULL, -- the one NexShow.php device session allowed to be
    -- logged in as this user right now; a fresh login overwrites it, which signs out whichever
    -- device was using the old one the next time it's checked (see xs_db_set_device_session_token()).
  reset_token_hash    VARCHAR(64) NULL, -- sha256 of the "forgot password" link's token; never the raw token
  reset_token_expires DATETIME NULL, -- the reset link stops working after this (see xs_db_set_reset_token())
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_username (username),
  KEY idx_company (company_id),
  KEY idx_reset_token (reset_token_hash),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- One row per company: site-wide slider settings. (Logins — both admin.php and the NexShow.php
-- device menu — live in `users` above; there is no separate device account.)
CREATE TABLE IF NOT EXISTS site_settings (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id            INT UNSIGNED NOT NULL,
  page_title            VARCHAR(80)  NOT NULL DEFAULT 'NexShoW',
  aria_label            VARCHAR(80)  NOT NULL DEFAULT 'Slideshow',
  autoplay              TINYINT(1)   NOT NULL DEFAULT 1,
  pause_on_hover        TINYINT(1)   NOT NULL DEFAULT 1,
  duration_ms           INT          NOT NULL DEFAULT 5000,
  transition            ENUM('fade','zoom','drift') NOT NULL DEFAULT 'fade',
  transition_ms         INT          NOT NULL DEFAULT 1200,
  swipe_threshold       INT          NOT NULL DEFAULT 50,
  mobile_breakpoint     INT          NOT NULL DEFAULT 768,
  orientation           ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_company (company_id),
  CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- One row per frame, scoped to its company. A frame's slug (`id`) only has to be unique within
-- its own company, so the primary key is the pair, not `id` alone.
CREATE TABLE IF NOT EXISTS frames (
  company_id          INT UNSIGNED    NOT NULL,
  id                  VARCHAR(64)     NOT NULL,
  sort_order          INT             NOT NULL DEFAULT 0,
  image               VARCHAR(255)    NOT NULL,
  mobile_image        VARCHAR(255)    NULL,
  alt                 VARCHAR(200)    NULL,
  eyebrow             VARCHAR(80)     NULL,
  title               VARCHAR(200)    NULL,
  description         TEXT            NULL,
  table_data           LONGTEXT        NULL, -- JSON: {enabled, headerRow, hAlign, vAlign, rows:[[cell,...],...]}
  button_text         VARCHAR(60)     NULL,
  button_url          VARCHAR(500)    NULL,
  open_new_tab        TINYINT(1)      NOT NULL DEFAULT 0,
  text_align          ENUM('left','center','right')          NOT NULL DEFAULT 'left',
  vertical_align      ENUM('top','center','bottom')          NOT NULL DEFAULT 'center',
  offset_x            VARCHAR(20)     NOT NULL DEFAULT '0px',
  offset_y            VARCHAR(20)     NOT NULL DEFAULT '0px',
  overlay             TINYINT(1)      NOT NULL DEFAULT 1,
  overlay_strength    DECIMAL(3,2)    NOT NULL DEFAULT 0.55,
  overlay_direction   ENUM('auto','left','right','bottom','full') NOT NULL DEFAULT 'auto',
  focal_point         VARCHAR(20)     NOT NULL DEFAULT '50% 50%',
  mobile_focal_point  VARCHAR(20)     NULL,
  text_color          CHAR(7)         NOT NULL DEFAULT '#ffffff',
  font_family         VARCHAR(120)    NULL,
  title_size          VARCHAR(80)     NULL,
  title_weight        SMALLINT        NULL,
  ken_burns           TINYINT(1)      NOT NULL DEFAULT 1,
  transition          ENUM('fade','zoom','drift')            NULL,
  duration_ms         INT             NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, id),
  INDEX idx_company_sort (company_id, sort_order),
  CONSTRAINT fk_frames_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- Named values usable as {{name}} placeholders inside a frame's table cells, scoped per company
-- — update one row here and every table cell referencing it (within that company) shows the new
-- value immediately.
CREATE TABLE IF NOT EXISTS tags (
  company_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(64)  NOT NULL,
  value       VARCHAR(255) NOT NULL DEFAULT '',
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, name),
  CONSTRAINT fk_tags_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;
