<div class="pannello pannello--stretto">
<?php if (!empty($ok)): ?>
  <span class="targhetta">Indirizzo confermato</span>
  <h1>Arruolamento accettato</h1>
  <p class="sommario">
    <?= !empty($user['username']) ? e($user['username']) . ', l' : 'L' ?>'account è attivo.
    Puoi entrare e presentarti in flottiglia.
  </p>
  <div class="azioni"><a class="bottone" href="<?= e(url('/accesso')) ?>">Entra</a></div>
<?php else: ?>
  <span class="targhetta">Verifica non riuscita</span>
  <h1>Collegamento non valido</h1>
  <p class="sommario"><?= e($error ?? 'Il collegamento non e\' utilizzabile.') ?></p>
  <div class="azioni">
    <a class="bottone" href="<?= e(url('/accesso')) ?>">Pagina di accesso</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/arruolamento')) ?>">Arruolamento</a>
  </div>
<?php endif; ?>
</div>
