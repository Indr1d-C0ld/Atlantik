<?php

declare(strict_types=1);

/**
 * La potatura: che il mondo non cresca per sempre.
 *
 *   php tests/test_potatura.php
 *
 * Un mondo che gira da mesi accumula: naviglio arrivato in porto, incontri
 * chiusi, contatti spenti, battiti, posta consegnata. Qualcuno deve toglierlo,
 * e la prova che quel qualcuno faccia il suo mestiere non si puo' aspettare sei
 * mesi: si costruisce un mondo gia' vecchio e gli si passa sopra il potatore.
 *
 * L'audit del 19/09/2026 ha trovato qui una perdita col botto doppio. Ogni
 * incontro materializza la formazione del convoglio — venticinque righe di
 * naviglio — e nessuno le toglieva a incontro finito. Non era solo peso morto:
 * Traffic::pota() si rifiuta di togliere una nave arrivata in porto se
 * un'entita' la nomina ancora, quindi ogni convoglio mai attaccato teneva in
 * vita le sue venticinque navi PER SEMPRE. Due tabelle che crescono, e la prima
 * che impedisce alla seconda di essere potata.
 *
 * Misurato allora su un caso costruito: una nave arrivata quaranta giorni
 * prima, trattenuta da un incontro concluso. Tolta l'entita', la stessa
 * potatura se la portava via subito.
 *
 * Tocca il database: crea roba vecchia e se la porta via, qualunque cosa
 * succeda.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Contacts;
use App\Sim\Encounter;
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

// --- un battello di prova, e un mondo gia' vecchio ----------------------------
$utente = 'prova potatura ' . time();
$reg = \App\Auth\Auth::register($utente, 'pot_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) {
    echo "Impossibile preparare il battello di prova.\n";
    exit(1);
}
$boatId = (int) $boat['id'];

$roba = ['navi' => [], 'incontri' => [], 'contatti' => []];
register_shutdown_function(static function () use ($userId, &$roba): void {
    foreach ($roba['incontri'] as $id) {
        Database::run('DELETE FROM encounters WHERE id = ?', [$id]);
    }
    foreach ($roba['navi'] as $id) {
        Database::run('DELETE FROM ships WHERE id = ?', [$id]);
    }
    Database::run('DELETE FROM users WHERE id = ?', [$userId]);
});

$gts = World::now();
$vecchio = $gts - 40 * 86400;     // quaranta giorni di gioco fa
$fresco  = $gts - 3600;           // un'ora fa

/** Una nave arrivata in porto, con l'ora che si vuole. */
function nave(string $nome, int $eta): int
{
    Database::run(
        "INSERT INTO ships (name, flag, class_key, rotta_key, speed_kn, grt, state, departed_gts, eta_gts, carico)
         VALUES (?, 'britannica', 'cargo_medio', 'hx_halifax_liverpool', 9, 6000, 'arrivata', ?, ?, 'acciaio')",
        [$nome, $eta - 86400, $eta]
    );

    return Database::lastInsertId();
}

/** Un incontro, con lo stato e l'ora che si vogliono, e una nave dentro. */
function incontro(int $boatId, int $shipId, string $stato, int $quando): int
{
    Database::run(
        "INSERT INTO encounters (boat_id, stato, started_gts, last_step_gts, finestra_fine, ended_gts, esito)
         VALUES (?, ?, ?, ?, ?, ?, 'prova di potatura')",
        [$boatId, $stato, $quando, $quando, $quando + 3600, $stato === 'concluso' ? $quando : null]
    );
    $encId = Database::lastInsertId();
    Database::run(
        "INSERT INTO encounter_entities (encounter_id, ship_id, class_key, name, ruolo, lat, lon, heading,
                                         speed_kn, grt, integrita, allagamento, incendio, stato)
         VALUES (?, ?, 'cargo_medio', 'Entita di prova', 'mercantile', 50, -30, 90, 9, 6000, 100, 0, 0, 'in_mare')",
        [$encId, $shipId]
    );
    Database::run(
        "INSERT INTO torpedo_runs (encounter_id, boat_id, tkey, tubo, lanciato_gts, lat, lon, heading,
                                   speed_kn, quota_m, spoletta, corsa_max_m, esito)
         VALUES (?, ?, 'G7a', 1, ?, 50, -30, 90, 30, 4, 'contatto', 6000, 'mancato')",
        [$encId, $boatId, $quando]
    );

    return $encId;
}

// Tre situazioni: la vecchia da potare, la fresca da lasciare, e quella
// ancora aperta che non si tocca per nessun motivo.
$naveVecchia = nave('Potatura Vecchia ' . time(), $vecchio);
$naveFresca  = nave('Potatura Fresca ' . time(), $fresco);
$naveAperta  = nave('Potatura Aperta ' . time(), $vecchio);
$roba['navi'] = [$naveVecchia, $naveFresca, $naveAperta];

$encVecchio = incontro($boatId, $naveVecchia, 'concluso', $vecchio);
$encFresco  = incontro($boatId, $naveFresca, 'concluso', $fresco);
$encAperto  = incontro($boatId, $naveAperta, 'attacco', $vecchio);
$roba['incontri'] = [$encVecchio, $encFresco, $encAperto];

Database::run(
    "INSERT INTO contacts (boat_id, target_kind, ship_id, sensore, first_gts, last_gts, bearing, certezza, perso, classe_est)
     VALUES (?, 'nave', ?, 'idrofono', ?, ?, 90, 0.4, 1, 'contatto vecchio di prova')",
    [$boatId, $naveVecchia, $vecchio, $vecchio]
);
Database::run(
    "INSERT INTO contacts (boat_id, target_kind, ship_id, sensore, first_gts, last_gts, bearing, certezza, perso, classe_est)
     VALUES (?, 'nave', ?, 'idrofono', ?, ?, 90, 0.4, 0, 'contatto vivo di prova')",
    [$boatId, $naveFresca, $fresco, $fresco]
);

// --- la zavorra degli incontri chiusi -----------------------------------------
titolo('La zavorra degli incontri chiusi');

$pe = Encounter::pota($gts);
$restaVecchio = (int) Database::first('SELECT COUNT(*) n FROM encounter_entities WHERE encounter_id = ?', [$encVecchio])['n'];
$restaFresco  = (int) Database::first('SELECT COUNT(*) n FROM encounter_entities WHERE encounter_id = ?', [$encFresco])['n'];
$restaAperto  = (int) Database::first('SELECT COUNT(*) n FROM encounter_entities WHERE encounter_id = ?', [$encAperto])['n'];

ok('l\'incontro chiuso da quaranta giorni lascia andare il suo naviglio', $restaVecchio === 0,
    sprintf('%d entita rimosse, %d corse di siluro', (int) $pe['entita'], (int) $pe['siluri']));
ok('quello chiuso un\'ora fa resta intero', $restaFresco === 1);
ok('e quello ancora in corso non si tocca', $restaAperto === 1);

$riga = Database::first('SELECT stato, esito FROM encounters WHERE id = ?', [$encVecchio]);
ok('la riga dell\'incontro resta: tre trofei guardano i suoi totali', $riga !== null,
    (string) ($riga['esito'] ?? '—'));

// --- i contatti spenti ---------------------------------------------------------
titolo('I contatti spenti');

$pc = Contacts::pota($gts);
$vecchioC = (int) Database::first(
    "SELECT COUNT(*) n FROM contacts WHERE boat_id = ? AND classe_est = 'contatto vecchio di prova'", [$boatId]
)['n'];
$vivoC = (int) Database::first(
    "SELECT COUNT(*) n FROM contacts WHERE boat_id = ? AND classe_est = 'contatto vivo di prova'", [$boatId]
)['n'];
ok('il contatto perso quaranta giorni fa se ne va', $vecchioC === 0, "{$pc} rimossi");
ok('quello ancora vivo resta', $vivoC === 1);

// --- e adesso la nave si puo' potare -------------------------------------------
titolo('E la nave, finalmente, si pota');

$r = Traffic::pota($gts);
$naveC = Database::first('SELECT id FROM ships WHERE id = ?', [$naveVecchia]);
$naveF = Database::first('SELECT id FROM ships WHERE id = ?', [$naveFresca]);
$naveA = Database::first('SELECT id FROM ships WHERE id = ?', [$naveAperta]);

ok('la nave arrivata quaranta giorni fa se ne va', $naveC === null,
    sprintf('%d navi potate in tutto', (int) $r['navi']));
ok('quella arrivata un\'ora fa resta', $naveF !== null);
ok('e quella nominata da un incontro ancora aperto resta', $naveA !== null,
    'un incontro in corso e\' roba viva');

// --- il battito lo fa da solo ---------------------------------------------------
titolo('E il battito se ne occupa da solo');

$fonte = (string) file_get_contents(__DIR__ . '/../bin/tick.php');
ok('la manutenzione del battito chiama la potatura degli incontri',
    str_contains($fonte, 'Encounter::pota('), 'bin/tick.php');
ok('e quella dei contatti', str_contains($fonte, 'Contacts::pota('), 'bin/tick.php');

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
