-- 0009_carriera : comandanti, gradi, decorazioni, prestigio, albo d'oro

CREATE TABLE IF NOT EXISTS commanders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  nome           VARCHAR(64) NOT NULL,
  nato_il        DATE NULL,
  nato_a         VARCHAR(64) NULL,
  ritratto       VARCHAR(24) NOT NULL DEFAULT 'r1',
  flottiglia     VARCHAR(64) NOT NULL,
  base_key       VARCHAR(24) NOT NULL,
  grado          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  prestigio      INT NOT NULL DEFAULT 0,
  prestigio_tot  INT NOT NULL DEFAULT 0,
  punti          INT NOT NULL DEFAULT 0,          -- Zuteilungspunkte da spendere
  reichsmark     INT NOT NULL DEFAULT 0,
  patrols        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  affondate      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grt_affondato  INT UNSIGNED NOT NULL DEFAULT 0,
  giorni_mare    DECIMAL(7,2) NOT NULL DEFAULT 0,
  stato          ENUM('attivo','disperso','prigioniero','congedato','caduto') NOT NULL DEFAULT 'attivo',
  entrato_gts    BIGINT NOT NULL DEFAULT 0,
  uscito_gts     BIGINT NULL,
  sorte          VARCHAR(255) NULL,
  ultimo_quadrat VARCHAR(12) NULL,
  KEY idx_cmd_user (user_id, stato),
  KEY idx_cmd_grt (grt_affondato),
  CONSTRAINT fk_cmd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE boats ADD COLUMN IF NOT EXISTS commander_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS commander_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS prestigio INT NOT NULL DEFAULT 0;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS punti INT NOT NULL DEFAULT 0;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS rapporto TEXT NULL;
ALTER TABLE sinkings ADD COLUMN IF NOT EXISTS commander_id BIGINT UNSIGNED NULL AFTER patrol_id;

CREATE TABLE IF NOT EXISTS award_types (
  akey        VARCHAR(32) NOT NULL PRIMARY KEY,
  nome        VARCHAR(96) NOT NULL,
  nome_it     VARCHAR(96) NOT NULL,
  ordine      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  richiede    VARCHAR(32) NULL,                   -- decorazione precedente necessaria
  min_patrols TINYINT UNSIGNED NOT NULL DEFAULT 0,
  min_grt     INT UNSIGNED NOT NULL DEFAULT 0,
  min_navi    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fonte       VARCHAR(255) NOT NULL,
  confidence  ENUM('alta','media','bassa') NOT NULL DEFAULT 'media',
  note        TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS awards (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  commander_id BIGINT UNSIGNED NOT NULL,
  akey         VARCHAR(32) NOT NULL,
  gts          BIGINT NOT NULL,
  patrol_id    BIGINT UNSIGNED NULL,
  motivazione  VARCHAR(500) NOT NULL,
  UNIQUE KEY uq_award (commander_id, akey),
  CONSTRAINT fk_award_cmd FOREIGN KEY (commander_id) REFERENCES commanders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upgrade_types (
  ukey        VARCHAR(32) NOT NULL PRIMARY KEY,
  nome        VARCHAR(96) NOT NULL,
  categoria   VARCHAR(24) NOT NULL,
  costo       SMALLINT UNSIGNED NOT NULL,
  unlock_rank TINYINT UNSIGNED NOT NULL DEFAULT 0,
  effetto     VARCHAR(32) NOT NULL,
  valore      DECIMAL(6,3) NOT NULL DEFAULT 1,
  storico     VARCHAR(64) NULL,                    -- in servizio dal...
  fonte       VARCHAR(255) NOT NULL,
  confidence  ENUM('alta','media','bassa') NOT NULL DEFAULT 'media',
  note        TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boat_upgrades (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id    BIGINT UNSIGNED NOT NULL,
  ukey       VARCHAR(32) NOT NULL,
  gts        BIGINT NOT NULL,
  UNIQUE KEY uq_boat_upg (boat_id, ukey),
  CONSTRAINT fk_upg_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('carriera.prestigio_per_100grt', '1.0',  'float', 'Prestigio per ogni 100 GRT affondati'),
  ('carriera.punti_per_patrol',     '25',   'int',   'Punti di assegnazione per patrol conclusa'),
  ('carriera.eredita_pct',          '25',   'int',   'Percentuale di prestigio ereditata dal comandante successivo'),
  ('carriera.rm_per_patrol',        '900',  'int',   'Reichsmark di paga per patrol conclusa')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
