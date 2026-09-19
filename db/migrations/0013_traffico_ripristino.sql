-- 0013_traffico_ripristino : rimette in mare il naviglio marcato per sbaglio
--
-- Residuo di una versione precedente dell'archiviazione del traffico (corretta
-- in F3): alcune navi in navigazione furono marcate 'arrivata' pur avendo
-- l'arrivo ancora davanti. Il risultato e' naviglio invisibile — esiste in
-- tabella, ma non popola l'oceano ne' puo' essere incontrato.
--
-- Si rimettono in mare solo quelle che, secondo i loro stessi tempi, in mare ci
-- sono davvero: partite, non ancora arrivate, mai affondate. Idempotente.

UPDATE ships s
   SET s.state = 'in_mare'
 WHERE s.state = 'arrivata'
   AND s.sunk_gts IS NULL
   AND s.departed_gts <= (SELECT epoch_game_ts + (UNIX_TIMESTAMP() - epoch_real_ts) * time_ratio FROM world WHERE id = 1)
   AND s.eta_gts      >  (SELECT epoch_game_ts + (UNIX_TIMESTAMP() - epoch_real_ts) * time_ratio FROM world WHERE id = 1);

UPDATE convoys c
   SET c.state = 'in_mare'
 WHERE c.state = 'arrivato'
   AND c.departed_gts <= (SELECT epoch_game_ts + (UNIX_TIMESTAMP() - epoch_real_ts) * time_ratio FROM world WHERE id = 1)
   AND c.eta_gts      >  (SELECT epoch_game_ts + (UNIX_TIMESTAMP() - epoch_real_ts) * time_ratio FROM world WHERE id = 1);
