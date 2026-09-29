<?php
/** @var array $boat @var array $type @var array $stato_periscopio @var array $portate @var list $in_vista
 *  @var array $meteo @var array $cielo @var \App\Sim\Clock $clock @var int $now */
use App\Game\Periscopio;

$st = $stato_periscopio;
$fuori = $st['alzato'] && !$st['guasto'];
$prua = (float) $boat['heading'];

// Il giro d'orizzonte si legge con la prua al centro: a sinistra quello che
// sta a sinistra, a destra quello che sta a destra, la poppa ai due bordi.
$segni = array_map(static function (array $k) use ($prua): array {
    $rel = fmod((float) $k['bearing'] - $prua + 540.0, 360.0) - 180.0;     // -180..180, 0 = prua
    return [
        'k'   => $k,
        'pos' => ($rel + 180.0) / 360.0 * 100.0,
        'rel' => $rel,
    ];
}, $in_vista);
?>
<?= partial('nav_plancia', ['attiva' => 'periscopio']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="due-colonne">
  <div>
    <div class="pannello oculare<?= $fuori ? '' : ' oculare--buio' ?>" id="oculare">
      <h2>Oculare</h2>
      <?php if ($st['posizione'] === Periscopio::IN_SUPERFICIE): ?>
        <p class="sommario">
          In superficie non si guarda nel periscopio: si guarda dalla torretta, e ci pensano le vedette.
          Quello che vedono sta nella pagina dell'<a href="<?= e(url('/contatti')) ?>">ascolto e avvistamenti</a>.
        </p>
      <?php elseif ($st['posizione'] === Periscopio::TROPPO_SOTTO): ?>
        <p class="sommario">
          A <?= e(number_format((float) $boat['depth_m'], 0, ',', '')) ?> metri il periscopio non arriva
          alla superficie. Si sale a quota periscopica, dodici-quattordici metri, e poi lo si alza.
        </p>
      <?php elseif ($st['guasto']): ?>
        <p class="sommario" style="color:var(--rosso)">
          Il periscopio d'osservazione è in avaria (<?= e($st['stato_apparato']) ?>). Finché non è riparato,
          da quota periscopica non si vede niente.
        </p>
      <?php elseif (!$fuori): ?>
        <p class="sommario">
          Periscopio dentro. Da sotto non si vede niente e nessuno vede noi: resta l'idrofono.
        </p>
      <?php else: ?>
        <?php /* Il giro d'orizzonte e' un disegno: gli stessi contatti, in
           tabella, stanno qui sotto. */ ?>
        <div class="orizzonte" role="img"
             aria-label="<?= e($segni === [] ? 'Orizzonte sgombro.' : sprintf('Giro d\'orizzonte: %d contatti in vista.', count($segni))) ?>">
          <span class="orizzonte-tacca" style="left:50%">prua</span>
          <span class="orizzonte-tacca" style="left:25%">sinistra</span>
          <span class="orizzonte-tacca" style="left:75%">dritta</span>
          <?php foreach ($segni as $s): $k = $s['k'];
            $riconosciuto = (string) $k['sensore'] === 'vista' && (float) $k['certezza'] >= 0.60
                && ($k['classe_key_est'] ?? '') !== ''; ?>
            <span class="orizzonte-segno<?= (string) $k['sensore'] === 'fumo' ? ' orizzonte-segno--fumo' : '' ?>"
                  style="left:<?= e(number_format($s['pos'], 2, '.', '')) ?>%"
                  title="<?= e(($k['classe_est'] ?? 'contatto') . ' · ' . sprintf('%03d', (int) round((float) $k['bearing']) % 360) . '°') ?>">
              <?= $riconosciuto ? partial('segnaposto', ['lega' => (string) $k['classe_key_est'], 'h' => 18]) : '<i></i>' ?>
              <b><?= e(sprintf('%03d', (int) round((float) $k['bearing']) % 360)) ?>°</b>
            </span>
          <?php endforeach; ?>
        </div>
        <p class="carta-aiuto">
          L'orizzonte intero, con la prua al centro e la poppa ai due bordi. Un segno pieno è una nave vista,
          uno sfumato è fumo all'orizzonte: la nave sotto il fumo non si vede ancora.
          <?= $segni === [] ? '<b>Orizzonte sgombro.</b>' : '' ?>
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="strumento">
      <h3>Periscopio d'osservazione</h3>
      <div class="riga"><span class="etichetta">Quota</span><span class="valore"><?= e(number_format((float) $boat['depth_m'], 0, ',', '')) ?> m</span></div>
      <div class="riga"><span class="etichetta">Periscopio</span>
        <span class="valore piccolo" data-periscopio="<?= $st['guasto'] ? 'avaria' : ($fuori ? 'fuori' : 'dentro') ?>">
          <?= $st['guasto'] ? 'in avaria' : ($fuori ? 'fuori' : 'dentro') ?>
        </span>
      </div>
      <form method="post" action="<?= e(url('/periscopio')) ?>" class="comandi">
        <?= csrf_field() ?>
        <?php if ($st['posizione'] !== Periscopio::A_QUOTA): ?>
          <button type="submit" name="azione" value="quota">Quota periscopica (12 m)</button>
        <?php elseif ($fuori): ?>
          <button type="submit" name="azione" value="abbassa"><?= partial('segnaposto', ['chiave' => 'periscopio', 'h' => 18]) ?>Abbassa periscopio</button>
        <?php else: ?>
          <button type="submit" name="azione" value="alza" <?= $st['guasto'] ? 'disabled' : '' ?>><?= partial('segnaposto', ['chiave' => 'periscopio', 'h' => 18]) ?>Alza periscopio</button>
        <?php endif; ?>
      </form>
      <p class="aiuto">
        Arrivando a quota periscopica il I.WO alza il periscopio per il giro d'orizzonte e lo lascia fuori.
        Scendendo più sotto rientra da solo.
      </p>
    </div>

    <div class="strumento" style="margin-top:1rem">
      <h3>Vedere ed essere visti</h3>
      <div class="riga"><span class="etichetta">Un mercantile grande si vede a</span><span class="valore"><?= e(number_format($portate['vediamo'], 1, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Una scorta coglierebbe la testa a</span><span class="valore"><?= e(number_format($portate['ci_vedono'], 1, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Luce · visibilità</span><span class="valore piccolo"><?= e($cielo['fase']) ?> · <?= e(number_format((float) $meteo['visibility_nm'], 1, ',', '')) ?> nm</span></div>
      <p class="aiuto">
        Dal periscopio l'orizzonte è vicino: l'occhio sta a pochi metri dall'acqua, contro i dieci della torretta.
        In cambio la testa del periscopio è una sagoma piccola, e la baffa si vede soprattutto col mare liscio
        e ad andatura sostenuta. Col periscopio dentro non si vede e non si è visti.
      </p>
    </div>
  </div>
</div>

<?php if ($fuori): ?>
<div class="pannello" style="margin-top:1.2rem">
  <h2>In vista</h2>
  <?php if ($in_vista === []): ?>
    <p class="sommario">Niente in vista. Quello che si sente e non si vede sta nella pagina dell'ascolto.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Ril.</th><th>Distanza</th><th></th><th>Che cosa</th><th>Certezza</th><th></th></tr>
      <?php foreach ($in_vista as $k):
        $riconosciuto = (string) $k['sensore'] === 'vista' && (float) $k['certezza'] >= 0.60 && ($k['classe_key_est'] ?? '') !== ''; ?>
        <tr>
          <td><?= e(sprintf('%03d', (int) round((float) $k['bearing']) % 360)) ?>°</td>
          <td><?= $k['range_nm'] !== null ? '~' . e(number_format((float) $k['range_nm'], 1, ',', '')) . ' nm' : '—' ?></td>
          <td style="width:7rem"><?= $riconosciuto ? partial('segnaposto', ['lega' => (string) $k['classe_key_est'], 'h' => 24]) : '' ?></td>
          <td><?= e($k['classe_est'] ?? '—') ?></td>
          <td><?= e(number_format(100 * (float) $k['certezza'], 0, ',', '')) ?>%</td>
          <td>
            <?php if ($k['convoy_id'] !== null || $k['ship_id'] !== null): ?>
              <form method="post" action="<?= e(url('/attacco/ingaggia')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="contatto" value="<?= e($k['id']) ?>">
                <button type="submit" class="bottone--fantasma" style="padding:.2rem .6rem;font-size:.7rem">ingaggia</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nota-segnaposto">
      Ingaggiare apre la stazione d'attacco: da lì il periscopio che si usa è quello d'attacco,
      più sottile, che il I.WO fa rientrare dopo cinque minuti.
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>
