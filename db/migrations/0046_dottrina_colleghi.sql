-- 0046: la dottrina d'ingaggio e i colleghi mossi dal motore
--
-- Una delle quattro decisioni fondanti del progetto (DESIGN.md): se il
-- comandante non c'e', subentra il I.WO, governato da una dottrina
-- d'ingaggio. Fino al 09/10/2026 la dottrina non esisteva — il I.WO
-- disimpegnava soltanto, e un attacco non lo cominciava mai — e la pagina
-- d'ingresso la prometteva lo stesso.
--
--   - boats.dottrina: che cosa il I.WO puo' attaccare da solo. 'prudente' (il
--     default, cioe' com'era prima) non attacca; 'isolate' attacca le navi
--     sole, senza scorta; 'convogli' anche i convogli.
--   - encounters.condotta: chi comanda l'incontro. 'iwo' quando l'ha
--     cominciato lui; torna 'comandante' appena il comandante apre la stazione
--     d'attacco.
--   - users.role 'motore': i colleghi. Ogni collega e' un account di sistema
--     che non puo' entrare (status 'suspended'), con un comandante e un
--     battello veri: partono, pattugliano, segnalano, attaccano, rientrano e
--     muoiono con le stesse regole dei giocatori. Il gioco li dichiara.

ALTER TABLE boats ADD COLUMN IF NOT EXISTS dottrina ENUM('prudente','isolate','convogli') NOT NULL DEFAULT 'prudente'
  COMMENT 'Che cosa il I.WO puo'' attaccare da solo, in assenza del comandante';

ALTER TABLE encounters ADD COLUMN IF NOT EXISTS condotta ENUM('comandante','iwo') NOT NULL DEFAULT 'comandante'
  COMMENT 'Chi conduce l''incontro: il comandante, o il I.WO secondo la dottrina';

ALTER TABLE users MODIFY COLUMN role ENUM('player','admin','motore') NOT NULL DEFAULT 'player';

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('colleghi.quanti', '4', 'int', 'Quanti U-Boot dei colleghi mossi dal motore tenere in servizio (0 = nessuno)'),
  ('colleghi.sosta_giorni', '6', 'int', 'Giorni di gioco in porto fra una missione e l''altra di un collega')
ON DUPLICATE KEY UPDATE note = VALUES(note);
