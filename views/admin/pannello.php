<?php
/** @var list $utenti @var array $config @var array|null $tick @var list $ticks
 *  @var array $traffico @var array $mondo @var \App\Sim\Clock $clock @var int $now */
use App\Sim\Clock as C;
?>
<?= partial('nav_plancia', ['attiva' => '']) ?>

<?= partial('nav_admin', ['attiva' => 'pannello']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Pannello</h1>
  <div class="quadranti">
    <div class="quadrante">
      <div class="etichetta">Ora di gioco</div>
      <div class="valore" style="font-size:1.1rem"><?= e($clock->format($now)) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Ultimo battito</div>
      <div class="valore" style="font-size:1.1rem;color:<?= $tick !== null && (int) $tick['ok'] === 1 ? 'var(--verde-fosf)' : 'var(--rosso)' ?>">
        <?= $tick !== null ? e(fmt_dt($tick['started_at'], true)) : 'mai' ?>
      </div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Naviglio in mare</div>
      <div class="valore"><?= e(number_format((float) $traffico['navi'], 0, ',', '.')) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Seme del mondo</div>
      <div class="valore" style="font-size:1rem"><?= e($mondo['seed']) ?></div>
    </div>
  </div>
</div>

<div class="pannello">
  <h2>Battiti recenti</h2>
  <table class="dati">
    <tr><th>Avviato</th><th>Esito</th><th>Durata</th><th>Lavoro</th></tr>
    <?php foreach ($ticks as $t): ?>
      <tr>
        <td><?= e(fmt_dt($t['started_at'], true)) ?></td>
        <td style="color:<?= (int) $t['ok'] === 1 ? 'var(--verde)' : 'var(--rosso)' ?>"><?= (int) $t['ok'] === 1 ? 'ok' : 'errore' ?></td>
        <td><?= e($t['duration_ms']) ?> ms</td>
        <?php $lavoro = \App\Game\Mondo::lavoroBattito($t['tasks'] ?? null); ?>
        <td style="font-size:.82rem;color:<?= $lavoro['niente'] ? 'var(--testo-3)' : 'var(--testo-2)' ?>"
            title="<?= e($lavoro['grezzo']) ?>"><?= e($lavoro['testo']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="pannello">
  <h2>Bilanciamento a caldo</h2>
  <p class="sommario">Le chiavi cambiano il comportamento del motore senza deploy. Ogni modifica finisce nel registro delle azioni.</p>
  <table class="dati">
    <tr><th>Chiave</th><th>Tipo</th><th>Valore</th><th></th></tr>
    <?php foreach ($config as $k => $v): ?>
      <tr>
        <td style="font-family:var(--mono);font-size:.82rem"><?= e($k) ?></td>
        <td style="color:var(--testo-3)"><?= e($v['type']) ?></td>
        <td>
          <form method="post" action="<?= e(url('/admin/config')) ?>" style="display:flex;gap:.3rem;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="chiave" value="<?= e($k) ?>">
            <input type="text" name="valore" value="<?= e($v['value']) ?>" style="width:7rem;padding:.25rem .4rem;font-size:.82rem">
            <button type="submit" class="bottone--fantasma" style="padding:.2rem .6rem;font-size:.7rem">salva</button>
          </form>
        </td>
        <td style="color:var(--testo-3);font-size:.78rem"><?= e($v['note'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="pannello">
  <h2>Utenti</h2>
  <table class="dati">
    <tr><th>ID</th><th>Utente</th><th>E-mail</th><th>Stato</th><th>Ruolo</th><th>Comandanti</th><th>Iscritto</th><th></th></tr>
    <?php foreach ($utenti as $u): ?>
      <tr>
        <td><?= e($u['id']) ?></td>
        <td><?= e($u['username']) ?></td>
        <td style="color:var(--testo-3);font-size:.8rem"><?= e($u['email']) ?></td>
        <td style="color:<?= $u['status'] === 'active' ? 'var(--verde)' : ($u['status'] === 'pending' ? 'var(--ambra)' : 'var(--rosso)') ?>"><?= e($u['status']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= e($u['comandanti']) ?></td>
        <td style="color:var(--testo-3);font-size:.8rem"><?= e(fmt_date($u['created_at'])) ?></td>
        <td>
          <form method="post" action="<?= e(url('/admin/utente')) ?>" style="display:flex;gap:.25rem">
            <?= csrf_field() ?>
            <input type="hidden" name="utente" value="<?= e($u['id']) ?>">
            <?php foreach (['attiva' => 'attiva', 'sospendi' => 'sospendi', 'bandisci' => 'bandisci'] as $a => $n): ?>
              <button type="submit" name="azione" value="<?= e($a) ?>" class="bottone--fantasma"
                      style="padding:.15rem .45rem;font-size:.68rem"><?= e($n) ?></button>
            <?php endforeach; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
