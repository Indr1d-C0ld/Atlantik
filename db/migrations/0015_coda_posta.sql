-- 0015_coda_posta : la posta non si perde piu' per strada
--
-- Prima: se l'SMTP non rispondeva, il messaggio era perduto. Per un gioco in
-- cui NON si entra senza aver confermato l'indirizzo, quella era l'unica porta
-- d'ingresso, e si chiudeva in silenzio (audit A6).
--
-- Ora ogni messaggio passa di qui: si tenta subito, e se non riesce resta in
-- coda con attesa crescente. La tabella serve anche da registro degli invii,
-- che e' l'unico modo di sapere quanto manca al tetto giornaliero del provider.

CREATE TABLE IF NOT EXISTS mail_queue (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  destinatario VARCHAR(190) NOT NULL,
  oggetto      VARCHAR(255) NOT NULL,
  corpo        MEDIUMTEXT NOT NULL,
  genere       VARCHAR(32) NOT NULL DEFAULT 'generico',
  priorita     TINYINT NOT NULL DEFAULT 5,       -- 1 = prima di tutto (verifica), 9 = ultima
  tentativi    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  prossimo_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  inviato_at   DATETIME NULL,
  rinunciato_at DATETIME NULL,
  ultimo_errore VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coda_da_fare (inviato_at, rinunciato_at, prossimo_at, priorita),
  KEY idx_coda_inviati (inviato_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, note) VALUES
  ('mail.max_tentativi', '6',   'Quante volte si riprova un messaggio prima di rinunciare'),
  ('mail.tetto_24h',     '280', 'Tetto di invii nelle ultime 24 ore (Brevo gratuito ne concede 300)'),
  ('mail.per_battito',   '5',   'Quanti messaggi in coda si tentano a ogni battito')
ON DUPLICATE KEY UPDATE cvalue = cvalue;
