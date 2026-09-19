-- L'unicita' solo dove ha senso.
--
-- La 0025 aveva bloccato quattro cose: nome del comandante, ritratto, emblema di
-- torretta e numero di U-Boot. Sull'emblema era sbagliato, e la storia lo dice
-- chiaramente: l'emblema di torretta non era sempre personale del battello.
--
--   Il toro che sbuffa nasce con U-47 di Prien dopo Scapa Flow e diventa poi
--   l'emblema di TUTTA la 7. U-Flottille: lo portavano decine di battelli.
--   Il pesce sega ridente di U-96 diventa il segno della 9. Flottille.
--   E un comandante che cambiava battello si portava dietro il proprio: il
--   diavolo rosso di Topp passo' da U-57 a U-552.
--
-- Quindi due battelli possono benissimo avere lo stesso emblema, ed e' anzi il
-- caso normale per i segni di flottiglia. Il vincolo se ne va.
--
-- Se ne va anche quello sull'impronta della fotografia caricata: se un giocatore
-- si porta da casa un'immagine, sono affari suoi, e non c'e' motivo di impedire
-- a due persone di caricare lo stesso file.
--
-- Resta bloccato quello che identifica davvero qualcuno in flottiglia, e resta
-- bloccato solo FRA I VIVI, come nella 0025:
--
--   il NOME del comandante          — in flottiglia ci si chiama per cognome
--   il RITRATTO preso dalla galleria — due comandanti non sono la stessa persona
--   il NUMERO dell'U-Boot           — due battelli non hanno lo stesso scafo

ALTER TABLE boats DROP INDEX IF EXISTS uq_vivo_emblema_key;
ALTER TABLE boats DROP INDEX IF EXISTS uq_vivo_emblema_hash;
ALTER TABLE boats DROP COLUMN IF EXISTS vivo_emblema_key;
ALTER TABLE boats DROP COLUMN IF EXISTS vivo_emblema_hash;

ALTER TABLE commanders DROP INDEX IF EXISTS uq_vivo_ritratto_hash;
ALTER TABLE commanders DROP COLUMN IF EXISTS vivo_ritratto_hash;
