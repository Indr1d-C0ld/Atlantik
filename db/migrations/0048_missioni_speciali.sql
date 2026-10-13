-- 0048: le missioni speciali
--
-- Non tutte le uscite erano caccia al tonnellaggio. Il BdU mandava battelli a
-- posare mine davanti ai porti nemici (Halifax, New York, Reykjavik, Freetown:
-- tre mine TMB nello spazio di un siluro), a fare da battello meteorologico —
-- un rapporto ogni poche ore da un quadrato fisso, per la Luftwaffe e per gli
-- altri U-Boot, pagato in rischio radiogoniometrico —, e a sbarcare agenti
-- dell'Abwehr su una costa, di notte, a motori fermi.
--
--   - missioni_speciali: le offerte del BdU e quelle accettate, con l'obiettivo
--     (mine da posare, rapporti da mandare) e il premio;
--   - campi_minati: quello che resta in mare dopo una posa. Lavora per
--     settimane sul traffico che passa, e ogni nave che salta e' accreditata a
--     chi l'ha posato (ships.danno_arma = 'mina', sinkings.arma = 'mina');
--   - campi_minati_passaggi: ogni nave rischia una volta per campo.

CREATE TABLE IF NOT EXISTS missioni_speciali (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  commander_id  BIGINT UNSIGNED NOT NULL,
  boat_id       BIGINT UNSIGNED NOT NULL,
  patrol_id     BIGINT UNSIGNED NULL,
  tipo          ENUM('mine','meteo','agenti') NOT NULL,
  stato         ENUM('offerta','accettata','in_corso','compiuta','fallita','scaduta') NOT NULL DEFAULT 'offerta',
  luogo         VARCHAR(80) NOT NULL,
  lat           DECIMAL(8,5) NOT NULL,
  lon           DECIMAL(9,5) NOT NULL,
  quadrat       VARCHAR(12) NULL,
  obiettivo     SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Mine da posare, rapporti da mandare, agenti da sbarcare',
  fatto         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_gts    BIGINT NULL COMMENT 'Ultimo rapporto meteo contato',
  premio        INT NOT NULL DEFAULT 0,
  punti         INT NOT NULL DEFAULT 0,
  offerta_gts   BIGINT NOT NULL,
  scade_gts     BIGINT NOT NULL,
  chiusa_gts    BIGINT NULL,
  KEY idx_missioni_cmd (commander_id, stato),
  KEY idx_missioni_boat (boat_id, stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campi_minati (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  missione_id   BIGINT UNSIGNED NULL,
  boat_id       BIGINT UNSIGNED NULL,
  commander_id  BIGINT UNSIGNED NULL,
  patrol_id     BIGINT UNSIGNED NULL,
  luogo         VARCHAR(80) NOT NULL,
  lat           DECIMAL(8,5) NOT NULL,
  lon           DECIMAL(9,5) NOT NULL,
  raggio_nm     DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  mine          SMALLINT UNSIGNED NOT NULL,
  posato_gts    BIGINT NOT NULL,
  scade_gts     BIGINT NOT NULL,
  KEY idx_campi_attivi (scade_gts, mine)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campi_minati_passaggi (
  campo_id  BIGINT UNSIGNED NOT NULL,
  ship_id   BIGINT UNSIGNED NOT NULL,
  colpita   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (campo_id, ship_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ships ADD COLUMN IF NOT EXISTS danno_arma VARCHAR(16) NULL
  COMMENT 'Che cosa l''ha colpita fuori da un incontro: mina';

ALTER TABLE sinkings MODIFY COLUMN arma ENUM('siluro','cannone','siluro_e_cannone','mina') NOT NULL DEFAULT 'siluro';

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('missioni.ogni_giorni', '4', 'int', 'Ogni quanti giorni di gioco il BdU rinnova le offerte di missione speciale a chi e'' in porto'),
  ('missioni.mina_p', '0.12', 'float', 'Probabilita'' che una nave che attraversa un campo minato pieno salti su una mina')
ON DUPLICATE KEY UPDATE note = VALUES(note);
