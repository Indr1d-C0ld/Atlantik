-- Il ritratto del comandante, e il profilo che gli altri possono vedere.
--
-- Il ritratto puo' venire da due posti: il repertorio storico (ritratto_key,
-- una voce di db/seed/ritratti.php) oppure una fotografia caricata dal
-- giocatore (ritratto_file, col suo sha256). Mai tutti e due.
--
-- Nessun volto in due plance: come per i nomi e per gli emblemi, il vincolo sta
-- nel database e non nel controllo a mano. UNIQUE su colonna che ammette NULL
-- lascia passare quanti NULL si vuole, che e' proprio cio' che serve: i
-- comandanti senza ritratto sono tanti, i comandanti con LO STESSO ritratto
-- nessuno.

ALTER TABLE commanders
  ADD COLUMN IF NOT EXISTS ritratto_key VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS ritratto_file VARCHAR(80) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS ritratto_hash CHAR(64) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS nome_storico TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS nota_pubblica VARCHAR(500) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS profilo_gts BIGINT NULL DEFAULT NULL;

ALTER TABLE commanders
  ADD UNIQUE INDEX IF NOT EXISTS uq_commander_ritratto_key (ritratto_key);
ALTER TABLE commanders
  ADD UNIQUE INDEX IF NOT EXISTS uq_commander_ritratto_hash (ritratto_hash);
