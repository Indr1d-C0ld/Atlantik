<?php /** @var array $patrol @var list $affondamenti @var \App\Sim\Clock $clock */ ?>
<?= partial('nav_plancia', ['attiva' => 'comandante']) ?>

<div class="pannello">
  <span class="targhetta">Befehlshaber der U-Boote</span>
  <h1>Rapporto di missione n. <?= e($patrol['number']) ?></h1>
  <pre style="white-space:pre-wrap;font:.9rem/1.6 var(--mono);color:var(--testo-2);background:var(--acciaio-0);padding:1rem;border:1px solid var(--bordo);border-radius:var(--raggio)"><?= e($patrol['rapporto'] ?? 'Rapporto non compilato.') ?></pre>

  <?php if ($affondamenti !== []): ?>
    <h2 style="margin-top:1.4rem">Naviglio affondato</h2>
    <table class="dati">
      <tr><th>Data</th><th></th><th>Nave</th><th>Bandiera</th><th>GRT</th><th>Carico</th><th>Quadrato</th><th>Arma</th></tr>
      <?php foreach ($affondamenti as $a): ?>
        <tr>
          <td><?= e($clock->format((int) $a['gts'])) ?></td>
          <td style="width:7rem"><?= partial('segnaposto', ['lega' => (string) ($a['class_key'] ?? ''), 'h' => 26]) ?></td>
          <td><?= e($a['nome']) ?></td>
          <td style="color:var(--testo-3)"><?= e($a['bandiera']) ?></td>
          <td><?= e(number_format((float) $a['grt'], 0, ',', '.')) ?></td>
          <td style="color:var(--testo-3)"><?= e($a['carico'] ?? '—') ?></td>
          <td><?= e($a['quadrat'] ?? '—') ?></td>
          <td><?= e($a['arma']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nota-segnaposto">
      Le sagome hanno tre provenienze: profili dell'<i>ONI 208 — Merchant Ship Recognition Manual</i>
      (Division of Naval Intelligence, pubblico dominio), sagome in scala costruite su misure
      documentate, e ricostruzioni disegnate. Passa sopra a una sagoma per sapere quale delle tre,
      e da dove vengono i dati. In ogni caso stanno qui perché il naviglio è già stato identificato
      e affondato, non per riconoscerlo — e una classe senza sagoma mostra solo il nome.
    </p>
  <?php endif; ?>

  <div class="azioni"><a class="bottone" href="<?= e(url('/comandante')) ?>">Torna al fascicolo</a></div>
</div>
