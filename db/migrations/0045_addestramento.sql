-- 0045: l'addestramento tattico (Agru-Front)
--
-- Prima di andare al fronte, i comandanti nuovi passavano dall'Agru-Front
-- (Ausbildungsgruppe fuer Frontunterseeboote) nel Baltico: attacchi ai convogli
-- d'esercitazione nella baia di Danzica, con le teste d'esercitazione nei
-- siluri e i cacciasommergibili della scuola sopra la testa. Qui e' un'uscita
-- vera, in mare, con la stessa simulazione della guerra, ma:
--
--   - la missione e' di tipo 'addestramento' e non conta fra le missioni di
--     guerra (statistiche, fascicolo, numero della missione);
--   - il convoglio e' 'esercitazione': non entra nel conto del traffico;
--   - le cariche delle scorte non fanno danno, ma si contano: quello che in
--     guerra sarebbe costato resta in encounters.danno_simulato;
--   - alla fine l'istruttore scrive un giudizio, che resta in addestramenti.

ALTER TABLE patrols ADD COLUMN IF NOT EXISTS tipo ENUM('guerra','addestramento') NOT NULL DEFAULT 'guerra'
  COMMENT 'Le missioni d''addestramento non contano fra quelle di guerra' AFTER number;

ALTER TABLE convoys ADD COLUMN IF NOT EXISTS esercitazione TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Convoglio d''esercitazione dell''Agru-Front: fuori dal conto del traffico';

ALTER TABLE encounters ADD COLUMN IF NOT EXISTS danno_simulato DECIMAL(6,1) NOT NULL DEFAULT 0
  COMMENT 'In esercitazione: la sollecitazione dello scafo che le cariche avrebbero prodotto in guerra';

ALTER TABLE encounters ADD COLUMN IF NOT EXISTS allarme_gts BIGINT NULL
  COMMENT 'Quando il convoglio si e'' accorto di noi: serve a dire se l''avvicinamento e'' riuscito non visto';

CREATE TABLE IF NOT EXISTS addestramenti (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  commander_id  BIGINT UNSIGNED NOT NULL,
  boat_id       BIGINT UNSIGNED NOT NULL,
  patrol_id     BIGINT UNSIGNED NOT NULL,
  convoy_id     BIGINT UNSIGNED NULL,
  iniziato_gts  BIGINT NOT NULL,
  finito_gts    BIGINT NULL,
  punteggio     SMALLINT NULL,
  giudizio      VARCHAR(16) NULL COMMENT 'ottimo, buono, sufficiente, insufficiente, non concluso',
  dettaglio     TEXT NULL COMMENT 'JSON: lanci, colpi, visto prima del lancio, danno simulato',
  rapporto      TEXT NULL,
  KEY idx_addestramenti_cmd (commander_id, id),
  UNIQUE KEY uq_addestramenti_patrol (patrol_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('addestramento.premio', '60', 'int', 'Prestigio al primo addestramento superato almeno con «sufficiente»'),
  ('addestramento.competenza', '3', 'int', 'Punti di competenza a ogni uomo per un addestramento superato')
ON DUPLICATE KEY UPDATE note = VALUES(note);
