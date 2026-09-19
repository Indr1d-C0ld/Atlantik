<?php
/** @var array $boat @var array $type @var array $tipi @var int $grado @var array $scorte
 *  @var array $voci @var int $capacita @var float $usato @var \App\Sim\Clock $clock @var int $now */
?>
<?= partial('nav_plancia', ['attiva' => 'base']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="pannello">
  <span class="targhetta">Bunker — cantiere</span>
  <h1>Allestimento per la prossima missione</h1>
  <p class="sommario">
    Lo spazio è l'unica valuta vera a bordo. Ogni giorno di viveri in più è una cassa di ricambi in meno;
    ogni cartuccia di potassa è un'ora di immersione guadagnata e un colpo di cannone lasciato a terra.
  </p>

  <form method="post" action="<?= e(url('/cantiere/allestimento')) ?>">
    <?= csrf_field() ?>
    <table class="dati">
      <tr><th>Voce</th><th>Quantità</th><th>Spazio unitario</th><th>Nota</th></tr>
      <?php foreach ($voci as $k => $v): ?>
        <?php if ($k === 'munizioni_cannone' && empty($type['deck_gun'])) { continue; } ?>
        <tr>
          <td><?= e($v['nome']) ?></td>
          <td style="width:9rem">
            <input type="text" name="<?= e($k) ?>" inputmode="numeric" style="width:6rem;padding:.3rem .4rem"
                   value="<?= e(number_format((float) ($scorte[$k] ?? 0), 0, '.', '')) ?>">
            <span style="color:var(--testo-3);font-size:.75rem"><?= e($v['unita']) ?></span>
          </td>
          <td style="color:var(--testo-3)"><?= e(number_format($v['spazio'], 2, ',', '')) ?></td>
          <td style="color:var(--testo-3);font-size:.8rem"><?= e($v['note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <div class="riga" style="margin-top:1rem">
      <span class="etichetta">Stiva occupata</span>
      <span class="valore"><?= e(number_format($usato, 0, ',', '.')) ?> / <?= e(number_format($capacita, 0, ',', '.')) ?> unità</span>
    </div>
    <div class="misuratore <?= $usato > $capacita ? 'allarme' : ($usato > $capacita * 0.9 ? 'attenzione' : '') ?>">
      <i style="width:<?= e(number_format(min(100, 100 * $usato / max(1, $capacita)), 1, '.', '')) ?>%"></i>
    </div>

    <div class="azioni">
      <button type="submit">Imbarca</button>
      <a class="bottone bottone--fantasma" href="<?= e(url('/base')) ?>">Torna in flottiglia</a>
    </div>
  </form>
</div>

<div class="pannello">
  <h2>Battelli disponibili</h2>
  <p class="sommario">La flottiglia assegna quello che c'è. Con l'anzianità e il tonnellaggio si apriranno gli altri: la scheda dichiara sempre da quando il tipo è esistito davvero.</p>
  <table class="dati">
    <tr><th>Tipo</th><th>Vel. sup./imm.</th><th>Autonomia</th><th>Siluri</th><th>Quota prova</th><th>Equipaggio</th><th></th></tr>
    <?php foreach ($tipi as $t): ?>
      <tr<?= (string) $t['type_key'] === (string) $boat['type_key'] ? ' style="background:rgba(195,154,82,.07)"' : '' ?>>
        <td><?= e($t['name']) ?><?= $t['soprannome'] ? ' <span style="color:var(--testo-3)">· ' . e($t['soprannome']) . '</span>' : '' ?></td>
        <td><?= e(number_format((float) $t['speed_surf_kn'], 1, ',', '')) ?> / <?= e(number_format((float) $t['speed_sub_kn'], 1, ',', '')) ?> kn</td>
        <td><?= e(number_format((float) $t['range1_nm'], 0, ',', '.')) ?> nm</td>
        <td><?= e($t['torpedoes']) ?></td>
        <td><?= e($t['test_depth_m']) ?> m</td>
        <td><?= e($t['crew_max']) ?></td>
        <td>
          <?php if ((string) $t['type_key'] === (string) $boat['type_key']): ?>
            <span style="color:var(--ottone)">assegnato</span>
          <?php elseif ((int) $t['unlock_rank'] > $grado): ?>
            <span style="color:var(--testo-3)">anzianità insufficiente</span>
          <?php else: ?>
            <form method="post" action="<?= e(url('/cantiere/tipo')) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="type_key" value="<?= e($t['type_key']) ?>">
              <button type="submit" class="bottone--fantasma" style="padding:.25rem .7rem;font-size:.72rem">assegna</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="pannello">
  <h2>Emblema di torretta</h2>
  <p class="sommario">
    Il segno dipinto sulla vela: è così che dalla rada si riconosce chi rientra, prima ancora
    di leggere il distintivo. <b>Lo stesso emblema può stare su più torrette</b>: molti erano
    di flottiglia e non di battello — il toro infuriato di Prien, da U-47, diventò il segno di
    tutta la 7. U-Flottille, e lo portavano decine di battelli insieme. Qui sotto, accanto a
    ciascuno, c'è scritto chi altro lo porta: è una informazione, non un divieto.
  </p>

  <?php if (($emblema ?? null) !== null): ?>
    <div style="display:flex;gap:1.2rem;align-items:center;margin:1rem 0;padding:.9rem;
                background:var(--acciaio-0);border:1px solid var(--bordo);border-radius:var(--raggio)">
      <img class="emblema-tondo" src="<?= e(asset($emblema['url'])) ?>" width="84" height="84"
           alt="<?= e($emblema['nome']) ?>"
           data-emblema="<?= e($emblema['nome']) ?>"
           data-emblema-motto="<?= e((string) ($emblema['motto'] ?? '')) ?>">
      <div>
        <b style="color:var(--ottone)"><?= e($emblema['nome']) ?></b>
        <div style="color:var(--testo-3);font-size:.82rem">
          <?= $emblema['origine'] === 'caricato' ? 'Portato da casa dal comandante.' : 'Dal repertorio.' ?>
        </div>
        <form method="post" action="<?= e(url('/cantiere/emblema')) ?>" style="margin-top:.5rem">
          <?= csrf_field() ?>
          <input type="hidden" name="chiave" value="">
          <button type="submit" class="bottone--fantasma"
                  style="padding:.3rem .8rem;font-size:.74rem">Ripulisci la torretta</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <h3>Repertorio</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.9rem">
    <?php foreach (($emblemi ?? []) as $em): ?>
      <div style="padding:.8rem;border:1px solid var(--<?= $em['e_mio'] ? 'ottone-2' : 'bordo' ?>);
                  border-radius:var(--raggio);background:var(--acciaio-0);text-align:center">
        <img class="emblema-tondo" src="<?= e(asset($em['url'])) ?>" width="72" height="72"
             alt="<?= e($em['nome']) ?>"
             data-emblema="<?= e($em['nome']) ?>" data-emblema-motto="<?= e($em['motto']) ?>"
             loading="lazy">
        <div style="margin-top:.4rem"><b style="font-size:.86rem"><?= e($em['nome']) ?></b></div>
        <div style="color:var(--testo-3);font-size:.72rem;font-style:italic;margin:.2rem 0 .4rem">
          <?= e($em['motto']) ?>
        </div>
        <div class="dichiarato" title="<?= e($em['storia']) ?>"
             style="color:var(--testo-3);font-size:.68rem;margin-bottom:.5rem">
          <?= $em['confidence'] === 'alta' ? 'soggetto documentato' : 'soggetto ricostruito' ?>
        </div>
        <?php if ($em['lo_portano'] !== []): ?>
          <div style="color:var(--testo-3);font-size:.68rem;margin-bottom:.4rem"
               title="Molti segni di torretta erano di flottiglia, non di battello: li portavano in tanti insieme.">
            lo porta anche <?= e(implode(', ', array_slice($em['lo_portano'], 0, 3)))
              ?><?= count($em['lo_portano']) > 3 ? ' e altri ' . e((string) (count($em['lo_portano']) - 3)) : '' ?>
          </div>
        <?php endif; ?>
        <?php if ($em['e_mio']): ?>
          <span style="color:var(--ottone);font-size:.74rem">è il tuo</span>
        <?php else: ?>
          <form method="post" action="<?= e(url('/cantiere/emblema')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="chiave" value="<?= e($em['chiave']) ?>">
            <button type="submit" style="padding:.3rem .8rem;font-size:.74rem">Adotta</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 style="margin-top:1.4rem">Oppure il tuo</h3>
  <form method="post" action="<?= e(url('/cantiere/emblema/carica')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="file" name="emblema" accept="image/png,image/jpeg,image/webp" required
           style="max-width:22rem">
    <div class="comandi" style="margin-top:.6rem">
      <button type="submit">Porta a bordo</button>
    </div>
    <p class="aiuto">
      PNG, JPEG o WebP fino a 3 MB. L'immagine viene ritagliata quadrata e ridisegnata a 256 pixel:
      quello che finisce sul server è un file costruito qui, senza niente di quello che c'era dentro
      all'originale. Gli SVG non si accettano — un SVG può contenere codice.
      Due comandanti non possono caricare la stessa identica immagine.
    </p>
  </form>
</div>
