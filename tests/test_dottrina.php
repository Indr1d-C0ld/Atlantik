<?php

declare(strict_types=1);

/**
 * Atlantik — la dottrina d'ingaggio e i colleghi mossi dal motore.
 *
 *   php tests/test_dottrina.php
 *
 * Una delle quattro decisioni fondanti (DESIGN.md) era rimasta a meta': in
 * assenza del comandante il I.WO doveva condurre il battello secondo una
 * dottrina, e invece sapeva solo disimpegnare. La pagina d'ingresso la
 * prometteva lo stesso (audit, tredicesima revisione). Qui si verifica che:
 *
 *   - il I.WO dia battaglia solo se la dottrina lo consente e il comandante non c'e';
 *   - conduca l'attacco: avvicinamento, un ventaglio con la sua stima, poi giu';
 *   - alla scadenza della finestra non torni a ottanta metri: l'incontro e' suo;
 *   - il comandante che torna riprenda il comando;
 *   - un collega parta, prenda il mare, segnali un convoglio, rientri, e non
 *     riceva posta; e che le statistiche lo dichiarino.
 *
 * Il convoglio e' di prova, creato e cancellato qui: niente danni alle navi del
 * mondo vero. Il battito resta fermo per tutta la prova.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Colleghi;
use App\Game\Dottrina;
use App\Game\Patrol;
use App\Sim\Contacts;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Rng;
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

$lucchetto = fopen(__DIR__ . '/../storage/tick.lock', 'c');
if ($lucchetto === false || !flock($lucchetto, LOCK_EX)) {
    fwrite(STDERR, "Non riesco a prendere il lucchetto del battito.\n");
    exit(2);
}

$suffisso = substr((string) time(), -6);
$gts = World::now();
$utenti = [];
$convogli = [];

/** Pulizia di tutto quello che un battello di prova lascia in giro. */
$pulisciBattello = static function (int $id): void {
    Database::run('DELETE FROM torpedo_runs WHERE boat_id = ?', [$id]);
    Database::run('UPDATE boats SET encounter_id = NULL WHERE id = ?', [$id]);
    Database::run('DELETE FROM encounter_entities WHERE encounter_id IN (SELECT id FROM encounters WHERE boat_id = ?)', [$id]);
    Database::run('DELETE FROM encounters WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrols WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM radio_messages WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM hfdf_fixes WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM bdu_orders WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM wolfpack_members WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM sinkings WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
};
register_shutdown_function(static function () use (&$utenti, &$convogli, $lucchetto, $pulisciBattello): void {
    foreach ($utenti as $uid) {
        foreach (Database::all('SELECT id FROM boats WHERE user_id = ?', [$uid]) as $b) {
            $pulisciBattello((int) $b['id']);
        }
        Database::run('DELETE FROM avvisi WHERE user_id = ?', [$uid]);
        Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    }
    foreach ($convogli as $c) {
        Database::run('DELETE FROM encounter_entities WHERE ship_id IN (SELECT id FROM ships WHERE convoy_id = ?)', [$c]);
        Database::run('DELETE FROM contacts WHERE convoy_id = ?', [$c]);
        Database::run('DELETE FROM convoys WHERE id = ?', [$c]);
    }
    flock($lucchetto, LOCK_UN);
});

// --- un convoglio di prova sulla rotta HX, a meta' oceano ----------------------
$durata = Traffic::durata('hx', 9.0);
$partito = $gts - (int) ($durata * 0.45);
Database::run(
    "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ('PROVA', ?, 'hx', 9.0, 3, ?, ?, 0, 0, 4)",
    [(int) $suffisso % 30000, $partito, $partito + $durata]
);
$cvId = Database::lastInsertId();
$convogli[] = $cvId;
foreach (['A' => 'cargo_grande', 'B' => 'cargo_medio', 'C' => 'tramp_piccolo'] as $l => $classe) {
    Database::run(
        "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
         VALUES (?, 'britannica', ?, ?, ?, 1, 'mercantile', 'hx', 9.0, ?, ?, ?, 'grano')",
        ['Prova Dottrina ' . $l . $suffisso, $classe, $cvId, ord($l) - 64, $partito, $partito + $durata, (int) Traffic::classe($classe)['grt']]
    );
}
Database::run(
    "INSERT INTO ships (name, flag, class_key, convoy_id, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
     VALUES (?, 'britannica', 'corvetta_flower', ?, 'scorta', 'hx', 9.0, ?, ?, 925)",
    ['Prova Scorta ' . $suffisso, $cvId, $partito, $partito + $durata]
);
$pcv = Traffic::posizione('hx', 9.0, $partito, $gts, 0.0);

// --- un comandante in mare vicino al convoglio, con il contatto in mano --------
$reg = \App\Auth\Auth::register('prova dottrina ' . $suffisso, 'dottrina' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$uid = (int) $reg['user_id'];
$utenti[] = $uid;
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW(), last_seen_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [$uid]);
\App\Game\Comandante::crea($uid, ['nome' => 'Dottrina Prova ' . $suffisso, 'nato_il' => '01/02/1912', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($uid, 'VIIC');
$id = (int) $boat['id'];
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [$id]));
$type = World::type('VIIC');
$leggi = static fn (): array => Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
$mettiVicino = static function (float $nm) use ($id, $pcv, $gts, $cvId): void {
    [$la, $lo] = Geo::destination($pcv['lat'], $pcv['lon'], Geo::normBearing($pcv['heading'] + 60), $nm);
    Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, encounter_id = NULL, depth_m = 0, ordered_depth_m = 0,
                   mode = "superficie", battery_pct = 95, hull_stress = 0, last_sim_gts = ? WHERE id = ?', [$la, $lo, $la, $lo, $gts, $id]);
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
    Contacts::upsert($id, null, ['kind' => 'convoglio', 'id' => $cvId, 'sensore' => 'idrofono',
        'bearing' => Geo::bearing($la, $lo, $pcv['lat'], $pcv['lon']), 'distanza' => $nm, 'est_lat' => $la, 'est_lon' => $lo,
        'classe_est' => 'convoglio', 'navi' => 4, 'snr' => 25.0], $gts, Rng::for(1, 'prova dottrina'));
};
$incontro = static fn (): ?array => Encounter::corrente($id);

// ==========================================================================
titolo('Quando il I.WO da\' battaglia da solo');
// ==========================================================================

$mettiVicino(4.0);
Dottrina::imposta($id, 'prudente');
Dottrina::cerca($gts);
ok('con la dottrina prudente, no', $incontro() === null);

Dottrina::imposta($id, 'isolate');
Dottrina::cerca($gts);
ok('con «navi isolate», un convoglio no', $incontro() === null);

Dottrina::imposta($id, 'convogli');
Database::run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$uid]);
Dottrina::cerca($gts);
ok('con «convogli», ma col comandante alla console, no: decide lui', $incontro() === null);

Database::run('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [$uid]);
$r = Dottrina::cerca($gts);
$enc = $incontro();
ok('con «convogli» e il comandante assente, si', $enc !== null && (string) $enc['condotta'] === 'iwo',
    $enc !== null ? 'incontro ' . $enc['id'] . ', condotta ' . $enc['condotta'] : 'nessun incontro');
$ev = Database::first("SELECT text FROM patrol_events WHERE boat_id = ? AND kind = 'iwo' ORDER BY id DESC LIMIT 1", [$id]);
ok('e il giornale dice perche\'', $ev !== null && str_contains((string) $ev['text'], 'dottrina lasciata'), (string) ($ev['text'] ?? ''));
ok('e lascia l\'avviso per la posta', Database::first("SELECT 1 FROM avvisi WHERE user_id = ? AND categoria = 'contatti'", [$uid]) !== null);

// ==========================================================================
titolo('Come conduce l\'attacco');
// ==========================================================================

$encId = (int) $enc['id'];
$fase = Dottrina::conduci($encId);
$b = $leggi();
ok('lontano dal bersaglio, si avvicina', $fase['fase'] === 'avvicinamento' && (float) $b['ordered_speed_kn'] > 0,
    sprintf('%s, %.0f nodi, quota %.0f', $fase['fase'], (float) $b['ordered_speed_kn'], (float) $b['ordered_depth_m']));

// La finestra di tempo reale scade mentre il I.WO si avvicina: la regola del
// comandante assente — giu' a ottanta metri e disimpegno — non vale per
// l'incontro che conduce lui, o non lancerebbe mai.
$quotaPrima = (float) $leggi()['ordered_depth_m'];
Database::run('UPDATE encounters SET finestra_fine = ? WHERE id = ?', [time() - 10, $encId]);
Encounter::passoAutomatico($encId);
ok('alla scadenza della finestra non disimpegna: l\'incontro e\' suo', (float) $leggi()['ordered_depth_m'] === $quotaPrima,
    sprintf('quota ordinata %.0f → %.0f m', $quotaPrima, (float) $leggi()['ordered_depth_m']));

// Un incontro che il I.WO conduce da sette ore senza lanciare si chiude: nei
// primi giorni in esercizio un collega ha inseguito una nave per novantun ore.
$e7 = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
Database::run('UPDATE encounters SET started_gts = last_step_gts - 7 * 3600 WHERE id = ?', [$encId]);
$f7 = Dottrina::conduci($encId);
ok('sette ore senza un lancio: il I.WO rompe il contatto e l\'incontro si chiude',
    $f7['fase'] === 'rinuncia' && (string) Database::first('SELECT stato FROM encounters WHERE id = ?', [$encId])['stato'] === 'concluso'
    && $leggi()['encounter_id'] === null, (string) ($f7['testo'] ?? $f7['fase']));
// E si riapre un incontro nuovo per il resto della prova.
Database::run('UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [$uid]);
$mettiVicino(4.0);
Dottrina::cerca($gts);
$enc = $incontro();
$encId = (int) $enc['id'];

// Vicino e di fianco al bersaglio piu' grosso, a quota periscopica: si lancia.
$grosso = Database::first("SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' ORDER BY grt DESC LIMIT 1", [$encId]);
[$la, $lo] = Geo::destination((float) $grosso['lat'], (float) $grosso['lon'], Geo::normBearing((float) $grosso['heading'] + 90), 0.8);
Database::run('UPDATE boats SET lat = ?, lon = ?, depth_m = 12, ordered_depth_m = 12, mode = "periscopio" WHERE id = ?', [$la, $lo, $id]);
$fase = Dottrina::conduci($encId);
$corse = Database::all('SELECT * FROM torpedo_runs WHERE encounter_id = ?', [$encId]);
ok('a ottocento metri, col fianco in vista, lancia un ventaglio', $fase['fase'] === 'lancio' && count($corse) >= 2 && count($corse) <= 3,
    count($corse) . ' siluri — ' . mb_substr((string) ($fase['testo'] ?? ''), 0, 90));
ok('con la stima del I.WO, non con la verita\'', str_contains((string) ($fase['testo'] ?? ''), 'stima del I.WO'));

$fase = Dottrina::conduci($encId);
$b = $leggi();
ok('dopo il lancio scende: quota di prova, due nodi, silenzio', $fase['fase'] === 'evasione'
    && abs((float) $b['ordered_depth_m'] - (float) $type['test_depth_m']) < 0.1 && (float) $b['ordered_speed_kn'] === 2.0
    && (int) $b['silent'] === 1, sprintf('%.0f m, %.0f nodi', (float) $b['ordered_depth_m'], (float) $b['ordered_speed_kn']));

Database::run('UPDATE encounters SET finestra_fine = ? WHERE id = ?', [time() - 600, $encId]);
ok('il comandante che torna riprende il comando', Dottrina::riprendi(Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]), $leggi())
    && (string) Database::first('SELECT condotta FROM encounters WHERE id = ?', [$encId])['condotta'] === 'comandante');
ok('e da li\' il I.WO non decide piu\'', Dottrina::conduci($encId)['fase'] === 'nessuna');
ok('e la finestra di condotta riparte da adesso: non lo si fa disimpegnare appena arrivato',
    (int) Database::first('SELECT finestra_fine FROM encounters WHERE id = ?', [$encId])['finestra_fine'] > time() + 60);
Encounter::chiudi($encId, 'fine della prova', $gts);

// Una nave sola, senza scorta, con la dottrina «navi isolate».
Database::run(
    "INSERT INTO ships (name, flag, class_key, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
     VALUES (?, 'norvegese', 'cargo_medio', 'hx', 9.0, ?, ?, 5200, 'legname')",
    ['Prova Isolata ' . $suffisso, $partito, $partito + $durata]
);
$isolata = Database::lastInsertId();
$mettiVicino(4.0);
Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
Contacts::upsert($id, null, ['kind' => 'nave', 'id' => $isolata, 'sensore' => 'vista', 'bearing' => 0.0, 'distanza' => 4.0,
    'est_lat' => (float) $leggi()['lat'], 'est_lon' => (float) $leggi()['lon'], 'classe_est' => 'piroscafo', 'snr' => 25.0],
    $gts, Rng::for(3, 'prova isolata'));
Dottrina::imposta($id, 'isolate');
Dottrina::cerca($gts);
$e2 = $incontro();
ok('con «navi isolate», una nave sola si', $e2 !== null && (int) $e2['ship_id'] === $isolata && (string) $e2['condotta'] === 'iwo');
if ($e2 !== null) {
    Encounter::chiudi((int) $e2['id'], 'fine della prova', $gts);
}
Database::run('DELETE FROM encounter_entities WHERE ship_id = ?', [$isolata]);
Database::run('DELETE FROM contacts WHERE ship_id = ?', [$isolata]);
Database::run('DELETE FROM ships WHERE id = ?', [$isolata]);

// ==========================================================================
titolo('Un collega');
// ==========================================================================

Database::run(
    "INSERT INTO users (username, email, password_hash, status, role, email_verified_at)
     VALUES (?, ?, ?, 'suspended', 'motore', NOW())",
    ['prova collega ' . $suffisso, 'collega' . $suffisso . '@motore.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]
);
$cu = Database::first('SELECT * FROM users WHERE id = ?', [Database::lastInsertId()]);
$utenti[] = (int) $cu['id'];
$colleghiPrima = \App\Game\Statistiche::campagna($gts)['colleghi'];

$esito = [];
Colleghi::vivi($cu, 0, $gts, $esito);
$cb = \App\Game\Fleet::boatOf((int) $cu['id']);
ok('ha un comandante e un battello, ed esce dal bunker', ($esito['nuovi'] ?? 0) === 1 && $cb !== null && (string) $cb['state'] === 'mare',
    ($cb['uboat_number'] ?? '—') . ', ' . ($cb['state'] ?? '—'));
ok('e\' un collega, per il gioco', Colleghi::eCollega($cb));
ok('la dottrina, per lui, e\' attaccare i convogli', Dottrina::di($cb) === 'convogli' && Dottrina::assente($cb));
ok('non riceve posta: niente avvisi', (int) Database::first('SELECT COUNT(*) n FROM avvisi WHERE user_id = ?', [(int) $cu['id']])['n'] === 0);
ok('le statistiche lo contano fra i colleghi', \App\Game\Statistiche::campagna($gts)['colleghi'] === $colleghiPrima + 1);
$cid = (int) $cb['id'];

// Nel Baltico, lontano dai branchi del mondo vero: prende il mare.
Database::run('UPDATE boats SET lat = 55.0, lon = 19.0, est_lat = 55.0, est_lon = 19.0 WHERE id = ?', [$cid]);
Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [$cid]);
Database::run("UPDATE bdu_orders SET stato = 'assolto' WHERE boat_id = ?", [$cid]);
Colleghi::vivi($cu, 0, $gts, $esito);
$cb = Database::first('SELECT * FROM boats WHERE id = ?', [$cid]);
ok('in mare si da\' una rotta e macchine avanti', count(Patrol::route($cid)) > 0 && (float) $cb['ordered_speed_kn'] >= 7.0,
    count(Patrol::route($cid)) . ' punti, ' . $cb['ordered_speed_kn'] . ' nodi');
ok('e non si unisce a un branco lontano', $cb['wolfpack_id'] === null);

// Un convoglio in contatto: lo segnala al BdU.
Contacts::upsert($cid, null, ['kind' => 'convoglio', 'id' => $cvId, 'sensore' => 'idrofono', 'bearing' => 90.0, 'distanza' => 12.0,
    'est_lat' => 55.0, 'est_lon' => 19.0, 'classe_est' => 'convoglio', 'navi' => 4, 'snr' => 25.0], $gts, Rng::for(2, 'prova'));
Colleghi::vivi($cu, 0, $gts, $esito);
ok('un convoglio in contatto si segnala per radio', Database::first(
    "SELECT 1 FROM radio_messages WHERE boat_id = ? AND tipo = 'contatto'", [$cid]) !== null);
Colleghi::vivi($cu, 0, $gts, $esito);
ok('una volta sola, non a ogni battito', (int) Database::first(
    "SELECT COUNT(*) n FROM radio_messages WHERE boat_id = ? AND tipo = 'contatto'", [$cid])['n'] === 1);

// Nafta al lumicino: rotta per casa, e in porto si entra.
Database::run('DELETE FROM contacts WHERE boat_id = ?', [$cid]);
$base = World::port((string) $cb['home_port_key']);
[$la, $lo] = Geo::destination((float) $base['lat'], (float) $base['lon'], 250.0, 120.0);
Database::run('UPDATE boats SET fuel_t = 10, lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?', [$la, $lo, $la, $lo, $cid]);
Colleghi::vivi($cu, 0, $gts, $esito);
$rotta = Patrol::route($cid);
$ultimo = $rotta !== [] ? end($rotta) : null;
ok('con poca nafta fa rotta per la base', $ultimo !== null && Geo::distanceNm((float) $ultimo['lat'], (float) $ultimo['lon'], (float) $base['lat'], (float) $base['lon']) < 1.0,
    count($rotta) . ' punti');
Database::run('UPDATE boats SET lat = ?, lon = ? WHERE id = ?', [(float) $base['lat'], (float) $base['lon'], $cid]);
$prima = $esito['rientrati'] ?? 0;
Colleghi::vivi($cu, 0, $gts, $esito);
ok('arrivato, entra in porto', (string) Database::first('SELECT state FROM boats WHERE id = ?', [$cid])['state'] === 'base'
    && ($esito['rientrati'] ?? 0) === $prima + 1);
Colleghi::vivi($cu, 0, $gts, $esito);
ok('e non riparte subito: c\'e\' la sosta in porto', (string) Database::first('SELECT state FROM boats WHERE id = ?', [$cid])['state'] === 'base');

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
