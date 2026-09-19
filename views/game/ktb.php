<?php
/** @var array|null $patrol_scelta @var list $righe @var list $patrols @var \App\Sim\Clock $clock */
use App\Sim\Clock;
?>
<?= partial('nav_plancia', ['attiva' => 'ktb']) ?>

<div class="pannello">
  <span class="targhetta">Kriegstagebuch</span>
  <h1>Giornale di guerra</h1>

  <?php if ($patrol_scelta === null): ?>
    <p class="sommario">Nessuna missione registrata: il giornale si apre con la prima uscita.</p>
  <?php else: ?>
    <div class="scheda-patrol">
      <span>Patrol <b>n. <?= e($patrol_scelta['number']) ?></b></span>
      <span>Partenza <b><?= e($clock->formatDiario((int) $patrol_scelta['departed_gts'])) ?></b></span>
      <?php if ($patrol_scelta['returned_gts'] !== null): ?>
        <span>Rientro <b><?= e($clock->formatDiario((int) $patrol_scelta['returned_gts'])) ?></b></span>
      <?php endif; ?>
      <span>Percorse <b><?= e(number_format((float) $patrol_scelta['distance_nm'], 0, ',', '.')) ?> nm</b></span>
      <span>di cui immerso <b><?= e(number_format((float) $patrol_scelta['submerged_nm'], 0, ',', '.')) ?> nm</b></span>
      <span>Nafta <b><?= e(number_format((float) $patrol_scelta['fuel_used_t'], 1, ',', '')) ?> t</b></span>
      <span>Quota massima <b><?= e(number_format((float) $patrol_scelta['max_depth_m'], 0, ',', '')) ?> m</b></span>
      <span>Siluri lanciati <b><?= e((int) ($patrol_scelta['siluri_lanciati'] ?? 0)) ?></b></span>
      <span>Affondate <b><?= e((int) ($patrol_scelta['affondate'] ?? 0)) ?></b> per <b><?= e(number_format((float) ($patrol_scelta['grt_affondato'] ?? 0), 0, ',', '.')) ?> GRT</b></span>
      <span>Stato <b><?= e($patrol_scelta['state']) ?></b></span>
      <span><a href="<?= e(url('/ktb/' . (int) $patrol_scelta['id'] . '/esporta')) ?>">scarica il giornale</a></span>
    </div>

    <?php if ($righe === []): ?>
      <p class="sommario">Nessuna annotazione.</p>
    <?php else: ?>
      <ul class="ktb">
        <?php foreach ($righe as $e): ?>
          <?php $nave = (string) $e['kind'] === 'affondamento' ? (($affondate ?? [])[(int) $e['gts']] ?? null) : null; ?>
          <li class="sev-<?= e($e['severity']) ?><?= $nave !== null ? ' ktb-affondamento' : '' ?>">
            <span class="ora"><?= e($clock->formatDiario((int) $e['gts'])) ?></span>
            <span class="quadrat"><?= e($e['quadrat'] ?? '—') ?></span>
            <span class="testo"><?= e($e['text']) ?></span>
            <?php if ($nave !== null): ?>
              <span class="sagoma-ktb">
                <?= partial('segnaposto', ['lega' => (string) $nave['class_key'], 'h' => 26]) ?>
              </span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (($affondate ?? []) !== []): ?>
        <p class="nota-segnaposto">
          Le sagome accanto agli affondamenti sono profili documentali, sagome costruite su
          misure, o ricostruzioni disegnate: passa sopra a una per sapere quale. Stanno nel
          giornale perché il giornale è un resoconto — quelle navi sono già state riconosciute
          e affondate.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (count($patrols) > 1): ?>
<div class="pannello">
  <h2>Missioni precedenti</h2>
  <table class="dati">
    <tr><th>N.</th><th>Partenza</th><th>Rientro</th><th>Miglia</th><th>Stato</th><th></th></tr>
    <?php foreach ($patrols as $p): ?>
      <tr>
        <td><?= e($p['number']) ?></td>
        <td><?= e($clock->formatDiario((int) $p['departed_gts'])) ?></td>
        <td><?= $p['returned_gts'] !== null ? e($clock->formatDiario((int) $p['returned_gts'])) : '—' ?></td>
        <td><?= e(number_format((float) $p['distance_nm'], 0, ',', '.')) ?></td>
        <td><?= e($p['state']) ?></td>
        <td><a href="<?= e(url('/ktb?patrol=' . (int) $p['id'])) ?>">apri</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
