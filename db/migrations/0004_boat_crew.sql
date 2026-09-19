-- 0004_boat_crew : compartimenti, sistemi, equipaggio, dotazioni, avarie

ALTER TABLE uboat_types ADD COLUMN IF NOT EXISTS layout VARCHAR(8) NOT NULL DEFAULT 'VII' AFTER name;

ALTER TABLE boats ADD COLUMN IF NOT EXISTS hull_stress DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER hull_integrity;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS repair_focus VARCHAR(32) NULL AFTER hull_stress;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS watch_no TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER repair_focus;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS battle_stations TINYINT(1) NOT NULL DEFAULT 0 AFTER watch_no;

-- Compartimenti, da prua a poppa.
CREATE TABLE IF NOT EXISTS boat_compartments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id       BIGINT UNSIGNED NOT NULL,
  ckey          VARCHAR(24) NOT NULL,
  name          VARCHAR(48) NOT NULL,
  seq           TINYINT UNSIGNED NOT NULL,
  integrity     DECIMAL(5,2) NOT NULL DEFAULT 100,
  flooding      DECIMAL(5,2) NOT NULL DEFAULT 0,
  fire          DECIMAL(5,2) NOT NULL DEFAULT 0,
  sealed        TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_comp (boat_id, ckey),
  KEY idx_comp_boat (boat_id, seq),
  CONSTRAINT fk_comp_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sistemi: ognuno vive in un compartimento e puo' guastarsi.
CREATE TABLE IF NOT EXISTS boat_systems (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id          BIGINT UNSIGNED NOT NULL,
  skey             VARCHAR(32) NOT NULL,
  name             VARCHAR(64) NOT NULL,
  category         VARCHAR(24) NOT NULL,
  compartment      VARCHAR(24) NOT NULL,
  specialty        VARCHAR(24) NOT NULL,          -- specialita' che lo ripara
  condition_pct    DECIMAL(5,2) NOT NULL DEFAULT 100,
  state            ENUM('ok','avaria','distrutto') NOT NULL DEFAULT 'ok',
  repairable_sea   TINYINT(1) NOT NULL DEFAULT 1,
  repair_hours     DECIMAL(6,2) NOT NULL DEFAULT 2,   -- ore-uomo per la riparazione completa
  repair_progress  DECIMAL(6,2) NOT NULL DEFAULT 0,
  failure_rate     DECIMAL(8,6) NOT NULL DEFAULT 0.0005,  -- guasti per ora di esercizio
  last_failure_gts BIGINT NULL,
  note             VARCHAR(255) NULL,
  UNIQUE KEY uq_sys (boat_id, skey),
  KEY idx_sys_boat (boat_id, state),
  CONSTRAINT fk_sys_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipaggio.
CREATE TABLE IF NOT EXISTS crew_members (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id      BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(64) NOT NULL,
  rank_key     VARCHAR(32) NOT NULL,
  rank_name    VARCHAR(48) NOT NULL,
  role_key     VARCHAR(24) NOT NULL,              -- specialita'
  role_name    VARCHAR(48) NOT NULL,
  station      VARCHAR(24) NOT NULL,              -- compartimento di servizio
  watch_no     TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1-3, oppure 0 = fuori turno (ufficiali, cuoco)
  competence   DECIMAL(5,2) NOT NULL DEFAULT 50,
  fatigue      DECIMAL(5,2) NOT NULL DEFAULT 0,
  morale       DECIMAL(5,2) NOT NULL DEFAULT 70,
  health       ENUM('ok','ferito','grave','morto') NOT NULL DEFAULT 'ok',
  patrols      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  joined_gts   BIGINT NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  KEY idx_crew_boat (boat_id, role_key),
  KEY idx_crew_watch (boat_id, watch_no),
  CONSTRAINT fk_crew_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dotazioni di bordo: viveri, ricambi, cartucce di potassa, ossigeno, munizioni.
CREATE TABLE IF NOT EXISTS boat_stores (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id   BIGINT UNSIGNED NOT NULL,
  item_key  VARCHAR(24) NOT NULL,
  qty       DECIMAL(8,2) NOT NULL DEFAULT 0,
  qty_max   DECIMAL(8,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_store (boat_id, item_key),
  CONSTRAINT fk_store_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('crew.watch_hours',      '4',    'int',   'Durata di una guardia, in ore'),
  ('damage.rate_scale',     '1.0',  'float', 'Moltiplicatore globale della frequenza delle avarie'),
  ('damage.pressure_scale', '1.0',  'float', 'Moltiplicatore del danno da pressione oltre la quota di prova')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
