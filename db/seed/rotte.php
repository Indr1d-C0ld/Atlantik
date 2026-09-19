<?php

declare(strict_types=1);

/**
 * Rotte mercantili e serie di convogli dell'Atlantico.
 *
 * Le rotte sono spezzate in punti di passaggio che tengono il largo dalle
 * coste e ricalcano gli itinerari reali: i convogli da Halifax e da Sydney
 * salivano verso nord per accorciare la traversata, passavano il punto di
 * scambio della scorta a mezzo oceano e scendevano sulle Approaches
 * occidentali. Le serie, le velocita' e la consistenza sono quelle storiche.
 *
 * fonte: organizzazione dei convogli atlantici 1941-43 (serie HX, SC, ON, ONS,
 * OG, HG, SL, TM), velocita' di convoglio "veloce" 9-10 nodi e "lento" 7 nodi.
 */

return [
    'rotte' => [
        'hx' => ['nome' => 'Halifax → Liverpool', 'punti' => [
            [44.65, -63.57], [46.0, -52.0], [49.0, -42.0], [52.0, -32.0], [54.5, -22.0], [55.5, -12.0], [54.0, -6.0], [53.41, -3.00],
        ]],
        'sc' => ['nome' => 'Sydney (Capo Bretone) → Liverpool', 'punti' => [
            [46.13, -60.19], [47.5, -50.0], [50.0, -40.0], [53.0, -30.0], [55.0, -20.0], [55.5, -11.0], [54.0, -6.0], [53.41, -3.00],
        ]],
        'on' => ['nome' => 'Liverpool → Halifax', 'punti' => [
            [53.41, -3.00], [54.5, -8.0], [55.5, -15.0], [54.0, -26.0], [51.0, -36.0], [47.5, -48.0], [44.65, -63.57],
        ]],
        'ons' => ['nome' => 'Liverpool → Halifax (lento)', 'punti' => [
            [53.41, -3.00], [55.0, -9.0], [56.0, -18.0], [54.5, -28.0], [51.5, -38.0], [48.0, -50.0], [44.65, -63.57],
        ]],
        'og' => ['nome' => 'Liverpool → Gibilterra', 'punti' => [
            [53.41, -3.00], [51.0, -9.0], [47.0, -13.0], [42.0, -15.0], [38.0, -13.0], [36.14, -5.35],
        ]],
        'hg' => ['nome' => 'Gibilterra → Liverpool', 'punti' => [
            [36.14, -5.35], [38.0, -12.0], [42.0, -16.0], [47.0, -14.0], [51.0, -10.0], [53.41, -3.00],
        ]],
        'sl' => ['nome' => 'Freetown → Liverpool', 'punti' => [
            [8.48, -13.23], [14.0, -19.0], [22.0, -22.0], [30.0, -21.0], [37.0, -18.0], [44.0, -15.0], [50.0, -12.0], [53.41, -3.00],
        ]],
        'tm' => ['nome' => 'Trinidad → Gibilterra (petroliere)', 'punti' => [
            [10.65, -61.51], [18.0, -55.0], [26.0, -45.0], [32.0, -32.0], [35.0, -20.0], [36.14, -5.35],
        ]],
        'caraibi_usa' => ['nome' => 'Aruba → New York (isolate)', 'punti' => [
            [12.43, -69.90], [18.0, -70.0], [25.0, -76.0], [31.0, -79.0], [36.0, -75.0], [40.70, -74.01],
        ]],
        'capo' => ['nome' => 'Freetown → Terranova (isolate)', 'punti' => [
            [8.48, -13.23], [18.0, -25.0], [28.0, -35.0], [38.0, -45.0], [45.0, -52.0], [47.56, -52.71],
        ]],
        'islanda' => ['nome' => 'Reykjavik → Clyde', 'punti' => [
            [64.15, -21.94], [62.0, -16.0], [59.0, -10.0], [56.0, -7.0], [55.86, -4.25],
        ]],
    ],

    // Serie di convogli: ogni quanto partono, quanto sono grandi, come sono scortati.
    'serie' => [
        ['serie' => 'HX',  'rotta' => 'hx',  'speed_kn' => 9.5, 'ogni_giorni' => 6, 'navi' => [35, 60], 'scorte' => [5, 8],  'numero_da' => 170, 'colonne' => 9,
         'nota' => 'Convogli veloci da Halifax: il flusso principale verso la Gran Bretagna.'],
        ['serie' => 'SC',  'rotta' => 'sc',  'speed_kn' => 7.0, 'ogni_giorni' => 8, 'navi' => [30, 55], 'scorte' => [4, 7],  'numero_da' => 95,  'colonne' => 9,
         'nota' => 'Convogli lenti: piu' . "'" . ' vecchi, piu' . "'" . ' lenti, piu' . "'" . ' facili da raggiungere.'],
        ['serie' => 'ON',  'rotta' => 'on',  'speed_kn' => 9.0, 'ogni_giorni' => 6, 'navi' => [30, 50], 'scorte' => [4, 7],  'numero_da' => 60,  'colonne' => 9,
         'nota' => 'In uscita dalla Gran Bretagna, spesso in zavorra.'],
        ['serie' => 'ONS', 'rotta' => 'ons', 'speed_kn' => 7.0, 'ogni_giorni' => 9, 'navi' => [25, 45], 'scorte' => [4, 6],  'numero_da' => 4,   'colonne' => 8,
         'nota' => null],
        ['serie' => 'OG',  'rotta' => 'og',  'speed_kn' => 8.0, 'ogni_giorni' => 11,'navi' => [15, 30], 'scorte' => [3, 6],  'numero_da' => 70,  'colonne' => 6,
         'nota' => 'Rotta di Gibilterra: piu' . "'" . ' vicina alle basi aeree nemiche.'],
        ['serie' => 'HG',  'rotta' => 'hg',  'speed_kn' => 9.0, 'ogni_giorni' => 12,'navi' => [15, 30], 'scorte' => [3, 6],  'numero_da' => 70,  'colonne' => 6,
         'nota' => null],
        ['serie' => 'SL',  'rotta' => 'sl',  'speed_kn' => 8.0, 'ogni_giorni' => 14,'navi' => [20, 40], 'scorte' => [3, 6],  'numero_da' => 110, 'colonne' => 7,
         'nota' => 'Dall\'Africa occidentale: minerali, oli vegetali, uomini.'],
        ['serie' => 'TM',  'rotta' => 'tm',  'speed_kn' => 9.0, 'ogni_giorni' => 16,'navi' => [9, 15],  'scorte' => [3, 5],  'numero_da' => 1,   'colonne' => 4,
         'nota' => 'Solo petroliere: il carico piu' . "'" . ' prezioso e il piu' . "'" . ' infiammabile.'],
    ],

    // Traffico isolato: rotte battute da navi che viaggiano sole.
    'isolate' => [
        ['rotta' => 'caraibi_usa', 'peso' => 22, 'classi' => ['petroliera_media', 'petroliera_t2', 'cargo_medio']],
        ['rotta' => 'capo',        'peso' => 16, 'classi' => ['cargo_medio', 'cargo_grande', 'frigorifera']],
        ['rotta' => 'sl',          'peso' => 12, 'classi' => ['cargo_medio', 'tramp_piccolo', 'frigorifera']],
        ['rotta' => 'og',          'peso' => 10, 'classi' => ['cargo_medio', 'tramp_piccolo']],
        ['rotta' => 'hx',          'peso' => 14, 'classi' => ['cargo_grande', 'cargo_grande', 'trasporto_truppe', 'transatlantico']],
        ['rotta' => 'on',          'peso' => 12, 'classi' => ['cargo_medio', 'tramp_piccolo', 'tramp_piccolo']],
        ['rotta' => 'islanda',     'peso' => 8,  'classi' => ['cargo_medio', 'tramp_piccolo', 'peschereccio']],
        ['rotta' => 'tm',          'peso' => 6,  'classi' => ['petroliera_media', 'petroliera_t2']],
    ],

    // Copertura aerea per zona: probabilita' oraria di base di un avvistamento
    // aereo a battello emerso. Il "buco" dell'Atlantico centrale e' il vuoto
    // fra le basi di Terranova, Islanda e Irlanda del Nord.
    'aria' => [
        ['nome' => 'Golfo di Biscaglia',      'lat' => 45.5, 'lon' => -7.0,  'raggio_nm' => 420, 'intensita' => 0.085, 'classi' => ['sunderland', 'wellington_leigh', 'hudson']],
        ['nome' => 'Approaches occidentali',  'lat' => 54.0, 'lon' => -12.0, 'raggio_nm' => 480, 'intensita' => 0.075, 'classi' => ['sunderland', 'hudson', 'liberator']],
        ['nome' => 'Terranova',               'lat' => 47.0, 'lon' => -52.0, 'raggio_nm' => 520, 'intensita' => 0.055, 'classi' => ['hudson', 'liberator']],
        ['nome' => 'Islanda',                 'lat' => 63.0, 'lon' => -20.0, 'raggio_nm' => 500, 'intensita' => 0.050, 'classi' => ['hudson', 'liberator']],
        ['nome' => 'Gibilterra',              'lat' => 36.5, 'lon' => -8.0,  'raggio_nm' => 380, 'intensita' => 0.045, 'classi' => ['hudson', 'sunderland']],
        ['nome' => 'Freetown',                'lat' => 9.5,  'lon' => -15.0, 'raggio_nm' => 360, 'intensita' => 0.030, 'classi' => ['hudson', 'sunderland']],
        ['nome' => 'Costa orientale USA',     'lat' => 36.0, 'lon' => -74.0, 'raggio_nm' => 420, 'intensita' => 0.040, 'classi' => ['hudson', 'liberator']],
    ],
];
