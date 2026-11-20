-- ============================================================================
-- LoyalBox database (MySQL 8 / MariaDB 10.5+, InnoDB, utf8mb4)
-- phpMyAdmin: open the "Import" tab (no database selected), choose this file, click Import.
-- It creates the database itself and is SAFE TO IMPORT TWICE: existing tables are kept.
-- ============================================================================
CREATE DATABASE IF NOT EXISTS `loyalbox` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `loyalbox`;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(30)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  -- UNSIGNED: the database itself refuses a negative balance.
  points_balance INT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  sku         VARCHAR(40)  NOT NULL,
  description VARCHAR(500) NULL,
  points      INT UNSIGNED NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_batches (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL,
  note       VARCHAR(200) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_batch_admin   FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_codes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id   INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  -- HMAC-SHA256 of the normalised code. The plaintext code is never stored.
  code_hash  CHAR(64) NOT NULL,
  -- Last 4 characters only, so staff can identify a code on a damaged label.
  code_hint  CHAR(4) NOT NULL,
  status     ENUM('active','inactive','claimed') NOT NULL DEFAULT 'active',
  claimed_by INT UNSIGNED NULL,
  claimed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_codes_hash (code_hash),
  KEY ix_codes_batch_status (batch_id, status),
  KEY ix_codes_status (status),
  CONSTRAINT fk_code_batch   FOREIGN KEY (batch_id)   REFERENCES product_batches(id),
  CONSTRAINT fk_code_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_code_user    FOREIGN KEY (claimed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  code_id         BIGINT UNSIGNED NOT NULL,
  product_id      INT UNSIGNED NOT NULL,
  points_awarded  INT UNSIGNED NOT NULL,
  ip              VARCHAR(45) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Safety net #2: even if application logic failed, a code can be claimed once.
  UNIQUE KEY uq_claims_code (code_id),
  KEY ix_claims_user (user_id, created_at),
  CONSTRAINT fk_claim_user    FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_claim_code    FOREIGN KEY (code_id)    REFERENCES product_codes(id),
  CONSTRAINT fk_claim_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rewards (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  points_cost INT UNSIGNED NOT NULL,
  stock       INT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS redemptions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  reward_id    INT UNSIGNED NOT NULL,
  points_spent INT UNSIGNED NOT NULL,
  status       ENUM('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_red_user (user_id, created_at),
  CONSTRAINT fk_red_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_red_reward FOREIGN KEY (reward_id) REFERENCES rewards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only ledger: every balance change is one row. users.points_balance is a cache of SUM(points).
CREATE TABLE IF NOT EXISTS point_transactions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  type          ENUM('claim','redeem','refund') NOT NULL,
  points        INT NOT NULL,                 -- signed: + earn / refund, - spend
  balance_after INT UNSIGNED NOT NULL,
  ref_type      VARCHAR(20) NOT NULL,
  ref_id        BIGINT UNSIGNED NOT NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tx_ref (type, ref_type, ref_id),   -- one ledger row per business event
  KEY ix_tx_user (user_id, created_at),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(40) NOT NULL,   -- login_failed, admin_login_failed, claim_failed, claim_rate_limited, register ...
  user_id    INT UNSIGNED NULL,
  identifier VARCHAR(190) NULL,      -- e-mail used in a failed login
  ip         VARCHAR(45) NOT NULL,
  detail     VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_se_type_time (event_type, created_at),
  KEY ix_se_ip_time (ip, created_at),
  KEY ix_se_user_time (user_id, created_at),
  KEY ix_se_ident_time (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('admin','customer','system') NOT NULL,
  actor_id   INT UNSIGNED NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40) NULL,
  entity_id  BIGINT UNSIGNED NULL,
  detail     VARCHAR(500) NULL,
  ip         VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
