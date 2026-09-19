<?php
/** @var array $stat @var list $classifica @var list $convogli @var \App\Sim\Clock $clock @var int $now */
use App\Game\Carriera;
?>
<div class="pannello">
  <span class="targhetta">Stato della battaglia</span>
  <h1>Statistiche di campagna</h1>
  <p class="sommario">
    Il numero che decise davvero la Battaglia dell'Atlantico non è il tonnellaggio assoluto: è il
    <b>rapporto di scambio</b>, cioè quante tonnellate di naviglio nemico costa ogni U-Boot perduto.
    Finché quel numero restava alto, l'arma vinceva. Quando crollò, la guerra al traffico era finita.
  </p>

  <div class="quadranti">
    <div class="quadrante">
      <div class="etichetta">Naviglio affondato</div>
      <div class="valore"><?= e(number_format((float) $stat['navi_affondate'], 0, ',', '.')) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Stazza affondata</div>
      <div class="valore" style="font-size:1.2rem"><?= e(number_format((float) $stat['grt_affondato'], 0, ',', '.')) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Battelli perduti</div>
      <div class="valore" style="color:var(--rosso)"><?= e($stat['battelli_persi']) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Rapporto di scambio</div>
      <div class="valore" style="font-size:1.2rem">
        <?= $stat['scambio'] !== null ? e(number_format((float) $stat['scambio'], 0, ',', '.')) . ' GRT' : '—' ?>
      </div>
    </div>
  </div>

  <table class="dati" style="margin-top:1rem">
    <tr><th>Battelli in mare</th><td><?= e($stat['battelli_in_mare']) ?> su <?= e($stat['battelli']) ?></td>
        <th>Comandanti in servizio</th><td><?= e($stat['comandanti_attivi']) ?></td></tr>
    <tr><th>Fascicoli chiusi</th><td><?= e($stat['fascicoli_chiusi']) ?></td>
        <th>Missioni compiute</th><td><?= e($stat['patrol']) ?></td></tr>
    <tr><th>Miglia percorse</th><td><?= e(number_format((float) $stat['miglia'], 0, ',', '.')) ?></td>
        <th>Siluri lanciati</th><td><?= e($stat['siluri_lanciati']) ?>
          <?= $stat['percentuale_colpi'] !== null ? ' (' . e(number_format((float) $stat['percentuale_colpi'], 1, ',', '')) . '% a segno)' : '' ?></td></tr>
    <tr><th>Naviglio alleato in mare</th><td><?= e(number_format((float) $stat['naviglio_in_mare'], 0, ',', '.')) ?> navi</td>
        <th>Stazza in navigazione</th><td><?= e(number_format((float) $stat['grt_in_mare'], 0, ',', '.')) ?> GRT</td></tr>
  </table>
</div>

<?php if ($stat['settori_caldi'] !== []): ?>
<div class="pannello">
  <h2>Settori sorvegliati</h2>
  <p class="sommario">Dove si è fatto rumore, il mare si è scaldato. Il calore scende da solo col passare dei giorni.</p>
  <table class="dati">
    <tr><th>Quadrato</th><th>Calore</th><th>Giudizio</th></tr>
    <?php foreach ($stat['settori_caldi'] as $s): ?>
      <tr>
        <td><?= e($s['quadrat']) ?></td>
        <td><?= e(number_format((float) $s['heat'], 0, ',', '')) ?>/100</td>
        <td style="color:<?= $s['heat'] > 55 ? 'var(--rosso)' : 'var(--ambra)' ?>"><?= e($s['stato']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="pannello">
  <h2>Classifica per tonnellaggio</h2>
  <?php if ($classifica === []): ?>
    <p class="sommario">Nessun comandante ha ancora aperto il suo conto.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>#</th><th>Comandante</th><th>Grado</th><th>Missioni</th><th>Navi</th><th>GRT</th><th>Segnalazioni</th><th>Stato</th></tr>
      <?php foreach ($classifica as $i => $c): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($c['nome']) ?> <span style="color:var(--testo-3)">(<?= e($c['username']) ?>)</span></td>
          <td><?= e(Carriera::gradoNome((int) $c['grado'])) ?></td>
          <td><?= e($c['patrols']) ?></td>
          <td><?= e($c['affondate']) ?></td>
          <td><?= e(number_format((float) $c['grt_affondato'], 0, ',', '.')) ?></td>
          <td><?= e($c['segnalazioni']) ?></td>
          <td style="color:<?= $c['stato'] === 'attivo' ? 'var(--verde)' : 'var(--testo-3)' ?>"><?= e($c['stato']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php if ($convogli !== []): ?>
<div class="pannello">
  <h2>Convogli colpiti</h2>
  <table class="dati">
    <tr><th>Convoglio</th><th>Rotta</th><th>Navi perdute</th><th>Su</th><th>Stato</th></tr>
    <?php foreach ($convogli as $c): ?>
      <tr>
        <td><?= e($c['serie'] . $c['numero']) ?></td>
        <td style="color:var(--testo-3)"><?= e(\App\Sim\Traffic::nomeRotta((string) $c['rotta_key'])) ?></td>
        <td><?= e($c['affondate']) ?></td>
        <td><?= e($c['navi_iniziali']) ?></td>
        <td><?= e($c['state']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
