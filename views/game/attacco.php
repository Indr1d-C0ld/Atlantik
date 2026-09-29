<?php
/** @var array $boat @var array $enc @var array $type @var list $unita @var list $tubi
 *  @var array $inventario @var array $tipi_siluro @var array $scorte @var list $siluri_in_corsa
 *  @var \App\Sim\Clock $clock @var int $now @var int $finestra_s @var int $finestra_tot_s @var list $cronaca
 *  @var array $quadro_sommario @var ?int $peri_restano */
use App\Sim\Clock;

/*
 * La stazione d'attacco, riordinata il 29/09/2026 su segnalazione del
 * proprietario («molto confusa, fatico a capire cosa bisognerebbe fare»).
 * Prima era un'unica tavola: il quadro, un riquadro di comandi, una tabella,
 * un calcolatore riempito una volta sola dal primo bersaglio dell'elenco — e
 * che non cambiava scegliendone un altro — e la cronaca in fondo alla pagina.
 *
 * Adesso segue l'ordine in cui un attacco si fa davvero, in cinque passi:
 * avvicinarsi, osservare, fare la soluzione, lanciare, sottrarsi. Ogni passo
 * dice in una riga a che cosa serve. La cronaca e i dati del battello stanno
 * sempre di lato, e la finestra di tempo reale in cima, in grande.
 *
 * Il modulo di lancio e' uno solo (id «form-lancio»), ma i suoi campi stanno
 * in tre passi diversi: la scelta del bersaglio nell'osservazione, i valori
 * nella soluzione, i tubi nel lancio. Li tiene insieme l'attributo «form» dei
 * campi, non l'annidamento: un modulo dentro l'altro (quello del periscopio,
 * quello della manovra) non si puo' fare.
 */

// Sul tavolo vanno le sedici unita' piu' vicine — un convoglio intero non ci
// sta, e quelle lontane non sono un problema di tiro — in ordine di numero di
// plottaggio: «1», «2», «3»... come sul quadro.
$bersagli = array_values(array_filter($unita, static fn (array $u): bool => $u['stato'] !== 'affondata'));
usort($bersagli, static fn (array $a, array $b): int => $a['metri'] <=> $b['metri']);
$bersagli = array_slice($bersagli, 0, 16);
usort($bersagli, static fn (array $a, array $b): int => ($a['numero'] ?? 0) <=> ($b['numero'] ?? 0));
// Si propone il mercantile piu' vicino, non il primo dell'elenco: una scorta
// o un'eco lontana non sono un bersaglio da suggerire.
$proposto = null;
foreach ($bersagli as $u) {
    if ($u['ruolo'] !== 'scorta' && ($proposto === null || $u['metri'] < $proposto['metri'])) {
        $proposto = $u;
    }
}
$proposto ??= $bersagli[0] ?? null;
$scorteVicine = array_values(array_filter($unita, static fn (array $u): bool => $u['ruolo'] === 'scorta' && $u['distanza'] < 4));

$modo = (string) $boat['mode'];
$perisu = (bool) ($boat['periscopio_alzato'] ?? 0);
$occhioFuori = $modo === 'superficie' || ($modo === 'periscopio' && $perisu);
$riconosciute = count(array_filter($bersagli, static fn (array $u): bool => (bool) $u['identificata']));
$evasione = (string) $enc['stato'] === 'evasione';

// Che cosa fare adesso, in una riga. Non decide niente: dice dove guardare.
$guida = match (true) {
    $evasione => ['allarme', 'Ci cercano. Adesso conta sottrarsi: profondità, marcia silenziosa, Bold (passo 5). Il lancio può aspettare.'],
    $bersagli === [] => ['', 'Niente sul tavolo. Avvicinati sul rilevamento dell\'ultimo contatto (passo 1), poi sali a quota periscopica e guarda.'],
    $modo === 'immersione' => ['', 'Troppo profondi per vedere: sali a quota periscopica (passo 1) e alza il periscopio (passo 2).'],
    $modo === 'periscopio' && !$perisu => ['', 'Alza il periscopio (passo 2): a orecchio distanza, angolo e velocità sono stime grossolane.'],
    $tubi === [] => ['attenzione', 'Nessun tubo pronto: i siluristi stanno ricaricando. Intanto tieni il contatto e resta nascosto.'],
    $proposto !== null && $proposto['metri'] > 3000 => ['', sprintf(
        'Il bersaglio più vicino è a circa %s m: a quella distanza un errore di un nodo è un colpo a vuoto. Avvicinati sotto i 2.000 m (passo 1).',
        number_format($proposto['metri'], 0, ',', '.'))],
    default => ['', 'Scegli il bersaglio (passo 2), controlla la soluzione (passo 3), scegli i tubi e lancia (passo 4).'],
};
$tre = static fn (float $g): string => sprintf('%03d', (int) round($g) % 360);
// Come si chiama la scelta nelle righe «Valori per...» e «Su...»: il nome se si
// legge, altrimenti la classe col numero di plottaggio. «Piroscafo da carico
// medio» da solo non dice quale: nel convoglio ce ne sono cinque.
$chiama = static fn (array $u): string => $u['nome_noto'] || str_contains((string) $u['breve'], '«')
    ? (string) $u['breve']
    : sprintf('%s «%d»', $u['breve'], (int) ($u['numero'] ?? 0));
?>
<?= partial('nav_plancia', ['attiva' => 'attacco']) ?>

<div class="intestazione-battello">
  <span class="numero"><?= e($boat['uboat_number']) ?></span>
  <span class="tipo">
    <?php if ($evasione): ?>
      <b style="color:var(--rosso)">EVASIONE</b> — ci stanno cercando
    <?php elseif ((string) $enc['stato'] === 'attacco'): ?>
      <b style="color:var(--ambra)">ATTACCO</b>
    <?php else: ?>
      Avvicinamento
    <?php endif; ?>
  </span>
  <span class="ora" data-campo="ora"><?= e($clock->formatDiario($now)) ?></span>
</div>

<?php $quota = $finestra_tot_s > 0 ? max(0.0, min(1.0, $finestra_s / $finestra_tot_s)) : 0.0; ?>
<div class="finestra-attacco<?= $finestra_s < 300 ? ' finestra-attacco--poca' : '' ?>" id="finestra-attacco"
     data-finestra="<?= (int) $finestra_s ?>" data-finestra-tot="<?= (int) $finestra_tot_s ?>">
  <div class="finestra-riga">
    <span>Finestra di condotta</span>
    <b data-campo="finestra"><?= e(Clock::durata($finestra_s)) ?></b>
    <span class="finestra-nota">di tempo reale. Scaduta, prende il Primo Ufficiale e disimpegna.</span>
  </div>
  <div class="misuratore<?= $finestra_s < 300 ? ' allarme' : ($finestra_s < 600 ? ' attenzione' : '') ?>">
    <i data-campo="finestra-barra" style="width:<?= e(number_format($quota * 100, 1, '.', '')) ?>%"></i>
  </div>
  <p class="guida guida--<?= e($guida[0] !== '' ? $guida[0] : 'normale') ?>"><b>Adesso:</b> <?= e($guida[1]) ?></p>
</div>

<nav class="passi-attacco" aria-label="Passi dell'attacco">
  <a href="#avvicinamento"><i>1</i> Avvicinamento</a>
  <a href="#osservazione"><i>2</i> Osservazione</a>
  <a href="#soluzione"><i>3</i> Soluzione</a>
  <a href="#lancio"><i>4</i> Lancio</a>
  <a href="#evasione" class="<?= $evasione ? 'urgente' : '' ?>"><i>5</i> Evasione</a>
</nav>

<div class="griglia-attacco">
  <div class="attacco-principale">

    <!-- 1. Avvicinamento --------------------------------------------------- -->
    <section class="pannello passo" id="avvicinamento">
      <h2><i>1</i> Avvicinamento</h2>
      <p class="passo-scopo">
        Portarsi davanti al bersaglio, di lato alla sua rotta, e aspettarlo lì. In immersione il battello fa
        sette nodi al massimo: un convoglio che ne fa nove, da dietro, non lo si raggiunge mai.
      </p>
      <div class="tavolo">
        <?php /* La plotta dell'attacco: bersagli, scorte e siluri in acqua. Chi
           non la vede trova gli stessi bersagli nell'elenco del passo 2. */ ?>
        <canvas id="plotta" role="img"
                aria-label="<?= e(sprintf(
                  'Plotta dell\'attacco: %d unita\' in vista, prua %03d gradi. L\'elenco dei bersagli sta al passo 2.',
                  count($unita), (int) round((float) $boat['heading'])
                )) ?>"
                data-plotta="<?= e(json_encode([
          'battello' => ['lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'], 'rotta' => (float) $boat['heading']],
          'unita'    => $unita,
          'siluri'   => array_map(static fn (array $r): array => [
              'lat' => (float) $r['lat'], 'lon' => (float) $r['lon'], 'rotta' => (float) $r['heading'],
          ], $siluri_in_corsa),
        ], JSON_UNESCAPED_UNICODE)) ?>"></canvas>
        <p class="carta-aiuto">
          Il battello al centro, la prua in alto; i cerchi sono a 1, 2 e 4 miglia, la rotella cambia la scala.
          Il numero accanto a ogni segno è quello del tavolo al passo 2, e <b>un clic su un segno lo sceglie come
          bersaglio</b>. Le scorte diventano rosse quando hanno un contatto su di noi; i cerchietti sono unità
          solo sentite, di cui si sa il rilevamento e poco altro. È il tavolo di plottaggio, non la verità.
        </p>
      </div>

      <form method="post" action="<?= e(url('/attacco/manovra')) ?>" class="manovra">
        <?= csrf_field() ?>
        <label>Rotta <input type="text" name="rotta" placeholder="<?= e($tre((float) $boat['heading'])) ?>" inputmode="numeric"></label>
        <label>Nodi <input type="text" name="speed" placeholder="<?= e(number_format((float) $boat['speed_kn'], 1, ',', '')) ?>" inputmode="decimal"></label>
        <label>Quota (m) <input type="text" name="depth" placeholder="<?= e(number_format((float) $boat['ordered_depth_m'], 0, ',', '')) ?>" inputmode="numeric"></label>
        <button type="submit">Ordina</button>
      </form>
      <form method="post" action="<?= e(url('/attacco/manovra')) ?>" class="comandi">
        <?= csrf_field() ?>
        <button type="submit" name="depth" value="0">Emersione</button>
        <button type="submit" name="depth" value="12">Quota periscopica (12 m)</button>
        <button type="submit" name="depth" value="60">60 m</button>
      </form>
      <p class="aiuto">
        In superficie si va più veloci e si vede di più, ma ci vedono. A quota periscopica si guarda
        col periscopio (passo 2). Sotto, si ascolta soltanto.
      </p>
    </section>

    <!-- 2. Osservazione ---------------------------------------------------- -->
    <section class="pannello passo" id="osservazione">
      <h2><i>2</i> Osservazione</h2>
      <p class="passo-scopo">
        Guardare il bersaglio per stimarne distanza, angolo sulla prua e velocità. Col periscopio alzato le stime
        sono buone; a orecchio sono grossolane. Scegli qui il bersaglio: il calcolatore del passo 3 si riempie da solo.
      </p>

      <div class="periscopio-attacco">
        <form method="post" action="<?= e(url('/attacco/periscopio')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="stato" value="<?= $perisu ? 'abbassa' : 'alza' ?>">
          <button type="submit" <?= $modo !== 'periscopio' && !$perisu ? 'disabled' : '' ?>
                  title="Alzato si vede il bersaglio, ma sul mare liscio la corsa lascia una baffa: la nostra sagoma per una vedetta triplica.">
            <?= partial('segnaposto', ['chiave' => 'periscopio', 'h' => 18]) ?><?= $perisu ? 'Abbassa periscopio' : 'Alza periscopio' ?>
          </button>
        </form>
        <span class="piccolo">
          <?php if ($modo === 'superficie'): ?>
            In superficie si guarda dalla torretta: il periscopio non serve.
          <?php elseif ($modo !== 'periscopio'): ?>
            A <?= e(number_format((float) $boat['depth_m'], 0, ',', '')) ?> m il periscopio non arriva: sali a quota periscopica.
          <?php elseif ($perisu): ?>
            Fuori<?= $peri_restano !== null ? e(sprintf(' — il I.WO lo fa rientrare fra %s', Clock::durata($peri_restano))) : '' ?>.
            Ogni minuto fuori è un minuto in cui la baffa si può vedere.
          <?php else: ?>
            Dentro: ciechi, e invisibili.
          <?php endif; ?>
        </span>
      </div>

      <div class="tavola-scorre">
      <table class="dati bersagli" id="tavola-bersagli">
        <tr><th></th><th>N.</th><th>Bersaglio</th><th></th><th>Ril.</th><th>Distanza</th><th>AOB</th><th>Vel.</th></tr>
        <?php foreach ($bersagli as $u): ?>
          <tr data-unita="<?= e($u['id']) ?>" class="<?= $u['ruolo'] === 'scorta' ? 'scorta' : '' ?>">
            <td>
              <input type="radio" name="bersaglio" value="<?= e($u['id']) ?>" form="form-lancio"
                     aria-label="<?= e('Bersaglio ' . $chiama($u)) ?>"
                     data-aob="<?= e($u['aob']) ?>" data-metri="<?= e($u['metri']) ?>"
                     data-vel="<?= e(number_format($u['velocita'], 1, '.', '')) ?>" data-breve="<?= e($chiama($u)) ?>"
                     <?= $proposto !== null && $u['id'] === $proposto['id'] ? ' checked' : '' ?>>
            </td>
            <td class="numero-plot"><?= e($u['numero']) ?></td>
            <td>
              <?= e($u['nome']) ?>
              <?php // La classe e' gia' nel nome quando il nome non si legge: la si
              // aggiunge solo accanto a un nome vero («HMS Vimy, cacciatorpediniere»).
              $dettaglio = array_filter([
                  $u['ruolo'] === 'scorta' ? 'scorta' : null,
                  $u['nome_noto'] && $u['classe'] !== '' ? $u['classe'] : null,
                  $u['grt'] > 0 ? '~' . number_format($u['grt'], 0, ',', '.') . ' GRT' : null,
              ]); ?>
              <span class="come<?= $u['osservazione'] === 'vista' ? '' : ' come--ascolto' ?>">
                <?= $u['osservazione'] === 'vista'
                    ? ($u['identificata'] ? 'riconosciuta' : 'sagoma')
                    : 'all\'ascolto' ?><?= $dettaglio !== [] ? ' · ' . e(implode(', ', $dettaglio)) : '' ?>
              </span>
            </td>
            <td class="sagoma-plot"><?= $u['identificata'] ? partial('segnaposto', ['lega' => (string) ($u['classe_key'] ?? ''), 'h' => 20]) : '' ?></td>
            <td data-c="ril"><?= e($tre($u['rilevamento'])) ?>°</td>
            <td data-c="metri">~<?= e(number_format($u['metri'], 0, ',', '.')) ?> m</td>
            <td data-c="aob"><?= e($u['aob']) ?>° <?= e($u['aob_lato']) ?></td>
            <td data-c="vel"><?= e(number_format($u['velocita'], 1, ',', '')) ?> kn</td>
          </tr>
        <?php endforeach; ?>
        <?php if ($bersagli === []): ?>
          <tr><td colspan="8" style="color:var(--testo-3)">Niente sul tavolo: non vediamo e non sentiamo nessuno.</td></tr>
        <?php endif; ?>
      </table>
      </div>
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
      <?php if ($scorteVicine !== []): ?>
        <h3 class="sottotitolo">Scorte vicine</h3>
        <?php foreach ($scorteVicine as $s): ?>
          <div class="riga">
            <span class="etichetta"><?= e($s['breve']) ?></span>
            <span class="valore piccolo" style="color:<?= $s['contatto'] > 0.4 ? 'var(--rosso)' : ($s['contatto'] > 0.1 ? 'var(--ambra)' : 'var(--testo-2)') ?>">
              <?= e(number_format($s['metri'], 0, ',', '.')) ?> m · ril <?= e($tre($s['rilevamento'])) ?>°
              <?= $s['contatto'] > 0.4 ? ' · CI HA' : ($s['contatto'] > 0.1 ? ' · cerca' : '') ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- 3. Soluzione ------------------------------------------------------- -->
    <section class="pannello passo" id="soluzione">
      <h2><i>3</i> Soluzione — Calcolatore di lancio (Vorhaltrechner)</h2>
      <p class="passo-scopo">
        Il calcolatore trova l'angolo di mira a partire da tre valori: angolo sulla prua del bersaglio (AOB),
        distanza e velocità. Li mette il Primo Ufficiale dalle stime del passo 2; se hai fatto il punto tu,
        correggili. Un errore di due nodi, a duemila metri, è un colpo mancato.
      </p>
      <p class="soluzione-per" id="soluzione-per">
        <?= $proposto !== null ? 'Valori per ' . e($chiama($proposto)) . '.' : 'Nessun bersaglio scelto.' ?>
      </p>
      <div class="calcolatore">
        <div class="campo">
          <label for="c-aob">AOB (gradi)</label>
          <input type="text" id="c-aob" name="aob" form="form-lancio" value="<?= e($proposto !== null ? (string) $proposto['aob'] : '90') ?>" inputmode="numeric">
          <span class="aiuto">90° = ci mostra il fianco</span>
        </div>
        <div class="campo">
          <label for="c-dist">Distanza (metri)</label>
          <input type="text" id="c-dist" name="distanza" form="form-lancio" value="<?= e($proposto !== null ? (string) $proposto['metri'] : '1500') ?>" inputmode="numeric">
        </div>
        <div class="campo">
          <label for="c-vel">Velocità (nodi)</label>
          <input type="text" id="c-vel" name="velocita" form="form-lancio" value="<?= e($proposto !== null ? number_format($proposto['velocita'], 1, '.', '') : '9') ?>" inputmode="decimal">
        </div>
        <div class="campo">
          <label for="c-quota">Quota corsa (m)</label>
          <input type="text" id="c-quota" name="quota" form="form-lancio" value="4" inputmode="numeric">
          <span class="aiuto">sotto la chiglia, non sotto la linea d'acqua</span>
        </div>
        <div class="campo">
          <label for="c-spoletta">Spoletta</label>
          <select id="c-spoletta" name="spoletta" form="form-lancio">
            <option value="contatto">a contatto</option>
            <option value="magnetica">magnetica</option>
          </select>
        </div>
        <div class="campo">
          <label for="c-vent">Ventaglio (°)</label>
          <input type="text" id="c-vent" name="ventaglio" form="form-lancio" value="1.5" inputmode="decimal">
          <span class="aiuto">apertura fra i siluri di una salva</span>
        </div>
      </div>
      <p class="aiuto">
        Scegliendo un altro bersaglio i tre valori si ricaricano, anche quelli che hai corretto a mano.
      </p>
    </section>

    <!-- 4. Lancio ---------------------------------------------------------- -->
    <section class="pannello passo" id="lancio">
      <h2><i>4</i> Lancio</h2>
      <p class="passo-scopo">
        Si lancia a quota periscopica o in superficie. Un tubo solo per un colpo preciso, due o più a ventaglio
        quando la soluzione è incerta.
      </p>
      <?php if ($tubi === []): ?>
        <p class="sommario">Nessun tubo pronto: si ricarica, e ci vuole il suo tempo.</p>
      <?php else: ?>
        <form method="post" action="<?= e(url('/attacco/lancia')) ?>" id="form-lancio">
          <?= csrf_field() ?>
          <fieldset class="tubi">
            <legend>Tubi pronti</legend>
            <?php foreach ($tubi as $t): ?>
              <label>
                <input type="checkbox" name="tubi[]" value="<?= e($t['tubo']) ?>">
                n. <?= e($t['tubo']) ?> <span style="color:var(--testo-3)"><?= e($tipi_siluro[$t['tkey']]['sigla'] ?? $t['tkey']) ?></span>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <div class="azioni">
            <button type="submit">Lanciare!</button>
            <span class="aiuto" style="margin:0" id="riepilogo-lancio">
              <?= $proposto !== null ? 'Su ' . e($chiama($proposto)) . ', con i valori del passo 3.' : '' ?>
            </span>
          </div>
        </form>
      <?php endif; ?>

      <?php if (!empty($type['deck_gun'])): ?>
        <h3 class="sottotitolo">Cannone di coperta — <?= e($type['deck_gun']) ?></h3>
        <form method="post" action="<?= e(url('/attacco/cannone')) ?>" class="cannone">
          <?= csrf_field() ?>
          <div class="campo">
            <label for="g-bersaglio">Bersaglio</label>
            <select id="g-bersaglio" name="bersaglio">
              <?php foreach ($bersagli as $u): ?>
                <option value="<?= e($u['id']) ?>"<?= $proposto !== null && $u['id'] === $proposto['id'] ? ' selected' : '' ?>><?= e($chiama($u)) ?> — <?= e(number_format($u['metri'], 0, ',', '.')) ?> m</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="campo">
            <label for="g-colpi">Colpi</label>
            <input type="text" id="g-colpi" name="colpi" value="6" inputmode="numeric">
          </div>
          <button type="submit" <?= $modo !== 'superficie' ? 'disabled title="Il cannone si usa in superficie."' : '' ?>>Fuoco</button>
          <span class="aiuto" style="margin:0">Munizioni: <?= e(number_format((float) ($scorte['munizioni_cannone'] ?? 0), 0)) ?>. Solo in superficie, mare fino a forza 4: contro chi non risponde al fuoco.</span>
        </form>
      <?php endif; ?>
    </section>

    <!-- 5. Evasione -------------------------------------------------------- -->
    <section class="pannello passo<?= $evasione ? ' passo--urgente' : '' ?>" id="evasione">
      <h2><i>5</i> Evasione</h2>
      <p class="passo-scopo">
        Dopo il lancio, o appena una scorta ci prende: sotto, lenti, in silenzio. Il Bold è un'esca di bolle
        che l'ASDIC scambia per noi, per qualche minuto.
      </p>
      <form method="post" action="<?= e(url('/attacco/manovra')) ?>" class="comandi">
        <?= csrf_field() ?>
        <button type="submit" name="depth" value="<?= e((string) min(150, (int) $type['test_depth_m'] + 40)) ?>">Profondo (<?= e((string) min(150, (int) $type['test_depth_m'] + 40)) ?> m)</button>
        <button type="submit" name="silent" value="<?= (int) $boat['silent'] === 1 ? '0' : '1' ?>">
          <?= (int) $boat['silent'] === 1 ? 'Fine marcia silenziosa' : 'Marcia silenziosa' ?>
        </button>
      </form>
      <div class="comandi">
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
      <p class="aiuto">
        Disimpegnare chiude l'attacco e riporta alla navigazione: il convoglio resta nei contatti, finché lo si tiene.
      </p>
    </section>
  </div>

  <aside class="attacco-lato">
    <div class="strumento">
      <h3>Battello</h3>
      <div class="riga"><span class="etichetta">Quota</span><span class="valore grande" data-campo="quota"><?= e(number_format((float) $boat['depth_m'], 0, ',', '')) ?> m</span></div>
      <div class="riga"><span class="etichetta">Velocità · rotta</span><span class="valore piccolo"><span data-campo="velocita"><?= e(number_format((float) $boat['speed_kn'], 1, ',', '')) ?></span> kn · <span data-campo="rotta"><?= e($tre((float) $boat['heading'])) ?></span>°</span></div>
      <div class="riga"><span class="etichetta">Batteria · aria</span><span class="valore piccolo"><span data-campo="batteria"><?= e(number_format((float) $boat['battery_pct'], 0, ',', '')) ?></span>% · <span data-campo="aria"><?= e(number_format((float) $boat['air_pct'], 0, ',', '')) ?></span>%</span></div>
      <div class="riga" title="La pressione che lo scafo sta sopportando adesso, non i danni: 0% è riposo, 100% è il limite.">
        <span class="etichetta">Sollecitazione scafo</span><span class="valore piccolo"><span data-campo="stress"><?= e(number_format((float) $boat['hull_stress'], 0, ',', '')) ?></span>%</span>
      </div>
      <div class="riga"><span class="etichetta">Siluri</span><span class="valore piccolo"><?= e($inventario['tubi']) ?> nei tubi, <?= e($inventario['riserve']) ?> in riserva<?php
        if (($inventario['esterni'] ?? 0) > 0): ?> (<?= e($inventario['esterni']) ?> in coperta)<?php endif; ?></span></div>
      <?php if (($inventario['in_carica'] ?? 0) > 0): ?>
        <div class="riga"><span class="etichetta">In carica</span><span class="valore piccolo"><?= e($inventario['in_carica']) ?> fra le mani dei siluristi</span></div>
      <?php endif; ?>
      <?php if (($inventario['guasti'] ?? 0) > 0): ?>
        <div class="riga"><span class="etichetta">Guasti</span><span class="valore piccolo attenzione"><?= e($inventario['guasti']) ?> inservibili</span></div>
      <?php endif; ?>
    </div>

    <div class="strumento cronaca-lato">
      <h3>Cronaca</h3>
      <ul class="ktb" id="cronaca-viva">
        <?php foreach ($cronaca as $e): ?>
          <li><span class="ora"><?= e($clock->formatDiario((int) $e['gts'])) ?></span><span class="testo"><?= e($e['text']) ?></span></li>
        <?php endforeach; ?>
        <?php if ($cronaca === []): ?>
          <li class="vuoto">Ancora niente da scrivere.</li>
        <?php endif; ?>
      </ul>
    </div>
  </aside>
</div>

<script src="<?= e(asset('js/attacco.js')) ?>" defer></script>
