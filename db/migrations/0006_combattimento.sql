-- 0006_combattimento : siluri, incontri tattici, lanci, affondamenti

CREATE TABLE IF NOT EXISTS torpedo_types (
  tkey            VARCHAR(16) NOT NULL PRIMARY KEY,
  name            VARCHAR(64) NOT NULL,
  sigla           VARCHAR(16) NOT NULL,
  propulsione     ENUM('vapore','elettrico','acustico') NOT NULL,
  scia            TINYINT(1) NOT NULL DEFAULT 0,          -- lascia scia visibile
  warhead_kg      SMALLINT UNSIGNED NOT NULL,
  -- fino a tre regolazioni di velocita'/gittata
  v1_kn           DECIMAL(4,1) NOT NULL, r1_m MEDIUMINT UNSIGNED NOT NULL,
  v2_kn           DECIMAL(4,1) NULL,     r2_m MEDIUMINT UNSIGNED NULL,
  v3_kn           DECIMAL(4,1) NULL,     r3_m MEDIUMINT UNSIGNED NULL,
  guida           ENUM('dritto','fat','lut','acustico') NOT NULL DEFAULT 'dritto',
  p_cilecca       DECIMAL(5,4) NOT NULL DEFAULT 0.08,     -- spoletta che non funziona
  p_prematura     DECIMAL(5,4) NOT NULL DEFAULT 0.03,     -- scoppio anticipato
  p_quota_errata  DECIMAL(5,4) NOT NULL DEFAULT 0.06,     -- corsa troppo profonda
  unlock_rank     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fonte           VARCHAR(255) NOT NULL,
  confidence      ENUM('alta','media','bassa') NOT NULL DEFAULT 'media',
  note            TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Siluri imbarcati: quali, dove, e in che stato.
CREATE TABLE IF NOT EXISTS boat_torpedoes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id     BIGINT UNSIGNED NOT NULL,
  tkey        VARCHAR(16) NOT NULL,
  posizione   ENUM('tubo_prua','tubo_poppa','riserva_interna','riserva_esterna') NOT NULL,
  tubo        TINYINT UNSIGNED NULL,          -- 1-4 prua, 5 poppa
  stato       ENUM('pronto','in_carica','lanciato','guasto') NOT NULL DEFAULT 'pronto',
  ricarica_fine_gts BIGINT NULL,
  KEY idx_tor_boat (boat_id, stato),
  CONSTRAINT fk_tor_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Incontro tattico: quando il mondo rallenta e si combatte.
CREATE TABLE IF NOT EXISTS encounters (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id        BIGINT UNSIGNED NOT NULL,
  patrol_id      BIGINT UNSIGNED NULL,
  convoy_id      BIGINT UNSIGNED NULL,
  ship_id        BIGINT UNSIGNED NULL,
  stato          ENUM('avvicinamento','attacco','evasione','concluso') NOT NULL DEFAULT 'avvicinamento',
  allarme        TINYINT(1) NOT NULL DEFAULT 0,      -- il nemico sa che ci siamo
  started_gts    BIGINT NOT NULL,
  last_step_gts  BIGINT NOT NULL,
  ended_gts      BIGINT NULL,
  finestra_fine  BIGINT NOT NULL,                    -- scadenza reale della condotta del comandante
  ratio          SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  esito          VARCHAR(255) NULL,
  affondate      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grt_affondato  INT UNSIGNED NOT NULL DEFAULT 0,
  cariche_subite SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_enc_boat (boat_id, stato),
  CONSTRAINT fk_enc_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unita' presenti nell'incontro, con posizione vera e manovra.
CREATE TABLE IF NOT EXISTS encounter_entities (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  encounter_id  BIGINT UNSIGNED NOT NULL,
  ship_id       BIGINT UNSIGNED NULL,
  class_key     VARCHAR(32) NOT NULL,
  name          VARCHAR(64) NOT NULL,
  ruolo         ENUM('bersaglio','scorta','mercantile') NOT NULL DEFAULT 'mercantile',
  lat           DECIMAL(8,5) NOT NULL,
  lon           DECIMAL(9,5) NOT NULL,
  heading       DECIMAL(5,1) NOT NULL,
  speed_kn      DECIMAL(4,1) NOT NULL,
  grt           MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  integrita     DECIMAL(5,2) NOT NULL DEFAULT 100,
  allagamento   DECIMAL(5,2) NOT NULL DEFAULT 0,
  incendio      DECIMAL(5,2) NOT NULL DEFAULT 0,
  stato         ENUM('in_mare','danneggiata','affonda','affondata','fuggita') NOT NULL DEFAULT 'in_mare',
  affonda_gts   BIGINT NULL,
  -- comportamento delle scorte
  contatto      DECIMAL(4,3) NOT NULL DEFAULT 0,     -- quanto ci ha in mano (0-1)
  manovra       VARCHAR(24) NOT NULL DEFAULT 'rotta',
  dc_residue    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_attacco_gts BIGINT NULL,
  KEY idx_ent_enc (encounter_id, ruolo),
  CONSTRAINT fk_ent_enc FOREIGN KEY (encounter_id) REFERENCES encounters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Siluri in corsa.
CREATE TABLE IF NOT EXISTS torpedo_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  encounter_id  BIGINT UNSIGNED NOT NULL,
  boat_id       BIGINT UNSIGNED NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  tkey          VARCHAR(16) NOT NULL,
  tubo          TINYINT UNSIGNED NOT NULL,
  lanciato_gts  BIGINT NOT NULL,
  lat           DECIMAL(8,5) NOT NULL,
  lon           DECIMAL(9,5) NOT NULL,
  heading       DECIMAL(5,1) NOT NULL,
  speed_kn      DECIMAL(4,1) NOT NULL,
  quota_m       DECIMAL(4,1) NOT NULL DEFAULT 3,
  spoletta      ENUM('contatto','magnetica') NOT NULL DEFAULT 'contatto',
  corsa_max_m   MEDIUMINT UNSIGNED NOT NULL,
  percorso_m    MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  esito         ENUM('in_corsa','colpito','mancato','cilecca','prematuro','esaurito') NOT NULL DEFAULT 'in_corsa',
  esito_gts     BIGINT NULL,
  nota          VARCHAR(255) NULL,
  KEY idx_run_enc (encounter_id, esito),
  CONSTRAINT fk_run_enc FOREIGN KEY (encounter_id) REFERENCES encounters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Albo degli affondamenti: la contabilita' della guerra al tonnellaggio.
CREATE TABLE IF NOT EXISTS sinkings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id      BIGINT UNSIGNED NOT NULL,
  patrol_id    BIGINT UNSIGNED NULL,
  ship_id      BIGINT UNSIGNED NULL,
  nome         VARCHAR(64) NOT NULL,
  bandiera     VARCHAR(24) NOT NULL,
  class_key    VARCHAR(32) NOT NULL,
  grt          MEDIUMINT UNSIGNED NOT NULL,
  carico       VARCHAR(32) NULL,
  arma         ENUM('siluro','cannone','siluro_e_cannone') NOT NULL DEFAULT 'siluro',
  siluri_usati TINYINT UNSIGNED NOT NULL DEFAULT 0,
  convoglio    VARCHAR(16) NULL,
  gts          BIGINT NOT NULL,
  lat          DECIMAL(8,5) NOT NULL,
  lon          DECIMAL(9,5) NOT NULL,
  quadrat      VARCHAR(12) NULL,
  danneggiata  TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_sink_boat (boat_id, gts),
  KEY idx_sink_patrol (patrol_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE boats ADD COLUMN IF NOT EXISTS encounter_id BIGINT UNSIGNED NULL AFTER battle_stations;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS affondate SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS grt_affondato INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE patrols ADD COLUMN IF NOT EXISTS siluri_lanciati SMALLINT UNSIGNED NOT NULL DEFAULT 0;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('combat.finestra_min',   '25',  'int',   'Minuti reali di condotta concessi al comandante per un incontro'),
  ('combat.ratio_attacco',  '1',   'int',   'Rapporto di compressione durante l''attacco'),
  ('combat.ratio_avvicin',  '4',   'int',   'Rapporto di compressione in avvicinamento'),
  ('combat.passo_s',        '10',  'int',   'Passo della simulazione tattica, in secondi di gioco'),
  ('combat.raggio_nm',      '10',  'float', 'Distanza entro cui un contatto puo'' diventare un incontro'),
  ('combat.qualita_siluri', '1.0', 'float', 'Moltiplicatore dei difetti dei siluri (lotti di magazzino)')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
