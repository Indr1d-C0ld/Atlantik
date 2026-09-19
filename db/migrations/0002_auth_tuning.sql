-- 0002_auth_tuning : lunghezza minima della password portata a 9 caratteri
-- (richiesta dell'amministratore, 17/09/2026)

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('auth.min_password_length', '9', 'int', 'Lunghezza minima della password')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype);
