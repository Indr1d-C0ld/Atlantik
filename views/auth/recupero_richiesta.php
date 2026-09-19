<div class="pannello pannello--stretto">
  <span class="targhetta">Ufficio arruolamenti</span>
  <h1>Password dimenticata</h1>

  <p class="sommario">
    Scrivi l'indirizzo con cui ti sei iscritto: ti arriva un collegamento per sceglierne una
    nuova. Vale una volta sola e per poche ore.
  </p>

  <form method="post" action="<?= e(url('/recupero-richiesta')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="email">Indirizzo di posta</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" maxlength="190" required autofocus>
    </div>
    <div class="azioni">
      <button type="submit">Mandami il collegamento</button>
      <a class="bottone bottone--fantasma" href="<?= e(url('/accesso')) ?>">Torna all'accesso</a>
    </div>
  </form>

  <p class="aiuto">
    La risposta è la stessa che l'indirizzo risulti iscritto o no. Non è scortesia: dire
    «questo indirizzo non c'è» regalerebbe a chiunque un modo per sapere chi gioca.
  </p>
</div>
