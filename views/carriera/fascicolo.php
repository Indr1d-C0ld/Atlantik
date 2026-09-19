<?php
/** @var array $cmd @var array $boat @var string $grado @var array $prossimo @var list $decorazioni
 *  @var list $catalogo @var list $miglioramenti @var list $patrols @var list $affondamenti @var \App\Sim\Clock $clock */
use App\Game\Carriera;

$ottenute = array_column($decorazioni, 'akey');
$perCategoria = [];
foreach ($miglioramenti as $m) { $perCategoria[(string) $m['categoria']][] = $m; }
?>
<?= partial('nav_plancia', ['attiva' => 'comandante']) ?>

<div class="intestazione-battello">
  <span class="numero"><?= e($cmd['nome']) ?></span>
  <span class="tipo"><?= e($grado) ?> · <?= e($cmd['flottiglia']) ?></span>
  <span class="ora"><?= e($boat['uboat_number']) ?></span>
</div>

<div class="griglia-plancia">
  <div class="strumento">
    <h3>Anzianità</h3>
    <div class="riga"><span class="etichetta">Livello</span><span class="valore grande"><?= e($cmd['grado']) ?></span></div>
    <div class="riga"><span class="etichetta">Prestigio complessivo</span><span class="valore piccolo"><?= e(number_format((float) $cmd['prestigio_tot'], 0, ',', '.')) ?></span></div>
    <?php if ($prossimo['prossimo'] !== null): ?>
      <div class="riga"><span class="etichetta">Al livello <?= e($prossimo['prossimo']) ?></span><span class="valore piccolo">mancano <?= e(number_format((float) $prossimo['mancano'], 0, ',', '.')) ?></span></div>
    <?php else: ?>
      <div class="riga"><span class="etichetta">Anzianità</span><span class="valore piccolo">al massimo</span></div>
    <?php endif; ?>
    <div class="riga"><span class="etichetta">In servizio dal</span><span class="valore piccolo"><?= e($clock->format((int) $cmd['entrato_gts'], false)) ?></span></div>
  </div>

  <div class="strumento">
    <h3>Risorse</h3>
    <div class="riga"><span class="etichetta">Punti di assegnazione</span><span class="valore grande"><?= e(number_format((float) $cmd['punti'], 0, ',', '.')) ?></span></div>
    <div class="riga"><span class="etichetta">Reichsmark</span><span class="valore piccolo"><?= e(number_format((float) $cmd['reichsmark'], 0, ',', '.')) ?> RM</span></div>
    <p class="aiuto">Il prestigio è il merito e apre le porte; i punti di assegnazione sono la priorità in cantiere, e servono ad avere davvero il pezzo sbloccato.</p>
  </div>

  <div class="strumento">
    <h3>Ruolino di guerra</h3>
    <div class="riga"><span class="etichetta">Missioni</span><span class="valore grande"><?= e($cmd['patrols']) ?></span></div>
    <div class="riga"><span class="etichetta">Navi affondate</span><span class="valore piccolo"><?= e($cmd['affondate']) ?></span></div>
    <div class="riga"><span class="etichetta">Stazza affondata</span><span class="valore piccolo"><?= e(number_format((float) $cmd['grt_affondato'], 0, ',', '.')) ?> GRT</span></div>
    <div class="riga"><span class="etichetta">Giorni di mare</span><span class="valore piccolo"><?= e(number_format((float) $cmd['giorni_mare'], 0, ',', '')) ?></span></div>
  </div>
</div>

<div class="pannello">
  <div class="azioni" style="margin:0">
    <a class="bottone bottone--fantasma" href="<?= e(url('/trofei')) ?>">Trofei</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/albo')) ?>">Albo d'oro</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/statistiche')) ?>">Statistiche di campagna</a>
  </div>
</div>

<div class="pannello">
  <h2>Decorazioni</h2>
  <?php if ($decorazioni === []): ?>
    <p class="sommario">Nessuna, per ora. Si comincia con la Croce di Ferro di seconda classe, al rientro dalla prima missione.</p>
  <?php else: ?>
    <?php foreach ($decorazioni as $d): ?>
      <div style="border-left:3px solid var(--ottone);padding:.5rem .9rem;margin-bottom:.8rem;background:rgba(195,154,82,.05);display:flex;gap:.9rem;align-items:flex-start">
        <?= partial('segnaposto', ['lega' => (string) $d['akey'], 'h' => 52]) ?>
        <div>
        <b style="color:var(--ottone)"><?= e($d['nome']) ?></b>
        <span style="color:var(--testo-3)"> — <?= e($d['nome_it']) ?>, <?= e($clock->format((int) $d['gts'], false)) ?></span>
        <p style="margin:.35rem 0 0;font-size:.88rem;color:var(--testo-2)"><?= e($d['motivazione']) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h3 style="margin-top:1.2rem">Da conseguire</h3>
  <table class="dati">
    <tr><th>Decorazione</th><th>Missioni</th><th>Stazza</th><th>Navi</th><th>Nota storica</th></tr>
    <?php foreach ($catalogo as $a): ?>
      <?php if (in_array((string) $a['akey'], $ottenute, true)) { continue; } ?>
      <tr>
        <td><?= e($a['nome_it']) ?></td>
        <td><?= e($a['min_patrols']) ?></td>
        <td><?= e(number_format((float) $a['min_grt'], 0, ',', '.')) ?></td>
        <td><?= e($a['min_navi']) ?></td>
        <td style="color:var(--testo-3);font-size:.8rem"><?= e(mb_substr((string) ($a['note'] ?? $a['fonte']), 0, 90)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="pannello">
  <h2>Cantiere — apparati e miglioramenti</h2>
  <p class="sommario">Ogni scheda dichiara da quando l'apparato è esistito davvero. Qui si ottiene per anzianità, non per data.</p>
  <?php foreach ($perCategoria as $cat => $lista): ?>
    <h3 style="margin-top:1rem"><?= e(ucfirst($cat)) ?></h3>
    <table class="dati">
      <?php foreach ($lista as $m): ?>
        <tr>
          <td style="width:20rem"><?= e($m['nome']) ?>
            <div style="color:var(--testo-3);font-size:.78rem"><?= e($m['storico']) ?></div>
          </td>
          <td style="width:6rem"><?= e($m['costo']) ?> punti</td>
          <td style="color:var(--testo-3);font-size:.82rem"><?= e($m['note'] ?? '') ?></td>
          <td style="width:8rem">
            <?php if ($m['posseduto']): ?>
              <span style="color:var(--verde)">installato</span>
            <?php elseif (!$m['sbloccato']): ?>
              <span style="color:var(--testo-3)">livello <?= e($m['unlock_rank']) ?></span>
            <?php elseif ((string) $boat['state'] !== 'base'): ?>
              <span style="color:var(--testo-3)">in mare</span>
            <?php else: ?>
              <form method="post" action="<?= e(url('/comandante/compra')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="ukey" value="<?= e($m['ukey']) ?>">
                <button type="submit" class="bottone--fantasma" style="padding:.25rem .7rem;font-size:.72rem"
                  <?= (int) $cmd['punti'] < (int) $m['costo'] ? 'disabled' : '' ?>>richiedi</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>

<?php if ((string) $boat['state'] === 'base'): ?>
<div class="pannello">
  <h2>Corsi per l'equipaggio</h2>
  <p class="sommario">Quaranta punti di assegnazione per un corso: l'intera specialità guadagna competenza. Un equipaggio bravo ripara in metà tempo e sbaglia meno al lancio.</p>
  <form method="post" action="<?= e(url('/comandante/addestra')) ?>" style="display:flex;gap:.7rem;flex-wrap:wrap;align-items:flex-end">
    <?= csrf_field() ?>
    <div class="campo" style="margin:0">
      <label for="spec">Specialità</label>
      <select id="spec" name="specialita" style="padding:.55rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo)">
        <?php foreach ([
          'silurista' => 'Siluristi', 'macchinista_diesel' => 'Macchinisti ai diesel',
          'macchinista_elettrico' => 'Macchinisti ai motori elettrici', 'radiotelegrafista' => 'Radiotelegrafisti e idrofonisti',
          'zentrale' => 'Addetti alla centrale', 'marinaio' => 'Marinai e vedette',
        ] as $k => $n): ?>
          <option value="<?= e($k) ?>"><?= e($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" <?= (int) $cmd['punti'] < 40 ? 'disabled' : '' ?>>Iscrivi al corso (40 punti)</button>
  </form>
</div>
<?php endif; ?>

<div class="pannello">
  <h2>Missioni</h2>
  <?php if ($patrols === []): ?>
    <p class="sommario">Nessuna missione conclusa.</p>
  <?php else: ?>
  <table class="dati">
    <tr><th>N.</th><th>Partenza</th><th>Rientro</th><th>Affondate</th><th>GRT</th><th>Prestigio</th><th>Stato</th><th></th></tr>
    <?php foreach ($patrols as $p): ?>
      <tr>
        <td><?= e($p['number']) ?></td>
        <td><?= e($clock->format((int) $p['departed_gts'])) ?></td>
        <td><?= $p['returned_gts'] !== null ? e($clock->format((int) $p['returned_gts'])) : '—' ?></td>
        <td><?= e($p['affondate']) ?></td>
        <td><?= e(number_format((float) $p['grt_affondato'], 0, ',', '.')) ?></td>
        <td><?= e(number_format((float) $p['prestigio'], 0, ',', '.')) ?></td>
        <td><?= e($p['state']) ?></td>
        <td>
          <?php if ($p['rapporto'] !== null): ?><a href="<?= e(url('/rapporto/' . (int) $p['id'])) ?>">rapporto</a> · <?php endif; ?>
          <a href="<?= e(url('/ktb/' . (int) $p['id'] . '/esporta')) ?>">scarica KTB</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
