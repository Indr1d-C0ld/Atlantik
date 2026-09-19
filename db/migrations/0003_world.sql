-- 0003_world : mondo, tipi di U-Boot, porti, battelli, rotta, patrol, diario di bordo

CREATE TABLE IF NOT EXISTS world (
  id            TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  seed          BIGINT NOT NULL,
  epoch_real_ts BIGINT NOT NULL,          -- istante reale d'inizio campagna
  epoch_game_ts BIGINT NOT NULL,          -- istante di gioco corrispondente
  time_ratio    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note          VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipi di U-Boot. Ogni riga porta la fonte e il grado di affidabilita' del dato.
CREATE TABLE IF NOT EXISTS uboat_types (
  type_key           VARCHAR(16) NOT NULL PRIMARY KEY,
  name               VARCHAR(48) NOT NULL,
  soprannome         VARCHAR(48) NULL,
  disp_surf_t        SMALLINT UNSIGNED NOT NULL,
  disp_sub_t         SMALLINT UNSIGNED NOT NULL,
  speed_surf_kn      DECIMAL(4,1) NOT NULL,
  speed_sub_kn       DECIMAL(4,1) NOT NULL,
  fuel_t             DECIMAL(6,1) NOT NULL,
  range1_nm          MEDIUMINT UNSIGNED NOT NULL,   -- autonomia di riferimento
  range1_kn          DECIMAL(4,1) NOT NULL,
  range2_nm          MEDIUMINT UNSIGNED NULL,       -- secondo punto, se documentato
  range2_kn          DECIMAL(4,1) NULL,
  sub_range_nm       SMALLINT UNSIGNED NOT NULL,    -- autonomia in immersione
  sub_range_kn       DECIMAL(4,1) NOT NULL,
  sub_max_hours      DECIMAL(4,2) NOT NULL,         -- ore alla massima velocita' subacquea
  test_depth_m       SMALLINT UNSIGNED NOT NULL,
  crush_depth_min_m  SMALLINT UNSIGNED NOT NULL,
  crush_depth_max_m  SMALLINT UNSIGNED NOT NULL,
  dive_time_s        SMALLINT UNSIGNED NOT NULL,    -- immersione rapida
  crew_min           TINYINT UNSIGNED NOT NULL,
  crew_max           TINYINT UNSIGNED NOT NULL,
  torpedoes          TINYINT UNSIGNED NOT NULL,
  tubes_bow          TINYINT UNSIGNED NOT NULL,
  tubes_stern        TINYINT UNSIGNED NOT NULL,
  deck_gun           VARCHAR(32) NULL,
  provisions_days    TINYINT UNSIGNED NOT NULL,
  unlock_rank        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  playable           TINYINT(1) NOT NULL DEFAULT 1,
  fonte              VARCHAR(255) NOT NULL,
  confidence         ENUM('alta','media','bassa') NOT NULL DEFAULT 'media',
  note               TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ports (
  port_key   VARCHAR(24) NOT NULL PRIMARY KEY,
  name       VARCHAR(64) NOT NULL,
  country    VARCHAR(48) NOT NULL,
  lat        DECIMAL(8,5) NOT NULL,
  lon        DECIMAL(9,5) NOT NULL,
  kind       ENUM('base','alleato','neutro') NOT NULL DEFAULT 'alleato',
  flotillas  VARCHAR(128) NULL,
  note       VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boats (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  type_key          VARCHAR(16) NOT NULL,
  uboat_number      VARCHAR(12) NOT NULL,           -- "U-96"
  flotilla          VARCHAR(48) NOT NULL,
  home_port_key     VARCHAR(24) NOT NULL,
  state             ENUM('base','mare','perduto') NOT NULL DEFAULT 'base',
  -- posizione vera
  lat               DECIMAL(8,5) NOT NULL DEFAULT 0,
  lon               DECIMAL(9,5) NOT NULL DEFAULT 0,
  heading           DECIMAL(5,1) NOT NULL DEFAULT 0,
  speed_kn          DECIMAL(4,1) NOT NULL DEFAULT 0,
  ordered_speed_kn  DECIMAL(4,1) NOT NULL DEFAULT 0,
  depth_m           DECIMAL(6,1) NOT NULL DEFAULT 0,
  ordered_depth_m   DECIMAL(6,1) NOT NULL DEFAULT 0,
  mode              ENUM('superficie','periscopio','immersione') NOT NULL DEFAULT 'superficie',
  silent            TINYINT(1) NOT NULL DEFAULT 0,
  -- posizione stimata dall'Obersteuermann (quella che il comandante crede)
  est_lat           DECIMAL(8,5) NOT NULL DEFAULT 0,
  est_lon           DECIMAL(9,5) NOT NULL DEFAULT 0,
  est_error_nm      DECIMAL(6,2) NOT NULL DEFAULT 0,
  last_fix_gts      BIGINT NULL,
  -- risorse
  fuel_t            DECIMAL(6,2) NOT NULL DEFAULT 0,
  battery_pct       DECIMAL(5,2) NOT NULL DEFAULT 100,
  air_pct           DECIMAL(5,2) NOT NULL DEFAULT 100,
  co2_pct           DECIMAL(5,2) NOT NULL DEFAULT 0,
  provisions_days   DECIMAL(5,2) NOT NULL DEFAULT 0,
  hull_integrity    DECIMAL(5,2) NOT NULL DEFAULT 100,
  submerged_since   BIGINT NULL,
  last_sim_gts      BIGINT NOT NULL DEFAULT 0,
  version           INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_boat_number (uboat_number),
  KEY idx_boats_user (user_id),
  KEY idx_boats_state (state),
  CONSTRAINT fk_boats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_boats_type FOREIGN KEY (type_key) REFERENCES uboat_types(type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boat_waypoints (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id     BIGINT UNSIGNED NOT NULL,
  seq         SMALLINT UNSIGNED NOT NULL,
  lat         DECIMAL(8,5) NOT NULL,
  lon         DECIMAL(9,5) NOT NULL,
  label       VARCHAR(48) NULL,
  reached_gts BIGINT NULL,
  KEY idx_wp_boat (boat_id, seq),
  CONSTRAINT fk_wp_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patrols (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  boat_id        BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  number         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  base_key       VARCHAR(24) NOT NULL,
  area_quadrat   VARCHAR(12) NULL,
  departed_gts   BIGINT NOT NULL,
  returned_gts   BIGINT NULL,
  state          ENUM('in_corso','conclusa','perduta') NOT NULL DEFAULT 'in_corso',
  distance_nm    DECIMAL(9,2) NOT NULL DEFAULT 0,
  surfaced_nm    DECIMAL(9,2) NOT NULL DEFAULT 0,
  submerged_nm   DECIMAL(9,2) NOT NULL DEFAULT 0,
  fuel_used_t    DECIMAL(7,2) NOT NULL DEFAULT 0,
  max_depth_m    DECIMAL(6,1) NOT NULL DEFAULT 0,
  note           VARCHAR(255) NULL,
  KEY idx_patrol_boat (boat_id),
  KEY idx_patrol_user (user_id),
  CONSTRAINT fk_patrol_boat FOREIGN KEY (boat_id) REFERENCES boats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kriegstagebuch: il giornale di guerra. Solo aggiunte, mai modifiche.
CREATE TABLE IF NOT EXISTS patrol_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  patrol_id  BIGINT UNSIGNED NOT NULL,
  boat_id    BIGINT UNSIGNED NOT NULL,
  gts        BIGINT NOT NULL,
  kind       VARCHAR(32) NOT NULL,
  severity   ENUM('info','nota','attenzione','allarme') NOT NULL DEFAULT 'info',
  lat        DECIMAL(8,5) NULL,
  lon        DECIMAL(9,5) NULL,
  quadrat    VARCHAR(12) NULL,
  text       VARCHAR(500) NOT NULL,
  meta       JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ev_patrol (patrol_id, gts),
  KEY idx_ev_boat (boat_id, gts),
  CONSTRAINT fk_ev_patrol FOREIGN KEY (patrol_id) REFERENCES patrols(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('world.substep_s',       '300', 'int', 'Sotto-passo della simulazione di crociera, in secondi di gioco'),
  ('world.max_catchup_h',   '72',  'int', 'Massimo recupero in una sola volta, in ore di gioco'),
  ('nav.fix_interval_h',    '8',   'int', 'Intervallo minimo fra due punti nave astronomici'),
  ('limits.actions_per_min','120', 'int', 'Azioni non di sola lettura al minuto')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
