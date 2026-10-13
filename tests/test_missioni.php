<?php

declare(strict_types=1);

/**
 * Atlantik — le missioni speciali: mine, battello meteorologico, agenti.
 *
 *   php tests/test_missioni.php
 *
 * Si verifica che:
 *   - il BdU offra due lavori diversi a chi e' in porto, e li tenga fermi;
 *   - accettarne uno chiuda gli altri, e alla partenza la missione parta;
 *   - per le mine si sbarchino i siluri al loro posto, si possano posare solo
 *     sul punto e in immersione, e il campo affondi le navi che passano,
 *     accreditate a chi l'ha posato, con l'arma giusta;
 *   - il rapporto meteorologico conti nel quadrato, ogni otto ore, e la
 *     radio lo passi alla missione;
 *   - lo sbarco chieda notte, superficie, macchine ferme e costa vicina;
 *   - una missione non compiuta al rientro sia fallita.
 *
 * Le trasmissioni radio della prova partono dal Baltico, dove il calore dei
 * settori non sposta il traffico vero. Il battito resta fermo.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;
use App\Game\MissioniSpeciali;
use App\Game\Patrol;
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

$lucchetto = fopen(__DIR__ . '/../storage/tick.lock', 'c');
if ($lucchetto === false || !flock($lucchetto, LOCK_EX)) {
    fwrite(STDERR, "Non riesco a prendere il lucchetto del battito.\n");
    exit(2);
}

$suffisso = substr((string) time(), -6);
$gts = World::now();
$convogli = [];
$minaPrima = Database::first('SELECT cvalue, ctype FROM game_config WHERE ckey = ?', ['missioni.mina_p']);
$reg = \App\Auth\Auth::register('prova missioni ' . $suffisso, 'missioni' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$uid = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
\App\Game\Comandante::crea($uid, ['nome' => 'Missioni Prova ' . $suffisso, 'nato_il' => '01/02/1912', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($uid, 'VIIC');
$id = (int) $boat['id'];
$cmdId = (int) Database::first('SELECT id FROM commanders WHERE user_id = ?', [$uid])['id'];
register_shutdown_function(static function () use ($uid, $id, &$convogli, $lucchetto, $minaPrima): void {
    if ($minaPrima !== null) {
        GameConfig::set('missioni.mina_p', (string) $minaPrima['cvalue'], (string) $minaPrima['ctype']);
    }
    $campi = array_column(Database::all('SELECT id FROM campi_minati WHERE boat_id = ?', [$id]), 'id');
    foreach ($campi as $c) {
        Database::run('DELETE FROM campi_minati_passaggi WHERE campo_id = ?', [(int) $c]);
    }
    Database::run('DELETE FROM campi_minati WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM missioni_speciali WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM sinkings WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM radio_messages WHERE boat_id = ? OR dest_boat_id = ?', [$id, $id]);
    Database::run('DELETE FROM hfdf_fixes WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrols WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM bdu_orders WHERE boat_id = ?', [$id]);
    foreach ($convogli as $c) {
        Database::run('DELETE FROM convoys WHERE id = ?', [$c]);
    }
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$uid]);
    Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    flock($lucchetto, LOCK_UN);
});
$leggi = static fn (): array => Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
$prestigio = static fn (): int => (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
$metti = static function (float $la, float $lo, string $modo, float $quota, float $nodi) use ($id): void {
    Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, mode = ?, depth_m = ?, ordered_depth_m = ?,
                   speed_kn = ?, ordered_speed_kn = ? WHERE id = ?', [$la, $lo, $la, $lo, $modo, $quota, $quota, $nodi, $nodi, $id]);
};

// ==========================================================================
titolo('Le offerte del BdU');
// ==========================================================================

$offerte = MissioniSpeciali::offerte($leggi(), $gts);
ok('a chi e\' in porto il BdU offre due lavori diversi', count($offerte) === 2 && $offerte[0]['tipo'] !== $offerte[1]['tipo'],
    implode(', ', array_map(static fn (array $o): string => $o['tipo'] . ' — ' . $o['luogo'], $offerte)));
ok('e restano quelle, rileggendo la pagina', array_column(MissioniSpeciali::offerte($leggi(), $gts), 'id') === array_column($offerte, 'id'));
$tuttiInMare = true;
foreach ($offerte as $o) {
    $tuttiInMare = $tuttiInMare && !\App\Sim\Terra::aTerra((float) $o['lat'], (float) $o['lon']);
}
ok('ogni obiettivo e\' in mare', $tuttiInMare);

// Una posa di mine scritta a mano, per non dipendere dal sorteggio: sulla
// rotta del Baltico, dove passa solo il convoglio della prova. Sulla rotta vera
// di Halifax il campo della prova ha affondato una Liberty del mondo vero
// (09/10/2026): rimessa a galla, e la prova spostata qui.
Database::run('DELETE FROM missioni_speciali WHERE boat_id = ?', [$id]);
$hal = Traffic::posizione('agru', 1.0, 0, 20 * 3600, 0.0);
Database::run(
    "INSERT INTO missioni_speciali (commander_id, boat_id, tipo, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
     VALUES (?, ?, 'mine', 'negli approcci di Halifax', ?, ?, 'CB 0000', 24, 400, 60, ?, ?)",
    [$cmdId, $id, $hal['lat'], $hal['lon'], $gts, $gts + 86400]
);
$mineId = Database::lastInsertId();
Database::run(
    "INSERT INTO missioni_speciali (commander_id, boat_id, tipo, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
     VALUES (?, ?, 'meteo', 'prova', 47, -33, 'BD 00', 6, 360, 50, ?, ?)", [$cmdId, $id, $gts, $gts + 86400]);
ok('accettarne una chiude le altre', MissioniSpeciali::accetta($leggi(), $mineId)['ok']
    && (int) Database::first("SELECT COUNT(*) n FROM missioni_speciali WHERE boat_id = ? AND stato = 'offerta'", [$id])['n'] === 0);

// ==========================================================================
titolo('La posa di mine');
// ==========================================================================

$siluriPrima = (int) Database::first('SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ?', [$id])['n'];
Patrol::depart($leggi());
$m = MissioniSpeciali::attiva($id);
ok('alla partenza la missione e\' in corso', $m !== null && (string) $m['stato'] === 'in_corso' && $m['patrol_id'] !== null);
$siluriDopo = (int) Database::first('SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ?', [$id])['n'];
$tipo = World::type('VIIC');
ok('e si parte con otto siluri in meno: ventiquattro mine al loro posto', $siluriDopo === (int) $tipo['torpedoes'] - 8,
    sprintf('%d siluri a bordo, su %d', $siluriDopo, (int) $tipo['torpedoes']));
ok('il giornale ha l\'ordine', Database::first("SELECT 1 FROM patrol_events WHERE boat_id = ? AND kind = 'ordine_bdu' AND text LIKE '%mine TMB%'", [$id]) !== null);

$s = MissioniSpeciali::situazione($leggi(), $gts);
ok('lontano dal punto non si posa', !$s['pronta'] && !MissioniSpeciali::esegui($leggi(), $gts)['ok'], implode('; ', $s['manca']));
[$la, $lo] = Geo::destination($hal['lat'], $hal['lon'], 90.0, 2.0);
$metti($la, $lo, 'superficie', 0.0, 3.0);
ok('sul punto ma in superficie, nemmeno', !MissioniSpeciali::situazione($leggi(), $gts)['pronta']);
$metti($la, $lo, 'periscopio', 12.0, 3.0);
$p0 = $prestigio();
$r = MissioniSpeciali::esegui($leggi(), $gts);
$campo = Database::first('SELECT * FROM campi_minati WHERE boat_id = ?', [$id]);
ok('sul punto e in immersione, le mine si posano', $r['ok'] && $campo !== null && (int) $campo['mine'] === 24, (string) ($r['testo'] ?? $r['error'] ?? ''));
$pDopoPosa = $prestigio();
ok('un secondo clic non posa un secondo campo e non paga due volte', !MissioniSpeciali::esegui($leggi(), $gts)['ok']
    && (int) Database::first('SELECT COUNT(*) n FROM campi_minati WHERE boat_id = ?', [$id])['n'] === 1 && $prestigio() === $pDopoPosa);
ok('la missione e\' compiuta, e il premio arriva', (string) Database::first('SELECT stato FROM missioni_speciali WHERE id = ?', [$mineId])['stato'] === 'compiuta'
    && $prestigio() === $p0 + 400, sprintf('prestigio %d → %d', $p0, $prestigio()));

// Un convoglio di prova che passa sul campo proprio adesso.
$dur = Traffic::durata('agru', 9.0);
$partito = $gts - (int) (20.0 / 9.0 * 3600);
Database::run(
    "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ('PROVA', ?, 'agru', 9.0, 3, ?, ?, 0, 0, 6)", [((int) $suffisso + 7) % 30000, $partito, $partito + $dur]);
$cvId = Database::lastInsertId();
$convogli[] = $cvId;
for ($i = 1; $i <= 6; $i++) {
    Database::run(
        "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
         VALUES (?, 'britannica', 'cargo_medio', ?, ?, 1, 'mercantile', 'agru', 9.0, ?, ?, 5000, 'grano')",
        ['Prova Mina ' . $i . ' ' . $suffisso, $cvId, $i, $partito, $partito + $dur]
    );
}
GameConfig::set('missioni.mina_p', '1', 'float');
$r = MissioniSpeciali::campiMinati($gts);
$passaggi = (int) Database::first('SELECT COUNT(*) n FROM campi_minati_passaggi WHERE campo_id = ?', [(int) $campo['id']])['n'];
ok('le navi che passano sul campo rischiano, una volta ciascuna', $passaggi === 6 && $r['colpite'] >= 1,
    sprintf('%d passaggi, %d colpite', $passaggi, $r['colpite']));
ok('e una seconda volta non rischiano piu\'', MissioniSpeciali::campiMinati($gts)['colpite'] === 0);
ok('le mine si consumano', (int) Database::first('SELECT mine FROM campi_minati WHERE id = ?', [(int) $campo['id']])['mine'] === 24 - $r['colpite']);
$condannate = (int) Database::first("SELECT COUNT(*) n FROM ships WHERE convoy_id = ? AND affonda_gts IS NOT NULL AND danno_arma = 'mina'", [$cvId])['n'];
\App\Sim\Danni::agonia($gts + 4000);
$aff = Database::all("SELECT * FROM sinkings WHERE boat_id = ? AND arma = 'mina'", [$id]);
ok('quelle che affondano sono accreditate a chi ha posato il campo, come affondate da mina', count($aff) === $condannate && $condannate >= 1,
    sprintf('%d condannate, %d accreditate', $condannate, count($aff)));
$conferma = Database::first("SELECT testo FROM radio_messages WHERE dest_boat_id = ? AND testo LIKE '%nostre mine%'", [$id]);
ok('e il BdU lo conferma per radio', $conferma !== null, (string) ($conferma['testo'] ?? ''));

// Il convoglio che attraversa il campo fra un battito e l'altro: adesso e' gia'
// sei miglia oltre, ma un quarto d'ora fa era sopra le mine. Prima non lo si
// vedeva mai (revisione del 13/10/2026).
$part2 = $gts - (int) ((20.0 + 6.0) / 9.0 * 3600);
Database::run(
    "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ('PROVA', ?, 'agru', 9.0, 3, ?, ?, 0, 0, 1)", [((int) $suffisso + 9) % 30000, $part2, $part2 + $dur]);
$cv2 = Database::lastInsertId();
$convogli[] = $cv2;
Database::run(
    "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
     VALUES (?, 'britannica', 'cargo_medio', ?, 1, 1, 'mercantile', 'agru', 9.0, ?, ?, 5000, 'grano')",
    ['Prova Mina Tardi ' . $suffisso, $cv2, $part2, $part2 + $dur]);
$tardi = Database::lastInsertId();
// Gia' colpita da un altro battello: il merito di un danno successivo resta a lui.
Database::run('UPDATE ships SET integrita = 40, danno_boat_id = NULL, danno_gts = ? WHERE id = ?', [$gts - 600, $tardi]);
Database::run('UPDATE boats SET encounter_id = NULL WHERE id = ?', [$id]);
$altro = (int) Database::first("SELECT id FROM boats WHERE id <> ? AND state <> 'perduto' ORDER BY id LIMIT 1", [$id])['id'];
Database::run('UPDATE ships SET danno_boat_id = ? WHERE id = ?', [$altro, $tardi]);
GameConfig::set('missioni.mina_p', '5', 'float');     // salta di sicuro: la verifica e' su che cosa succede dopo
MissioniSpeciali::campiMinati($gts);
ok('chi ha attraversato il campo fra due battiti ha rischiato lo stesso', Database::first(
    'SELECT 1 FROM campi_minati_passaggi WHERE campo_id = ? AND ship_id = ?', [(int) $campo['id'], $tardi]) !== null);
$sh = Database::first('SELECT * FROM ships WHERE id = ?', [$tardi]);
ok('salta, e il merito e l\'arma restano di chi l\'aveva colpita prima, e il danno non si ripara',
    (int) Database::first('SELECT colpita FROM campi_minati_passaggi WHERE campo_id = ? AND ship_id = ?', [(int) $campo['id'], $tardi])['colpita'] === 1
    && (int) $sh['danno_boat_id'] === $altro && $sh['danno_arma'] === null && (float) $sh['integrita'] <= 40.0,
    sprintf('battello %s, arma %s, integrita %.0f', $sh['danno_boat_id'], $sh['danno_arma'] ?? '—', (float) $sh['integrita']));
Database::run('UPDATE ships SET danno_boat_id = NULL, affonda_gts = NULL WHERE id = ?', [$tardi]);

// Le offerte di un periodo sono quelle: lo stesso tipo due volte non entra.
$periodo = 999999;
foreach ([1, 2] as $volta) {
    Database::run("INSERT IGNORE INTO missioni_speciali (commander_id, boat_id, periodo, tipo, luogo, lat, lon, obiettivo, offerta_gts, scade_gts)
         VALUES (?, ?, ?, 'meteo', 'prova', 47, -33, 6, ?, ?)", [$cmdId, $id, $periodo, $gts, $gts]);
}
ok('le offerte di un periodo non si scrivono due volte', (int) Database::first(
    'SELECT COUNT(*) n FROM missioni_speciali WHERE boat_id = ? AND periodo = ?', [$id, $periodo])['n'] === 1);
Database::run('DELETE FROM missioni_speciali WHERE boat_id = ? AND periodo = ?', [$id, $periodo]);

// ==========================================================================
titolo('Il battello meteorologico');
// ==========================================================================

Database::run("INSERT INTO missioni_speciali (commander_id, boat_id, patrol_id, tipo, stato, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
     VALUES (?, ?, ?, 'meteo', 'in_corso', 'prova', 47, -33, 'BD 00', 6, 360, 50, ?, ?)",
    [$cmdId, $id, (int) Patrol::corrente($id)['id'], $gts, $gts + 86400]);
$meteoId = Database::lastInsertId();
// La radio passa il rapporto alla missione: dal Baltico, lontano dal quadrato, non conta.
$metti(55.0, 19.0, 'superficie', 0.0, 5.0);
\App\Game\Radio::trasmetti($leggi(), 'kurz', '', 'meteo');
ok('la radio passa il rapporto alla missione, e fuori dal quadrato non conta',
    Database::first("SELECT 1 FROM patrol_events WHERE boat_id = ? AND kind = 'missione' AND text LIKE '%non conta%'", [$id]) !== null
    && (int) Database::first('SELECT fatto FROM missioni_speciali WHERE id = ?', [$meteoId])['fatto'] === 0);
$metti(47.2, -33.0, 'superficie', 0.0, 5.0);
ok('nel quadrato conta', str_contains((string) MissioniSpeciali::rapportoMeteo($leggi(), $gts), '1 di 6'));
ok('ma uno ogni otto ore', str_contains((string) MissioniSpeciali::rapportoMeteo($leggi(), $gts + 3600), 'otto ore'));
Database::run('UPDATE missioni_speciali SET fatto = 5, ultimo_gts = ? WHERE id = ?', [$gts - 9 * 3600, $meteoId]);
$p1 = $prestigio();
MissioniSpeciali::rapportoMeteo($leggi(), $gts);
ok('il sesto compie la missione', (string) Database::first('SELECT stato FROM missioni_speciali WHERE id = ?', [$meteoId])['stato'] === 'compiuta'
    && $prestigio() === $p1 + 360);

// ==========================================================================
titolo('Lo sbarco di agenti');
// ==========================================================================

Database::run("INSERT INTO missioni_speciali (commander_id, boat_id, patrol_id, tipo, stato, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
     VALUES (?, ?, ?, 'agenti', 'in_corso', 'Irlanda, baia di Dingle', 52.10, -10.30, 'AM 00', 3, 350, 50, ?, ?)",
    [$cmdId, $id, (int) Patrol::corrente($id)['id'], $gts, $gts + 86400]);
$agentiId = Database::lastInsertId();
$metti(52.10, -10.33, 'periscopio', 12.0, 4.0);
$s = MissioniSpeciali::situazione($leggi(), $gts);
ok('in immersione e con le macchine avanti non si sbarca', !$s['pronta'] && in_array('emergere: il battellino va a remi', $s['manca'], true)
    && in_array('fermare le macchine (due nodi al massimo)', $s['manca'], true), implode('; ', $s['manca']));
$metti(52.10, -10.33, 'superficie', 0.0, 1.0);
$s = MissioniSpeciali::situazione($leggi(), $gts);
$notte = (float) World::sky(52.10, -10.33, $gts)['luce'] < 0.25;
ok('in superficie e fermi manca solo la notte, se e\' giorno', $notte ? $s['pronta'] : $s['manca'] === ['aspettare la notte'],
    $notte ? 'e\' notte adesso' : implode('; ', $s['manca']));
if ($notte) {
    ok('di notte gli agenti vanno a terra', MissioniSpeciali::esegui($leggi(), $gts)['ok']);
}

// ==========================================================================
titolo('Non compiuta al rientro');
// ==========================================================================

// Una missione meteorologica appena cominciata, e si rientra senza compierla.
Database::run("UPDATE missioni_speciali SET stato = 'compiuta' WHERE boat_id = ? AND stato = 'in_corso'", [$id]);
Database::run("INSERT INTO missioni_speciali (commander_id, boat_id, patrol_id, tipo, stato, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
     VALUES (?, ?, ?, 'meteo', 'in_corso', 'prova', 47, -33, 'BD 00', 6, 360, 50, ?, ?)",
    [$cmdId, $id, (int) Patrol::corrente($id)['id'], $gts, $gts + 86400]);
$incompiuta = Database::lastInsertId();
$p2 = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
\App\Game\MissioniSpeciali::allaFine($id, $gts);
ok('una missione non compiuta al rientro e\' fallita, e costa sessanta di prestigio',
    (string) Database::first('SELECT stato FROM missioni_speciali WHERE id = ?', [$incompiuta])['stato'] === 'fallita'
    && $prestigio() === $p2 - 60, sprintf('prestigio %d → %d', $p2, $prestigio()));
$base = World::port('lorient');
$metti((float) $base['lat'], (float) $base['lon'], 'superficie', 0.0, 0.0);
Database::run("UPDATE missioni_speciali SET stato = 'in_corso' WHERE id = ?", [$incompiuta]);
Patrol::dock($leggi());
ok('e il rientro in porto la chiude cosi\'', (string) Database::first('SELECT stato FROM missioni_speciali WHERE id = ?', [$incompiuta])['stato'] === 'fallita');

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
