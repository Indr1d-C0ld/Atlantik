<?php
/** @var array $boat @var array $type @var list $ruolino @var array $ciurma @var int $guardia
 *  @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Crew;

$perTurno = [0 => [], 1 => [], 2 => [], 3 => []];
foreach ($ruolino as $m) { $perTurno[(int) $m['watch_no']][] = $m; }
?>
<?= partial('nav_plancia', ['attiva' => 'equipaggio']) ?>
<?= partial('intestazione_battello', compact('boat', 'type', 'clock', 'now')) ?>

<div class="griglia-plancia">
  <div class="strumento">
    <h3>Ruolino</h3>
    <div class="riga"><span class="etichetta">Uomini a bordo</span><span class="valore grande"><?= e($ciurma['uomini']) ?></span></div>
    <div class="riga"><span class="etichetta">Feriti</span><span class="valore piccolo"><?= e($ciurma['feriti']) ?></span></div>
    <div class="riga"><span class="etichetta">Competenza media</span><span class="valore piccolo"><?= e(number_format($ciurma['competenza'], 0, ',', '')) ?>/100</span></div>
    <div class="riga"><span class="etichetta">Guardia in servizio</span><span class="valore piccolo"><?= e($guardia) ?>ª</span></div>
  </div>

  <div class="strumento">
    <h3>Morale</h3>
    <div class="riga"><span class="etichetta">Umore a bordo</span><span class="valore grande"><?= e(number_format($ciurma['morale'], 0, ',', '')) ?></span></div>
    <div class="misuratore <?= $ciurma['morale'] < 30 ? 'allarme' : ($ciurma['morale'] < 50 ? 'attenzione' : '') ?>"><i style="width:<?= e(number_format($ciurma['morale'], 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Giudizio</span><span class="valore piccolo"><?= e(Crew::statoMorale($ciurma['morale'])) ?></span></div>
    <p class="aiuto">Sale con l'aria aperta, il cibo e le riparazioni riuscite. Scende con le settimane di mare, le avarie, la burrasca e le ore sott'acqua.</p>
  </div>

  <div class="strumento">
    <h3>Stanchezza</h3>
    <div class="riga"><span class="etichetta">Media</span><span class="valore grande"><?= e(number_format($ciurma['fatica'], 0, ',', '')) ?></span></div>
    <div class="misuratore <?= $ciurma['fatica'] > 75 ? 'allarme' : ($ciurma['fatica'] > 55 ? 'attenzione' : '') ?>"><i style="width:<?= e(number_format($ciurma['fatica'], 1, '.', '')) ?>%"></i></div>
    <div class="riga"><span class="etichetta">Giudizio</span><span class="valore piccolo">gli uomini sono <?= e(Crew::statoFaticaPlurale($ciurma['fatica'])) ?></span></div>
    <p class="aiuto">Il rendimento di ogni stazione è competenza × riposo × morale: un equipaggio bravo ma sfinito sbaglia quanto uno scarso.</p>
  </div>
</div>

<?php foreach ([1 => '1ª guardia', 2 => '2ª guardia', 3 => '3ª guardia', 0 => 'A giornata (fuori dai quarti)'] as $t => $titolo): ?>
  <?php if (empty($perTurno[$t])) { continue; } ?>
  <div class="pannello">
    <h2><?= e($titolo) ?><?= $t === $guardia ? ' — in servizio adesso' : '' ?> <span style="color:var(--testo-3);font-weight:400">(<?= count($perTurno[$t]) ?> uomini)</span></h2>
    <table class="dati">
      <tr><th>Nome</th><th>Grado</th><th>Incarico</th><th>Compart.</th><th>Comp.</th><th>Stanch.</th><th>Morale</th><th>Turno</th></tr>
      <?php foreach ($perTurno[$t] as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td>
          <td style="color:var(--testo-3)"><?= e($m['rank_name']) ?></td>
          <td><?= e($m['role_name']) ?></td>
          <td style="color:var(--testo-3)"><?= e($m['station']) ?></td>
          <td><?= e(number_format((float) $m['competence'], 0, ',', '')) ?></td>
          <td><?= e(Crew::statoFatica((float) $m['fatigue'])) ?></td>
          <td><?= e(Crew::statoMorale((float) $m['morale'])) ?></td>
          <td>
            <form method="post" action="<?= e(url('/turno')) ?>" style="display:flex;gap:.2rem">
              <?= csrf_field() ?>
              <input type="hidden" name="crew_id" value="<?= e($m['id']) ?>">
              <?php foreach ([1, 2, 3, 0] as $n): ?>
                <?php if ($n === (int) $m['watch_no']) { continue; } ?>
                <button type="submit" name="watch" value="<?= $n ?>" class="bottone--fantasma"
                        style="padding:.1rem .4rem;font-size:.65rem" title="Sposta <?= $n === 0 ? 'a giornata' : "alla {$n}ª guardia" ?>">
                  <?= $n === 0 ? 'g' : $n ?>
                </button>
              <?php endforeach; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; ?>
