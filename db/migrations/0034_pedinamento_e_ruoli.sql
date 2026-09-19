-- Due cose che lo schema prometteva e il codice non manteneva.
--
-- 1. IL PEDINAMENTO. bdu_orders.tipo ha sei valori e il codice ne scriveva
--    uno solo, "area". Il piu' importante di quelli morti era "pedinamento",
--    che per giunta era gia' letto in Bdu::verifica: qualcuno aveva
--    preparato la porta e non ci era mai passato nessuno.
--
--    E' la meta' mancante della tattica del branco, che nel gioco c'e' gia'
--    tutta dall'altra parte: i gruppi si formano, chi segnala un contatto
--    prende il premio del Fuehlungshalter, i compagni ricevono il punto. Solo
--    che il BdU non chiedeva MAI a nessuno di pedinare, e pedinare senza che
--    te lo chiedano non da' niente se non sei in un branco. Il mestiere piu'
--    ingrato e piu' decisivo dell'Atlantico non esisteva.
--
--    Serve la colonna del convoglio: un ordine di pedinamento e' legato a un
--    bersaglio che si muove, non a un punto sulla carta come l'area operativa.
--
-- 2. IL MODERATORE. users.role prevedeva "moderator" dal primo giorno e
--    nessuna riga di codice ha mai nominato quel valore: il controllo di
--    accesso guarda soltanto se il ruolo e' "admin". Un moderatore sarebbe
--    stato identico a un giocatore qualunque — con la differenza che chi
--    glielo assegnava credeva di avergli dato qualcosa. Un grado che non
--    conferisce niente e' peggio di un grado che non c'e': si toglie. Se
--    servira' davvero, lo rimettera' una migrazione con dietro il codice che
--    gli da' un significato.

ALTER TABLE bdu_orders
    ADD COLUMN convoy_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER wolfpack_id,
    ADD CONSTRAINT fk_bdu_convoy FOREIGN KEY (convoy_id) REFERENCES convoys (id) ON DELETE SET NULL;

ALTER TABLE users
    MODIFY role ENUM('player','admin') NOT NULL DEFAULT 'player';
