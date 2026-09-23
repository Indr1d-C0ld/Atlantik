<?php
/** @var array $boat @var array $type @var \App\Sim\Clock $clock @var int $now */
$emblema = \App\Game\Emblema::di($boat);
?>
<div class="intestazione-battello">
  <?php if ($emblema !== null): ?>
    <img class="emblema-torretta" src="<?= e(asset($emblema['url'])) ?>" width="34" height="34"
         alt="Emblema di torretta: <?= e($emblema['nome']) ?>"
         data-emblema="<?= e($emblema['nome']) ?>"
         data-emblema-motto="<?= e((string) ($emblema['motto'] ?? '')) ?>">
  <?php endif; ?>
  <?php /* Il numero del battello e' il titolo della pagina: per chi naviga con
     un lettore di schermo, un h1 e' il punto da cui si comincia. Era uno span,
     e le pagine di gioco non avevano nessun titolo di primo livello. */ ?>
  <h1 class="numero"><?= e($boat['uboat_number']) ?></h1>
  <?php /* Il soprannome e' del TIPO, non del battello: fino al 23/09/2026 qui si
     leggeva $boat['soprannome'], colonna che boats non ha, e il «cavallo da tiro»
     del VII C o la «Milchkuh» del XIV non comparivano mai. In piu' usciva senza
     escape. Adesso come nella base di flottiglia e nel cantiere. */ ?>
  <span class="tipo"><?= e($type['name']) ?><?= !empty($type['soprannome']) ? ' — ' . e($type['soprannome']) : '' ?> · <?= e($boat['flotilla']) ?></span>
  <span class="ora" data-campo="ora"><?= e($clock->formatDiario($now)) ?></span>
</div>
