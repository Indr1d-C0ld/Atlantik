<?php

declare(strict_types=1);

/**
 * Da Natural Earth alle coste di Atlantik.
 *
 *   php bin/genera_coste.php [percorso/ne_50m_land.geojson]
 *
 * Scrive assets/js/coste.js. Il file prodotto NON si corregge a mano: si
 * rigenera da qui. I nomi sulla carta stanno altrove (assets/js/etichette.js),
 * perche' quelli sono scritti a mano e una rigenerazione non deve portarseli via.
 *
 * Dato d'ingresso: ne_50m_land.geojson dal repository ufficiale Natural Earth
 *   https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_land.geojson
 * Natural Earth e' di pubblico dominio (nessuna attribuzione dovuta; la diamo
 * lo stesso, in docs/FONTI.md, perche' e' giusto cosi').
 *
 * Tre passaggi, in quest'ordine:
 *   1. ritaglio al teatro atlantico (Sutherland-Hodgman su un rettangolo);
 *   2. semplificazione Douglas-Peucker alla tolleranza della carta;
 *   3. scarto degli anelli troppo piccoli per lasciare un segno.
 *
 * Il ritaglio introduce lati che non sono coste ma bordi del riquadro: vengono
 * marcati, perche' vanno riempiti ma non disegnati come linea di costa —
 * altrimenti la carta mostrerebbe una spiaggia dritta lungo il 108° meridiano.
 */

$progetto = require __DIR__ . '/_bootstrap.php';

const LON_W = -108.0;
const LON_E =   44.0;
const LAT_S =  -62.0;
const LAT_N =   83.0;

/** Gradi: ~3,3 km, sotto il pixel a qualunque ingrandimento utile della carta. */
const TOLLERANZA = 0.03;
/** Gradi quadrati: sotto questa soglia l'isola sulla carta e' un punto. */
const AREA_MINIMA = 0.004;

// Il dato d'ingresso sta nel progetto, compresso: cosi' la carta si rigenera
// senza rete e senza fidarsi che un indirizzo sia ancora al suo posto fra un
// anno. Un percorso esplicito ha comunque la precedenza.
$sorgente = $argv[1] ?? null;
if ($sorgente === null) {
    foreach ([
        $progetto . '/db/seed/ne_50m_land.geojson.gz',
        $progetto . '/db/seed/ne_50m_land.geojson',
    ] as $tentativo) {
        if (is_readable($tentativo)) {
            $sorgente = $tentativo;
            break;
        }
    }
}
if ($sorgente === null || !is_readable($sorgente)) {
    fwrite(STDERR, "Non trovo il dato di Natural Earth.\n"
        . "Atteso in db/seed/ne_50m_land.geojson[.gz], oppure passalo come argomento.\n"
        . "Origine:\n  https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_land.geojson\n");
    exit(1);
}
if (str_ends_with($sorgente, '.gz')) {
    $sorgente = 'compress.zlib://' . $sorgente;
}

// --- 1. ritaglio -------------------------------------------------------------

/** @param array{0:float,1:float} $p */
function dentro(array $p, string $lato): bool
{
    return match ($lato) {
        'W' => $p[0] >= LON_W,
        'E' => $p[0] <= LON_E,
        'S' => $p[1] >= LAT_S,
        default => $p[1] <= LAT_N,
    };
}

/** @return array{0:float,1:float} */
function incrocio(array $a, array $b, string $lato): array
{
    [$x1, $y1] = $a;
    [$x2, $y2] = $b;
    if ($lato === 'W' || $lato === 'E') {
        $xc = $lato === 'W' ? LON_W : LON_E;
        $t = ($x2 - $x1) != 0.0 ? ($xc - $x1) / ($x2 - $x1) : 0.0;
        return [$xc, $y1 + $t * ($y2 - $y1)];
    }
    $yc = $lato === 'S' ? LAT_S : LAT_N;
    $t = ($y2 - $y1) != 0.0 ? ($yc - $y1) / ($y2 - $y1) : 0.0;
    return [$x1 + $t * ($x2 - $x1), $yc];
}

/**
 * Sutherland-Hodgman. Ogni vertice porta un segno: true = nato dal ritaglio,
 * quindi il lato che ne esce non e' costa.
 *
 * @param list<array{0:float,1:float}> $anello
 * @return list<array{0:array{0:float,1:float},1:bool}>
 */
function ritaglia(array $anello): array
{
    $punti = array_map(static fn (array $p): array => [$p, false], $anello);
    foreach (['W', 'E', 'S', 'N'] as $lato) {
        if ($punti === []) {
            return [];
        }
        $fuori = [];
        $n = count($punti);
        for ($i = 0; $i < $n; $i++) {
            [$a, $fa] = $punti[$i];
            [$b, $fb] = $punti[($i + 1) % $n];
            $da = dentro($a, $lato);
            $db = dentro($b, $lato);
            if ($da) {
                $fuori[] = [$a, $fa];
                if (!$db) {
                    $fuori[] = [incrocio($a, $b, $lato), true];
                }
            } elseif ($db) {
                $fuori[] = [incrocio($a, $b, $lato), true];
            }
        }
        $punti = $fuori;
    }
    return $punti;
}

// --- 2. semplificazione ------------------------------------------------------

function douglasPeucker(array $punti, float $eps): array
{
    $n = count($punti);
    if ($n < 3) {
        return $punti;
    }
    [$a] = $punti[0];
    [$b] = $punti[$n - 1];
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $lun = sqrt($dx * $dx + $dy * $dy);

    $dmax = 0.0;
    $idx = 0;
    for ($i = 1; $i < $n - 1; $i++) {
        [$p] = $punti[$i];
        $d = $lun == 0.0
            ? sqrt(($p[0] - $a[0]) ** 2 + ($p[1] - $a[1]) ** 2)
            : abs($dy * $p[0] - $dx * $p[1] + $b[0] * $a[1] - $b[1] * $a[0]) / $lun;
        if ($d > $dmax) {
            $dmax = $d;
            $idx = $i;
        }
    }
    if ($dmax > $eps) {
        $sx = douglasPeucker(array_slice($punti, 0, $idx + 1), $eps);
        $dx2 = douglasPeucker(array_slice($punti, $idx), $eps);
        array_pop($sx);
        return array_merge($sx, $dx2);
    }
    return [$punti[0], $punti[$n - 1]];
}

// --- 3. area -----------------------------------------------------------------

function areaAnello(array $punti): float
{
    $s = 0.0;
    $n = count($punti);
    for ($i = 0; $i < $n; $i++) {
        [$p1] = $punti[$i];
        [$p2] = $punti[($i + 1) % $n];
        $s += $p1[0] * $p2[1] - $p2[0] * $p1[1];
    }
    return abs($s) / 2.0;
}

/** Il lato fra due vertici e' bordo del ritaglio, non costa? */
function bordoDelRitaglio(array $a, array $b, bool $fa, bool $fb): bool
{
    if (!$fa || !$fb) {
        return false;
    }
    $t = 0.02;
    return (abs($a[0] - LON_W) < $t && abs($b[0] - LON_W) < $t)
        || (abs($a[0] - LON_E) < $t && abs($b[0] - LON_E) < $t)
        || (abs($a[1] - LAT_S) < $t && abs($b[1] - LAT_S) < $t)
        || (abs($a[1] - LAT_N) < $t && abs($b[1] - LAT_N) < $t);
}

// --- lavorazione -------------------------------------------------------------

$dati = json_decode((string) file_get_contents($sorgente), true, 512, JSON_THROW_ON_ERROR);
$anelli = [];

foreach ($dati['features'] as $ft) {
    $g = $ft['geometry'];
    $poligoni = $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
    foreach ($poligoni as $poly) {
        // Solo l'anello esterno: i buchi sono laghi, e a una carta nautica
        // dell'Atlantico non servono.
        $anello = $poly[0];
        $xs = array_column($anello, 0);
        $ys = array_column($anello, 1);
        if (max($xs) < LON_W || min($xs) > LON_E || max($ys) < LAT_S || min($ys) > LAT_N) {
            continue;
        }
        $r = ritaglia(array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $anello));
        if (count($r) < 4) {
            continue;
        }
        $r = douglasPeucker($r, TOLLERANZA);
        if (count($r) < 4 || areaAnello($r) < AREA_MINIMA) {
            continue;
        }
        $anelli[] = $r;
    }
}

usort($anelli, static fn (array $a, array $b): int => areaAnello($b) <=> areaAnello($a));

$righe = [];
$vertici = 0;
$conBordo = 0;
foreach ($anelli as $anello) {
    $n = count($anello);
    $vertici += $n;

    $reale = [];
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $reale[$i] = !bordoDelRitaglio($anello[$i][0], $anello[$j][0], $anello[$i][1], $anello[$j][1]);
    }
    $tutto = !in_array(false, $reale, true);
    if (!$tutto) {
        $conBordo++;
    }

    $tratte = [];
    if (!$tutto) {
        $i = 0;
        while ($i < $n) {
            if ($reale[$i]) {
                $j = $i;
                while ($j < $n && $reale[$j]) {
                    $j++;
                }
                $tratte[] = [$i, $j - $i];
                $i = $j;
            } else {
                $i++;
            }
        }
    }

    $lat = [];
    $lon = [];
    $piatti = [];
    foreach ($anello as [$p]) {
        $la = round($p[1], 2);
        $lo = round($p[0], 2);
        $lat[] = $la;
        $lon[] = $lo;
        $piatti[] = $la;
        $piatti[] = $lo;
    }

    $riga = '{b:[' . implode(', ', [min($lat), max($lat), min($lon), max($lon)]) . ']'
          . ',p:[' . implode(',', $piatti) . ']';
    if ($tratte !== []) {
        $riga .= ',t:[' . implode(',', array_map(static fn (array $t): string => '[' . $t[0] . ',' . $t[1] . ']', $tratte)) . ']';
    }
    $righe[] = $riga . '}';
}

$testata = <<<'JS'
/* Linee di costa dell'Atlantico — Natural Earth 1:50m, pubblico dominio.
 *
 * FILE GENERATO da bin/genera_coste.php: non si corregge a mano, si rigenera.
 * I nomi sulla carta stanno in assets/js/etichette.js, che invece e' scritto
 * a mano e una rigenerazione non deve portarselo via.
 *
 * Lavorazione: ritaglio al teatro (108°O..44°E, 62°S..83°N), semplificazione
 * Douglas-Peucker a 0,03° (~3,3 km, sotto il pixel a qualunque ingrandimento
 * utile), scarto degli anelli sotto 0,004 gradi quadrati. Coordinate in
 * centesimi di grado.
 *
 * Verifica di posizione — le stesse misure con cui e' stata bocciata la carta
 * illustrata (docs/AUDIT.md, punto 16):
 *   Islanda       63,41..66,52 N   24,48..13,56 O   (reale 63,39..66,53 / 24,54..13,50)
 *   Gran Bretagna 50,02..58,65 N    6,13 O..1,75 E  (reale 49,96..58,64 /  6,22 O..1,76 E)
 * Scarto sotto il decimo di grado: e' la tolleranza di semplificazione, non un errore.
 *
 * Ogni anello ha:
 *   b  riquadro [latS, latN, lonO, lonE], per saltare cio' che non e' in vista
 *   p  vertici appiattiti [lat, lon, lat, lon, ...], anello chiuso
 *   t  tratte di costa VERA [inizio, quanti]: dove manca, l'anello e' tutto
 *      costa; dove c'e', i lati esclusi sono il bordo del ritaglio e vanno
 *      riempiti ma non disegnati come linea di costa.
 */
window.ATL_TERRE = [
JS;

// La provenienza viaggia col dato: sulla carta compare in chiaro, cosi' chi la
// guarda sa sempre quale rilievo sta vedendo — e se un giorno il browser gli
// servisse un file vecchio di cache, se ne accorge invece di indovinare.
$provenienza = sprintf(
    "\nwindow.ATL_COSTE_FONTE = { nome: 'Natural Earth 1:50m', anelli: %d, vertici: %d };\n",
    count($anelli),
    $vertici
);

$uscita = $progetto . '/assets/js/coste.js';
file_put_contents($uscita, $testata . "\n" . implode(",\n", $righe) . "\n];\n" . $provenienza);

printf("Scritto %s\n", $uscita);
printf("  anelli   : %d (con bordo di ritaglio: %d)\n", count($anelli), $conBordo);
printf("  vertici  : %d\n", $vertici);
printf("  dimensione: %.0f KB\n", filesize($uscita) / 1024);
