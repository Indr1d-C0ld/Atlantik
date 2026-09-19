-- 0011_rifinitura : trofei, preferenze, amministrazione

CREATE TABLE IF NOT EXISTS achievement_types (
  akey        VARCHAR(32) NOT NULL PRIMARY KEY,
  nome        VARCHAR(96) NOT NULL,
  descrizione VARCHAR(255) NOT NULL,
  categoria   ENUM('caccia','navigazione','sopravvivenza','comando','mestiere') NOT NULL DEFAULT 'caccia',
  nascosto    TINYINT(1) NOT NULL DEFAULT 0,
  ordine      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  nota        VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS achievements (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  commander_id BIGINT UNSIGNED NULL,
  akey         VARCHAR(32) NOT NULL,
  gts          BIGINT NOT NULL,
  dettaglio    VARCHAR(255) NULL,
  UNIQUE KEY uq_ach (user_id, akey),
  KEY idx_ach_user (user_id),
  CONSTRAINT fk_ach_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS preferenze JSON NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS note_admin VARCHAR(255) NULL;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('app.nome_beta', 'beta chiusa', 'string', 'Etichetta mostrata accanto al nome del gioco'),
  ('app.registrazione_messaggio', '', 'string', 'Avviso mostrato nella pagina di arruolamento')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue);
