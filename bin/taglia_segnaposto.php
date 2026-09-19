<?php

declare(strict_types=1);

/**
 * Dalle tavole di riferimento alle singole figure di segnaposto.
 *
 *   php bin/taglia_segnaposto.php [--secco]
 *
 * Legge il piano dei ritagli (db/seed/segnaposto_ritagli.php), ritaglia ogni
 * figura dalla sua tavola, toglie lo sfondo attorno, la riduce e la salva in
 * WebP dentro assets/img/segnaposto/. Alla fine scrive il registro
 * (db/seed/segnaposto.php), che e' quello che il gioco legge.
 *
 * Il piano e' scritto a mano — dice che cos'e' ogni figura e a che cosa si
 * attacca — il registro invece e' generato: non si corregge, si rigenera.
 *
 * --secco  mostra cosa farebbe senza scrivere niente.
 */

$progetto = require __DIR__ . '/_bootstrap.php';

$secco = in_array('--secco', $argv, true);
$piano = require $progetto . '/db/seed/segnaposto_ritagli.php';
$uscita = $progetto . '/assets/img/segnaposto';

if (!is_dir($uscita) && !$secco) {
    mkdir($uscita, 0775, true);
}

/** Il colore dello sfondo, preso dall'angolo: la pergamena delle tavole. */
function coloreSfondo(\GdImage $im): array
{
    $c = imagecolorat($im, 2, 2);
    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

function vicinoA(array $a, array $b, int $tolleranza): bool
{
    return abs($a[0] - $b[0]) <= $tolleranza
        && abs($a[1] - $b[1]) <= $tolleranza
        && abs($a[2] - $b[2]) <= $tolleranza;
}

/**
 * Stringe il riquadro attorno a quello che c'e' davvero, buttando via la
 * pergamena tutto intorno. Senza, ogni figura si porterebbe dietro mezza tavola
 * di margine e il ritaglio dovrebbe essere perfetto al pixel.
 */
function stringi(\GdImage $im, array $box, array $sfondo, int $tolleranza = 26): array
{
    [$x0, $y0, $w, $h] = $box;
    $minx = $x0 + $w; $maxx = $x0; $miny = $y0 + $h; $maxy = $y0;
    for ($x = $x0; $x < $x0 + $w; $x++) {
        for ($y = $y0; $y < $y0 + $h; $y++) {
            $c = imagecolorat($im, $x, $y);
            $p = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            if (!vicinoA($p, $sfondo, $tolleranza)) {
                if ($x < $minx) { $minx = $x; }
                if ($x > $maxx) { $maxx = $x; }
                if ($y < $miny) { $miny = $y; }
                if ($y > $maxy) { $maxy = $y; }
            }
        }
    }
    if ($maxx < $minx || $maxy < $miny) {
        return $box;                       // tutto sfondo: si lascia com'e'
    }
    $m = 4;                                 // un filo di aria attorno
    $minx = max($x0, $minx - $m); $miny = max($y0, $miny - $m);
    $maxx = min($x0 + $w - 1, $maxx + $m); $maxy = min($y0 + $h - 1, $maxy + $m);
    return [$minx, $miny, $maxx - $minx + 1, $maxy - $miny + 1];
}

/**
 * Toglie la pergamena e lascia solo la figura.
 *
 * Non con un test sul colore — le tavole sono JPEG, e attorno a ogni tratto
 * scuro la compressione lascia un alone di pixel che col colore di fondo non
 * c'entrano piu' niente: un test sul colore li tiene, e la figura si porta
 * dietro una nuvola sporca.
 *
 * Si riempie invece DAI BORDI verso l'interno, con tolleranza larga: la
 * pergamena e' tutta attaccata al bordo del ritaglio, la figura e' un'isola in
 * mezzo. Quello che il riempimento non raggiunge resta, alone compreso quando
 * sta dentro la sagoma. Gli spazi chiusi — il campo di un emblema, le caselle
 * di una griglia — non vengono raggiunti e restano pieni, che e' giusto.
 *
 * All'ultimo passaggio i pixel di confine prendono un'alfa intermedia: senza,
 * il contorno viene seghettato.
 */
function scavaSfondo(\GdImage $im, array $sfondo, bool $ancheInterni = false): void
{
    $W = imagesx($im);
    $H = imagesy($im);
    $tolleranza = 96;      // somma delle differenze RGB: larga, siamo su JPEG

    $vicino = static function (int $c) use ($sfondo, $tolleranza): bool {
        return abs((($c >> 16) & 255) - $sfondo[0])
             + abs((($c >> 8) & 255) - $sfondo[1])
             + abs(($c & 255) - $sfondo[2]) <= $tolleranza;
    };

    /** @var array<int,bool> $fuori mappa dei pixel raggiunti dal riempimento */
    $fuori = [];
    $coda = [];
    for ($x = 0; $x < $W; $x++) {
        $coda[] = [$x, 0];
        $coda[] = [$x, $H - 1];
    }
    for ($y = 0; $y < $H; $y++) {
        $coda[] = [0, $y];
        $coda[] = [$W - 1, $y];
    }

    while ($coda !== []) {
        [$x, $y] = array_pop($coda);
        if ($x < 0 || $y < 0 || $x >= $W || $y >= $H) {
            continue;
        }
        $k = $y * $W + $x;
        if (isset($fuori[$k])) {
            continue;
        }
        if (!$vicino(imagecolorat($im, $x, $y))) {
            continue;
        }
        $fuori[$k] = true;
        $coda[] = [$x + 1, $y];
        $coda[] = [$x - 1, $y];
        $coda[] = [$x, $y + 1];
        $coda[] = [$x, $y - 1];
    }

    // Secondo passaggio, SOLO SE RICHIESTO: le sacche di pergamena chiuse dentro
    // la figura.
    //
    // Serve perche' alcuni di questi disegni usano lo sfondo del foglio come
    // parte del disegno: fra il ponte e la murata del sommergibile non c'e'
    // vernice chiara, c'e' la pergamena che si vede attraverso. Sul foglio
    // funziona; su una plancia d'acciaio diventa un cuneo color crema in mezzo
    // allo scafo.
    //
    // Non e' il comportamento di serie, e non deve esserlo: le navi dipinte di
    // chiaro — la Sunderland, la Liberty, la corvetta — hanno grigi vicini alla
    // pergamena, e scavarle le riempie di buchi. Si accende una figura alla
    // volta, nel piano dei ritagli, dopo aver guardato il risultato.
    //
    // Doppia cautela: tolleranza stretta, e si buttano via solo le sacche
    // GRANDI. Una macchia di dieci pixel e' rumore del JPEG, non un pezzo di
    // cielo.
    if ($ancheInterni) {
        // Stessa tolleranza larga del riempimento dai bordi: il cuneo del ponte
        // e' pergamena ombreggiata, e con una soglia stretta non lo si prende.
        // A tenere il coltello per il manico e' il minimo di superficie.
        $stretta = 96;
        $minimo  = 60;      // pixel: sotto, la sacca si tiene
        $vicinoStretto = static function (int $c) use ($sfondo, $stretta): bool {
            return abs((($c >> 16) & 255) - $sfondo[0])
                 + abs((($c >> 8) & 255) - $sfondo[1])
                 + abs(($c & 255) - $sfondo[2]) <= $stretta;
        };
        $visti = [];
        for ($x = 0; $x < $W; $x++) {
            for ($y = 0; $y < $H; $y++) {
                $k = $y * $W + $x;
                if (isset($fuori[$k]) || isset($visti[$k]) || !$vicinoStretto(imagecolorat($im, $x, $y))) {
                    continue;
                }
                // Si raccoglie tutta la sacca PRIMA di decidere che farne.
                $sacca = [];
                $coda2 = [[$x, $y]];
                while ($coda2 !== []) {
                    [$sx, $sy] = array_pop($coda2);
                    if ($sx < 0 || $sy < 0 || $sx >= $W || $sy >= $H) {
                        continue;
                    }
                    $sk = $sy * $W + $sx;
                    if (isset($fuori[$sk]) || isset($visti[$sk]) || !$vicinoStretto(imagecolorat($im, $sx, $sy))) {
                        continue;
                    }
                    $visti[$sk] = true;
                    $sacca[] = $sk;
                    $coda2[] = [$sx + 1, $sy];
                    $coda2[] = [$sx - 1, $sy];
                    $coda2[] = [$sx, $sy + 1];
                    $coda2[] = [$sx, $sy - 1];
                }
                if (count($sacca) >= $minimo) {
                    foreach ($sacca as $sk) {
                        $fuori[$sk] = true;
                    }
                }
            }
        }
    }

    imagealphablending($im, false);
    imagesavealpha($im, true);
    for ($x = 0; $x < $W; $x++) {
        for ($y = 0; $y < $H; $y++) {
            $k = $y * $W + $x;
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            if (isset($fuori[$k])) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, 127));
                continue;
            }
            // Confine: se un vicino e' fuori, mezza trasparenza per ammorbidire.
            $bordo = isset($fuori[$k - 1]) || isset($fuori[$k + 1])
                  || isset($fuori[$k - $W]) || isset($fuori[$k + $W]);
            if ($bordo) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, 54));
            }
        }
    }
}

/**
 * Rovescia un disegno a inchiostro.
 *
 * I profili dei manuali di riconoscimento sono inchiostro nero su carta chiara:
 * ritagliati e messi su una plancia d'acciaio diventano una macchia nera
 * invisibile, oppure — peggio — restano attaccati al loro rettangolo di carta.
 *
 * Qui si fa il negativo: quanto piu' un pixel era scuro, tanto piu' resta
 * opaco, e il colore diventa un grigio chiaro. Quello che era carta sparisce da
 * solo. Il risultato e' una sagoma luminosa su fondo trasparente — che e'
 * esattamente come si stampano le tavole di silhouette su fondo scuro.
 */
function rovescia(\GdImage $im): void
{
    $W = imagesx($im);
    $H = imagesy($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    for ($x = 0; $x < $W; $x++) {
        for ($y = 0; $y < $H; $y++) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 127;
            if ($a >= 127) {
                continue;                       // gia' trasparente: si lascia
            }
            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;
            $luce = ($r * 0.3 + $g * 0.59 + $b * 0.11) / 255.0;
            // Carta (chiara) → trasparente. Inchiostro (scuro) → opaco.
            $alpha = (int) round(min(127, max(0, $luce * 140 - 6)));
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, 174, 185, 192, $alpha));
        }
    }
}

$registro = [];
$fatte = 0;
$saltate = 0;
$sorgenti = [];

foreach ($piano as $chiave => $v) {
    $file = (string) $v['sorgente'];
    if (!isset($sorgenti[$file])) {
        if (!is_readable($file)) {
            fwrite(STDERR, "Tavola non leggibile: {$file}\n");
            exit(1);
        }
        $im = str_ends_with(strtolower($file), '.png')
            ? imagecreatefrompng($file)
            : imagecreatefromjpeg($file);
        if ($im === false) {
            fwrite(STDERR, "Non riesco ad aprire: {$file}\n");
            exit(1);
        }
        $sorgenti[$file] = $im;
    }
    $im = $sorgenti[$file];
    $W = imagesx($im);
    $H = imagesy($im);

    // Il piano parla in frazioni della tavola, non in pixel: cosi' resta valido
    // anche se la tavola arriva a una risoluzione diversa.
    [$fx, $fy, $fw, $fh] = $v['box'];
    $box = [(int) round($fx * $W), (int) round($fy * $H), (int) round($fw * $W), (int) round($fh * $H)];
    $box = stringi($im, $box, coloreSfondo($im));

    $altezza = (int) ($v['altezza'] ?? 96);
    $scala = $altezza / max(1, $box[3]);
    $larghezza = max(1, (int) round($box[2] * $scala));

    $nome = $chiave . '.webp';
    printf("  %-28s %4dx%-4d → %3dx%-3d  %s\n", $chiave, $box[2], $box[3], $larghezza, $altezza, $nome);

    if (!$secco) {
        $out = imagecreatetruecolor($larghezza, $altezza);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, $box[0], $box[1], $larghezza, $altezza, $box[2], $box[3]);
        scavaSfondo($out, coloreSfondo($im), (bool) ($v['interni'] ?? false));
        if (!empty($v['negativo'])) {
            rovescia($out);
        }
        imagewebp($out, $uscita . '/' . $nome, 82);
        imagedestroy($out);
    }

    $registro[$chiave] = [
        'file'      => $nome,
        'soggetto'  => (string) $v['soggetto'],
        'lega'      => $v['lega'] ?? null,
        // 'ricostruzione' per i disegni generati, 'documentale' per i profili
        // presi da un manuale di riconoscimento. La differenza cambia quello
        // che il gioco puo' dire della figura, quindi non e' un dettaglio.
        'fonte'     => (string) ($v['fonte'] ?? 'ricostruzione'),
        'documento' => $v['documento'] ?? null,
        'nota'      => (string) ($v['nota'] ?? ''),
    ];
    $fatte++;
}

foreach ($sorgenti as $im) {
    imagedestroy($im);
}

if ($secco) {
    printf("\nProva a vuoto: %d figure, niente scritto.\n", $fatte);
    exit(0);
}

$testata = <<<'PHP'
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

return
PHP;

file_put_contents(
    $progetto . '/db/seed/segnaposto.php',
    $testata . ' ' . var_export($registro, true) . ";\n"
);

printf("\nScritte %d figure in %s\n", $fatte, $uscita);
printf("Registro: db/seed/segnaposto.php\n");
