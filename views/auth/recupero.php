<?php /** @var string $token @var bool $valido @var string|null $errore */ ?>
<div class="pannello pannello--stretto">
  <span class="targhetta">Ufficio arruolamenti</span>
  <h1>Password nuova</h1>

  <?php if (!$valido): ?>
    <div class="avviso avviso--errore"><?= e((string) ($errore ?? 'Collegamento non valido.')) ?></div>
    <p class="sommario">
      I collegamenti valgono poche ore e una volta sola. Se questo è scaduto o è già stato
      usato, chiedine un altro.
    </p>
    <div class="azioni">
      <a class="bottone" href="<?= e(url('/recupero-richiesta')) ?>">Chiedine uno nuovo</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/accesso')) ?>">Torna all'accesso</a>
    </div>
  <?php else: ?>
    <p class="sommario">
      Scegli la password nuova. Appena la salvi, <b>tutte le sessioni già aperte cadono</b>:
      se qualcuno era entrato con la vecchia, si ritrova fuori.
    </p>

    <form method="post" action="<?= e(url('/recupero')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="campo">
        <label for="password">Password nuova</label>
        <input type="password" id="password" name="password" required autofocus
               minlength="<?= e((string) \App\Auth\Auth::minPasswordLength()) ?>">
        <p class="aiuto">Almeno <?= e((string) \App\Auth\Auth::minPasswordLength()) ?> caratteri.</p>
      </div>
      <div class="campo">
        <label for="password_confirm">Ripetila</label>
        <input type="password" id="password_confirm" name="password_confirm" required>
      </div>
      <div class="azioni"><button type="submit">Salva la password</button></div>
    </form>
  <?php endif; ?>
</div>
