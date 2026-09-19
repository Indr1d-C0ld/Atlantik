-- 0005_traffic : naviglio alleato, convogli, settori, contatti

CREATE TABLE IF NOT EXISTS ship_classes (
  class_key     VARCHAR(32) NOT NULL PRIMARY KEY,
  name          VARCHAR(64) NOT NULL,
  kind          ENUM('mercantile','petroliera','trasporto','scorta','ausiliaria','aereo') NOT NULL,
  grt           MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  speed_kn      DECIMAL(4,1) NOT NULL,
  length_m      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  eliche        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  armata        TINYINT(1) NOT NULL DEFAULT 0,
  asdic         TINYINT(1) NOT NULL DEFAULT 0,
  radar         TINYINT(1) NOT NULL DEFAULT 0,
  hfdf          TINYINT(1) NOT NULL DEFAULT 0,
  dc_carica     SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- cariche di profondita' imbarcate
  rumore_db     DECIMAL(5,1) NOT NULL DEFAULT 140,      -- livello di sorgente a velocita' di crociera
  fonte         VARCHAR(255) NOT NULL,
  confidence    ENUM('alta','media','bassa') NOT NULL DEFAULT 'media',
  note          VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convoys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serie         VARCHAR(8) NOT NULL,          -- HX, SC, ON, ONS, OG, HG, SL, TM
  numero        SMALLINT UNSIGNED NOT NULL,
  rotta_key     VARCHAR(24) NOT NULL,
  speed_kn      DECIMAL(4,1) NOT NULL,
  colonne       TINYINT UNSIGNED NOT NULL DEFAULT 9,
  departed_gts  BIGINT NOT NULL,
  eta_gts       BIGINT NOT NULL,
  state         ENUM('in_mare','arrivato','disperso') NOT NULL DEFAULT 'in_mare',
  zigzag        TINYINT(1) NOT NULL DEFAULT 1,
  deviazione    DECIMAL(5,2) NOT NULL DEFAULT 0,   -- gradi di deviazione dalla rotta base
  navi_iniziali SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  affondate     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_convoglio (serie, numero),
  KEY idx_convoy_state (state, departed_gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Naviglio: sia le navi isolate sia quelle inquadrate in convoglio.
CREATE TABLE IF NOT EXISTS ships (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(64) NOT NULL,
  flag          VARCHAR(24) NOT NULL,
  class_key     VARCHAR(32) NOT NULL,
  convoy_id     BIGINT UNSIGNED NULL,
  colonna       TINYINT UNSIGNED NULL,
  fila          TINYINT UNSIGNED NULL,
  ruolo         ENUM('mercantile','commodoro','soccorso','scorta') NOT NULL DEFAULT 'mercantile',
  rotta_key     VARCHAR(24) NOT NULL,
  speed_kn      DECIMAL(4,1) NOT NULL,
  departed_gts  BIGINT NOT NULL,
  eta_gts       BIGINT NOT NULL,
  grt           MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  carico        VARCHAR(32) NULL,
  state         ENUM('in_mare','arrivata','affondata','danneggiata') NOT NULL DEFAULT 'in_mare',
  sunk_gts      BIGINT NULL,
  sunk_by       BIGINT UNSIGNED NULL,
  lat_ultima    DECIMAL(8,5) NULL,
  lon_ultima    DECIMAL(9,5) NULL,
  KEY idx_ships_convoy (convoy_id),
  KEY idx_ships_state (state, departed_gts),
  KEY idx_ships_rotta (rotta_key, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calore di settore: la reazione alleata cresce dove si combatte.
CREATE TABLE IF NOT EXISTS sectors (
  quadrat     VARCHAR(8) NOT NULL PRIMARY KEY,   -- grande quadrato + prima cifra (es. "BF 3")
  heat        DECIMAL(5,2) NOT NULL DEFAULT 0,
  aria_base   DECIMAL(5,3) NOT NULL DEFAULT 0,   -- copertura aerea propria della zona
  updated_gts BIGINT NOT NULL DEFAULT 0,
  note        VARCHAR(128) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contatti: soggettivi, appartengono al battello che li ha rilevati.
CREATE TABLE IF NOT EXISTS contacts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id       BIGINT UNSIGNED NOT NULL,
  patrol_id     BIGINT UNSIGNED NULL,
  target_kind   ENUM('nave','convoglio','aereo','sconosciuto') NOT NULL DEFAULT 'sconosciuto',
  ship_id       BIGINT UNSIGNED NULL,
  convoy_id     BIGINT UNSIGNED NULL,
  sensore       ENUM('vista','idrofono','radar','fumo','radiogoniometro') NOT NULL,
  first_gts     BIGINT NOT NULL,
  last_gts      BIGINT NOT NULL,
  bearing       DECIMAL(5,1) NOT NULL,
  range_nm      DECIMAL(6,2) NULL,
  range_err_nm  DECIMAL(6,2) NOT NULL DEFAULT 0,
  course_est    DECIMAL(5,1) NULL,
  speed_est     DECIMAL(4,1) NULL,
  classe_est    VARCHAR(64) NULL,
  certezza      DECIMAL(4,3) NOT NULL DEFAULT 0.3,
  navi_stimate  SMALLINT UNSIGNED NULL,
  perso         TINYINT(1) NOT NULL DEFAULT 0,
  lat_est       DECIMAL(8,5) NULL,
  lon_est       DECIMAL(9,5) NULL,
  KEY idx_contact_boat (boat_id, perso, last_gts),
  KEY idx_contact_target (ship_id, convoy_id),
  CONSTRAINT fk_contact_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('traffic.convogli_attivi', '10',  'int',   'Convogli in mare da tenere contemporaneamente'),
  ('traffic.isolate_attive',  '55',  'int',   'Navi isolate in mare da tenere contemporaneamente'),
  ('traffic.scala',           '1.0', 'float', 'Moltiplicatore globale della densita'' del traffico'),
  ('heat.decadimento_giorno', '6.0', 'float', 'Punti di calore smaltiti al giorno da un settore'),
  ('detect.scala_vista',      '1.0', 'float', 'Moltiplicatore della portata visiva'),
  ('detect.scala_idrofono',   '1.0', 'float', 'Moltiplicatore della sensibilita'' idrofonica')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
