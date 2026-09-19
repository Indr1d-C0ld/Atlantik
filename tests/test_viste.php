<?php

declare(strict_types=1);

/**
 * La struttura delle viste.
 *
 *   php tests/test_viste.php
 *
 * Non tocca il database e non apre un browser: legge i modelli di pagina e
 * controlla come sono annidati i tag. Nasce da un guasto del 18/09/2026 nella
 * creazione del comandante, dove un </div> chiudeva il pannello mentre il
 * <form> era ancora aperto:
 *
 *     <div class="pannello">
 *       <form ...>
 *         ...
 *     </div>              <-- chiude il pannello, e il browser SGANCIA il form
 *     <div class="pannello">
 *         ... ritratto, fotografia, casella d'epoca ...
 *       </form>           <-- tag di chiusura ormai spaiato
 *     </div>
 *
 * Il PHP non protesta, la pagina si vede bene, e il conto dei <div> torna
 * persino a zero. Ma nel DOM il form resta con quattro figli: tutto quello che
 * viene dopo e' fuori, la casella d'epoca non arriva a chi la ascolta e il
 * ritratto scelto in galleria non viene nemmeno spedito.
 *
 * Il segnale e' il saldo dei <div> che scende sotto zero prima del </form>.
 */

$falliti = 0;

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

$radice = dirname(__DIR__) . '/views';
$viste  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));

echo "\n\033[1mAnnidamento dei form nelle viste\033[0m\n";

$esaminate = 0;
$formTrovati = 0;
$guasti = [];

foreach ($viste as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $esaminate++;
    $testo = (string) file_get_contents($file->getPathname());
    $breve = substr($file->getPathname(), strlen(dirname($radice)) + 1);

    $da = 0;
    while (($apre = strpos($testo, '<form', $da)) !== false) {
        $chiude = strpos($testo, '</form>', $apre);
        if ($chiude === false) {
            $guasti[] = "{$breve}: <form> senza </form>";
            break;
        }
        $formTrovati++;
        $dentro = substr($testo, $apre, $chiude - $apre);
        preg_match_all('#<div\b|</div>#', $dentro, $tag);

        $saldo = 0;
        $minimo = 0;
        foreach ($tag[0] as $t) {
            $saldo += ($t === '</div>') ? -1 : 1;
            $minimo = min($minimo, $saldo);
        }
        if ($minimo < 0) {
            $guasti[] = "{$breve}: un </div> chiude un antenato del form (saldo {$minimo})";
        } elseif ($saldo !== 0) {
            $guasti[] = "{$breve}: dentro il form restano {$saldo} <div> aperti";
        }
        $da = $chiude + 7;
    }
}

ok(
    'nessun form sganciato da un </div> di troppo',
    $guasti === [],
    $guasti === [] ? sprintf('%d viste, %d form', $esaminate, $formTrovati) : implode(' | ', $guasti)
);

// Il caso che ha fatto nascere la prova, nominato: la pagina di creazione deve
// tenere ritratto, fotografia e casella d'epoca dentro il suo form.
$creazione = $radice . '/carriera/creazione.php';
$testo = (string) file_get_contents($creazione);
$apre = strpos($testo, '<form');
$chiude = strpos($testo, '</form>');
$dentro = ($apre !== false && $chiude !== false) ? substr($testo, $apre, $chiude - $apre) : '';

foreach ([
    'il campo del nome'            => 'name="nome"',
    'la scelta del ritratto'       => 'scelta_ritratto',
    'il caricamento della foto'    => 'data-ritaglio',
    "la casella d'epoca"           => 'name="invecchia"',
    "il pulsante d'invio"          => 'type="submit"',
] as $cosa => $ago) {
    ok("creazione del comandante: {$cosa} sta dentro il form", str_contains($dentro, $ago));
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
