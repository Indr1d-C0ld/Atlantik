-- 0016_periscopio : la corsa del periscopio diventa una decisione
--
-- Fino a qui il periscopio era sempre considerato alzato (Encounter passava
-- 'true' fisso alla sagoma): a quota periscopica il battello era esposto al
-- massimo, sempre, e il comandante non poteva farci niente. Ma alzare e
-- abbassare il periscopio e' UNA delle scelte del comandante, e nel 1942 era
-- una scelta che si pagava: sul mare calmo la corsa lascia una baffa bianca
-- che una vedetta attenta vede a due o tremila metri.
--
-- Vedi docs/AUDIT.md, punto A8.

ALTER TABLE boats ADD COLUMN IF NOT EXISTS periscopio_alzato TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Periscopio fuori: si vede il bersaglio, ma anche il bersaglio puo vedere noi';
ALTER TABLE boats ADD COLUMN IF NOT EXISTS periscopio_gts BIGINT NULL
  COMMENT 'Istante di gioco in cui e stato alzato: il I.WO lo abbassa da solo se ci si dimentica';
