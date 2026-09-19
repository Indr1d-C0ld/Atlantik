<?php

declare(strict_types=1);

/**
 * Prove del dato delle coste (assets/js/coste.js).
 *
 *   php tests/test_coste.php
 *
 * Il file e' generato da bin/genera_coste.php a partire da Natural Earth. Se
 * qualcuno lo rigenera con parametri sbagliati — tolleranza troppo larga,
 * ritaglio storto, soglia d'area che si mangia le Azzorre — la carta smette di
 * essere una carta e nessuno se ne accorge finche' non ci naviga sopra.
 * Queste prove se ne accorgono.
 *
 * Nessun database, nessuna e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

$falliti = 0;
function titolo(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function ok(string $titolo, bool $esito, string $dettaglio = ''): void
{
    global $falliti;
    if ($esito) {
        echo "  \033[0;32mok\033[0m    {$titolo}" . ($dettaglio !== '' ? "  \033[0;90m{$dettaglio}\033[0m" : '') . "\n";
    } else {
        echo "  \033[0;31mKO\033[0m    {$titolo}" . ($dettaglio !== '' ? "  ({$dettaglio})" : '') . "\n";
        $falliti++;
    }
}

$percorso = dirname(__DIR__) . '/assets/js/coste.js';
$js = (string) file_get_contents($percorso);

/**
 * Legge gli anelli dal file generato. Non e' JSON (le chiavi non hanno le
 * virgolette), ma la forma e' fissa e la scrive il nostro generatore.
 *
 * @return list<array{b:list<float>,p:list<float>,t:list<array{0:int,1:int}>}>
 */
function leggiAnelli(string $js): array
{
    preg_match_all('/\{b:\[([^\]]*)\],p:\[([^\]]*)\](?:,t:\[(.*?)\])?\}/', $js, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $tratte = [];
        if (($x[3] ?? '') !== '') {
            preg_match_all('/\[(\d+),(\d+)\]/', $x[3], $tm, PREG_SET_ORDER);
            foreach ($tm as $t) {
                $tratte[] = [(int) $t[1], (int) $t[2]];
            }
        }
        $out[] = [
            'b' => array_map('floatval', explode(',', $x[1])),
            'p' => array_map('floatval', explode(',', $x[2])),
            't' => $tratte,
        ];
    }
    return $out;
}

/** L'anello piu' piccolo che contiene il punto: e' l'isola, non il continente. */
function isolaCon(array $anelli, float $lat, float $lon): ?array
{
    $migliore = null;
    $area = INF;
    foreach ($anelli as $a) {
        $p = $a['p'];
        $n = count($p) / 2;
        $dentro = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = $p[$i * 2]; $xi = $p[$i * 2 + 1];
            $yj = $p[$j * 2]; $xj = $p[$j * 2 + 1];
            if ((($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $dentro = !$dentro;
            }
        }
        if ($dentro) {
            $ar = ($a['b'][1] - $a['b'][0]) * ($a['b'][3] - $a['b'][2]);
            if ($ar < $area) {
                $area = $ar;
                $migliore = $a;
            }
        }
    }
    return $migliore;
}

$anelli = leggiAnelli($js);

// --- il file ----------------------------------------------------------------
titolo('Il file delle coste');

ok('il file esiste ed e\' leggibile', $js !== '');
ok('e\' dichiarato come generato', str_contains($js, 'FILE GENERATO'));
ok('gli anelli si leggono', count($anelli) > 400, count($anelli) . ' anelli');

$vertici = 0;
foreach ($anelli as $a) {
    $vertici += count($a['p']) / 2;
}
ok('i vertici bastano per una costa credibile', $vertici > 9000, $vertici . ' vertici');
ok('e non sono tanti da appesantire la carta', $vertici < 22000, $vertici . ' vertici');
ok('il file sta sotto i 250 KB', filesize($percorso) < 250 * 1024,
    sprintf('%.0f KB', filesize($percorso) / 1024));

// --- forma del dato ---------------------------------------------------------
titolo('Forma del dato');

$coppie = true;
$riquadriGiusti = 0;
$tratteValide = true;
foreach ($anelli as $a) {
    if (count($a['p']) % 2 !== 0 || count($a['p']) < 8) {
        $coppie = false;
    }
    $n = count($a['p']) / 2;
    $lat = []; $lon = [];
    for ($i = 0; $i < $n; $i++) {
        $lat[] = $a['p'][$i * 2];
        $lon[] = $a['p'][$i * 2 + 1];
    }
    if (abs(min($lat) - $a['b'][0]) < 0.011 && abs(max($lat) - $a['b'][1]) < 0.011
        && abs(min($lon) - $a['b'][2]) < 0.011 && abs(max($lon) - $a['b'][3]) < 0.011) {
        $riquadriGiusti++;
    }
    foreach ($a['t'] as [$da, $quanti]) {
        if ($da < 0 || $quanti < 1 || $da + $quanti > $n) {
            $tratteValide = false;
        }
    }
}
ok('ogni vertice e\' una coppia lat/lon', $coppie);
ok('ogni riquadro corrisponde ai suoi vertici', $riquadriGiusti === count($anelli),
    $riquadriGiusti . ' su ' . count($anelli));
ok('le tratte di costa stanno dentro l\'anello', $tratteValide);

$conTratte = count(array_filter($anelli, static fn (array $a): bool => $a['t'] !== []));
ok('solo poche terre sono tagliate al bordo del teatro', $conTratte > 0 && $conTratte < 30,
    $conTratte . ' anelli col bordo di ritaglio');

// --- posizione: la prova che conta ------------------------------------------
titolo('Posizione — le stesse misure con cui e\' stata bocciata la carta illustrata');

/** @param array{0:float,1:float,2:float,3:float} $atteso */
function verificaTerra(array $anelli, string $nome, float $lat, float $lon, array $atteso, float $tol = 0.15): void
{
    $a = isolaCon($anelli, $lat, $lon);
    if ($a === null) {
        ok($nome . ': presente sulla carta', false, 'non trovata');
        return;
    }
    $b = $a['b'];
    $scarto = max(
        abs($b[0] - $atteso[0]), abs($b[1] - $atteso[1]),
        abs($b[2] - $atteso[2]), abs($b[3] - $atteso[3])
    );
    ok(
        sprintf('%s al suo posto entro %.2f°', $nome, $tol),
        $scarto <= $tol,
        sprintf('scarto massimo %.2f° (%.0f miglia)', $scarto, $scarto * 60)
    );
}

verificaTerra($anelli, 'Islanda',       64.90, -19.00, [63.39, 66.53, -24.54, -13.50]);
verificaTerra($anelli, 'Gran Bretagna', 54.00,  -2.00, [49.96, 58.64,  -6.22,   1.76]);
verificaTerra($anelli, 'Irlanda',       53.30,  -8.00, [51.45, 55.38, -10.48,  -5.47]);
verificaTerra($anelli, 'Terranova',     48.50, -56.00, [46.61, 51.73, -59.41, -52.62]);
verificaTerra($anelli, 'Cuba',          21.80, -78.80, [19.83, 23.23, -84.95, -74.13]);

// Le basi delle flottiglie devono cadere dove c'e' la terra: e' la prova che
// il dato della carta e quello del motore parlano dello stesso pianeta. Non si
// pretende che affaccino sull'oceano aperto — Bordeaux sta cinquanta miglia su
// per la Gironda e Trondheim in fondo a un fiordo, ed e' giusto cosi'.
titolo('Le basi cadono dove c\'e\' la terra');

$terraVicina = static function (array $anelli, float $la, float $lo, float $raggio): bool {
    for ($i = 0; $i < 16; $i++) {
        $a = $i * M_PI / 8;
        if (isolaCon($anelli, $la + $raggio * cos($a), $lo + $raggio * sin($a) / max(0.2, cos(deg2rad($la)))) !== null) {
            return true;
        }
    }
    return isolaCon($anelli, $la, $lo) !== null;
};
$mareVicino = static function (array $anelli, float $la, float $lo, float $raggio): bool {
    for ($i = 0; $i < 16; $i++) {
        $a = $i * M_PI / 8;
        if (isolaCon($anelli, $la + $raggio * cos($a), $lo + $raggio * sin($a) / max(0.2, cos(deg2rad($la)))) === null) {
            return true;
        }
    }
    return false;
};

foreach (\App\Sim\World::ports() as $porto) {
    if ((string) $porto['kind'] !== 'base') {
        continue;
    }
    $la = (float) $porto['lat'];
    $lo = (float) $porto['lon'];
    ok(
        sprintf('%s: terra entro 30 miglia', (string) $porto['name']),
        $terraVicina($anelli, $la, $lo, 0.5)
    );
    ok(
        sprintf('%s: mare raggiungibile entro 120 miglia', (string) $porto['name']),
        $mareVicino($anelli, $la, $lo, 2.0)
    );
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
