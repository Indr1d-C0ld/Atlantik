<?php

declare(strict_types=1);

/**
 * Atlantik — il giornale di guerra pubblico.
 *
 *   php tests/test_giornale.php
 *
 * Si verifica che:
 *   - si pubblichino solo le missioni di guerra concluse, del proprio comandante;
 *   - la pagina si legga senza accesso, dal server vero, col gettone;
 *   - abbia la testata, la carta con coste, rotta e croci, le righe del
 *     giornale col tempo, gli affondamenti e il giudizio — e niente rumore;
 *   - ritirata, non si legga piu'; e un gettone inventato non apra niente.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\GiornalePubblico;
use App\Sim\BoatSim;
use App\Sim\Grid;
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

$base = rtrim((string) (getenv('BASE_LOCALE') ?: 'http://localhost/atlantik'), '/');
$scarica = static function (string $url): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
    $corpo = (string) @file_get_contents($url, false, $ctx);
    $codice = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
            $codice = (int) $m[1];
        }
    }
    return [$codice, $corpo];
};

$suffisso = substr((string) time(), -6);
$gts = World::now();
$reg = \App\Auth\Auth::register('prova giornale ' . $suffisso, 'giornale' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$uid = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
\App\Game\Comandante::crea($uid, ['nome' => 'Giornale Prova ' . $suffisso, 'nato_il' => '01/02/1912', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($uid, 'VIIC');
$id = (int) $boat['id'];
$cmdId = (int) Database::first('SELECT id FROM commanders WHERE user_id = ?', [$uid])['id'];
register_shutdown_function(static function () use ($uid, $id): void {
    Database::run('DELETE FROM sinkings WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrols WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$uid]);
    Database::run('DELETE FROM users WHERE id = ?', [$uid]);
});

// Una missione di guerra conclusa, scritta a mano: da Lorient al largo e ritorno.
Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, tipo, base_key, departed_gts, returned_gts, state,
                          distance_nm, submerged_nm, siluri_lanciati, affondate, grt_affondato, rapporto)
     VALUES (?, ?, ?, 1, 'guerra', 'lorient', ?, ?, 'conclusa', 1840, 210, 4, 1, 5100, 'Missione ben condotta. Il BdU approva.')",
    [$id, $uid, $cmdId, $gts - 20 * 86400, $gts - 86400]
);
$pid = Database::lastInsertId();
$punti = [[47.5, -3.6], [47.2, -8.0], [48.5, -15.0], [50.0, -20.0], [49.0, -14.0], [47.4, -6.0]];
foreach ($punti as $i => [$la, $lo]) {
    BoatSim::save(['gts' => $gts - (19 - $i * 3) * 86400, 'kind' => 'posizione', 'severity' => 'info', 'lat' => $la, 'lon' => $lo,
        'quadrat' => Grid::toQuadrat($la, $lo), 'text' => 'Rapporto di posizione.'], $pid, $id);
}
BoatSim::save(['gts' => $gts - 10 * 86400, 'kind' => 'affondamento', 'severity' => 'nota', 'lat' => 50.0, 'lon' => -20.0,
    'quadrat' => Grid::toQuadrat(50.0, -20.0), 'text' => 'Affondata la Prova Giornale, 5.100 GRT.'], $pid, $id);
Database::run(
    "INSERT INTO sinkings (boat_id, patrol_id, commander_id, nome, bandiera, class_key, grt, carico, arma, siluri_usati, gts, lat, lon, quadrat)
     VALUES (?, ?, ?, 'Prova Giornale', 'britannica', 'cargo_medio', 5100, 'grano', 'siluro', 2, ?, 50.0, -20.0, ?)",
    [$id, $pid, $cmdId, $gts - 10 * 86400, Grid::toQuadrat(50.0, -20.0)]
);

// ==========================================================================
titolo('Che cosa si pubblica');
// ==========================================================================

Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, tipo, base_key, departed_gts, state) VALUES (?, ?, ?, 2, 'guerra', 'lorient', ?, 'in_corso')",
    [$id, $uid, $cmdId, $gts]);
$inCorso = Database::lastInsertId();
ok('una missione in corso no: direbbe dove si e\'', !GiornalePubblico::pubblica($uid, $inCorso)['ok']);
Database::run("UPDATE patrols SET tipo = 'addestramento', state = 'conclusa' WHERE id = ?", [$inCorso]);
ok('l\'addestramento no', !GiornalePubblico::pubblica($uid, $inCorso)['ok']);
ok('quella di un altro no', !GiornalePubblico::pubblica($uid + 1, $pid)['ok']);
$r = GiornalePubblico::pubblica($uid, $pid);
ok('una missione di guerra conclusa si', $r['ok'] && preg_match('/^[0-9a-f]{24}$/', (string) $r['token']) === 1);
ok('e ripubblicarla da\' lo stesso collegamento', GiornalePubblico::pubblica($uid, $pid)['token'] === $r['token']);
$token = (string) $r['token'];

// ==========================================================================
titolo('La pagina, dal server');
// ==========================================================================

[$codice, $html] = $scarica($base . '/giornale/' . $token);
ok('si legge senza accesso', $codice === 200, 'HTTP ' . $codice);
ok('la testata: Kriegstagebuch col numero del battello e il comandante',
    str_contains($html, 'Kriegstagebuch ' . (string) $boat['uboat_number']) && str_contains($html, 'Giornale Prova ' . $suffisso));
ok('la carta: coste, rotta e una croce', str_contains($html, '<svg class="ktb-carta"') && substr_count($html, '<polygon') > 3
    && str_contains($html, 'ktb-carta-rotta') && substr_count($html, 'M') > 0 && str_contains($html, 'ktb-carta-croci"><path'),
    substr_count($html, '<polygon') . ' coste');
ok('le righe del giornale, con vento e mare', str_contains($html, 'Affondata la Prova Giornale') && preg_match('/mare \d/', $html) === 1);
ok('ma non il rumore di bordo', !str_contains($html, 'Rapporto di posizione.'));
ok('gli affondamenti e il giudizio del BdU', str_contains($html, 'Naviglio affondato') && str_contains($html, 'Il BdU approva'));

GiornalePubblico::ritira($uid, $pid);
[$codice2] = $scarica($base . '/giornale/' . $token);
ok('ritirata, non si legge piu\'', $codice2 === 404, 'HTTP ' . $codice2);
[$codice3] = $scarica($base . '/giornale/' . str_repeat('a', 24));
ok('un gettone inventato non apre niente', $codice3 === 404, 'HTTP ' . $codice3);
[$codice4] = $scarica($base . '/giornale/..%2F..%2Fetc');
ok('e uno storto nemmeno', $codice4 === 404, 'HTTP ' . $codice4);

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
