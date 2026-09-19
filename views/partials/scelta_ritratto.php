<?php
/**
 * Il selettore del ritratto.
 *
 * @var list  $elenco   catalogo ridotto (App\Game\Ritratto::elenco())
 * @var ?string $scelto chiave gia' scelta, se c'e'
 *
 * Cinquecento volti in un documento sono cinquecento tag <img>, e si sentono.
 * Qui il server disegna solo la PRIMA pagina — che e' anche quello che vede chi
 * ha JavaScript spento, e con quaranta volti puo' comunque scegliere — e manda
 * giu' l'elenco intero in un attributo, in forma compatta. Il resto lo disegna
 * assets/js/ritratti.js, che si prende anche la ricerca su tutti e cinquecento.
 */
use App\Game\Ritratto;

$per = Ritratto::PER_PAGINA;
$prima = array_slice($elenco, 0, $per);
$liberi = count(array_filter($elenco, static fn (array $r): bool => $r['p'] === null));
?>
<div id="scelta-ritratti" data-elenco="<?= e(json_encode($elenco, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
     data-per="<?= e((string) $per) ?>" data-scelto="<?= e((string) ($scelto ?? '')) ?>">

  <p class="aiuto" data-conteggio>
    <?= e((string) count($elenco)) ?> ritratti, <?= e((string) $liberi) ?> liberi.
    Ne vedi <?= e((string) count($prima)) ?> per pagina.
  </p>

  <div class="griglia-ritratti" data-griglia>
    <label class="scelta-ritratto" data-fisso>
      <div class="profilo-ritratto--vuoto" style="aspect-ratio:1">nessuna</div>
      <b>Senza ritratto</b>
      <input type="radio" name="ritratto_key" value=""<?= ($scelto ?? '') === '' ? ' checked' : '' ?>>
    </label>
    <?php foreach ($prima as $r): ?>
      <?php $bloccato = $r['p'] !== null; ?>
      <label class="scelta-ritratto<?= $bloccato ? ' preso' : '' ?>"
             title="<?= e($r['n'] . ($bloccato ? ' — lo porta ' . $r['p'] . ', in servizio' : '')) ?>">
        <img src="<?= e(asset($r['u'])) ?>" alt="<?= e($r['n']) ?>" width="320" height="320"
             loading="lazy" decoding="async">
        <b><?= e($r['n']) ?></b>
        <?php if ($r['a'] !== ''): ?><small><?= e($r['a']) ?></small><?php endif; ?>
        <?php if ($bloccato): ?>
          <small style="color:var(--ambra)">in servizio: <?= e((string) $r['p']) ?></small>
        <?php else: ?>
          <input type="radio" name="ritratto_key" value="<?= e($r['k']) ?>"<?= ($scelto ?? '') === $r['k'] ? ' checked' : '' ?>>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
</div>
