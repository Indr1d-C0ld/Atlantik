<?php
/** @var array $p  @var \App\Sim\Clock $clock  @var bool $e_mio  @var bool $admin */
use App\Game\Profilo;
use App\Game\Ritratto;

$cmd = $p['cmd'];
$n   = $p['numeri'];
$mil = $p['per_genere']['militare'];
$civ = $p['per_genere']['civile'];
$tot = max(1, $mil['grt'] + $civ['grt']);

$num = static fn (float|int $v, int $d = 0): string => number_format((float) $v, $d, ',', '.');
?>

<div class="pannello">
  <div class="targhetta">Fascicolo personale</div>

  <div class="profilo-testata">
    <?php if ($p['ritratto'] !== null): ?>
      <figure class="profilo-ritratto" style="margin:0">
        <img src="<?= e(asset($p['ritratto']['url'])) ?>" alt="Ritratto di <?= e($cmd['nome']) ?>"
             width="384" height="384" loading="lazy" decoding="async">
        <figcaption><?= e(Ritratto::dichiarazione($p['ritratto'])) ?></figcaption>
      </figure>
    <?php else: ?>
      <div class="profilo-ritratto profilo-ritratto--vuoto" style="width:11rem">nessun<br>ritratto</div>
    <?php endif; ?>

    <div class="profilo-dati">
      <h1 style="margin:0 0 .2rem"><?= e($cmd['nome']) ?></h1>
      <p class="sommario" style="margin:.1rem 0 .9rem">
        <?= e($p['grado']) ?> · <?= e($cmd['flottiglia']) ?>
        <?php if ((bool) $cmd['nome_storico']): ?>
          <br><span style="color:var(--testo-3);font-size:.8rem">Porta il nome del comandante ritratto, in omaggio.
          È un altro uomo: questo è il suo fascicolo, non il suo.</span>
        <?php endif; ?>
      </p>

      <?php
      // Il nome d'accesso lo vedono solo il proprietario e l'amministrazione.
      // Sul fascicolo di un altro non serve a niente — chi guarda vuole sapere
      // del comandante, non dell'account — e un nome d'accesso valido e' meta'
      // di un tentativo d'intrusione: tanto vale non regalarlo. (Audit del
      // 19/09/2026.)
      $vedeAccount = auth_check()
          && ((int) ($cmd['user_id'] ?? 0) === (int) (auth_user()['id'] ?? 0) || \App\Auth\Auth::isAdmin());
      ?>
      <?php if ($vedeAccount): ?>
        <div class="riga"><span class="etichetta">Account</span><span class="valore piccolo"><?= e($cmd['username'] ?? '—') ?></span></div>
      <?php endif; ?>
      <div class="riga"><span class="etichetta">Nato</span><span class="valore piccolo">
        <?= e(fmt_date($cmd['nato_il'])) ?><?= $cmd['nato_a'] ? ', ' . e($cmd['nato_a']) : '' ?></span></div>
      <div class="riga"><span class="etichetta">In servizio dal</span><span class="valore piccolo"><?= e($clock->format((int) $cmd['entrato_gts'], false)) ?></span></div>
      <div class="riga"><span class="etichetta">Stato</span>
        <span class="valore piccolo" style="color:<?= (string) $cmd['stato'] === 'attivo' ? 'var(--verde)' : 'var(--rosso)' ?>">
          <?= e($cmd['stato']) ?><?= $cmd['uscito_gts'] !== null ? ' — ' . e($clock->format((int) $cmd['uscito_gts'], false)) : '' ?>
        </span></div>
      <?php if ($cmd['sorte'] !== null): ?>
        <p class="aiuto" style="margin-top:.5rem"><?= e($cmd['sorte']) ?></p>
      <?php endif; ?>

      <?php if ($p['boat'] !== null): ?>
        <div class="riga" style="margin-top:.8rem;align-items:center">
          <span class="etichetta">Battello</span>
          <span class="valore piccolo" style="display:flex;align-items:center;gap:.6rem">
            <?php if ($p['emblema'] !== null): ?>
              <img class="profilo-emblema emblema-tondo" src="<?= e(asset($p['emblema']['url'])) ?>"
                   alt="Emblema: <?= e($p['emblema']['nome']) ?>"
                   data-emblema="<?= e($p['emblema']['nome']) ?>"
                   data-emblema-motto="<?= e((string) ($p['emblema']['motto'] ?? '')) ?>"
                   loading="lazy" decoding="async">
            <?php endif; ?>
            <b><?= e($p['boat']['uboat_number']) ?></b>
            <?= $p['tipo'] !== null ? e($p['tipo']['name']) : '' ?>
            <span style="color:var(--testo-3)">· <?= e($p['boat']['state']) ?></span>
          </span>
        </div>
      <?php endif; ?>

      <?php if (($cmd['nota_pubblica'] ?? '') !== ''): ?>
        <p class="sommario" style="margin-top:.9rem;border-left:2px solid var(--ottone-2);padding-left:.8rem">
          <?= nl2br(e($cmd['nota_pubblica'])) ?></p>
      <?php endif; ?>

      <?php if ($e_mio || $admin): ?>
        <div class="azioni" style="margin-top:1rem">
          <a class="bottone bottone--fantasma" href="<?= e(url($admin && !$e_mio
              ? '/admin/comandante/' . (int) $cmd['id'] : '/comandante/profilo')) ?>">Modifica il fascicolo</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="quadranti">
  <div class="quadrante"><div class="etichetta">uscite</div><div class="valore"><?= e((string) $n['uscite']) ?></div></div>
  <div class="quadrante"><div class="etichetta">navi affondate</div><div class="valore"><?= e((string) $n['navi']) ?></div></div>
  <div class="quadrante"><div class="etichetta">stazza</div><div class="valore" style="font-size:1.15rem"><?= e($num($n['grt'])) ?> GRT</div></div>
  <div class="quadrante"><div class="etichetta">giorni di mare</div><div class="valore"><?= e($num((float) $cmd['giorni_mare'], 1)) ?></div></div>
  <div class="quadrante"><div class="etichetta">miglia</div><div class="valore" style="font-size:1.15rem"><?= e($num($n['miglia'])) ?></div></div>
  <div class="quadrante"><div class="etichetta">prestigio</div><div class="valore"><?= e($num((float) $cmd['prestigio_tot'])) ?></div></div>
</div>

<div class="griglia-plancia">
  <div class="strumento">
    <h3>Rendimento</h3>
    <div class="riga"><span class="etichetta">GRT per siluro</span><span class="valore piccolo">
      <?= $n['grt_siluro'] !== null ? e($num($n['grt_siluro'])) : '—' ?></span></div>
    <div class="riga"><span class="etichetta">GRT per missione</span><span class="valore piccolo">
      <?= $n['grt_missione'] !== null ? e($num($n['grt_missione'])) : '—' ?></span></div>
    <div class="riga"><span class="etichetta">GRT per mille miglia</span><span class="valore piccolo">
      <?= $n['grt_mille_miglia'] !== null ? e($num($n['grt_mille_miglia'])) : '—' ?></span></div>
    <div class="riga"><span class="etichetta">Siluri lanciati</span><span class="valore piccolo"><?= e((string) $n['siluri']) ?></span></div>
    <div class="riga"><span class="etichetta">Colpi a segno</span><span class="valore piccolo">
      <?= $n['colpi_a_segno'] !== null ? e($num($n['colpi_a_segno'], 1)) . '%' : '—' ?></span></div>
    <div class="riga"><span class="etichetta">Quota massima</span><span class="valore piccolo"><?= e($num($n['quota_max'])) ?> m</span></div>
    <div class="riga"><span class="etichetta">Nafta consumata</span><span class="valore piccolo"><?= e($num($n['nafta'], 1)) ?> t</span></div>
  </div>

  <div class="strumento">
    <h3>Le missioni più lunghe</h3>
    <?php $pg = $p['piu_lunga']['giorni']; $pm = $p['piu_lunga']['miglia']; ?>
    <?php if ($pg === null): ?>
      <p class="sommario">Nessuna missione conclusa.</p>
    <?php else: ?>
      <div class="riga"><span class="etichetta">Per giorni</span><span class="valore piccolo">
        <?= e($num((float) $pg['giorni'], 1)) ?> g — missione n. <?= e((string) $pg['number']) ?>,
        <?= e((string) ($pg['area_quadrat'] ?? '—')) ?></span></div>
      <div class="riga"><span class="etichetta">Per miglia</span><span class="valore piccolo">
        <?= e($num((float) ($pm['distance_nm'] ?? 0))) ?> nm — missione n. <?= e((string) ($pm['number'] ?? '—')) ?></span></div>
      <div class="riga"><span class="etichetta">In superficie</span><span class="valore piccolo"><?= e($num($n['miglia_sup'])) ?> nm</span></div>
      <div class="riga"><span class="etichetta">In immersione</span><span class="valore piccolo"><?= e($num($n['miglia_sub'])) ?> nm</span></div>
    <?php endif; ?>
  </div>

  <div class="strumento">
    <h3>Che cosa ha affondato</h3>
    <?php if ($n['navi'] === 0): ?>
      <p class="sommario">Niente, ancora.</p>
    <?php else: ?>
      <?php
      // ATTENZIONE: il CSS vuole il punto decimale. $num() formatta all'italiana,
      // con la virgola, e "width:3,4%" e' una regola non valida che il browser
      // butta via — la barra spariva e sembrava un bordo.
      $pct = static fn (int $parte): string => number_format(100 * $parte / $tot, 2, '.', '');
      ?>
      <div class="barra-genere" title="Militare contro civile, in stazza">
        <i class="militare" style="width:<?= e($pct($mil['grt'])) ?>%"></i>
        <i class="civile" style="width:<?= e($pct($civ['grt'])) ?>%"></i>
      </div>
      <div class="riga" style="margin-top:.5rem"><span class="etichetta" style="color:var(--rosso)">Militare</span>
        <span class="valore piccolo"><?= e((string) $mil['navi']) ?> navi · <?= e($num($mil['grt'])) ?> GRT</span></div>
      <div class="riga"><span class="etichetta" style="color:var(--ottone)">Mercantile e civile</span>
        <span class="valore piccolo"><?= e((string) $civ['navi']) ?> navi · <?= e($num($civ['grt'])) ?> GRT</span></div>
      <p class="aiuto">Militare è il naviglio che navigava armato per mestiere — scorte e ausiliarie.
        Il resto è naviglio civile requisito alla guerra: era il bersaglio della guerra al traffico.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($p['per_bandiera'] !== []): ?>
<div class="pannello" style="margin-top:1.2rem">
  <h2>Sotto quale bandiera</h2>
  <table class="dati">
    <tr><th>Bandiera</th><th>Navi</th><th>Stazza</th><th></th></tr>
    <?php foreach ($p['per_bandiera'] as $b): ?>
      <tr>
        <td<?= $b['neutrale'] ? ' style="color:var(--ambra)"' : '' ?>><?= e($b['nome']) ?></td>
        <td><?= e((string) $b['navi']) ?></td>
        <td><?= e($num((float) $b['grt'])) ?> GRT</td>
        <td style="color:var(--testo-3)"><?= $b['neutrale'] ? 'neutrale — costa prestigio, non ne dà' : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($p['migliori'] !== []): ?>
<div class="pannello" style="margin-top:1.2rem">
  <h2>Le prede maggiori</h2>
  <table class="dati">
    <tr><th></th><th>Nave</th><th>Bandiera</th><th>Stazza</th><th>Quando</th><th>Dove</th></tr>
    <?php foreach ($p['migliori'] as $s): ?>
      <tr>
        <td style="width:6rem"><?= partial('segnaposto', ['lega' => (string) $s['class_key'], 'h' => 20]) ?></td>
        <td><b><?= e($s['nome']) ?></b></td>
        <td style="color:var(--testo-3)"><?= e(Profilo::nomeBandiera((string) $s['bandiera'])) ?></td>
        <td><?= e($num((float) $s['grt'])) ?> GRT</td>
        <td style="color:var(--testo-3)"><?= e($clock->format((int) $s['gts'])) ?></td>
        <td><?= e((string) ($s['quadrat'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="griglia-plancia" style="margin-top:1.2rem">
  <div class="strumento">
    <h3>Decorazioni</h3>
    <?php if ($p['decorazioni'] === []): ?>
      <p class="sommario">Nessuna decorazione conferita.</p>
    <?php else: ?>
      <ul class="elenco-lavori">
        <?php foreach ($p['decorazioni'] as $d): ?>
          <li class="fatto"><b><?= e($d['nome_it'] ?: $d['nome']) ?></b>
            <span style="color:var(--testo-3)">— <?= e($clock->format((int) $d['gts'], false)) ?></span>
            <?php if (($d['motivazione'] ?? '') !== ''): ?>
              <br><span style="color:var(--testo-3);font-size:.82rem"><?= e($d['motivazione']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="strumento">
    <h3>Trofei</h3>
    <?php if ($p['trofei'] === []): ?>
      <p class="sommario">Nessun trofeo.</p>
    <?php else: ?>
      <ul class="elenco-lavori">
        <?php foreach ($p['trofei'] as $t): ?>
          <li class="fatto"><b><?= e($t['nome']) ?></b>
            <span style="color:var(--testo-3)">— <?= e($t['descrizione']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php if ($p['missioni'] !== []): ?>
<div class="pannello" style="margin-top:1.2rem">
  <h2>Ruolino delle missioni</h2>
  <table class="dati">
    <tr><th>N.</th><th>Partita</th><th>Rientrata</th><th>Zona</th><th>Miglia</th><th>Affondate</th><th>Stazza</th><th>Siluri</th></tr>
    <?php foreach ($p['missioni'] as $m): ?>
      <tr>
        <td><?= e((string) $m['number']) ?></td>
        <td><?= e($clock->format((int) $m['departed_gts'], false)) ?></td>
        <td><?= $m['returned_gts'] !== null ? e($clock->format((int) $m['returned_gts'], false))
             : '<span style="color:var(--ambra)">in mare</span>' ?></td>
        <td><?= e((string) ($m['area_quadrat'] ?? '—')) ?></td>
        <td><?= e($num((float) $m['distance_nm'])) ?></td>
        <td><?= e((string) $m['affondate']) ?></td>
        <td><?= e($num((float) $m['grt_affondato'])) ?></td>
        <td><?= e((string) $m['siluri_lanciati']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
