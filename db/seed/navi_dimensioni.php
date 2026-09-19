<?php

declare(strict_types=1);

/**
 * Dimensioni documentate delle unita' che il motore disegna, e come si presentano di profilo.
 *
 * Da qui bin/disegna_scorte.php ricava le sagome. Il dato e' questo file: il
 * disegno e' una conseguenza, e si rifa' cambiando i numeri.
 *
 * COSA E' DOCUMENTATO E COSA NO — la distinzione conta:
 *
 *   documentato   lunghezza fuori tutto, baglio, dislocamento, numero di
 *                 fumaioli e di alberi, numero e posizione dei pezzi principali.
 *                 Sono dati pubblicati, e la fonte e' citata riga per riga.
 *
 *   ricostruito   le PROPORZIONI delle sovrastrutture: quanto e' lungo il
 *                 castello, quanto e' alto il fumaiolo, dove comincia la plancia.
 *                 Ricavate dalla descrizione del profilo, non da un piano di
 *                 costruzione. Sono la parte che rende la sagoma riconoscibile,
 *                 ed e' la parte che non posso dichiarare esatta.
 *
 * Tutte le lunghezze in metri. Le posizioni sono frazioni della lunghezza
 * misurate da prua.
 */

return [
    'corvetta_flower' => [
        'nome'        => 'Corvetta classe Flower',
        'lunghezza'   => 62.5,
        'baglio'      => 10.1,
        'pescaggio'   => 3.51,
        'dislocamento'=> 925,
        'fonte'       => 'Flower-class corvette, dati generali (925 t, 205 ft / 62,5 m f.t., '
                       . '33 ft / 10,1 m di baglio, 11,5 ft / 3,51 m di pescaggio)',
        'profilo'     => 'Castello rialzato, pozzo, plancia, ponte continuo fino a poppa, '
                       . 'poppa da incrociatore. Albero subito a proravia della plancia. '
                       . 'Un pezzo da 4 pollici sul castello.',
        'bordo_libero'=> 4.4,
        'castello'    => ['fino' => 0.30, 'alt' => 2.1],
        'plancia'     => ['da' => 0.33, 'a' => 0.44, 'alt' => 4.0],
        'fumaioli'    => [['a' => 0.53, 'alt' => 7.0, 'largo' => 3.2]],
        'alberi'      => [['a' => 0.345, 'alt' => 14.0]],
        'pezzi'       => [['a' => 0.16, 'su' => 'castello']],
        'poppa'       => 'incrociatore',
    ],

    'fregata_river' => [
        'nome'        => 'Fregata classe River',
        'lunghezza'   => 91.8,
        'baglio'      => 11.1,
        'pescaggio'   => 4.0,
        'dislocamento'=> 1370,
        'fonte'       => 'River-class frigate, gruppo I (1.370 t standard, 301,25 ft / 91,8 m f.t., '
                       . '36 ft 6 in / 11,1 m di baglio, 13 ft / 4,0 m a pieno carico). '
                       . 'Due pezzi da 4 pollici in impianti singoli.',
        'profilo'     => 'Castello lungo, un fumaiolo, un albero. Un pezzo a proravia e uno a poppavia.',
        'bordo_libero'=> 5.0,
        'castello'    => ['fino' => 0.46, 'alt' => 2.4],
        'plancia'     => ['da' => 0.36, 'a' => 0.47, 'alt' => 4.6],
        'fumaioli'    => [['a' => 0.56, 'alt' => 7.5, 'largo' => 3.6]],
        'alberi'      => [['a' => 0.40, 'alt' => 16.0]],
        'pezzi'       => [['a' => 0.15, 'su' => 'castello'], ['a' => 0.82, 'su' => 'ponte']],
        'poppa'       => 'incrociatore',
    ],

    'ct_vw' => [
        'nome'        => 'Cacciatorpediniere classe V&W',
        'lunghezza'   => 95.1,
        'baglio'      => 9.0,
        'pescaggio'   => 3.6,
        'dislocamento'=> 1100,
        'fonte'       => 'V and W-class destroyer (circa 1.100 t standard, 312 ft / 95,1 m f.t., '
                       . '29 ft 6 in / 9,0 m di baglio, 11 ft 9 in a pieno carico). Quattro pezzi '
                       . 'da 4 pollici in posizione A, B, X, Y.',
        'profilo'     => 'Castello rialzato con la plancia sopra, DUE fumaioli di altezza diversa: '
                       . 'il poppiero piu' . "'" . ' basso e piu' . "'" . ' largo. Sagoma asimmetrica, ed e' . "'" . ' '
                       . 'il segno che li distingue.',
        'bordo_libero'=> 4.2,
        'castello'    => ['fino' => 0.36, 'alt' => 2.3],
        'plancia'     => ['da' => 0.27, 'a' => 0.37, 'alt' => 4.4],
        'fumaioli'    => [
            ['a' => 0.45, 'alt' => 8.4, 'largo' => 3.0],
            ['a' => 0.60, 'alt' => 6.8, 'largo' => 4.0],
        ],
        'alberi'      => [['a' => 0.39, 'alt' => 17.0]],
        'pezzi'       => [
            ['a' => 0.13, 'su' => 'castello'],
            ['a' => 0.24, 'su' => 'castello'],
            ['a' => 0.79, 'su' => 'ponte'],
            ['a' => 0.90, 'su' => 'ponte'],
        ],
        'poppa'       => 'tonda',
    ],

    'ct_town' => [
        'nome'        => 'Cacciatorpediniere classe Town',
        'lunghezza'   => 95.8,
        'baglio'      => 9.6,
        'pescaggio'   => 3.9,
        'dislocamento'=> 1190,
        'fonte'       => 'Town-class destroyer, tipo A ex Clemson (1.190 t standard, 314 ft 4 in / '
                       . '95,8 m f.t., 31 ft 8 in / 9,6 m di baglio, 12 ft 10 in di pescaggio). '
                       . 'QUATTRO fumaioli: erano chiamati "four-pipers". Scafo a ponte continuo.',
        'profilo'     => 'Ponte continuo, senza castello rialzato: e' . "'" . ' la differenza che si vede '
                       . 'subito rispetto a un cacciatorpediniere britannico. Quattro fumaioli '
                       . 'ravvicinati e uguali.',
        'bordo_libero'=> 4.0,
        'castello'    => ['fino' => 0.0, 'alt' => 0.0],
        'plancia'     => ['da' => 0.24, 'a' => 0.33, 'alt' => 4.2],
        'fumaioli'    => [
            ['a' => 0.38, 'alt' => 7.6, 'largo' => 2.6],
            ['a' => 0.47, 'alt' => 7.6, 'largo' => 2.6],
            ['a' => 0.56, 'alt' => 7.6, 'largo' => 2.6],
            ['a' => 0.65, 'alt' => 7.6, 'largo' => 2.6],
        ],
        'alberi'      => [['a' => 0.35, 'alt' => 16.0]],
        'pezzi'       => [['a' => 0.13, 'su' => 'ponte'], ['a' => 0.90, 'su' => 'ponte']],
        'poppa'       => 'tonda',
    ],

    'sloop_black_swan' => [
        'nome'        => 'Sloop classe Black Swan',
        'lunghezza'   => 91.3,
        'baglio'      => 11.4,
        'pescaggio'   => 3.35,
        'dislocamento'=> 1250,
        'fonte'       => 'Black Swan-class sloop (1.250 t, 299 ft 6 in / 91,3 m f.t., 37 ft 6 in / '
                       . '11,4 m di baglio, 11 ft / 3,35 m di pescaggio). Sei pezzi da 4 pollici '
                       . 'contraerei in tre impianti binati.',
        'profilo'     => 'Nave da guerra costruita apposta, non un mercantile adattato: castello '
                       . 'lungo, un fumaiolo, impianti binati a proravia e a poppavia.',
        'bordo_libero'=> 5.0,
        'castello'    => ['fino' => 0.45, 'alt' => 2.5],
        'plancia'     => ['da' => 0.34, 'a' => 0.46, 'alt' => 4.8],
        'fumaioli'    => [['a' => 0.54, 'alt' => 7.2, 'largo' => 3.4]],
        'alberi'      => [['a' => 0.42, 'alt' => 16.5]],
        'pezzi'       => [
            ['a' => 0.16, 'su' => 'castello'],
            ['a' => 0.27, 'su' => 'castello'],
            ['a' => 0.80, 'su' => 'ponte'],
        ],
        'poppa'       => 'incrociatore',
    ],

    'trawler_armato' => [
        'nome'        => 'Peschereccio armato',
        'lunghezza'   => 50.0,
        'baglio'      => 8.4,
        'pescaggio'   => 3.2,
        'dislocamento'=> 530,
        'fonte'       => 'Peschereccio d\'altura requisito, misure tipiche della classe Isles '
                       . '(circa 545 t, 164 ft / 50 m f.t., 27 ft 6 in / 8,4 m di baglio). '
                       . 'Il gioco ne dichiara 530.',
        'profilo'     => 'Scafo da pesca: castello corto e alto, plancia e fumaiolo spostati a '
                       . 'poppavia del mezzo, albero da carico a proravia. Un pezzo a prua.',
        'bordo_libero'=> 4.0,
        'castello'    => ['fino' => 0.26, 'alt' => 2.2],
        'plancia'     => ['da' => 0.50, 'a' => 0.64, 'alt' => 4.4],
        'fumaioli'    => [['a' => 0.68, 'alt' => 6.4, 'largo' => 3.0]],
        'alberi'      => [['a' => 0.36, 'alt' => 13.0]],
        'pezzi'       => [['a' => 0.13, 'su' => 'castello']],
        'poppa'       => 'tonda',
    ],

    'peschereccio' => [
        'nome'        => 'Peschereccio d\'altura',
        'lunghezza'   => 44.0,
        'baglio'      => 7.6,
        'pescaggio'   => 3.0,
        'dislocamento'=> 450,
        'fonte'       => 'Peschereccio d\'altura non armato, misure tipiche del naviglio da pesca '
                       . 'britannico d\'altura del periodo.',
        'profilo'     => 'Come il peschereccio armato, senza il pezzo a prua.',
        'bordo_libero'=> 3.8,
        'castello'    => ['fino' => 0.26, 'alt' => 2.1],
        'plancia'     => ['da' => 0.50, 'a' => 0.64, 'alt' => 4.2],
        'fumaioli'    => [['a' => 0.68, 'alt' => 6.0, 'largo' => 2.8]],
        'alberi'      => [['a' => 0.36, 'alt' => 12.0]],
        'pezzi'       => [],
        'poppa'       => 'tonda',
    ],

    // --- Non e' una scorta, ma si disegna allo stesso modo ------------------
    'frigorifera' => [
        'nome'        => 'Nave frigorifera',
        'lunghezza'   => 128.0,
        'baglio'      => 16.6,
        'pescaggio'   => 8.2,
        'dislocamento'=> 6100,
        'fonte'       => 'Misure TIPICHE di una frigorifera da circa 6.000 tonnellate di stazza '
                       . 'lorda del periodo: non di una nave precisa. Nessun manuale di '
                       . 'riconoscimento in pubblico dominio ne porta un profilo identificabile, '
                       . 'e una frigorifera somiglia a un piroscafo da carico — e' . "'" . ' il motivo per '
                       . 'cui questa casella era rimasta vuota.',
        'profilo'     => 'Carico veloce: scafo lungo e filante, castello, tuga centrale alta con un '
                       . 'fumaiolo solo, due coppie di alberi con i picchi di carico. Niente armi.',
        'bordo_libero'=> 6.4,
        'castello'    => ['fino' => 0.14, 'alt' => 2.6],
        'plancia'     => ['da' => 0.44, 'a' => 0.58, 'alt' => 5.5],
        'fumaioli'    => [['a' => 0.555, 'alt' => 13.0, 'largo' => 4.4]],
        'alberi'      => [['a' => 0.24, 'alt' => 20.0], ['a' => 0.76, 'alt' => 19.0]],
        'pezzi'       => [],
        'poppa'       => 'incrociatore',
    ],
];
