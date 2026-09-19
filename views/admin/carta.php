<?php
/** @var list $quadrati @var list $porti @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Grid;

$dati = [
    'lat_top'  => Grid::LAT_TOP,
    'lon_west' => Grid::LON_WEST,
    'band_deg' => Grid::BAND_DEG,
    'col_deg'  => Grid::COL_DEG,
    'quadrati' => $quadrati,
    'porti'    => $porti,
    'url_dati' => url('/admin/mondo/dati'),
    'url_meteo' => url('/admin/mondo/meteo'),
];
?>
<?= partial('nav_admin', ['attiva' => 'carta_admin']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Carta ammiraglia</h1>
  <p class="sommario">
    Tutto quello che galleggia, tutto insieme: il traffico alleato, i convogli con la loro
    scorta, i battelli dei giocatori. È una vista che in gioco non esiste e non deve
    esistere — di qui si vede anche quello che nessun comandante potrebbe vedere.
  </p>

  <div class="comandi-carta">
    <label><input type="checkbox" data-mostra="navi" checked> navi isolate</label>
    <label><input type="checkbox" data-mostra="convogli" checked> convogli</label>
    <label><input type="checkbox" data-mostra="scorte" checked> scorte</label>
    <label><input type="checkbox" data-mostra="battelli" checked> battelli</label>
    <label><input type="checkbox" data-mostra="meteo"> vento e mare</label>
    <label><input type="checkbox" data-mostra="griglia" checked> quadrati</label>
    <label><input type="checkbox" data-mostra="coste" checked> coste</label>
    <span class="separatore"></span>
    <select data-vai title="Inquadra un battello o un convoglio"><option value="">vai a…</option></select>
    <button type="button" data-meno title="Rimpicciolisci">−</button>
    <span data-zoom-valore class="zoom-valore">tutto il teatro</span>
    <button type="button" data-piu title="Ingrandisci">+</button>
    <button type="button" data-tutto class="bottone--fantasma">tutto il teatro</button>
    <button type="button" data-aggiorna>Aggiorna</button>
    <span data-stato class="stato-carta">—</span>
  </div>

  <div class="carta-admin-scatola">
    <canvas id="carta-admin" width="1600" height="1100" data-carta="<?= e((string) json_encode($dati)) ?>"></canvas>
  </div>

  <div id="scheda-carta" class="scheda-carta" hidden></div>

  <p class="aiuto" style="margin-top:.8rem">
    <b>Navigare:</b> rotellina per ingrandire dove sta il cursore, trascinare per spostarsi,
    doppio clic per avvicinarsi a un punto, frecce e <code>+</code> / <code>−</code> da
    tastiera. «Vai a» inquadra un battello o un convoglio senza cercarlo a occhio.<br>
    <b>Interrogare:</b> un clic su un punto qualunque apre la scheda del tempo lì; un clic su
    una sagoma apre la scheda di quella.<br>
    La carta non si aggiorna da sola: il pulsante è lì apposta, perché ricalcolare il meteo
    su tutta la maglia costa.
  </p>
</div>

<script src="<?= e(asset('js/coste.js')) ?>"></script>
<script src="<?= e(asset('js/carta_admin.js')) ?>" defer></script>
