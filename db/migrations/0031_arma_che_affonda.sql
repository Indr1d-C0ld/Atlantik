-- Di che cosa e' morta una nave.
--
-- Il registro degli affondamenti ha una colonna 'arma' con tre valori —
-- siluro, cannone, siluro_e_cannone — e il codice ci scriveva sempre "siluro",
-- scritto a mano in tutte e due le chiamate. Una nave finita a cannonate
-- risultava affondata a siluri.
--
-- Non e' solo una riga sbagliata nel giornale: il trofeo "cannoniere" cerca un
-- affondamento con arma = 'cannone', e quell'affondamento non poteva esistere.
-- Il trofeo era INOTTENIBILE. E le statistiche di rendimento — tonnellate per
-- siluro — contavano come siluri anche le navi affondate col pezzo da 88.
--
-- Perche' il registro possa dire il vero, l'incontro deve ricordare da che cosa
-- ogni nave e' stata colpita. Due bandierine sull'entita' bastano: se ne ha
-- una sola, l'arma e' quella; se le ha tutte e due, e' il caso storico piu'
-- comune — il siluro la ferma, il cannone la finisce.

ALTER TABLE encounter_entities
    ADD COLUMN IF NOT EXISTS colpita_siluro  TINYINT(1) NOT NULL DEFAULT 0 AFTER smascherata,
    ADD COLUMN IF NOT EXISTS colpita_cannone TINYINT(1) NOT NULL DEFAULT 0 AFTER colpita_siluro;
