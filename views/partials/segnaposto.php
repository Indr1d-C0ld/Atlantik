<?php
/**
 * Una figura di segnaposto, con la sua dichiarazione attaccata.
 *
 * Non si mette mai al posto del nome: gli sta accanto. Chi non vede le
 * immagini, o le ha spente, legge esattamente le stesse informazioni.
 *
 * @var string $lega   chiave dell'entita' di gioco (class_key, akey, ...)
 * @var string $chiave chiave diretta della figura, alternativa a $lega
 * @var int    $h      altezza in pixel a schermo
 */
use App\Game\Segnaposto;

$fig = isset($chiave) ? Segnaposto::chiave((string) $chiave) : Segnaposto::per((string) ($lega ?? ''));
if ($fig === null) {
    return;   // nessuna figura per questa cosa: il nome scritto basta e avanza
}
$alt = $fig['soggetto'] . ' — ' . Segnaposto::dichiarazione($fig);
?>
<img class="segnaposto" src="<?= e(asset($fig['file'])) ?>" alt="<?= e($alt) ?>"
     title="<?= e($alt . ($fig['nota'] !== '' ? ' ' . $fig['nota'] : '')) ?>"
     height="<?= (int) ($h ?? 40) ?>" loading="lazy" decoding="async">
