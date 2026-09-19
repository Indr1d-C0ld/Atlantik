<?php

declare(strict_types=1);

/**
 * L'ordine di pedinamento: il mestiere del Fuehlungshalter.
 *
 *   php tests/test_pedinamento.php
 *
 * Fino all'audit del 19/09/2026 questo ordine non esisteva. Lo schema lo
 * prevedeva dalla prima migrazione — bdu_orders.tipo ha sei valori — e
 * Bdu::verifica lo leggeva gia': la porta era montata e non ci era mai passato
 * nessuno. Dei sei tipi di ordine, uno solo veniva emesso davvero.
 *
 * E' la meta' mancante della tattica del branco: i gruppi si formano, chi
 * segnala prende il premio del Fuehlungshalter, i compagni ricevono il punto —
 * ma il BdU non chiedeva mai a nessuno di restare attaccato a un convoglio, e
 * fuori da un branco segnalare non dava niente a nessuno.
 *
 * Tocca il database: crea un utente di prova e se lo porta via alla fine.
 * Non manda e-mail e non trasmette niente fuori di qui.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Bdu;
use App\Sim\Traffic;
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

$utente = 'prova pedina ' . time();
$reg = \App\Auth\Auth::register($utente, 'ped_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
register_shutdown_function(static function () use ($userId): void {
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    }
});

$cmd = $userId > 0 ? \App\Game\Comandante::crea($userId, [
    'nome' => 'Pedina Prova', 'nato_il' => '1910-07-02', 'nato_a' => 'Kiel',
    'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];
$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null || !($cmd['ok'] ?? false)) {
    echo "Impossibile preparare il battello di prova.\n";
    exit(1);
}
$boatId = (int) $boat['id'];

$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? + 43200 LIMIT 1",
    [$gts, $gts]
);
if ($cv === null) {
    echo "  \033[0;33m--\033[0m    nessun convoglio con abbastanza strada davanti: prova saltata\n";
    exit(0);
}

$pos = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts,
    (float) $cv['deviazione']);

// Il battello, attaccato al convoglio.
Database::run(
    "UPDATE boats SET state = 'mare', mode = 'superficie', lat = ?, lon = ?, est_lat = ?, est_lon = ?,
            commander_id = (SELECT id FROM commanders WHERE user_id = ? ORDER BY id DESC LIMIT 1)
     WHERE id = ?",
    [$pos['lat'], $pos['lon'], $pos['lat'], $pos['lon'], $userId, $boatId]
);
$boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);

// --- l'ordine nasce -----------------------------------------------------------
titolo('Il BdU chiede di restare attaccati');

$ordine = Bdu::ordinaPedinamento($boat, (int) $cv['id'], $gts);
ok('il contatto su un convoglio produce un ordine di pedinamento', $ordine !== null);
if ($ordine === null) {
    echo "\n\033[0;31m1 verifiche fallite.\033[0m\n";
    exit(1);
}
ok('e l\'ordine e\' del tipo giusto', (string) $ordine['tipo'] === 'pedinamento', (string) $ordine['tipo']);
ok('ed e\' legato al convoglio, non a un punto sulla carta',
    (int) $ordine['convoy_id'] === (int) $cv['id']);
ok('nasce aperto: si puo\' rifiutare', (string) $ordine['stato'] === 'aperto');

ok('non se ne aprono due insieme', Bdu::ordinaPedinamento($boat, (int) $cv['id'], $gts) === null);

// --- si accetta ---------------------------------------------------------------
$r = Bdu::rispondi($boat, (int) $ordine['id'], true, $gts);
ok('si accetta', ($r['ok'] ?? false) && ($r['accettato'] ?? false));

$prestigioPrima = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?',
    [(int) $boat['commander_id']])['prestigio'];

// --- troppo presto ------------------------------------------------------------
titolo('Sei ore, non cinque');

$eventi = Bdu::verifica($boat, $gts + 3 * 3600);
ok('dopo tre ore non si incassa ancora', $eventi === []);
ok('e l\'ordine e\' ancora in corso',
    (string) Database::first('SELECT stato FROM bdu_orders WHERE id = ?', [(int) $ordine['id']])['stato'] === 'accettato');

// --- contatto perduto ---------------------------------------------------------
titolo('Contatto perduto');

// Lo stesso ordine, ma il battello si e' fatto distanziare di trecento miglia.
[$lat, $lon] = \App\Sim\Geo::destination((float) $pos['lat'], (float) $pos['lon'], 180.0, 300.0);
$lontano = array_merge($boat, ['lat' => $lat, 'lon' => $lon]);
$eventi = Bdu::verifica($lontano, $gts + 4 * 3600);
$statoDopo = (string) Database::first('SELECT stato FROM bdu_orders WHERE id = ?', [(int) $ordine['id']])['stato'];
ok('chi si fa distanziare perde l\'ordine', $statoDopo === 'scaduto', $statoDopo);
ok('e il giornale lo dice', $eventi !== [] && str_contains(implode(' ', $eventi), 'Contatto perduto'));
ok('senza premio', (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?',
    [(int) $boat['commander_id']])['prestigio'] === $prestigioPrima);

// --- pedinamento portato a termine -------------------------------------------
titolo('Sei ore attaccati');

$ordine2 = Bdu::ordinaPedinamento($boat, (int) $cv['id'], $gts);
ok('si puo\' ricominciare dopo un fallimento', $ordine2 !== null);
if ($ordine2 !== null) {
    Bdu::rispondi($boat, (int) $ordine2['id'], true, $gts);

    // Sei ore dopo, il battello e' dove sara' il convoglio.
    $dopo = $gts + 6 * 3600 + 60;
    $pos2 = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $dopo,
        (float) $cv['deviazione']);
    if ($pos2 === null) {
        ok('il convoglio e\' ancora in mare sei ore dopo', false, 'arrivato prima');
    } else {
        $attaccato = array_merge($boat, ['lat' => $pos2['lat'], 'lon' => $pos2['lon']]);
        $eventi = Bdu::verifica($attaccato, $dopo);
        $stato2 = (string) Database::first('SELECT stato FROM bdu_orders WHERE id = ?', [(int) $ordine2['id']])['stato'];
        ok('sei ore attaccati assolvono l\'ordine', $stato2 === 'assolto', $stato2);
        ok('e il BdU paga', (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?',
            [(int) $boat['commander_id']])['prestigio'] === $prestigioPrima + (int) $ordine2['prestigio'],
            sprintf('+%d prestigio', (int) $ordine2['prestigio']));
        ok('con una riga che si capisce', $eventi !== [] && str_contains(implode(' ', $eventi), 'pedin')
            || ($eventi !== [] && str_contains(implode(' ', $eventi), 'convoglio')),
            (string) ($eventi[0] ?? '—'));
    }
}

// --- e il collegamento vero ---------------------------------------------------
//
// Le due verifiche di sopra passerebbero benissimo anche se nessuno chiamasse
// mai Bdu::ordinaPedinamento. E' gia' successo con i compartimenti: la classe
// funzionava e il punto in cui andava chiamata era vuoto. Qui si passa dalla
// porta principale — il Funkmaat che trasmette — e si guarda se l'ordine
// arriva.
titolo('Dalla radio, come farebbe il comandante');

Database::run("UPDATE bdu_orders SET stato = 'scaduto' WHERE boat_id = ?", [$boatId]);
Database::run(
    "INSERT INTO contacts (boat_id, target_kind, convoy_id, sensore, first_gts, last_gts, bearing,
                           range_nm, certezza, perso, lat_est, lon_est)
     VALUES (?, 'convoglio', ?, 'idrofono', ?, ?, 90, 12, 0.8, 0, ?, ?)",
    [$boatId, (int) $cv['id'], $gts, $gts, $pos['lat'], $pos['lon']]
);
Database::run("UPDATE boats SET mode = 'superficie', depth_m = 0, radio_ultima_gts = NULL WHERE id = ?", [$boatId]);
$boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);

$tx = \App\Game\Radio::trasmetti($boat, 'contatto', '', 'contatto');
ok('la trasmissione parte', (bool) ($tx['ok'] ?? false), (string) ($tx['error'] ?? ''));
ok('e il BdU risponde con un ordine di pedinamento',
    ($tx['ordine'] ?? null) !== null && (string) $tx['ordine']['tipo'] === 'pedinamento');

$daRadio = Database::first(
    "SELECT COUNT(*) n FROM bdu_orders WHERE boat_id = ? AND tipo = 'pedinamento' AND stato = 'aperto'",
    [$boatId]
);
ok('l\'ordine e\' davvero in archivio', (int) ($daRadio['n'] ?? 0) === 1);

// --- il ruolo che non c'e' piu' -----------------------------------------------
titolo('Un grado che non conferiva niente');

$ruoli = (string) Database::first("SHOW COLUMNS FROM users LIKE 'role'")['Type'];
ok('users.role non promette piu\' un moderatore che non esiste',
    !str_contains($ruoli, 'moderator'), $ruoli);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
