<?php
/** @var list $caduti @var list $in_servizio @var array $totali @var \App\Sim\Clock $clock */
use App\Game\Carriera;
?>
<div class="pannello">
  <span class="targhetta">Memoriale</span>
  <h1>Albo d'oro</h1>
  <p class="sommario">
    I comandanti che non sono tornati restano qui, con il loro tonnellaggio, la loro data e l'ultimo
    quadrato noto. Nella guerra vera ne morirono circa ventottomila su quarantamila: di tre uomini
    imbarcati, due non rividero la costa.
  </p>
  <div class="quadranti">
    <div class="quadrante">
      <div class="etichetta">Fascicoli chiusi</div>
      <div class="valore"><?= e(number_format((float) ($totali['n'] ?? 0), 0, ',', '.')) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Stazza affondata</div>
      <div class="valore" style="font-size:1.1rem"><?= e(number_format((float) ($totali['grt'] ?? 0), 0, ',', '.')) ?> GRT</div>
    </div>
  </div>
</div>

<div class="pannello">
  <h2>Caduti e dispersi</h2>
  <?php if ($caduti === []): ?>
    <p class="sommario">Nessun fascicolo chiuso: finora sono tornati tutti.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Comandante</th><th>Grado</th><th>Missioni</th><th>Navi</th><th>GRT</th><th>Quadrato</th><th>Sorte</th></tr>
      <?php foreach ($caduti as $c): ?>
        <tr>
          <td><a href="<?= e(url('/profilo/' . (int) $c['id'])) ?>"><?= e($c['nome']) ?></a>
              <span style="color:var(--testo-3)">(<?= e($c['username']) ?>)</span></td>
          <td><?= e(Carriera::gradoNome((int) $c['grado'])) ?></td>
          <td><?= e($c['patrols']) ?></td>
          <td><?= e($c['affondate']) ?></td>
          <td><?= e(number_format((float) $c['grt_affondato'], 0, ',', '.')) ?></td>
          <td><?= e($c['ultimo_quadrat'] ?? '—') ?></td>
          <td style="color:var(--testo-3);font-size:.82rem"><?= e(mb_substr((string) ($c['sorte'] ?? $c['stato']), 0, 120)) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="pannello">
  <h2>In servizio</h2>
  <?php if ($in_servizio === []): ?>
    <p class="sommario">Nessun comandante in servizio.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Comandante</th><th>Grado</th><th>Missioni</th><th>Navi</th><th>GRT</th></tr>
      <?php foreach ($in_servizio as $c): ?>
        <tr>
          <td><a href="<?= e(url('/profilo/' . (int) $c['id'])) ?>"><?= e($c['nome']) ?></a></td>
          <td><?= e(Carriera::gradoNome((int) $c['grado'])) ?></td>
          <td><?= e($c['patrols']) ?></td>
          <td><?= e($c['affondate']) ?></td>
          <td><?= e(number_format((float) $c['grt_affondato'], 0, ',', '.')) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
