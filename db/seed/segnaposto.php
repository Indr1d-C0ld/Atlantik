<?php

declare(strict_types=1);

/**
 * Registro delle figure di segnaposto.
 *
 * FILE GENERATO da bin/taglia_segnaposto.php: non si corregge a mano, si
 * rigenera. Il piano dei ritagli — quello scritto a mano, che dice cos'e' ogni
 * figura — sta in db/seed/segnaposto_ritagli.php.
 *
 * Qui dentro stanno SOLO ricostruzioni: disegni generati, non riferimenti
 * documentali. Servono a dare una faccia alle cose e non a insegnare a
 * riconoscere niente. Vedi docs/FONTI.md.
 *
 * REGOLA: nessuna figura entra nel gioco senza una riga qui, e nessuna riga
 * qui senza il suo file. tests/test_segnaposto.php lo verifica in tutte e due
 * le direzioni.
 */

return array (
  'uboot_viic' => 
  array (
    'file' => 'uboot_viic.webp',
    'soggetto' => 'Sagoma di U-Boot Tipo VII C in superficie',
    'lega' => 'VIIC',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'Il battello del gioco. Proporzioni e dettagli non verificati sui piani costruttivi: torretta, cannone e ponte sono resi in modo plausibile, non esatto.',
  ),
  'liberty' => 
  array (
    'file' => 'liberty.webp',
    'soggetto' => 'Sagoma di Liberty ship',
    'lega' => 'liberty',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'La nave da carico di serie della guerra. La sagoma vera e\' molto documentata: questa le somiglia, non e\' presa da un piano.',
  ),
  'cargo_medio' => 
  array (
    'file' => 'cargo_medio.webp',
    'soggetto' => 'Profilo di piroscafo da carico a tre isole (Clan Macdougall)',
    'lega' => 'cargo_medio',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
    'nota' => 'Il piroscafo da carico britannico di configurazione classica: castello, tuga centrale, cassero. Rappresenta la categoria, non una stazza precisa.',
  ),
  'petroliera_media' => 
  array (
    'file' => 'petroliera_media.webp',
    'soggetto' => 'Profilo di petroliera a motrice poppiera (Trontolite)',
    'lega' => 'petroliera_media',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 47 (ENGINES AFT, TYPE T)',
    'nota' => 'La passerella che corre da prua a poppa sopra il ponte e\' il segno che distingue una petroliera da un carico: e\' scritto nel manuale stesso.',
  ),
  'petroliera_t2' => 
  array (
    'file' => 'petroliera_t2.webp',
    'soggetto' => 'Profilo di petroliera di squadra (Cadillac / Saranac)',
    'lega' => 'petroliera_t2',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 47',
    'nota' => 'Petroliera grande da rifornimento. Il gioco la usa per la T2, che nel 1942 stava appena entrando in servizio e in questa edizione del manuale non c\'e\' ancora: la sagoma e\' quella giusta, il nome no.',
  ),
  'trasporto_truppe' => 
  array (
    'file' => 'trasporto_truppe.webp',
    'soggetto' => 'Profilo di transatlantico da trasporto truppe (Strathaird / Strathnaver)',
    'lega' => 'trasporto_truppe',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 43',
    'nota' => 'Transatlantici P&O da 22.500 tonnellate, requisiti davvero per il trasporto truppe. Il trasporto del gioco ne dichiara 20.000.',
  ),
  'transatlantico' => 
  array (
    'file' => 'transatlantico.webp',
    'soggetto' => 'Profilo di transatlantico veloce (Empress of Britain)',
    'lega' => 'transatlantico',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 43',
    'nota' => 'Il transatlantico veloce del gioco dichiara 26.000 tonnellate, l\'Empress of Britain ne faceva 42.000: la sagoma sta per il tipo di nave, non per la stazza.',
  ),
  'cargo_grande' => 
  array (
    'file' => 'cargo_grande.webp',
    'soggetto' => 'Profilo di piroscafo da carico grande (Amarapoora / Pegu / Sagaing)',
    'lega' => 'cargo_grande',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
    'nota' => 'Piroscafi misti carico-passeggeri della linea birmana: scafo lungo, sovrastruttura centrale ampia. Rappresentano il carico grande del gioco.',
  ),
  'tramp_piccolo' => 
  array (
    'file' => 'tramp_piccolo.webp',
    'soggetto' => 'Profilo di piroscafo da carico piccolo (Granville / Roseville)',
    'lega' => 'tramp_piccolo',
    'fonte' => 'documentale',
    'documento' => 'ONI 208, Merchant Ship Recognition Manual, p. 29',
    'nota' => 'Il piccolo vapore da carico che faceva la spola lungo la costa e nei convogli minori: due isole, un fumaiolo, alberi di carico.',
  ),
  'sunderland' => 
  array (
    'file' => 'sunderland.webp',
    'soggetto' => 'Sagoma di idrovolante Short Sunderland',
    'lega' => 'sunderland',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'Il pattugliatore che copre gli approcci occidentali.',
  ),
  'liberator' => 
  array (
    'file' => 'liberator.webp',
    'soggetto' => 'Sagoma di bombardiere Consolidated B-24 Liberator',
    'lega' => 'liberator',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'L\'aereo a lungo raggio che chiude il buco centrale dell\'Atlantico.',
  ),
  'schwerter' => 
  array (
    'file' => 'schwerter.webp',
    'soggetto' => 'Croce di Cavaliere con fronde di quercia e spade',
    'lega' => 'schwerter',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'La forma della croce e del nastro e\' resa in modo plausibile; il numero e il taglio delle fronde non sono verificati su un esemplare.',
  ),
  'frontspange' => 
  array (
    'file' => 'frontspange.webp',
    'soggetto' => 'Fregio di fronte dei sommergibili, tre gradi',
    'lega' => 'frontspange',
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'La tavola ne mostra tre (bronzo, argento, oro). Il Fregio di fronte storico era in bronzo e in argento: il terzo grado e\' un\'aggiunta del disegno. Dichiarato qui invece che corretto in silenzio.',
  ),
  'grado_ammiraglio' => 
  array (
    'file' => 'grado_ammiraglio.webp',
    'soggetto' => 'Mostrina e gradi da Vizeadmiral',
    'lega' => NULL,
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'Nel gioco i comandanti arrivano al grado di Fregattenkapitaen: questa e\' l\'insegna di chi firma gli ordini dall\'altra parte della radio, il BdU.',
  ),
  'periscopio' => 
  array (
    'file' => 'periscopio.webp',
    'soggetto' => 'Icona: testa di periscopio',
    'lega' => NULL,
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'Icona d\'interfaccia. Non rappresenta un modello preciso.',
  ),
  'griglia_navale' => 
  array (
    'file' => 'griglia_navale.webp',
    'soggetto' => 'Icona: riquadro di griglia navale',
    'lega' => NULL,
    'fonte' => 'ricostruzione',
    'documento' => NULL,
    'nota' => 'Icona d\'interfaccia. Le sigle disegnate sopra non seguono il reticolo Marinequadrat del gioco.',
  ),
);
