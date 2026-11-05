-- NexShoW slider database. Separate from any other project's database.
-- Multi-tenant: every company's frames, settings and tags are isolated by company_id.
CREATE DATABASE IF NOT EXISTS NexShoW_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE NexShoW_db;

-- A fixed set of referral codes, one per reference person who refers new companies to sign up.
-- Rows here are managed directly in the database by Ab Studio Digital (there is no admin.php UI
-- for it, same as how `users.payment_status` is flipped to 'paid' directly in the database) —
-- e.g. INSERT INTO referral_codes (person_name, code) VALUES ('Jane Doe', 'JANE499');
CREATE TABLE IF NOT EXISTS referral_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_name VARCHAR(120) NOT NULL,
  code        VARCHAR(32)  NOT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB;

-- One row per company (tenant). Created at registration (admin.php's "Register your company").
CREATE TABLE IF NOT EXISTS companies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(120) NOT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  referral_code_id  INT UNSIGNED NULL, -- the referral code entered at registration, if any; every
    -- payment this company ever makes credits ₹499 to this code (see `referrals` below). Set once
    -- at registration and never changed afterwards.
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_referral_code (referral_code_id),
  CONSTRAINT fk_companies_referral_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id)
) ENGINE=InnoDB;

-- admin.php logins. Each user belongs to exactly one company; a company can have several users.
-- username is globally unique (login has no separate "company" field — the user's own row tells
-- us their company_id, which is the only value ever trusted for scoping queries).
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  username      VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NULL, -- optional; used only to email "forgot password" reset links
  password_hash VARCHAR(255) NOT NULL, -- bcrypt; this is what login actually checks (password_verify())
  password_aes  BLOB NULL, -- a second, reversible copy of the same password — MySQL AES_ENCRYPT(),
    -- the server's default block_encryption_mode (aes-128-ecb unless changed), written alongside
    -- password_hash whenever one is set (see xs_password_write_fragment() in db-config.php). Not
    -- used for login; purely so the plaintext can be recovered later (e.g.
    -- xs_db_decrypt_user_password()). NULL for any row whose password was never (re)set since this
    -- column was added. ECB has no IV, so identical passwords produce identical password_aes bytes
    -- — visible to anyone with database access without decrypting anything.
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  payment_status ENUM('none','pending','paid') NOT NULL DEFAULT 'none', -- 'none' = not subject to
    -- the per-user activation fee (the company's own founding admin, and anyone created before
    -- this existed). A user added via Settings > Add user starts 'pending' (and status
    -- 'inactive')j until Ab Studio Digital confirms payment and flips it to 'paid' directly in
    -- the database — a company's own admin can never do this themselves (see the 'toggle_user'
    -- guard in admin.php, which refuses to reactivate a still-pending user).
  expires_at    DATETIME NULL, -- 'user' role: 1 year from creation, checked at login and on every request after. 'admin' role: NULL, never expires.
  device_session_token VARCHAR(64) NULL, -- the one NexShow.php device session allowed to be
    -- logged in as this user right now; a fresh login overwrites it, which signs out whichever
    -- device was using the old one the next time it's checked (see xs_db_set_device_session_token()).
  reset_token_hash    VARCHAR(64) NULL, -- sha256 of the "forgot password" link's token; never the raw token
  reset_token_expires DATETIME NULL, -- the reset link stops working after this (see xs_db_set_reset_token())
  last_transaction_ref VARCHAR(64) NULL, -- the Razorpay payment id (razorpay_payment_id) of the
    -- activation payment that most recently activated this user — set only by
    -- xs_db_activate_paid_user(), once the payment's signature has been verified server-side
    -- (see the razorpay_verify_payment action in admin.php). Not a hash; Razorpay's own id is
    -- already an opaque reference, safe to store and show as-is.
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
  screen_width          INT UNSIGNED NULL, -- the device's screen.width/height, captured by the device-menu
  screen_height         INT UNSIGNED NULL, -- login screen (see NexShow.php) so admin.php's frame preview can
    -- size itself to match the real screen instead of a hardcoded 1920x1080 guess. NULL until that
    -- device has logged in at least once since this column was added.
  company_table         TEXT NULL, -- the one shared data table (rows, colors, position, sizing — same JSON
    -- shape as a frame's old per-frame table) built in admin.php's Table Settings page; a frame just
    -- opts in to showing it via its own table_data.enabled flag, NULL until built.
  rate_config           TEXT NULL, -- the Rate page's standard rate + per-level percentages (JSON); saving
    -- it also computes and upserts the PURITY/SALERATE/URRATE/EXCHANGERATE 1-10 tags into `tags`.
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
  overlay             TINYINT(1)      NOT NULL DEFAULT 0,
  overlay_strength    DECIMAL(3,2)    NOT NULL DEFAULT 0.55,
  overlay_direction   ENUM('auto','left','right','bottom','full') NOT NULL DEFAULT 'auto',
  focal_point         VARCHAR(20)     NOT NULL DEFAULT '50% 50%',
  mobile_focal_point  VARCHAR(20)     NULL,
  text_color          CHAR(7)         NOT NULL DEFAULT '#ffffff',
  font_family         VARCHAR(120)    NULL,
  title_size          VARCHAR(80)     NULL,
  title_weight        SMALLINT        NULL,
  ken_burns           TINYINT(1)      NOT NULL DEFAULT 1,
  mute_video          TINYINT(1)      NOT NULL DEFAULT 0,
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

-- One row per Razorpay order created for a user's activation payment. Created with status
-- 'created' the moment admin.php asks Razorpay for an order (before the admin has paid anything);
-- flipped to 'paid' only after the payment's signature is verified server-side (never trusts the
-- browser's own "it succeeded" callback alone), at the same time users.last_transaction_ref,
-- status and expires_at are updated. A row that's created but never completed just stays
-- 'created' — Razorpay expires the order on its own side; nothing here needs to clean it up.
CREATE TABLE IF NOT EXISTS payments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id           INT UNSIGNED NOT NULL,
  user_id              INT UNSIGNED NOT NULL, -- the user this activation payment is for
  razorpay_order_id    VARCHAR(64)  NOT NULL,
  razorpay_payment_id  VARCHAR(64)  NULL, -- set once Razorpay's checkout actually completes
  razorpay_signature   VARCHAR(128) NULL, -- the HMAC Razorpay returned, kept for audit/dispute reference
  amount               INT UNSIGNED NOT NULL, -- in paise (e.g. 299900 = ₹2,999.00), matching what Razorpay was asked to collect
  currency             VARCHAR(8)   NOT NULL DEFAULT 'INR',
  status               ENUM('created', 'paid', 'failed') NOT NULL DEFAULT 'created',
  referral_code_id     INT UNSIGNED NULL, -- resolved once, when the order is created, from either
    -- a code typed into the pay window for this payment or (if left blank) the company's own
    -- registration code — see xs_db_create_payment(). This is what funds the ₹499 referral credit
    -- once the payment verifies paid, so a payment's credit always reflects the code chosen at
    -- the moment it was created, not whatever the company's registration code is by then.
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_order (razorpay_order_id),
  KEY idx_company (company_id),
  KEY idx_user (user_id),
  KEY idx_referral_code (referral_code_id),
  CONSTRAINT fk_payments_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_payments_referral_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id)
) ENGINE=InnoDB;

-- One row per completed payment made by a company that registered with a referral code — the
-- ₹499 owed to that code's reference person. Written once, right after a payment is verified
-- paid (see xs_db_create_referral_credit(), the only writer); `status` tracks whether Ab Studio
-- Digital has paid that reference person out yet (flipped directly in the database, same pattern
-- as users.payment_status). UNIQUE on payment_id means a given payment can only ever fund one
-- referral credit, even if verification were somehow re-run.
CREATE TABLE IF NOT EXISTS referrals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  referral_code_id  INT UNSIGNED NOT NULL,
  company_id        INT UNSIGNED NOT NULL, -- the company whose payment earned this credit
  payment_id        INT UNSIGNED NOT NULL, -- the specific payments row that triggered it
  amount            INT UNSIGNED NOT NULL DEFAULT 49900, -- in paise; 49900 = ₹499.00
  status            ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payment (payment_id),
  KEY idx_referral_code (referral_code_id),
  KEY idx_company (company_id),
  CONSTRAINT fk_referrals_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id),
  CONSTRAINT fk_referrals_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_referrals_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;
