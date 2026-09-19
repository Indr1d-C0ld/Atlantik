<?php

declare(strict_types=1);

/**
 * Siluri tedeschi: prestazioni, difetti e impiego.
 *
 * Le probabilita' di difetto (`p_cilecca`, `p_prematura`, `p_quota_errata`)
 * sono la traduzione in numeri della **crisi dei siluri**: nel 1939-40 i
 * battelli tornavano con rapporti di lanci perfetti finiti in nulla —
 * spolette magnetiche che scoppiavano in anticipo o non scoppiavano affatto, e
 * armi che correvano due o tre metri piu' profonde del regolato, passando
 * sotto la chiglia. Qui i valori sono quelli dei lotti "collaudati": i lotti
 * di vecchio magazzino sono piu' economici e molto piu' infidi (vedi la chiave
 * di configurazione `combat.qualita_siluri`).
 *
 * fonte: caratteristiche dei siluri G7a (T I), G7e (T II e T III), FAT, LUT e
 * G7es T5 "Zaunkoenig"; letteratura sulla crisi dei siluri del 1940.
 */

return [
    [
        'tkey' => 'G7a', 'name' => 'G7a — siluro a vapore', 'sigla' => 'T I',
        'propulsione' => 'vapore', 'scia' => 1, 'warhead_kg' => 280,
        'v1_kn' => 30.0, 'r1_m' => 12500,
        'v2_kn' => 40.0, 'r2_m' => 7500,
        'v3_kn' => 44.0, 'r3_m' => 5500,
        'guida' => 'dritto',
        'p_cilecca' => 0.075, 'p_prematura' => 0.030, 'p_quota_errata' => 0.055,
        'unlock_rank' => 0,
        'fonte' => 'G7a (T I): tre regolazioni 30/40/44 nodi, testata da 280 kg.',
        'confidence' => 'alta',
        'note' => 'Veloce e a lunga gittata, ma lascia una scia di bolle che di giorno si vede benissimo: si usa di notte, o non si usa.',
    ],
    [
        'tkey' => 'G7e_T2', 'name' => 'G7e — siluro elettrico', 'sigla' => 'T II',
        'propulsione' => 'elettrico', 'scia' => 0, 'warhead_kg' => 280,
        'v1_kn' => 30.0, 'r1_m' => 5000,
        'v2_kn' => null, 'r2_m' => null, 'v3_kn' => null, 'r3_m' => null,
        'guida' => 'dritto',
        'p_cilecca' => 0.080, 'p_prematura' => 0.020, 'p_quota_errata' => 0.060,
        'unlock_rank' => 0,
        'fonte' => 'G7e (T II): elettrico, senza scia, 30 nodi per 5.000 metri.',
        'confidence' => 'alta',
        'note' => 'Nessuna scia: e\' l\'arma dell\'attacco diurno in immersione. La batteria va preriscaldata, altrimenti perde velocita\' e gittata.',
    ],
    [
        'tkey' => 'G7e_T3', 'name' => 'G7e migliorato', 'sigla' => 'T III',
        'propulsione' => 'elettrico', 'scia' => 0, 'warhead_kg' => 280,
        'v1_kn' => 30.0, 'r1_m' => 7500,
        'v2_kn' => null, 'r2_m' => null, 'v3_kn' => null, 'r3_m' => null,
        'guida' => 'dritto',
        'p_cilecca' => 0.055, 'p_prematura' => 0.015, 'p_quota_errata' => 0.035,
        'unlock_rank' => 2,
        'fonte' => 'G7e (T III): gittata portata a 7.500 metri, spolette piu' . "'" . ' affidabili.',
        'confidence' => 'media',
        'note' => 'Il siluro standard della battaglia: silenzioso, affidabile quanto bastava.',
    ],
    [
        'tkey' => 'FAT', 'name' => 'FAT — siluro a corsa programmata', 'sigla' => 'FAT I',
        'propulsione' => 'elettrico', 'scia' => 0, 'warhead_kg' => 280,
        'v1_kn' => 30.0, 'r1_m' => 7500,
        'v2_kn' => null, 'r2_m' => null, 'v3_kn' => null, 'r3_m' => null,
        'guida' => 'fat',
        'p_cilecca' => 0.060, 'p_prematura' => 0.020, 'p_quota_errata' => 0.040,
        'unlock_rank' => 4,
        'fonte' => 'FAT: dopo una corsa iniziale rettilinea il siluro percorre un tracciato a zigzag dentro la formazione.',
        'confidence' => 'media',
        'note' => 'Si spara nel mucchio e si aspetta: prima o poi incontra una chiglia. Vietato usarlo quando ci sono altri U-Boot vicini, per ovvie ragioni.',
    ],
    [
        'tkey' => 'LUT', 'name' => 'LUT — corsa programmata migliorata', 'sigla' => 'LUT',
        'propulsione' => 'elettrico', 'scia' => 0, 'warhead_kg' => 280,
        'v1_kn' => 30.0, 'r1_m' => 7500,
        'v2_kn' => null, 'r2_m' => null, 'v3_kn' => null, 'r3_m' => null,
        'guida' => 'lut',
        'p_cilecca' => 0.050, 'p_prematura' => 0.015, 'p_quota_errata' => 0.035,
        'unlock_rank' => 7,
        'fonte' => 'LUT: evoluzione del FAT, tracciato regolabile e lancio da qualunque angolo.',
        'confidence' => 'media',
        'note' => 'Permette di attaccare il convoglio senza doversi mettere in posizione perfetta.',
    ],
    [
        'tkey' => 'T5', 'name' => 'G7es T5 "Zaunkoenig"', 'sigla' => 'T V',
        'propulsione' => 'acustico', 'scia' => 0, 'warhead_kg' => 274,
        'v1_kn' => 24.5, 'r1_m' => 5700,
        'v2_kn' => null, 'r2_m' => null, 'v3_kn' => null, 'r3_m' => null,
        'guida' => 'acustico',
        'p_cilecca' => 0.070, 'p_prematura' => 0.045, 'p_quota_errata' => 0.030,
        'unlock_rank' => 9,
        'fonte' => 'G7es T5 Zaunkoenig (in servizio dal settembre 1943): testa acustica che insegue il rumore d\'elica, efficace fra 10 e 18 nodi.',
        'confidence' => 'media',
        'note' => 'L\'arma contro le scorte. Dopo il lancio bisogna scendere sotto i sessanta metri e allontanarsi: se perde il bersaglio puo\' tornare sul lanciatore. Gli alleati risposero col Foxer, un generatore di rumore rimorchiato.',
    ],
];
