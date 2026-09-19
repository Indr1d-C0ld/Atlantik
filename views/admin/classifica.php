<?php
/** @var list $tonnellaggio @var list $decorati @var list $convogli @var list $bersagli
 *  @var list $perduti @var array $campagna @var \App\Sim\Clock $clock */
$num = static fn ($v, int $d = 0): string => number_format((float) $v, $d, ',', '.');
?>
<?= partial('nav_admin', ['attiva' => 'classifica']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Classifica</h1>
  <p class="sommario">
    Il tonnellaggio da solo premia chi resta fuori più a lungo. Qui si guarda anche il
    <b>rendimento</b> — tonnellate per siluro, per missione, per mille miglia — e chi è tornato a
    casa, che nel 1942 era la statistica che contava davvero.
  </p>

  <div class="quadranti">
    <div class="quadrante"><div class="etichetta">navi affondate</div><div class="valore"><?= e($num($campagna['navi_affondate'] ?? 0)) ?></div></div>
    <div class="quadrante"><div class="etichetta">stazza</div><div class="valore" style="font-size:1.15rem"><?= e($num($campagna['grt_affondato'] ?? 0)) ?> GRT</div></div>
    <div class="quadrante"><div class="etichetta">battelli perduti</div><div class="valore"><?= e($num($campagna['battelli_persi'] ?? 0)) ?></div></div>
    <div class="quadrante"><div class="etichetta">scambio</div><div class="valore" style="font-size:1.15rem"><?= e($campagna['scambio'] ?? '—') ?></div></div>
    <div class="quadrante"><div class="etichetta">colpi a segno</div><div class="valore"><?= e($num($campagna['percentuale_colpi'] ?? 0, 1)) ?>%</div></div>
    <div class="quadrante"><div class="etichetta">naviglio in mare</div><div class="valore"><?= e($num($campagna['naviglio_in_mare'] ?? 0)) ?></div></div>
  </div>
</div>

<div class="pannello">
  <h2>Comandanti</h2>
  <?php if ($tonnellaggio === []): ?>
    <p class="sommario">Nessun comandante in ruolo.</p>
  <?php else: ?>
    <table class="dati">
      <tr>
        <th>#</th><th>Comandante</th><th>Account</th><th>Battello</th><th>Stato</th>
        <th>Affondate</th><th>GRT</th><th>Miss.</th><th>Siluri</th>
        <th>GRT/siluro</th><th>GRT/missione</th><th>Miglia</th><th>Prestigio</th>
      </tr>
      <?php foreach ($tonnellaggio as $i => $c): ?>
        <?php
        $siluri = (int) $c['siluri'];
        $miss   = (int) $c['missioni'];
        $grt    = (float) $c['grt_affondato'];
        ?>
        <tr>
          <td><?= e($i + 1) ?></td>
          <td><b><a href="<?= e(url('/profilo/' . (int) $c['id'])) ?>"><?= e($c['nome']) ?></a></b></td>
          <td style="color:var(--testo-3)"><?= e($c['username'] ?? '—') ?></td>
          <td><?= e($c['uboat_number'] ?? '—') ?></td>
          <td style="color:<?= (string) $c['stato'] === 'attivo' ? 'var(--verde)' : 'var(--rosso)' ?>"><?= e($c['stato']) ?></td>
          <td><?= e($c['affondate']) ?></td>
          <td><b><?= e($num($grt)) ?></b></td>
          <td><?= e($miss) ?></td>
          <td><?= e($siluri) ?></td>
          <td><?= $siluri > 0 ? e($num($grt / $siluri)) : '—' ?></td>
          <td><?= $miss > 0 ? e($num($grt / $miss)) : '—' ?></td>
          <td><?= e($num($c['miglia'])) ?></td>
          <td><?= e($num($c['prestigio_tot'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
  <div class="pannello">
    <h2>Più decorati</h2>
    <?php if ($decorati === []): ?>
      <p class="sommario">Nessuna decorazione conferita.</p>
    <?php else: ?>
      <table class="dati">
        <tr><th>Comandante</th><th>Account</th><th>Decorazioni</th></tr>
        <?php foreach ($decorati as $d): ?>
          <tr><td><?= e($d['nome']) ?></td><td style="color:var(--testo-3)"><?= e($d['username'] ?? '—') ?></td><td><?= e($d['decorazioni']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="pannello">
    <h2>Chi non è tornato</h2>
    <?php if ($perduti === []): ?>
      <p class="sommario">Nessuna perdita. Per ora.</p>
    <?php else: ?>
      <?php /* La sorte e' una frase intera, non un dato da incolonnare: sta su
               una riga sua sotto il nome, larga quanto il pannello. Finche' era
               una colonna, in un pannello stretto schiacciava tutte le altre. */ ?>
      <table class="dati dati--perdite">
        <tr><th>Comandante</th><th>Battello</th><th>GRT</th><th>Ultimo quadrato</th></tr>
        <?php foreach ($perduti as $p): ?>
          <tr class="perdita-capo">
            <td><?= e($p['nome']) ?></td>
            <td><?= e($p['uboat_number'] ?? '—') ?></td>
            <td><?= e($num($p['grt_affondato'])) ?></td>
            <td><?= e($p['ultimo_quadrat'] ?? '—') ?></td>
          </tr>
          <tr class="perdita-sorte">
            <td colspan="4"><?= e($p['sorte'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="pannello">
    <h2>Convogli più colpiti</h2>
    <?php if ($convogli === []): ?>
      <p class="sommario">Nessun convoglio attaccato.</p>
    <?php else: ?>
      <table class="dati">
        <tr><th>Convoglio</th><th>Navi</th><th>GRT</th></tr>
        <?php foreach ($convogli as $c): ?>
          <tr><td><b><?= e($c['convoglio']) ?></b></td><td><?= e($c['navi']) ?></td><td><?= e($num($c['grt'])) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="pannello">
    <h2>Che cosa si affonda</h2>
    <?php if ($bersagli === []): ?>
      <p class="sommario">Niente, ancora.</p>
    <?php else: ?>
      <table class="dati">
        <tr><th></th><th>Classe</th><th>Navi</th><th>GRT</th></tr>
        <?php foreach ($bersagli as $b): ?>
          <tr>
            <td style="width:5rem"><?= partial('segnaposto', ['lega' => (string) $b['class_key'], 'h' => 20]) ?></td>
            <td><?= e($b['name'] ?? $b['class_key']) ?></td>
            <td><?= e($b['navi']) ?></td>
            <td><?= e($num($b['grt'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
