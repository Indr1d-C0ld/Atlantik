-- 0044: avvisi per posta
--
-- La fase tattica dura una finestra di tempo reale, e gli avvisi del browser
-- funzionano solo a pagina aperta: un convoglio che passava mentre il
-- comandante era altrove passava e basta. Chi lo chiede riceve un messaggio
-- quando c'e' un contatto, un pericolo o un ordine del BdU.
--
-- Ogni riga del giornale che merita un avviso lascia qui una riga; il battito
-- le raccoglie, le raggruppa per comandante e decide se mandarle (preferenze,
-- presenza alla console, freni). Quello che non parte resta, col motivo.

CREATE TABLE IF NOT EXISTS avvisi (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  boat_id     BIGINT UNSIGNED NOT NULL,
  event_id    BIGINT UNSIGNED NULL,
  categoria   ENUM('contatti','pericoli','bdu') NOT NULL,
  testo       VARCHAR(500) NOT NULL,
  quadrat     VARCHAR(16) NULL,
  gts         BIGINT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mail_id     BIGINT UNSIGNED NULL COMMENT 'Riga di mail_queue che lo ha portato',
  saltato     VARCHAR(32) NULL COMMENT 'spento, presente, frenato, tetto, scaduto, senza indirizzo',
  KEY idx_avvisi_aperti (mail_id, saltato, created_at),
  KEY idx_avvisi_utente (user_id, categoria, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('avvisi.presenza_s',  '180', 'int', 'Chi ha caricato una pagina negli ultimi N secondi e'' alla console: niente posta'),
  ('avvisi.intervallo_min', '30', 'int', 'Minuti minimi fra due messaggi della stessa categoria allo stesso comandante'),
  ('avvisi.max_24h',     '12',  'int', 'Messaggi di avviso al giorno per comandante, al massimo'),
  ('avvisi.scadenza_min', '45', 'int', 'Un avviso non partito entro N minuti non serve piu'' e si lascia cadere')
ON DUPLICATE KEY UPDATE note = VALUES(note);
