-- Via sectors.aria_base.
--
-- La colonna c'e' dalla migrazione 0005 con un commento che prometteva la
-- "copertura aerea propria della zona". Non l'ha mai scritta nessuno — trentasei
-- righe, tutte a zero — e non l'ha mai letta nessuno: il pericolo aereo viene
-- dalle sette zone del seme del traffico, che hanno un centro, un raggio, una
-- intensita' e le classi di velivolo. Quella si', ed e' l'unica.
--
-- Tenerla voleva dire lasciare in giro una seconda sorgente per la stessa cosa,
-- vuota, pronta a confondere chi la trovasse. L'audit del 19/09/2026 l'ha
-- trovata; questa migrazione la toglie.

ALTER TABLE sectors DROP COLUMN IF EXISTS aria_base;
