<?php
/** @var list $righe @var int $totale @var int $pagina @var int $per_pagina
 *  @var string $area @var string $cerca @var array $conteggi */
use App\Support\Registro;

// Gli indirizzi stanno in tabella come byte (VARBINARY): si rileggono qui, e
// non si geolocalizzano — non si e' mai fatto e non si fa.
$ip = static function ($b): string {
    if ($b === null || $b === '') { return '—'; }
    $t = @inet_ntop($b);
    return $t === false ? '—' : $t;
};

$pagine = max(1, (int) ceil($totale / max(1, $per_pagina)));
$url = static function (array $q) use ($area, $cerca): string {
    return url('/admin/registro') . '?' . http_build_query(array_merge(
        ['area' => $area, 'cerca' => $cerca],
        $q
    ));
};
?>
<?= partial('nav_admin', ['attiva' => 'registro']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Registro delle azioni</h1>
  <p class="sommario">
    Tutto quello che è stato fatto, e da chi. Non solo gli accessi: le manopole girate, i
    provvedimenti sugli account, le forzature del tempo, la moderazione della bacheca.
    Il dettaglio è raccontato; la riga originale resta nel suggerimento del mouse.
  </p>

  <form method="get" action="<?= e(url('/admin/registro')) ?>" class="filtri-registro">
    <select name="area">
      <option value="">ogni area (<?= e((string) $totale) ?>)</option>
      <?php foreach (Registro::AREE as $k => $et): ?>
        <option value="<?= e($k) ?>"<?= $area === $k ? ' selected' : '' ?>>
          <?= e($et) ?><?= isset($conteggi[$k]) ? ' (' . (int) $conteggi[$k] . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="cerca" value="<?= e($cerca) ?>" placeholder="account, azione o dettaglio">
    <button type="submit">Cerca</button>
    <?php if ($area !== '' || $cerca !== ''): ?>
      <a class="bottone bottone--fantasma" href="<?= e(url('/admin/registro')) ?>">tutto</a>
    <?php endif; ?>
  </form>

  <?php if ($righe === []): ?>
    <p class="sommario">Nessuna riga risponde a questa ricerca.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Quando</th><th>Chi</th><th>Che cosa</th><th>Su</th><th>Dettaglio</th><th>Da</th></tr>
      <?php foreach ($righe as $r): ?>
        <?php $d = Registro::dettaglio($r['meta'] ?? null); ?>
        <tr>
          <td style="white-space:nowrap"><?= e(fmt_dt($r['created_at'], true)) ?></td>
          <td>
            <?php if ($r['actor_user_id'] !== null): ?>
              <a href="<?= e(url('/admin/utente/' . (int) $r['actor_user_id'])) ?>"><?= e((string) ($r['username'] ?? ('#' . $r['actor_user_id']))) ?></a>
            <?php else: ?>
              <span style="color:var(--testo-3)">—</span>
            <?php endif; ?>
          </td>
          <td<?= Registro::grave((string) $r['action']) ? ' style="color:var(--ambra)"' : '' ?>
              title="<?= e((string) $r['action']) ?>"><?= e(Registro::etichetta((string) $r['action'])) ?></td>
          <td style="color:var(--testo-3);font-size:.8rem">
            <?= e((string) ($r['target_type'] ?? '—')) ?><?= $r['target_id'] !== null ? ' #' . e((string) $r['target_id']) : '' ?>
          </td>
          <td style="font-size:.8rem;color:var(--testo-2)" title="<?= e($d['grezzo']) ?>"><?= e($d['testo']) ?></td>
          <td class="macchina" style="font:.76rem var(--mono);color:var(--testo-3)"><?= e($ip($r['ip'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <?php if ($pagine > 1): ?>
      <div class="piede-registro">
        <?php if ($pagina > 1): ?>
          <a class="bottone bottone--fantasma" href="<?= e($url(['pagina' => $pagina - 1])) ?>">← precedenti</a>
        <?php endif; ?>
        <span>pagina <?= e((string) $pagina) ?> di <?= e((string) $pagine) ?> — <?= e((string) $totale) ?> righe</span>
        <?php if ($pagina < $pagine): ?>
          <a class="bottone bottone--fantasma" href="<?= e($url(['pagina' => $pagina + 1])) ?>">successive →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
