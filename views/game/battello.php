<?php
/** @var array $boat @var array $type @var list $sistemi @var list $compartimenti
 *  @var array $effetti @var array $scorte @var array $voci @var array $ciurma @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Crew;

$guasti = array_values(array_filter($sistemi, static fn (array $s): bool => (string) $s['state'] !== 'ok'));
$perCategoria = [];
foreach ($sistemi as $s) { $perCategoria[(string) $s['category']][] = $s; }
$nomiCategoria = [
    'propulsione' => 'Propulsione', 'governo' => 'Governo e immersione',
    'scoperta' => 'Scoperta e comunicazioni', 'scafo' => 'Scafo', 'armamento' => 'Armamento',
];
$stressPct = (float) $boat['hull_stress'];
?>
<?= partial('nav_plancia', ['attiva' => 'battello']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<?php if ($guasti !== []): ?>
<div class="pannello">
  <span class="targhetta">Rapporto del Leitender Ingenieur</span>
  <h2><?= count($guasti) ?> <?= count($guasti) === 1 ? 'avaria a bordo' : 'avarie a bordo' ?></h2>
  <table class="dati">
    <tr><th>Sistema</th><th>Dove</th><th>Stato</th><th>Riparazione</th><th></th></tr>
    <?php foreach ($guasti as $g):
      $necessarie = (float) $g['repair_hours'] * ((string) $g['state'] === 'distrutto' ? 1.8 : 1.0);
      $pct = $necessarie > 0 ? min(100, 100 * (float) $g['repair_progress'] / $necessarie) : 0; ?>
      <tr>
        <td><?= e($g['name']) ?></td>
        <td><?= e($g['compartment']) ?></td>
        <td style="color:var(--<?= (string) $g['state'] === 'distrutto' ? 'rosso' : 'ambra' ?>)"><?= e($g['state']) ?></td>
        <td>
          <?php if ((int) $g['repairable_sea'] === 1): ?>
            <div class="misuratore" style="width:8rem"><i style="width:<?= e(number_format($pct, 0)) ?>%"></i></div>
          <?php else: ?>
            <span style="color:var(--testo-3)">non a mare</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int) $g['repairable_sea'] === 1): ?>
          <form method="post" action="<?= e(url('/riparazione')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="skey" value="<?= e($g['skey']) ?>">
            <button type="submit" class="bottone--fantasma" style="padding:.25rem .6rem;font-size:.7rem">
              <?= (string) $boat['repair_focus'] === (string) $g['skey'] ? 'in corso' : 'dai priorità' ?>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($boat['repair_focus'] !== null): ?>
    <form method="post" action="<?= e(url('/riparazione')) ?>" style="margin-top:.7rem">
      <?= csrf_field() ?><input type="hidden" name="skey" value="">
      <button type="submit" class="bottone--fantasma" style="padding:.3rem .8rem;font-size:.72rem">Torna alla priorità automatica</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="griglia-plancia">
  <div class="strumento">
    <h3>Efficienza</h3>
    <div class="riga"><span class="etichetta">Velocità in superficie</span><span class="valore">×<?= e(number_format($effetti['vel_superficie'], 2, ',', '')) ?></span></div>
    <div class="riga"><span class="etichetta">Velocità in immersione</span><span class="valore">×<?= e(number_format($effetti['vel_immersione'], 2, ',', '')) ?></span></div>
    <div class="riga"><span class="etichetta">Controllo della quota</span><span class="valore">×<?= e(number_format($effetti['quota_controllo'], 2, ',', '')) ?></span></div>
    <div class="riga"><span class="etichetta">Riserva di batteria</span><span class="valore">×<?= e(number_format($effetti['batteria'], 2, ',', '')) ?></span></div>
  </div>

  <div class="strumento">
    <h3>Scafo</h3>
    <div class="riga"><span class="etichetta">Sollecitazione accumulata</span><span class="valore grande"><?= e(number_format($stressPct, 0, ',', '')) ?>%</span></div>
    <div class="misuratore <?= $stressPct > 60 ? 'allarme' : ($stressPct > 30 ? 'attenzione' : '') ?>"><i style="width:<?= e(number_format($stressPct, 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Quota di prova</span><span class="valore piccolo"><?= e($type['test_depth_m']) ?> m</span></div>
    <div class="riga"><span class="etichetta">Scafo</span><span class="valore piccolo"><?= e(number_format((float) $boat['hull_integrity'], 0, ',', '')) ?>%</span></div>
    <div class="riga"><span class="etichetta">Collasso stimato</span><span class="valore piccolo">
      <?= e(number_format($banda['min'], 0, ',', '')) ?>–<?= e(number_format($banda['max'], 0, ',', '')) ?> m
    </span></div>
    <p class="aiuto">Le deformazioni da pressione non si raddrizzano a mare: ogni ora passata sotto la quota di prova abbassa il limite per sempre. Dentro quell'intervallo questo scafo ha il suo punto di cedimento, e nessuno a bordo sa quale sia.</p>
  </div>

  <div class="strumento">
    <h3>Stiva</h3>
    <?php foreach ($scorte as $k => $q): ?>
      <div class="riga">
        <span class="etichetta"><?= e($voci[$k]['nome'] ?? $k) ?></span>
        <span class="valore piccolo"><?= e(number_format((float) $q, 0, ',', '.')) ?> <?= e($voci[$k]['unita'] ?? '') ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="pannello">
  <h2>Sistemi</h2>
  <?php foreach ($nomiCategoria as $cat => $nome): ?>
    <?php if (empty($perCategoria[$cat])) { continue; } ?>
    <h3 style="margin-top:1rem"><?= e($nome) ?></h3>
    <table class="dati">
      <?php foreach ($perCategoria[$cat] as $s): ?>
        <tr>
          <td style="width:16rem"><?= e($s['name']) ?></td>
          <td style="width:9rem;color:var(--testo-3)"><?= e($s['compartment']) ?></td>
          <td style="width:7rem">
            <?php if ((string) $s['state'] === 'ok'): ?>
              <span style="color:var(--verde)">in servizio</span>
            <?php elseif ((string) $s['state'] === 'avaria'): ?>
              <span style="color:var(--ambra)">in avaria</span>
            <?php else: ?>
              <span style="color:var(--rosso)">fuori uso</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--testo-3);font-size:.8rem"><?= e($s['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>

<div class="pannello">
  <h2>Compartimenti</h2>
  <?php $inMare = (string) $boat['state'] === 'mare'; ?>
  <table class="dati">
    <tr><th>#</th><th>Compartimento</th><th>Integrità</th><th>Allagamento</th><th>Paratia</th></tr>
    <?php foreach ($compartimenti as $cp): ?>
      <?php
      $acqua = (float) $cp['flooding'];
      $sigillato = (int) $cp['sealed'] === 1;
      $colore = $acqua >= 35 ? 'var(--rosso)' : ($acqua > 0 ? 'var(--ambra)' : 'var(--testo-3)');
      ?>
      <tr<?= $sigillato ? ' style="opacity:.62"' : '' ?>>
        <td><?= e($cp['seq']) ?></td>
        <td><?= e($cp['name']) ?></td>
        <td style="color:<?= (float) $cp['integrity'] < 70 ? 'var(--ambra)' : 'inherit' ?>"><?= e(number_format((float) $cp['integrity'], 0, ',', '')) ?>%</td>
        <td style="color:<?= $colore ?>"><?= $acqua > 0 ? e(number_format($acqua, 0, ',', '')) . '%' : '—' ?></td>
        <td>
          <?php if ($sigillato): ?>
            <span style="color:var(--rosso);font-size:.72rem">sigillata</span>
            <?php if ($inMare): ?>
              <form method="post" action="<?= e(url('/paratia')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="compartimento" value="<?= e($cp['ckey']) ?>">
                <input type="hidden" name="azione" value="apri">
                <button type="submit" class="bottone--fantasma" style="padding:.15rem .5rem;font-size:.66rem">riapri</button>
              </form>
            <?php endif; ?>
          <?php elseif ($inMare && $acqua > 0 && (string) $cp['ckey'] !== 'zentrale'): ?>
            <form method="post" action="<?= e(url('/paratia')) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="compartimento" value="<?= e($cp['ckey']) ?>">
              <input type="hidden" name="azione" value="sigilla">
              <button type="submit" class="allarme" style="padding:.15rem .5rem;font-size:.66rem">sigilla</button>
            </form>
          <?php else: ?>
            <span style="color:var(--testo-3)">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php $zav = \App\Sim\Compartimenti::zavorra((int) $boat['id'], $compartimenti); ?>
  <?php if ($zav['acqua_pct'] > 0): ?>
    <div class="riga" style="margin-top:.7rem">
      <span class="etichetta">Acqua imbarcata</span>
      <span class="valore piccolo" style="color:<?= $zav['critico'] ? 'var(--rosso)' : 'var(--ambra)' ?>">
        circa <?= e(number_format($zav['acqua_t'], 1, ',', '.')) ?> t — quota di sicurezza al
        <?= e(number_format($zav['quota_max'] * 100, 0, ',', '')) ?>%, velocità al
        <?= e(number_format($zav['velocita'] * 100, 0, ',', '')) ?>%
      </span>
    </div>
  <?php endif; ?>
  <p class="aiuto">
    L'acqua entra con le cariche vicine e con la pressione, dove la lamiera ha già lavorato, e
    la squadra di falla la combatte da sé. Quando non c'è più niente da fare si <b>sigilla</b>:
    la paratia si chiude, l'acqua resta di là — e chi era dentro resta dentro. In bacino si
    rimette tutto a posto.
  </p>
</div>
