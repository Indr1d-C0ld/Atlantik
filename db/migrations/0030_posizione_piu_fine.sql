-- Latitudine e longitudine del battello con due decimali in piu'.
--
-- La posizione del battello non e' un dato che si legge: e' un contatore che la
-- simulazione sposta a ogni sotto-passo, e che passa dal database a ogni
-- salvataggio. Con cinque decimali la risoluzione e' circa un metro, e un
-- metro per centoquaranta salvataggi in dodici ore fa qualche decina di
-- centimetri di scarto fra chi avanza in un colpo solo e chi avanza a
-- spezzoni.
--
-- Nessuno se ne accorgerebbe guardando la carta. Ma quello scarto ogni tanto
-- fa cadere una soglia da una parte invece che dall'altra — l'errore di
-- navigazione che supera o non supera il limite per annotare il punto nave — e
-- allora due giocatori identici leggono giornali diversi. Con sette decimali
-- la risoluzione e' poco piu' di un centimetro e la soglia non balla piu'.
--
-- Le posizioni del traffico alleato non hanno questo problema: non si
-- accumulano, si calcolano dalla rotta e dall'ora.

ALTER TABLE boats
    MODIFY COLUMN lat     DECIMAL(10,7) NOT NULL DEFAULT 0,
    MODIFY COLUMN lon     DECIMAL(11,7) NOT NULL DEFAULT 0,
    MODIFY COLUMN est_lat DECIMAL(10,7) NOT NULL DEFAULT 0,
    MODIFY COLUMN est_lon DECIMAL(11,7) NOT NULL DEFAULT 0;
