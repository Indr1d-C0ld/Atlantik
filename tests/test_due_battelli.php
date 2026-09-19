<?php

declare(strict_types=1);

/**
 * Due giocatori, lo stesso convoglio.
 *
 *   php tests/test_due_battelli.php
 *
 * Tutte le prove di combattimento fatte finora hanno un battello solo in
 * acqua. Ma il gioco e' multigiocatore, la tattica del branco e' il suo
 * cuore, e il caso che conta e' proprio quello: due comandanti addosso allo
 * stesso convoglio, nello stesso momento.
 *
 * Ognuno apre il suo incontro e si materializza la sua copia della formazione
 * — ed e' giusto cosi'. Quello che non era giusto e' il seguito: la stessa
 * nave andava a fondo in tutti e due gli incontri e veniva accreditata a tutti
 * e due i comandanti. Misurato il 19/09/2026: il piroscafo Jonathan Cabot,
 * 4.130 GRT, una sola riga nella tabella delle navi, due righe nel registro
 * degli affondamenti.
 *
 * In un gioco dove il punteggio E' il tonnellaggio, due giocatori d'accordo
 * fra loro raddoppiavano tutto navigando insieme.
 *
 * Tocca il database: crea due utenti di prova e se li porta via alla fine.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Encounter;
use App\Sim\Grid;
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
function saltata(string $titolo, string $perche): void
{
    echo "  \033[0;33m--\033[0m    {$titolo}  \033[0;90m{$perche}\033[0m\n";
}

// --- due giocatori veri -------------------------------------------------------
$utenti = [];
$battelli = [];
$patrol = [];
foreach (['uno', 'due'] as $eti) {
    $nome = 'prova branco ' . $eti . ' ' . time();
    $reg = \App\Auth\Auth::register($nome, 'br' . $eti . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
    $uid = (int) ($reg['user_id'] ?? 0);
    if ($uid === 0) {
        echo "Impossibile iscrivere il giocatore di prova.\n";
        exit(1);
    }
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
    \App\Game\Comandante::crea($uid, [
        'nome' => 'Branco ' . ucfirst($eti) . ' ' . substr((string) time(), -4),
        'nato_il' => '1912-03-03', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient',
    ]);
    $b = \App\Game\Fleet::ensureBoat($uid);
    $utenti[] = $uid;
    $battelli[] = (int) $b['id'];
}
register_shutdown_function(static function () use ($utenti): void {
    foreach ($utenti as $uid) {
        Database::run('DELETE FROM users WHERE id = ?', [$uid]);
    }
});

$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts, $gts]
);
if ($cv === null) {
    saltata('due battelli sullo stesso convoglio', 'nessun convoglio in mare adesso');
    echo "\n\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
$pos = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'],
    $gts, (float) $cv['deviazione']);

titolo('Due battelli, un convoglio');

$enc = [];
foreach ($battelli as $k => $id) {
    Database::run(
        "UPDATE boats SET state = 'mare', lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?",
        [$pos['lat'], $pos['lon'], $pos['lat'], $pos['lon'], $id]
    );
    Database::run(
        "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
         SELECT id, user_id, commander_id, 1, ?, 'in_corso', home_port_key FROM boats WHERE id = ?",
        [$gts - 3600, $id]
    );
    $patrol[$k] = Database::lastInsertId();
    $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
    $r = Encounter::apri($boat, ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
    if ($r['ok'] ?? false) {
        $enc[$k] = (int) $r['encounter_id'];
    }
}

ok('tutti e due aprono il loro incontro sullo stesso convoglio', count($enc) === 2,
    sprintf('%s%s in %s', (string) $cv['serie'], (string) $cv['numero'],
        Grid::toQuadrat($pos['lat'], $pos['lon']) ?? '—'));

if (count($enc) < 2) {
    echo "\n";
    printf("\033[0;31m%d verifiche fallite.\033[0m\n", max(1, $falliti));
    exit($falliti === 0 ? 0 : 1);
}

$navi = [];
foreach ($enc as $k => $e) {
    $navi[$k] = array_column(
        Database::all('SELECT ship_id, name FROM encounter_entities WHERE encounter_id = ? AND ship_id IS NOT NULL', [$e]),
        'name', 'ship_id'
    );
}
$comuni = array_intersect_key($navi[0], $navi[1]);
ok('e si trovano davanti le stesse navi', $comuni !== [], sprintf('%d navi in comune', count($comuni)));

if ($comuni === []) {
    echo "\n\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}

// --- la stessa nave, affondata da tutti e due --------------------------------
titolo('La stessa nave, affondata due volte');

$shipId = (int) array_key_first($comuni);
$prima = (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'];

foreach ($enc as $k => $e) {
    $ent = Database::first('SELECT * FROM encounter_entities WHERE encounter_id = ? AND ship_id = ?', [$e, $shipId]);
    Database::run(
        "UPDATE encounter_entities SET stato = 'affonda', integrita = 0, allagamento = 95,
                affonda_gts = ?, colpita_siluro = 1 WHERE id = ?",
        [$gts, (int) $ent['id']]
    );
    // Si rompe il contatto: l'incontro si chiude e la condannata viene accreditata.
    Database::run('UPDATE boats SET lat = lat + 1.2 WHERE id = ?', [$battelli[$k]]);
    $t0 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$e])['last_step_gts'];
    Encounter::step($e, $t0 + 30);
}

$righe = Database::all('SELECT boat_id, grt FROM sinkings WHERE ship_id = ?', [$shipId]);
ok('la nave finisce nel registro una volta sola', count($righe) === 1,
    sprintf('%s: %d righe', (string) $comuni[$shipId], count($righe)));
ok('e il totale degli affondamenti cresce di uno solo',
    (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'] === $prima + 1);

$conta = [];
foreach ($patrol as $k => $pid) {
    $p = Database::first('SELECT affondate, grt_affondato FROM patrols WHERE id = ?', [$pid]);
    $conta[$k] = [(int) $p['affondate'], (int) $p['grt_affondato']];
}
ok('una sola delle due missioni la conta',
    ($conta[0][0] > 0) !== ($conta[1][0] > 0),
    sprintf('missione A: %d navi / %d GRT — missione B: %d navi / %d GRT',
        $conta[0][0], $conta[0][1], $conta[1][0], $conta[1][1]));

// Il tonnellaggio complessivo delle due missioni dev'essere ESATTAMENTE quello
// della nave: non zero (allora non l'ha contata nessuno) e non il doppio.
$grtNave = (int) $righe[0]['grt'];
$grtTotale = $conta[0][1] + $conta[1][1];
ok('e il tonnellaggio contato e\' quello della nave, non il doppio',
    $grtTotale === $grtNave,
    sprintf('%s GRT contati per una nave da %s GRT',
        number_format($grtTotale, 0, ',', '.'), number_format($grtNave, 0, ',', '.')));

// --- e il database non lo permetterebbe comunque -----------------------------
titolo('La rete sotto');

$dup = false;
try {
    Database::run(
        'INSERT INTO sinkings (boat_id, ship_id, nome, bandiera, class_key, grt, arma, siluri_usati, gts, lat, lon)
         VALUES (?, ?, ?, ?, ?, ?, "siluro", 1, ?, 0, 0)',
        [$battelli[1], $shipId, 'Doppione', 'britannica', 'cargo_medio', 1000, $gts]
    );
} catch (PDOException $e) {
    $dup = $e->getCode() === '23000';
}
ok('il database rifiuta un secondo affondamento della stessa nave', $dup,
    'uq_sinking_nave');

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
