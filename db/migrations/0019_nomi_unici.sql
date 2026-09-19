-- 0019_nomi_unici : nessun nome in due, ne' fra le navi ne' fra i comandanti
--
-- In mare non possono esistere due navi con lo stesso nome, e in flottiglia non
-- possono esistere due comandanti che si chiamano uguale. Il vincolo sta nel
-- database, non nelle buone intenzioni del generatore: e' l'unico posto dove non
-- si puo' barare, e regge anche la corsa fra due creazioni simultanee.
--
-- Prima di stringere bisogna pulire. Le navi sono naviglio generato dal motore,
-- e i doppioni nascevano da uno spazio di nomi troppo stretto (152 voci per
-- duemila scafi): quelli che nessuno riferisce si cancellano e il traffico si
-- ripopola da solo al primo battito. I pochi riferiti da un contatto, da un
-- incontro o da un affondamento non si toccano: si distinguono aggiungendo
-- l'identificativo, perche' cancellarli spezzerebbe un pezzo di storia.

-- 1. i doppioni non riferiti da nessuno: via, il mare si riempie da solo
DELETE s FROM ships s
  JOIN (
    SELECT name, MIN(id) AS tenere FROM ships GROUP BY name HAVING COUNT(*) > 1
  ) d ON d.name = s.name AND s.id <> d.tenere
 WHERE NOT EXISTS (SELECT 1 FROM contacts c WHERE c.ship_id = s.id)
   AND NOT EXISTS (SELECT 1 FROM encounter_entities e WHERE e.ship_id = s.id)
   AND NOT EXISTS (SELECT 1 FROM sinkings k WHERE k.ship_id = s.id)
   AND NOT EXISTS (SELECT 1 FROM encounters n WHERE n.ship_id = s.id);

-- 2. quelli che restano doppi sono riferiti: si distinguono invece di sparire
UPDATE ships s
  JOIN (
    SELECT name, MIN(id) AS tenere FROM ships GROUP BY name HAVING COUNT(*) > 1
  ) d ON d.name = s.name AND s.id <> d.tenere
   SET s.name = CONCAT(s.name, ' (', s.id, ')');

UPDATE commanders c
  JOIN (
    SELECT nome, MIN(id) AS tenere FROM commanders GROUP BY nome HAVING COUNT(*) > 1
  ) d ON d.nome = c.nome AND c.id <> d.tenere
   SET c.nome = CONCAT(c.nome, ' (', c.id, ')');

-- 3. adesso si puo' stringere
ALTER TABLE ships      ADD UNIQUE INDEX IF NOT EXISTS uq_ship_name (name);
ALTER TABLE commanders ADD UNIQUE INDEX IF NOT EXISTS uq_commander_nome (nome);
