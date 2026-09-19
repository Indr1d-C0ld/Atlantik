-- Da quando valgono le sessioni di un account.
--
-- Rifare la password serve quasi sempre perche' qualcun altro e' entrato. Se
-- le sessioni gia' aperte restano aperte, la password nuova non serve a
-- niente: chi era dentro resta dentro. E' la meta' del lavoro che quasi sempre
-- si dimentica.
--
-- Le sessioni qui sono quelle di PHP, e non c'e' modo di elencarle per utente
-- senza frugare nella cartella del gestore. Ma non serve: basta che ogni
-- sessione si porti dietro l'istante in cui e' nata, e che a ogni richiesta si
-- controlli che sia successivo a questo. Cambiata la password, si sposta
-- questa data e tutte le sessioni piu' vecchie cadono da sole.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS sessioni_da DATETIME NULL DEFAULT NULL AFTER password_hash;
