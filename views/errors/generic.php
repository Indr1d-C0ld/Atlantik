<div class="pannello pannello--stretto">
  <span class="targhetta">Errore <?= e($status ?? 500) ?></span>
  <h1><?= e($title ?? 'Errore') ?></h1>
  <p class="sommario"><?= e($message ?? 'Si e\' verificato un problema.') ?></p>
  <div class="azioni"><a class="bottone" href="<?= e(url('/')) ?>">Torna in plancia</a></div>
</div>
