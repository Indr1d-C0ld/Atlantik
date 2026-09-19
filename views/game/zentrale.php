<?php
/** @var array $boat @var array $type @var array $meteo @var array $cielo @var array $rotta
 *  @var \App\Sim\Clock $clock @var int $now @var array $ktb @var float $max_kn @var float $ore_sub */
use App\Sim\Consumption;
use App\Sim\Geo;
use App\Sim\Weather;

$naftaPct = 100 * (float) $boat['fuel_t'] / max(0.1, (float) $type['fuel_t']);
$classe = static fn (float $p): string => $p <= 12 ? 'allarme' : ($p <= 30 ? 'attenzione' : '');
?>
<?= partial('nav_plancia', ['attiva' => 'zentrale']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div data-plancia data-stato-url="<?= e(url('/api/stato')) ?>" data-intervallo="30000">
<div class="griglia-plancia">

  <div class="strumento">
    <h3>Posizione stimata</h3>
    <div class="riga"><span class="etichetta">Quadrato</span><span class="valore grande" data-campo="quadrat"><?= e($quadrat) ?></span></div>
    <div class="riga"><span class="etichetta">Punto</span><span class="valore piccolo" data-campo="posizione"><?= e(Geo::formatLat((float) $boat['est_lat']) . '   ' . Geo::formatLon((float) $boat['est_lon'])) ?></span></div>
    <div class="riga"><span class="etichetta">Incertezza</span><span class="valore piccolo" data-campo="errore">± <?= e(number_format((float) $boat['est_error_nm'], 1, ',', '')) ?> nm</span></div>
    <p class="aiuto">È la posizione calcolata dall'Obersteuermann: si corregge col punto astronomico, quando il cielo lo permette.</p>
  </div>

  <div class="strumento">
    <h3>Governo</h3>
    <div class="riga"><span class="etichetta">Rotta</span><span class="valore grande" data-campo="rotta"><?= e(str_pad(number_format((float) $boat['heading'], 0, ',', ''), 3, '0', STR_PAD_LEFT)) ?>°</span></div>
    <div class="riga"><span class="etichetta">Velocità</span><span class="valore" data-campo="velocita"><?= e(number_format((float) $boat['speed_kn'], 1, ',', '')) ?> kn</span></div>
    <div class="riga"><span class="etichetta">Ordinata</span><span class="valore piccolo" data-campo="vel_ord"><?= e(number_format((float) $boat['ordered_speed_kn'], 1, ',', '')) ?> kn</span></div>
    <div class="riga"><span class="etichetta">Massima ora</span><span class="valore piccolo"><?= e(number_format($max_kn, 1, ',', '')) ?> kn</span></div>
    <div class="comandi">
      <?php foreach ([0 => 'Stop', 4 => 'Lenta', 7 => 'Piccola', 10 => 'Crociera', 14 => 'Forza'] as $kn => $nome): ?>
        <button type="button" data-velocita="<?= $kn ?>"><?= e($nome) ?></button>
      <?php endforeach; ?>
      <button type="button" data-velocita="<?= e((string) $type['speed_surf_kn']) ?>">Tutta forza</button>
    </div>
  </div>

  <div class="strumento">
    <h3>Immersione</h3>
    <div class="riga"><span class="etichetta">Quota</span><span class="valore grande" data-campo="quota"><?= e(number_format((float) $boat['depth_m'], 1, ',', '')) ?> m</span></div>
    <div class="riga"><span class="etichetta">Ordinata</span><span class="valore piccolo" data-campo="quota_ord"><?= e(number_format((float) $boat['ordered_depth_m'], 0, ',', '')) ?> m</span></div>
    <div class="riga"><span class="etichetta">Assetto</span><span class="valore piccolo" data-campo="modo"><?= e($boat['mode']) ?></span></div>
    <div class="riga"><span class="etichetta">Quota di prova</span><span class="valore piccolo"><?= e($type['test_depth_m']) ?> m</span></div>
    <div class="comandi">
      <button type="button" data-quota="0">Emergere</button>
      <button type="button" data-quota="12">Periscopio</button>
      <button type="button" data-quota="40">40 m</button>
      <button type="button" data-quota="80">80 m</button>
      <button type="button" class="allarme" data-quota="<?= e((string) $type['test_depth_m']) ?>">Alarm! <?= e($type['test_depth_m']) ?> m</button>
    </div>
  </div>

  <div class="strumento">
    <h3>Nafta</h3>
    <div class="riga"><span class="etichetta">A bordo</span><span class="valore grande" data-campo="nafta"><?= e(number_format((float) $boat['fuel_t'], 1, ',', '')) ?> t</span></div>
    <div class="misuratore <?= $classe($naftaPct) ?>"><i data-barra="nafta" style="width: <?= e(number_format($naftaPct, 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Percentuale</span><span class="valore piccolo" data-campo="nafta_pct"><?= e(number_format($naftaPct, 0, ',', '')) ?>%</span></div>
    <div class="riga"><span class="etichetta">Autonomia a 10 kn</span><span class="valore piccolo" data-campo="autonomia"><?= e(number_format(Consumption::rangeLeftNm($type, (float) $boat['fuel_t'], 10), 0, ',', '.')) ?> nm</span></div>
  </div>

  <div class="strumento">
    <h3>Batterie e aria</h3>
    <div class="riga"><span class="etichetta">Batteria</span><span class="valore grande" data-campo="batteria"><?= e(number_format((float) $boat['battery_pct'], 0, ',', '')) ?>%</span></div>
    <div class="misuratore <?= $classe((float) $boat['battery_pct']) ?>"><i data-barra="batteria" style="width: <?= e(number_format((float) $boat['battery_pct'], 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Immersione residua</span><span class="valore piccolo" data-campo="ore_immersione"><?= e(number_format($ore_sub, 1, ',', '')) ?> h</span></div>
    <div class="riga"><span class="etichetta">Aria</span><span class="valore piccolo" data-campo="aria"><?= e(number_format((float) $boat['air_pct'], 0, ',', '')) ?>%</span></div>
    <div class="misuratore <?= $classe((float) $boat['air_pct']) ?>"><i data-barra="aria" style="width: <?= e(number_format((float) $boat['air_pct'], 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Anidride carbonica</span><span class="valore piccolo" data-campo="co2"><?= e(number_format((float) $boat['co2_pct'], 2, ',', '')) ?>%</span></div>
    <div class="riga"><span class="etichetta">Viveri</span><span class="valore piccolo" data-campo="viveri"><?= e(number_format((float) $boat['provisions_days'], 1, ',', '')) ?> g</span></div>
  </div>

  <div class="strumento">
    <h3>Battello ed equipaggio</h3>
    <div class="riga"><span class="etichetta">Avarie</span><span class="valore grande" style="color:<?= $effetti['guasti'] > 0 ? 'var(--ambra)' : 'var(--verde-fosf)' ?>"><?= e($effetti['guasti']) ?></span></div>
    <?php if ($effetti['guasti'] > 0): ?>
      <div class="riga"><span class="etichetta">In avaria</span><span class="valore piccolo"><?= e(implode(', ', array_slice($effetti['elenco'], 0, 3))) ?></span></div>
    <?php endif; ?>
    <div class="riga"><span class="etichetta">Morale</span><span class="valore piccolo"><?= e(\App\Sim\Crew::statoMorale($ciurma['morale'])) ?> (<?= e(number_format($ciurma['morale'], 0, ',', '')) ?>)</span></div>
    <div class="riga"><span class="etichetta">Uomini</span><span class="valore piccolo">gli uomini sono <?= e(\App\Sim\Crew::statoFaticaPlurale($ciurma['fatica'])) ?></span></div>
    <div class="riga"><span class="etichetta">Sollecitazione scafo</span><span class="valore piccolo" data-campo="stress"><?= e(number_format((float) $boat['hull_stress'], 0, ',', '')) ?>%</span></div>
      <?php /* L'API manda a ogni aggiornamento il conto delle avarie: prima lo
               scriveva in un elemento che non esisteva in nessuna vista, e non
               lo vedeva nessuno. */ ?>
      <div class="riga"><span class="etichetta">Avarie a bordo</span><span class="valore piccolo" data-campo="avarie"><?= e((string) (int) ($effetti['guasti'] ?? 0)) ?></span></div>
    <div class="comandi">
      <a class="bottone bottone--fantasma" style="padding:.35rem .7rem;font-size:.7rem" href="<?= e(url('/battello')) ?>">Scheda battello</a>
      <a class="bottone bottone--fantasma" style="padding:.35rem .7rem;font-size:.7rem" href="<?= e(url('/equipaggio')) ?>">Ruolino</a>
    </div>
  </div>

  <div class="strumento">
    <h3>Mare e cielo</h3>
    <div class="riga"><span class="etichetta">Tempo</span><span class="valore piccolo" data-campo="meteo"><?= e($meteo['descrizione']) ?></span></div>
    <div class="riga"><span class="etichetta">Vento</span><span class="valore piccolo"><?= e(Weather::rosa((float) $meteo['wind_dir'])) ?> <span data-campo="vento"><?= e(number_format((float) $meteo['wind_kn'], 0, ',', '')) ?> kn</span></span></div>
    <div class="riga"><span class="etichetta">Mare</span><span class="valore piccolo" data-campo="mare">forza <?= e($meteo['sea_state']) ?></span></div>
    <div class="riga"><span class="etichetta">Visibilità</span><span class="valore piccolo" data-campo="visibilita"><?= e(number_format((float) $meteo['visibility_nm'], 1, ',', '')) ?> nm</span></div>
    <div class="riga"><span class="etichetta">Barometro</span><span class="valore piccolo" data-campo="pressione"><?= e(number_format((float) $meteo['pressure_hpa'], 0, ',', '')) ?> hPa</span></div>
    <div class="riga"><span class="etichetta">Cielo</span><span class="valore piccolo" data-campo="fase"><?= e($cielo['fase']) ?></span></div>
    <div class="riga"><span class="etichetta">Luce</span><span class="valore piccolo" data-campo="luce"><?= e(number_format($cielo['luce'] * 100, 0, ',', '')) ?>%</span></div>
    <div class="riga"><span class="etichetta">Luna</span><span class="valore piccolo" data-campo="luna"><?= e($cielo['moon_phase']) ?></span></div>
  </div>

</div>

<form method="post" action="<?= e(url('/ordini')) ?>" id="modulo-ordini" class="pannello" style="margin-top:1.2rem">
  <?= csrf_field() ?>
  <h2>Ordini alla centrale</h2>
  <div style="display:flex; gap:1.2rem; flex-wrap:wrap; align-items:flex-end">
    <div class="campo" style="margin:0; max-width:10rem">
      <label for="campo-velocita">Velocità (nodi)</label>
      <input type="text" id="campo-velocita" name="speed" value="<?= e(number_format((float) $boat['ordered_speed_kn'], 1, '.', '')) ?>" inputmode="decimal">
    </div>
    <div class="campo" style="margin:0; max-width:10rem">
      <label for="campo-quota">Quota (metri)</label>
      <input type="text" id="campo-quota" name="depth" value="<?= e(number_format((float) $boat['ordered_depth_m'], 0, '.', '')) ?>" inputmode="numeric">
    </div>
    <div class="campo" style="margin:0">
      <label for="campo-silenzio">Marcia silenziosa</label>
      <select id="campo-silenzio" name="silent" style="padding:.6rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:1rem var(--mono)">
        <option value="0"<?= (int) $boat['silent'] === 0 ? ' selected' : '' ?>>no</option>
        <option value="1"<?= (int) $boat['silent'] === 1 ? ' selected' : '' ?>>sì</option>
      </select>
    </div>
    <button type="submit">Impartisci</button>
  </div>
</form>

<div class="pannello" style="margin-top:1.2rem">
  <h2>Giornale di guerra — ultime righe</h2>
  <ul class="ktb" id="ktb-vivo">
    <?php foreach ($ktb as $e): ?>
      <li class="sev-<?= e($e['severity']) ?>">
        <span class="ora"><?= e($clock->formatDiario((int) $e['gts'])) ?></span>
        <span class="testo"><?= e($e['text']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="azioni">
    <a class="bottone bottone--fantasma" href="<?= e(url('/ktb')) ?>">Giornale completo</a>
    <form method="post" action="<?= e(url('/rientro')) ?>" style="display:inline">
      <?= csrf_field() ?>
      <button type="submit" class="bottone--fantasma">Entrare in porto</button>
    </form>
  </div>
</div>
</div>

<script src="<?= e(asset('js/plancia.js')) ?>" defer></script>
