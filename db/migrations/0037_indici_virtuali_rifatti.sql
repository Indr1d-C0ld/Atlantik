-- Un DROP COLUMN ha spento tre indici di unicita' senza dirlo a nessuno.
--
-- La migrazione 0036 toglie due colonne morte, fra cui boats.periscopio.
-- MariaDB 11.8 esegue un DROP COLUMN in modo "istantaneo": non ricostruisce la
-- tabella, si limita a segnare che quella colonna non c'e' piu'. Comodo, e
-- quasi sempre innocuo — ma su questa tabella c'e' un indice UNIQUE costruito
-- sopra una colonna VIRTUALE generata (vivo_numero, che vale il numero del
-- battello finche' il battello non e' perduto). Dopo l'operazione istantanea
-- quell'indice resta nel catalogo, si vede in SHOW INDEX, e NON FILTRA PIU'.
--
-- Misurato subito dopo la 0036: due battelli vivi, si prende il numero del
-- primo e lo si da' al secondo, e il database accetta. Prima della 0036 lo
-- rifiutava. Nessun errore, nessun avviso: la garanzia si era semplicemente
-- spenta, e da quel momento due U-Boot in mare avrebbero potuto portare lo
-- stesso numero — che in un gioco dove il numero e' l'identita' del battello
-- vuol dire due giocatori con lo stesso nome.
--
-- L'ha trovato una prova che c'era gia': test_profilo.php verifica che il
-- numero di un battello vivo non si possa rubare. Non stava verificando il
-- database — stava verificando il codice — e ha preso il database in fallo.
--
-- Qui si rifanno i tre indici sulle colonne generate. Rifarli li ricostruisce
-- davvero, e la garanzia torna. La regola per il futuro: dopo un DROP COLUMN
-- su una tabella con indici su colonne generate, quegli indici si rifanno.

ALTER TABLE boats DROP INDEX IF EXISTS uq_vivo_numero;
ALTER TABLE boats ADD UNIQUE INDEX uq_vivo_numero (vivo_numero);

ALTER TABLE commanders DROP INDEX IF EXISTS uq_vivo_nome;
ALTER TABLE commanders ADD UNIQUE INDEX uq_vivo_nome (vivo_nome);

ALTER TABLE commanders DROP INDEX IF EXISTS uq_vivo_ritratto_key;
ALTER TABLE commanders ADD UNIQUE INDEX uq_vivo_ritratto_key (vivo_ritratto_key);
