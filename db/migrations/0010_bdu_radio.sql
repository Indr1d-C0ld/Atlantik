-- 0010_bdu_radio : BdU, ordini, radio e HF/DF, branchi, rifornimento, bacheca

CREATE TABLE IF NOT EXISTS bdu_orders (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id       BIGINT UNSIGNED NULL,           -- NULL = ordine generale
  commander_id  BIGINT UNSIGNED NULL,
  wolfpack_id   BIGINT UNSIGNED NULL,
  tipo          ENUM('area','pedinamento','rifornimento','rientro','meteo','generale') NOT NULL,
  quadrat       VARCHAR(12) NULL,
  lat           DECIMAL(8,5) NULL,
  lon           DECIMAL(9,5) NULL,
  testo         VARCHAR(500) NOT NULL,
  emesso_gts    BIGINT NOT NULL,
  scade_gts     BIGINT NULL,
  stato         ENUM('aperto','accettato','assolto','scaduto','rifiutato') NOT NULL DEFAULT 'aperto',
  prestigio     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  punti         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_ord_boat (boat_id, stato),
  KEY idx_ord_tipo (tipo, emesso_gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radio_messages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id      BIGINT UNSIGNED NULL,            -- mittente (NULL = BdU)
  commander_id BIGINT UNSIGNED NULL,
  destinatario ENUM('bdu','branco','tutti','battello') NOT NULL DEFAULT 'bdu',
  dest_boat_id BIGINT UNSIGNED NULL,
  wolfpack_id  BIGINT UNSIGNED NULL,
  tipo         ENUM('kurzsignal','rapporto','meteo','ordine','comunicato','contatto') NOT NULL,
  testo        VARCHAR(500) NOT NULL,
  quadrat      VARCHAR(12) NULL,
  lat          DECIMAL(8,5) NULL,
  lon          DECIMAL(9,5) NULL,
  gts          BIGINT NOT NULL,
  durata_s     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  intercettato TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_radio_boat (boat_id, gts),
  KEY idx_radio_dest (destinatario, gts),
  KEY idx_radio_pack (wolfpack_id, gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rilevamenti radiogoniometrici alleati sulle nostre trasmissioni.
CREATE TABLE IF NOT EXISTS hfdf_fixes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id     BIGINT UNSIGNED NOT NULL,
  message_id  BIGINT UNSIGNED NULL,
  gts         BIGINT NOT NULL,
  rilevamenti TINYINT UNSIGNED NOT NULL DEFAULT 1,
  errore_nm   DECIMAL(6,2) NOT NULL,
  lat         DECIMAL(8,5) NOT NULL,
  lon         DECIMAL(9,5) NOT NULL,
  quadrat     VARCHAR(12) NULL,
  reazione    VARCHAR(255) NULL,
  KEY idx_hfdf_boat (boat_id, gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wolfpacks (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(48) NOT NULL,
  quadrat      VARCHAR(12) NOT NULL,
  lat          DECIMAL(8,5) NOT NULL,
  lon          DECIMAL(9,5) NOT NULL,
  aperto_gts   BIGINT NOT NULL,
  chiude_gts   BIGINT NOT NULL,
  stato        ENUM('raccolta','operativo','sciolto') NOT NULL DEFAULT 'raccolta',
  convoy_id    BIGINT UNSIGNED NULL,
  affondate    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grt          INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_pack_stato (stato, chiude_gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wolfpack_members (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wolfpack_id  BIGINT UNSIGNED NOT NULL,
  boat_id      BIGINT UNSIGNED NOT NULL,
  commander_id BIGINT UNSIGNED NULL,
  entrato_gts  BIGINT NOT NULL,
  uscito_gts   BIGINT NULL,
  contatti     SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- segnalazioni utili agli altri
  affondate    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grt          INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_membro (wolfpack_id, boat_id),
  KEY idx_membro_boat (boat_id),
  CONSTRAINT fk_membro_pack FOREIGN KEY (wolfpack_id) REFERENCES wolfpacks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appuntamenti col battello cisterna.
CREATE TABLE IF NOT EXISTS rendezvous (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id       BIGINT UNSIGNED NOT NULL,
  quadrat       VARCHAR(12) NOT NULL,
  lat           DECIMAL(8,5) NOT NULL,
  lon           DECIMAL(9,5) NOT NULL,
  apertura_gts  BIGINT NOT NULL,
  scadenza_gts  BIGINT NOT NULL,
  stato         ENUM('fissato','concluso','mancato','compromesso') NOT NULL DEFAULT 'fissato',
  nafta_t       DECIMAL(6,1) NOT NULL DEFAULT 0,
  siluri        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  note          VARCHAR(255) NULL,
  KEY idx_rdv_boat (boat_id, stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bacheca della mensa ufficiali: qui si parla liberamente, ma solo a terra.
CREATE TABLE IF NOT EXISTS bacheca (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  commander_id BIGINT UNSIGNED NULL,
  flottiglia   VARCHAR(64) NOT NULL,
  testo        VARCHAR(1000) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bacheca_data (created_at),
  CONSTRAINT fk_bacheca_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE boats ADD COLUMN IF NOT EXISTS wolfpack_id BIGINT UNSIGNED NULL AFTER commander_id;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS radio_ultima_gts BIGINT NULL AFTER wolfpack_id;
ALTER TABLE commanders ADD COLUMN IF NOT EXISTS segnalazioni SMALLINT UNSIGNED NOT NULL DEFAULT 0;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('radio.hfdf_raggio_nm',   '160', 'int',   'Raggio entro cui una scorta con HF/DF sente la trasmissione'),
  ('radio.hfdf_costiero',    '0.35','float', 'Probabilita'' che le stazioni costiere alleate prendano il rilevamento'),
  ('radio.heat_per_fix',     '18',  'float', 'Calore aggiunto al settore per ogni punto radiogoniometrico'),
  ('branco.durata_ore',      '72',  'int',   'Durata in ore reali della finestra operativa di un branco'),
  ('branco.premio_contatto', '180', 'int',   'Prestigio per una segnalazione di contatto utile al branco'),
  ('rifornimento.ore_min',   '4',   'int',   'Ore di gioco necessarie al trasferimento in mare')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
