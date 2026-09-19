<?php

declare(strict_types=1);

/**
 * Gradi e livelli di anzianita'.
 *
 * Il "livello" (0-12) e' l'anzianita' operativa che sblocca battelli, siluri e
 * apparati; il grado e' il titolo che gli corrisponde. Nella realta' i
 * comandanti di U-Boot erano quasi tutti Oberleutnant o Kapitaenleutnant: i
 * gradi superiori comandavano flottiglie, non battelli. Qui si sale piu' in
 * alto perche' la carriera del giocatore e' una sola e deve durare.
 */

return [
    ['livello' => 0,  'grado' => 'Oberleutnant zur See', 'prestigio' => 0],
    ['livello' => 1,  'grado' => 'Oberleutnant zur See', 'prestigio' => 250],
    ['livello' => 2,  'grado' => 'Kapitaenleutnant',     'prestigio' => 700],
    ['livello' => 3,  'grado' => 'Kapitaenleutnant',     'prestigio' => 1400],
    ['livello' => 4,  'grado' => 'Kapitaenleutnant',     'prestigio' => 2400],
    ['livello' => 5,  'grado' => 'Korvettenkapitaen',    'prestigio' => 3800],
    ['livello' => 6,  'grado' => 'Korvettenkapitaen',    'prestigio' => 5600],
    ['livello' => 7,  'grado' => 'Korvettenkapitaen',    'prestigio' => 7800],
    ['livello' => 8,  'grado' => 'Korvettenkapitaen',    'prestigio' => 10500],
    ['livello' => 9,  'grado' => 'Fregattenkapitaen',    'prestigio' => 14000],
    ['livello' => 10, 'grado' => 'Fregattenkapitaen',    'prestigio' => 18000],
    ['livello' => 11, 'grado' => 'Fregattenkapitaen',    'prestigio' => 23000],
    ['livello' => 12, 'grado' => 'Fregattenkapitaen',    'prestigio' => 30000],
];
