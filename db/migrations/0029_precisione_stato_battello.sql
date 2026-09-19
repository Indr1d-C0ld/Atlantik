-- Piu' decimali alle grandezze che si accumulano passo per passo.
--
-- Nafta, batteria, aria, CO2, viveri, sollecitazione e errore di navigazione
-- non sono misure che si leggono: sono contatori che la simulazione aggiorna a
-- ogni sotto-passo. Con due decimali, un sotto-passo da cinque minuti consuma
-- 0,0104 t di nafta e ne salva 0,01: lo 0,0004 che resta si perde, e si perde
-- a ogni passo.
--
-- Da soli quei centesimi non si vedono. Il guaio e' che se ne perdono TANTI
-- quanti sono i salvataggi, e i salvataggi dipendono da quante volte il
-- giocatore apre una pagina: chi ricarica spesso consumava piu' nafta di chi
-- torna una volta al giorno, a parita' di miglia percorse. Un mondo che
-- dipende dal ritmo di collegamento non e' lo stesso mondo per tutti.
--
-- Con cinque decimali il residuo per passo scende sotto il decimilionesimo e
-- centoquaranta salvataggi in dodici ore di gioco valgono meno di un grammo.
-- Misurato dopo la modifica: quattro ritmi di collegamento diversi danno lo
-- stesso identico esito.

ALTER TABLE boats
    MODIFY COLUMN fuel_t          DECIMAL(10,5) NOT NULL DEFAULT 0,
    MODIFY COLUMN battery_pct     DECIMAL(8,5)  NOT NULL DEFAULT 100,
    MODIFY COLUMN air_pct         DECIMAL(8,5)  NOT NULL DEFAULT 100,
    MODIFY COLUMN co2_pct         DECIMAL(8,5)  NOT NULL DEFAULT 0,
    MODIFY COLUMN provisions_days DECIMAL(8,5)  NOT NULL DEFAULT 0,
    MODIFY COLUMN hull_stress     DECIMAL(8,5)  NOT NULL DEFAULT 0,
    MODIFY COLUMN hull_integrity  DECIMAL(8,5)  NOT NULL DEFAULT 100,
    MODIFY COLUMN est_error_nm    DECIMAL(9,5)  NOT NULL DEFAULT 0;

ALTER TABLE boat_compartments
    MODIFY COLUMN integrity DECIMAL(8,5) NOT NULL DEFAULT 100,
    MODIFY COLUMN flooding  DECIMAL(8,5) NOT NULL DEFAULT 0,
    MODIFY COLUMN fire      DECIMAL(8,5) NOT NULL DEFAULT 0;

ALTER TABLE patrols
    MODIFY COLUMN distance_nm  DECIMAL(12,5) NOT NULL DEFAULT 0,
    MODIFY COLUMN surfaced_nm  DECIMAL(12,5) NOT NULL DEFAULT 0,
    MODIFY COLUMN submerged_nm DECIMAL(12,5) NOT NULL DEFAULT 0,
    MODIFY COLUMN fuel_used_t  DECIMAL(10,5) NOT NULL DEFAULT 0;
