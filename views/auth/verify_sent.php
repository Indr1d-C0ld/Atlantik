<div class="pannello pannello--stretto">
  <span class="targhetta">Domanda registrata</span>
  <h1>Controlla la posta</h1>
  <p class="sommario">
    Abbiamo inviato il collegamento di conferma<?= !empty($email) ? ' a <b>' . e($email) . '</b>' : '' ?>.
    Aprilo per attivare l'account: fino ad allora l'accesso resta chiuso.
  </p>
  <p class="aiuto">Se non arriva entro qualche minuto, controlla la posta indesiderata.
     Dalla pagina di accesso puoi chiedere un nuovo invio.</p>
  <div class="azioni"><a class="bottone" href="<?= e(url('/accesso')) ?>">Vai all'accesso</a></div>
</div>
