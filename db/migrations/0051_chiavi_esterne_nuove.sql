-- 0051: le tabelle nuove si portano via con quello a cui appartengono
--
-- Nei quattro giorni dopo il 09/10/2026 si sono trovate 421 righe di avvisi e
-- dieci gruppi di naufraghi di utenti, battelli e affondamenti che non
-- esistevano piu': le prove li creano e li cancellano, ma le prove scritte
-- prima non conoscono le tabelle nuove. Era la stessa trappola gia' annotata
-- per patrols e sinkings. Qui le tabelle nuove hanno la chiave esterna, con la
-- cancellazione a cascata: chi cancella un utente o un battello non deve sapere
-- che cosa ci pende sotto.

DELETE a FROM avvisi a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL;
DELETE a FROM avvisi a LEFT JOIN boats b ON b.id = a.boat_id WHERE b.id IS NULL;
DELETE n FROM naufraghi n LEFT JOIN sinkings s ON s.id = n.sinking_id WHERE s.id IS NULL;
DELETE n FROM naufraghi n LEFT JOIN boats b ON b.id = n.boat_id WHERE b.id IS NULL;
DELETE m FROM missioni_speciali m LEFT JOIN boats b ON b.id = m.boat_id WHERE b.id IS NULL;
DELETE a FROM addestramenti a LEFT JOIN boats b ON b.id = a.boat_id WHERE b.id IS NULL;
UPDATE campi_minati c LEFT JOIN boats b ON b.id = c.boat_id SET c.boat_id = NULL WHERE c.boat_id IS NOT NULL AND b.id IS NULL;
DELETE p FROM campi_minati_passaggi p LEFT JOIN campi_minati c ON c.id = p.campo_id WHERE c.id IS NULL;

ALTER TABLE avvisi
  ADD CONSTRAINT fk_avvisi_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_avvisi_boat FOREIGN KEY (boat_id) REFERENCES boats (id) ON DELETE CASCADE;
ALTER TABLE naufraghi
  ADD CONSTRAINT fk_naufraghi_sinking FOREIGN KEY (sinking_id) REFERENCES sinkings (id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_naufraghi_boat FOREIGN KEY (boat_id) REFERENCES boats (id) ON DELETE CASCADE;
ALTER TABLE missioni_speciali
  ADD CONSTRAINT fk_missioni_boat FOREIGN KEY (boat_id) REFERENCES boats (id) ON DELETE CASCADE;
ALTER TABLE addestramenti
  ADD CONSTRAINT fk_addestramenti_boat FOREIGN KEY (boat_id) REFERENCES boats (id) ON DELETE CASCADE;
ALTER TABLE campi_minati
  ADD CONSTRAINT fk_campi_boat FOREIGN KEY (boat_id) REFERENCES boats (id) ON DELETE SET NULL;
ALTER TABLE campi_minati_passaggi
  ADD CONSTRAINT fk_passaggi_campo FOREIGN KEY (campo_id) REFERENCES campi_minati (id) ON DELETE CASCADE;
