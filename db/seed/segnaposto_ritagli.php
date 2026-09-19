<?php

declare(strict_types=1);

/**
 * Piano dei ritagli delle figure di segnaposto. SCRITTO A MANO.
 *
 * Dice, per ogni figura: da che tavola viene, dove sta dentro la tavola, che
 * cosa rappresenta e a che entita' di gioco si attacca. Da qui
 * bin/taglia_segnaposto.php ricava i file e il registro.
 *
 * Il riquadro e' in FRAZIONI della tavola [x, y, larghezza, altezza], non in
 * pixel: cosi' il piano resta valido anche se la tavola arriva a una
 * risoluzione diversa. I riquadri sono stati MISURATI sulle tavole (profilo
 * dell'inchiostro riga per riga), non stimati a occhio; possono essere larghi,
 * tanto la pergamena attorno viene tolta in automatico. Devono pero' escludere
 * le didascalie, che sono inchiostro anche loro.
 *
 * DOVE SI USANO — la regola:
 *   le sagome vanno nel RESOCONTO (cosa hai affondato, cosa hai incontrato: il
 *   giornale di guerra, l'albo dei bersagli), MAI nell'IDENTIFICAZIONE (la
 *   pagina dei contatti, la stazione d'attacco). Una figura inventata accanto a
 *   un contatto da classificare insegnerebbe a riconoscere navi che non
 *   esistono; la stessa figura accanto a un affondamento gia' avvenuto e'
 *   soltanto un ricordo illustrato.
 *
 * COSA NON E' STATO RITAGLIATO, e perche':
 *   - Cacciatorpediniere "Tribal", Fregata "Captain", Incrociatore
 *     "Southampton", Portaerei di scorta "Bogue": classi che nel gioco non
 *     esistono. Attaccarle a una classe diversa (una Captain per una River)
 *     sarebbe insegnare una cosa falsa;
 *   - Hawker Hurricane, Vought F4U Corsair, TBF Avenger: aerei che non
 *     compaiono nel teatro simulato;
 *   - Petroliera "Empire": il gioco ha una petroliera media sola, e la prende
 *     la "Shell", che e' quella dichiarata come media sulla tavola;
 *   - Rimorchiatore oceanico: nessuna classe corrispondente;
 *   - medaglie e gradi della prima tavola oltre a quelli qui sotto: stanno
 *     troppo vicini per ritagliarli senza portarsi dietro il vicino;
 *   - Emblema di torretta "Sawfish" di U-96: bello e storico, ma nel gioco
 *     non c'e' ancora un modo di scegliere l'emblema del proprio battello, e
 *     attaccare quello di U-96 al battello di chiunque sarebbe una bugia
 *     gratuita. Il ritaglio si riaccende il giorno che ci sara' la scelta;
 *   - Corvetta Flower dalla tavola generata: sostituita da una sagoma costruita
 *     sulle misure documentate (bin/disegna_scorte.php). Una classe, una figura;
 *   - Insegna da Oberstabsgefreiter: e' un grado che nell'equipaggio del
 *     gioco non esiste (i comuni sono Matrosengefreiter e
 *     Matrosenobergefreiter), e il disegno non e' nemmeno di foggia navale.
 *     Meglio nessuna insegna che l'insegna di un altro.
 *
 * REGOLA CHE NE DISCENDE: una figura entra solo se ha un posto onesto dove
 * stare. Ritagli senza destinazione fanno peso e basta, e col tempo
 * qualcuno li usa dove capita.
 */

$tavolaCatalogo = getenv('ONI_TAVOLA_CATALOGO') ?: __DIR__ . '/oni/tavola-catalogo.jpg';
$tavolaSingole  = getenv('ONI_TAVOLA_SINGOLE') ?: __DIR__ . '/oni/tavola-singole.jpg';

return [

    // --- Sommergibili -------------------------------------------------------
    'uboot_viic' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.2250, 0.0788, 0.2758, 0.1089],
        'altezza'  => 88,
        'interni'  => true,    // fra ponte e murata il disegno lascia vedere il foglio
        'soggetto' => 'Sagoma di U-Boot Tipo VII C in superficie',
        'lega'     => 'VIIC',
        'nota'     => 'Il battello del gioco. Proporzioni e dettagli non verificati sui piani '
                    . 'costruttivi: torretta, cannone e ponte sono resi in modo plausibile, non esatto.',
    ],

    // --- Naviglio alleato ---------------------------------------------------
    'liberty' => [
        'sorgente' => $tavolaCatalogo,
        'box'      => [0.5250, 0.5774, 0.1250, 0.0645],
        'altezza'  => 64,
        'soggetto' => 'Sagoma di Liberty ship',
        'lega'     => 'liberty',
        'nota'     => 'La nave da carico di serie della guerra. La sagoma vera e' . "'" . ' molto '
                    . 'documentata: questa le somiglia, non e' . "'" . ' presa da un piano.',
    ],


    // --- Profili documentali: ONI 208, Merchant Ship Recognition Manual -----
    //
    // Division of Naval Intelligence, US Navy. Opera del governo degli Stati
    // Uniti: pubblico dominio (l'esemplare digitalizzato porta il Public Domain
    // Mark 1.0). Sono disegni di profilo fatti per essere confrontati con una
    // nave vera a distanza: la ragione per cui esistono e' esattamente
    // l'accuratezza.
    //
    // Le pagine lavorate stanno in db/seed/oni/, rese a 150 punti per pollice,
    // cosi' il ritaglio si rifa' senza riscaricare 52 MB.
    //
    // Ogni profilo e' di una NAVE PRECISA, col suo nome: si usa come
    // rappresentante della classe del gioco, e la nota dice sempre quale nave
    // e' e quanto e' grossa davvero.

    'cargo_medio' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p60.png',
        'box'       => [0.1650, 0.1380, 0.2900, 0.0640],
        'altezza'   => 62,
        'soggetto'  => 'Profilo di piroscafo da carico a tre isole (Clan Macdougall)',
        'lega'      => 'cargo_medio',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
        'nota'      => 'Il piroscafo da carico britannico di configurazione classica: castello, '
                     . 'tuga centrale, cassero. Rappresenta la categoria, non una stazza precisa.',
    ],
    'petroliera_media' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p100.png',
        'box'       => [0.1480, 0.2370, 0.2650, 0.0480],
        'altezza'   => 58,
        'soggetto'  => 'Profilo di petroliera a motrice poppiera (Trontolite)',
        'lega'      => 'petroliera_media',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 47 (ENGINES AFT, TYPE T)',
        'nota'      => 'La passerella che corre da prua a poppa sopra il ponte e\' il segno che '
                     . 'distingue una petroliera da un carico: e\' scritto nel manuale stesso.',
    ],
    'petroliera_t2' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p100.png',
        'box'       => [0.1430, 0.8400, 0.2800, 0.0570],
        'altezza'   => 58,
        'soggetto'  => 'Profilo di petroliera di squadra (Cadillac / Saranac)',
        'lega'      => 'petroliera_t2',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 47',
        'nota'      => 'Petroliera grande da rifornimento. Il gioco la usa per la T2, che nel 1942 '
                     . 'stava appena entrando in servizio e in questa edizione del manuale non c\'e\' '
                     . 'ancora: la sagoma e\' quella giusta, il nome no.',
    ],
    'trasporto_truppe' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p88.png',
        'box'       => [0.1380, 0.2560, 0.5500, 0.0560],
        'altezza'   => 64,
        'soggetto'  => 'Profilo di transatlantico da trasporto truppe (Strathaird / Strathnaver)',
        'lega'      => 'trasporto_truppe',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 43',
        'nota'      => 'Transatlantici P&O da 22.500 tonnellate, requisiti davvero per il trasporto '
                     . 'truppe. Il trasporto del gioco ne dichiara 20.000.',
    ],
    'transatlantico' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p88.png',
        'box'       => [0.1380, 0.5460, 0.5500, 0.0930],
        'altezza'   => 68,
        'soggetto'  => 'Profilo di transatlantico veloce (Empress of Britain)',
        'lega'      => 'transatlantico',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 43',
        'nota'      => 'Il transatlantico veloce del gioco dichiara 26.000 tonnellate, l\'Empress of '
                     . 'Britain ne faceva 42.000: la sagoma sta per il tipo di nave, non per la stazza.',
    ],

    'cargo_grande' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p60.png',
        'box'       => [0.1650, 0.2550, 0.2560, 0.0620],
        'altezza'   => 62,
        'soggetto'  => 'Profilo di piroscafo da carico grande (Amarapoora / Pegu / Sagaing)',
        'lega'      => 'cargo_grande',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
        'nota'      => 'Piroscafi misti carico-passeggeri della linea birmana: scafo lungo, '
                     . 'sovrastruttura centrale ampia. Rappresentano il carico grande del gioco.',
    ],
    'tramp_piccolo' => [
        'sorgente'  => __DIR__ . '/oni/oni208-p60.png',
        'box'       => [0.1650, 0.8270, 0.2560, 0.0590],
        'altezza'   => 56,
        'soggetto'  => 'Profilo di piroscafo da carico piccolo (Granville / Roseville)',
        'lega'      => 'tramp_piccolo',
        'fonte'     => 'documentale',
        'negativo'  => true,   // inchiostro su carta: va rovesciato per la plancia
        'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
        'nota'      => 'Il piccolo vapore da carico che faceva la spola lungo la costa e nei '
                     . 'convogli minori: due isole, un fumaiolo, alberi di carico.',
    ],

    // --- Aerei --------------------------------------------------------------
    'sunderland' => [
        'sorgente' => $tavolaCatalogo,
        'box'      => [0.6813, 0.8338, 0.1125, 0.0817],
        'altezza'  => 64,
        'soggetto' => 'Sagoma di idrovolante Short Sunderland',
        'lega'     => 'sunderland',
        'nota'     => 'Il pattugliatore che copre gli approcci occidentali.',
    ],
    'liberator' => [
        'sorgente' => $tavolaCatalogo,
        'box'      => [0.7953, 0.8338, 0.0992, 0.0817],
        'altezza'  => 64,
        'soggetto' => 'Sagoma di bombardiere Consolidated B-24 Liberator',
        'lega'     => 'liberator',
        'nota'     => "L'aereo a lungo raggio che chiude il buco centrale dell'Atlantico.",
    ],

    // --- Onorificenze -------------------------------------------------------
    'schwerter' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.8445, 0.0473, 0.0703, 0.1848],
        'altezza'  => 96,
        'soggetto' => 'Croce di Cavaliere con fronde di quercia e spade',
        'lega'     => 'schwerter',
        'nota'     => 'La forma della croce e del nastro e' . "'" . ' resa in modo plausibile; il numero '
                    . 'e il taglio delle fronde non sono verificati su un esemplare.',
    ],
    'frontspange' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.7875, 0.3639, 0.1828, 0.1103],
        'altezza'  => 72,
        'soggetto' => 'Fregio di fronte dei sommergibili, tre gradi',
        'lega'     => 'frontspange',
        'nota'     => 'La tavola ne mostra tre (bronzo, argento, oro). Il Fregio di fronte storico '
                    . 'era in bronzo e in argento: il terzo grado e' . "'" . ' un'
                    . "'" . 'aggiunta del disegno. Dichiarato qui invece che corretto in silenzio.',
    ],

    // --- Gradi e insegne ----------------------------------------------------
    'grado_ammiraglio' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.0563, 0.3797, 0.1141, 0.2049],
        'altezza'  => 96,
        'soggetto' => 'Mostrina e gradi da Vizeadmiral',
        'lega'     => null,
        'nota'     => 'Nel gioco i comandanti arrivano al grado di Fregattenkapitaen: questa e' . "'" . ' '
                    . "l'insegna di chi firma gli ordini dall'altra parte della radio, il BdU.",
    ],
    
    // --- Interfaccia e personalizzazione ------------------------------------
        'periscopio' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.6000, 0.6977, 0.0703, 0.1705],
        'altezza'  => 72,
        'soggetto' => 'Icona: testa di periscopio',
        'lega'     => null,
        'nota'     => "Icona d'interfaccia. Non rappresenta un modello preciso.",
    ],
    'griglia_navale' => [
        'sorgente' => $tavolaSingole,
        'box'      => [0.7922, 0.6117, 0.1773, 0.3138],
        'altezza'  => 96,
        'soggetto' => 'Icona: riquadro di griglia navale',
        'lega'     => null,
        'nota'     => "Icona d'interfaccia. Le sigle disegnate sopra non seguono il reticolo "
                    . 'Marinequadrat del gioco.',
    ],
];
