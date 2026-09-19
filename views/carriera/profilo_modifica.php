<?php
/** @var array $cmd  @var ?array $ritratto  @var list $catalogo  @var string $azione  @var bool $admin */
use App\Game\Ritratto;

$id = (int) $cmd['id'];
$campoAdmin = $admin ? '<input type="hidden" name="comandante" value="' . $id . '">' : '';
$liberi = count(array_filter($catalogo, static fn (array $r): bool => $r['p'] === null));
$presi  = count($catalogo) - $liberi;
?>

<div class="pannello">
  <div class="targhetta"><?= $admin ? 'Amministrazione' : 'Il tuo fascicolo' ?></div>
  <h1><?= e($cmd['nome']) ?></h1>
  <p class="sommario">
    Il fascicolo è quello che di te vedono gli altri comandanti della flottiglia.
    <a href="<?= e(url('/profilo/' . $id)) ?>">Guardalo come lo vedono loro</a>.
  </p>

  <div class="profilo-testata" style="margin-top:1rem">
    <?php if ($ritratto !== null): ?>
      <figure class="profilo-ritratto" style="margin:0">
        <img src="<?= e(asset($ritratto['url'])) ?>" alt="Ritratto attuale" width="384" height="384">
        <figcaption><?= e(Ritratto::dichiarazione($ritratto)) ?></figcaption>
      </figure>
    <?php else: ?>
      <div class="profilo-ritratto profilo-ritratto--vuoto" style="width:11rem">nessun<br>ritratto</div>
    <?php endif; ?>

    <div class="profilo-dati">
      <?php if ($ritratto !== null): ?>
        <form method="post" action="<?= e(url('/comandante/ritratto/togli')) ?>">
          <?= csrf_field() ?><?= $campoAdmin ?>
          <button type="submit" class="bottone--fantasma">Togli il ritratto</button>
          <p class="aiuto">Toglierlo libera quel volto: un altro comandante potrà prenderlo.</p>
        </form>
      <?php endif; ?>

      <form method="post" action="<?= e(url('/comandante/nota')) ?>" style="margin-top:1.2rem">
        <?= csrf_field() ?><?= $campoAdmin ?>
        <div class="campo">
          <label for="c-nota">Due righe di te, sul fascicolo</label>
          <textarea id="c-nota" name="nota" rows="4" maxlength="500"
                    style="width:100%;padding:.55rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:.92rem var(--sans)"><?= e((string) ($cmd['nota_pubblica'] ?? '')) ?></textarea>
          <p class="aiuto">Cinquecento battute. Le legge chiunque apra il tuo fascicolo.</p>
        </div>
        <button type="submit">Salva</button>
      </form>

      <?php if ($admin): ?>
        <form method="post" action="<?= e(url('/admin/comandante/rinomina')) ?>" style="margin-top:1.2rem">
          <?= csrf_field() ?><?= $campoAdmin ?>
          <div class="campo">
            <label for="c-nome">Nome del comandante</label>
            <input type="text" id="c-nome" name="nome" value="<?= e($cmd['nome']) ?>" maxlength="64">
            <p class="aiuto">Resta unico in tutta la flottiglia: se il nome è già in servizio, il cambio viene respinto.</p>
          </div>
          <button type="submit">Rinomina</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="pannello" style="margin-top:1.2rem">
  <h2>Una fotografia portata da casa</h2>
  <form method="post" action="<?= e(url('/comandante/ritratto/carica')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?><?= $campoAdmin ?>
    <div class="campo">
      <label for="c-file">Immagine</label>
      <input type="file" id="c-file" name="ritratto" accept="image/png,image/jpeg,image/webp" data-ritaglio>
      <p class="aiuto">
        PNG, JPEG o WebP, fino a 5 MB. Viene ritagliata quadrata e riportata alla misura della
        galleria storica, così sta accanto alle altre senza saltare all'occhio. Quello che
        finisce sul server è un'immagine costruita qui, non il file che hai mandato: gli SVG
        non si accettano, perché un SVG può contenere codice.
        <b>Resta tua</b>: una fotografia caricata non entra nel repertorio e nessun altro
        comandante può sceglierla.
      </p>
    </div>
    <div class="campo">
      <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:0;color:var(--testo-2)">
        <input type="checkbox" name="invecchia" value="1"> rendila d'epoca
      </label>
      <p class="aiuto">
        Bianco e nero <b>neutro</b>, contrasto ed esposizione portati a quelli delle fotografie
        vere della galleria, un filo di sfocatura, grana e angoli più scuri. Niente seppia:
        misurato sui 508 volti storici, la dominante di colore ha mediana zero — le stampe alla
        gelatina d'argento sono grigie, il seppia è un luogo comune da cartolina.
        È facoltativo: se la vuoi com'è, lascia stare.
      </p>
    </div>
    <button type="submit">Carica</button>
  </form>
</div>

<div class="pannello" style="margin-top:1.2rem">
  <h2>Il repertorio storico</h2>
  <p class="sommario">
    <?= e((string) count($catalogo)) ?> fotografie di comandanti di U-Boot realmente esistiti.
    <b><?= e((string) $liberi) ?></b> sono libere<?= $presi > 0
      ? ', ' . e((string) $presi) . ' portate da un comandante in servizio' : '' ?>.
    Un volto per comandante — come il nome, come il numero del battello — <b>finché quel
    comandante è in servizio</b>: quando cade, il suo volto torna disponibile per un altro, e
    resta il suo nell'albo d'oro. L'emblema di torretta no: quello si porta in tanti, perché
    molti erano di flottiglia.
  </p>
  <p class="aiuto" style="margin-bottom:1rem">
    Puoi anche prenderti il nome del comandante ritratto. È un omaggio, e il gioco lo dice:
    sul tuo fascicolo comparirà che porti quel nome in memoria, non che sei lui.
  </p>

  <form method="post" action="<?= e(url('/comandante/ritratto')) ?>">
    <?= csrf_field() ?><?= $campoAdmin ?>
    <?= partial('scelta_ritratto', ['elenco' => $catalogo, 'scelto' => $cmd['ritratto_key'] ?? null]) ?>

    <div class="azioni">
      <label style="display:flex;align-items:center;gap:.4rem;text-transform:none;letter-spacing:0;color:var(--testo-2)">
        <input type="checkbox" name="nome_storico" value="1"> prendi anche il suo nome
      </label>
      <button type="submit">Metti nel fascicolo</button>
    </div>
  </form>

  <p class="nota-segnaposto" style="margin-top:1rem">
    Il repertorio è una raccolta messa insieme a mano dal proprietario del gioco: di quei file
    <b>non si conosce la provenienza singola</b>, e il gioco lo dice sotto ogni ritratto invece
    di attribuire una licenza che nessuno ha verificato. Anche i nomi vengono dal nome del file,
    smontato da un lettore automatico: sono giusti quasi sempre, e la scheda lo dichiara.
  </p>
</div>

<script src="<?= e(asset('js/ritratti.js')) ?>" defer></script>
<script src="<?= e(asset('js/invecchia.js')) ?>" defer></script>
<script src="<?= e(asset('js/ritaglio.js')) ?>" defer></script>
