-- Adds Razorpay payment processing: a new `payments` table (one row per Razorpay order created
-- for a user's activation fee) and a `last_transaction_ref` column on `users` (the Razorpay
-- payment id of the activation payment that most recently activated that user).
-- Safe to run once against a live database; it only adds things, nothing is dropped.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_razorpay.sql

USE NexShoW_db;

ALTER TABLE users
  ADD COLUMN last_transaction_ref VARCHAR(64) NULL AFTER reset_token_expires;

CREATE TABLE IF NOT EXISTS payments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id           INT UNSIGNED NOT NULL,
  user_id              INT UNSIGNED NOT NULL,
  razorpay_order_id    VARCHAR(64)  NOT NULL,
  razorpay_payment_id  VARCHAR(64)  NULL,
  razorpay_signature   VARCHAR(128) NULL,
  amount               INT UNSIGNED NOT NULL,
  currency             VARCHAR(8)   NOT NULL DEFAULT 'INR',
  status               ENUM('created', 'paid', 'failed') NOT NULL DEFAULT 'created',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_order (razorpay_order_id),
  KEY idx_company (company_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_payments_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
