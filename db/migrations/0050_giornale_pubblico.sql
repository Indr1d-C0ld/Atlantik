-- 0050: il giornale di guerra pubblico
--
-- Il comandante puo' pubblicare il giornale di una missione di guerra conclusa:
-- una pagina leggibile da chiunque abbia il collegamento, impaginata come un
-- Kriegstagebuch, con la carta della rotta. Le comunita' di questi giochi vivono
-- di resoconti di missione, e questo e' il modo naturale di condividerne uno.
-- Il collegamento ha un gettone non indovinabile; ritirarlo lo spegne.
-- Mai le missioni in corso (sarebbe dire agli altri dove si e') e mai
-- l'addestramento.

ALTER TABLE patrols ADD COLUMN IF NOT EXISTS pubblica_token CHAR(24) NULL
  COMMENT 'Gettone del giornale pubblico, NULL se non pubblicato';
ALTER TABLE patrols ADD UNIQUE INDEX IF NOT EXISTS uq_patrols_pubblica (pubblica_token);
