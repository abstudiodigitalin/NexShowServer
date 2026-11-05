-- Lets a referral code be typed into the "Make payment" window itself, per payment, instead of
-- only being fixed at a company's registration. Adds `payments.referral_code_id` — resolved once
-- when the Razorpay order is created (from the code typed in, or the company's own registration
-- code as a fallback if left blank) — which is what the payment's referral credit uses once it
-- verifies paid. Requires migrate_add_referral.sql to already have been run.
-- Safe to run once against a live database; it only adds things, nothing is dropped.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_payment_referral.sql

USE NexShoW_db;

ALTER TABLE payments
  ADD COLUMN referral_code_id INT UNSIGNED NULL AFTER status,
  ADD KEY idx_referral_code (referral_code_id),
  ADD CONSTRAINT fk_payments_referral_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id);
