<?php

declare(strict_types=1);

/**
 * Porti: le basi delle flottiglie atlantiche (kind = 'base') e i porti
 * alleati/neutri che serviranno alle rotte mercantili in F3.
 * Coordinate arrotondate al decimo di minuto, sufficiente per la navigazione.
 */

return [
    // --- Basi U-Boot ---------------------------------------------------------
    ['port_key' => 'lorient',       'name' => 'Lorient (Keroman)', 'country' => 'Francia occupata', 'lat' => 47.74800, 'lon' => -3.36700, 'kind' => 'base', 'flotillas' => '2. und 10. U-Flottille', 'note' => 'Il bunker piu' . "'" . ' grande: la casa della 2. e della 10. Flottiglia.'],
    ['port_key' => 'brest',         'name' => 'Brest',             'country' => 'Francia occupata', 'lat' => 48.38300, 'lon' => -4.50000, 'kind' => 'base', 'flotillas' => '1. und 9. U-Flottille', 'note' => 'La piu' . "'" . ' vicina alle Approaches occidentali, e la piu' . "'" . ' battuta dagli aerei.'],
    ['port_key' => 'saint_nazaire', 'name' => 'Saint-Nazaire',     'country' => 'Francia occupata', 'lat' => 47.28300, 'lon' => -2.20000, 'kind' => 'base', 'flotillas' => '6. und 7. U-Flottille', 'note' => 'Bunker e bacino: l\'unico porto atlantico capace di accogliere una corazzata.'],
    ['port_key' => 'la_pallice',    'name' => 'La Pallice (La Rochelle)', 'country' => 'Francia occupata', 'lat' => 46.15800, 'lon' => -1.21700, 'kind' => 'base', 'flotillas' => '3. U-Flottille', 'note' => null],
    ['port_key' => 'bordeaux',      'name' => 'Bordeaux',          'country' => 'Francia occupata', 'lat' => 44.86000, 'lon' => -0.55000, 'kind' => 'base', 'flotillas' => '12. U-Flottille', 'note' => 'Risalita della Gironda: due giorni di fiume prima del mare.'],
    ['port_key' => 'bergen',        'name' => 'Bergen',            'country' => 'Norvegia occupata', 'lat' => 60.39000, 'lon' => 5.32000, 'kind' => 'base', 'flotillas' => '11. U-Flottille', 'note' => 'Uscita a nord, fuori dalla Biscaglia.'],
    ['port_key' => 'trondheim',     'name' => 'Trondheim',         'country' => 'Norvegia occupata', 'lat' => 63.44000, 'lon' => 10.40000, 'kind' => 'base', 'flotillas' => '13. U-Flottille', 'note' => null],
    ['port_key' => 'kiel',          'name' => 'Kiel',              'country' => 'Germania',         'lat' => 54.32000, 'lon' => 10.14000, 'kind' => 'base', 'flotillas' => '5. U-Flottille', 'note' => 'Consegna dei battelli nuovi e addestramento.'],
    ['port_key' => 'wilhelmshaven', 'name' => 'Wilhelmshaven',     'country' => 'Germania',         'lat' => 53.51000, 'lon' => 8.14000, 'kind' => 'base', 'flotillas' => '2. U-Flottille (fino al 1941)', 'note' => 'La base del primo periodo, prima delle basi francesi.'],

    // --- Porti alleati e neutri (serviranno alle rotte mercantili) -----------
    ['port_key' => 'halifax',   'name' => 'Halifax',        'country' => 'Canada',        'lat' => 44.65000, 'lon' => -63.57000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Punto di raccolta dei convogli HX.'],
    ['port_key' => 'sydney_cb', 'name' => 'Sydney (Capo Bretone)', 'country' => 'Canada', 'lat' => 46.13000, 'lon' => -60.19000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Partenza dei convogli lenti SC.'],
    ['port_key' => 'st_johns',  'name' => "St. John's",     'country' => 'Terranova',     'lat' => 47.56000, 'lon' => -52.71000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Base dei gruppi di scorta canadesi.'],
    ['port_key' => 'new_york',  'name' => 'New York',       'country' => 'Stati Uniti',   'lat' => 40.70000, 'lon' => -74.01000, 'kind' => 'alleato', 'flotillas' => null, 'note' => null],
    ['port_key' => 'liverpool', 'name' => 'Liverpool',      'country' => 'Regno Unito',   'lat' => 53.41000, 'lon' => -3.00000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Quartier generale delle Approaches occidentali.'],
    ['port_key' => 'glasgow',   'name' => 'Glasgow (Clyde)','country' => 'Regno Unito',   'lat' => 55.86000, 'lon' => -4.25000, 'kind' => 'alleato', 'flotillas' => null, 'note' => null],
    ['port_key' => 'bristol',   'name' => 'Bristol',        'country' => 'Regno Unito',   'lat' => 51.45000, 'lon' => -2.59000, 'kind' => 'alleato', 'flotillas' => null, 'note' => null],
    ['port_key' => 'reykjavik', 'name' => 'Reykjavik',      'country' => 'Islanda',       'lat' => 64.15000, 'lon' => -21.94000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Scalo delle scorte sulla rotta settentrionale.'],
    ['port_key' => 'gibilterra','name' => 'Gibilterra',     'country' => 'Regno Unito',   'lat' => 36.14000, 'lon' => -5.35000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Convogli OG e HG.'],
    ['port_key' => 'freetown',  'name' => 'Freetown',       'country' => 'Sierra Leone',  'lat' => 8.48000,  'lon' => -13.23000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Convogli SL: navi isolate, caldo, usura.'],
    ['port_key' => 'trinidad',  'name' => 'Porto di Spagna','country' => 'Trinidad',      'lat' => 10.65000, 'lon' => -61.51000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Petroliere.'],
    ['port_key' => 'aruba',     'name' => 'Aruba (San Nicolas)', 'country' => 'Antille olandesi', 'lat' => 12.43000, 'lon' => -69.90000, 'kind' => 'alleato', 'flotillas' => null, 'note' => 'Raffinerie: bersaglio di pregio.'],
    ['port_key' => 'lisbona',   'name' => 'Lisbona',        'country' => 'Portogallo',    'lat' => 38.71000, 'lon' => -9.14000, 'kind' => 'neutro', 'flotillas' => null, 'note' => 'Neutrale: attenzione alla bandiera.'],
    ['port_key' => 'vigo',      'name' => 'Vigo',           'country' => 'Spagna',        'lat' => 42.24000, 'lon' => -8.72000, 'kind' => 'neutro', 'flotillas' => null, 'note' => 'Neutrale.'],
];
