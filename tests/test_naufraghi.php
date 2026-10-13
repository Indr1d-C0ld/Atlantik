<?php

declare(strict_types=1);

/**
 * Atlantik — i naufraghi.
 *
 *   php tests/test_naufraghi.php
 *
 * Si verifica che:
 *   - una nave affondata in un incontro lasci le sue scialuppe;
 *   - per avvicinarsi si debba chiudere l'incontro, emergere e andare a due miglia;
 *   - dare la rotta costi viveri e scaldi il settore, prendere il comandante
 *     dia prestigio, e nessuna delle due si possa fare due volte;
 *   - passate sei ore le scialuppe non siano piu' raggiungibili;
 *   - la sorte si decida giorni dopo, la notizia arrivi a chi le ha lasciate, e
 *     con la rotta data se ne salvino di piu';
 *   - il fascicolo conti i soccorsi.
 *
 * L'affondamento avviene nel Baltico, su un convoglio di prova: il calore che
 * lascia non sposta il traffico vero. Il battito resta fermo.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Naufraghi;
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
$reg = \App\Auth\Auth::register('prova naufraghi ' . $suffisso, 'naufraghi' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$uid = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
\App\Game\Comandante::crea($uid, ['nome' => 'Naufraghi Prova ' . $suffisso, 'nato_il' => '01/02/1912', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($uid, 'VIIC');
$id = (int) $boat['id'];
$cmdId = (int) Database::first('SELECT id FROM commanders WHERE user_id = ?', [$uid])['id'];
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [$id]));
$cvId = null;
register_shutdown_function(static function () use ($uid, $id, &$cvId, $lucchetto): void {
    Database::run('DELETE FROM naufraghi WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM sinkings WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM radio_messages WHERE boat_id = ? OR dest_boat_id = ?', [$id, $id]);
    Database::run('DELETE FROM torpedo_runs WHERE boat_id = ?', [$id]);
    Database::run('UPDATE boats SET encounter_id = NULL WHERE id = ?', [$id]);
    Database::run('DELETE FROM encounter_entities WHERE encounter_id IN (SELECT id FROM encounters WHERE boat_id = ?)', [$id]);
    Database::run('DELETE FROM encounters WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrols WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM bdu_orders WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
    if ($cvId !== null) {
        Database::run('DELETE FROM convoys WHERE id = ?', [$cvId]);
    }
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$uid]);
    Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    flock($lucchetto, LOCK_UN);
});
$leggi = static fn (): array => Database::first('SELECT * FROM boats WHERE id = ?', [$id]);

// ==========================================================================
titolo('Una nave affondata lascia le sue scialuppe');
// ==========================================================================

// Un piccolo convoglio di prova sulla rotta del Baltico.
$dur = Traffic::durata('agru', 7.0);
$partito = $gts - (int) ($dur * 0.4);
Database::run(
    "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ('PROVA', ?, 'agru', 7.0, 2, ?, ?, 0, 0, 2)", [((int) $suffisso + 11) % 30000, $partito, $partito + $dur]);
$cvId = Database::lastInsertId();
foreach (['A', 'B'] as $i => $l) {
    Database::run(
        "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
         VALUES (?, 'britannica', 'cargo_medio', ?, ?, 1, 'mercantile', 'agru', 7.0, ?, ?, 5000, 'grano')",
        ['Prova Naufraghi ' . $l . $suffisso, $cvId, $i + 1, $partito, $partito + $dur]
    );
}
$pc = Traffic::pos(Database::first('SELECT * FROM convoys WHERE id = ?', [$cvId]), $gts);
[$la, $lo] = Geo::destination($pc['lat'], $pc['lon'], Geo::normBearing($pc['heading'] + 90), 1.5);
Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, mode = "periscopio", depth_m = 12, ordered_depth_m = 12,
               speed_kn = 2, ordered_speed_kn = 2, last_sim_gts = ? WHERE id = ?', [$la, $lo, $la, $lo, $gts, $id]);
$res = Encounter::apri($leggi(), ['convoy_id' => $cvId, 'ship_id' => null], $gts);
$encId = (int) ($res['encounter_id'] ?? 0);
$t0 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];
$vittima = Database::first("SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' ORDER BY id LIMIT 1", [$encId]);
Database::run("UPDATE encounter_entities SET stato = 'affonda', integrita = 0, allagamento = 95, incendio = 5, affonda_gts = ?, colpita_siluro = 1 WHERE id = ?",
    [$t0 + 10, (int) $vittima['id']]);
Encounter::step($encId, $t0 + 30);
$n = Database::first('SELECT * FROM naufraghi WHERE boat_id = ?', [$id]);
ok('dopo l\'affondamento ci sono le scialuppe', $n !== null && (int) $n['nelle_scialuppe'] > 0
    && (int) $n['nelle_scialuppe'] <= (int) $n['equipaggio'], $n !== null
    ? sprintf('%s: %d su %d, %d scialuppe', $n['nave'], $n['nelle_scialuppe'], $n['equipaggio'], $n['scialuppe']) : 'nessuna');
ok('legate all\'affondamento registrato', $n !== null
    && Database::first('SELECT 1 FROM sinkings WHERE id = ? AND boat_id = ?', [(int) $n['sinking_id'], $id]) !== null);

// ==========================================================================
titolo('Avvicinarsi');
// ==========================================================================

$n = Naufraghi::inVista($leggi(), $gts)[0] ?? null;
ok('si vedono, e prima va chiuso l\'incontro', $n !== null && in_array('chiudere l\'incontro', Naufraghi::manca($leggi(), $n), true));
Encounter::chiudi($encId, 'fine', $t0 + 40);
$nId = (int) $n['id'];
[$la2, $lo2] = Geo::destination((float) $n['lat'], (float) $n['lon'], 0.0, 4.0);
Database::run('UPDATE boats SET lat = ?, lon = ?, mode = "periscopio", depth_m = 12 WHERE id = ?', [$la2, $lo2, $id]);
$n = Naufraghi::inVista($leggi(), $gts)[0];
$manca = Naufraghi::manca($leggi(), $n);
ok('in immersione e a quattro miglia: bisogna emergere e avvicinarsi', count($manca) === 2, implode('; ', $manca));
ok('e non si puo\' aiutarli da li\'', !Naufraghi::aiuta($leggi(), $nId, $gts)['ok']);
[$la3, $lo3] = Geo::destination((float) $n['lat'], (float) $n['lon'], 0.0, 0.5);
Database::run('UPDATE boats SET lat = ?, lon = ?, mode = "superficie", depth_m = 0, ordered_depth_m = 0 WHERE id = ?', [$la3, $lo3, $id]);

// ==========================================================================
titolo('Aiutarli, o prendere il comandante');
// ==========================================================================

$viveri = (float) $leggi()['provisions_days'];
$morale = (float) Database::first("SELECT AVG(morale) m FROM crew_members WHERE boat_id = ? AND health <> 'morto'", [$id])['m'];
$caloreKey = \App\Sim\Sectors::key($la3, $lo3);
// Il settore del Baltico puo' essere gia' al massimo, scaldato da altre prove:
// lo si riporta a zero (li' non passa traffico vero) per vedere se sale.
Database::run('UPDATE sectors SET heat = 0 WHERE quadrat = ?', [$caloreKey]);
$calore = \App\Sim\Sectors::heat($caloreKey, $gts);
$r = Naufraghi::aiuta($leggi(), $nId, $gts);
ok('in superficie e vicini, si da\' la rotta', $r['ok'], mb_substr((string) ($r['testo'] ?? $r['error'] ?? ''), 0, 120));
ok('costa viveri', abs((float) $leggi()['provisions_days'] - ($viveri - 0.3)) < 0.01);
ok('all\'equipaggio fa bene', (float) Database::first("SELECT AVG(morale) m FROM crew_members WHERE boat_id = ? AND health <> 'morto'", [$id])['m'] > $morale);
ok('e fermarsi li\' scalda il settore', \App\Sim\Sectors::heat($caloreKey, $gts) > $calore);
ok('la rotta e\' per un porto vero, con distanza e rilevamento', str_contains((string) ($r['testo'] ?? ''), 'miglia per'));
ok('una seconda volta no', !Naufraghi::aiuta($leggi(), $nId, $gts)['ok']);
$p0 = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
$c = Naufraghi::prendiComandante($leggi(), $nId, $gts);
ok('il comandante della nave sale a bordo', $c['ok'] && (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'] === $p0 + 40,
    mb_substr((string) ($c['testo'] ?? $c['error'] ?? ''), 0, 120));
ok('e uno di meno nelle scialuppe', (int) Database::first('SELECT nelle_scialuppe FROM naufraghi WHERE id = ?', [$nId])['nelle_scialuppe'] === (int) $n['nelle_scialuppe'] - 1);
ok('passate sei ore non sono piu\' raggiungibili', Naufraghi::inVista($leggi(), $gts + 7 * 3600) === []);

// ==========================================================================
titolo('La sorte');
// ==========================================================================

Database::run('UPDATE naufraghi SET destino_gts = ? WHERE id = ?', [$gts - 1, $nId]);
Naufraghi::destino($gts);
$fine = Database::first('SELECT * FROM naufraghi WHERE id = ?', [$nId]);
ok('giorni dopo se ne sa la sorte', in_array((string) $fine['stato'], ['raccolti', 'perduti'], true) && $fine['salvati'] !== null
    && (int) $fine['salvati'] <= (int) $fine['nelle_scialuppe'], $fine['stato'] . ', ' . $fine['salvati'] . ' salvati');
ok('e la notizia arriva a chi le ha lasciate', Database::first(
    "SELECT 1 FROM radio_messages WHERE dest_boat_id = ? AND testo LIKE ?", [$id, '%' . $fine['nave'] . '%']) !== null);

// Con la rotta se ne salvano di piu': due gruppi uguali, uno aiutato e uno no.
$righe = [];
foreach ([0, 1] as $aiuto) {
    // Ogni gruppo ha il suo affondamento: la chiave esterna (migrazione 0051)
    // non ammette naufraghi di un affondamento che non c'e'.
    Database::run(
        "INSERT INTO sinkings (boat_id, commander_id, nome, bandiera, class_key, grt, arma, siluri_usati, gts, lat, lon)
         VALUES (?, ?, 'prova', 'britannica', 'cargo_medio', 5000, 'siluro', 1, ?, 52.0, -25.0)",
        [$id, $cmdId, $gts - 3 * 86400]
    );
    $sinkingFinto = Database::lastInsertId();
    Database::run(
        "INSERT INTO naufraghi (sinking_id, boat_id, commander_id, nave, lat, lon, gts, equipaggio, scialuppe, nelle_scialuppe, aiutati, destino_gts)
         VALUES (?, ?, ?, 'prova', 52.0, -25.0, ?, 1000, 50, 1000, ?, ?)",
        [$sinkingFinto, $id, $cmdId, $gts - 3 * 86400, $aiuto, $gts - 1]
    );
    $righe[$aiuto] = Database::lastInsertId();
}
Naufraghi::destino($gts);
$senza = (int) Database::first('SELECT salvati FROM naufraghi WHERE id = ?', [$righe[0]])['salvati'];
$con = (int) Database::first('SELECT salvati FROM naufraghi WHERE id = ?', [$righe[1]])['salvati'];
ok('con la rotta data se ne salvano di piu\'', $con > $senza + 100, sprintf('%d contro %d su mille', $con, $senza));
Database::run('DELETE FROM sinkings WHERE boat_id = ? AND nome = ?', [$id, 'prova']);

$num = \App\Game\Profilo::numeri($cmdId);
ok('il fascicolo conta i soccorsi', (int) $num['naufraghi']['volte'] === 1 && (int) $num['naufraghi']['salvati'] === (int) $fine['salvati'],
    json_encode($num['naufraghi']));

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
