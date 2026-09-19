<?php
/** @var array $boat @var array $enc @var array $type @var list $unita @var list $tubi
 *  @var array $inventario @var array $tipi_siluro @var array $scorte @var list $siluri_in_corsa
 *  @var \App\Sim\Clock $clock @var int $now @var int $finestra_s @var list $cronaca
 *  @var array $quadro_sommario */
use App\Sim\Clock;

$bersagli = array_values(array_filter($unita, static fn (array $u): bool => $u['stato'] !== 'affondata'));
$primo = $bersagli[0] ?? null;
$scorteVicine = array_values(array_filter($unita, static fn (array $u): bool => $u['ruolo'] === 'scorta' && $u['distanza'] < 4));
?>
<?= partial('nav_plancia', ['attiva' => 'attacco']) ?>

<div class="intestazione-battello">
  <span class="numero"><?= e($boat['uboat_number']) ?></span>
  <span class="tipo">
    <?php if ((string) $enc['stato'] === 'evasione'): ?>
      <b style="color:var(--rosso)">EVASIONE</b> — ci stanno cercando
    <?php elseif ((string) $enc['stato'] === 'attacco'): ?>
      <b style="color:var(--ambra)">ATTACCO</b>
    <?php else: ?>
      Avvicinamento
    <?php endif; ?>
  </span>
  <span class="ora" data-campo="ora"><?= e($clock->formatDiario($now)) ?></span>
</div>

<div class="avviso <?= $finestra_s < 300 ? 'avviso--attenzione' : '' ?>">
  Finestra di condotta: <b data-campo="finestra"><?= e(Clock::durata($finestra_s)) ?></b> di tempo reale.
  Scaduta, prende il Primo Ufficiale e disimpegna.
</div>

<div class="griglia-attacco">
  <div class="tavolo">
    <canvas id="plotta" data-plotta="<?= e(json_encode([
      'battello' => ['lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'], 'rotta' => (float) $boat['heading']],
      'unita'    => $unita,
      'siluri'   => array_map(static fn (array $r): array => [
          'lat' => (float) $r['lat'], 'lon' => (float) $r['lon'], 'rotta' => (float) $r['heading'],
      ], $siluri_in_corsa),
    ], JSON_UNESCAPED_UNICODE)) ?>"></canvas>
    <p class="carta-aiuto">
      Quadro tattico: il battello al centro, la prua in alto. I cerchi sono a 1, 2 e 4 miglia.
      Le scorte sono in rosso quando hanno un contatto su di noi. I <b>cerchietti</b> sono
      contatti che non si sono visti: rilevamento e distanza stimata, niente altro. Questo
      e' il tavolo di plottaggio, non la verità.
    </p>
  </div>

  <div>
    <div class="strumento">
      <h3>Battello</h3>
      <div class="riga"><span class="etichetta">Quota</span><span class="valore grande" data-campo="quota"><?= e(number_format((float) $boat['depth_m'], 0, ',', '')) ?> m</span></div>
      <div class="riga"><span class="etichetta">Velocità / rotta</span><span class="valore piccolo"><span data-campo="velocita"><?= e(number_format((float) $boat['speed_kn'], 1, ',', '')) ?></span> kn · <span data-campo="rotta"><?= e(str_pad(number_format((float) $boat['heading'], 0, ',', ''), 3, '0', STR_PAD_LEFT)) ?></span>°</span></div>
      <div class="riga"><span class="etichetta">Batteria / aria</span><span class="valore piccolo"><span data-campo="batteria"><?= e(number_format((float) $boat['battery_pct'], 0, ',', '')) ?></span>% · <span data-campo="aria"><?= e(number_format((float) $boat['air_pct'], 0, ',', '')) ?></span>%</span></div>
      <div class="riga"><span class="etichetta">Scafo</span><span class="valore piccolo"><span data-campo="stress"><?= e(number_format((float) $boat['hull_stress'], 0, ',', '')) ?></span>%</span></div>
      <div class="riga"><span class="etichetta">Siluri</span><span class="valore piccolo"><?= e($inventario['tubi']) ?> nei tubi, <?= e($inventario['riserve']) ?> in riserva</span></div>

      <form method="post" action="<?= e(url('/attacco/manovra')) ?>" style="margin-top:.7rem">
        <?= csrf_field() ?>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          <input type="text" name="rotta" placeholder="rotta" style="width:4.5rem;padding:.3rem" inputmode="numeric">
          <input type="text" name="speed" placeholder="nodi" style="width:4.5rem;padding:.3rem" inputmode="decimal">
          <input type="text" name="depth" placeholder="quota" style="width:4.5rem;padding:.3rem" inputmode="numeric">
          <button type="submit" style="padding:.35rem .7rem;font-size:.72rem">Ordina</button>
        </div>
      </form>
      <div class="comandi">
        <form method="post" action="<?= e(url('/attacco/manovra')) ?>" style="display:contents">
          <?= csrf_field() ?>
          <button type="submit" name="depth" value="12">Periscopio</button>
          <button type="submit" name="depth" value="60">60 m</button>
          <button type="submit" name="depth" value="<?= e((string) min(150, (int) $type['test_depth_m'] + 40)) ?>">Profondo</button>
          <button type="submit" name="silent" value="<?= (int) $boat['silent'] === 1 ? '0' : '1' ?>">
            <?= (int) $boat['silent'] === 1 ? 'Fine silenzio' : 'Silenziosa' ?>
          </button>
        </form>
      </div>
      <div class="comandi">
        <?php $perisu = (bool) ($boat['periscopio_alzato'] ?? 0); ?>
        <form method="post" action="<?= e(url('/attacco/periscopio')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="stato" value="<?= $perisu ? 'abbassa' : 'alza' ?>">
          <button type="submit" <?= (string) $boat['mode'] !== 'periscopio' && !$perisu ? 'disabled' : '' ?>
                  title="Alzato si vede il bersaglio, ma sul mare liscio la corsa lascia una baffa: la nostra sagoma per una vedetta triplica.">
            <?= partial('segnaposto', ['chiave' => 'periscopio', 'h' => 18]) ?><?= $perisu ? 'Abbassa periscopio' : 'Alza periscopio' ?>
          </button>
        </form>
        <form method="post" action="<?= e(url('/attacco/bold')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" <?= ((float) ($scorte['bold'] ?? 0)) < 1 ? 'disabled' : '' ?>>
            Bold (<?= e(number_format((float) ($scorte['bold'] ?? 0), 0)) ?>)
          </button>
        </form>
        <form method="post" action="<?= e(url('/attacco/disimpegna')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <button type="submit" class="allarme">Disimpegna</button>
        </form>
      </div>
    </div>

    <?php if ($scorteVicine !== []): ?>
    <div class="strumento" style="margin-top:1rem">
      <h3>Scorte vicine</h3>
      <?php foreach ($scorteVicine as $s): ?>
        <div class="riga">
          <span class="etichetta"><?= e($s['nome']) ?></span>
          <span class="valore piccolo" style="color:<?= $s['contatto'] > 0.4 ? 'var(--rosso)' : ($s['contatto'] > 0.1 ? 'var(--ambra)' : 'var(--testo-2)') ?>">
            <?= e(number_format($s['metri'], 0, ',', '.')) ?> m · ril <?= e(str_pad((string) round($s['rilevamento']), 3, '0', STR_PAD_LEFT)) ?>
            <?= $s['contatto'] > 0.4 ? ' · CI HA' : ($s['contatto'] > 0.1 ? ' · cerca' : '') ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="pannello" style="margin-top:1.2rem">
  <h2>Calcolatore di lancio (Vorhaltrechner)</h2>
  <?php if ($tubi === []): ?>
    <p class="sommario">Nessun tubo pronto: si ricarica, e ci vuole il suo tempo.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url('/attacco/lancia')) ?>">
    <?= csrf_field() ?>
    <table class="dati">
      <?php
      // Sul tavolo ci va quello che qualcuno ha visto o sentito, non la verita'.
      // La sagoma compare solo per le unita' RICONOSCIUTE: vederle non basta,
      // bisogna essere abbastanza vicini — e con abbastanza luce — da
      // distinguere i dettagli che fanno la classe.
      $occhioFuori = (string) $boat['mode'] === 'superficie'
          || ((string) $boat['mode'] === 'periscopio' && (bool) ($boat['periscopio_alzato'] ?? 0));
      $riconosciute = count(array_filter($bersagli, static fn (array $u): bool => (bool) $u['identificata']));
      ?>
      <tr><th></th><th>Bersaglio</th><th></th><th>Ril.</th><th>Distanza</th><th>AOB</th><th>Vel. stimata</th><th>Come</th></tr>
      <?php foreach (array_slice($bersagli, 0, 12) as $i => $u): ?>
        <tr>
          <td><input type="radio" name="bersaglio" value="<?= e($u['id']) ?>"<?= $i === 0 ? ' checked' : '' ?>></td>
          <td>
            <?= e($u['nome']) ?>
            <?php $dettaglio = array_filter([
                $u['classe'] !== '' ? $u['classe'] : null,
                $u['grt'] > 0 ? '~' . number_format($u['grt'], 0, ',', '.') . ' GRT' : null,
            ]); ?>
            <?php if ($dettaglio !== []): ?>
              <span style="color:var(--testo-3)"><?= e(implode(', ', $dettaglio)) ?></span>
            <?php endif; ?>
          </td>
          <td style="width:7rem"><?= $u['identificata'] ? partial('segnaposto', ['lega' => (string) ($u['classe_key'] ?? ''), 'h' => 24]) : '' ?></td>
          <td><?= e(str_pad((string) round($u['rilevamento']), 3, '0', STR_PAD_LEFT)) ?>°</td>
          <td>~<?= e(number_format($u['metri'], 0, ',', '.')) ?> m</td>
          <td><?= e($u['aob']) ?>° <?= e($u['aob_lato']) ?></td>
          <td><?= e(number_format($u['velocita'], 1, ',', '')) ?> kn</td>
          <td style="color:<?= $u['osservazione'] === 'vista' ? 'var(--testo-2)' : 'var(--testo-3)' ?>">
            <?= $u['osservazione'] === 'vista'
                ? ($u['identificata'] ? 'riconosciuta' : 'sagoma')
                : 'all\'ascolto' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bersagli === []): ?>
        <tr><td colspan="8" style="color:var(--testo-3)">Niente sul tavolo: non vediamo e non sentiamo nessuno.</td></tr>
      <?php endif; ?>
    </table>
    <?php $nascoste = (int) ($quadro_sommario['nascoste'] ?? 0); ?>
    <?php if ($nascoste > 0): ?>
      <p class="sommario" style="color:var(--ambra)">
        Il Funkmaat: «<?= $nascoste >= 6 ? 'Molte eliche, Herr Kaleun. Rumore di massa' : 'Altre eliche' ?>
        — <?= e((string) $nascoste) ?> unità che non riesco a separare.» Sul tavolo non si segna
        quello che non si distingue.
      </p>
    <?php endif; ?>
    <p class="nota-segnaposto">
      <?php if (!$occhioFuori): ?>
        Periscopio abbassato: si pedina a orecchio. Il Funkmaat dà il rilevamento e poco
        altro; distanza, angolo e velocità sono <b>stime grossolane</b>, e nessuna sagoma
        si riconosce a orecchio.
      <?php elseif ($riconosciute === 0): ?>
        Si vedono sagome, non navi. Per dire di che classe sono — e per leggerne il nome —
        bisogna avvicinarsi: <b>riconoscere costa distanza, luce e rischio</b>.
      <?php else: ?>
        Le sagome compaiono solo per le unità <b>riconosciute</b>. Il nome si legge da vicino
        e con la luce: quando non si legge, il plottaggio le dà un numero — ed è così che
        finiscono nel giornale, «Dampfer ca. 6000 BRT», col nome aggiunto dopo dal BdU.
      <?php endif; ?>
      Tutti i numeri qui sopra sono <b>stime</b>, col loro errore: è per questo che il
      calcolatore esiste, ed è per questo che si sbaglia.
    </p>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;align-items:flex-end">
      <div class="campo" style="margin:0;max-width:8rem">
        <label for="c-aob">AOB (gradi)</label>
        <input type="text" id="c-aob" name="aob" value="<?= e($primo !== null ? (string) $primo['aob'] : '90') ?>" inputmode="numeric">
      </div>
      <div class="campo" style="margin:0;max-width:9rem">
        <label for="c-dist">Distanza (metri)</label>
        <input type="text" id="c-dist" name="distanza" value="<?= e($primo !== null ? (string) $primo['metri'] : '1500') ?>" inputmode="numeric">
      </div>
      <div class="campo" style="margin:0;max-width:8rem">
        <label for="c-vel">Velocità (nodi)</label>
        <input type="text" id="c-vel" name="velocita" value="<?= e($primo !== null ? number_format($primo['velocita'], 1, '.', '') : '9') ?>" inputmode="decimal">
      </div>
      <div class="campo" style="margin:0;max-width:8rem">
        <label for="c-quota">Quota corsa (m)</label>
        <input type="text" id="c-quota" name="quota" value="4" inputmode="numeric">
      </div>
      <div class="campo" style="margin:0">
        <label for="c-spoletta">Spoletta</label>
        <select id="c-spoletta" name="spoletta" style="padding:.55rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:1rem var(--mono)">
          <option value="contatto">a contatto</option>
          <option value="magnetica">magnetica</option>
        </select>
      </div>
      <div class="campo" style="margin:0;max-width:8rem">
        <label for="c-vent">Ventaglio (°)</label>
        <input type="text" id="c-vent" name="ventaglio" value="1.5" inputmode="decimal">
      </div>
    </div>

    <div style="margin-top:.9rem">
      <label>Tubi</label>
      <div style="display:flex;gap:.8rem;flex-wrap:wrap">
        <?php foreach ($tubi as $t): ?>
          <label style="display:flex;align-items:center;gap:.35rem;font:.85rem var(--mono);color:var(--testo-2);text-transform:none;letter-spacing:0">
            <input type="checkbox" name="tubi[]" value="<?= e($t['tubo']) ?>">
            n. <?= e($t['tubo']) ?> <span style="color:var(--testo-3)"><?= e($tipi_siluro[$t['tkey']]['sigla'] ?? $t['tkey']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="azioni">
      <button type="submit">Lanciare!</button>
      <span class="aiuto" style="margin:0">
        I valori sono la stima del Primo Ufficiale: correggili se hai fatto il punto tu.
        Un errore di due nodi sulla velocità, a duemila metri, è un colpo mancato.
      </span>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($type['deck_gun'])): ?>
<div class="pannello">
  <h2>Cannone di coperta — <?= e($type['deck_gun']) ?></h2>
  <form method="post" action="<?= e(url('/attacco/cannone')) ?>" style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:flex-end">
    <?= csrf_field() ?>
    <div class="campo" style="margin:0">
      <label for="g-bersaglio">Bersaglio</label>
      <select id="g-bersaglio" name="bersaglio" style="padding:.55rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo)">
        <?php foreach (array_slice($bersagli, 0, 12) as $u): ?>
          <option value="<?= e($u['id']) ?>"><?= e($u['nome']) ?> — <?= e(number_format($u['metri'], 0, ',', '.')) ?> m</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo" style="margin:0;max-width:7rem">
      <label for="g-colpi">Colpi</label>
      <input type="text" id="g-colpi" name="colpi" value="6" inputmode="numeric">
    </div>
    <button type="submit">Fuoco</button>
    <span class="aiuto" style="margin:0">Munizioni: <?= e(number_format((float) ($scorte['munizioni_cannone'] ?? 0), 0)) ?>. Solo in superficie, mare fino a forza 4.</span>
  </form>
</div>
<?php endif; ?>

<div class="pannello">
  <h2>Cronaca</h2>
  <ul class="ktb" id="cronaca-viva">
    <?php foreach ($cronaca as $e): ?>
      <li><span class="ora"><?= e($clock->formatDiario((int) $e['gts'])) ?></span><span class="testo"><?= e($e['text']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</div>

<script src="<?= e(asset('js/attacco.js')) ?>" defer></script>
