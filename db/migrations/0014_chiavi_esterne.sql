-- 0014_chiavi_esterne : i collegamenti diventano vincoli
--
-- L'audit (A3) ha trovato 33 colonne di collegamento senza vincolo, in venti
-- tabelle. Non era teoria: la pulizia dei dati di prova aveva gia' rimosso 18
-- righe orfane che nessun vincolo aveva fermato. In un gioco con permadeath e
-- albo d'oro, un affondamento orfano e' una riga di storia che punta al vuoto.
--
-- Il criterio e' uno solo, applicato riga per riga:
--   CASCADE  quando il dato esiste SOLO come parte del padre (una riga di
--            giornale senza il suo battello non significa niente);
--   SET NULL quando il dato ha valore per se' e deve sopravvivere al padre
--            (un affondamento resta un affondamento anche se il comandante
--            che lo firmo' non c'e' piu').
--
-- Verificato prima di applicare: zero righe orfane su tutte e 33 le colonne.

-- --- il battello e la sua vita ---------------------------------------------
ALTER TABLE patrol_events    ADD CONSTRAINT fk_ev_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE torpedo_runs     ADD CONSTRAINT fk_tr_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE hfdf_fixes       ADD CONSTRAINT fk_hfdf_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE rendezvous       ADD CONSTRAINT fk_rdv_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE wolfpack_members ADD CONSTRAINT fk_wm_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE patrols          ADD CONSTRAINT fk_patrol_user FOREIGN KEY IF NOT EXISTS (user_id)      REFERENCES users(id)      ON DELETE CASCADE;
ALTER TABLE contacts         ADD CONSTRAINT fk_contact_patrol FOREIGN KEY IF NOT EXISTS (patrol_id)    REFERENCES patrols(id)    ON DELETE CASCADE;

-- --- la storia, che sopravvive a chi l'ha fatta -----------------------------
ALTER TABLE sinkings         ADD CONSTRAINT fk_sink_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE sinkings         ADD CONSTRAINT fk_sink_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE sinkings         ADD CONSTRAINT fk_sink_patrol FOREIGN KEY IF NOT EXISTS (patrol_id)    REFERENCES patrols(id)    ON DELETE SET NULL;
ALTER TABLE sinkings         ADD CONSTRAINT fk_sink_ship FOREIGN KEY IF NOT EXISTS (ship_id)      REFERENCES ships(id)      ON DELETE SET NULL;
ALTER TABLE awards           ADD CONSTRAINT fk_award_patrol FOREIGN KEY IF NOT EXISTS (patrol_id)    REFERENCES patrols(id)    ON DELETE SET NULL;
ALTER TABLE achievements     ADD CONSTRAINT fk_ach_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE bacheca          ADD CONSTRAINT fk_bacheca_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE patrols          ADD CONSTRAINT fk_patrol_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE wolfpack_members ADD CONSTRAINT fk_wm_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;

-- --- il traffico alleato ----------------------------------------------------
ALTER TABLE ships            ADD CONSTRAINT fk_ship_convoy FOREIGN KEY IF NOT EXISTS (convoy_id)    REFERENCES convoys(id)    ON DELETE CASCADE;
ALTER TABLE contacts         ADD CONSTRAINT fk_contact_ship FOREIGN KEY IF NOT EXISTS (ship_id)      REFERENCES ships(id)      ON DELETE SET NULL;
ALTER TABLE contacts         ADD CONSTRAINT fk_contact_convoy FOREIGN KEY IF NOT EXISTS (convoy_id)    REFERENCES convoys(id)    ON DELETE SET NULL;

-- --- l'incontro tattico -----------------------------------------------------
ALTER TABLE encounters       ADD CONSTRAINT fk_enc_patrol FOREIGN KEY IF NOT EXISTS (patrol_id)    REFERENCES patrols(id)    ON DELETE SET NULL;
ALTER TABLE encounters       ADD CONSTRAINT fk_enc_convoy FOREIGN KEY IF NOT EXISTS (convoy_id)    REFERENCES convoys(id)    ON DELETE SET NULL;
ALTER TABLE encounters       ADD CONSTRAINT fk_enc_ship FOREIGN KEY IF NOT EXISTS (ship_id)      REFERENCES ships(id)      ON DELETE SET NULL;
ALTER TABLE encounter_entities ADD CONSTRAINT fk_ee_ship FOREIGN KEY IF NOT EXISTS (ship_id)      REFERENCES ships(id)      ON DELETE SET NULL;

-- --- il BdU, la radio e i branchi ------------------------------------------
ALTER TABLE bdu_orders       ADD CONSTRAINT fk_bdu_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE CASCADE;
ALTER TABLE bdu_orders       ADD CONSTRAINT fk_bdu_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE bdu_orders       ADD CONSTRAINT fk_bdu_pack FOREIGN KEY IF NOT EXISTS (wolfpack_id)  REFERENCES wolfpacks(id)  ON DELETE SET NULL;
ALTER TABLE radio_messages   ADD CONSTRAINT fk_radio_boat FOREIGN KEY IF NOT EXISTS (boat_id)      REFERENCES boats(id)      ON DELETE SET NULL;
ALTER TABLE radio_messages   ADD CONSTRAINT fk_radio_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE radio_messages   ADD CONSTRAINT fk_radio_pack FOREIGN KEY IF NOT EXISTS (wolfpack_id)  REFERENCES wolfpacks(id)  ON DELETE SET NULL;
ALTER TABLE wolfpacks        ADD CONSTRAINT fk_pack_convoy FOREIGN KEY IF NOT EXISTS (convoy_id)    REFERENCES convoys(id)    ON DELETE SET NULL;

-- --- il battello e i suoi puntatori ----------------------------------------
ALTER TABLE boats            ADD CONSTRAINT fk_boat_cmd FOREIGN KEY IF NOT EXISTS (commander_id) REFERENCES commanders(id) ON DELETE SET NULL;
ALTER TABLE boats            ADD CONSTRAINT fk_boat_pack FOREIGN KEY IF NOT EXISTS (wolfpack_id)  REFERENCES wolfpacks(id)  ON DELETE SET NULL;
ALTER TABLE boats            ADD CONSTRAINT fk_boat_enc FOREIGN KEY IF NOT EXISTS (encounter_id) REFERENCES encounters(id) ON DELETE SET NULL;
