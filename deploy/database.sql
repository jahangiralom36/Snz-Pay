-- =========================================================================
-- SNZPay — সম্পূর্ণ ডাটাবেজ (নতুন হোস্টিং এর জন্য)
-- phpMyAdmin → আপনার ডাটাবেজ সিলেক্ট → Import → এই ফাইল → Go
-- =========================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- 1) ইউজার
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `has_used_trial` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 2) প্ল্যান (Admin → Plans থেকে নিয়ন্ত্রণ)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `duration_hours` INT NOT NULL DEFAULT 720,
  `max_devices` INT NOT NULL DEFAULT 1,
  `max_brand_keys` INT NOT NULL DEFAULT 1,
  `badge` VARCHAR(40) NULL,
  `description` VARCHAR(255) NULL,
  `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `category` VARCHAR(40) NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `plans` (`name`, `price`, `discount_percent`, `duration_hours`, `max_devices`, `max_brand_keys`, `badge`, `description`, `is_trial`, `status`, `sort_order`, `category`, `is_pinned`) VALUES
('Free Trial (30 Hours)', 0.00, 0, 30, 1, 1, '1st Time Free', 'Test all automated features completely free of charge.', 1, 'active', 1, 'Trial', 0),
('Standard Plan (30 BDT)', 30.00, 0, 168, 1, 1, 'Standard', '7 days of uninterrupted SMS & Binance automation.', 0, 'active', 2, 'Weekly', 0),
('Premium Plan (50 BDT)', 50.00, 0, 720, 1, 1, 'Popular Choice', 'Full 1 month of uninterrupted high-priority processing.', 0, 'active', 3, 'Monthly', 0);

-- -------------------------------------------------------------------------
-- 3) লাইসেন্স কী (অ্যাপ লগইন)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_keys` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `license_key` VARCHAR(64) NOT NULL,
  `plan_name` VARCHAR(128) NULL,
  `plan_id` INT NULL,
  `max_devices` INT NOT NULL DEFAULT 1,
  `max_brand_keys` INT NOT NULL DEFAULT 1,
  `expires_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `device_id` VARCHAR(100) NULL,
  `device_name` VARCHAR(150) NULL,
  `device_bound_at` DATETIME NULL,
  `last_seen_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uk_license` (`license_key`),
  KEY `idx_uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- কোন কী কোন ফোনে কানেক্টেড
CREATE TABLE IF NOT EXISTS `key_devices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `key_id` INT NOT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `device_name` VARCHAR(150) NULL,
  `bound_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_device` (`key_id`, `device_id`),
  KEY `idx_kd_key` (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 4) Brand Key (Website / App / Bot API)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `brand_keys` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `brand_name` VARCHAR(128) NULL,
  `brand_type` VARCHAR(20) NULL,
  `brand_link` VARCHAR(255) NULL,
  `whatsapp` VARCHAR(30) NULL,
  `api_key` VARCHAR(64) NOT NULL,
  `plan_duration_hours` INT NOT NULL DEFAULT 720,
  `status` VARCHAR(20) NOT NULL DEFAULT 'unlinked',
  `is_connected` TINYINT(1) NOT NULL DEFAULT 0,
  `connected_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `bound_fingerprint` VARCHAR(255) NULL,
  `bound_label` VARCHAR(255) NULL,
  `bound_at` DATETIME NULL,
  `last_used_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bk_api` (`api_key`),
  KEY `idx_bk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 5) পেমেন্ট (অ্যাপ থেকে আসা SMS)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `trx_id` VARCHAR(64) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `method` VARCHAR(32) NULL,
  `sender` VARCHAR(64) NULL,
  `raw_sms` TEXT NULL,
  `origin` VARCHAR(64) NULL,
  `user_id` INT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `used_by` VARCHAR(191) NULL,
  `used_at` DATETIME NULL,
  `claimed_via` VARCHAR(64) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_trx` (`trx_id`),
  KEY `idx_pay_status_user` (`status`, `user_id`),
  KEY `idx_pay_sender` (`sender`),
  KEY `idx_pay_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 6) ইউজারের Wallet Setup (bKash / Nagad / Bank / QR / Binance)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `category` VARCHAR(32) NULL,
  `provider` VARCHAR(64) NOT NULL,
  `account_type` VARCHAR(32) NULL,
  `account_number` VARCHAR(128) NULL,
  `account_name` VARCHAR(255) NULL,
  `api_key` TEXT NULL,
  `secret_key` TEXT NULL,
  `qr_image` VARCHAR(255) NULL,
  `dollar_rate` DECIMAL(10,2) DEFAULT 125.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uw_provider` (`provider`, `status`, `user_id`),
  KEY `idx_uw_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 7) ডিপোজিট রিকোয়েস্ট (৩ বার ব্যর্থ / Auto Pay বন্ধ → Admin)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposit_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `trx_id` VARCHAR(64) NOT NULL,
  `provider` VARCHAR(30) NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `attempts` INT NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'trying',
  `admin_note` VARCHAR(255) NULL,
  `credited_amount` DECIMAL(12,2) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_trx` (`user_id`, `trx_id`),
  KEY `idx_dr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- ✅ শেষ। এরপর সাইটে গিয়ে প্রথমে নিজের অ্যাকাউন্ট Register করুন —
--    প্রথম অ্যাকাউন্ট (#1) টাই master key (অ্যাডমিনের অ্যাপ) এর অ্যাকাউন্ট।
-- =========================================================================

-- -------------------------------------------------------------------------
-- 9) error_logs — Admin → Errors (App Error / Website Error)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `error_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `source` VARCHAR(10) NOT NULL DEFAULT 'web',
  `err_hash` CHAR(32) NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `detail` TEXT NULL,
  `where_at` VARCHAR(255) NULL,
  `device` VARCHAR(190) NULL,
  `license_key` VARCHAR(100) NULL,
  `ip` VARCHAR(64) NULL,
  `app_version` VARCHAR(30) NULL,
  `hits` INT NOT NULL DEFAULT 1,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `first_seen` DATETIME NULL,
  `last_seen` DATETIME NULL,
  UNIQUE KEY `uq_err_hash` (`err_hash`),
  KEY `idx_err_src` (`source`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
