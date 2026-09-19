-- 0017_emblemi : l'emblema di torretta
--
-- Ogni battello puo' portare un emblema: uno scelto dal repertorio, oppure uno
-- caricato dal comandante. Due regole, e stanno tutte e due nel database invece
-- che nelle buone intenzioni del codice:
--
--   1. un battello, un emblema  → una colonna sola;
--   2. nessun emblema in due    → indice UNIQUE.
--
-- In MySQL un indice UNIQUE lascia passare quanti NULL vuole: i battelli senza
-- emblema non si danno fastidio fra loro, ma appena uno prende "ferro di
-- cavallo" quel ferro di cavallo e' suo e basta. La corsa fra due comandanti che
-- scelgono lo stesso emblema nello stesso istante la perde uno dei due, e la
-- perde sul vincolo, che e' l'unico posto dove non si puo' barare.

ALTER TABLE boats ADD COLUMN IF NOT EXISTS emblema_key VARCHAR(40) NULL
  COMMENT 'Chiave del repertorio, NULL se caricato o se non ne ha';
ALTER TABLE boats ADD COLUMN IF NOT EXISTS emblema_file VARCHAR(80) NULL
  COMMENT 'Nome del file caricato dentro assets/img/emblemi/caricati/';
ALTER TABLE boats ADD COLUMN IF NOT EXISTS emblema_hash CHAR(64) NULL
  COMMENT 'sha256 dell immagine normalizzata: impedisce due caricamenti identici';
ALTER TABLE boats ADD COLUMN IF NOT EXISTS emblema_gts BIGINT NULL
  COMMENT 'Quando e stato adottato';

ALTER TABLE boats ADD UNIQUE INDEX IF NOT EXISTS uq_emblema_key (emblema_key);
ALTER TABLE boats ADD UNIQUE INDEX IF NOT EXISTS uq_emblema_hash (emblema_hash);
