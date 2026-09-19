-- 0001_core_auth : account, verifica e-mail, rate limit, audit, esecuzioni del tick
-- (schema_migrations e' gestita dal Migratore, non qui)

CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(32)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  status            ENUM('pending','active','suspended','banned') NOT NULL DEFAULT 'pending',
  role              ENUM('player','moderator','admin') NOT NULL DEFAULT 'player',
  email_verified_at DATETIME NULL,
  verify_sent_at    DATETIME NULL,
  verify_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  admin_notified_at DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at     DATETIME NULL,
  last_login_ip     VARBINARY(16) NULL,
  last_seen_at      DATETIME NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gettoni monouso: verifica e-mail e reimpostazione password.
-- In tabella finisce solo l'hash del gettone: chi legge il DB non puo' usarlo.
CREATE TABLE IF NOT EXISTS user_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  kind       ENUM('verify_email','reset_password') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip VARBINARY(16) NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_user (user_id, kind),
  KEY idx_token_expires (expires_at),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rkey     VARCHAR(190) NOT NULL PRIMARY KEY,
  hits     INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at DATETIME NOT NULL,
  KEY idx_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(64) NOT NULL,
  target_type   VARCHAR(32) NULL,
  target_id     BIGINT UNSIGNED NULL,
  meta          JSON NULL,
  ip            VARBINARY(16) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Impostazioni di gioco modificabili a caldo (le chiavi di bilanciamento).
CREATE TABLE IF NOT EXISTS game_config (
  ckey       VARCHAR(64) NOT NULL PRIMARY KEY,
  cvalue     TEXT NOT NULL,
  ctype      ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  note       VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diario delle esecuzioni del tick: serve a capire se il mondo sta girando.
CREATE TABLE IF NOT EXISTS tick_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME(3) NOT NULL,
  finished_at DATETIME(3) NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  tasks       JSON NULL,
  note        VARCHAR(255) NULL,
  KEY idx_tick_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('auth.min_password_length', '10', 'int',  'Lunghezza minima della password'),
  ('auth.registration_open',   '1',  'bool', 'Registrazioni aperte'),
  ('auth.verify_ttl_hours',    '48', 'int',  'Validita'' del collegamento di verifica, in ore'),
  ('world.time_ratio',         '30', 'int',  'Minuti di gioco per minuto reale (crociera)')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
