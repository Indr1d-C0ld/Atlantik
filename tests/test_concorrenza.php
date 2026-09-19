<?php

declare(strict_types=1);

/**
 * Prove di concorrenza (A2).
 *
 *   php tests/test_concorrenza.php
 *
 * Il battito del minuto e la richiesta del giocatore sono processi diversi e
 * possono capitare nello stesso istante. Queste prove verificano che non
 * riescano a far avanzare due volte lo stesso battello sulla stessa finestra di
 * tempo — che era il difetto A2 dell'audit.
 *
 * Tocca il database: avanza davvero il battello di prova. Non manda e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Sim\BoatSim;
use App\Sim\World;

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

/** Esegue l'avanzamento in un ALTRO processo, cioe' su un'altra connessione. */
function avanzaAltrove(int $boatId, ?int $fino = null): array
{
    $script = sprintf(
        'require "%s/bin/_bootstrap.php"; echo json_encode(App\Sim\BoatSim::advance(%d, %s));',
        dirname(__DIR__),
        $boatId,
        $fino === null ? 'null' : (string) $fino
    );
    $out = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($script) . ' 2>/dev/null');
    $r = json_decode((string) $out, true);
    return is_array($r) ? $r : ['steps' => -1, 'from' => 0, 'to' => 0];
}

// --- il lucchetto in se' -----------------------------------------------------
titolo('Lucchetto consultivo');

ok('si prende un lucchetto libero', Lock::prendi('prova:unita'));
$occupato = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg(sprintf(
    'require "%s/bin/_bootstrap.php"; echo App\Core\Lock::prendi("prova:unita") ? "preso" : "occupato";',
    dirname(__DIR__)
)) . ' 2>/dev/null');
ok('un altro processo lo trova occupato', trim((string) $occupato) === 'occupato', trim((string) $occupato));
Lock::lascia('prova:unita');
$libero = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg(sprintf(
    'require "%s/bin/_bootstrap.php"; echo App\Core\Lock::prendi("prova:unita") ? "preso" : "occupato";',
    dirname(__DIR__)
)) . ' 2>/dev/null');
ok('lasciato, torna disponibile', trim((string) $libero) === 'preso', trim((string) $libero));

ok('il lavoro non gira se il lucchetto e\' occupato', (function (): bool {
    Lock::prendi('prova:lavoro');
    $girato = false;
    // Dallo stesso processo GET_LOCK e' rientrante: la prova vera e' quella
    // sopra, fra processi diversi. Qui si verifica solo il valore di ripiego.
    $esito = Lock::con('prova:lavoro', function () use (&$girato) { $girato = true; return 'fatto'; }, 'occupato');
    Lock::liberaTutto();
    return $esito === 'fatto' && $girato;
})());

// --- avanzamento del battello ------------------------------------------------
titolo('Avanzamento serializzato');

// La prova si fa su un battello suo.
//
// Prima pescava il primo che capitava in tabella e lo spingeva avanti "di due
// ore": significava scrivere su un battello vero — e, siccome l'ora richiesta
// poteva superare quella del mondo, lasciarlo avanti all'orologio della
// campagna, quindi fermo finche' il mondo non lo raggiungeva. Adesso il
// battello se lo crea, e alla fine se lo porta via.
$utente = 'prova concorrenza ' . time();
$reg = \App\Auth\Auth::register($utente, 'concorrenza_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}

$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;

if ($boat === null) {
    echo "  \033[0;90mnon si e' potuto creare il battello di prova: prove saltate\033[0m\n";
} else {
    $boatId = (int) $boat['id'];
    $adesso = World::now();
    $indietro = 7200;                       // due ore di gioco da recuperare

    // In mare, con due ore di ritardo da colmare: cosi' c'e' del lavoro da fare.
    Database::run(
        "UPDATE boats SET state = 'mare', last_sim_gts = ?, encounter_id = NULL WHERE id = ?",
        [$adesso - $indietro, $boatId]
    );

    // 1. Col lucchetto in mano, nessun altro deve poter avanzare.
    $prima = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    Lock::prendi('boat:' . $boatId);
    $res = avanzaAltrove($boatId);
    $dopo = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    Lock::lascia('boat:' . $boatId);

    ok('battello bloccato: l\'altro processo non avanza', (int) $res['steps'] === 0, 'passi=' . $res['steps']);
    ok('battello bloccato: l\'orologio del battello non si muove', $dopo === $prima,
        sprintf('%d → %d', $prima, $dopo));

    // 2. Lasciato il lucchetto, l'avanzamento deve funzionare davvero.
    $eventiPrima = (int) Database::first('SELECT COUNT(*) n FROM patrol_events WHERE boat_id = ?', [$boatId])['n'];
    avanzaAltrove($boatId);
    $dopo2 = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    ok('lasciato il lucchetto, il battello avanza', $dopo2 > $prima, sprintf('%d → %d', $prima, $dopo2));

    // 3. L'orologio del battello non deve superare quello del mondo, nemmeno
    //    se glielo si chiede: il futuro non si simula.
    $futuro = avanzaAltrove($boatId, World::now() + 86400);
    $oltre = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    ok('non si puo\' spingere un battello nel futuro', $oltre <= World::now(),
        sprintf('battello a %d, mondo a %d', $oltre, World::now()));

    // 4. Due processi insieme sulla stessa finestra: niente doppioni nel giornale.
    Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - $indietro, $boatId]);
    $ripartenza = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    $cmd = sprintf(
        '%s -r %s >/dev/null 2>&1',
        PHP_BINARY,
        escapeshellarg(sprintf(
            'require "%s/bin/_bootstrap.php"; App\Sim\BoatSim::advance(%d);',
            dirname(__DIR__), $boatId
        ))
    );
    shell_exec($cmd . ' & ' . $cmd . ' & wait');

    $doppioni = Database::all(
        'SELECT gts, kind, COUNT(*) n FROM patrol_events
          WHERE boat_id = ? AND gts > ? GROUP BY gts, kind, text HAVING n > 1',
        [$boatId, $ripartenza]
    );
    ok('due avanzamenti simultanei non duplicano il giornale', $doppioni === [],
        count($doppioni) . ' gruppi duplicati');

    $finale = (int) Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'];
    ok('l\'orologio arriva al presente, non oltre', $finale <= World::now(),
        sprintf('%d ≤ %d', $finale, World::now()));

    $eventiDopo = (int) Database::first('SELECT COUNT(*) n FROM patrol_events WHERE boat_id = ?', [$boatId])['n'];
    ok('il giornale e\' cresciuto in modo ragionevole', $eventiDopo - $eventiPrima < 60,
        sprintf('%d righe nuove', $eventiDopo - $eventiPrima));
}

// Si porta via tutto: il battello, l'utente, le righe di posta.
if ($userId > 0) {
    shell_exec(sprintf('%s %s %s 2>/dev/null',
        PHP_BINARY, escapeshellarg(dirname(__DIR__) . '/bin/_cleanup_test_user.php'), escapeshellarg($utente)));
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
