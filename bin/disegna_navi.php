<?php

declare(strict_types=1);

/**
 * Dalle dimensioni documentate alle sagome delle scorte.
 *
 *   php bin/disegna_navi.php
 *
 * Nessun manuale di riconoscimento alleato in pubblico dominio porta i profili
 * delle scorte: FM 30-50 si ferma agli incrociatori, ONI 204 e' naviglio di
 * superficie tedesco, ONI 220-M classifica gli U-Boot per tonnellaggio. Le
 * misure pero' sono pubblicate — lunghezza, baglio, dislocamento, numero e
 * posizione di fumaioli e pezzi — e da quelle la sagoma si costruisce.
 *
 * Il dato sta in db/seed/navi_dimensioni.php, questo e' solo il pennello: si
 * cambiano i numeri e il disegno cambia. Cio' che e' documentato e cio' che e'
 * ricostruito e' scritto riga per riga nel file dei dati.
 *
 * TUTTE LE SAGOME CONDIVIDONO LA SCALA. Un'unita' del disegno e' un metro, e
 * l'altezza del riquadro e' la stessa per tutte: messe una sotto l'altra alla
 * stessa altezza in pixel, le proporzioni fra una classe e l'altra sono quelle
 * vere. Un four-piper e' lungo il doppio di una corvetta perche' lo era.
 */

$progetto = require __DIR__ . '/_bootstrap.php';

const ALTEZZA_RIQUADRO = 22.0;      // metri: ci sta l'albero piu' alto
const LINEA_ACQUA      = 20.0;      // a che quota del riquadro sta il mare

$dati = require $progetto . '/db/seed/navi_dimensioni.php';
$uscita = $progetto . '/assets/img/segnaposto';

/** y del disegno per una quota in metri sul livello dell'acqua. */
function q(float $metriSulMare): float
{
    return round(LINEA_ACQUA - $metriSulMare, 2);
}

function n(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/**
 * Lo scafo.
 *
 * Con l'insellatura: il ponte non e' una riga dritta, sale verso prua e un po'
 * verso poppa. E' cio' che distingue una nave da una chiatta, e a colpo d'occhio
 * si vede anche in una sagoma alta trenta pixel.
 */
function scafo(float $L, float $bl, string $poppa): string
{
    $prua = $L * 0.045;                    // slancio di prua
    $ya   = q(0.0);                        // linea d'acqua
    $yPrua  = q($bl * 1.35);               // il ponte a prua e' piu' alto
    $yMezzo = q($bl * 0.92);               // il punto piu' basso, verso il mezzo
    $yPoppa = q($bl * 1.05);               // e risale un poco a poppa

    $p = 'M0 ' . n($ya)
       . ' L' . n($prua) . ' ' . n($yPrua)
       . ' Q' . n($L * 0.33) . ' ' . n($yMezzo) . ' ' . n($L * 0.62) . ' ' . n($yMezzo)
       . ' Q' . n($L * 0.85) . ' ' . n($yMezzo) . ' ' . n($L * 0.97) . ' ' . n($yPoppa);
    if ($poppa === 'incrociatore') {
        $p .= ' Q' . n($L) . ' ' . n($yPoppa) . ' ' . n($L * 0.965) . ' ' . n($ya);
    } else {
        $p .= ' L' . n($L) . ' ' . n($yPoppa + ($ya - $yPoppa) * 0.5)
            . ' L' . n($L * 0.985) . ' ' . n($ya);
    }
    return $p . ' Z';
}

$fatte = [];

foreach ($dati as $chiave => $v) {
    $L  = (float) $v['lunghezza'];
    $bl = (float) $v['bordo_libero'];
    // Il ponte a mezzanave sta un po' sotto la quota nominale per via
    // dell'insellatura: le sovrastrutture poggiano li', non a mezz'aria.
    $bl = $bl * 0.94;
    $pz = [];

    // --- scafo
    $pz[] = '<path d="' . scafo($L, $bl, (string) $v['poppa']) . '" fill="currentColor"/>';

    // --- castello rialzato
    $fino = (float) ($v['castello']['fino'] ?? 0);
    if ($fino > 0.0) {
        $alt = (float) $v['castello']['alt'];
        $pz[] = sprintf('<path d="M%s %s L%s %s L%s %s L%s %s Z" fill="currentColor"/>',
            n($L * 0.045), n(q($bl + $alt)), n($L * $fino), n(q($bl + $alt)),
            n($L * $fino), n(q($bl)), n($L * 0.045), n(q($bl)));
    }

    // --- plancia e tuga
    $da = (float) $v['plancia']['da'];
    $a  = (float) $v['plancia']['a'];
    $ap = (float) $v['plancia']['alt'];
    $base = $bl + ($fino > 0 && $da < $fino ? (float) $v['castello']['alt'] : 0.0);
    $pz[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="currentColor"/>',
        n($L * $da), n(q($base + $ap)), n($L * ($a - $da)), n($ap));
    // timoneria: un gradino piu' alto, stretto
    $pz[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="currentColor"/>',
        n($L * ($da + 0.01)), n(q($base + $ap + 1.8)), n($L * ($a - $da) * 0.55), '1.8');

    // --- fumaioli
    foreach ($v['fumaioli'] as $f) {
        $x = $L * (float) $f['a'];
        $w = (float) $f['largo'];
        $h = (float) $f['alt'];
        $pz[] = sprintf('<path d="M%s %s L%s %s L%s %s L%s %s Z" fill="currentColor"/>',
            n($x - $w / 2 - 0.25), n(q($bl + $h)), n($x + $w / 2 - 0.15), n(q($bl + $h)),
            n($x + $w / 2 + 0.35), n(q($bl)), n($x - $w / 2 - 0.35), n(q($bl)));
    }

    // --- alberi, con pennone
    foreach ($v['alberi'] as $m) {
        $x = $L * (float) $m['a'];
        $h = (float) $m['alt'];
        $pz[] = sprintf('<path d="M%s %s L%s %s" stroke="currentColor" stroke-width="0.55"/>',
            n($x), n(q($bl)), n($x), n(q($bl + $h)));
        $pz[] = sprintf('<path d="M%s %s L%s %s" stroke="currentColor" stroke-width="0.45"/>',
            n($x - $h * 0.16), n(q($bl + $h * 0.72)), n($x + $h * 0.16), n(q($bl + $h * 0.72)));
    }

    // --- pezzi
    foreach ($v['pezzi'] as $g) {
        $x = $L * (float) $g['a'];
        $suCastello = (string) $g['su'] === 'castello' && $fino > 0.0;
        $base2 = $bl + ($suCastello ? (float) $v['castello']['alt'] : 0.0);
        $pz[] = sprintf('<rect x="%s" y="%s" width="2.6" height="1.5" fill="currentColor"/>',
            n($x - 1.3), n(q($base2 + 1.5)));
        $pz[] = sprintf('<path d="M%s %s L%s %s" stroke="currentColor" stroke-width="0.4"/>',
            n($x + 0.6), n(q($base2 + 1.15)), n($x + 4.2), n(q($base2 + 1.55)));
    }

    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img" aria-label="%s">'
        . '<title>%s</title><g color="#aeb9c0">%s</g></svg>',
        n($L), n(ALTEZZA_RIQUADRO), htmlspecialchars((string) $v['nome'], ENT_QUOTES),
        htmlspecialchars((string) $v['nome'], ENT_QUOTES), implode('', $pz)
    );

    file_put_contents($uscita . '/' . $chiave . '.svg', $svg);
    $fatte[$chiave] = $v;
    printf("  %-20s %5.1f m  →  %s.svg  (%d byte)\n", $chiave, $L, $chiave, strlen($svg));
}

// --- il pezzo di registro che appartiene a queste sagome --------------------

$righe = [];
foreach ($fatte as $chiave => $v) {
    $righe[$chiave] = [
        'file'      => $chiave . '.svg',
        'soggetto'  => 'Sagoma in scala: ' . $v['nome'],
        'lega'      => $chiave,
        'fonte'     => 'misurata',
        'documento' => $v['fonte'],
        'nota'      => 'Costruita sulle misure documentate (' . n((float) $v['lunghezza']) . ' m fuori '
                     . 'tutto, ' . n((float) $v['baglio']) . ' m di baglio, ' . (int) $v['dislocamento']
                     . ' tonnellate). Le PROPORZIONI delle sovrastrutture sono ricostruite dalla '
                     . 'descrizione del profilo, non prese da un piano di costruzione. '
                     . $v['profilo'],
    ];
}

$testata = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Registro delle sagome disegnate dalle misure.
 *
 * FILE GENERATO da bin/disegna_navi.php: non si corregge a mano, si rigenera.
 * Il dato sta in db/seed/navi_dimensioni.php.
 *
 * Queste sagome non sono ne' ricostruzioni libere ne' profili documentali: sono
 * COSTRUITE sulle misure pubblicate. La fonte dichiarata e' 'misurata', e ogni
 * voce cita da dove vengono i numeri. Condividono tutte la stessa scala: un
 * metro e' un'unita' di disegno.
 */

return
PHP;

file_put_contents(
    $progetto . '/db/seed/segnaposto_scorte.php',
    $testata . ' ' . var_export($righe, true) . ";\n"
);

printf("\nScritte %d sagome e il loro registro (db/seed/segnaposto_scorte.php)\n", count($fatte));
