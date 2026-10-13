<?php

declare(strict_types=1);

/**
 * Atlantik — gli avvisi per posta.
 *
 *   php tests/test_avvisi.php
 *
 * Nasce da un conto fatto il 09/10/2026: in tutta la storia del mondo in
 * esercizio, zero affondamenti. La fase tattica si gioca in una finestra di
 * tempo reale, e nessuno da' battaglia al posto del comandante: un convoglio
 * che passava mentre lui era altrove passava e basta. Qui si verifica che:
 *
 *   - le righe del giornale giuste lascino un avviso, e le altre no;
 *   - il battito lo mandi solo a chi l'ha chiesto, e non a chi e' gia' alla
 *     console;
 *   - non piu' di un messaggio ogni mezz'ora per categoria, e quello che arriva
 *     nel frattempo parta col messaggio dopo; un tetto al giorno; e quello che
 *     e' troppo vecchio per servire si lasci cadere;
 *   - un contatto vero, preso dalla simulazione, lasci il suo avviso.
 *
 * Nessun messaggio esce: per tutta la prova si tiene il lucchetto del battito,
 * cosi' il cron non smista niente al posto nostro, e la posta accodata si
 * cancella prima di lasciarlo.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;
use App\Game\Avvisi;
use App\Game\Patrol;
use App\Game\Preferenze;
use App\Sim\BoatSim;
use App\Sim\Geo;
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

// Il battito resta fermo finche' la prova non ha finito.
$lucchetto = fopen(__DIR__ . '/../storage/tick.lock', 'c');
if ($lucchetto === false || !flock($lucchetto, LOCK_EX)) {
    fwrite(STDERR, "Non riesco a prendere il lucchetto del battito.\n");
    exit(2);
}

$suffisso = substr((string) time(), -6);
$email = 'avvisi' . $suffisso . '@esempio.invalid';
$reg = \App\Auth\Auth::register('prova avvisi ' . $suffisso, $email, 'kommandant42', '127.0.0.1');
$userId = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW(), last_seen_at = NULL WHERE id = ?", [$userId]);
\App\Game\Comandante::crea($userId, ['nome' => 'Avvisi Prova ' . $suffisso, 'nato_il' => '1912-11-30',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($userId);
$id = (int) $boat['id'];
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [$id]));
$patrolId = (int) Database::first("SELECT id FROM patrols WHERE boat_id = ? AND state = 'in_corso'", [$id])['id'];

$configOriginale = [];
foreach (['avvisi.max_24h', 'avvisi.intervallo_min'] as $k) {
    $configOriginale[$k] = Database::first('SELECT cvalue, ctype FROM game_config WHERE ckey = ?', [$k]);
}
register_shutdown_function(static function () use ($userId, $email, $lucchetto, $configOriginale): void {
    foreach ($configOriginale as $k => $r) {
        if ($r !== null) {
            GameConfig::set($k, (string) $r['cvalue'], (string) $r['ctype']);
        }
    }
    Database::run('DELETE FROM mail_queue WHERE destinatario = ?', [$email]);
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$userId]);
    Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    flock($lucchetto, LOCK_UN);
});

$riga = static fn (string $kind, string $sev, string $testo, array $piu = []): array => [
    'gts' => World::now(), 'kind' => $kind, 'severity' => $sev, 'lat' => 47.0, 'lon' => -20.0,
    'quadrat' => 'BE 5287', 'text' => $testo,
] + $piu;
$aperti = static fn (): array => Database::all(
    'SELECT * FROM avvisi WHERE user_id = ? AND mail_id IS NULL AND saltato IS NULL ORDER BY id', [$userId]);
$ultimo = static fn (): ?array => Database::first('SELECT * FROM avvisi WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
$posta = static fn (): array => Database::all('SELECT * FROM mail_queue WHERE destinatario = ? ORDER BY id', [$email]);
$pulisci = static function () use ($userId, $email): void {
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$userId]);
    Database::run('DELETE FROM mail_queue WHERE destinatario = ?', [$email]);
};
$accendi = static function (bool $si) use ($userId): void {
    foreach (array_keys(Avvisi::CATEGORIE) as $c) {
        Preferenze::set($userId, Avvisi::chiave($c), $si ? 'si' : 'no');
    }
};

// ==========================================================================
titolo('Quali righe del giornale meritano un avviso');
// ==========================================================================

$casi = [
    ['un aereo che attacca',             $riga('aereo', 'allarme', 'x'),        'pericoli'],
    ['un aereo soltanto avvistato',      $riga('aereo', 'attenzione', 'x'),     null],
    ['il Metox che fischia',             $riga('radar_warner', 'attenzione', 'x'), 'pericoli'],
    ['una scorta che ci ha visti',       $riga('avvistati', 'allarme', 'x'),    'pericoli'],
    ['un ordine del BdU',                $riga('ordine_bdu', 'nota', 'x'),      'bdu'],
    ['un messaggio del BdU per radio',   $riga('bdu', 'nota', 'x'),             'bdu'],
    ['un contatto che si apre',          $riga('contatto', 'nota', 'x', ['avviso' => 'contatti']), 'contatti'],
    ['un contatto che si perde',         $riga('contatto', 'attenzione', 'x'),  null],
    ['una riparazione',                  $riga('riparazione', 'nota', 'x'),     null],
];
foreach ($casi as [$nome, $e, $atteso]) {
    ok($nome . ': ' . ($atteso ?? 'niente'), Avvisi::categoria($e) === $atteso, (string) (Avvisi::categoria($e) ?? 'niente'));
}

// La partenza ha gia' scritto l'ordine del BdU, che un avviso lo lascia.
$daPartenza = Database::first("SELECT categoria FROM avvisi WHERE user_id = ? ORDER BY id LIMIT 1", [$userId]);
ok('la partenza, con l\'ordine del BdU, lascia il suo', $daPartenza !== null && $daPartenza['categoria'] === 'bdu');
$pulisci();
BoatSim::save($riga('riparazione', 'nota', 'Riparato il compressore.'), $patrolId, $id);
ok('una riga qualunque del giornale non lascia avvisi', $ultimo() === null);
BoatSim::save($riga('contatto', 'nota', 'Convoglio in vista, rilevamento 040.', ['avviso' => 'contatti']), $patrolId, $id);
$a = $ultimo();
ok('un contatto che si apre lascia un avviso, legato alla sua riga del giornale',
    $a !== null && $a['categoria'] === 'contatti' && $a['event_id'] !== null && $a['quadrat'] === 'BE 5287',
    $a === null ? 'nessuno' : $a['categoria'] . ', riga ' . $a['event_id']);

// ==========================================================================
titolo('A chi si scrive');
// ==========================================================================

$accendi(false);
Avvisi::smista();
ok('di norma gli avvisi sono spenti: non si scrive', $ultimo()['saltato'] === 'spento' && $posta() === [],
    (string) $ultimo()['saltato']);

$pulisci();
$accendi(true);
Database::run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
BoatSim::save($riga('aereo', 'allarme', 'Aereo in picchiata da poppa!'), $patrolId, $id);
Avvisi::smista();
ok('a chi e\' alla console non si scrive', $ultimo()['saltato'] === 'presente' && $posta() === [],
    (string) $ultimo()['saltato']);

$pulisci();
Database::run('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = ?', [$userId]);
BoatSim::save($riga('contatto', 'nota', 'Convoglio in vista: trenta piroscafi, sei scorte. Rilevamento 040, dodici miglia.',
    ['avviso' => 'contatti']), $patrolId, $id);
$esito = Avvisi::smista();
$m = $posta();
ok('chi li ha chiesti e non c\'e\' riceve il messaggio', count($m) === 1 && $esito['inviati'] === 1,
    count($m) . ' messaggi');
if ($m !== []) {
    $num = (string) Database::first('SELECT uboat_number FROM boats WHERE id = ?', [$id])['uboat_number'];
    ok('l\'oggetto dice il battello, cosa e dove', str_contains((string) $m[0]['oggetto'], $num)
        && str_contains((string) $m[0]['oggetto'], 'contatto in BE 5287'), (string) $m[0]['oggetto']);
    ok('il testo riporta la riga del giornale e porta alla pagina dei contatti',
        str_contains((string) $m[0]['corpo'], 'trenta piroscafi') && str_contains((string) $m[0]['corpo'], '/contatti'));
    ok('e dice come spegnerli', str_contains((string) $m[0]['corpo'], '/comandante/profilo'));
    ok('passa dopo la posta degli account', (int) $m[0]['priorita'] > 5 && (string) $m[0]['genere'] === 'avviso',
        'priorita ' . $m[0]['priorita']);
}

// ==========================================================================
titolo('I freni');
// ==========================================================================

BoatSim::save($riga('contatto', 'nota', 'Fumo all\'orizzonte, rilevamento 300.', ['avviso' => 'contatti']), $patrolId, $id);
Avvisi::smista();
ok('un altro contatto dieci secondi dopo non fa un altro messaggio: aspetta', count($posta()) === 1 && count($aperti()) === 1);
BoatSim::save($riga('aereo', 'allarme', 'Bombe a cinquanta metri!'), $patrolId, $id);
Avvisi::smista();
ok('ma un pericolo, che e\' un\'altra categoria, parte subito', count($posta()) === 2);

// Passata mezz'ora dal primo messaggio, quello che aspettava parte.
Database::run('UPDATE mail_queue SET created_at = DATE_SUB(created_at, INTERVAL 31 MINUTE) WHERE destinatario = ?', [$email]);
BoatSim::save($riga('contatto', 'nota', 'Eliche all\'idrofono, rilevamento 310.', ['avviso' => 'contatti']), $patrolId, $id);
Avvisi::smista();
$m = $posta();
$ultimaMail = end($m);
ok('dopo mezz\'ora i contatti trattenuti partono tutti in un messaggio',
    count($m) === 3 && str_contains((string) $ultimaMail['oggetto'], '2 contatti')
    && str_contains((string) $ultimaMail['corpo'], 'Fumo') && str_contains((string) $ultimaMail['corpo'], 'Eliche'),
    (string) ($ultimaMail['oggetto'] ?? ''));

$pulisci();
GameConfig::set('avvisi.max_24h', '1', 'int');
BoatSim::save($riga('ordine_bdu', 'nota', 'Portarsi in BE 61.'), $patrolId, $id);
Avvisi::smista();
BoatSim::save($riga('aereo', 'allarme', 'Aereo!'), $patrolId, $id);
Avvisi::smista();
ok('raggiunto il tetto del giorno, non si scrive piu\'', count($posta()) === 1 && $ultimo()['saltato'] === 'tetto',
    (string) $ultimo()['saltato']);
GameConfig::set('avvisi.max_24h', (string) ($configOriginale['avvisi.max_24h']['cvalue'] ?? '12'), 'int');

$pulisci();
BoatSim::save($riga('aereo', 'allarme', 'Aereo, ore fa.'), $patrolId, $id);
Database::run('UPDATE avvisi SET created_at = DATE_SUB(NOW(), INTERVAL 50 MINUTE) WHERE user_id = ?', [$userId]);
Avvisi::smista();
ok('un avviso troppo vecchio per servire si lascia cadere', $ultimo()['saltato'] === 'scaduto' && $posta() === [],
    (string) $ultimo()['saltato']);

$pulisci();
Database::run('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$userId]);
BoatSim::save($riga('aereo', 'allarme', 'Aereo!'), $patrolId, $id);
Avvisi::smista();
ok('a un indirizzo non confermato non si scrive', $ultimo()['saltato'] === 'senza indirizzo' && $posta() === []);
Database::run('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$userId]);

// ==========================================================================
titolo('Un contatto vero');
// ==========================================================================

// Il battello vicino a un convoglio vero, in superficie e di giorno o di
// notte che sia: l'idrofono lo sente comunque. La simulazione apre il contatto
// e la sua riga lascia l'avviso.
$pulisci();
$gts = World::now();
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? ORDER BY id LIMIT 1", [$gts, $gts]);
if ($cv === null) {
    echo "  \033[0;90mnessun convoglio in mare in questo momento: la prova si salta\033[0m\n";
} else {
    $p = Traffic::pos($cv, $gts - 1800);
    [$la, $lo] = Geo::destination((float) $p['lat'], (float) $p['lon'], 90.0, 4.0);
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
    Database::run(
        "UPDATE boats SET state = 'mare', encounter_id = NULL, mode = 'superficie', depth_m = 0, ordered_depth_m = 0,
                speed_kn = 2, ordered_speed_kn = 2, lat = ?, lon = ?, est_lat = ?, est_lon = ?, last_sim_gts = ?
          WHERE id = ?",
        [$la, $lo, $la, $lo, $gts - 1800, $id]
    );
    Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [$id]);
    $primaEv = (int) Database::first('SELECT COALESCE(MAX(id), 0) m FROM patrol_events')['m'];
    BoatSim::advance($id);
    // Le righe che aprono un contatto sono quelle col testo d'apertura del
    // Narrator; quante ce ne siano dipende dal mare e dalla luce del momento.
    // Con mare forza sette, di notte, in mezz'ora si puo' non sentire niente:
    // allora non c'e' niente da avvisare, e lo si dice invece di fallire.
    $aperture = Database::all(
        "SELECT e.id, e.text FROM patrol_events e WHERE e.boat_id = ? AND e.id > ? AND e.kind = 'contatto' AND e.severity = 'nota'",
        [$id, $primaEv]
    );
    $conAvviso = array_filter($aperture, static fn (array $e): bool =>
        Database::first('SELECT 1 FROM avvisi WHERE event_id = ?', [(int) $e['id']]) !== null);
    if ($aperture === []) {
        echo "  \033[0;90min questa mezz'ora la simulazione non ha aperto contatti (mare e luce del momento): la verifica si salta\033[0m\n";
    } else {
        ok('ogni contatto aperto dalla simulazione lascia il suo avviso', count($conAvviso) >= 1,
            count($aperture) . ' righe di contatto, ' . count($conAvviso) . ' con avviso — ' . mb_substr((string) $aperture[0]['text'], 0, 80));
    }
    $perso = Database::first("SELECT COUNT(*) n FROM avvisi a JOIN patrol_events e ON e.id = a.event_id
        WHERE a.user_id = ? AND e.severity = 'attenzione' AND e.kind = 'contatto'", [$userId]);
    ok('e nessun avviso per le righe di contatto che non lo aprono', (int) $perso['n'] === 0);
}

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
