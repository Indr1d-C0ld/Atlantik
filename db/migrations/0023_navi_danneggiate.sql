-- Il danno che resta addosso alla nave.
--
-- Prima una nave colpita ma non affondata tornava intera nel traffico appena
-- si rompeva il contatto: due siluri a segno e la mattina dopo navigava come
-- nuova. Non andava cosi'. Una nave silurata e rimasta a galla rallentava,
-- perdeva il posto in convoglio e restava indietro da sola — la "straggler",
-- che era poi la preda preferita degli U-Boot — e spesso affondava ore o
-- giorni dopo, lontano da chi l'aveva colpita.
--
-- Queste colonne portano il danno fuori dall'incontro e dentro il mondo.

ALTER TABLE ships
  ADD COLUMN IF NOT EXISTS integrita DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  ADD COLUMN IF NOT EXISTS allagamento DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS incendio DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS ritardataria TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS danno_gts BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS danno_boat_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS danno_patrol_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS danno_commander_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS affonda_gts BIGINT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS convoglio_perduto VARCHAR(16) NULL DEFAULT NULL;

-- La query del battito cerca chi deve andare a fondo adesso.
ALTER TABLE ships
  ADD INDEX IF NOT EXISTS idx_ships_agonia (state, affonda_gts);

-- Chi ha colpito. SET NULL e non CASCADE: se il battello sparisce, la nave
-- resta danneggiata lo stesso, semplicemente non la si accredita piu' a nessuno.
ALTER TABLE ships
  ADD CONSTRAINT fk_ships_danno_boat FOREIGN KEY IF NOT EXISTS (danno_boat_id)
      REFERENCES boats(id) ON DELETE SET NULL;
ALTER TABLE ships
  ADD CONSTRAINT fk_ships_danno_patrol FOREIGN KEY IF NOT EXISTS (danno_patrol_id)
      REFERENCES patrols(id) ON DELETE SET NULL;
ALTER TABLE ships
  ADD CONSTRAINT fk_ships_danno_commander FOREIGN KEY IF NOT EXISTS (danno_commander_id)
      REFERENCES commanders(id) ON DELETE SET NULL;
