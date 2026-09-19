<?php

declare(strict_types=1);

/**
 * Disposizione interna dei battelli, da prua a poppa.
 *
 * Il Tipo VII e' il riferimento: un tubo di poco piu' di sei metri di diametro
 * con i compartimenti in fila, separati da paratie stagne solo in tre punti
 * (prua, centrale, poppa) — le altre divisioni sono di tende e armadietti.
 * La camera siluri di prua e' anche l'alloggio di ventiquattro uomini, che
 * dormono sopra e sotto i siluri di riserva.
 *
 * fonte: disposizioni costruttive dei Tipi II, VII, IX e XXI.
 */

return [
    'VII' => [
        ['ckey' => 'prua',      'name' => 'Camera siluri di prua',      'seq' => 1],
        ['ckey' => 'sottuff',   'name' => 'Alloggi sottufficiali',      'seq' => 2],
        ['ckey' => 'quadrato',  'name' => 'Quadrato ufficiali e radio', 'seq' => 3],
        ['ckey' => 'zentrale',  'name' => 'Centrale e torretta',        'seq' => 4],
        ['ckey' => 'cucina',    'name' => 'Cucina e alloggi',           'seq' => 5],
        ['ckey' => 'diesel',    'name' => 'Locale diesel',              'seq' => 6],
        ['ckey' => 'elettrico', 'name' => 'Locale motori elettrici',    'seq' => 7],
        ['ckey' => 'poppa',     'name' => 'Camera siluri di poppa',     'seq' => 8],
    ],
    'II' => [
        ['ckey' => 'prua',      'name' => 'Camera siluri di prua',      'seq' => 1],
        ['ckey' => 'quadrato',  'name' => 'Alloggi e radio',            'seq' => 2],
        ['ckey' => 'zentrale',  'name' => 'Centrale e torretta',        'seq' => 3],
        ['ckey' => 'diesel',    'name' => 'Locale diesel',              'seq' => 4],
        ['ckey' => 'elettrico', 'name' => 'Locale motori elettrici',    'seq' => 5],
    ],
    'IX' => [
        ['ckey' => 'prua',      'name' => 'Camera siluri di prua',      'seq' => 1],
        ['ckey' => 'sottuff',   'name' => 'Alloggi sottufficiali',      'seq' => 2],
        ['ckey' => 'quadrato',  'name' => 'Quadrato ufficiali e radio', 'seq' => 3],
        ['ckey' => 'zentrale',  'name' => 'Centrale e torretta',        'seq' => 4],
        ['ckey' => 'cucina',    'name' => 'Cucina e alloggi',           'seq' => 5],
        ['ckey' => 'diesel',    'name' => 'Locale diesel',              'seq' => 6],
        ['ckey' => 'elettrico', 'name' => 'Locale motori elettrici',    'seq' => 7],
        ['ckey' => 'ausiliari', 'name' => 'Locale ausiliari',           'seq' => 8],
        ['ckey' => 'poppa',     'name' => 'Camera siluri di poppa',     'seq' => 9],
    ],
    'XXI' => [
        ['ckey' => 'prua',      'name' => 'Camera siluri di prua',      'seq' => 1],
        ['ckey' => 'sottuff',   'name' => 'Alloggi sottufficiali',      'seq' => 2],
        ['ckey' => 'quadrato',  'name' => 'Quadrato ufficiali e radio', 'seq' => 3],
        ['ckey' => 'zentrale',  'name' => 'Centrale',                   'seq' => 4],
        ['ckey' => 'batterie',  'name' => 'Locale batterie',            'seq' => 5],
        ['ckey' => 'cucina',    'name' => 'Cucina e alloggi',           'seq' => 6],
        ['ckey' => 'diesel',    'name' => 'Locale diesel',              'seq' => 7],
        ['ckey' => 'elettrico', 'name' => 'Locale motori elettrici',    'seq' => 8],
    ],
];
