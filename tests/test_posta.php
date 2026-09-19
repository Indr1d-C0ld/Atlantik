<?php

declare(strict_types=1);

/**
 * Prove della coda di spedizione (A6).
 *
 *   php tests/test_posta.php
 *
 * Nessun messaggio esce davvero: si usa il trasporto di prova, che esiste
 * apposta perche' la configurazione vera punta a un relay vero.
 * Le righe create vengono cancellate alla fine.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Posta;

$falliti = 0;
function titolo(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function ok(string $titolo, bool $esito, string $dettaglio = ''): void
{
    global $falliti;
    if ($esito) {
        echo "  \033[0;32mok\033[0m    {$titolo}" . ($dettaglio !== '' ? "  \033[0;90m{$dettaglio}\033[0m" : '') . "\n";
    } else {
        echo "  \033[0;31mKO\033[0m    {$titolo}" . ($dettaglio !== '' ? "  ({$dettaglio})" : '') . "\n";
        $falliti++;
    }
}

const PROVA = 'prova-coda@atlantik.invalid';
Database::run('DELETE FROM mail_queue WHERE destinatario LIKE ?', ['prova-coda%']);

$tentativi = 0;
$sempreOk   = function () use (&$tentativi) { $tentativi++; return ['ok' => true]; };
$sempreRotto = function () use (&$tentativi) { $tentativi++; return ['ok' => false, 'error' => 'relay irraggiungibile']; };

// --- consegna al primo colpo -------------------------------------------------
titolo('Messaggio che parte subito');

Posta::trasportoDiProva($sempreOk);
$r = Posta::invia(PROVA, 'prova', 'corpo', 'verifica', 1);
ok('parte al primo tentativo', $r['ok'] === true);
$m = Database::first('SELECT * FROM mail_queue WHERE id = ?', [$r['id']]);
ok('segnato come inviato', $m !== null && $m['inviato_at'] !== null);
ok('un solo tentativo consumato', (int) $m['tentativi'] === 1, 'tentativi=' . $m['tentativi']);

// --- il relay non risponde ---------------------------------------------------
titolo('Messaggio che non parte');

Posta::trasportoDiProva($sempreRotto);
$r2 = Posta::invia(PROVA, 'prova 2', 'corpo', 'verifica', 1);
ok('non riesce, ma non e\' perduto', $r2['ok'] === false);
$m2 = Database::first('SELECT * FROM mail_queue WHERE id = ?', [$r2['id']]);
ok('resta in coda', $m2 !== null && $m2['inviato_at'] === null && $m2['rinunciato_at'] === null);
ok('l\'errore e\' registrato', (string) $m2['ultimo_errore'] === 'relay irraggiungibile', (string) $m2['ultimo_errore']);
ok('riprova fra un minuto', strtotime((string) $m2['prossimo_at']) > time(), (string) $m2['prossimo_at']);

// --- attesa crescente e rinuncia ---------------------------------------------
titolo('Attesa crescente e rinuncia');

$max = max(1, GameConfig::int('mail.max_tentativi', 6));
// Il primo tentativo l'ha gia' consumato Posta::invia(): qui se ne fanno
// altri $max-2, cosi' l'ultimo tentativo (fuori dal ciclo) e' quello che
// fa scattare la rinuncia.
$attese = [];
for ($i = 1; $i <= $max - 2; $i++) {
    Database::run('UPDATE mail_queue SET prossimo_at = NOW() WHERE id = ?', [$r2['id']]);
    Posta::tenta((int) $r2['id']);
    $riga = Database::first('SELECT tentativi, prossimo_at, rinunciato_at FROM mail_queue WHERE id = ?', [$r2['id']]);
    $attese[] = max(0, strtotime((string) $riga['prossimo_at']) - time());
}
$crescono = true;
for ($i = 1; $i < count($attese); $i++) {
    if ($attese[$i] < $attese[$i - 1]) { $crescono = false; }
}
ok('l\'attesa fra un tentativo e l\'altro cresce', $crescono, implode('s, ', $attese) . 's');

Database::run('UPDATE mail_queue SET prossimo_at = NOW() WHERE id = ?', [$r2['id']]);
$ultimo = Posta::tenta((int) $r2['id']);
$m3 = Database::first('SELECT * FROM mail_queue WHERE id = ?', [$r2['id']]);
ok('dopo il massimo dei tentativi si rinuncia', $m3['rinunciato_at'] !== null && !empty($ultimo['rinunciato']),
    'tentativi=' . $m3['tentativi']);
ok('il messaggio rinunciato non viene piu' . "'" . ' ritentato', Posta::tenta((int) $r2['id'])['ok'] === false);

// --- ordine di priorita' -----------------------------------------------------
titolo('Chi passa per primo');

Posta::trasportoDiProva($sempreRotto);
$basso = Posta::accoda(PROVA, 'avviso', 'corpo', 'avviso_admin', 7);
$alto  = Posta::accoda(PROVA, 'verifica', 'corpo', 'verifica', 1);
$ordine = Database::all(
    "SELECT id FROM mail_queue WHERE destinatario = ? AND inviato_at IS NULL AND rinunciato_at IS NULL
     ORDER BY priorita, id",
    [PROVA]
);
ok('la verifica precede l\'avviso all\'amministratore',
    $ordine !== [] && (int) $ordine[0]['id'] === $alto, 'primo=' . ($ordine[0]['id'] ?? '?'));

// --- tetto giornaliero -------------------------------------------------------
titolo('Tetto del provider');

$tettoVecchio = GameConfig::int('mail.tetto_24h', 280);
GameConfig::set('mail.tetto_24h', '1');          // gia' inviato un messaggio sopra
ok('col tetto raggiunto non si spedisce', Posta::tettoRaggiunto(), 'inviate24h=' . Posta::inviate24h());
$tentativiPrima = $tentativi;
Database::run('UPDATE mail_queue SET prossimo_at = NOW() WHERE id = ?', [$alto]);
Posta::tenta($alto);
ok('col tetto raggiunto non si tocca nemmeno il relay', $tentativi === $tentativiPrima);
$rim = Database::first('SELECT tentativi, prossimo_at FROM mail_queue WHERE id = ?', [$alto]);
ok('e non si consuma un tentativo', (int) $rim['tentativi'] === 0, 'tentativi=' . $rim['tentativi']);
GameConfig::set('mail.tetto_24h', (string) $tettoVecchio);

// --- smistamento e potatura --------------------------------------------------
titolo('Smistamento e potatura');

Posta::trasportoDiProva($sempreOk);
Database::run('UPDATE mail_queue SET prossimo_at = NOW() WHERE destinatario = ? AND inviato_at IS NULL AND rinunciato_at IS NULL', [PROVA]);
$sm = Posta::smista(10);
ok('lo smistamento manda quello che e\' pronto', $sm['inviati'] >= 2, json_encode($sm));

Database::run('UPDATE mail_queue SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY) WHERE destinatario = ?', [PROVA]);
$potati = Posta::pota(30);
ok('la potatura toglie i messaggi vecchi gia\' chiusi', $potati >= 2, $potati . ' righe');

// --- pulizia -----------------------------------------------------------------
Posta::trasportoDiProva(null);
$resti = Database::run('DELETE FROM mail_queue WHERE destinatario LIKE ?', ['prova-coda%'])->rowCount();
echo "\n  \033[0;90mrighe di prova rimosse: {$resti}\033[0m\n";

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
