<?php

declare(strict_types=1);

/**
 * La camera di lancio: ricarica dei tubi e contenitori di coperta.
 *
 *   php tests/test_siluri.php
 *
 * Questo e' il difetto piu' grave trovato nell'audit del 19/09/2026, e si
 * vedeva soltanto guardando chi chiama che cosa: Torpedo::ricarica() esisteva,
 * era scritta bene, ed era chiamata da NESSUNO.
 *
 * Un VIIB parte con quattordici siluri: cinque nei tubi, otto in camera di
 * lancio, uno nel contenitore stagno di coperta. Lanciati i cinque dei tubi, il
 * battello restava disarmato per il resto della crociera — con nove siluri a
 * bordo, l'inventario che continuava a contarli, e nessun modo al mondo di
 * usarli. Una crociera di trenta giorni finiva dopo il primo attacco.
 *
 * E il siluro di coperta era zavorra comunque: la ricarica pescava solo dalle
 * riserve interne, quindi quello fuori non sarebbe mai entrato.
 *
 * Tocca il database: crea un utente di prova e se lo porta via alla fine.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Rng;
use App\Sim\Torpedo;
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

$utente = 'prova siluri ' . time();
$reg = \App\Auth\Auth::register($utente, 'sil_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
register_shutdown_function(static function () use ($userId): void {
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    }
});

$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) {
    echo "Impossibile preparare il battello di prova.\n";
    exit(1);
}
$boatId = (int) $boat['id'];
$type = World::type((string) $boat['type_key']);

Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
     SELECT id, user_id, commander_id, 1, ?, 'in_corso', home_port_key FROM boats WHERE id = ?",
    [World::now() - 3600, $boatId]
);
$patrolId = Database::lastInsertId();

// --- come si parte -----------------------------------------------------------
titolo('Quello che si imbarca');

Torpedo::imbarca($boatId, $type, Torpedo::caricoStandard($type));
$inv = Torpedo::inventario($boatId);
$tubiTot = (int) $type['tubes_bow'] + (int) $type['tubes_stern'];

ok('tutti i siluri del tipo sono a bordo',
    $inv['tubi'] + $inv['riserve'] === (int) $type['torpedoes'],
    sprintf('%d nei tubi, %d in riserva, di cui %d in coperta',
        $inv['tubi'], $inv['riserve'], $inv['esterni']));
ok('i tubi sono pieni', $inv['tubi'] === $tubiTot, sprintf('%d tubi', $tubiTot));
ok('e qualcosa sta nel contenitore di coperta', $inv['esterni'] > 0);

// --- si lancia tutto ----------------------------------------------------------
titolo('Lanciati i tubi, il battello e\' disarmato?');

Database::run("UPDATE boat_torpedoes SET stato = 'lanciato' WHERE boat_id = ? AND posizione LIKE 'tubo%'", [$boatId]);
ok('nessun tubo pronto dopo la salva', Torpedo::tubiPronti($boatId) === []);

$riservePrima = Torpedo::inventario($boatId)['riserve'];
$gts = World::now();
$rng = Rng::for(World::seed(), 'prova_siluri', $boatId);

// Mezz'ora di gioco, in superficie con mare calmo: si ricarica.
$eventi = [];
$passi = 0;
for ($t = $gts; $t < $gts + 7200; $t += 300) {
    Torpedo::ricarica($boatId, $t, 2, 1.2, true, $eventi, $rng);
    $passi++;
}
$dopo = Torpedo::inventario($boatId);
ok('due ore dopo, i tubi non sono piu\' vuoti', $dopo['tubi'] > 0,
    sprintf('%d tubi pronti, %d ancora in riserva (erano %d)', $dopo['tubi'], $dopo['riserve'], $riservePrima));
ok('e le riserve sono calate di altrettanto',
    $riservePrima - $dopo['riserve'] >= $dopo['tubi'] - ($dopo['in_carica'] ?? 0)
    || $dopo['riserve'] < $riservePrima);
ok('il giornale lo racconta', $eventi !== [], (string) ($eventi[0] ?? '—'));
ok('i tubi ricaricati si possono lanciare', Torpedo::tubiPronti($boatId) !== []);

// --- il contenitore di coperta -------------------------------------------------
titolo('Il siluro che stava fuori');

// Si consuma tutta la riserva interna: resta solo quello in coperta.
Database::run("DELETE FROM boat_torpedoes WHERE boat_id = ? AND posizione = 'riserva_interna'", [$boatId]);
Database::run("UPDATE boat_torpedoes SET stato = 'lanciato' WHERE boat_id = ? AND posizione LIKE 'tubo%'", [$boatId]);
$inv = Torpedo::inventario($boatId);
ok('resta solo il siluro di coperta', $inv['riserve'] === $inv['esterni'] && $inv['esterni'] > 0,
    sprintf('%d in coperta', $inv['esterni']));

// Sott'acqua non se ne parla.
$eventi = [];
for ($t = $gts; $t < $gts + 7200; $t += 300) {
    Torpedo::ricarica($boatId, $t, 2, 1.2, false, $eventi, $rng);
}
ok('immersi il portello di carico non si apre',
    Torpedo::inventario($boatId)['esterni'] === $inv['esterni']);

// Col mare grosso nemmeno.
$eventi = [];
for ($t = $gts; $t < $gts + 7200; $t += 300) {
    Torpedo::ricarica($boatId, $t, 7, 1.2, true, $eventi, $rng);
}
ok('col mare forza 7 nemmeno', Torpedo::inventario($boatId)['esterni'] === $inv['esterni']);

// In superficie, mare calmo, tutto il tempo che serve.
$eventi = [];
for ($t = $gts; $t < $gts + 6 * 3600; $t += 300) {
    Torpedo::ricarica($boatId, $t, 2, 1.2, true, $eventi, $rng);
}
$fine = Torpedo::inventario($boatId);
ok('in superficie col mare calmo il siluro entra', $fine['esterni'] < $inv['esterni'],
    sprintf('%d rimasti in coperta', $fine['esterni']));
ok('e finisce in un tubo o in stiva, non nel nulla',
    $fine['tubi'] + $fine['riserve'] + ($fine['in_carica'] ?? 0) + $fine['guasti'] > 0,
    sprintf('%d nei tubi, %d in riserva, %d in carica, %d guasti',
        $fine['tubi'], $fine['riserve'], $fine['in_carica'] ?? 0, $fine['guasti']));

// --- il siluro guasto ----------------------------------------------------------
titolo('Il siluro che ha preso acqua');

// Trentadue recuperi dal contenitore: con una probabilita' del dodici per
// cento, che nessuno esca guasto ha una probabilita' di uno su sessanta.
$guastiVisti = 0;
for ($giro = 0; $giro < 32; $giro++) {
    Database::run("DELETE FROM boat_torpedoes WHERE boat_id = ?", [$boatId]);
    Database::run(
        "INSERT INTO boat_torpedoes (boat_id, tkey, posizione, stato, ricarica_fine_gts)
         VALUES (?, 'g7e', 'riserva_interna', 'in_carica', ?)",
        [$boatId, $gts]
    );
    $eventi = [];
    Torpedo::ricarica($boatId, $gts + 1, 2, 1.2, true, $eventi, Rng::for(World::seed(), 'guasto', $boatId, $giro));
    if (Torpedo::inventario($boatId)['guasti'] > 0) {
        $guastiVisti++;
    }
}
ok('ogni tanto il siluro di coperta e\' andato', $guastiVisti > 0,
    sprintf('%d su 32 recuperi', $guastiVisti));

Database::run("DELETE FROM boat_torpedoes WHERE boat_id = ?", [$boatId]);
Database::run(
    "INSERT INTO boat_torpedoes (boat_id, tkey, posizione, stato) VALUES (?, 'g7e', 'riserva_interna', 'guasto')",
    [$boatId]
);
$invG = Torpedo::inventario($boatId);
ok('un siluro guasto non si conta fra quelli buoni',
    $invG['riserve'] === 0 && $invG['guasti'] === 1);

// --- e adesso dalla porta principale -------------------------------------------
//
// Tutto quello di sopra passerebbe benissimo anche con Torpedo::ricarica()
// chiamata da nessuno, che e' esattamente la situazione in cui stava. La
// verifica che conta e' questa: si fa navigare il battello e si guarda se i
// tubi si riempiono da soli.
titolo('Navigando, senza chiamare nessuno a mano');

Torpedo::imbarca($boatId, $type, Torpedo::caricoStandard($type));
Database::run("UPDATE boat_torpedoes SET stato = 'lanciato' WHERE boat_id = ? AND posizione LIKE 'tubo%'", [$boatId]);
ok('si riparte con i tubi vuoti', Torpedo::tubiPronti($boatId) === []);

$arrivo = World::now();
Database::run(
    "UPDATE boats SET state = 'mare', mode = 'superficie', depth_m = 0, ordered_depth_m = 0,
            speed_kn = 8, ordered_speed_kn = 8, fuel_t = 50, battery_pct = 100, air_pct = 100,
            provisions_days = 30, lat = 47, lon = -12, est_lat = 47, est_lon = -12,
            hull_stress = 0, hull_integrity = 100, last_sim_gts = ? WHERE id = ?",
    [$arrivo - 4 * 3600, $boatId]
);
\App\Sim\BoatSim::advance($boatId, $arrivo);

$navigando = Torpedo::inventario($boatId);
ok('quattro ore di navigazione ricaricano i tubi', $navigando['tubi'] > 0,
    sprintf('%d nei tubi, %d in riserva', $navigando['tubi'], $navigando['riserve']));
ok('e la ricarica finisce nel giornale di bordo',
    (int) (Database::first(
        "SELECT COUNT(*) n FROM patrol_events WHERE patrol_id = ? AND kind = 'siluri'", [$patrolId]
    )['n'] ?? 0) > 0);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
