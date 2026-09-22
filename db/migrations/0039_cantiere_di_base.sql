-- 0039: il cantiere di base ripara davvero
--
-- Fino al 21/09/2026 le riparazioni avanzavano solo a mare, dentro
-- BoatSim::advance, e il battito avanza solo i battelli in mare. Un battello
-- in porto restava fermo com'era: la barra non si muoveva e il pulsante
-- "dai priorita'" scriveva un valore che nessuno leggeva. Le avarie sparivano
-- soltanto alla partenza successiva, quando Damage::overhaul rimetteva tutto
-- a nuovo in un colpo.
--
-- I tre sistemi marcati "non a mare" — i due periscopi e lo scafo resistente —
-- avevano repair_hours a zero. Non era una svista di poco conto: il seed dice
-- "non si ripara a mare, bisogna rientrare", quindi il rientro era previsto,
-- ma non esistendo nessun cantiere nessuno aveva mai dovuto decidere quanto
-- ci volesse. Senza un tempo, adesso che il cantiere lavora, quelle avarie si
-- chiuderebbero in un istante.
--
-- Sono ore-uomo, come per tutti gli altri sistemi.

UPDATE boat_systems SET repair_hours = 18 WHERE skey = 'periscopio_att' AND repair_hours <= 0;
UPDATE boat_systems SET repair_hours = 16 WHERE skey = 'periscopio_osc' AND repair_hours <= 0;
UPDATE boat_systems SET repair_hours = 30 WHERE skey = 'scafo' AND repair_hours <= 0;
