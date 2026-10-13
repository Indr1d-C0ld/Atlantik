-- 0052: le offerte di missione speciale, una volta per periodo
--
-- Si generavano alla prima apertura della base nel periodo, controllando prima
-- se c'erano gia': due pagine aperte insieme ne scrivevano quattro invece di
-- due (revisione del 13/10/2026). Ora ogni offerta porta il suo periodo, e la
-- coppia battello-periodo-tipo e' unica.

ALTER TABLE missioni_speciali ADD COLUMN IF NOT EXISTS periodo BIGINT NULL
  COMMENT 'Periodo di offerta (giorni di gioco / missioni.ogni_giorni)' AFTER stato;
ALTER TABLE missioni_speciali ADD UNIQUE INDEX IF NOT EXISTS uq_missioni_periodo (boat_id, periodo, tipo);
