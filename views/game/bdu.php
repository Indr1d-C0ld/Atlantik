<?php
/** @var array $boat @var array $type @var list $ordini @var array|null $branco @var list $branchi
 *  @var list $membri @var array|null $rdv @var \App\Sim\Clock $clock @var int $now */
?>
<?= partial('nav_plancia', ['attiva' => 'bdu']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="pannello">
  <span class="targhetta">Befehlshaber der U-Boote</span>
  <div style="display:flex;gap:1rem;align-items:center">
    <?= partial('segnaposto', ['chiave' => 'grado_ammiraglio', 'h' => 56]) ?>
    <h1 style="margin:0">Ordini</h1>
  </div>
  <?php if ($ordini === []): ?>
    <p class="sommario">Nessun ordine in archivio. Il BdU assegna le aree operative ai battelli in mare.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Emesso</th><th>Tipo</th><th>Quadrato</th><th>Testo</th><th>Stato</th><th></th></tr>
      <?php foreach ($ordini as $o): ?>
        <tr>
          <td><?= e($clock->format((int) $o['emesso_gts'])) ?></td>
          <td><?= e($o['tipo']) ?></td>
          <td><?= e($o['quadrat'] ?? '—') ?></td>
          <td style="font-size:.86rem"><?= e($o['testo']) ?></td>
          <td style="color:<?= $o['stato'] === 'assolto' ? 'var(--verde)' : ($o['stato'] === 'rifiutato' || $o['stato'] === 'scaduto' ? 'var(--rosso)' : 'var(--ambra)') ?>">
            <?= e($o['stato']) ?>
          </td>
          <td>
            <?php if ((string) $o['stato'] === 'aperto' && $o['boat_id'] !== null): ?>
              <form method="post" action="<?= e(url('/bdu/ordine')) ?>" style="display:flex;gap:.3rem">
                <?= csrf_field() ?>
                <input type="hidden" name="ordine" value="<?= e($o['id']) ?>">
                <button type="submit" name="accetta" value="1" class="bottone--fantasma" style="padding:.2rem .5rem;font-size:.7rem">accetta</button>
                <button type="submit" name="accetta" value="0" class="bottone--fantasma" style="padding:.2rem .5rem;font-size:.7rem">rifiuta</button>
              </form>
            <?php elseif ((string) $o['stato'] === 'accettato' && (int) $o['prestigio'] > 0): ?>
              <span style="color:var(--testo-3);font-size:.75rem">+<?= e($o['prestigio']) ?> / +<?= e($o['punti']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="pannello">
  <h2>Rudeltaktik — gruppi operativi</h2>
  <?php if ($branco !== null): ?>
    <div class="avviso">
      Sei inquadrato nel gruppo <b>"<?= e($branco['nome']) ?>"</b>, sbarramento in quadrato <b><?= e($branco['quadrat']) ?></b>.
      Chi tiene il contatto guida gli altri: ogni segnalazione dalla radio vale prestigio, anche senza affondare nulla.
    </div>
    <table class="dati">
      <tr><th>Battello</th><th>Comandante</th><th>Stato</th><th>Segnalazioni</th><th>Affondate</th><th>GRT</th></tr>
      <?php foreach ($membri as $m): ?>
        <tr>
          <td><?= e($m['uboat_number']) ?></td>
          <td><?= e($m['comandante'] ?? '—') ?></td>
          <td><?= e($m['state']) ?></td>
          <td><?= e($m['contatti']) ?></td>
          <td><?= e($m['affondate']) ?></td>
          <td><?= e(number_format((float) $m['grt'], 0, ',', '.')) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="<?= e(url('/bdu/branco/esci')) ?>" style="margin-top:.8rem">
      <?= csrf_field() ?>
      <button type="submit" class="bottone--fantasma">Uscire dal gruppo e cacciare da soli</button>
    </form>
  <?php else: ?>
    <p class="sommario">
      Non sei inquadrato in nessun gruppo. La caccia solitaria paga di più in prestigio personale e non ha obblighi —
      ma il convoglio che trovi da solo è un convoglio che ti può seppellire da solo.
    </p>
    <?php if ($branchi === []): ?>
      <p class="sommario">Nessun gruppo in formazione al momento.</p>
    <?php else: ?>
      <table class="dati">
        <tr><th>Gruppo</th><th>Sbarramento</th><th>Battelli</th><th>Chiude fra</th><th></th></tr>
        <?php foreach ($branchi as $b): ?>
          <tr>
            <td><?= e($b['nome']) ?></td>
            <td><?= e($b['quadrat']) ?></td>
            <td><?= e($b['membri']) ?></td>
            <?php
              // chiude_gts, nonostante il nome, e' tempo REALE (vedi Branco::apri):
              // qui si scrive «reali» perche' ogni altro orario della pagina e' di bordo.
              $oreChiusura = (int) round(((int) $b['chiude_gts'] - time()) / 3600);
            ?>
            <td><?= e($oreChiusura < 1 ? 'meno di un\'ora reale' : plurale($oreChiusura, 'un\'ora reale', '%d ore reali')) ?></td>
            <td>
              <?php if ((string) $boat['state'] === 'mare'): ?>
                <form method="post" action="<?= e(url('/bdu/branco/entra')) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="branco" value="<?= e($b['id']) ?>">
                  <button type="submit" class="bottone--fantasma" style="padding:.2rem .6rem;font-size:.7rem">unisciti</button>
                </form>
              <?php else: ?>
                <span style="color:var(--testo-3);font-size:.75rem">dal mare</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="pannello">
  <h2>Rifornimento in mare</h2>
  <?php if ($rdv !== null): ?>
    <div class="avviso avviso--attenzione">
      Appuntamento col battello cisterna in quadrato <b><?= e($rdv['quadrat']) ?></b>,
      dal <b><?= e($clock->format((int) $rdv['apertura_gts'])) ?></b> al <b><?= e($clock->format((int) $rdv['scadenza_gts'])) ?></b>.
      Presentarsi in superficie con mare non superiore a forza 5. Nessuna trasmissione sul posto.
    </div>
    <p class="aiuto">In cambio: <?= e(number_format((float) $rdv['nafta_t'], 0, ',', '')) ?> tonnellate di nafta,
      <?= e($rdv['siluri']) ?> siluri e viveri per due settimane.</p>
  <?php else: ?>
    <p class="sommario">
      Il Tipo XIV cede nafta, siluri e viveri in mezzo all'oceano. È il modo di raddoppiare una missione —
      ed è il momento più vulnerabile della vita di un U-Boot: ore immobili in superficie, a fianco di un bersaglio
      grande come una casa. La richiesta passa per radio, e chi ascolta la sente.
    </p>
    <?php if ((string) $boat['state'] === 'mare'): ?>
      <form method="post" action="<?= e(url('/bdu/rifornimento')) ?>">
        <?= csrf_field() ?>
        <div class="azioni"><button type="submit">Chiedere l'appuntamento</button></div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
