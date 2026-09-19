<?php
/** @var array $basi @var int $eredita @var list $fascicoli @var \App\Sim\Clock $clock */
?>
<form method="post" action="<?= e(url('/comandante/crea')) ?>" enctype="multipart/form-data">
<div class="pannello pannello--stretto">
  <span class="targhetta">Befehlshaber der U-Boote — assegnazione</span>
  <h1>Il tuo comandante</h1>

  <?php if ($fascicoli !== []): ?>
    <div class="avviso avviso--attenzione">
      Il comandante precedente non è più in servizio. Il nuovo eredita una quota della reputazione
      di flottiglia — <b><?= e(number_format($eredita, 0, ',', '.')) ?> di prestigio</b> — ma non i gradi
      e non le decorazioni: quelle restano nel fascicolo di chi le ha guadagnate.
    </div>
  <?php else: ?>
    <p class="sommario">
      Si comincia da Oberleutnant zur See, con un battello stanco e un equipaggio mediocre.
      Il grado, le decorazioni e i battelli migliori si guadagnano in mare, e non è detto che ci sia tempo.
    </p>
  <?php endif; ?>

    <?= csrf_field() ?>

    <div class="campo">
      <label for="nome">Nome del comandante</label>
      <input type="text" id="nome" name="nome" value="<?= e(old('nome')) ?>" maxlength="64" required autofocus>
      <p class="aiuto">Nome e cognome, come andrà scritto sul giornale di guerra.</p>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div class="campo" style="flex:1;min-width:11rem">
        <label for="nato_il">Data di nascita</label>
        <input type="text" id="nato_il" name="nato_il" value="<?= e(old('nato_il', '22/05/1913')) ?>" placeholder="GG/MM/AAAA" inputmode="numeric">
      </div>
      <div class="campo" style="flex:1;min-width:11rem">
        <label for="nato_a">Luogo di nascita</label>
        <input type="text" id="nato_a" name="nato_a" value="<?= e(old('nato_a')) ?>" maxlength="64" placeholder="Wilhelmshaven">
      </div>
    </div>

    <div class="campo">
      <label for="base">Flottiglia di assegnazione</label>
      <select id="base" name="base" style="width:100%;padding:.6rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:1rem var(--mono)">
        <?php foreach ($basi as $b): ?>
          <option value="<?= e($b['port_key']) ?>"><?= e($b['name']) ?> — <?= e($b['flotillas']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="aiuto">La base decide da dove si esce e quanto mare c'è prima della zona di caccia.</p>
    </div>

</div>

<div class="pannello" style="margin-top:1.2rem">
  <div class="campo">
      <label>Ritratto <span style="color:var(--testo-3);text-transform:none;letter-spacing:0">— facoltativo</span></label>
      <p class="aiuto" style="margin:0 0 .7rem">
        Fotografie di comandanti di U-Boot realmente esistiti. <b>Un volto per comandante</b>,
        finché quel comandante è in servizio: quelli portati da qualcuno che è ancora in mare
        non compaiono qui, e tornano disponibili quando lui non c'è più. Puoi anche prenderti
        il nome del comandante ritratto — è un omaggio, e il gioco lo dice sul tuo fascicolo.
        Se non scegli niente, il fascicolo resta senza foto e potrai metterne una dopo,
        anche una tua.
      </p>
      <?= partial('scelta_ritratto', ['elenco' => $ritratti, 'scelto' => null]) ?>

      <label style="display:flex;align-items:center;gap:.4rem;margin-top:.7rem;text-transform:none;letter-spacing:0;color:var(--testo-2)">
        <input type="checkbox" name="nome_storico" value="1"> prendi anche il nome del comandante ritratto
      </label>
    </div>

    <div class="campo">
      <label for="c-file">…oppure una fotografia tua</label>
      <input type="file" id="c-file" name="ritratto" accept="image/png,image/jpeg,image/webp" data-ritaglio>
      <p class="aiuto">
        PNG, JPEG o WebP, fino a 5 MB. Se scegli un file, questo ha la precedenza sul ritratto
        del repertorio. Puoi decidere tu l'inquadratura qui sotto; se non lo fai, viene presa
        quadrata dal centro, un po' alzata, perché in un ritratto la testa sta in alto.
        <b>Resta tua</b>: non entra nel repertorio e nessun altro può sceglierla.
      </p>
    </div>
    <div class="campo">
      <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:0;color:var(--testo-2)">
        <input type="checkbox" name="invecchia" value="1"> rendila d'epoca
      </label>
      <p class="aiuto">
        Bianco e nero neutro, contrasto ed esposizione portati a quelli delle fotografie vere
        della galleria, un filo di sfocatura, grana e angoli più scuri. Niente seppia: misurato
        sui 508 volti storici, la dominante di colore ha mediana zero.
      </p>
    </div>


  <div class="azioni">
    <button type="submit">Prendere servizio</button>
    <a class="bottone bottone--fantasma" href="<?= e(url('/albo')) ?>">Albo d'oro</a>
  </div>
</div>
</form>

<?php if ($fascicoli !== []): ?>
<div class="pannello pannello--stretto">
  <h2>I tuoi comandanti</h2>
  <table class="dati">
    <tr><th>Nome</th><th>Grado</th><th>Patrol</th><th>GRT</th><th>Sorte</th></tr>
    <?php foreach ($fascicoli as $f): ?>
      <tr>
        <td><?= e($f['nome']) ?></td>
        <td><?= e(\App\Game\Carriera::gradoNome((int) $f['grado'])) ?></td>
        <td><?= e($f['patrols']) ?></td>
        <td><?= e(number_format((float) $f['grt_affondato'], 0, ',', '.')) ?></td>
        <td style="color:<?= $f['stato'] === 'attivo' ? 'var(--verde)' : 'var(--testo-3)' ?>"><?= e($f['stato']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script src="<?= e(asset('js/ritratti.js')) ?>" defer></script>
<script src="<?= e(asset('js/invecchia.js')) ?>" defer></script>
<script src="<?= e(asset('js/ritaglio.js')) ?>" defer></script>
