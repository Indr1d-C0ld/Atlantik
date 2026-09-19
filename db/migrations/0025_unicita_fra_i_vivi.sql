-- L'unicita' vale fra i vivi.
--
-- Fino a qui un nome, un volto, un emblema e un numero di U-Boot erano unici
-- PER SEMPRE: il primo che li prendeva se li teneva anche da morto, e nessuno
-- poteva piu' usarli. Per un mondo persistente che va avanti per anni non
-- regge — i comandanti muoiono, e nel 1942 morivano quasi tutti — e il
-- repertorio si sarebbe consumato da solo.
--
-- La regola giusta e' quella che il gioco gia' racconta: in flottiglia non ci
-- sono due cose uguali FRA QUELLE IN SERVIZIO. Quando un comandante cade, viene
-- dato per disperso, finisce prigioniero o viene congedato, il suo nome e il suo
-- volto tornano disponibili; quando un battello si perde, il suo numero e il suo
-- emblema tornano disponibili. Il fascicolo del caduto resta com'e' — nell'albo
-- d'oro il suo volto e il suo nome non cambiano — ma la prenotazione decade.
--
-- COME
--
-- Una colonna generata che vale il dato quando la riga e' "viva" e NULL quando
-- non lo e', piu' un indice UNIQUE su quella colonna. UNIQUE ignora i NULL,
-- quindi i morti non danno fastidio a nessuno e non serve una riga di codice
-- applicativo: la liberazione avviene nell'istante in cui cambia lo stato.

ALTER TABLE commanders DROP INDEX IF EXISTS uq_commander_nome;
ALTER TABLE commanders DROP INDEX IF EXISTS uq_commander_ritratto_key;
ALTER TABLE commanders DROP INDEX IF EXISTS uq_commander_ritratto_hash;

-- Le impronte erano CHAR(64). Una colonna generata che legge un CHAR non si
-- puo' indicizzare: il riempimento a lunghezza fissa rende l'espressione non
-- deterministica agli occhi del motore, e l'indice viene rifiutato. Sono
-- stringhe esadecimali di lunghezza fissa, quindi VARCHAR non cambia niente per
-- noi e risolve tutto per lui.
ALTER TABLE commanders MODIFY COLUMN ritratto_hash VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE boats MODIFY COLUMN emblema_hash VARCHAR(64) NULL DEFAULT NULL;

-- Il primo tentativo aveva dichiarato l'impronta come CHAR e si era fermato
-- qui: si toglie la colonna storta prima di rifarla giusta.
ALTER TABLE commanders DROP COLUMN IF EXISTS vivo_ritratto_hash;

ALTER TABLE commanders
  ADD COLUMN IF NOT EXISTS vivo_nome VARCHAR(64)
      AS (IF(stato = 'attivo', nome, NULL)) VIRTUAL,
  ADD COLUMN IF NOT EXISTS vivo_ritratto_key VARCHAR(64)
      AS (IF(stato = 'attivo', ritratto_key, NULL)) VIRTUAL,
  ADD COLUMN IF NOT EXISTS vivo_ritratto_hash VARCHAR(64)
      AS (IF(stato = 'attivo', ritratto_hash, NULL)) VIRTUAL;

ALTER TABLE commanders ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_nome (vivo_nome);
ALTER TABLE commanders ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_ritratto_key (vivo_ritratto_key);
ALTER TABLE commanders ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_ritratto_hash (vivo_ritratto_hash);

ALTER TABLE boats DROP INDEX IF EXISTS uq_boat_number;
ALTER TABLE boats DROP INDEX IF EXISTS uq_emblema_key;
ALTER TABLE boats DROP INDEX IF EXISTS uq_emblema_hash;

ALTER TABLE boats
  ADD COLUMN IF NOT EXISTS vivo_numero VARCHAR(12)
      AS (IF(state <> 'perduto', uboat_number, NULL)) VIRTUAL,
  ADD COLUMN IF NOT EXISTS vivo_emblema_key VARCHAR(40)
      AS (IF(state <> 'perduto', emblema_key, NULL)) VIRTUAL,
  ADD COLUMN IF NOT EXISTS vivo_emblema_hash VARCHAR(64)
      AS (IF(state <> 'perduto', emblema_hash, NULL)) VIRTUAL;

ALTER TABLE boats ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_numero (vivo_numero);
ALTER TABLE boats ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_emblema_key (vivo_emblema_key);
ALTER TABLE boats ADD UNIQUE INDEX IF NOT EXISTS uq_vivo_emblema_hash (vivo_emblema_hash);
