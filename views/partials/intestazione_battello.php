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
  <span class="numero"><?= e($boat['uboat_number']) ?></span>
  <span class="tipo"><?= e($type['name']) ?><?= $boat['soprannome'] ?? '' ?> · <?= e($boat['flotilla']) ?></span>
  <span class="ora" data-campo="ora"><?= e($clock->formatDiario($now)) ?></span>
</div>
