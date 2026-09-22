<?php

declare(strict_types=1);

/**
 * Atlantik — il giro completo di una missione.
 *
 *   php tests/test_ciclo.php
 *
 * Tutte le altre prove guardano un pezzo per volta: la simulazione, i danni, i
 * compartimenti, il cantiere. Questa guarda il giro intero, nell'ordine in cui
 * lo vive un giocatore — partenza, mare, avaria, rientro, banchina, seconda
 * partenza — e serve a prendere i difetti che stanno nelle giunture fra un
 * pezzo e l'altro, dove nessuna prova di dettaglio arriva.
 *
 * Nasce dal 22/09/2026, quando il cantiere di base ha cambiato il significato
 * della partenza. Fino a quel giorno `Damage::overhaul` rimetteva a nuovo
 * l'intero battello nel momento in cui si mollavano gli ormeggi: tolta quella
 * rete, bisogna sapere che la partenza rifornisce e arma ancora — quello non
 * doveva cambiare — ma non ripara piu'. E' esattamente il genere di cosa che
 * una prova sul singolo modulo non vede: ogni pezzo funzionava benissimo da
 * solo anche quando il giro non funzionava.
 *
 * Il lucchetto del battello resta preso per tutta la durata: il battito di
 * sistema lavora sugli stessi battelli — anche su quelli in base, da quando
 * c'e' il cantiere — e senza il lucchetto riparerebbe lui mentre la prova
 * misura, rendendo le verifiche capricciose.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Damage;
use App\Sim\World;

$falliti = 0;
function verifica(string $che, mixed $atteso, mixed $ottenuto): void
{
    global $falliti;
    if ($atteso === $ottenuto) {
        printf("  \033[0;32mok\033[0m    %s\n", $che);
        return;
    }
    $falliti++;
    printf("  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n",
        $che, var_export($atteso, true), var_export($ottenuto, true));
}
function titolo(string $t): void { printf("\n  \033[0;90m%s\033[0m\n", $t); }

// Una prova non tocca mai roba che non ha creato lei.
$utente = 'prova ciclo ' . time();
$reg = \App\Auth\Auth::register($utente, 'ciclo_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
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
    'nome' => 'Ciclo Prova ' . substr((string) time(), -5), 'nato_il' => '1912-11-30',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];
$boat = $userId > 0 && ($cmd['ok'] ?? false) ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) {
    echo "  \033[0;90mniente battello: prova saltata\033[0m\n";
    exit(0);
}
$boatId = (int) $boat['id'];
Lock::prendi('boat:' . $boatId, 5);
register_shutdown_function(static fn () => Lock::lascia('boat:' . $boatId));

$leggi = static fn (string $skey): array => Database::first(
    'SELECT state, condition_pct, repair_progress FROM boat_systems WHERE boat_id = ? AND skey = ?',
    [$boatId, $skey]
) ?? [];
$rompi = static function (string $skey) use ($boatId): void {
    Database::run(
        "UPDATE boat_systems SET state = 'avaria', condition_pct = 40, repair_progress = 0
          WHERE boat_id = ? AND skey = ?",
        [$boatId, $skey]
    );
};

titolo('1. Partenza: rifornisce e arma ancora');

// Si parte da un battello svuotato e da un equipaggio distrutto, per vedere
// che cosa la partenza rimette a posto davvero.
Database::run('UPDATE boat_stores SET qty = 1 WHERE boat_id = ?', [$boatId]);
Database::run('UPDATE crew_members SET fatigue = 90, morale = 30 WHERE boat_id = ?', [$boatId]);
$boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$esito = Patrol::depart($boat);
verifica('il battello esce', true, (bool) ($esito['ok'] ?? false));

$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
verifica('e' . "' " . 'in mare', 'mare', (string) $b['state']);
verifica('con la nafta imbarcata', true, (float) $b['fuel_t'] > 0.0);
verifica('e le batterie cariche', 100.0, (float) $b['battery_pct']);
$vuote = (int) (Database::first(
    'SELECT COUNT(*) n FROM boat_stores WHERE boat_id = ? AND qty < qty_max', [$boatId]
)['n'] ?? 0);
verifica('le scorte sono state reintegrate', 0, $vuote);
$siluri = (int) (Database::first('SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ?', [$boatId])['n'] ?? 0);
verifica('i siluri sono a bordo', true, $siluri > 0);
$fatica = (float) (Database::first('SELECT AVG(fatigue) f FROM crew_members WHERE boat_id = ?', [$boatId])['f'] ?? 99.0);
verifica('l' . "'" . 'equipaggio ha riposato in banchina', true, $fatica < 40.0);

titolo('2. A mare: la squadra ripara quello che si puo\' riparare a mare');

$rompi('pompe');
$rompi('periscopio_att');
$progressi = [];
Damage::repairStep(
    $boatId,
    Damage::systems($boatId),
    3.0,
    ['specialita' => ['macchinista_elettrico' => 1.2, 'zentrale' => 1.2], 'mare' => 3, 'ricambi' => 5],
    null,
    $progressi
);
verifica('la squadra lavora sulle pompe', true, (float) $leggi('pompe')['repair_progress'] > 0.0);
verifica('e il periscopio a mare non lo tocca', 0.0, (float) $leggi('periscopio_att')['repair_progress']);

titolo('3. Rientro: il cantiere prende in carico, ma non ha ancora fatto niente');

$base = World::port((string) $b['home_port_key']);
Database::run('UPDATE boats SET lat = ?, lon = ? WHERE id = ?', [(float) $base['lat'], (float) $base['lon'], $boatId]);
Database::run(
    'UPDATE boat_compartments SET sealed = 1, integrity = 55, flooding = 15, repair_progress = 0
      WHERE boat_id = ? ORDER BY seq LIMIT 1',
    [$boatId]
);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$rientro = Patrol::dock($b);
verifica('il battello rientra', true, (bool) ($rientro['ok'] ?? false));

$b = Database::first('SELECT state, last_sim_gts FROM boats WHERE id = ?', [$boatId]);
verifica('e' . "' " . 'in base', 'base', (string) $b['state']);
verifica('con l' . "'" . 'orologio all' . "'" . 'istante dell' . "'" . 'attracco', true,
    abs((int) $b['last_sim_gts'] - World::now()) < 120);
$rotti = (int) (Database::first(
    "SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'", [$boatId]
)['n'] ?? 0);
verifica('le avarie sono ancora a bordo appena attraccati', true, $rotti > 0);

titolo('4. In banchina: il cantiere lavora, e ci mette il suo tempo');

// Mezz'ora non basta a chiudere un periscopio (diciotto ore-uomo): deve pero'
// lasciare traccia, se no vuol dire che in porto non lavora nessuno.
Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - 1800, $boatId]);
BoatSim::advance($boatId);
$per = $leggi('periscopio_att');
verifica('mezz' . "'" . 'ora: il periscopio e' . "' " . 'in lavorazione', true,
    (float) $per['repair_progress'] > 0.0);
verifica('ma non e' . "' " . 'finito', 'avaria', (string) $per['state']);

$aperti = static function () use ($boatId): int {
    return (int) (Database::first(
        "SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND (state <> 'ok' OR condition_pct < 100)", [$boatId]
    )['n'] ?? 0)
    + (int) (Database::first(
        'SELECT COUNT(*) n FROM boat_compartments
          WHERE boat_id = ? AND (integrity < 100 OR flooding > 0 OR fire > 0 OR sealed = 1)', [$boatId]
    )['n'] ?? 0);
};
$giri = 0;
while ($giri < 40 && $aperti() > 0) {
    $giri++;
    Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - 6 * 3600, $boatId]);
    BoatSim::advance($boatId);
}
verifica('col tempo il cantiere chiude tutti i lavori', true, $giri < 40);
verifica('non resta niente di rotto ne' . "' " . 'di logoro', 0, $aperti());
$c = Database::first('SELECT sealed, integrity, flooding FROM boat_compartments WHERE boat_id = ? ORDER BY seq LIMIT 1', [$boatId]);
verifica('la paratia sigillata e' . "' " . 'stata riaperta', 0, (int) $c['sealed']);
verifica('il compartimento e' . "' " . 'integro', 100.0, (float) $c['integrity']);
verifica('e asciutto', 0.0, (float) $c['flooding']);

titolo('5. Si riparte con quello che c\'e\': la partenza non ripara piu\'');

$rompi('diesel_2');
Database::run('UPDATE boat_compartments SET sealed = 1 WHERE boat_id = ? ORDER BY seq LIMIT 1', [$boatId]);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$esito2 = Patrol::depart($b);
verifica('si riparte: decide il comandante, non il cantiere', true, (bool) ($esito2['ok'] ?? false));
verifica('il diesel rotto e' . "' " . 'ancora rotto dopo la partenza', 'avaria', (string) $leggi('diesel_2')['state']);
$cDopo = Database::first('SELECT sealed FROM boat_compartments WHERE boat_id = ? ORDER BY seq LIMIT 1', [$boatId]);
verifica('e la paratia sigillata e' . "' " . 'ancora sigillata', 1, (int) $cDopo['sealed']);

// Ma il rifornimento deve avere funzionato lo stesso: la partenza ha smesso di
// riparare, non di armare.
$vuote = (int) (Database::first(
    'SELECT COUNT(*) n FROM boat_stores WHERE boat_id = ? AND qty < qty_max', [$boatId]
)['n'] ?? 0);
verifica('e le scorte sono state reintegrate lo stesso', 0, $vuote);

titolo('6. Il ruolino');

$n = (int) (Database::first('SELECT COUNT(*) n FROM patrols WHERE boat_id = ?', [$boatId])['n'] ?? 0);
verifica('due missioni a ruolino', 2, $n);
$concluse = (int) (Database::first(
    "SELECT COUNT(*) n FROM patrols WHERE boat_id = ? AND state = 'conclusa'", [$boatId]
)['n'] ?? 0);
verifica('la prima risulta conclusa', 1, $concluse);
$b = Database::first('SELECT state FROM boats WHERE id = ?', [$boatId]);
verifica('e il battello e' . "' " . 'di nuovo in mare', 'mare', (string) $b['state']);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
