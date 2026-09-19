-- 0018_contatto_classe : la classe che il contatto SEMBRA, non quella che e'
--
-- Sulla pagina d'ascolto la sagoma compare solo quando il contatto e' stato
-- davvero classificato a vista. Per farlo serve sapere di che classe il
-- comandante CREDE che sia — e quella la si scrive solo quando qualcuno l'ha
-- guardata davvero.
--
-- All'idrofono resta NULL, e deve restarci: si sente un battito d'elica e il
-- Funkmaat dice "mercantile isolato", non "piroscafo a tre isole". Una sagoma
-- accanto a un contatto acustico regalerebbe al giocatore un'informazione che
-- il comandante non aveva.

ALTER TABLE contacts ADD COLUMN IF NOT EXISTS classe_key_est VARCHAR(32) NULL
  COMMENT 'Classe riconosciuta a vista. NULL se il contatto non e stato identificato';
