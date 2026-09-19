<?php
/** @var list $utenti @var list $conteggi @var string $cerca @var string $stato */
$per = [];
foreach ($conteggi as $c) { $per[(string) $c['status']] = (int) $c['n']; }
?>
<?= partial('nav_admin', ['attiva' => 'utenti']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Utenti</h1>

  <div class="quadranti">
    <?php foreach (['active' => 'attivi', 'pending' => 'in attesa', 'suspended' => 'sospesi', 'banned' => 'revocati'] as $k => $et): ?>
      <div class="quadrante">
        <div class="etichetta"><?= e($et) ?></div>
        <div class="valore"><?= e((string) ($per[$k] ?? 0)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= e(url('/admin/utenti')) ?>" style="margin:1rem 0;display:flex;gap:.6rem;flex-wrap:wrap">
    <input type="search" name="cerca" value="<?= e($cerca) ?>" placeholder="nome o e-mail" style="max-width:16rem">
    <select name="stato" style="max-width:10rem">
      <option value="">ogni stato</option>
      <?php foreach (['active','pending','suspended','banned'] as $s): ?>
        <option value="<?= e($s) ?>"<?= $stato === $s ? ' selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Cerca</button>
  </form>

  <table class="dati">
    <tr><th>#</th><th>Utente</th><th>E-mail</th><th>Stato</th><th>Ruolo</th><th>Com.</th><th>Miss.</th><th>GRT</th><th>Ultimo accesso</th><th></th></tr>
    <?php foreach ($utenti as $u): ?>
      <tr>
        <td><?= e($u['id']) ?></td>
        <td><b><?= e($u['username']) ?></b></td>
        <td style="color:var(--testo-3)"><?= e($u['email']) ?></td>
        <td style="color:<?= (string) $u['status'] === 'active' ? 'var(--verde)' : ((string) $u['status'] === 'pending' ? 'var(--ambra)' : 'var(--rosso)') ?>"><?= e($u['status']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= e($u['comandanti']) ?></td>
        <td><?= e($u['missioni']) ?></td>
        <td><?= e(number_format((float) $u['grt'], 0, ',', '.')) ?></td>
        <td style="color:var(--testo-3)"><?= $u['last_login_at'] !== null ? e(fmt_dt($u['last_login_at'])) : 'mai' ?></td>
        <td><a href="<?= e(url('/admin/utente/' . (int) $u['id'])) ?>">scheda</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($utenti === []): ?>
      <tr><td colspan="10" style="color:var(--testo-3)">Nessun account risponde a questi criteri.</td></tr>
    <?php endif; ?>
  </table>
  <p class="aiuto">Le azioni sullo stato di un account stanno nella sua scheda.</p>
</div>
