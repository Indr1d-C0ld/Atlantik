-- 0021_civetta_smascherata : quando la maschera cade, resta caduta
--
-- La nave civetta finge finche' non spara. Dal primo colpo in poi e' quello che
-- e', e non torna a sembrare un piroscafo: chi ha visto i pannelli cadere non
-- se lo dimentica.

ALTER TABLE encounter_entities ADD COLUMN IF NOT EXISTS smascherata TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'La nave ha aperto il fuoco e non finge piu';
