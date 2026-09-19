-- 0020_civetta : la nave civetta deve poter mentire
--
-- La Q-ship era un mercantile all'apparenza, con l'artiglieria dietro pannelli
-- che cadevano quando il U-Boot era ormai in superficie a tiro di cannone. Nel
-- gioco la classe esisteva ma non entrava mai nel traffico, e quando fosse
-- entrata si sarebbe annunciata da sola: la vedetta avrebbe riferito "nave
-- civetta", cioe' l'unica cosa che una nave civetta non dice mai.
--
-- Qui una classe puo' dichiarare di FINGERSI un'altra. Chi la guarda vede
-- quello che la nave vuole far vedere. La verita' viene fuori in un modo solo:
-- quando spara.

ALTER TABLE ship_classes ADD COLUMN IF NOT EXISTS finge VARCHAR(32) NULL
  COMMENT 'Classe di cui questa nave assume le apparenze. NULL se e quello che sembra';

UPDATE ship_classes SET finge = 'cargo_medio' WHERE class_key = 'qship';

INSERT INTO game_config (ckey, cvalue, note) VALUES
  ('traffic.civette_per_mille', '14',
   'Quante navi civetta ogni mille mercantili isolati generati. Storicamente erano poche e sempre meno efficaci: nel 1942 quasi nessuna.')
ON DUPLICATE KEY UPDATE cvalue = cvalue;

