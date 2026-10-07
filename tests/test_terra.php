<?php

declare(strict_types=1);

/**
 * Atlantik — la terraferma.
 *
 *   php tests/test_terra.php
 *
 * Nasce da una domanda del proprietario (30/09/2026): «siamo sicuri che il
 * nostro U-Boot, tracciando una linea retta verso la base, non attraversi la
 * terraferma come se nulla fosse?». Non lo eravamo: la simulazione non sapeva
 * dove fosse la terra, e le coste stavano solo nel disegno della carta. Qui si
 * verifica che adesso:
 *
 *   - la terra della simulazione sia quella della carta, con i canali delle
 *     basi aperti e le loro uscite in mare aperto;
 *   - una rotta fra due punti di mare non tocchi mai terra, e non faccia giri
 *     assurdi;
 *   - la rotta del comandante si adegui (punti aggiunti, punto a terra portato
 *     in mare, punto irraggiungibile tolto);
 *   - alla partenza la rotta d'uscita sia il canale, e che percorrendola si
 *     arrivi al largo senza toccare terra;
 *   - un battello lanciato contro la costa si fermi prima.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Geo;
use App\Sim\Terra;
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

/** Lunghezza di una rotta e se qualche tratto tocca terra. */
function misura(float $la, float $lo, array $punti): array
{
    $l = 0.0;
    $tocca = 0;
    foreach ($punti as $p) {
        [$y, $x] = isset($p['lat']) ? [(float) $p['lat'], (float) $p['lon']] : $p;
        $l += Geo::distanceNm($la, $lo, $y, $x);
        if (Terra::attraversa($la, $lo, $y, $x)) {
            $tocca++;
        }
        [$la, $lo] = [$y, $x];
    }
    return [$l, $tocca];
}

// ==========================================================================
titolo('La terra della simulazione e\' quella della carta');
// ==========================================================================

$terra = [[48.00, -2.50, 'Bretagna interna'], [56.00, 9.00, 'Jutland'], [52.50, -1.50, 'Midlands'],
          [40.00, -4.00, 'Castiglia'], [65.00, -18.00, 'Islanda']];
$mare  = [[47.00, -10.00, 'Biscaglia'], [50.00, -40.00, 'Atlantico'], [50.10, -1.00, 'Manica'],
          [56.00, 3.00, 'Mare del Nord'], [36.00, -5.60, 'Gibilterra, a meta\' stretto']];
foreach ($terra as [$la, $lo, $nome]) {
    ok($nome . ': terra', Terra::aTerra($la, $lo));
}
foreach ($mare as [$la, $lo, $nome]) {
    ok($nome . ': mare', !Terra::aTerra($la, $lo));
}

$canali = Terra::canali();
$basi = array_filter(World::ports(), static fn (array $p): bool => (string) $p['kind'] === 'base');
ok('ogni base ha il suo canale', count($canali) === count($basi)
    && array_diff(array_column($basi, 'port_key'), array_keys($canali)) === [],
    implode(', ', array_keys($canali)));
foreach ($canali as $chiave => $punti) {
    $aTerra = 0;
    foreach ($punti as [$la, $lo]) {
        $aTerra += Terra::aTerra($la, $lo) ? 1 : 0;
    }
    [$ul, $uo] = $punti[count($punti) - 1];
    $libera = true;
    foreach ([0, 45, 90, 135, 180, 225, 270, 315] as $r) {
        [$y, $x] = Geo::destination($ul, $uo, $r, 3.0);
        $libera = $libera && !Terra::aTerra($y, $x);
    }
    [$l, $tocca] = misura($punti[0][0], $punti[0][1], array_slice($punti, 1));
    ok(sprintf('canale di %s: navigabile, e l\'uscita e\' mare aperto', $chiave),
        $aTerra === 0 && $tocca === 0 && $libera, sprintf('%.0f miglia', $l));
}

// ==========================================================================
titolo('Le rotte non toccano terra');
// ==========================================================================

$casi = [
    ['da Lorient al largo di Kiel', 47.48, -3.52, 54.05, 7.90, 1.45],
    ['dal Mare del Nord al largo d\'Irlanda', 56.0, 3.0, 54.0, -15.0, 1.6],
    ['da Bordeaux a Brest, porto a porto', 44.86, -0.55, 48.383, -4.50, 1.7],
    ['dal largo in porto a Trondheim', 64.0, 2.0, 63.44, 10.40, 1.5],
    // In linea d'aria attraversa Francia e Spagna: girando la penisola iberica
    // sono circa milleduecento miglia contro ottocento.
    ['dalla Manica a Gibilterra e oltre', 50.1, -1.0, 36.5, -2.0, 1.65],
];
foreach ($casi as [$nome, $a, $b, $c, $d, $maxAllungo]) {
    $r = Terra::rotta($a, $b, $c, $d);
    [$l, $tocca] = misura($a, $b, $r['punti']);
    $dritto = Geo::distanceNm($a, $b, $c, $d);
    $fine = $r['punti'][count($r['punti']) - 1] ?? null;
    ok($nome, $r['trovata'] && $tocca === 0 && $l <= $dritto * $maxAllungo
        && $fine !== null && Geo::distanceNm($fine[0], $fine[1], $c, $d) < 0.5,
        sprintf('%d punti, %.0f nm contro %.0f in linea d\'aria%s', count($r['punti']), $l, $dritto, $tocca ? ", $tocca tratti a terra" : ''));
}
$kiel = Terra::rotta(54.32, 10.14, 55.0, -5.0);
$perIlCanale = false;
foreach ($kiel['punti'] as [$la, $lo]) {
    $perIlCanale = $perIlCanale || (abs($la - 53.89) < 0.02 && abs($lo - 9.14) < 0.02);
}
ok('da Kiel si esce per il canale, non girando la Danimarca', $perIlCanale, count($kiel['punti']) . ' punti');
$diritta = Terra::rotta(47.0, -10.0, 46.0, -12.0);
ok('in mare aperto la rotta resta una linea retta', count($diritta['punti']) === 1);

// ==========================================================================
titolo('Le rotte dei convogli e delle navi isolate stanno in mare');
// ==========================================================================

// Fino al 30/09/2026 tutte e undici passavano per tratti di terraferma. Si
// controlla ogni mezzo miglio, e per le rotte dei convogli anche due miglia a
// destra e a sinistra: la deviazione li sposta di tanto. Si tollera la terra
// solo nelle sei miglia intorno ai porti, che sulla carta stanno sulla costa.
$datiRotte = require __DIR__ . '/../db/seed/rotte.php';
$diConvoglio = array_unique(array_column($datiRotte['serie'], 'rotta'));
foreach ($datiRotte['rotte'] as $chiave => $r) {
    $p = $r['punti'];
    $a = $p[0];
    $z = $p[count($p) - 1];
    $scarti = in_array($chiave, $diConvoglio, true) ? [0.0, 2.0, -2.0] : [0.0];
    $aTerra = 0.0;
    $dove = null;
    for ($i = 1; $i < count($p); $i++) {
        $d = Geo::distanceNm($p[$i - 1][0], $p[$i - 1][1], $p[$i][0], $p[$i][1]);
        $prora = Geo::bearing($p[$i - 1][0], $p[$i - 1][1], $p[$i][0], $p[$i][1]);
        $n = max(1, (int) ceil($d / 0.5));
        for ($j = 0; $j <= $n; $j++) {
            $la = $p[$i - 1][0] + ($p[$i][0] - $p[$i - 1][0]) * $j / $n;
            $lo = $p[$i - 1][1] + ($p[$i][1] - $p[$i - 1][1]) * $j / $n;
            if (Geo::distanceNm($la, $lo, $a[0], $a[1]) < 6.0 || Geo::distanceNm($la, $lo, $z[0], $z[1]) < 6.0) {
                continue;
            }
            foreach ($scarti as $scarto) {
                [$y, $x] = $scarto !== 0.0
                    ? Geo::destination($la, $lo, Geo::normBearing($prora + ($scarto > 0 ? 90.0 : -90.0)), abs($scarto))
                    : [$la, $lo];
                if (Terra::aTerra($y, $x)) {
                    $aTerra += $d / $n;
                    $dove ??= sprintf('%.2f, %.2f', $la, $lo);
                }
            }
        }
    }
    ok(sprintf('%s (%s) non tocca terra', $chiave, $r['nome']), $aTerra === 0.0,
        $aTerra > 0 ? sprintf('%.0f miglia a terra, la prima in %s', $aTerra, $dove) : '');
}

// ==========================================================================
titolo('La rotta del comandante si adegua');
// ==========================================================================

$barca = ['est_lat' => 47.20, 'est_lon' => -5.50];
$a = Patrol::adegua($barca, [['lat' => 50.20, 'lon' => -1.00]]);      // dalla Biscaglia alla Manica: c'e' la Bretagna
$auto = array_filter($a['punti'], static fn (array $p): bool => $p['auto']);
[$l, $tocca] = misura(47.20, -5.50, $a['punti']);
ok('un tratto che taglia la Bretagna prende i punti per doppiarla', count($auto) > 0 && $tocca === 0,
    sprintf('%d punti automatici, %.0f miglia', count($auto), $l));
ok('e l\'ultimo punto resta quello del comandante', !end($a['punti'])['auto']
    && abs(end($a['punti'])['lat'] - 50.20) < 0.001);
ok('e la centrale lo dice', $a['note'] !== [] && str_contains($a['note'][0], 'terraferma'), $a['note'][0] ?? '');

$b = Patrol::adegua($barca, [['lat' => 48.00, 'lon' => -3.00]]);     // un punto in Bretagna
ok('un punto sulla terraferma si porta nel mare piu\' vicino', count($b['punti']) >= 1
    && !Terra::aTerra(end($b['punti'])['lat'], end($b['punti'])['lon'])
    && (bool) array_filter($b['note'], static fn (string $n): bool => str_contains($n, 'stava sulla terraferma')),
    implode('; ', $b['note']));

$c = Patrol::adegua($barca, [['lat' => 48.85, 'lon' => 2.35], ['lat' => 46.0, 'lon' => -8.0]]);  // Parigi, poi Biscaglia
ok('un punto nell\'entroterra si toglie, e la rotta prosegue col successivo',
    count(array_filter($c['punti'], static fn (array $p): bool => !$p['auto'])) === 1
    && (bool) array_filter($c['note'], static fn (string $n): bool => str_contains($n, 'non si raggiunge')),
    implode('; ', $c['note']));

$d = Patrol::adegua($barca, [['lat' => 50.20, 'lon' => -1.00, 'auto' => false], ['lat' => 49.0, 'lon' => -6.0, 'auto' => true]]);
ok('i punti automatici arrivati dal browser non contano: si ricalcolano', count(array_filter($d['punti'],
    static fn (array $p): bool => !$p['auto'])) === 1);

// ==========================================================================
titolo('Partenza, canale, costa');
// ==========================================================================

$reg = \App\Auth\Auth::register('prova terra ' . substr((string) time(), -5),
    'terra' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) $reg['user_id'];
register_shutdown_function(static fn () => Database::run('DELETE FROM users WHERE id = ?', [$userId]));
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
\App\Game\Comandante::crea($userId, ['nome' => 'Terra Prova ' . substr((string) time(), -4),
    'nato_il' => '1912-11-30', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'kiel']);
$boat = \App\Game\Fleet::ensureBoat($userId);
$id = (int) $boat['id'];
Database::run("UPDATE boats SET home_port_key = 'kiel' WHERE id = ?", [$id]);
$boat = Database::first('SELECT * FROM boats WHERE id = ?', [$id]);

$res = Patrol::depart($boat);
ok('si parte da Kiel', (bool) $res['ok'], $res['error'] ?? '');
$wp = Patrol::route($id);
ok('e la rotta d\'uscita e\' il canale, tracciata dalla centrale', count($wp) === count($canali['kiel']) - 1
    && array_filter($wp, static fn (array $w): bool => !(bool) $w['auto']) === [],
    count($wp) . ' punti automatici');

// Macchine avanti: in un giorno e mezzo si esce per il canale fino al largo.
// Il recupero di un battello non va oltre le 72 ore di gioco: si fa in due.
Database::run('UPDATE boats SET ordered_speed_kn = 12, speed_kn = 12 WHERE id = ?', [$id]);
foreach ([18, 18] as $ore) {
    Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - $ore * 3600, $id]);
    BoatSim::advance($id);
}
// Dove si e' esaurita la rotta, non dove sta adesso: a macchine ferme il
// vento lo scarroccia, e con una burrasca da sud-ovest di parecchie miglia.
[$ul, $uo] = Terra::uscita('kiel');
$fine = Database::first("SELECT lat, lon FROM patrol_events WHERE boat_id = ? AND kind = 'fine_rotta' ORDER BY id LIMIT 1", [$id]);
$aFine = $fine !== null ? Geo::distanceNm((float) $fine['lat'], (float) $fine['lon'], $ul, $uo) : INF;
ok('seguendo il canale si arriva all\'uscita, nella baia di Helgoland', $aFine < 2.0 && Patrol::route($id) === [],
    $fine !== null ? sprintf('rotta esaurita a %.1f miglia dall\'uscita', $aFine) : 'rotta mai esaurita');
$costa = (int) Database::first("SELECT COUNT(*) n FROM patrol_events WHERE boat_id = ? AND kind = 'costa'", [$id])['n'];
ok('senza mai fermarsi contro la costa', $costa === 0, "$costa fermate");
$giornale = (int) Database::first("SELECT COUNT(*) n FROM patrol_events WHERE boat_id = ? AND kind = 'waypoint'", [$id])['n'];
ok('e i punti del canale non riempiono il giornale', $giornale === 0, "$giornale righe");

// Contro la costa: dal largo della Bretagna, prora est a 15 nodi, per un giorno.
Database::run("DELETE FROM boat_waypoints WHERE boat_id = ?", [$id]);
Database::run("UPDATE boats SET lat = 48.00, lon = -5.50, est_lat = 48.10, est_lon = -5.70, heading = 90,
               speed_kn = 15, ordered_speed_kn = 15, mode = 'superficie', depth_m = 0, ordered_depth_m = 0,
               last_sim_gts = ? WHERE id = ?", [World::now() - 24 * 3600, $id]);
BoatSim::advance($id);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
ok('lanciato contro la Bretagna, il battello non ci entra', !Terra::aTerra((float) $b['lat'], (float) $b['lon']),
    sprintf('%.3f, %.3f', (float) $b['lat'], (float) $b['lon']));
ok('si ferma con la costa davanti', (float) $b['ordered_speed_kn'] === 0.0 && (float) $b['lon'] > -5.2,
    sprintf('lon %.3f, macchine %.1f', (float) $b['lon'], (float) $b['ordered_speed_kn']));
// Si partiva con la stima dieci miglia fuori. Dopo la fermata il battello resta
// ore alla deriva e la stima torna ad allontanarsi un poco: e' giusto cosi'.
ok('e la costa in vista vale un punto nave', (float) $b['est_error_nm'] < 3.0,
    sprintf('errore %.2f nm, era di %.1f', (float) $b['est_error_nm'], Geo::distanceNm(48.00, -5.50, 48.10, -5.70)));
$costa = Database::all("SELECT text FROM patrol_events WHERE boat_id = ? AND kind = 'costa'", [$id]);
ok('il giornale lo racconta una volta', count($costa) === 1, count($costa) . ' righe');

// La rotta per la base, da qui.
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
$rb = Patrol::rottaBase($b);
// Dopo ore di deriva la posizione stimata puo' stare dentro la costa: allora la
// rotta comincia dal mare piu' vicino, e la si misura da li'.
$daTerra = Terra::aTerra((float) $b['est_lat'], (float) $b['est_lon']);
$primo = $rb['punti'][0] ?? ['lat' => (float) $b['est_lat'], 'lon' => (float) $b['est_lon']];
[$l, $tocca] = $daTerra
    ? misura((float) $primo['lat'], (float) $primo['lon'], array_slice($rb['punti'], 1))
    : misura((float) $b['est_lat'], (float) $b['est_lon'], $rb['punti']);
ok('dalla stima dentro la costa, la rotta comincia in mare', !$daTerra || !Terra::aTerra((float) $primo['lat'], (float) $primo['lon']),
    $daTerra ? 'stima a terra' : 'stima in mare: niente da verificare');
$ultimo = end($rb['punti']);
ok('la rotta per la base arriva al porto per mare e per il canale', $rb['trovata'] && $tocca === 0
    && Geo::distanceNm($ultimo['lat'], $ultimo['lon'], 54.32, 10.14) < Patrol::RAGGIO_PORTO_NM,
    sprintf('%d punti, %.0f miglia, %d tratti a terra, fine a %.1f miglia dal porto, partenza %.3f %.3f',
        count($rb['punti']), $l, $tocca, $ultimo ? Geo::distanceNm($ultimo['lat'], $ultimo['lon'], 54.32, 10.14) : -1,
        (float) $b['est_lat'], (float) $b['est_lon']));

echo "\n";
if ($falliti === 0) { echo "\033[0;32mTutte le verifiche superate.\033[0m\n"; exit(0); }
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
