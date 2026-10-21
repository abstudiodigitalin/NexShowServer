-- Adds a second, reversible copy of each user's password alongside the existing bcrypt
-- password_hash — MySQL's own AES_ENCRYPT() (the server's default block_encryption_mode,
-- aes-128-ecb unless changed), written whenever a password is (re)set. Login still checks only
-- password_hash (password_verify()); this is purely so the plaintext can be read back later via
-- xs_db_decrypt_user_password() in db-config.php.
-- Safe to run once against a live database; it only adds a column, nothing is dropped.
-- Existing users' password_aes starts NULL (there's no plaintext to encrypt yet) and gets filled
-- in the next time that account's password is set or changed.
--
-- Back up first:  mysqldump NexShoW_db > backup_before_password_aes.sql

USE NexShoW_db;

ALTER TABLE users
  ADD COLUMN password_aes BLOB NULL AFTER password_hash;
