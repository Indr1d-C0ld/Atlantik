<?php
/** @var array $boat */
use App\Game\Dottrina;

$attuale = Dottrina::di($boat);
?>
<div class="pannello" id="dottrina" style="margin-top:1.2rem">
  <h2>Dottrina d'ingaggio — gli ordini al I.WO</h2>
  <p class="sommario">
    Il mare non aspetta che il comandante sia in plancia. Quando non ci sei, il battello lo conduce il
    Primo Ufficiale, e attacca solo quello che gli hai lasciato scritto. Quando torni e apri la stazione
    d'attacco, il comando torna a te.
  </p>
  <form method="post" action="<?= e(url('/dottrina')) ?>">
    <?= csrf_field() ?>
    <?php foreach (Dottrina::DOTTRINE as $chiave => $d): ?>
      <div class="campo">
        <label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:0;color:var(--testo)">
          <input type="radio" name="dottrina" value="<?= e($chiave) ?>" <?= $attuale === $chiave ? 'checked' : '' ?>>
          <?= e($d['nome']) ?>
        </label>
        <p class="aiuto"><?= e(ucfirst($d['spiega'])) ?>.</p>
      </div>
    <?php endforeach; ?>
    <button type="submit">Lascia l'ordine</button>
  </form>
</div>
