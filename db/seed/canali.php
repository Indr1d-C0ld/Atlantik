<?php

declare(strict_types=1);

/**
 * I canali d'uscita delle basi: dal porto al mare aperto.
 *
 * Nella carta 1:50m di Natural Earth — la stessa della carta di bordo e, dal
 * 30/09/2026, della simulazione — sette basi su nove stanno sulla terraferma:
 * la rada di Lorient, il Goulet de Brest, la Gironda, il fiordo di Trondheim
 * sono troppo stretti per quella scala, e Bordeaux sta cinquanta miglia su per
 * il fiume. Qui si scrive la via d'acqua che la carta non ha: una spezzata dal
 * porto verso il largo. Intorno alla spezzata, per CORRIDOIO_NM miglia per
 * parte, si naviga anche dove la carta dice terra.
 *
 * I punti seguono i passaggi storici: il canale di Kiel (Kaiser-Wilhelm-Kanal)
 * fino a Brunsbuettel e la foce dell'Elba, la Jade per Wilhelmshaven, il
 * Coureau de Groix per Lorient, il Goulet e l'Iroise per Brest, la Loira, il
 * Pertuis d'Antioche per La Pallice, la Gironda fino a Royan, lo Hjeltefjord
 * per Bergen, il Trondheimsfjord fino ad Agdenes. Precisione da carta 1:50m:
 * qualche decimo di miglio, non una carta da pilota.
 *
 * L'ultimo punto di ogni canale e' l'«uscita»: mare aperto, lontano abbastanza
 * dalla costa perche' da li' una rotta si possa tracciare liberamente. Lo
 * verifica tests/test_terra.php.
 */
return [
    'lorient' => [
        [47.748, -3.367], [47.720, -3.360], [47.700, -3.368], [47.680, -3.385],
        [47.660, -3.410], [47.620, -3.440], [47.560, -3.480], [47.480, -3.520],
    ],
    'brest' => [
        [48.383, -4.500], [48.360, -4.540], [48.348, -4.590], [48.340, -4.640],
        [48.320, -4.720], [48.290, -4.820], [48.250, -4.980], [48.200, -5.150],
    ],
    'saint_nazaire' => [
        [47.283, -2.200], [47.250, -2.260], [47.210, -2.330], [47.150, -2.430],
        [47.080, -2.550],
    ],
    'la_pallice' => [
        [46.158, -1.217], [46.140, -1.270], [46.110, -1.340], [46.080, -1.420],
        [46.040, -1.530], [46.000, -1.650],
    ],
    'bordeaux' => [
        [44.860, -0.550], [44.950, -0.580], [45.050, -0.650], [45.150, -0.700],
        [45.250, -0.760], [45.350, -0.860], [45.450, -0.940], [45.550, -1.020],
        [45.610, -1.080], [45.650, -1.200], [45.650, -1.350], [45.600, -1.550],
    ],
    'kiel' => [
        [54.320, 10.140], [54.365, 10.140], [54.355, 10.050], [54.330, 9.930],
        [54.300, 9.800], [54.300, 9.670], [54.230, 9.550], [54.130, 9.400],
        [54.000, 9.270], [53.890, 9.140], [53.880, 8.950], [53.900, 8.720],
        [53.950, 8.450], [54.000, 8.200], [54.050, 7.900],
    ],
    'wilhelmshaven' => [
        [53.510, 8.140], [53.560, 8.150], [53.620, 8.160], [53.700, 8.140],
        [53.780, 8.100], [53.860, 8.020], [53.950, 7.900], [54.020, 7.750],
    ],
    'bergen' => [
        [60.390, 5.320], [60.410, 5.270], [60.440, 5.200], [60.490, 5.100],
        [60.550, 5.000], [60.620, 4.900], [60.690, 4.800], [60.720, 4.650],
        [60.720, 4.450],
    ],
    'trondheim' => [
        [63.440, 10.400], [63.460, 10.250], [63.490, 10.100], [63.530, 9.950],
        [63.580, 9.820], [63.630, 9.720], [63.670, 9.580], [63.720, 9.400],
        [63.770, 9.150], [63.820, 8.850], [63.850, 8.500],
    ],
];
