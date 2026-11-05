-- Adds referral tracking: a `referral_codes` master table (one row per reference person, with
-- their fixed code), a `companies.referral_code_id` column (set once at registration if the
-- company entered a code), and a `referrals` table (one row per completed payment made by a
-- company that registered with a code — the ₹499 owed to that code's reference person).
-- Safe to run once against a live database; it only adds things, nothing is dropped.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_referral.sql

USE NexShoW_db;

CREATE TABLE IF NOT EXISTS referral_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_name VARCHAR(120) NOT NULL,
  code        VARCHAR(32)  NOT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB;

ALTER TABLE companies
  ADD COLUMN referral_code_id INT UNSIGNED NULL AFTER status,
  ADD KEY idx_referral_code (referral_code_id),
  ADD CONSTRAINT fk_companies_referral_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id);

CREATE TABLE IF NOT EXISTS referrals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  referral_code_id  INT UNSIGNED NOT NULL,
  company_id        INT UNSIGNED NOT NULL,
  payment_id        INT UNSIGNED NOT NULL,
  amount            INT UNSIGNED NOT NULL DEFAULT 49900,
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

-- To add a reference person and their fixed code (repeat per person):
-- INSERT INTO referral_codes (person_name, code) VALUES ('Jane Doe', 'JANE499');
