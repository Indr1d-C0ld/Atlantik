-- 0007_tattico : orologio proprio dell'incontro e ordini tattici

ALTER TABLE encounters ADD COLUMN IF NOT EXISTS last_step_real BIGINT NOT NULL DEFAULT 0 AFTER last_step_gts;
ALTER TABLE encounters ADD COLUMN IF NOT EXISTS log JSON NULL;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS ordered_heading DECIMAL(5,1) NULL AFTER heading;
ALTER TABLE boats ADD COLUMN IF NOT EXISTS periscopio TINYINT(1) NOT NULL DEFAULT 0 AFTER silent;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('combat.max_passi', '900', 'int', 'Massimo di passi tattici per singola chiamata')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
