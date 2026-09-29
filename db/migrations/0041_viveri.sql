-- 0041: da quando si e' senza viveri
--
-- Fino al 29/09/2026 i viveri pesavano solo sul morale, e un battello poteva
-- restare in mare a oltranza a viveri finiti. Adesso il digiuno dura, e dopo
-- qualche giorno gli uomini si ammalano: per saperlo serve ricordare quando
-- la dispensa si e' svuotata. NULL finche' c'e' da mangiare.

ALTER TABLE boats ADD COLUMN IF NOT EXISTS senza_viveri_gts BIGINT NULL
  COMMENT 'Istante di gioco in cui i viveri sono finiti, NULL se ce ne sono';
