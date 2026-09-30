-- Punti di rotta aggiunti dall'Obersteuermann (30/09/2026).
-- Quando la rotta tracciata dal comandante taglia la terraferma, o parte da un
-- porto, la centrale ci mette i punti che servono per doppiare la costa o per
-- percorrere il canale d'uscita. Si distinguono da quelli del comandante: sulla
-- carta si disegnano diversi, e a ogni nuova rotta si ricalcolano da capo.
ALTER TABLE boat_waypoints ADD COLUMN IF NOT EXISTS auto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Punto aggiunto dalla centrale per evitare la terraferma o seguire un canale';
