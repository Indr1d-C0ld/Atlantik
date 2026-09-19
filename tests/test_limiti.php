<?php

declare(strict_types=1);

/**
 * I casi limite: che cosa succede quando una riserva finisce davvero.
 *
 *   php tests/test_limiti.php
 *
 * Un gioco di sopravvivenza si giudica ai bordi, non al centro. Questa prova
 * porta un battello in ognuna delle cinque situazioni che un comandante teme e
 * guarda se il mondo reagisce. Tre reagivano gia'; due no, e sono state
 * scoperte qui all'audit del 19/09/2026:
 *
 *   - l'aria poteva scendere a zero e restarci per giorni. Era l'unica delle
 *     quattro riserve senza conseguenze: la nafta ferma il battello, le
 *     batterie lo fanno emergere, i viveri affamano l'equipaggio, l'aria
 *     niente;
 *
 *   - la quota di collasso non esisteva. Era scritta nella scheda del tipo,
 *     mostrata al comandante nella pagina del battello, e non la leggeva
 *     nessuno: si poteva scendere a duecentosessanta metri e restarci a
 *     tempo indeterminato.
 *
 * Se domani qualcuno stacca uno dei due collegamenti, qui diventa rosso.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\BoatSim;
use App\Sim\Damage;
use App\Sim\World;

$falliti = 0;

function titolo(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function saltata(string $titolo, string $perche): void
{
    echo "  \033[0;33m--\033[0m    {$titolo}  \033[0;90m{$perche}\033[0m\n";
}
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

// --- un battello di prova, che si porta via alla fine ------------------------
$utente = 'prova limiti ' . time();
$reg = \App\Auth\Auth::register($utente, 'lim_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
// Serve un comandante vero: il fascicolo si chiude su quello, e senza
// fascicolo la verifica sulla perdita passerebbe da sola — che e' il modo
// peggiore di non accorgersi di niente.
$cmd = $userId > 0 ? \App\Game\Comandante::crea($userId, [
    'nome' => 'Limiti Prova', 'nato_il' => '1911-03-18', 'nato_a' => 'Kiel',
    'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];
if (!($cmd['ok'] ?? false)) {
    echo "Impossibile preparare il comandante di prova.\n";
    exit(1);
}

$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) {
    echo "Impossibile preparare il battello di prova.\n";
    exit(1);
}
$boatId = (int) $boat['id'];
$type = World::type((string) $boat['type_key']);

register_shutdown_function(static function () use ($userId): void {
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    }
});

Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
     SELECT id, user_id, commander_id, 1, ?, 'in_corso', home_port_key FROM boats WHERE id = ?",
    [World::now() - 86400, $boatId]
);
$patrolId = Database::lastInsertId();

/**
 * Rimette il battello a nuovo e lo piazza nella situazione voluta, poi avanza
 * di $ore. Torna lo stato finale.
 *
 * @param array<string,mixed> $stato
 * @return array<string,mixed>
 */
function scenario(int $boatId, int $patrolId, array $stato, float $ore): array
{
    Database::run("UPDATE boat_systems SET state = 'ok', condition_pct = 100, repair_progress = 0 WHERE boat_id = ?", [$boatId]);
    Database::run('UPDATE boat_compartments SET integrity = 100, flooding = 0, fire = 0, sealed = 0 WHERE boat_id = ?', [$boatId]);
    Database::run("UPDATE crew_members SET health = 'ok' WHERE boat_id = ?", [$boatId]);
    Database::run("UPDATE commanders SET stato = 'attivo', uscito_gts = NULL, sorte = NULL
                   WHERE user_id = (SELECT user_id FROM boats WHERE id = ?)", [$boatId]);
    Database::run("UPDATE patrols SET state = 'in_corso', returned_gts = NULL WHERE id = ?", [$patrolId]);
    Database::run('DELETE FROM patrol_events WHERE patrol_id = ?', [$patrolId]);

    $base = [
        'state' => 'mare', 'mode' => 'superficie', 'depth_m' => 0, 'ordered_depth_m' => 0,
        'speed_kn' => 8, 'ordered_speed_kn' => 8, 'fuel_t' => 50, 'battery_pct' => 100,
        'air_pct' => 100, 'co2_pct' => 0, 'provisions_days' => 30, 'hull_stress' => 0,
        'hull_integrity' => 100, 'lat' => 47, 'lon' => -12, 'est_lat' => 47, 'est_lon' => -12,
        'encounter_id' => null, 'auto_dive_fine_gts' => null, 'auto_dive_quota' => null,
    ];
    $stato = array_merge($base, $stato);

    $arrivo = World::now();
    $campi = [];
    $val = [];
    foreach ($stato as $k => $v) {
        $campi[] = "{$k} = ?";
        $val[] = $v;
    }
    $val[] = $arrivo - (int) round($ore * 3600);
    $val[] = $boatId;
    Database::run('UPDATE boats SET ' . implode(', ', $campi) . ', last_sim_gts = ? WHERE id = ?', $val);

    BoatSim::advance($boatId, $arrivo);

    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
    $b['__eventi'] = array_column(
        Database::all('SELECT DISTINCT kind FROM patrol_events WHERE patrol_id = ?', [$patrolId]),
        'kind'
    );

    return $b;
}

// --- nafta ------------------------------------------------------------------
titolo('Le riserve che finiscono');

$b = scenario($boatId, $patrolId, ['fuel_t' => 0], 3);
ok('senza nafta il battello si ferma', (float) $b['speed_kn'] < 0.01, sprintf('%.2f nodi', (float) $b['speed_kn']));
ok('e la panne finisce nel giornale', in_array('in_panne', $b['__eventi'], true));

// --- batteria ---------------------------------------------------------------
$b = scenario($boatId, $patrolId, [
    'mode' => 'immersione', 'depth_m' => 60, 'ordered_depth_m' => 60,
    'speed_kn' => 3, 'ordered_speed_kn' => 3, 'battery_pct' => 0,
], 3);
ok('con le batterie a zero si emerge', (string) $b['mode'] === 'superficie' && (float) $b['depth_m'] < 1.0,
    sprintf('%s a %.0f m', (string) $b['mode'], (float) $b['depth_m']));
ok('e l\'emersione forzata finisce nel giornale', in_array('emersione_forzata', $b['__eventi'], true));

// --- aria -------------------------------------------------------------------
$b = scenario($boatId, $patrolId, [
    'mode' => 'immersione', 'depth_m' => 40, 'ordered_depth_m' => 40,
    'speed_kn' => 2, 'ordered_speed_kn' => 2, 'air_pct' => 4, 'co2_pct' => 7,
], 3);
ok('con l\'aria finita si emerge lo stesso', (string) $b['mode'] === 'superficie' && (float) $b['depth_m'] < 1.0,
    sprintf('%s a %.0f m', (string) $b['mode'], (float) $b['depth_m']));
ok('e l\'aria finita finisce nel giornale', in_array('aria_finita', $b['__eventi'], true));

// --- viveri -----------------------------------------------------------------
$b = scenario($boatId, $patrolId, ['provisions_days' => 0], 3);
ok('i viveri finiti finiscono nel giornale', in_array('viveri_finiti', $b['__eventi'], true));

// --- lo scafo ---------------------------------------------------------------
titolo('La quota di collasso');

$banda = Damage::bandaCollasso($type, 1.0, 100.0);
ok('a scafo intero la banda e\' quella del cantiere',
    abs($banda['min'] - (float) $type['crush_depth_min_m']) < 0.01
    && abs($banda['max'] - (float) $type['crush_depth_max_m']) < 0.01,
    sprintf('%.0f-%.0f m', $banda['min'], $banda['max']));

$suo = Damage::quotaCollasso($type, $boatId, 1.0, 100.0);
ok('ogni battello ha il suo punto dentro la banda', $suo >= $banda['min'] && $suo <= $banda['max'],
    sprintf('%.1f m', $suo));
ok('e non cambia mai', abs($suo - Damage::quotaCollasso($type, $boatId, 1.0, 100.0)) < 0.0001);
ok('due battelli non cedono alla stessa quota',
    abs($suo - Damage::quotaCollasso($type, $boatId + 1, 1.0, 100.0)) > 0.01);

$banda2 = Damage::bandaCollasso($type, 1.0, 40.0);
ok('uno scafo consumato cede piu' . "'" . ' in alto', $banda2['min'] < $banda['min'],
    sprintf('%.0f m invece di %.0f', $banda2['min'], $banda['min']));
ok('ma mai sopra la quota di prova',
    Damage::bandaCollasso($type, 0.0, 0.0)['min'] >= (float) $type['test_depth_m'],
    sprintf('%.0f m', Damage::bandaCollasso($type, 0.0, 0.0)['min']));

// Alla quota di prova non succede niente, per sempre.
$b = scenario($boatId, $patrolId, [
    'mode' => 'immersione', 'depth_m' => (float) $type['test_depth_m'], 'ordered_depth_m' => (float) $type['test_depth_m'],
    'speed_kn' => 2, 'ordered_speed_kn' => 2,
], 24);
ok('alla quota di prova si resta un giorno intero senza un graffio',
    (string) $b['state'] === 'mare' && (float) $b['hull_stress'] < 0.01 && (float) $b['hull_integrity'] > 99.99,
    sprintf('stress %.1f, scafo %.1f%%', (float) $b['hull_stress'], (float) $b['hull_integrity']));

// Sotto la quota di collasso, no.
$b = scenario($boatId, $patrolId, [
    'mode' => 'immersione', 'depth_m' => (float) $type['crush_depth_max_m'] + 10,
    'ordered_depth_m' => (float) $type['crush_depth_max_m'] + 10,
    'speed_kn' => 2, 'ordered_speed_kn' => 2,
], 3);
ok('sotto l\'intervallo di collasso lo scafo cede', (string) $b['state'] === 'perduto',
    sprintf('a %.0f m', (float) $type['crush_depth_max_m'] + 10));
ok('e il battello si ferma dov\'e\' morto', (float) $b['speed_kn'] < 0.01);
$fascicolo = Database::first('SELECT stato FROM commanders WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
ok('il comandante non torna',
    $fascicolo !== null && (string) $fascicolo['stato'] !== 'attivo',
    (string) ($fascicolo['stato'] ?? 'nessun fascicolo'));
ok('la missione si chiude come perduta',
    (string) (Database::first('SELECT state FROM patrols WHERE id = ?', [$patrolId])['state'] ?? '') === 'perduta');
ok('e il giornale lo racconta', in_array('perdita', $b['__eventi'], true));

// La simulazione si ferma: niente eventi dopo la morte.
$ultimo = Database::first(
    "SELECT MAX(gts) g FROM patrol_events WHERE patrol_id = ? AND kind <> 'perdita'", [$patrolId]
);
$morte = Database::first("SELECT gts FROM patrol_events WHERE patrol_id = ? AND kind = 'perdita'", [$patrolId]);
ok('dopo la perdita il giornale non scrive piu\' niente',
    $morte !== null && (int) ($ultimo['g'] ?? 0) <= (int) $morte['gts'],
    sprintf('ultima riga %s, perdita %s', (string) ($ultimo['g'] ?? '—'), (string) ($morte['gts'] ?? '—')));

// Un battello perduto non riparte.
$prima = Database::first('SELECT lat, lon, last_sim_gts FROM boats WHERE id = ?', [$boatId]);
BoatSim::advance($boatId, World::now());
$dopo = Database::first('SELECT lat, lon FROM boats WHERE id = ?', [$boatId]);
ok('e il relitto non naviga piu\'',
    abs((float) $prima['lat'] - (float) $dopo['lat']) < 0.0001
    && abs((float) $prima['lon'] - (float) $dopo['lon']) < 0.0001);

// --- la deformazione permanente ---------------------------------------------
titolo('Lo scafo che non torna quello di prima');

$b = scenario($boatId, $patrolId, [
    'mode' => 'immersione', 'depth_m' => 150, 'ordered_depth_m' => 150,
    'speed_kn' => 2, 'ordered_speed_kn' => 2,
], 12);
$scafo = (float) $b['hull_integrity'];
$stressPrima = (float) $b['hull_stress'];
ok('dodici ore sotto la quota di prova lasciano il segno', (string) $b['state'] === 'mare' && $scafo < 99.5,
    sprintf('scafo al %.1f%%', $scafo));

// E il segno non si riassorbe tornando in superficie.
//
// I valori di partenza si rileggono SUBITO dopo aver messo il battello in
// superficie, non da quelli di prima: bin/tick.php passa ogni minuto e fa
// avanzare tutti i battelli in mare, questo compreso. Leggendo lo stato di
// dodici ore fa si rischiava di confrontare due istanti diversi — e infatti
// una volta su tre la sollecitazione risultava cresciuta invece che scesa.
$arrivo = World::now();
$dalle = $arrivo - 6 * 3600;
Database::run("UPDATE boats SET mode = 'superficie', depth_m = 0, ordered_depth_m = 0,
               last_sim_gts = ? WHERE id = ?", [$dalle, $boatId]);
$partenza = Database::first('SELECT hull_stress, hull_integrity FROM boats WHERE id = ?', [$boatId]);
$scafo = (float) $partenza['hull_integrity'];
$stressPrima = (float) $partenza['hull_stress'];
BoatSim::advance($boatId, $arrivo);
$dopo = Database::first('SELECT hull_stress, hull_integrity FROM boats WHERE id = ?', [$boatId]);

// Sei ore in superficie a mare aperto non sono un laboratorio: puo' arrivare
// un aereo, puo' arrivare una scorta, e le bombe la sollecitazione la ALZANO.
// Se e' successo, questa verifica non ha niente da dire e lo dice.
$disturbo = Database::first(
    "SELECT COUNT(*) n FROM patrol_events WHERE patrol_id = ? AND gts >= ?
     AND kind IN ('aereo', 'flak', 'combattimento', 'scafo', 'avaria', 'immersione')",
    [$patrolId, $dalle]
);
if ((int) ($disturbo['n'] ?? 0) > 0) {
    saltata('la sollecitazione si riassorbe, ma con calma',
        'sono successe cose in quelle sei ore: il confronto non direbbe niente');
} else {
    ok('la sollecitazione si riassorbe, ma con calma',
        (float) $dopo['hull_stress'] < $stressPrima - 2.0,
        sprintf('%.1f dopo sei ore in superficie, era %.1f', (float) $dopo['hull_stress'], $stressPrima));
}
ok('la deformazione no', abs((float) $dopo['hull_integrity'] - $scafo) < 0.01,
    sprintf('%.1f%%, come prima', (float) $dopo['hull_integrity']));

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
