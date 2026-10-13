-- 0049: i naufraghi
--
-- Dopo un affondamento, in mare restano le scialuppe. Che cosa farne era una
-- scelta del comandante: dare la rotta per terra, acqua e gallette; prendere a
-- bordo il comandante della nave, che a volte parlava; o andarsene. Dopo il
-- Laconia (settembre 1942) Doenitz proibi' i soccorsi; qui il mondo e' un
-- eterno 1942 e la scelta resta al comandante. Costa tempo in superficie, e
-- viveri; quello che ne viene e' il destino degli uomini, che si viene a
-- sapere giorni dopo.

CREATE TABLE IF NOT EXISTS naufraghi (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sinking_id        BIGINT UNSIGNED NOT NULL,
  boat_id           BIGINT UNSIGNED NOT NULL,
  patrol_id         BIGINT UNSIGNED NULL,
  commander_id      BIGINT UNSIGNED NULL,
  nave              VARCHAR(80) NOT NULL,
  lat               DECIMAL(8,5) NOT NULL,
  lon               DECIMAL(9,5) NOT NULL,
  gts               BIGINT NOT NULL,
  equipaggio        SMALLINT UNSIGNED NOT NULL,
  scialuppe         SMALLINT UNSIGNED NOT NULL,
  nelle_scialuppe   SMALLINT UNSIGNED NOT NULL,
  aiutati           TINYINT(1) NOT NULL DEFAULT 0,
  comandante_preso  TINYINT(1) NOT NULL DEFAULT 0,
  stato             ENUM('in_mare','raccolti','perduti') NOT NULL DEFAULT 'in_mare',
  salvati           SMALLINT UNSIGNED NULL,
  destino_gts       BIGINT NOT NULL COMMENT 'Quando se ne sapra'' la sorte',
  KEY idx_naufraghi_boat (boat_id, stato),
  KEY idx_naufraghi_destino (stato, destino_gts),
  UNIQUE KEY uq_naufraghi_sinking (sinking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
