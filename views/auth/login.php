<div class="pannello pannello--stretto">
  <span class="targhetta">Controllo accessi</span>
  <h1>Accesso</h1>

  <form method="post" action="<?= e(url('/accesso')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="login">Nome utente o e-mail</label>
      <input type="text" id="login" name="login" value="<?= e(old('login')) ?>" required autofocus>
    </div>
    <div class="campo">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
    </div>
    <div class="azioni">
      <button type="submit">Entra</button>
      <a class="bottone bottone--fantasma" href="<?= e(url('/arruolamento')) ?>">Arruolati</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/recupero-richiesta')) ?>">Password dimenticata</a>
    </div>
  </form>
</div>

<?php $needVerify = flash('need_verify'); if (is_string($needVerify) && $needVerify !== ''): ?>
<div class="pannello pannello--stretto">
  <h2>Conferma non ancora ricevuta?</h2>
  <p class="sommario">Possiamo inviarti di nuovo il collegamento di verifica.</p>
  <form method="post" action="<?= e(url('/rinvia-verifica')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="login" value="<?= e($needVerify) ?>">
    <div class="azioni"><button type="submit" class="bottone--fantasma">Invia di nuovo</button></div>
  </form>
</div>
<?php endif; ?>
