<div class="pannello pannello--stretto">
  <span class="targhetta">Avaria</span>
  <h1>Servizio non disponibile</h1>
  <p class="sommario">La base non risponde: il collegamento con l'archivio è interrotto.
     Se sei l'amministratore, controlla il database e le migrazioni.</p>
  <?php if (!empty($debug) && !empty($detail)): ?>
    <pre style="white-space:pre-wrap;font-size:.8rem;color:var(--testo-3)"><?= e($detail) ?></pre>
  <?php endif; ?>
</div>
