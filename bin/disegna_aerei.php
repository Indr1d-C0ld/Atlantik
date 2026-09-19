<?php

declare(strict_types=1);

/**
 * Dalle dimensioni documentate alle sagome dei velivoli.
 *
 *   php bin/disegna_aerei.php
 *
 * Vista in PIANTA, come sui manuali di riconoscimento aereo: e' l'unica che due
 * numeri documentati — apertura alare e lunghezza — definiscano quasi per
 * intero. Il dato sta in db/seed/aerei_dimensioni.php.
 *
 * Come per le navi, tutte le sagome condividono la scala: un'unita' di disegno
 * e' un metro. Un Wellington e' largo il doppio di uno Swordfish perche' lo era.
 */

$progetto = require __DIR__ . '/_bootstrap.php';

const RIQUADRO = 28.0;          // metri: ci sta l'apertura piu' grande
const INCHIOSTRO = '#aeb9c0';

$dati = require $progetto . '/db/seed/aerei_dimensioni.php';
$uscita = $progetto . '/assets/img/segnaposto';

function n(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

$righe = [];

foreach ($dati as $chiave => $v) {
    $ap = (float) $v['apertura'];
    $lu = (float) $v['lunghezza'];
    $motori = (int) $v['motori'];
    $biplano = (int) $v['ali'] > 1;

    $cx = RIQUADRO / 2;                       // asse della fusoliera
    $cy = RIQUADRO / 2;
    $prua = $cy - $lu * 0.42;                 // il muso sta un po' avanti al centro
    $coda = $cy + $lu * 0.58;
    $semi = $ap / 2;
    $larghezzaFusoliera = max(0.9, $lu * 0.085);

    $p = [];

    // --- ala principale: trapezio, bordo d'entrata dritto, d'uscita rastremato
    $yAla = $prua + $lu * 0.30;
    $corda = $lu * 0.23;
    $p[] = sprintf(
        '<path d="M%s %s L%s %s L%s %s L%s %s Z" fill="currentColor"/>',
        n($cx - $semi), n($yAla),
        n($cx + $semi), n($yAla),
        n($cx + $semi * 0.92), n($yAla + $corda * 0.55),
        n($cx - $semi * 0.92), n($yAla + $corda * 0.55)
    );
    // il biplano ha la seconda ala sfalsata: in pianta si vede il bordo sporgere
    if ($biplano) {
        $p[] = sprintf(
            '<path d="M%s %s L%s %s L%s %s L%s %s Z" fill="currentColor" opacity=".55"/>',
            n($cx - $semi * 0.96), n($yAla + $corda * 0.62),
            n($cx + $semi * 0.96), n($yAla + $corda * 0.62),
            n($cx + $semi * 0.88), n($yAla + $corda * 1.05),
            n($cx - $semi * 0.88), n($yAla + $corda * 1.05)
        );
    }

    // --- fusoliera: muso arrotondato, coda affusolata
    $p[] = sprintf(
        '<path d="M%s %s Q%s %s %s %s L%s %s Q%s %s %s %s Z" fill="currentColor"/>',
        n($cx - $larghezzaFusoliera / 2), n($prua + $lu * 0.10),
        n($cx), n($prua - $lu * 0.04), n($cx + $larghezzaFusoliera / 2), n($prua + $lu * 0.10),
        n($cx + $larghezzaFusoliera * 0.22), n($coda),
        n($cx), n($coda + $lu * 0.03), n($cx - $larghezzaFusoliera * 0.22), n($coda)
    );

    // --- piani di coda
    $semiCoda = $ap * 0.30;
    $yCoda = $coda - $lu * 0.10;
    $p[] = sprintf(
        '<path d="M%s %s L%s %s L%s %s L%s %s Z" fill="currentColor"/>',
        n($cx - $semiCoda), n($yCoda),
        n($cx + $semiCoda), n($yCoda),
        n($cx + $semiCoda * 0.8), n($yCoda + $lu * 0.075),
        n($cx - $semiCoda * 0.8), n($yCoda + $lu * 0.075)
    );

    // --- motori ed eliche
    if ($motori === 1) {
        $p[] = sprintf('<ellipse cx="%s" cy="%s" rx="%s" ry="%s" fill="currentColor"/>',
            n($cx), n($prua + $lu * 0.06), n($larghezzaFusoliera * 0.62), n($lu * 0.05));
        $p[] = sprintf('<path d="M%s %s L%s %s" stroke="currentColor" stroke-width="0.5" opacity=".6"/>',
            n($cx - $ap * 0.17), n($prua), n($cx + $ap * 0.17), n($prua));
    } else {
        foreach ([-1, 1] as $lato) {
            $x = $cx + $lato * $semi * 0.34;
            $p[] = sprintf('<ellipse cx="%s" cy="%s" rx="%s" ry="%s" fill="currentColor"/>',
                n($x), n($yAla - $lu * 0.03), n($larghezzaFusoliera * 0.5), n($lu * 0.10));
            $p[] = sprintf('<path d="M%s %s L%s %s" stroke="currentColor" stroke-width="0.45" opacity=".6"/>',
                n($x - $ap * 0.10), n($yAla - $lu * 0.12), n($x + $ap * 0.10), n($yAla - $lu * 0.12));
        }
    }

    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img" aria-label="%s">'
        . '<title>%s</title><g color="%s">%s</g></svg>',
        n(RIQUADRO), n(RIQUADRO),
        htmlspecialchars((string) $v['nome'], ENT_QUOTES),
        htmlspecialchars((string) $v['nome'], ENT_QUOTES),
        INCHIOSTRO, implode('', $p)
    );

    file_put_contents($uscita . '/' . $chiave . '.svg', $svg);
    printf("  %-20s apertura %5.2f m  →  %s.svg  (%d byte)\n", $chiave, $ap, $chiave, strlen($svg));

    $righe[$chiave] = [
        'file'      => $chiave . '.svg',
        'soggetto'  => 'Sagoma in pianta: ' . $v['nome'],
        'lega'      => $chiave,
        'fonte'     => 'misurata',
        'documento' => $v['fonte'],
        'nota'      => 'Vista dall\'alto costruita su apertura alare (' . n($ap) . ' m) e lunghezza ('
                     . n($lu) . ' m), che sono documentate. La FORMA della pianta — profilo dell\'ala, '
                     . 'larghezza della fusoliera, posizione dei motori — e\' ricostruita. '
                     . (string) ($v['nota'] ?? ''),
    ];
}

$testata = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Registro delle sagome di velivolo disegnate dalle misure.
 *
 * FILE GENERATO da bin/disegna_aerei.php: non si corregge a mano, si rigenera.
 * Il dato sta in db/seed/aerei_dimensioni.php.
 */

return
PHP;

file_put_contents($progetto . '/db/seed/segnaposto_aerei.php', $testata . ' ' . var_export($righe, true) . ";\n");
printf("\nScritte %d sagome e il loro registro (db/seed/segnaposto_aerei.php)\n", count($righe));
