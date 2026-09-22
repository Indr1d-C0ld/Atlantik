<?php

declare(strict_types=1);

/**
 * Sistemi di bordo: quello che si puo' rompere, e cosa comporta.
 *
 * `failure_rate` e' la probabilita' di guasto per ora di esercizio in
 * condizioni normali. I valori sono scelti perche' una patrol lunga (quattro
 * settimane, circa 700 ore) produca un pugno di avarie — come accadeva: i
 * giornali di guerra sono pieni di piccoli guasti, e ogni tanto di uno grosso.
 * Sono moltiplicati per l'usura della missione, il regime delle macchine, la
 * qualita' dell'equipaggio e lo stato del mare (vedi Sim\Damage).
 *
 * `repair_hours` sono ore-uomo: due macchinisti bravi ci mettono meno.
 * `repairable_sea` a zero vuol dire che quella cosa a mare non si aggiusta —
 * e allora la patrol e' finita, e il ritorno diventa esso stesso una missione.
 */

return [
    // --- Propulsione ---------------------------------------------------------
    ['skey' => 'diesel_1',    'name' => 'Diesel di dritta',            'category' => 'propulsione', 'compartment' => 'diesel',    'specialty' => 'macchinista_diesel', 'repairable_sea' => 1, 'repair_hours' => 6,  'failure_rate' => 0.00085, 'note' => 'Un diesel fuori uso dimezza la potenza in superficie.'],
    ['skey' => 'diesel_2',    'name' => 'Diesel di sinistra',          'category' => 'propulsione', 'compartment' => 'diesel',    'specialty' => 'macchinista_diesel', 'repairable_sea' => 1, 'repair_hours' => 6,  'failure_rate' => 0.00085, 'note' => null],
    ['skey' => 'emotore_1',   'name' => 'Motore elettrico di dritta',  'category' => 'propulsione', 'compartment' => 'elettrico', 'specialty' => 'macchinista_elettrico', 'repairable_sea' => 1, 'repair_hours' => 5,  'failure_rate' => 0.00045, 'note' => null],
    ['skey' => 'emotore_2',   'name' => 'Motore elettrico di sinistra','category' => 'propulsione', 'compartment' => 'elettrico', 'specialty' => 'macchinista_elettrico', 'repairable_sea' => 1, 'repair_hours' => 5,  'failure_rate' => 0.00045, 'note' => null],
    ['skey' => 'batterie',    'name' => 'Batterie di accumulatori',    'category' => 'propulsione', 'compartment' => 'elettrico', 'specialty' => 'macchinista_elettrico', 'repairable_sea' => 1, 'repair_hours' => 10, 'failure_rate' => 0.00030, 'note' => 'Se allagate sviluppano cloro: e\' il modo peggiore di morire a bordo.'],

    // --- Governo e immersione ------------------------------------------------
    ['skey' => 'timoni_orizz','name' => 'Timoni orizzontali',          'category' => 'governo',     'compartment' => 'zentrale',  'specialty' => 'zentrale',   'repairable_sea' => 1, 'repair_hours' => 4,  'failure_rate' => 0.00035, 'note' => 'Senza, la quota si tiene solo con l\'assetto e la velocita\'.'],
    ['skey' => 'timone',      'name' => 'Timone verticale',            'category' => 'governo',     'compartment' => 'poppa',     'specialty' => 'zentrale',   'repairable_sea' => 1, 'repair_hours' => 5,  'failure_rate' => 0.00025, 'note' => null],
    ['skey' => 'pompe',       'name' => 'Pompe di esaurimento',        'category' => 'governo',     'compartment' => 'zentrale',  'specialty' => 'macchinista_elettrico', 'repairable_sea' => 1, 'repair_hours' => 3, 'failure_rate' => 0.00055, 'note' => 'Servono a togliere l\'acqua e a tenere l\'assetto. Fanno rumore.'],
    ['skey' => 'compressori', 'name' => 'Compressori d\'aria',         'category' => 'governo',     'compartment' => 'diesel',    'specialty' => 'macchinista_diesel', 'repairable_sea' => 1, 'repair_hours' => 4,  'failure_rate' => 0.00045, 'note' => 'Senza aria compressa non si soffiano le casse: si emerge solo coi motori.'],
    ['skey' => 'casse',       'name' => 'Casse di zavorra e sfiati',   'category' => 'governo',     'compartment' => 'zentrale',  'specialty' => 'zentrale',   'repairable_sea' => 1, 'repair_hours' => 6,  'failure_rate' => 0.00020, 'note' => null],

    // --- Scoperta -------------------------------------------------------------
    ['skey' => 'periscopio_att','name' => 'Periscopio d\'attacco',     'category' => 'scoperta',    'compartment' => 'zentrale',  'specialty' => 'zentrale',   'repairable_sea' => 0, 'repair_hours' => 18,  'failure_rate' => 0.00018, 'note' => 'Piegato o allagato non si ripara a mare: bisogna rientrare.'],
    ['skey' => 'periscopio_osc','name' => 'Periscopio di scoperta',    'category' => 'scoperta',    'compartment' => 'zentrale',  'specialty' => 'zentrale',   'repairable_sea' => 0, 'repair_hours' => 16,  'failure_rate' => 0.00015, 'note' => null],
    ['skey' => 'idrofono',    'name' => 'Idrofono GHG',                'category' => 'scoperta',    'compartment' => 'quadrato',  'specialty' => 'radiotelegrafista', 'repairable_sea' => 1, 'repair_hours' => 4, 'failure_rate' => 0.00030, 'note' => 'Gli orecchi del battello: senza, sott\'acqua si e\' ciechi.'],
    ['skey' => 'radio',       'name' => 'Stazione radio',              'category' => 'scoperta',    'compartment' => 'quadrato',  'specialty' => 'radiotelegrafista', 'repairable_sea' => 1, 'repair_hours' => 3, 'failure_rate' => 0.00028, 'note' => null],

    // --- Scafo e armamento -----------------------------------------------------
    ['skey' => 'scafo',       'name' => 'Scafo resistente',            'category' => 'scafo',       'compartment' => 'zentrale',  'specialty' => 'zentrale',   'repairable_sea' => 0, 'repair_hours' => 30,  'failure_rate' => 0.00004, 'note' => 'Le deformazioni da pressione non si raddrizzano: restano, e abbassano la quota di collasso.'],
    ['skey' => 'tubi_prua',   'name' => 'Tubi lanciasiluri di prua',   'category' => 'armamento',   'compartment' => 'prua',      'specialty' => 'silurista',  'repairable_sea' => 1, 'repair_hours' => 5,  'failure_rate' => 0.00025, 'note' => null],
    ['skey' => 'tubi_poppa',  'name' => 'Tubo lanciasiluri di poppa',  'category' => 'armamento',   'compartment' => 'poppa',     'specialty' => 'silurista',  'repairable_sea' => 1, 'repair_hours' => 5,  'failure_rate' => 0.00022, 'note' => null],
    ['skey' => 'cannone',     'name' => 'Cannone di coperta',          'category' => 'armamento',   'compartment' => 'zentrale',  'specialty' => 'silurista',  'repairable_sea' => 1, 'repair_hours' => 3,  'failure_rate' => 0.00015, 'note' => 'L\'acqua di mare non gli fa bene.'],
    ['skey' => 'flak',        'name' => 'Mitragliera antiaerea',       'category' => 'armamento',   'compartment' => 'zentrale',  'specialty' => 'silurista',  'repairable_sea' => 1, 'repair_hours' => 2,  'failure_rate' => 0.00020, 'note' => null],
];
