<?php

declare(strict_types=1);

/**
 * Classi di naviglio alleato e neutrale, e velivoli.
 *
 * Il tonnellaggio e' quello di stazza lorda (GRT), la misura con cui si teneva
 * il conto della guerra al traffico. `rumore_db` e' il livello di sorgente
 * acustica a velocita' di crociera, in decibel convenzionali: e' il numero che
 * entra nel modello dell'idrofono (vedi Sim\Acoustics) e non ha pretese di
 * misura reale — e' tarato perche' un convoglio si senta a trenta-cinquanta
 * miglia e un peschereccio a cinque, come riportano i giornali di guerra.
 */

return [
    // --- Mercantili ----------------------------------------------------------
    ['class_key' => 'tramp_piccolo', 'name' => 'Piroscafo da carico piccolo', 'kind' => 'mercantile',
     'grt' => 2400, 'speed_kn' => 8.0, 'length_m' => 95, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 132,
     'fonte' => 'Tramp britannici e greci di piccolo cabotaggio oceanico.', 'confidence' => 'media',
     'note' => 'Vecchi, lenti, a carbone: il fumo si vede prima dello scafo. Armato: dal 1942 '
             . 'il programma DEMS aveva messo un pezzo a poppa anche ai piroscafi piccoli, ed e\' '
             . 'il motivo per cui l\'attacco col cannone in superficie smise di essere un affare.'],

    ['class_key' => 'cargo_medio', 'name' => 'Piroscafo da carico medio', 'kind' => 'mercantile',
     'grt' => 5100, 'speed_kn' => 9.5, 'length_m' => 125, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 138,
     'fonte' => 'Classe piu' . "'" . ' numerosa dei convogli atlantici.', 'confidence' => 'media', 'note' => null],

    ['class_key' => 'cargo_grande', 'name' => 'Piroscafo da carico grande', 'kind' => 'mercantile',
     'grt' => 8200, 'speed_kn' => 11.0, 'length_m' => 150, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 142,
     'fonte' => 'Carichi generali e materiale bellico.', 'confidence' => 'media', 'note' => null],

    ['class_key' => 'liberty', 'name' => 'Liberty ship', 'kind' => 'mercantile',
     'grt' => 7176, 'speed_kn' => 11.0, 'length_m' => 135, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 141,
     'fonte' => 'Liberty ship statunitense: 7.176 GRT di stazza, costruita in serie dal 1941.',
     'confidence' => 'alta', 'note' => 'Se ne varavano piu' . "'" . ' di quante se ne potessero affondare: e\' questo che perse la battaglia.'],

    ['class_key' => 'frigorifera', 'name' => 'Nave frigorifera', 'kind' => 'mercantile',
     'grt' => 6100, 'speed_kn' => 13.0, 'length_m' => 140, 'eliche' => 2, 'armata' => 1, 'rumore_db' => 144,
     'fonte' => 'Carne e derrate dall\'Argentina e dall\'Australia.', 'confidence' => 'media', 'note' => null],

    ['class_key' => 'petroliera_media', 'name' => 'Petroliera media', 'kind' => 'petroliera',
     'grt' => 8300, 'speed_kn' => 11.0, 'length_m' => 145, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 140,
     'fonte' => 'Petroliere della rotta caraibica e del Golfo.', 'confidence' => 'media',
     'note' => 'Carica brucia per ore e si vede a venti miglia; in zavorra affonda a fatica.'],

    ['class_key' => 'petroliera_t2', 'name' => 'Petroliera tipo T2', 'kind' => 'petroliera',
     'grt' => 16600, 'speed_kn' => 14.5, 'length_m' => 159, 'eliche' => 1, 'armata' => 1, 'rumore_db' => 147,
     'fonte' => 'Petroliera T2 statunitense: circa 16.600 GRT, 14,5 nodi.', 'confidence' => 'alta',
     'note' => 'Il bersaglio piu' . "'" . ' prezioso dell\'Atlantico dopo i trasporti truppe.'],

    ['class_key' => 'trasporto_truppe', 'name' => 'Trasporto truppe', 'kind' => 'trasporto',
     'grt' => 20000, 'speed_kn' => 17.0, 'length_m' => 200, 'eliche' => 2, 'armata' => 1, 'rumore_db' => 152,
     'fonte' => 'Transatlantici requisiti per il trasporto di truppe.', 'confidence' => 'media',
     'note' => 'Viaggiano veloci e spesso soli: troppo rapidi per i convogli e per la maggior parte degli U-Boot.'],

    ['class_key' => 'transatlantico', 'name' => 'Transatlantico veloce', 'kind' => 'trasporto',
     'grt' => 26000, 'speed_kn' => 20.0, 'length_m' => 230, 'eliche' => 4, 'armata' => 1, 'rumore_db' => 156,
     'fonte' => 'Liner veloci in servizio truppe, non inquadrati in convoglio.', 'confidence' => 'media',
     'note' => 'Praticamente inattaccabile se non per caso: si passa davanti e basta.'],

    ['class_key' => 'peschereccio', 'name' => 'Peschereccio d\'altura', 'kind' => 'ausiliaria',
     'grt' => 450, 'speed_kn' => 9.0, 'length_m' => 50, 'eliche' => 1, 'armata' => 0, 'rumore_db' => 124,
     'fonte' => 'Pescherecci dei Banchi di Terranova e delle isole britanniche.', 'confidence' => 'bassa',
     'note' => 'Tonnellaggio trascurabile: affondarlo costa un siluro e non paga.'],

    ['class_key' => 'qship', 'name' => 'Nave civetta (Q-ship)', 'kind' => 'ausiliaria',
     'grt' => 4200, 'speed_kn' => 10.0, 'length_m' => 120, 'eliche' => 1, 'armata' => 1, 'asdic' => 1, 'dc_carica' => 40, 'rumore_db' => 137,
     'fonte' => 'Navi trappola britanniche: mercantile all\'apparenza, artiglieria mascherata.',
     'confidence' => 'media', 'note' => 'Sembra una preda facile per il cannone. Non lo e\'.'],

    // --- Scorte ---------------------------------------------------------------
    ['class_key' => 'corvetta_flower', 'name' => 'Corvetta classe Flower', 'kind' => 'scorta',
     'grt' => 925, 'speed_kn' => 16.0, 'length_m' => 62, 'eliche' => 1, 'armata' => 1, 'asdic' => 1, 'radar' => 1, 'dc_carica' => 40, 'rumore_db' => 143,
     'fonte' => 'Corvetta Flower: 925 t, 16 nodi, ASDIC e lanciabombe. La spina dorsale delle scorte oceaniche.',
     'confidence' => 'alta', 'note' => 'Lenta per inseguire un U-Boot in superficie, ma non molla mai.'],

    ['class_key' => 'ct_vw', 'name' => 'Cacciatorpediniere classe V&W', 'kind' => 'scorta',
     'grt' => 1100, 'speed_kn' => 24.0, 'length_m' => 95, 'eliche' => 2, 'armata' => 1, 'asdic' => 1, 'radar' => 1, 'hfdf' => 1, 'dc_carica' => 70, 'rumore_db' => 150,
     'fonte' => 'Cacciatorpediniere britannici della Grande Guerra convertiti a scorta oceanica.',
     'confidence' => 'media', 'note' => 'Veloce: in superficie vi raggiunge.'],

    ['class_key' => 'ct_town', 'name' => 'Cacciatorpediniere classe Town', 'kind' => 'scorta',
     'grt' => 1190, 'speed_kn' => 20.0, 'length_m' => 96, 'eliche' => 2, 'armata' => 1, 'asdic' => 1, 'radar' => 1, 'dc_carica' => 60, 'rumore_db' => 149,
     'fonte' => 'Ex cacciatorpediniere statunitensi ceduti col patto basi-cacciatorpediniere (1940).',
     'confidence' => 'media', 'note' => null],

    ['class_key' => 'fregata_river', 'name' => 'Fregata classe River', 'kind' => 'scorta',
     'grt' => 1370, 'speed_kn' => 20.0, 'length_m' => 92, 'eliche' => 2, 'armata' => 1, 'asdic' => 1, 'radar' => 1, 'hfdf' => 1, 'dc_carica' => 126, 'rumore_db' => 148,
     'fonte' => 'Fregate River: scorta oceanica con armi a lancio in avanti.', 'confidence' => 'media',
     'note' => 'Hedgehog: colpisce senza perdere il contatto ASDIC. La peggiore nemica.'],

    ['class_key' => 'sloop_black_swan', 'name' => 'Sloop classe Black Swan', 'kind' => 'scorta',
     'grt' => 1300, 'speed_kn' => 19.0, 'length_m' => 91, 'eliche' => 2, 'armata' => 1, 'asdic' => 1, 'radar' => 1, 'hfdf' => 1, 'dc_carica' => 110, 'rumore_db' => 147,
     'fonte' => 'Sloop dei gruppi di supporto: equipaggi d\'elite, tempi di caccia lunghissimi.',
     'confidence' => 'media', 'note' => 'Non e\' legata al convoglio: puo\' restare sopra di voi per ore.'],

    ['class_key' => 'trawler_armato', 'name' => 'Peschereccio armato', 'kind' => 'scorta',
     'grt' => 530, 'speed_kn' => 12.0, 'length_m' => 50, 'eliche' => 1, 'armata' => 1, 'asdic' => 1, 'dc_carica' => 30, 'rumore_db' => 138,
     'fonte' => 'Pescherecci requisiti per scorta costiera e pattugliamento.', 'confidence' => 'media', 'note' => null],

    // --- Velivoli --------------------------------------------------------------
    ['class_key' => 'sunderland', 'name' => 'Short Sunderland', 'kind' => 'aereo',
     'grt' => 0, 'speed_kn' => 110.0, 'length_m' => 26, 'armata' => 1, 'radar' => 1, 'rumore_db' => 0,
     'fonte' => 'Idrovolante da pattugliamento del Coastal Command, raggio medio.', 'confidence' => 'media',
     'note' => 'Il padrone del Golfo di Biscaglia.'],

    ['class_key' => 'wellington_leigh', 'name' => 'Wellington con faro Leigh', 'kind' => 'aereo',
     'grt' => 0, 'speed_kn' => 140.0, 'length_m' => 19, 'armata' => 1, 'radar' => 1, 'rumore_db' => 0,
     'fonte' => 'Wellington con radar ASV e faro Leigh: agguato notturno.', 'confidence' => 'media',
     'note' => 'Arriva al buio, accende il faro a un miglio e siete illuminati come a mezzogiorno.'],

    ['class_key' => 'liberator', 'name' => 'B-24 Liberator a lungo raggio', 'kind' => 'aereo',
     'grt' => 0, 'speed_kn' => 150.0, 'length_m' => 20, 'armata' => 1, 'radar' => 1, 'rumore_db' => 0,
     'fonte' => 'Liberator VLR: e\' il velivolo che chiuse il buco aereo dell\'Atlantico centrale.',
     'confidence' => 'media', 'note' => 'Dove arriva lui, il branco non si raduna piu\'.'],

    ['class_key' => 'hudson', 'name' => 'Lockheed Hudson', 'kind' => 'aereo',
     'grt' => 0, 'speed_kn' => 130.0, 'length_m' => 14, 'armata' => 1, 'radar' => 1, 'rumore_db' => 0,
     'fonte' => 'Bimotore da pattugliamento costiero.', 'confidence' => 'media', 'note' => null],

    ['class_key' => 'swordfish', 'name' => 'Fairey Swordfish (da portaerei di scorta)', 'kind' => 'aereo',
     'grt' => 0, 'speed_kn' => 90.0, 'length_m' => 11, 'armata' => 1, 'radar' => 0, 'rumore_db' => 0,
     'fonte' => 'Biplano imbarcato sulle portaerei di scorta a partire dal 1941-42.',
     'confidence' => 'media', 'note' => 'Lento e antiquato, ma vi trova mentre caricate le batterie.'],
];
