<?php

declare(strict_types=1);

/**
 * Dimensioni documentate dei velivoli, e come si presentano in pianta.
 *
 * Da qui bin/disegna_aerei.php ricava le sagome. Come per le navi, il dato e'
 * questo file e il disegno e' una conseguenza.
 *
 * PERCHE' LA PIANTA E NON IL PROFILO. La vista dall'alto e' quella classica dei
 * manuali di riconoscimento aereo, ed e' anche l'unica che due numeri
 * documentati — apertura alare e lunghezza — definiscano quasi per intero. Il
 * profilo laterale dipende da dettagli (forma della deriva, carrello, gondole)
 * che non stanno nelle schede tecniche, e inventarli vorrebbe dire insegnare a
 * riconoscere aerei che non esistono.
 *
 * documentato : apertura alare, lunghezza, numero di motori, numero di ali
 * ricostruito : la FORMA della pianta — profilo dell'ala, larghezza della fusoliera,
 *               posizione dei motori lungo l'apertura
 *
 * Misure in metri.
 */

return [
    'swordfish' => [
        'nome'       => 'Fairey Swordfish',
        'apertura'   => 13.87,
        'lunghezza'  => 10.87,
        'motori'     => 1,
        'ali'        => 2,
        'fonte'      => 'Fairey Swordfish: apertura 45 ft 6 in (13,87 m), lunghezza 35 ft 8 in '
                      . '(10,87 m), altezza 12 ft 4 in (3,76 m), un motore radiale Bristol Pegasus, '
                      . 'biplano, equipaggio di tre.',
        'nota'       => "Il biplano che decollava dalle portaerei di scorta: lento, e per questo "
                      . "capace di star dietro a un convoglio per ore.",
    ],
    'hudson' => [
        'nome'       => 'Lockheed Hudson',
        'apertura'   => 19.96,
        'lunghezza'  => 13.51,
        'motori'     => 2,
        'ali'        => 1,
        'fonte'      => 'Lockheed Hudson: apertura 65 ft 6 in (19,96 m), lunghezza 44 ft 4 in '
                      . '(13,51 m), altezza 11 ft 10 in (3,61 m), due motori radiali Wright Cyclone, '
                      . 'equipaggio di cinque.',
        'nota'       => 'La configurazione della coda non compare nella scheda consultata e non '
                      . 'viene percio' . "'" . ' disegnata come segno di riconoscimento: la sagoma sta '
                      . 'sull\'apertura e sulla lunghezza, che sono documentate.',
    ],
    'wellington_leigh' => [
        'nome'       => 'Vickers Wellington con faro Leigh',
        'apertura'   => 26.26,
        'lunghezza'  => 19.69,
        'motori'     => 2,
        'ali'        => 1,
        'fonte'      => 'Vickers Wellington Mk IC: apertura 86 ft 2 in (26,26 m), lunghezza 64 ft '
                      . '7 in (19,69 m), altezza 17 ft 5 in (5,31 m), due motori radiali Bristol '
                      . 'Pegasus, equipaggio di cinque o sei.',
        'nota'       => 'Il faro Leigh era un proiettore da ventiquattro pollici calato dalla '
                      . 'pancia: si accendeva nell\'ultimo miglio, quando il radar aveva gia' . "'" . ' '
                      . 'fatto il suo. Non si vede in pianta.',
    ],
];
