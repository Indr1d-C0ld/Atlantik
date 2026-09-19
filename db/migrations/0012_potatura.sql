-- 0012_potatura : indici per le interrogazioni calde e spazio per la potatura
--
-- L'audit (A5) ha misurato che la ricerca del naviglio in mare faceva
-- scansione completa: idx_ships_state copre (state, departed_gts), ma la
-- condizione vera filtra anche su convoy_id e su eta_gts, e il pianificatore
-- rinunciava. Con 2.000 righe non si sentiva; a 900.000 sarebbe stato il collo
-- di bottiglia del battito.
--
-- eta_gts sta in fondo perche' e' quello selettivo: le navi ancora in viaggio
-- sono poche, quelle gia' partite quasi tutte.

CREATE INDEX IF NOT EXISTS idx_ships_attive  ON ships   (state, convoy_id, eta_gts);
CREATE INDEX IF NOT EXISTS idx_ships_eta     ON ships   (state, eta_gts);
CREATE INDEX IF NOT EXISTS idx_convoy_attivi ON convoys (state, eta_gts);

-- La potatura cancella per fascia di tempo: serve l'indice anche qui.
CREATE INDEX IF NOT EXISTS idx_ev_gts        ON patrol_events (gts);
