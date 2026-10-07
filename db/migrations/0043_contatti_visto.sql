-- L'ultima volta che un contatto si e' visto (07/10/2026).
-- La classe riconosciuta segue il sensore del momento e si toglie quando resta
-- solo l'idrofono; l'ora dell'ultimo avvistamento resta, ed e' la traccia che
-- quel contatto e' stato visto davvero.
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS visto_gts BIGINT NULL COMMENT 'Ultimo istante di gioco in cui il contatto si e visto, NULL se mai';
