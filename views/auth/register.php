<div class="pannello pannello--stretto">
  <span class="targhetta">Domanda di arruolamento</span>
  <h1>Arruolamento</h1>

  <?php
  // L'avviso che l'amministrazione scrive per chi si iscrive: c'erano la
  // chiave e la casella nel pannello, e non compariva da nessuna parte.
  $avvisoArruolamento = trim((string) \App\Core\GameConfig::get('app.registrazione_messaggio', ''));
  ?>
  <?php if ($avvisoArruolamento !== ''): ?>
    <div class="avviso"><?= e($avvisoArruolamento) ?></div>
  <?php endif; ?>

  <?php if (empty($open)): ?>
    <div class="avviso avviso--attenzione">Le domande di arruolamento sono momentaneamente chiuse.</div>
  <?php else: ?>
    <p class="sommario">Registra l'account. Riceverai per posta un collegamento di conferma:
       fino ad allora la domanda resta in sospeso.</p>

    <form method="post" action="<?= e(url('/arruolamento')) ?>" autocomplete="off">
      <?= csrf_field() ?>

      <div class="campo">
        <label for="username">Nome utente</label>
        <input type="text" id="username" name="username" value="<?= e(old('username')) ?>"
               maxlength="32" required autofocus>
        <p class="aiuto">Da 3 a 32 caratteri: lettere, cifre, spazi, apostrofo, punto, trattino,
           underscore &mdash; per esempio <i>Indrid Cold</i>. Il nome del comandante lo sceglierai a bordo.</p>
      </div>

      <div class="campo">
        <label for="email">Indirizzo e-mail</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" maxlength="190" required>
        <p class="aiuto">Serve solo per la conferma e per il recupero dell'accesso.</p>
      </div>

      <div class="campo">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="<?= e($minPassword ?? 10) ?>" required>
        <p class="aiuto">Almeno <?= e($minPassword ?? 10) ?> caratteri.</p>
      </div>

      <div class="campo">
        <label for="password_confirm">Conferma password</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="<?= e($minPassword ?? 10) ?>" required>
      </div>

      <div class="azioni">
        <button type="submit">Presenta domanda</button>
        <a class="bottone bottone--fantasma" href="<?= e(url('/accesso')) ?>">Accedi</a>
      </div>
    </form>
  <?php endif; ?>
</div>
