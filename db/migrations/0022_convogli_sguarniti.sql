-- 0022_convogli_sguarniti : rimettere in ordine dopo la potatura dei nomi
--
-- La migrazione 0019 ha cancellato le navi con nome duplicato. Erano quasi
-- millesettecento, sparse dentro i convogli: il risultato e' che alcuni
-- convogli sono rimasti con tre o quattro scafi, il che non e' un convoglio —
-- e' una processione.
--
-- Non si possono rimpiazzare le navi mancanti dentro un convoglio gia' partito
-- (posizione in formazione, rotta, orari sono tutti coerenti fra loro). Si
-- chiudono quelli sguarniti: il generatore del traffico ne mette in mare di
-- nuovi al primo battito, completi.
--
-- Soglia bassa di proposito: un convoglio che ha perso meta' delle navi per
-- mano di un U-Boot resta un convoglio, e non va toccato. Sotto gli otto
-- mercantili invece e' un residuo di questa potatura.

UPDATE convoys c
   SET c.state = 'arrivato'
 WHERE c.state = 'in_mare'
   AND (SELECT COUNT(*) FROM ships s
         WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo <> 'scorta') < 8
   AND NOT EXISTS (SELECT 1 FROM encounters e WHERE e.convoy_id = c.id AND e.stato <> 'concluso');

UPDATE ships s
   SET s.state = 'arrivata'
 WHERE s.state = 'in_mare'
   AND s.convoy_id IN (SELECT id FROM convoys WHERE state = 'arrivato');
