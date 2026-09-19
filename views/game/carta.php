<?php
/** @var array $boat @var array $type @var array $rotta @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\World;

$porti = [];
foreach (World::ports() as $p) {
    $porti[] = [
        'nome' => $p['name'],
        'lat'  => (float) $p['lat'],
        'lon'  => (float) $p['lon'],
        'base' => (string) $p['kind'] === 'base',
    ];
}

// Sigle dei grandi quadrati: si stampano sulla carta come su quelle di bordo.
$quadrati = [];
foreach (Grid::table() as $sigla => $q) {
    $quadrati[] = ['sigla' => $sigla, 'row' => (int) $q['row'], 'col' => (int) $q['col']];
}

$datiCarta = [
    'lat'       => (float) $boat['est_lat'],
    'lon'       => (float) $boat['est_lon'],
    'heading'   => (float) $boat['heading'],
    'errore_nm' => (float) $boat['est_error_nm'],
    'lat_top'   => Grid::LAT_TOP,
    'lon_west'  => Grid::LON_WEST,
    'quadrati'  => $quadrati,
    'stile'     => (string) ($stile ?? 'piena'),
    'porti'     => $porti,
    'rotta'     => array_map(static fn (array $w): array => [
        'lat' => (float) $w['lat'], 'lon' => (float) $w['lon'],
    ], $rotta),
    'contatti'  => array_map(static fn (array $k): array => [
        'lat'      => (float) $k['lat_est'],
        'lon'      => (float) $k['lon_est'],
        'tipo'     => (string) $k['target_kind'],
        'sensore'  => (string) $k['sensore'],
        'classe'   => (string) ($k['classe_est'] ?? ''),
        'certezza' => (float) $k['certezza'],
        'ril'      => (float) $k['bearing'],
        'distanza' => $k['range_nm'] !== null ? (float) $k['range_nm'] : null,
    ], array_filter($contatti, static fn (array $k): bool => $k['lat_est'] !== null)),
];
?>
<?= partial('nav_plancia', ['attiva' => 'carta']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div style="display:grid; grid-template-columns: minmax(0,1fr) 17rem; gap:1rem; align-items:start">
  <div class="tavolo">
    <canvas id="carta" data-carta="<?= e(json_encode($datiCarta, JSON_UNESCAPED_UNICODE)) ?>"></canvas>
    <p class="carta-aiuto">
      Trascina per spostare la carta, rotella per la scala. Un clic aggiunge un punto di rotta.
      Il cerchio tratteggiato è l'incertezza sulla posizione: dentro quel cerchio, il battello può essere ovunque.
      Le sigle in rosso sono i grandi quadrati Marinequadrat: sono quelle che si trasmettono al BdU.
    </p>
  </div>

  <div>
    <div class="strumento">
      <h3>Rotta pianificata</h3>
      <ul id="elenco-rotta"></ul>
      <form method="post" action="<?= e(url('/rotta')) ?>" style="margin-top:.8rem">
        <?= csrf_field() ?>
        <input type="hidden" name="waypoints" id="campo-waypoints" value="[]">
        <div class="comandi">
          <button type="submit" id="salva-rotta">Trasmetti alla centrale</button>
          <button type="button" id="pulisci-rotta">Cancella</button>
        </div>
      </form>
      <p class="aiuto">Il battello segue i punti nell'ordine. Raggiunto l'ultimo, resta in zona.</p>
    </div>

    <div class="strumento" style="margin-top:1rem">
      <h3>Tratto della carta</h3>
      <form method="post" action="<?= e(url('/carta/stile')) ?>">
        <?= csrf_field() ?>
        <div class="comandi">
          <button type="submit" name="stile" value="piena"
                  <?= ($stile ?? 'piena') === 'piena' ? 'disabled' : '' ?>>Carta da tavolo</button>
          <button type="submit" name="stile" value="essenziale"
                  <?= ($stile ?? 'piena') === 'essenziale' ? 'disabled' : '' ?>>Minuta a inchiostro</button>
        </div>
      </form>
      <p class="aiuto">
        Cambia il tratto, non la geometria: le coste stanno nello stesso posto in tutti e due i casi.
        <span id="fonte-coste" class="fonte-coste"></span>
      </p>
    </div>

    <div class="strumento" style="margin-top:1rem">
      <h3>Punto attuale</h3>
      <div class="riga">
        <span class="etichetta"><?= partial('segnaposto', ['chiave' => 'griglia_navale', 'h' => 20]) ?> Quadrato</span>
        <span class="valore"><?= e($quadrat) ?></span>
      </div>
      <div class="riga"><span class="etichetta">Latitudine</span><span class="valore piccolo"><?= e(Geo::formatLat((float) $boat['est_lat'])) ?></span></div>
      <div class="riga"><span class="etichetta">Longitudine</span><span class="valore piccolo"><?= e(Geo::formatLon((float) $boat['est_lon'])) ?></span></div>
      <div class="riga"><span class="etichetta">Incertezza</span><span class="valore piccolo">± <?= e(number_format((float) $boat['est_error_nm'], 1, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Rotta</span><span class="valore piccolo"><?= e(str_pad(number_format((float) $boat['heading'], 0, ',', ''), 3, '0', STR_PAD_LEFT)) ?>°</span></div>
    </div>
  </div>
</div>

<script src="<?= e(asset('js/coste.js')) ?>"></script>
<script src="<?= e(asset('js/etichette.js')) ?>"></script>
<script src="<?= e(asset('js/carta.js')) ?>" defer></script>
