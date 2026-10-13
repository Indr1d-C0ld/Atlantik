<?php
/** @var array $patrol @var ?array $boat @var ?array $cmd @var ?array $tipo @var list $righe @var list $affondate
 *  @var ?float $giorni @var string $carta @var \App\Sim\Clock $clock */
$num = static fn (float $v, int $d = 0): string => number_format($v, $d, ',', '.');
$grt = array_sum(array_map(static fn (array $a): int => (int) $a['grt'], $affondate));
?>
<article class="ktb-documento">
  <header class="ktb-testata">
    <p class="ktb-segreto">Geheime Kommandosache — documento segreto</p>
    <h1>Kriegstagebuch <?= e((string) ($boat['uboat_number'] ?? '—')) ?></h1>
    <p class="ktb-sotto"><?= e((string) $patrol['number']) ?>ª missione di guerra · <?= e((string) ($boat['flotilla'] ?? '')) ?></p>
    <table class="ktb-scheda">
      <tr><th>Comandante</th><td><?= e((string) ($cmd['nome'] ?? '—')) ?><?= ($cmd['role'] ?? '') === 'motore' ? ' <span class="ktb-nota">(collega del motore)</span>' : '' ?></td>
          <th>Battello</th><td><?= e((string) ($tipo['name'] ?? '—')) ?></td></tr>
      <tr><th>Partenza</th><td><?= e($clock->formatDiario((int) $patrol['departed_gts'])) ?></td>
          <th>Rientro</th><td><?= $patrol['returned_gts'] !== null ? e($clock->formatDiario((int) $patrol['returned_gts'])) : '—' ?><?= (string) $patrol['state'] === 'perduta' ? ' <b>— non rientrato</b>' : '' ?></td></tr>
      <tr><th>Giorni in mare</th><td><?= $giorni !== null ? e($num($giorni, 1)) : '—' ?></td>
          <th>Miglia</th><td><?= e($num((float) $patrol['distance_nm'])) ?> (<?= e($num((float) $patrol['submerged_nm'])) ?> in immersione)</td></tr>
      <tr><th>Siluri lanciati</th><td><?= e((string) (int) $patrol['siluri_lanciati']) ?></td>
          <th>Affondate</th><td><?= e(plurale(count($affondate), 'una nave', '%d navi')) ?>, <?= e($num($grt)) ?> GRT</td></tr>
    </table>
  </header>

  <?php if ($carta !== ''): ?>
    <figure class="ktb-figura">
      <?= $carta ?>
      <figcaption>La rotta, dalle posizioni annotate nel giornale. Le croci sono gli affondamenti.</figcaption>
    </figure>
  <?php endif; ?>

  <table class="ktb-tabella">
    <thead><tr><th>Data e ora</th><th>Posizione</th><th>Vento, mare</th><th>Avvenimenti</th></tr></thead>
    <tbody>
      <?php foreach ($righe as $r): ?>
        <tr class="<?= in_array((string) $r['kind'], ['affondamento', 'combattimento', 'lancio'], true) ? 'ktb-rilievo' : '' ?>">
          <td class="ktb-ora"><?= e($clock->formatDiario((int) $r['gts'])) ?></td>
          <td><?= e((string) ($r['quadrat'] ?? '—')) ?></td>
          <td class="ktb-ora"><?= e((string) ($r['tempo'] ?? '')) ?></td>
          <td><?= e((string) $r['text']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($affondate !== []): ?>
    <h2>Naviglio affondato</h2>
    <table class="ktb-tabella">
      <thead><tr><th>Data e ora</th><th>Nave</th><th>Bandiera</th><th>GRT</th><th>Quadrato</th><th>Arma</th></tr></thead>
      <tbody>
        <?php foreach ($affondate as $a): ?>
          <tr><td class="ktb-ora"><?= e($clock->formatDiario((int) $a['gts'])) ?></td><td><?= e($a['nome']) ?></td>
            <td><?= e(\App\Game\Profilo::nomeBandiera((string) $a['bandiera'])) ?></td><td><?= e($num((float) $a['grt'])) ?></td>
            <td><?= e((string) ($a['quadrat'] ?? '—')) ?></td><td><?= e(str_replace('_', ' e ', (string) $a['arma'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!empty($patrol['rapporto'])): ?>
    <h2>Giudizio del BdU</h2>
    <p class="ktb-giudizio"><?= nl2br(e((string) $patrol['rapporto'])) ?></p>
  <?php endif; ?>

  <p class="ktb-piede">Atlantik — simulazione storica multigiocatore della Battaglia dell'Atlantico.
    <a href="<?= e(url('/manuale')) ?>">Che cos'è</a> · <a href="<?= e(url('/')) ?>">Arruolarsi</a></p>
</article>
