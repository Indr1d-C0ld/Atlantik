<?php
/** @var array $boat @var array $type @var list $messaggi @var list $fix @var array $kurz
 *  @var array|null $branco @var string $quadrat @var \App\Sim\Clock $clock @var int $now */
$inSuperficie = (string) $boat['mode'] === 'superficie';
?>
<?= partial('nav_plancia', ['attiva' => 'radio']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="pannello">
  <span class="targhetta">Funkraum</span>
  <h1>Radio</h1>
  <p class="sommario">
    Ricevere non costa nulla: gli ordini arrivano sulle onde lunghissime e si prendono anche a venti metri di quota.
    <b>Trasmettere è un'altra cosa.</b> Serve l'antenna fuori, e per tutto il tempo dell'emissione ogni scorta con
    l'apparato radiogoniometrico prende un rilevamento. Due rilevamenti fanno un punto — e su quel punto arriva qualcuno.
    I segnali brevi esistono apposta: venti secondi invece di cinque minuti.
  </p>

  <?php if (!$inSuperficie): ?>
    <div class="avviso avviso--attenzione">Il battello è in immersione: per trasmettere bisogna emergere.</div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/radio/trasmetti')) ?>">
    <?= csrf_field() ?>
    <h2>Segnali brevi (Kurzsignale)</h2>
    <div class="comandi">
      <?php foreach ($kurz as $k => $v): ?>
        <button type="submit" name="kurz" value="<?= e($k) ?>" <?= $inSuperficie ? '' : 'disabled' ?>>
          <?= e($v['testo']) ?> <span style="opacity:.7">(<?= e($v['durata']) ?> s)</span>
        </button>
      <?php endforeach; ?>
    </div>
  </form>

  <form method="post" action="<?= e(url('/radio/trasmetti')) ?>" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <h2>Messaggio esteso</h2>
    <div class="campo">
      <label for="testo">Testo (quadrato attuale: <?= e($quadrat) ?>)</label>
      <textarea id="testo" name="testo" rows="3" maxlength="480"
        style="width:100%;padding:.6rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:1rem var(--mono)"
        <?= $inSuperficie ? '' : 'disabled' ?>></textarea>
      <p class="aiuto">Più è lungo, più dura l'emissione, più è facile triangolarla. Circa un secondo ogni tre caratteri.</p>
    </div>
    <div class="azioni">
      <button type="submit" name="tipo" value="rapporto" <?= $inSuperficie ? '' : 'disabled' ?>>Trasmetti al BdU</button>
      <?php if ($branco !== null): ?>
        <button type="submit" name="tipo" value="contatto" class="bottone--fantasma" <?= $inSuperficie ? '' : 'disabled' ?>>
          Segnala contatto al gruppo "<?= e($branco['nome']) ?>"
        </button>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($fix !== []): ?>
<div class="pannello">
  <h2>Intercettazioni subite</h2>
  <p class="sommario">Quello che il nemico ha ricavato dalle nostre emissioni. A bordo non lo si può sapere: qui è mostrato perché il giocatore capisca il prezzo.</p>
  <table class="dati">
    <tr><th>Ora</th><th>Rilevamenti</th><th>Errore del punto</th><th>Quadrato stimato</th><th>Reazione</th></tr>
    <?php foreach ($fix as $f): ?>
      <tr>
        <td><?= e($clock->format((int) $f['gts'])) ?></td>
        <td><?= e($f['rilevamenti']) ?></td>
        <td><?= e(number_format((float) $f['errore_nm'], 0, ',', '')) ?> nm</td>
        <td><?= e($f['quadrat'] ?? '—') ?></td>
        <td style="color:var(--ambra)"><?= e($f['reazione']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="pannello">
  <h2>Traffico radio</h2>
  <?php if ($messaggi === []): ?>
    <p class="sommario">Etere silenzioso.</p>
  <?php else: ?>
    <ul class="ktb">
      <?php foreach ($messaggi as $m): ?>
        <li class="sev-<?= $m['tipo'] === 'comunicato' ? 'nota' : 'info' ?>">
          <span class="ora"><?= e($clock->format((int) $m['gts'])) ?></span>
          <span class="quadrat"><?= e($m['mittente'] ?? 'BdU') ?></span>
          <span class="testo"><?= e($m['testo']) ?>
            <?php if ((int) $m['intercettato'] === 1): ?>
              <span style="color:var(--rosso)"> — intercettato</span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
