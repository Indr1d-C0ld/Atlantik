<?php
/** @var array $gruppi @var int $ottenuti @var int $totale @var \App\Sim\Clock $clock */
$nomiCat = [
    'caccia' => 'Caccia', 'navigazione' => 'Navigazione', 'sopravvivenza' => 'Sopravvivenza',
    'comando' => 'Comando', 'mestiere' => 'Mestiere',
];
?>
<?= partial('nav_plancia', ['attiva' => 'comandante']) ?>

<div class="pannello">
  <span class="targhetta">Riconoscimenti</span>
  <h1>Trofei <span style="color:var(--testo-3);font-weight:400"><?= e($ottenuti) ?> su <?= e($totale) ?></span></h1>
  <p class="sommario">
    Non sono decorazioni: quelle le conferisce il BdU secondo criteri d'epoca e stanno nel fascicolo.
    Questi premiano il <b>modo</b> in cui si gioca — la pazienza dell'agguato, la disciplina con la radio,
    il mestiere del navigatore. Quasi tutti si ottengono facendo le cose come si facevano davvero.
  </p>
  <div class="misuratore"><i style="width:<?= e(number_format($totale > 0 ? 100 * $ottenuti / $totale : 0, 1, '.', '')) ?>%"></i></div>
</div>

<?php foreach ($gruppi as $cat => $lista): ?>
<div class="pannello">
  <h2><?= e($nomiCat[$cat] ?? ucfirst($cat)) ?></h2>
  <?php foreach ($lista as $t): ?>
    <?php $ok = $t['ottenuto'] !== null; ?>
    <div style="display:flex;gap:1rem;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--acciaio-2)">
      <div style="width:1.6rem;text-align:center;font-size:1.1rem;color:<?= $ok ? 'var(--ottone)' : 'var(--testo-3)' ?>">
        <?= $ok ? '■' : '□' ?>
      </div>
      <div style="flex:1">
        <b style="color:<?= $ok ? 'var(--testo)' : 'var(--testo-3)' ?>"><?= e($t['nome']) ?></b>
        <?php if ($ok): ?>
          <span style="color:var(--testo-3);font-size:.78rem"> — <?= e($clock->format((int) $t['ottenuto']['gts'], false)) ?>
            <?= $t['ottenuto']['dettaglio'] !== null ? ' · ' . e($t['ottenuto']['dettaglio']) : '' ?></span>
        <?php endif; ?>
        <div style="font-size:.88rem;color:var(--testo-2)"><?= e($t['descrizione']) ?></div>
        <?php if (!empty($t['nota'])): ?>
          <div style="font-size:.8rem;color:var(--testo-3);font-style:italic"><?= e($t['nota']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
