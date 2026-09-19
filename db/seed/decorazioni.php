<?php

declare(strict_types=1);

/**
 * Decorazioni della Kriegsmarine per i comandanti di sommergibile.
 *
 * Le soglie di tonnellaggio sono INDICATIVE: la Ritterkreuz non si otteneva
 * per tabella ma per proposta del BdU, e i criteri cambiarono nel corso della
 * guerra (nel 1940 bastavano circa centomila tonnellate, piu' tardi ne
 * servivano molte di piu' o un'azione eccezionale). Qui si usa la soglia dei
 * primi anni, dichiarata come tale.
 *
 * fonte: sistema di decorazioni tedesco 1939-45 e prassi di conferimento ai
 * comandanti di U-Boot.
 */

return [
    [
        'akey' => 'ek2', 'nome' => 'Eisernes Kreuz II. Klasse', 'nome_it' => 'Croce di Ferro di seconda classe',
        'ordine' => 1, 'richiede' => null, 'min_patrols' => 1, 'min_grt' => 0, 'min_navi' => 0,
        'fonte' => 'Conferita di norma dopo la prima missione di guerra conclusa.',
        'confidence' => 'media', 'note' => 'La prima decorazione: si portava il nastro all\'occhiello della giubba.',
    ],
    [
        'akey' => 'ubootabzeichen', 'nome' => 'U-Boots-Kriegsabzeichen', 'nome_it' => 'Distintivo di guerra dei sommergibili',
        'ordine' => 2, 'richiede' => null, 'min_patrols' => 2, 'min_grt' => 0, 'min_navi' => 0,
        'fonte' => 'Istituito nel 1939: due missioni di guerra, oppure una sola se coronata da successo o conclusa con ferite.',
        'confidence' => 'alta', 'note' => 'L\'aquila sopra il sommergibile: il segno che si e\' andati in mare davvero.',
    ],
    [
        'akey' => 'ek1', 'nome' => 'Eisernes Kreuz I. Klasse', 'nome_it' => 'Croce di Ferro di prima classe',
        'ordine' => 3, 'richiede' => 'ek2', 'min_patrols' => 3, 'min_grt' => 20000, 'min_navi' => 3,
        'fonte' => 'Conferita per meriti ripetuti dopo la Croce di seconda classe.',
        'confidence' => 'media', 'note' => null,
    ],
    [
        'akey' => 'frontspange', 'nome' => 'U-Boot-Frontspange', 'nome_it' => 'Fregio di fronte dei sommergibili',
        'ordine' => 4, 'richiede' => 'ubootabzeichen', 'min_patrols' => 8, 'min_grt' => 0, 'min_navi' => 0,
        'fonte' => 'Istituito nel 1944 per chi aveva continuato a uscire in mare quando le probabilita\' di tornare erano ormai minime.',
        'confidence' => 'media', 'note' => 'Molte missioni. Nella realta\' arrivo\' tardi, quando la maggior parte degli equipaggi era gia\' morta.',
    ],
    [
        'akey' => 'dkig', 'nome' => 'Deutsches Kreuz in Gold', 'nome_it' => 'Croce Tedesca in oro',
        'ordine' => 5, 'richiede' => 'ek1', 'min_patrols' => 5, 'min_grt' => 60000, 'min_navi' => 8,
        'fonte' => 'Istituita nel 1941 per colmare il vuoto fra la Croce di Ferro di prima classe e la Croce di Cavaliere.',
        'confidence' => 'media', 'note' => null,
    ],
    [
        'akey' => 'ritterkreuz', 'nome' => 'Ritterkreuz des Eisernen Kreuzes', 'nome_it' => 'Croce di Cavaliere',
        'ordine' => 6, 'richiede' => 'ek1', 'min_patrols' => 4, 'min_grt' => 100000, 'min_navi' => 12,
        'fonte' => 'Soglia indicativa dei primi anni di guerra: circa centomila tonnellate di stazza affondate, o un\'azione eccezionale.',
        'confidence' => 'bassa', 'note' => 'La decorazione che tutti volevano. Si portava al collo, e si vedeva nelle fotografie.',
    ],
    [
        'akey' => 'eichenlaub', 'nome' => 'Ritterkreuz mit Eichenlaub', 'nome_it' => 'Croce di Cavaliere con fronde di quercia',
        'ordine' => 7, 'richiede' => 'ritterkreuz', 'min_patrols' => 8, 'min_grt' => 200000, 'min_navi' => 25,
        'fonte' => 'Secondo grado della Croce di Cavaliere.',
        'confidence' => 'bassa', 'note' => null,
    ],
    [
        'akey' => 'schwerter', 'nome' => 'Ritterkreuz mit Eichenlaub und Schwertern', 'nome_it' => 'Croce di Cavaliere con fronde di quercia e spade',
        'ordine' => 8, 'richiede' => 'eichenlaub', 'min_patrols' => 12, 'min_grt' => 280000, 'min_navi' => 35,
        'fonte' => 'Terzo grado. Fra i comandanti di U-Boot la ottennero in pochissimi.',
        'confidence' => 'bassa', 'note' => null,
    ],
    [
        'akey' => 'brillanten', 'nome' => 'Ritterkreuz mit Eichenlaub, Schwertern und Brillanten', 'nome_it' => 'Croce di Cavaliere con fronde di quercia, spade e brillanti',
        'ordine' => 9, 'richiede' => 'schwerter', 'min_patrols' => 15, 'min_grt' => 350000, 'min_navi' => 45,
        'fonte' => 'Grado massimo: fra i sommergibilisti lo ricevettero due soli comandanti in tutta la guerra.',
        'confidence' => 'alta', 'note' => 'Praticamente irraggiungibile, ed e\' giusto che lo sia.',
    ],
];
