<?php
/** @var string $attiva */
$attiva = $attiva ?? '';

// Centrale, carteggio e ascolto sono stazioni di bordo: in porto non c'e'
// niente da tenere d'occhio, e infatti il controller rimanda alla flottiglia.
// Finche' i collegamenti restavano accesi lo faceva in silenzio, e sembrava
// che il gioco fosse rotto. In porto si mostrano spenti, e dicono perche'.
$inMare = !empty($GLOBALS['__in_mare']);
$soloInMare = static function (string $rotta, string $nome, string $chiave) use ($attiva, $inMare): string {
    if ($inMare) {
        return sprintf('<a href="%s" class="%s">%s</a>',
            e(url($rotta)), $attiva === $chiave ? 'attivo' : '', e($nome));
    }
    return sprintf('<span class="spento" title="%s">%s</span>',
        e('Si apre quando il battello e\' in mare.'), e($nome));
};
?>
<nav class="nav-plancia">
  <?= $soloInMare('/zentrale', 'Zentrale', 'zentrale') ?>
  <?= $soloInMare('/carta', 'Carteggio', 'carta') ?>
  <?php if (!empty($GLOBALS['__incontro'])): ?>
    <a href="<?= e(url('/attacco')) ?>" class="<?= $attiva === 'attacco' ? 'attivo' : '' ?>" style="border-color:var(--rosso);color:var(--rosso)">Attacco</a>
  <?php endif; ?>
  <a href="<?= e(url('/radio')) ?>" class="<?= $attiva === 'radio' ? 'attivo' : '' ?>">Radio</a>
  <a href="<?= e(url('/bdu')) ?>" class="<?= $attiva === 'bdu' ? 'attivo' : '' ?>">BdU</a>
  <?= $soloInMare('/contatti', 'Ascolto', 'contatti') ?>
  <a href="<?= e(url('/battello')) ?>" class="<?= $attiva === 'battello' ? 'attivo' : '' ?>">Battello</a>
  <a href="<?= e(url('/equipaggio')) ?>" class="<?= $attiva === 'equipaggio' ? 'attivo' : '' ?>">Equipaggio</a>
  <a href="<?= e(url('/ktb')) ?>" class="<?= $attiva === 'ktb' ? 'attivo' : '' ?>">Giornale di guerra</a>
  <a href="<?= e(url('/base')) ?>" class="<?= $attiva === 'base' ? 'attivo' : '' ?>">Flottiglia</a>
  <a href="<?= e(url('/comandante')) ?>" class="<?= $attiva === 'comandante' ? 'attivo' : '' ?>">Fascicolo</a>
  <a href="<?= e(url('/comandante/profilo')) ?>" class="<?= $attiva === 'profilo' ? 'attivo' : '' ?>">Profilo</a>
</nav>
