<?php

declare(strict_types=1);

/**
 * Prove del registro delle figure di segnaposto.
 *
 *   php tests/test_segnaposto.php
 *
 * La regola che queste prove fanno rispettare e' una sola: nessuna figura entra
 * nel gioco senza una riga nel registro che dica cos'e' e da dove viene. E'
 * l'unica difesa contro il modo in cui queste cose vanno di solito a finire —
 * un'immagine bella messa in pagina "per adesso", e sei mesi dopo nessuno sa
 * piu' se quella sagoma e' documentata o inventata.
 *
 * Nessun database, nessuna e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Game\Segnaposto;

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

$registro = Segnaposto::registro();
$suDisco  = Segnaposto::fileSuDisco();

titolo('Registro e disco si corrispondono');

$dichiarati = [];
foreach ($registro as $v) {
    $dichiarati[] = (string) ($v['file'] ?? '');
}
sort($dichiarati);

$orfaneSuDisco = array_values(array_diff($suDisco, $dichiarati));
$mancanti      = array_values(array_diff($dichiarati, $suDisco));

ok('nessuna figura sul disco senza una riga nel registro', $orfaneSuDisco === [],
    $orfaneSuDisco === [] ? count($suDisco) . ' file' : implode(', ', array_slice($orfaneSuDisco, 0, 5)));
ok('nessuna riga del registro senza la sua figura', $mancanti === [],
    $mancanti === [] ? count($dichiarati) . ' voci' : implode(', ', array_slice($mancanti, 0, 5)));

titolo('Ogni figura dice cos\'e\' e da dove viene');

$senzaSoggetto = 0;
$senzaFonte    = 0;
$fonteSbagliata = [];
$senzaDocumento = [];
$legheDoppie   = [];
$viste         = [];
foreach ($registro as $chiave => $v) {
    if (trim((string) ($v['soggetto'] ?? '')) === '') {
        $senzaSoggetto++;
    }
    $fonte = (string) ($v['fonte'] ?? '');
    if ($fonte === '') {
        $senzaFonte++;
    } elseif (!in_array($fonte, ['ricostruzione', 'documentale', 'misurata'], true)) {
        $fonteSbagliata[] = $chiave . '=' . $fonte;
    }
    // Una figura che si dichiara documentale deve dire QUALE documento. Il
    // giorno in cui bastasse la parola, 'documentale' non varrebbe niente.
    if (in_array($fonte, ['documentale', 'misurata'], true)
        && trim((string) ($v['documento'] ?? '')) === '') {
        $senzaDocumento[] = $chiave;
    }
    $lega = (string) ($v['lega'] ?? '');
    if ($lega !== '') {
        if (isset($viste[$lega])) {
            $legheDoppie[] = $lega;
        }
        $viste[$lega] = true;
    }
}

ok('ogni figura dichiara il soggetto', $senzaSoggetto === 0, $senzaSoggetto . ' senza');
ok('ogni figura dichiara la fonte', $senzaFonte === 0, $senzaFonte . ' senza');
ok('la fonte e\' fra quelle previste', $fonteSbagliata === [], implode(', ', $fonteSbagliata));
ok('chi non e\' ricostruzione cita la fonte dei dati', $senzaDocumento === [], implode(', ', $senzaDocumento));
ok('nessuna entita\' di gioco ha due figure', $legheDoppie === [], implode(', ', $legheDoppie));

titolo('Le figure sono leggere e servibili');

$pesante = [];
$formato = [];
foreach ($suDisco as $f) {
    $percorso = dirname(__DIR__) . '/assets/img/segnaposto/' . $f;
    if (filesize($percorso) > 60 * 1024) {
        $pesante[] = $f . ' (' . round(filesize($percorso) / 1024) . ' KB)';
    }
    if (!preg_match('/\.(webp|svg)$/i', $f)) {
        $formato[] = $f;
    }
}
ok('nessuna figura sopra i 60 KB', $pesante === [], implode(', ', array_slice($pesante, 0, 4)));
ok('tutte in WebP o SVG', $formato === [], implode(', ', array_slice($formato, 0, 4)));

if ($registro === []) {
    echo "\n  \033[0;90mRegistro vuoto: il gioco non mostra ancora nessuna figura.\033[0m\n";
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
