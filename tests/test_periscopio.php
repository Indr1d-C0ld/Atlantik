<?php

declare(strict_types=1);

/**
 * Atlantik — il periscopio d'osservazione, in crociera.
 *
 *   php tests/test_periscopio.php
 *
 * Nasce da una prova sul campo del proprietario (29/09/2026): «non vedo la
 * sezione periscopio, e' tutta assieme nella sezione ascolto?». Non c'era: il
 * periscopio esisteva solo nella stazione d'attacco, e in crociera, a quota
 * periscopica, la simulazione lo dava per sempre fuori — si vedeva e si era
 * visti, senza poterlo abbassare. Qui si verifica che adesso:
 *
 *   - arrivati a quota periscopica il periscopio esca, e lasciata la quota
 *     rientri;
 *   - abbassato, resti abbassato, e non si veda piu' niente;
 *   - alzato, si veda quello che c'e' da vedere;
 *   - i comandi rifiutino quello che non si puo' fare;
 *   - l'incontro tattico lo prenda in carico con la regola dei cinque minuti,
 *     e alla fine lo restituisca alla guardia di crociera.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Periscopio;
use App\Sim\BoatSim;
use App\Sim\Detection;
use App\Sim\Encounter;
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

$reg = \App\Auth\Auth::register('prova periscopio ' . substr((string) time(), -5),
    'peri' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) $reg['user_id'];
register_shutdown_function(static fn () => Database::run('DELETE FROM users WHERE id = ?', [$userId]));
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
$boat = \App\Game\Fleet::ensureBoat($userId);
$id = (int) $boat['id'];
$leggi = static fn (): array => Database::first('SELECT * FROM boats WHERE id = ?', [$id]);

/** Mette il battello in mare in un assetto e con un'ora di storia da vivere. */
$assetto = static function (float $lat, float $lon, float $quota, float $quotaOrdinata, bool $alzato) use ($id): void {
    $modo = \App\Sim\Movement::modeForDepth($quota);
    Database::run(
        "UPDATE boats SET state = 'mare', encounter_id = NULL, battle_stations = 0, mode = ?, depth_m = ?, ordered_depth_m = ?,
                speed_kn = 2, ordered_speed_kn = 2, battery_pct = 90, air_pct = 95, provisions_days = 40, fuel_t = 80,
                lat = ?, lon = ?, est_lat = ?, est_lon = ?, silent = 0, periscopio_alzato = ?, periscopio_gts = NULL,
                auto_dive_fine_gts = NULL, auto_dive_quota = NULL, last_sim_gts = ? WHERE id = ?",
        [$modo, $quota, $quotaOrdinata, $lat, $lon, $lat, $lon, $alzato ? 1 : 0, World::now() - 3600, $id]
    );
};

// ==========================================================================
titolo('Arrivati a quota periscopica il periscopio esce, lasciata la quota rientra');
// ==========================================================================

$assetto(47.0, -25.0, 0.0, 12.0, false);
BoatSim::advance($id);
$b = $leggi();
ok('dalla superficie a dodici metri: si e\' a quota periscopica', (string) $b['mode'] === 'periscopio', (string) $b['mode'] . ', ' . $b['depth_m'] . ' m');
ok('e il I.WO ha alzato il periscopio', (int) $b['periscopio_alzato'] === 1);

$assetto(47.0, -25.0, 12.0, 60.0, true);
BoatSim::advance($id);
$b = $leggi();
ok('scendendo a sessanta metri il periscopio rientra da solo', (int) $b['periscopio_alzato'] === 0, (string) $b['mode']);

$assetto(47.0, -25.0, 12.0, 12.0, false);
BoatSim::advance($id);
ok('abbassato a quota periscopica, resta abbassato', (int) $leggi()['periscopio_alzato'] === 0);

// ==========================================================================
titolo('I comandi');
// ==========================================================================

$assetto(47.0, -25.0, 0.0, 0.0, false);
$r = Periscopio::comanda($leggi(), true);
ok('in superficie non si alza', !$r['ok'], $r['error'] ?? '');

$assetto(47.0, -25.0, 80.0, 80.0, false);
$r = Periscopio::comanda($leggi(), true);
ok('a ottanta metri nemmeno', !$r['ok'], $r['error'] ?? '');

$assetto(47.0, -25.0, 12.0, 12.0, false);
$r = Periscopio::comanda($leggi(), true);
ok('a quota periscopica si alza', $r['ok'] && (int) $leggi()['periscopio_alzato'] === 1, $r['error'] ?? ($r['testo'] ?? ''));
$r = Periscopio::comanda($leggi(), false);
ok('e si abbassa', $r['ok'] && (int) $leggi()['periscopio_alzato'] === 0, $r['error'] ?? ($r['testo'] ?? ''));

Database::run("UPDATE boat_systems SET state = 'avaria' WHERE boat_id = ? AND skey = 'periscopio_osc'", [$id]);
$r = Periscopio::comanda($leggi(), true);
ok('in avaria non si alza', !$r['ok'] && (int) $leggi()['periscopio_alzato'] === 0, $r['error'] ?? '');
Database::run("UPDATE boat_systems SET state = 'ok' WHERE boat_id = ? AND skey = 'periscopio_osc'", [$id]);

// ==========================================================================
titolo('Dentro non si vede niente, fuori si vede');
// ==========================================================================

$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1", [$gts, $gts]
);
// Per guardare serve una nave isolata, non un convoglio: il contatto conserva
// soltanto l'ULTIMO sensore che l'ha preso (se nell'ultimo passo la nave si
// sente ma non si vede, la riga dice «idrofono» anche dopo mezz'ora
// d'osservazione), ma la classe di una nave isolata si scrive solo a vista e
// poi resta. E' la traccia che un avvistamento c'e' stato.
$nave = Database::first(
    "SELECT * FROM ships WHERE convoy_id IS NULL AND state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts - 3600, $gts]
);
if ($nave === null) {
    echo "  \033[0;90mnessuna nave isolata in mare: prova saltata\033[0m\n";
} else {
    // L'ora che si simula e' quella appena passata, e in un'ora la nave fa
    // qualche miglio. Il battello sta a un miglio dal PUNTO MEDIO di quel
    // tratto, di traverso: la nave gli sfila davanti per tutta l'ora.
    $pos = static fn (int $t): array => Traffic::posizione((string) $nave['rotta_key'], (float) $nave['speed_kn'],
        (int) $nave['departed_gts'], $t, 0.0);
    $p0 = $pos($gts - 3600);
    $p1 = $pos($gts);
    $rotta = Geo::bearing((float) $p0['lat'], (float) $p0['lon'], (float) $p1['lat'], (float) $p1['lon']);
    [$mLa, $mLo] = Geo::destination((float) $p0['lat'], (float) $p0['lon'], $rotta,
        Geo::distanceNm((float) $p0['lat'], (float) $p0['lon'], (float) $p1['lat'], (float) $p1['lon']) / 2);
    [$la, $lo] = Geo::destination($mLa, $mLo, $rotta + 90.0, 1.0);

    $vista = static function (bool $alzato) use ($assetto, $id, $la, $lo, $nave): ?array {
        Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
        $assetto($la, $lo, 12.0, 12.0, $alzato);
        BoatSim::advance($id);
        return Database::first('SELECT sensore, classe_key_est FROM contacts WHERE boat_id = ? AND ship_id = ?',
            [$id, (int) $nave['id']]);
    };
    $giu = $vista(false);
    $su  = $vista(true);
    ok('col periscopio dentro, a un miglio da una nave, non la si vede mai',
        $giu === null || ($giu['classe_key_est'] === null && (string) $giu['sensore'] !== 'vista'),
        $giu === null ? 'nessun contatto' : 'contatto all\'' . $giu['sensore']);

    $meteo = World::weather($la, $lo, $gts);
    $cielo = World::sky($la, $lo, $gts, (float) $meteo['cloud']);
    $cls = Traffic::classe((string) $nave['class_key']);
    $portata = Detection::portataVisiva(Detection::H_PERISCOPIO, max(8.0, (float) $cls['length_m'] * 0.20), 1.0,
        (float) $meteo['visibility_nm'], (float) $cielo['luce'], (int) $meteo['sea_state'], 1.0, (bool) $meteo['fog']);
    if ($portata > 2.5) {
        ok('col periscopio fuori la si vede', $su !== null && $su['classe_key_est'] !== null,
            sprintf('%s, portata %.1f nm', $su === null ? 'nessun contatto' : 'classe ' . ($su['classe_key_est'] ?? 'ignota'), $portata));
    } else {
        printf("  \033[0;90mportata del periscopio su questa nave %.1f nm: il confronto col periscopio fuori si salta\033[0m\n", $portata);
    }
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$id]);
}
if ($cv !== null) {
    $p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
    [$la, $lo] = Geo::destination((float) $p['lat'], (float) $p['lon'], 90.0, 1.2);
}

// ==========================================================================
titolo('Da dove si guarda');
// ==========================================================================

$casi = [
    ['superficie', false, false, Detection::H_TORRETTA, 'in superficie, dalla torretta'],
    ['superficie', false, true,  Detection::H_TORRETTA, 'in superficie anche col periscopio guasto'],
    ['periscopio', true,  false, Detection::H_PERISCOPIO, 'a quota periscopica col periscopio fuori'],
    ['periscopio', false, false, null, 'a quota periscopica col periscopio dentro: niente'],
    ['periscopio', true,  true,  null, 'col periscopio in avaria: niente'],
    ['immersione', true,  false, null, 'sotto la quota periscopica: niente'],
];
foreach ($casi as [$modo, $su, $guasto, $atteso, $come]) {
    $h = BoatSim::occhio($modo, $su, $guasto);
    ok($come, $h === $atteso, $h === null ? 'cieco' : sprintf('occhio a %.1f m', $h));
}
ok('e l\'occhio del periscopio sta piu\' in basso di quello della torretta', Detection::H_PERISCOPIO < Detection::H_TORRETTA);

// ==========================================================================
titolo('L\'incontro lo prende in carico, e poi lo restituisce');
// ==========================================================================

if ($cv !== null) {
    $assetto($la, $lo, 12.0, 12.0, true);
    $res = Encounter::apri($leggi(), ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
    ok('si apre l\'incontro col periscopio fuori', (bool) $res['ok'], $res['error'] ?? '');
    if ($res['ok']) {
        $b = $leggi();
        ok('e da quel momento corre l\'orologio dei cinque minuti', $b['periscopio_gts'] !== null && (int) $b['periscopio_gts'] === $gts,
            (string) ($b['periscopio_gts'] ?? 'nessuno'));
        $r = Periscopio::comanda($b, false);
        ok('dalla postazione di crociera non lo si comanda durante l\'attacco', !$r['ok'], $r['error'] ?? '');

        Encounter::chiudi((int) $res['encounter_id'], 'Prova.', $gts);
        ok('chiuso l\'incontro a quota periscopica, il periscopio d\'osservazione torna fuori',
            (int) $leggi()['periscopio_alzato'] === 1 && $leggi()['encounter_id'] === null);

        $res2 = Encounter::apri($leggi(), ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
        Database::run('UPDATE boats SET mode = "immersione", depth_m = 60 WHERE id = ?', [$id]);
        if ($res2['ok']) {
            Encounter::chiudi((int) $res2['encounter_id'], 'Prova.', $gts);
        }
        ok('chiuso a sessanta metri, resta dentro', (int) $leggi()['periscopio_alzato'] === 0);
        Database::run('DELETE FROM encounters WHERE boat_id = ?', [$id]);
    }
}

echo "\n";
if ($falliti === 0) { echo "\033[0;32mTutte le verifiche superate.\033[0m\n"; exit(0); }
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
