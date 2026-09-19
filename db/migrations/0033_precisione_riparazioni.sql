-- L'ultimo contatore rimasto a due decimali.
--
-- La migrazione 0029 ha dato cinque decimali a tutto quello che la simulazione
-- accumula passo per passo — nafta, batteria, aria, sollecitazione — perche' un
-- residuo buttato via a ogni salvataggio fa un mondo diverso per chi ricarica
-- spesso e per chi torna una volta al giorno. Ne era rimasto fuori uno:
-- l'avanzamento delle riparazioni.
--
-- La squadra produce circa un decimo di ora-uomo per sotto-passo. Salvato con
-- due decimali, ogni salvataggio ne butta via fino a mezzo centesimo; dentro
-- una sola chiamata il conto resta in memoria e non si perde niente, ma fra una
-- chiamata e l'altra si riparte dal valore troncato. Su centoquaranta
-- sotto-passi il divario arriva a mezz'ora di lavoro: abbastanza perche' una
-- riparazione finisca prima delle dodici ore per chi si collega una volta sola
-- e non finisca per chi si collega di continuo. Misurato: un'avaria riparata in
-- un ritmo e ancora aperta nell'altro, a parita' di tempo di gioco.

ALTER TABLE boat_systems
    MODIFY repair_progress DECIMAL(9,5) NOT NULL DEFAULT 0;
