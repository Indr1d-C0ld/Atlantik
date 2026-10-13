-- 0047: la guerra delle informazioni
--
-- Fino al 09/10/2026 la deviazione di un convoglio si estraeva alla partenza
-- (al massimo due miglia di scostamento) e restava quella fino all'arrivo: dove
-- gli U-Boot si facevano sentire, il traffico continuava a passare. Era il
-- contrario di come andava. La Submarine Tracking Room dell'Ammiragliato
-- spostava i convogli lontano dai sommergibili localizzati — nel 1942, con
-- Ultra cieco sul traffico degli U-Boot (Triton, da febbraio a dicembre), quasi
-- solo coi radiogoniometri e gli avvistamenti — e il B-Dienst tedesco, che
-- leggeva i cifrari dei convogli, a volte lo sapeva.
--
-- La posizione di un convoglio e' una funzione pura (Traffic::posizione), e
-- cambiare la deviazione di colpo lo teletrasporterebbe. Allora la deviazione
-- cambia nel tempo: da dev_da verso deviazione, a partire da dev_inizio_gts,
-- alla velocita' laterale di un convoglio che accosta di trenta gradi. Le navi
-- che lasciano il convoglio si portano dietro la deviazione del momento.

ALTER TABLE convoys
  ADD COLUMN IF NOT EXISTS dev_da DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Deviazione da cui si parte nel cambio in corso',
  ADD COLUMN IF NOT EXISTS dev_inizio_gts BIGINT NULL COMMENT 'Quando l''Ammiragliato ha ordinato la deviazione in corso',
  ADD COLUMN IF NOT EXISTS dev_valutata_gts BIGINT NULL COMMENT 'Ultima volta che l''Ammiragliato ha guardato la rotta',
  ADD COLUMN IF NOT EXISTS bdienst_gts BIGINT NULL COMMENT 'Quando il B-Dienst decifrera'' la deviazione: allora il BdU la comunica';

ALTER TABLE convoys MODIFY COLUMN deviazione DECIMAL(6,2) NOT NULL DEFAULT 0;

ALTER TABLE ships ADD COLUMN IF NOT EXISTS deviazione DECIMAL(6,2) NOT NULL DEFAULT 0
  COMMENT 'Deviazione con cui la nave ha lasciato il convoglio';

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('ammiragliato.soglia_calore', '30', 'float', 'Calore di settore oltre il quale l''Ammiragliato fa girare i convogli al largo'),
  ('ammiragliato.raggio_nm', '120', 'float', 'Distanza da un pericolo noto a cui l''Ammiragliato tiene i convogli'),
  ('ammiragliato.bdienst', '0.35', 'float', 'Probabilita'' che il B-Dienst decifri una deviazione e il BdU la comunichi')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- L'ultimo comunicato del B-Dienst su cui un collega ha fatto rotta: lo si
-- segue una volta, poi si torna all'area.
ALTER TABLE boats ADD COLUMN IF NOT EXISTS bdienst_msg_id BIGINT UNSIGNED NULL
  COMMENT 'Ultimo comunicato del B-Dienst seguito da un collega';
