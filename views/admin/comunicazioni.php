<?php /** @var list $utenti @var array $coda @var list $recenti @var list $bacheca */ ?>
<?= partial('nav_admin', ['attiva' => 'comunicazioni']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Comunicazioni</h1>

  <div class="quadranti">
    <div class="quadrante"><div class="etichetta">in coda</div><div class="valore"><?= e($coda['in_coda']) ?></div></div>
    <div class="quadrante"><div class="etichetta">inviate 24h</div><div class="valore"><?= e($coda['inviate_24h']) ?></div></div>
    <div class="quadrante"><div class="etichetta">tetto</div><div class="valore"><?= e($coda['tetto']) ?></div></div>
    <div class="quadrante"><div class="etichetta">rinunciate</div><div class="valore"><?= e($coda['rinunciate']) ?></div></div>
  </div>

  <form method="post" action="<?= e(url('/admin/comunicazioni')) ?>" style="margin-top:1.2rem">
    <?= csrf_field() ?>

    <div class="campo">
      <label for="canale">Canale</label>
      <select id="canale" name="canale" style="max-width:26rem">
        <option value="posta">Posta — esce dal gioco e arriva in una casella vera</option>
        <option value="bacheca">Mensa ufficiali — resta dentro il gioco, la leggono i comandanti</option>
      </select>
    </div>

    <div class="campo">
      <label for="destinatario">Destinatario (solo per la posta)</label>
      <select id="destinatario" name="destinatario" style="max-width:26rem">
        <option value="tutti">tutti gli account attivi (<?= e(count($utenti)) ?>)</option>
        <?php foreach ($utenti as $u): ?>
          <option value="<?= e($u['id']) ?>"><?= e($u['username']) ?> — <?= e($u['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo">
      <label for="oggetto">Oggetto (solo per la posta)</label>
      <input type="text" id="oggetto" name="oggetto" maxlength="200" style="max-width:26rem">
    </div>

    <div class="campo">
      <label for="testo">Testo</label>
      <textarea id="testo" name="testo" rows="8" maxlength="4000" required
        style="width:100%;max-width:46rem;padding:.6rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:.92rem var(--sans)"></textarea>
    </div>

    <div class="comandi"><button type="submit">Manda</button></div>
    <p class="aiuto">
      La posta si accoda soltanto: la manda il battito, qualcuno per volta, rispettando il tetto
      giornaliero del provider. Un invio a tutti non può bruciare la quota in un colpo solo.
    </p>
  </form>
</div>

<div class="pannello">
  <h2>Ultima posta</h2>
  <?php if ($recenti === []): ?>
    <p class="sommario">Niente in archivio.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Destinatario</th><th>Oggetto</th><th>Genere</th><th>Esito</th></tr>
      <?php foreach ($recenti as $m): ?>
        <tr>
          <td><?= e($m['destinatario']) ?></td>
          <td><?= e($m['oggetto']) ?></td>
          <td style="color:var(--testo-3)"><?= e($m['genere']) ?></td>
          <td><?= $m['inviato_at'] !== null ? 'inviata' : ($m['rinunciato_at'] !== null ? 'rinunciata' : 'in coda') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h2 style="margin-top:1.4rem">Mensa ufficiali</h2>
  <?php if ($bacheca === []): ?>
    <p class="sommario">Bacheca vuota.</p>
  <?php else: ?>
    <ul class="ktb">
      <?php foreach ($bacheca as $b): ?>
        <li>
          <span class="ora"><?= e(fmt_dt($b['created_at'])) ?></span>
          <span class="quadrat"><?= e($b['nome'] ?? $b['flottiglia']) ?></span>
          <span class="testo">
            <?= e($b['testo']) ?>
            <?php if ($b['nome'] !== null): ?>
              <i style="color:var(--testo-3);font-size:.72rem"> — <?= e($b['flottiglia']) ?></i>
            <?php endif; ?>
          </span>
          <form method="post" action="<?= e(url('/bacheca/rimuovi')) ?>" class="togli-messaggio">
            <?= csrf_field() ?>
            <input type="hidden" name="messaggio" value="<?= e($b['id']) ?>">
            <input type="hidden" name="dove" value="admin">
            <button type="submit" title="Toglilo dalla bacheca">togli</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
