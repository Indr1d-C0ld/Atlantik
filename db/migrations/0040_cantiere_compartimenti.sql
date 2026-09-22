-- 0040: il bacino lavora anche sui compartimenti
--
-- Con la 0039 il cantiere ha cominciato a riparare i sistemi mentre il
-- battello sta in porto. Restavano fuori i compartimenti: l'integrita', gli
-- incendi spenti male e soprattutto le paratie sigillate, che a mare non si
-- riaprono per nessun motivo. Di quelli si occupava Damage::overhaul, tutto
-- insieme e gratis, nell'istante della partenza.
--
-- Ora che il tempo in banchina conta, anche questo lavoro costa ore, e le ore
-- vanno ricordate fra un battito e l'altro: mezz'ora di gioco per volta non
-- chiuderebbe mai un lavoro da dieci ore-uomo se ogni battito ripartisse da
-- zero.
--
-- DECIMAL(9,5) e non meno, per lo stesso motivo della 0033: con pochi decimali
-- gli incrementi piccoli si perdono nell'arrotondamento e la barra non si
-- muove piu'.

ALTER TABLE boat_compartments
  ADD COLUMN IF NOT EXISTS repair_progress DECIMAL(9,5) NOT NULL DEFAULT 0.00000;
