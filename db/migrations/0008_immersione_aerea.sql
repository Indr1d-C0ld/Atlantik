-- 0008_immersione_aerea : l'immersione per allarme aereo e' temporanea

ALTER TABLE boats ADD COLUMN IF NOT EXISTS auto_dive_fine_gts BIGINT NULL AFTER periscopio;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS auto_dive_quota DECIMAL(6,1) NULL AFTER auto_dive_fine_gts;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('iwo.minuti_sotto_aereo', '35', 'int', 'Minuti di gioco che il I.WO tiene il battello sotto dopo un allarme aereo')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
