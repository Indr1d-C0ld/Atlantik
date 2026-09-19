<?php
/** @var list $righe @var list $perIp @var list $freni @var list $sospetti */
$ip = static function ($b): string {
    if ($b === null || $b === '') { return '—'; }
    $t = @inet_ntop($b);
    return $t === false ? '—' : $t;
};
?>
<?= partial('nav_admin', ['attiva' => 'accessi']) ?>

<div class="pannello">
  <span class="targhetta">Amministrazione</span>
  <h1>Accessi e origine</h1>
  <p class="sommario">
    Da dove si entra. Gli indirizzi sono conservati in forma binaria e qui tornano leggibili.
    <b>Non c'è geolocalizzazione e non ci sarà</b>: manderebbe l'indirizzo di un giocatore a un
    servizio di terzi, che è esattamente il genere di cosa che questo progetto non fa.
  </p>

  <?php if ($sospetti !== []): ?>
    <h2 style="color:var(--ambra)">Da tenere d'occhio</h2>
    <p class="aiuto">Indirizzi con cinque o più accessi falliti nelle ultime ventiquattro ore.</p>
    <table class="dati">
      <tr><th>Indirizzo</th><th>Tentativi falliti</th></tr>
      <?php foreach ($sospetti as $s): ?>
        <tr><td><?= e($ip($s['ip'])) ?></td><td style="color:var(--ambra)"><?= e($s['n']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h2 style="margin-top:1.4rem">Per indirizzo</h2>
  <table class="dati">
    <tr><th>Indirizzo</th><th>Tentativi</th><th>Riusciti</th><th>Falliti</th><th>Account</th><th>Primo</th><th>Ultimo</th></tr>
    <?php foreach ($perIp as $r): ?>
      <tr>
        <td><?= e($ip($r['ip'])) ?></td>
        <td><?= e($r['tentativi']) ?></td>
        <td style="color:var(--verde)"><?= e($r['riusciti']) ?></td>
        <td style="color:<?= (int) $r['falliti'] > 0 ? 'var(--ambra)' : 'var(--testo-3)' ?>"><?= e($r['falliti']) ?></td>
        <td<?= (int) $r['utenti'] > 1 ? ' style="color:var(--ambra)"' : '' ?>><?= e($r['utenti']) ?></td>
        <td style="color:var(--testo-3)"><?= e(fmt_dt($r['primo'])) ?></td>
        <td style="color:var(--testo-3)"><?= e(fmt_dt($r['ultimo'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="aiuto">
    Più account dallo stesso indirizzo non vuol dire niente di per sé — una famiglia, un ufficio,
    una rete mobile. Vuol dire solo che è una cosa da guardare, non da concludere.
  </p>
</div>

<div class="pannello">
  <h2>Ultimi accessi</h2>
  <table class="dati">
    <tr><th>Quando</th><th>Cosa</th><th>Account</th><th>Da</th></tr>
    <?php foreach ($righe as $a): ?>
      <?php $fallito = (string) $a['action'] === 'auth.login_failed'; ?>
      <tr<?= $fallito ? ' style="color:var(--ambra)"' : '' ?>>
        <td><?= e(fmt_dt($a['created_at'], true)) ?></td>
        <td><?= e(str_replace('auth.', '', (string) $a['action'])) ?></td>
        <td><?php if ($a['actor_user_id'] !== null): ?>
              <a href="<?= e(url('/admin/utente/' . (int) $a['actor_user_id'])) ?>"><?= e($a['username'] ?? ('#' . $a['actor_user_id'])) ?></a>
            <?php else: ?>
              <span style="color:var(--testo-3)">sconosciuto</span>
            <?php endif; ?></td>
        <td><?= e($ip($a['ip'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($freni !== []): ?>
<div class="pannello">
  <h2>Freni attivi</h2>
  <p class="aiuto">Contatori del limitatore: iscrizioni, accessi e azioni di gioco.</p>
  <table class="dati">
    <tr><th>Chiave</th><th>Colpi</th><th>Scade</th></tr>
    <?php foreach ($freni as $f): ?>
      <tr><td class="macchina" style="font:.8rem var(--mono)"><?= e($f['rkey']) ?></td><td><?= e($f['hits']) ?></td><td style="color:var(--testo-3)"><?= e(fmt_dt($f['reset_at'], true)) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
