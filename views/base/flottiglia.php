<?php
/** @var array $user @var array $boat @var array $type @var array|null $base
 *  @var list $patrols @var array $totali @var \App\Sim\Clock $clock @var int $now @var float $autonomia */
?>
<?= partial('nav_plancia', ['attiva' => 'base']) ?>

<div class="pannello">
  <span class="targhetta"><?= e($base['flotillas'] ?? 'U-Flottille') ?></span>
  <h1><?= e($base['name'] ?? 'Base') ?></h1>
  <p class="sommario">
    <?= e($grado) ?> <?= e($cmd['nome']) ?>, il battello è in bunker: casse piene, equipaggio a terra, tavolo di carteggio sgombro.
    L'ora di bordo segna <b><?= e($clock->format($now)) ?></b>.
  </p>

  <div class="griglia-plancia">
    <div class="strumento">
      <h3>Battello assegnato</h3>
      <div class="riga"><span class="etichetta">Distintivo</span><span class="valore grande"><?= e($boat['uboat_number']) ?></span></div>
      <div class="riga"><span class="etichetta">Tipo</span><span class="valore piccolo"><?= e($type['name']) ?><?= $type['soprannome'] ? ' — ' . e($type['soprannome']) : '' ?></span></div>
      <div class="riga"><span class="etichetta">Equipaggio</span><span class="valore piccolo"><?= e($type['crew_min']) ?>–<?= e($type['crew_max']) ?> uomini</span></div>
      <div class="riga"><span class="etichetta">Siluri</span><span class="valore piccolo"><?= e($type['torpedoes']) ?> (<?= e($type['tubes_bow']) ?> a prua, <?= e($type['tubes_stern']) ?> a poppa)</span></div>
      <div class="riga"><span class="etichetta">Cannone</span><span class="valore piccolo"><?= e($type['deck_gun'] ?? 'nessuno') ?></span></div>
    </div>

    <div class="strumento">
      <h3>Prestazioni</h3>
      <div class="riga"><span class="etichetta">Velocità in superficie</span><span class="valore piccolo"><?= e(number_format((float) $type['speed_surf_kn'], 1, ',', '')) ?> kn</span></div>
      <div class="riga"><span class="etichetta">Velocità in immersione</span><span class="valore piccolo"><?= e(number_format((float) $type['speed_sub_kn'], 1, ',', '')) ?> kn</span></div>
      <div class="riga"><span class="etichetta">Nafta</span><span class="valore piccolo"><?= e(number_format((float) $type['fuel_t'], 1, ',', '')) ?> t</span></div>
      <div class="riga"><span class="etichetta">Autonomia a 10 kn</span><span class="valore piccolo"><?= e(number_format($autonomia, 0, ',', '.')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Quota di prova</span><span class="valore piccolo"><?= e($type['test_depth_m']) ?> m (collasso <?= e($type['crush_depth_min_m']) ?>–<?= e($type['crush_depth_max_m']) ?> m)</span></div>
      <div class="riga"><span class="etichetta">Immersione rapida</span><span class="valore piccolo"><?= e($type['dive_time_s']) ?> s</span></div>
      <?php
      // I viveri sono quelli scelti in cantiere, non la dotazione del tipo:
      // fino al 07/10/2026 qui c'era la dotazione, e sembrava che la scelta non
      // contasse.
      $viveriScelti = \App\Game\Outfitting::stores((int) $boat['id'])['viveri'] ?? (float) $type['provisions_days'];
      ?>
      <div class="riga"><span class="etichetta">Viveri a bordo</span><span class="valore piccolo"><?= e(number_format((float) $viveriScelti, 0, ',', '.')) ?> giorni<?=
        (int) round((float) $viveriScelti) !== (int) $type['provisions_days'] ? e(' (dotazione normale ' . (int) $type['provisions_days'] . ')') : '' ?></span></div>
    </div>

    <div class="strumento">
      <h3>Carriera</h3>
      <div class="riga"><span class="etichetta">Grado</span><span class="valore piccolo"><?= e($grado) ?></span></div>
      <div class="riga"><span class="etichetta">Prestigio</span><span class="valore piccolo"><?= e(number_format((float) $cmd['prestigio_tot'], 0, ',', '.')) ?></span></div>
      <div class="riga"><span class="etichetta">Punti di assegnazione</span><span class="valore grande"><?= e(number_format((float) $cmd['punti'], 0, ',', '.')) ?></span></div>
      <div class="comandi"><a class="bottone bottone--fantasma" style="padding:.35rem .7rem;font-size:.7rem" href="<?= e(url('/comandante')) ?>">Fascicolo</a></div>
    </div>

    <div class="strumento">
      <h3>Tonnellaggio</h3>
      <div class="riga"><span class="etichetta">Navi affondate</span><span class="valore grande"><?= e((int) ($bottino['n'] ?? 0)) ?></span></div>
      <div class="riga"><span class="etichetta">Stazza affondata</span><span class="valore piccolo"><?= e(number_format((float) ($bottino['grt'] ?? 0), 0, ',', '.')) ?> GRT</span></div>
      <div class="riga"><span class="etichetta">Siluri lanciati</span><span class="valore piccolo"><?= e((int) ($totali['siluri'] ?? 0)) ?></span></div>
    </div>

    <div class="strumento">
      <h3>Ruolino</h3>
      <div class="riga"><span class="etichetta">Missioni concluse</span><span class="valore grande"><?= e((int) ($totali['n'] ?? 0)) ?></span></div>
      <div class="riga"><span class="etichetta">Miglia percorse</span><span class="valore piccolo"><?= e(number_format((float) ($totali['nm'] ?? 0), 0, ',', '.')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Nafta consumata</span><span class="valore piccolo"><?= e(number_format((float) ($totali['t'] ?? 0), 1, ',', '')) ?> t</span></div>
      <div class="riga"><span class="etichetta">Quota massima raggiunta</span><span class="valore piccolo"><?= e(number_format((float) ($totali['q'] ?? 0), 0, ',', '')) ?> m</span></div>
    </div>
  </div>

  <?php if ($inLavorazione !== [] || $compInLavorazione !== []): ?>
  <div class="pannello" style="border-left:3px solid var(--ambra)">
    <span class="targhetta">Il cantiere sta ancora lavorando</span>
    <h2><?= count($inLavorazione) + count($compInLavorazione) ?>
        <?= count($inLavorazione) + count($compInLavorazione) === 1 ? 'lavoro aperto' : 'lavori aperti' ?></h2>
    <p class="sommario">
      Mollando gli ormeggi adesso, questo esce in mare cosi' com'e'. Gli operai
      della base lavorano da soli, ora per ora: basta aspettare.
    </p>
    <ul style="margin:0 0 .2rem 1.1rem">
      <?php foreach ($inLavorazione as $l): ?>
        <li><?= e($l['name']) ?> — <span style="color:var(--ambra)"><?= e($l['state']) ?></span></li>
      <?php endforeach; ?>
      <?php foreach ($compInLavorazione as $l): ?>
        <li><?= e($l['name']) ?> — <span style="color:var(--ambra)">da revisionare</span></li>
      <?php endforeach; ?>
    </ul>
    <p class="aiuto" style="margin-top:.7rem">
      Il dettaglio, con l'avanzamento di ogni lavoro, sta in
      <a href="<?= e(url('/battello')) ?>">scheda del battello</a>.
    </p>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/uscita')) ?>">
    <?= csrf_field() ?>
    <div class="azioni">
      <button type="submit">Mollare gli ormeggi</button>
      <a class="bottone bottone--fantasma" href="<?= e(url('/cantiere')) ?>">Cantiere e allestimento</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/equipaggio')) ?>">Equipaggio</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/bacheca')) ?>">Mensa ufficiali</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/ktb')) ?>">Giornale di guerra</a>
    </div>
  </form>
  <p class="aiuto">
    All'uscita il battello viene rifornito e armato secondo l'allestimento scelto in cantiere — viveri compresi:
    se ne possono stipare fino al doppio della dotazione normale, se la stiva li tiene. Le riparazioni no: quelle le fa il cantiere mentre si sta in porto, e quello che non ha finito parte cosi' com'e'. La rotta si traccia in mare, al tavolo di carteggio.
  </p>
</div>

<div class="pannello"<?= (int) $totali['n'] === 0 && $addestramenti === [] ? ' style="border-left:3px solid var(--ambra)"' : '' ?> id="agru">
  <h2>Addestramento tattico — Agru-Front</h2>
  <p class="sommario">
    Nessun comandante andava al fronte senza essere passato dall'Agru-Front, nel Baltico: un convoglio
    d'esercitazione nella baia di Danzica, vecchi piroscafi in colonna e due cacciasommergibili della
    scuola a fare la scorta. Si attacca davvero, dalla stazione d'attacco, ma con la testa d'esercitazione
    nei siluri; le cariche degli UJ sono di segnalazione, e l'istruttore conta quello che in guerra
    sarebbero costate. Alla fine giudica avvicinamento, tiro e disimpegno.
  </p>
  <p class="aiuto">
    Dura quanto un attacco: il convoglio arriva in pochi minuti. Siluri, nafta e viveri tornano come
    prima, e non conta fra le missioni di guerra. Superato, l'equipaggio guadagna competenza<?= !$agruSuperato ? ', e il primo vale anche prestigio' : '' ?>.
    <?= (int) $totali['n'] === 0 ? '<b>Prima di uscire in guerra per la prima volta, è il posto dove imparare la stazione d\'attacco.</b>' : '' ?>
  </p>
  <form method="post" action="<?= e(url('/addestramento')) ?>">
    <?= csrf_field() ?>
    <div class="azioni"><button type="submit"<?= (int) $totali['n'] === 0 ? '' : ' class="bottone--fantasma"' ?>>Partire per l'addestramento</button></div>
  </form>
  <?php if ($addestramenti !== []): ?>
    <table class="dati" style="margin-top:.8rem">
      <tr><th>Data</th><th>Giudizio</th><th>Il rapporto dell'istruttore</th></tr>
      <?php foreach ($addestramenti as $a): ?>
        <tr>
          <td><?= e($clock->format((int) $a['finito_gts'])) ?></td>
          <td><b><?= e((string) $a['giudizio']) ?></b></td>
          <td style="color:var(--testo-2)"><?= e((string) $a['rapporto']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?= partial('missione', ['boat' => $boat, 'offerte' => $offerte, 'attiva' => $missioneAttiva]) ?>

<?= partial('dottrina', ['boat' => $boat]) ?>

<?php if (!empty($affondamenti)): ?>
<div class="pannello">
  <h2>Albo degli affondamenti</h2>
  <table class="dati">
    <tr><th>Data</th><th>Nave</th><th>Bandiera</th><th>GRT</th><th>Carico</th><th>Quadrato</th><th>Arma</th></tr>
    <?php foreach ($affondamenti as $a): ?>
      <tr>
        <td><?= e($clock->format((int) $a['gts'])) ?></td>
        <td><?= e($a['nome']) ?></td>
        <td style="color:var(--testo-3)"><?= e($a['bandiera']) ?></td>
        <td><?= e(number_format((float) $a['grt'], 0, ',', '.')) ?></td>
        <td style="color:var(--testo-3)"><?= e($a['carico'] ?? '—') ?></td>
        <td><?= e($a['quadrat'] ?? '—') ?></td>
        <td><?= e($a['arma']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($patrols !== []): ?>
<div class="pannello">
  <h2>Missioni</h2>
  <table class="dati">
    <tr><th>N.</th><th>Partenza</th><th>Rientro</th><th>Miglia</th><th>Nafta</th><th>Stato</th><th></th></tr>
    <?php foreach ($patrols as $p): ?>
      <tr>
        <td><?= (string) ($p['tipo'] ?? 'guerra') === 'addestramento' ? '<span title="Addestramento, Agru-Front">A</span>' : e($p['number']) ?></td>
        <td><?= e($clock->format((int) $p['departed_gts'])) ?></td>
        <td><?= $p['returned_gts'] !== null ? e($clock->format((int) $p['returned_gts'])) : '—' ?></td>
        <td><?= e(number_format((float) $p['distance_nm'], 0, ',', '.')) ?></td>
        <td><?= e(number_format((float) $p['fuel_used_t'], 1, ',', '')) ?> t</td>
        <td><?= e($p['state']) ?></td>
        <td><a href="<?= e(url('/ktb?patrol=' . (int) $p['id'])) ?>">giornale</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="pannello">
  <h2>Il battello è pronto</h2>
  <ul class="elenco-lavori">
    <li class="fatto">Mondo persistente: griglia Marinequadrat, meteo, luce, correnti, consumi</li>
    <li class="fatto">Battello ed equipaggio: compartimenti, avarie, morale, riparazioni</li>
    <li class="fatto">Contatti: vedette, idrofono, radar, convogli persistenti, aerei</li>
    <li class="fatto">Combattimento: siluri, calcolatore di lancio, cannone, scorte, cariche</li>
    <li class="fatto">Carriera: gradi, decorazioni, permadeath, albo d'oro</li>
    <li class="fatto">BdU, branchi, radio e HF/DF, rifornimento in mare</li>
  </ul>
  <p class="aiuto">Da qui in avanti si bilancia sul campo: ogni partita è un dato.</p>
</div>
