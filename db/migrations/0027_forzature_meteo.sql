-- Forzature del meteo: la mano dell'amministratore sul tempo.
--
-- Il meteo e' una funzione pura del seme, dell'istante e del posto: non sta in
-- tabella, si calcola. Per poterlo forzare senza rompere quella proprieta' si
-- aggiunge uno strato sopra: una forzatura vale in un cerchio, per una
-- finestra di tempo di gioco, e dice solo i campi che vuole dire. Fuori dal
-- cerchio e fuori dalla finestra il mondo torna quello di prima, da solo.
--
-- raggio_nm NULL = tutto il teatro. scadenza_gts NULL = finche' non si toglie.

CREATE TABLE IF NOT EXISTS weather_overrides (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lat            DECIMAL(8,5)    NULL,
    lon            DECIMAL(9,5)    NULL,
    raggio_nm      INT UNSIGNED    NULL,
    da_gts         BIGINT          NOT NULL,
    scadenza_gts   BIGINT          NULL,
    wind_kn        DECIMAL(5,1)    NULL,
    wind_dir       DECIMAL(5,1)    NULL,
    sea_state      TINYINT         NULL,
    visibility_nm  DECIMAL(6,2)    NULL,
    fog            TINYINT(1)      NULL,
    cloud          DECIMAL(4,3)    NULL,
    nota           VARCHAR(255)    NULL,
    creato_da      BIGINT UNSIGNED NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_finestra (da_gts, scadenza_gts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
