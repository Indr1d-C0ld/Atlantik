<?php
/** @var array $user @var array $boat @var array|null $cmd @var list $messaggi @var string $flottiglia */
?>
<?= partial('nav_plancia', ['attiva' => 'base']) ?>

<div class="pannello">
  <span class="targhetta">Mensa ufficiali</span>
  <h1>Bacheca della <?= e($flottiglia) ?></h1>
  <p class="sommario">
    Qui si parla liberamente: è l'unico posto dove si può. In mare esiste solo la radio, con le sue regole
    e il suo prezzo. La mensa è quella della tua flottiglia: quello che si dice qui non si sente nelle
    altre basi — solo i comunicati del comando arrivano dappertutto.
  </p>

  <?php if ((string) $boat['state'] === 'base'): ?>
    <form method="post" action="<?= e(url('/bacheca')) ?>">
      <?= csrf_field() ?>
      <div class="campo">
        <label for="testo">Messaggio</label>
        <textarea id="testo" name="testo" rows="3" maxlength="1000"
          style="width:100%;padding:.6rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:1rem var(--sans)"></textarea>
      </div>
      <div class="azioni"><button type="submit">Affiggi</button></div>
    </form>
  <?php else: ?>
    <div class="avviso">Il battello è in mare: alla bacheca si scrive al rientro.</div>
  <?php endif; ?>
</div>

<div class="pannello">
  <?php if ($messaggi === []): ?>
    <p class="sommario">Bacheca vuota.</p>
  <?php else: ?>
    <ul class="ktb">
      <?php foreach ($messaggi as $m): ?>
        <?php
        // Un comunicato del comando non lo firma l'account di chi l'ha
        // scritto: lo firma il comando. L'amministratore non ha un comandante,
        // e la firma cadeva sul suo nome utente — un ordine del giorno del BdU
        // che risulta scritto da "admin".
        $comunicato = $m['commander_id'] === null && (string) $m['flottiglia'] === 'Comando';
        $firma = $m['comandante']
            ?? ((string) ($m['flottiglia'] ?? '') !== '' ? (string) $m['flottiglia'] : (string) $m['username']);
        ?>
        <li<?= $comunicato ? ' class="comunicato"' : '' ?>>
          <span class="ora"><?= e(fmt_dt($m['created_at'])) ?></span>
          <span class="quadrat"><?= e($firma) ?></span>
          <span class="testo"><?= e($m['testo']) ?></span>
          <?php if ((int) $m['user_id'] === (int) ($user['id'] ?? 0)): ?>
            <form method="post" action="<?= e(url('/bacheca/rimuovi')) ?>" class="togli-messaggio">
              <?= csrf_field() ?>
              <input type="hidden" name="messaggio" value="<?= e($m['id']) ?>">
              <button type="submit" title="Toglilo dalla bacheca">togli</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
