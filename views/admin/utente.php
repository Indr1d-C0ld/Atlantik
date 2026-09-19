<?php
/** @var array $u @var list $comandanti @var list $battelli @var list $missioni
 *  @var list $accessi @var list $posta @var \App\Sim\Clock $clock */
$ip = static function ($b): string {
    if ($b === null || $b === '') { return '—'; }
    $t = @inet_ntop($b);
    return $t === false ? '—' : $t;
};
?>
<?= partial('nav_admin', ['attiva' => 'utenti']) ?>

<div class="pannello">
  <span class="targhetta">Account #<?= e($u['id']) ?></span>
  <h1><?= e($u['username']) ?></h1>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem">
    <div class="strumento">
      <h3>Anagrafica</h3>
      <div class="riga"><span class="etichetta">E-mail</span><span class="valore piccolo"><?= e($u['email']) ?></span></div>
      <div class="riga"><span class="etichetta">Stato</span><span class="valore piccolo"><?= e($u['status']) ?></span></div>
      <div class="riga"><span class="etichetta">Ruolo</span><span class="valore piccolo"><?= e($u['role']) ?></span></div>
      <div class="riga"><span class="etichetta">Iscritto</span><span class="valore piccolo"><?= e(fmt_dt($u['created_at'])) ?></span></div>
      <div class="riga"><span class="etichetta">Confermato</span><span class="valore piccolo"><?= $u['email_verified_at'] !== null ? e(fmt_dt($u['email_verified_at'])) : 'no' ?></span></div>
      <div class="riga"><span class="etichetta">Ultimo accesso</span><span class="valore piccolo"><?= $u['last_login_at'] !== null ? e(fmt_dt($u['last_login_at'])) : 'mai' ?></span></div>
      <div class="riga"><span class="etichetta">Da</span><span class="valore piccolo"><?= e($ip($u['last_login_ip'])) ?></span></div>
      <div class="riga"><span class="etichetta">Visto</span><span class="valore piccolo"><?= e(fmt_dt($u['last_seen_at'])) ?></span></div>
    </div>

    <div class="strumento">
      <h3>Provvedimenti</h3>
      <form method="post" action="<?= e(url('/admin/utente')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="utente" value="<?= e($u['id']) ?>">
        <div class="comandi">
          <button type="submit" name="azione" value="attiva">Attiva</button>
          <button type="submit" name="azione" value="sospendi">Sospendi</button>
          <button type="submit" name="azione" value="bandisci" class="allarme">Revoca</button>
        </div>
      </form>
      <p class="aiuto">
        <b>Attivo</b>: entra e gioca. <b>Sospeso</b>: non entra piu', ma resta tutto dov'e' —
        e' il provvedimento che si toglie, per un richiamo o mentre si guarda una cosa.
        <b>Revocato</b>: come sospeso, e per il gioco vuol dire che non tornera'; cambia
        l'intenzione, non gli effetti. <b>In attesa</b> non e' un provvedimento: e' chi si e'
        iscritto e non ha ancora confermato l'indirizzo. Nessuno di questi stati cancella
        niente, e da ciascuno si torna indietro con «Attiva».
      </p>

      <div class="strumento strumento--allarme" style="margin-top:1rem">
        <h3 style="color:var(--rosso)">Cancellare l'account</h3>
        <p class="aiuto" style="margin-top:0">
          Questa non si disfa. Se ne vanno l'account, i battelli
          (<b><?= e((string) count($battelli ?? [])) ?></b>), i comandanti
          (<b><?= e((string) count($comandanti ?? [])) ?></b>) <i>compresi quelli caduti, che
          spariscono dall'albo d'oro</i>, le missioni, gli affondamenti, i trofei, le
          decorazioni e le immagini caricate. Se serve solo impedire l'accesso, il
          provvedimento giusto e' «Sospendi» o «Revoca».
        </p>
        <form method="post" action="<?= e(url('/admin/utente')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="utente" value="<?= e($u['id']) ?>">
          <label for="conferma">Per confermare, scrivi <code><?= e($u['username']) ?></code></label>
          <input type="text" id="conferma" name="conferma" autocomplete="off" spellcheck="false"
                 placeholder="<?= e($u['username']) ?>"
                 style="width:100%;max-width:22rem;padding:.45rem;background:var(--acciaio-0);
                        color:var(--testo);border:1px solid var(--bordo);font:.9rem var(--mono)">
          <div class="comandi" style="margin-top:.6rem">
            <button type="submit" name="azione" value="cancella" class="allarme">Cancella l'account</button>
          </div>
        </form>
      </div>
      <form method="post" action="<?= e(url('/admin/utente/nota')) ?>" style="margin-top:.8rem">
        <?= csrf_field() ?>
        <input type="hidden" name="utente" value="<?= e($u['id']) ?>">
        <label for="nota">Nota interna</label>
        <textarea id="nota" name="nota" rows="3" maxlength="2000"
          style="width:100%;padding:.5rem;background:var(--acciaio-0);color:var(--testo);border:1px solid var(--bordo);font:.85rem var(--sans)"><?= e($u['note_admin'] ?? '') ?></textarea>
        <div class="comandi" style="margin-top:.5rem"><button type="submit">Salva nota</button></div>
      </form>
    </div>
  </div>
</div>

<div class="pannello">
  <h2>Comandanti e battelli</h2>
  <?php if ($comandanti === []): ?>
    <p class="sommario">Nessun comandante creato.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Comandante</th><th>Stato</th><th>Grado</th><th>Prestigio</th><th>Missioni</th><th>Affondate</th><th>GRT</th><th>Sorte</th><th></th></tr>
      <?php foreach ($comandanti as $c): ?>
        <tr>
          <td><b><a href="<?= e(url('/profilo/' . (int) $c['id'])) ?>"><?= e($c['nome']) ?></a></b></td>
          <td><?= e($c['stato']) ?></td>
          <td><?= e($c['grado']) ?></td>
          <td><?= e(number_format((float) $c['prestigio_tot'], 0, ',', '.')) ?></td>
          <td><?= e($c['patrols']) ?></td>
          <td><?= e($c['affondate']) ?></td>
          <td><?= e(number_format((float) $c['grt_affondato'], 0, ',', '.')) ?></td>
          <td style="color:var(--testo-3)"><?= e($c['sorte'] ?? '—') ?></td>
          <td><a href="<?= e(url('/admin/comandante/' . (int) $c['id'])) ?>">fascicolo</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($battelli !== []): ?>
    <table class="dati" style="margin-top:1rem">
      <tr><th>Battello</th><th>Tipo</th><th>Stato</th><th>Flottiglia</th><th>Emblema</th></tr>
      <?php foreach ($battelli as $b): ?>
        <tr>
          <td><b><?= e($b['uboat_number']) ?></b></td>
          <td><?= e($b['type_key']) ?></td>
          <td><?= e($b['state']) ?></td>
          <td style="color:var(--testo-3)"><?= e($b['flotilla']) ?></td>
          <td><?= e($b['emblema_key'] ?? ($b['emblema_file'] !== null ? 'caricato' : '—')) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="pannello">
  <h2>Accessi di questo account</h2>
  <?php if ($accessi === []): ?>
    <p class="sommario">Nessun accesso registrato.</p>
  <?php else: ?>
    <table class="dati">
      <tr><th>Quando</th><th>Cosa</th><th>Da</th></tr>
      <?php foreach ($accessi as $a): ?>
        <tr<?= (string) $a['action'] === 'auth.login_failed' ? ' style="color:var(--ambra)"' : '' ?>>
          <td><?= e(fmt_dt($a['created_at'], true)) ?></td>
          <td><?= e(str_replace('auth.', '', (string) $a['action'])) ?></td>
          <td><?= e($ip($a['ip'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($posta !== []): ?>
    <h3 style="margin-top:1.2rem">Posta verso questo indirizzo</h3>
    <table class="dati">
      <tr><th>Oggetto</th><th>Genere</th><th>Esito</th><th>Tentativi</th></tr>
      <?php foreach ($posta as $m): ?>
        <tr>
          <td><?= e($m['oggetto']) ?></td>
          <td style="color:var(--testo-3)"><?= e($m['genere']) ?></td>
          <td><?= $m['inviato_at'] !== null ? 'inviata ' . e(fmt_dt($m['inviato_at'])) : ($m['rinunciato_at'] !== null ? 'rinunciata' : 'in coda') ?></td>
          <td><?= e($m['tentativi']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
