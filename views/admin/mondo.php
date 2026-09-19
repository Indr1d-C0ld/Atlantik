<?php
/** @var array $quadro @var array $censimento @var list $incontri @var array $manopole
 *  @var list $forzature @var array $mix_convoglio @var array $mix_scorta
 *  @var \App\Sim\Clock $clock @var int $now */
$num = static fn (int|float $v, int $d = 0): string => number_format((float) $v, $d, ',', '.');
?>
<?= partial('nav_admin', ['attiva' => 'mondo']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Il mondo</h1>
  <p class="sommario">
    La stanza dei bottoni. A sinistra i monitor — quello che si guarda non cambia niente —
    a destra le manopole. Ogni manopola girata finisce nel registro, le forzature del meteo
    scadono da sole, e la composizione del traffico torna ai pesi storici svuotando le caselle.
  </p>

  <div class="quadranti">
    <div class="quadrante"><div class="etichetta">ora di bordo</div><div class="valore" style="font-size:1.05rem"><?= e($clock->format($now)) ?></div></div>
    <div class="quadrante"><div class="etichetta">seme del mondo</div><div class="valore" style="font-size:1.05rem"><?= e((string) $quadro['seme']) ?></div></div>
    <div class="quadrante"><div class="etichetta">navi in mare</div><div class="valore"><?= e($num($quadro['navi'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">stazza in mare</div><div class="valore" style="font-size:1.1rem"><?= e($num($quadro['grt_in_mare'])) ?> GRT</div></div>
    <div class="quadrante"><div class="etichetta">convogli</div><div class="valore"><?= e($num($quadro['convogli'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">scorte</div><div class="valore"><?= e($num($quadro['scorte'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">battelli in mare</div><div class="valore"><?= e($num($quadro['in_mare'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">incontri aperti</div><div class="valore" style="color:<?= $quadro['incontri'] > 0 ? 'var(--rosso)' : 'var(--verde-fosf)' ?>"><?= e($num($quadro['incontri'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">siluri in acqua</div><div class="valore"><?= e($num($quadro['siluri'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">navi affondate</div><div class="valore"><?= e($num($quadro['affondate'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">branchi</div><div class="valore"><?= e($num($quadro['branchi'])) ?></div></div>
    <div class="quadrante"><div class="etichetta">posta in coda</div><div class="valore"><?= e($num($quadro['posta'])) ?></div></div>
  </div>

  <div class="azioni">
    <a class="bottone" href="<?= e(url('/admin/carta')) ?>">Apri la carta ammiraglia</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(26rem,1fr));gap:1rem">

  <div class="pannello">
    <h2>Naviglio in mare, per classe</h2>
    <table class="dati">
      <tr><th>Classe</th><th>Genere</th><th>N.</th><th>GRT</th></tr>
      <?php foreach ($censimento['classi'] as $c): ?>
        <tr>
          <td><?= e($c['nome']) ?></td>
          <td style="color:var(--testo-3)"><?= e($c['ruolo']) ?></td>
          <td><?= e($num($c['n'])) ?></td>
          <td><?= e($num($c['grt'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="pannello">
    <h2>Per bandiera</h2>
    <table class="dati">
      <tr><th>Bandiera</th><th>N.</th><th>GRT</th></tr>
      <?php foreach ($censimento['bandiere'] as $b): ?>
        <tr><td><?= e($b['chiave']) ?></td><td><?= e($num($b['n'])) ?></td><td><?= e($num($b['grt'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <h3 style="margin-top:1.2rem">Per ruolo</h3>
    <table class="dati">
      <tr><th>Ruolo</th><th>N.</th></tr>
      <?php foreach ($censimento['ruoli'] as $r): ?>
        <tr><td><?= e($r['chiave']) ?></td><td><?= e($num($r['n'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="pannello">
    <h2>Per rotta</h2>
    <table class="dati">
      <tr><th>Rotta</th><th>N.</th><th>GRT</th></tr>
      <?php foreach ($censimento['rotte'] as $r): ?>
        <tr><td><?= e($r['nome']) ?></td><td><?= e($num($r['n'])) ?></td><td><?= e($num($r['grt'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="pannello">
    <h2>Incontri aperti</h2>
    <?php if ($incontri === []): ?>
      <p class="sommario">Nessuno. Mare tranquillo.</p>
    <?php else: ?>
      <table class="dati">
        <tr><th>Battello</th><th>Comandante</th><th>Fase</th><th>Aff.</th><th>Cariche</th><th>Ora</th></tr>
        <?php foreach ($incontri as $i): ?>
          <tr>
            <td><?= e($i['uboat_number'] ?? '—') ?></td>
            <td><?= e($i['comandante'] ?? '—') ?></td>
            <td style="color:<?= (string) $i['stato'] === 'evasione' ? 'var(--rosso)' : 'var(--ambra)' ?>"><?= e($i['stato']) ?></td>
            <td><?= e((string) $i['affondate']) ?></td>
            <td><?= e((string) $i['cariche_subite']) ?></td>
            <td><?= e($clock->format((int) $i['last_step_gts'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="pannello">
  <h2>Il tempo, forzato</h2>
  <p class="sommario">
    Il tempo e' una funzione del seme e dell'istante: uguale per tutti, ricalcolabile
    all'indietro. Una forzatura non tocca quella funzione — le si siede sopra, in un cerchio
    e per una finestra. Si compila solo quello che si vuole imporre: il resto continua a
    venire dal modello. Quando scade, il mondo torna quello che sarebbe stato, da solo.
  </p>

  <form method="post" action="<?= e(url('/admin/mondo/meteo')) ?>">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(8rem,1fr));gap:.8rem">
      <div class="campo"><label for="f-lat">Centro lat</label><input type="text" id="f-lat" name="lat" placeholder="50.0"></div>
      <div class="campo"><label for="f-lon">Centro lon</label><input type="text" id="f-lon" name="lon" placeholder="-20.0"></div>
      <div class="campo"><label for="f-r">Raggio (nm)</label><input type="text" id="f-r" name="raggio_nm" placeholder="200"></div>
      <div class="campo"><label for="f-ore">Durata (ore di gioco)</label><input type="text" id="f-ore" name="ore" placeholder="6"></div>
      <div class="campo"><label for="f-mare">Mare (0-9)</label><input type="text" id="f-mare" name="sea_state" placeholder=""></div>
      <div class="campo"><label for="f-vento">Vento (kn)</label><input type="text" id="f-vento" name="wind_kn" placeholder=""></div>
      <div class="campo"><label for="f-dir">Direzione (°)</label><input type="text" id="f-dir" name="wind_dir" placeholder=""></div>
      <div class="campo"><label for="f-vis">Visibilità (nm)</label><input type="text" id="f-vis" name="visibility_nm" placeholder=""></div>
      <div class="campo"><label for="f-neb">Nebbia</label>
        <select id="f-neb" name="fog"><option value="">—</option><option value="1">sì</option><option value="0">no</option></select>
      </div>
      <div class="campo"><label for="f-nuv">Nuvole (0-1)</label><input type="text" id="f-nuv" name="cloud" placeholder=""></div>
    </div>
    <div class="campo"><label for="f-nota">Nota (perché)</label><input type="text" id="f-nota" name="nota" maxlength="255" style="width:100%;max-width:34rem"></div>
    <p class="aiuto">
      Centro e raggio insieme, o nessuno dei due: senza centro la forzatura vale su tutto il
      teatro. Durata a zero vuol dire finché non la togli.
    </p>
    <div class="azioni"><button type="submit">Forza il tempo</button></div>
  </form>

  <h3 style="margin-top:1.2rem">Forzature in vigore</h3>
  <?php if ($forzature === []): ?>
    <p class="sommario">Nessuna: il tempo è quello che il mondo dice.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Dove</th><th>Fino a</th><th>Impone</th><th>Nota</th><th></th></tr>
      <?php foreach ($forzature as $f): ?>
        <tr>
          <td><?= $f['lat'] === null ? 'tutto il teatro' : e(sprintf('%.2f / %.2f entro %d nm', (float) $f['lat'], (float) $f['lon'], (int) $f['raggio_nm'])) ?></td>
          <td><?= $f['scadenza_gts'] === null ? 'finché non si toglie' : e($clock->format((int) $f['scadenza_gts'])) ?></td>
          <td style="font-size:.82rem">
            <?php
            $pezzi = [];
            foreach (['sea_state' => 'mare', 'wind_kn' => 'vento kn', 'wind_dir' => 'dir', 'visibility_nm' => 'vis nm', 'cloud' => 'nuvole'] as $k => $et) {
                if ($f[$k] !== null) { $pezzi[] = $et . ' ' . rtrim(rtrim((string) $f[$k], '0'), '.'); }
            }
            if ($f['fog'] !== null) { $pezzi[] = (int) $f['fog'] === 1 ? 'nebbia' : 'senza nebbia'; }
            echo e(implode(' · ', $pezzi));
            ?>
          </td>
          <td style="color:var(--testo-3);font-size:.8rem"><?= e((string) ($f['nota'] ?? '')) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/mondo/meteo/togli')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="forzatura" value="<?= e($f['id']) ?>">
              <button type="submit" class="bottone--fantasma" style="padding:.2rem .6rem;font-size:.7rem">togli</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="<?= e(url('/admin/mondo/meteo/togli')) ?>" style="margin-top:.7rem">
      <?= csrf_field() ?>
      <input type="hidden" name="tutte" value="1">
      <button type="submit" class="allarme">Libera il tempo — togli tutte</button>
    </form>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(24rem,1fr));gap:1rem">
  <?php foreach ([['convoglio', 'Composizione dei convogli', $mix_convoglio], ['scorta', 'Composizione delle scorte', $mix_scorta]] as [$quale, $titolo, $mix]): ?>
    <div class="pannello">
      <h2><?= e($titolo) ?></h2>
      <p class="sommario">
        Pesi relativi, non percentuali: contano gli uni rispetto agli altri.
        <?= $mix['forzato'] ? '<b>Adesso è forzata.</b>' : 'Adesso valgono i pesi storici.' ?>
        Vale per le navi che partono da qui in avanti: quelle già in mare restano quelle che sono.
      </p>
      <form method="post" action="<?= e(url('/admin/mondo/mix')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="quale" value="<?= e($quale) ?>">
        <table class="dati">
          <tr><th>Classe</th><th>GRT</th><th>Peso</th><th>Storico</th></tr>
          <?php foreach ($mix['righe'] as $r): ?>
            <tr>
              <td><?= e($r['nome']) ?></td>
              <td style="color:var(--testo-3)"><?= e($num($r['grt'])) ?></td>
              <td><input type="text" name="peso[<?= e($r['chiave']) ?>]" value="<?= e((string) $r['peso']) ?>"
                         style="width:4rem;padding:.2rem .35rem;font:.82rem var(--mono)"></td>
              <td style="color:var(--testo-3)"><?= e((string) $r['storico']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="aiuto">Svuota tutte le caselle e salva per tornare ai pesi storici.</p>
        <div class="azioni"><button type="submit">Salva la composizione</button></div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="pannello">
  <h2>Leve sul mondo in esercizio</h2>
  <p class="sommario">
    Poche di proposito: qui dentro c'è la partita di altre persone, e una leva che non si sa
    disfare non ci sta.
  </p>
  <form method="post" action="<?= e(url('/admin/mondo/azione')) ?>" style="display:inline">
    <?= csrf_field() ?>
    <button type="submit" name="azione" value="traffico_ripopola">Ripopola il traffico</button>
  </form>
  <form method="post" action="<?= e(url('/admin/mondo/azione')) ?>" style="display:inline;margin-left:.5rem">
    <?= csrf_field() ?>
    <button type="submit" name="azione" value="traffico_pota" class="bottone--fantasma">Pota gli arrivati</button>
  </form>
</div>

<div class="pannello">
  <h2>Manopole del motore</h2>
  <p class="sommario">
    Le stesse chiavi del pannello, divise per area e con scritto a che servono. Cambiano il
    comportamento senza un deploy, e ogni modifica resta nel registro.
  </p>
  <?php foreach ($manopole as $titolo => $righe): ?>
    <h3 style="margin-top:1.1rem;color:var(--ottone)"><?= e($titolo) ?></h3>
    <table class="dati">
      <tr><th>Chiave</th><th>Valore</th><th>A che serve</th></tr>
      <?php foreach ($righe as $r): ?>
        <tr>
          <td style="font:.8rem var(--mono)"><?= e($r['chiave']) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/config')) ?>" style="display:flex;gap:.3rem;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="chiave" value="<?= e($r['chiave']) ?>">
              <input type="text" name="valore" value="<?= e((string) $r['valore']) ?>"
                     style="width:<?= (string) $r['tipo'] === 'json' ? '18' : '6' ?>rem;padding:.2rem .4rem;font:.8rem var(--mono)">
              <button type="submit" class="bottone--fantasma" style="padding:.2rem .55rem;font-size:.68rem">salva</button>
            </form>
          </td>
          <td style="color:var(--testo-3);font-size:.8rem"><?= e((string) $r['nota']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>
