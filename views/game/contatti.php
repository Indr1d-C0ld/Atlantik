<?php
/** @var array $boat @var array $type @var list $contatti @var array $ascolto @var array $vista
 *  @var array $settore @var array $meteo @var array $cielo @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Weather;

$attivi = array_values(array_filter($contatti, static fn (array $c): bool => !((bool) $c['perso'])));
$rosa = array_map(static fn (array $c): array => [
    'ril'      => (float) $c['bearing'],
    'sensore'  => (string) $c['sensore'],
    'certezza' => (float) $c['certezza'],
    'perso'    => (bool) $c['perso'],
    'etichetta'=> mb_substr((string) ($c['classe_est'] ?? ''), 0, 28),
], array_slice($contatti, 0, 20));
?>
<?= partial('nav_plancia', ['attiva' => 'contatti']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="due-colonne due-colonne--ascolto">
  <div class="tavolo">
    <?php /* Come per la carta: la rosa e' un disegno, e quello che dice si
       puo' dire a parole. L'elenco dei contatti, qui sotto, e' la stessa cosa
       scritta — e per chi usa un lettore di schermo e' l'unica. */ ?>
    <canvas id="rosa-idrofono" role="img"
            aria-label="<?= e(sprintf(
              'Rosa dei rilevamenti, prua %03d gradi. %s',
              (int) round((float) $boat['heading']),
              $rosa === [] ? 'Nessun contatto.' : sprintf('%d contatti in ascolto.', count($rosa))
            )) ?>"
            data-rosa="<?= e(json_encode([
        'contatti' => $rosa,
        'prua'     => (float) $boat['heading'],
    ], JSON_UNESCAPED_UNICODE)) ?>">La rosa e' un disegno: gli stessi contatti, in forma di elenco, stanno qui sotto.</canvas>
    <p class="carta-aiuto">
      Rosa dei rilevamenti. Il tratto pieno è un contatto in mano, quello sbiadito un contatto perso.
      La lunghezza dice quanto è netto: all'idrofono si ha il rilevamento, non la distanza.
    </p>
  </div>

  <div>
    <div class="strumento">
      <h3>Che cosa si sentirebbe adesso</h3>
      <div class="riga"><span class="etichetta">Rumore proprio</span><span class="valore"><?= e(number_format($ascolto['rumore_proprio'], 1, ',', '')) ?> dB</span></div>
      <div class="riga"><span class="etichetta">Un convoglio si sente fino a</span><span class="valore grande"><?= e(number_format($ascolto['portata_convoglio'], 0, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Una nave isolata fino a</span><span class="valore piccolo"><?= e(number_format($ascolto['portata_nave'], 0, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Strato termico</span><span class="valore piccolo">
        <?= $ascolto['strato_m'] > 0 ? e(number_format($ascolto['strato_m'], 0, ',', '')) . ' m' : 'assente' ?>
        <?= $ascolto['sotto_strato'] ? ' — ci siamo sotto' : '' ?>
      </span></div>
      <p class="aiuto">
        Ogni nodo in più è un contatto in meno: alla massima velocità il battello è sordo.
        Sotto lo strato termico l'ASDIC nemico fatica a trovarvi, ma anche voi smettete di sentire.
      </p>
    </div>

    <div class="strumento" style="margin-top:1rem">
      <h3>Vedette e sagoma</h3>
      <div class="riga"><span class="etichetta">Un mercantile grande si vede a</span><span class="valore"><?= e(number_format($vista['portata_grande'], 1, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">La nostra sagoma</span><span class="valore piccolo"><?= e(number_format($vista['nostra_sagoma'] * 100, 0, ',', '')) ?>%</span></div>
      <div class="riga"><span class="etichetta">Una scorta ci vedrebbe a</span><span class="valore grande" style="color:<?= $vista['ci_vedono'] > 4 ? 'var(--rosso)' : ($vista['ci_vedono'] > 1.5 ? 'var(--ambra)' : 'var(--verde-fosf)') ?>"><?= e(number_format($vista['ci_vedono'], 1, ',', '')) ?> nm</span></div>
      <div class="riga"><span class="etichetta">Luce</span><span class="valore piccolo"><?= e($cielo['fase']) ?>, <?= e($cielo['moon_phase']) ?></span></div>
      <div class="riga"><span class="etichetta">Visibilità / mare</span><span class="valore piccolo"><?= e(number_format((float) $meteo['visibility_nm'], 1, ',', '')) ?> nm · <?= e(Weather::nomeMare((int) $meteo['sea_state'])) ?></span></div>
    </div>

    <div class="strumento" style="margin-top:1rem">
      <h3>Settore</h3>
      <div class="riga"><span class="etichetta">Quadrato</span><span class="valore"><?= e($settore['quadrat']) ?></span></div>
      <div class="riga"><span class="etichetta">Sorveglianza</span><span class="valore piccolo"><?= e($settore['stato']) ?> (<?= e(number_format($settore['heat'], 0, ',', '')) ?>/100)</span></div>
      <div class="misuratore <?= $settore['heat'] > 60 ? 'allarme' : ($settore['heat'] > 25 ? 'attenzione' : '') ?>"><i style="width:<?= e(number_format(min(100, $settore['heat']), 1, '.', '')) ?>%"></i></div>
      <p class="aiuto">Il calore cresce dove ci si fa notare e cala col tempo: sparire per qualche giorno è una tattica.</p>
    </div>
  </div>
</div>

<div class="pannello" style="margin-top:1.2rem">
  <h2>Rapporto contatti <span style="color:var(--testo-3);font-weight:400">(<?= count($attivi) ?> in mano, <?= count($contatti) - count($attivi) ?> persi)</span></h2>
  <?php if ($contatti === []): ?>
    <p class="sommario">Nessun contatto. L'idrofonista ascolta, le vedette scrutano: mare vuoto.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Ora</th><th>Sensore</th><th>Ril.</th><th>Distanza</th><th></th><th>Classificazione</th><th>Certezza</th><th>Stato</th><th></th></tr>
      <?php foreach ($contatti as $c): ?>
        <tr<?= (bool) $c['perso'] ? ' style="opacity:.55"' : '' ?>>
          <td><?= e($clock->format((int) $c['last_gts'])) ?></td>
          <td><?= e($c['sensore']) ?></td>
          <td><?= e(str_pad(number_format((float) $c['bearing'], 0, ',', ''), 3, '0', STR_PAD_LEFT)) ?>°</td>
          <td><?= $c['range_nm'] !== null ? e(number_format((float) $c['range_nm'], 1, ',', '')) . ' nm' : '—' ?></td>
          <td style="width:7rem">
            <?php
            // La sagoma compare SOLO per un contatto visto e riconosciuto: a
            // vista, e con la classificazione ormai ferma. All'idrofono si sente
            // un battito d'elica, non si vede una nave — mostrare la sagoma
            // regalerebbe al comandante una cosa che non ha.
            $riconosciuto = (string) $c['sensore'] === 'vista'
                && (float) $c['certezza'] >= 0.60
                && ($c['classe_key_est'] ?? '') !== '';
            ?>
            <?= $riconosciuto ? partial('segnaposto', ['lega' => (string) $c['classe_key_est'], 'h' => 24]) : '' ?>
          </td>
          <td><?= e($c['classe_est'] ?? '—') ?></td>
          <td><?= e(number_format(100 * (float) $c['certezza'], 0, ',', '')) ?>%</td>
          <td><?= (bool) $c['perso'] ? 'perso' : 'in mano' ?></td>
          <td>
            <?php if (!(bool) $c['perso'] && ($c['convoy_id'] !== null || $c['ship_id'] !== null)): ?>
              <form method="post" action="<?= e(url('/attacco/ingaggia')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="contatto" value="<?= e($c['id']) ?>">
                <button type="submit" class="bottone--fantasma" style="padding:.2rem .6rem;font-size:.7rem">ingaggia</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nota-segnaposto">
      La sagoma compare solo per i contatti <b>visti</b> e ormai classificati (oltre il 60%).
      All'idrofono non si vede niente: si sente un battito d'elica, e il Funkmaat dice
      «mercantile isolato», non «piroscafo a tre isole». Vedere la sagoma vuol dire aver
      riconosciuto — e riconoscere costa tempo, avvicinamento e rischio.
    </p>
  <?php endif; ?>
</div>

<script src="<?= e(asset('js/rosa.js')) ?>" defer></script>
