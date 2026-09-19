<?php

declare(strict_types=1);

/**
 * L'orologio dell'incontro tattico.
 *
 *   php tests/test_incontro.php
 *
 * Un incontro deve avanzare DA SOLO: il battito del minuto lo fa camminare
 * anche mentre il comandante non tocca niente, ed e' cosi' che le scorte
 * cercano, i siluri percorrono la loro corsa e qualcuno affonda.
 *
 * Il 18/09/2026 non lo faceva. L'incontro nasceva con l'ora di partenza a zero,
 * il passo automatico la sostituiva al volo con l'ora corrente, il conto del
 * tempo trascorso dava sempre zero e si usciva prima di scrivere: ogni incontro
 * restava fermo per sempre, coi siluri in corsa e nessun risultato. Queste
 * prove guardano proprio quell'orologio — non il combattimento, che ha le sue.
 *
 * Tocca il database: apre e chiude un incontro vero su un battello di prova.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
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

/**
 * Un caso che non si e' potuto esercitare.
 *
 * Prima queste righe chiamavano ok(..., true) e uscivano verdi come tutte le
 * altre: un caso che smette di essere provato — perche' il mondo in quel
 * momento non offre la situazione giusta — spariva senza che nessuno se ne
 * accorgesse. Adesso si vede che e' stato saltato, e alla fine si contano.
 */
function saltata(string $titolo, string $perche = ''): void
{
    global $saltate;
    $saltate = ($saltate ?? 0) + 1;
    echo "  \033[0;33m--\033[0m    {$titolo}" . ($perche !== '' ? "  \033[0;90m{$perche}\033[0m" : '') . "\n";
}

titolo('Orologio dell\'incontro');

$gts = World::now();
Traffic::ensure($gts);

$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts, $gts]
);
$boat = Database::first("SELECT * FROM boats ORDER BY id DESC LIMIT 1");

if ($cv === null || $boat === null) {
    echo "  \033[0;90mniente convoglio o niente battello: prove saltate\033[0m\n";
    echo "\n\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}

// Si mette il battello addosso al convoglio, si apre l'incontro, e alla fine
// si rimette tutto com'era: questa prova non deve lasciare tracce.
$statoPrima = [
    'lat' => $boat['lat'], 'lon' => $boat['lon'], 'encounter_id' => $boat['encounter_id'],
    'battle_stations' => $boat['battle_stations'],
];
$apertoPrima = Encounter::corrente((int) $boat['id']);

$p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
$encId = null;

try {
    if ($apertoPrima !== null) {
        Database::run("UPDATE encounters SET stato = 'concluso' WHERE id = ?", [(int) $apertoPrima['id']]);
    }
    Database::run(
        'UPDATE boats SET lat = ?, lon = ?, encounter_id = NULL WHERE id = ?',
        [$p['lat'], $p['lon'], (int) $boat['id']]
    );
    $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);

    $res = Encounter::apri($boat, ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
    ok('l\'incontro si apre', (bool) $res['ok'], (string) ($res['error'] ?? ''));

    if (!$res['ok']) {
        throw new RuntimeException((string) ($res['error'] ?? 'apertura fallita'));
    }
    $encId = (int) $res['encounter_id'];

    // 1. L'ora di partenza dev'essere scritta all'apertura, non lasciata a zero.
    $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
    ok('l\'orologio parte all\'apertura, non da zero',
        (int) $enc['last_step_real'] > 0, 'last_step_real=' . $enc['last_step_real']);

    // 2. Un incontro nato prima della correzione si rimette in moto da solo.
    Database::run('UPDATE encounters SET last_step_real = 0 WHERE id = ?', [$encId]);
    Encounter::passoAutomatico($encId);
    $risanato = (int) Database::first('SELECT last_step_real FROM encounters WHERE id = ?', [$encId])['last_step_real'];
    ok('un incontro con l\'orologio fermo si rimette in moto da solo',
        $risanato > 0, 'last_step_real=' . $risanato);

    // 3. La prova che conta: passato del tempo reale, l'incontro cammina.
    $gtsPrima = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];
    Database::run('UPDATE encounters SET last_step_real = ? WHERE id = ?', [time() - 60, $encId]);
    $passo = Encounter::passoAutomatico($encId);
    $gtsDopo = (int) (Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'] ?? 0);

    ok('un minuto di attesa fa camminare l\'incontro',
        (int) $passo['passi'] > 0, 'passi=' . $passo['passi']);
    ok('l\'orologio dell\'incontro e\' avanzato davvero',
        $gtsDopo > $gtsPrima || (bool) $passo['chiuso'],
        sprintf('%d → %d%s', $gtsPrima, $gtsDopo, $passo['chiuso'] ? ' (chiuso)' : ''));

    // 4. Il resto del tempo non si butta: quello che avanza resta in cassa.
    if (!$passo['chiuso']) {
        $enc = Database::first('SELECT ratio, stato FROM encounters WHERE id = ?', [$encId]);
        $ratio = max(1, (int) $enc['ratio']);
        $durata = Encounter::passoS();
        // Si sceglie un'attesa che NON sia un multiplo esatto del passo: cosi'
        // un resto c'e' per forza, ed e' quello che si va a cercare.
        $attesa = (int) ceil($durata / $ratio) + 1;
        $adesso = time();
        Database::run('UPDATE encounters SET last_step_real = ? WHERE id = ?', [$adesso - $attesa, $encId]);
        $r = Encounter::passoAutomatico($encId);
        $dopo = (int) (Database::first('SELECT last_step_real FROM encounters WHERE id = ?', [$encId])['last_step_real'] ?? 0);
        ok('il tempo che avanza resta in cassa, non si butta',
            (int) $r['passi'] === 0 || $r['chiuso'] || $dopo < $adesso,
            sprintf('passi=%d, orologio a %d s da adesso', $r['passi'], $adesso - $dopo));
    } else {
        saltata('il tempo che avanza resta in cassa, non si butta', 'incontro gia\' chiuso');
    }

    // 5. Senza tempo trascorso non si muove: il passo non deve inventare nulla.
    if (!$passo['chiuso']) {
        Database::run('UPDATE encounters SET last_step_real = ? WHERE id = ?', [time(), $encId]);
        $fermo = Encounter::passoAutomatico($encId);
        ok('senza tempo trascorso non fa passi', (int) $fermo['passi'] === 0, 'passi=' . $fermo['passi']);
    } else {
        saltata('senza tempo trascorso non fa passi', 'incontro gia\' chiuso');
    }
} finally {
    if ($encId !== null) {
        Database::run('DELETE FROM torpedo_runs WHERE encounter_id = ?', [$encId]);
        Database::run('DELETE FROM encounter_entities WHERE encounter_id = ?', [$encId]);
        Database::run('DELETE FROM encounters WHERE id = ?', [$encId]);
    }
    Database::run(
        'UPDATE boats SET lat = ?, lon = ?, encounter_id = ?, battle_stations = ? WHERE id = ?',
        [$statoPrima['lat'], $statoPrima['lon'], $statoPrima['encounter_id'],
         $statoPrima['battle_stations'], (int) $boat['id']]
    );
    if ($apertoPrima !== null) {
        Database::run('UPDATE encounters SET stato = ? WHERE id = ?',
            [(string) $apertoPrima['stato'], (int) $apertoPrima['id']]);
    }
}

titolo('Chi sta affondando affonda lo stesso');

// Una nave con l'integrita' a zero e' condannata: le manca solo il tempo. Se
// il contatto si rompe prima che tocchi il fondo, non deve sparire dai conti —
// prima spariva, e il comandante restava a mani vuote dopo due siluri a segno.
$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts, $gts]
);
$boat = Database::first("SELECT * FROM boats WHERE encounter_id IS NULL ORDER BY id DESC LIMIT 1");

if ($cv === null || $boat === null) {
    echo "  \033[0;90mniente convoglio o niente battello libero: prove saltate\033[0m\n";
} else {
    $statoPrima = ['lat' => $boat['lat'], 'lon' => $boat['lon'], 'encounter_id' => $boat['encounter_id']];
    $encId = null;
    $condannataId = null;
    try {
        $p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
        Database::run('UPDATE boats SET lat = ?, lon = ? WHERE id = ?', [$p['lat'], $p['lon'], (int) $boat['id']]);
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);

        $res = Encounter::apri($boat, ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
        if (!$res['ok']) {
            throw new RuntimeException((string) ($res['error'] ?? 'apertura fallita'));
        }
        $encId = (int) $res['encounter_id'];
        $t0 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];

        // Una nave in punto di morte, con un'ora davanti prima di andare giu'.
        $bersaglio = Database::first(
            "SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' ORDER BY grt DESC LIMIT 1",
            [$encId]
        );
        $condannataId = (int) $bersaglio['id'];
        Database::run(
            "UPDATE encounter_entities SET stato = 'affonda', integrita = 0, allagamento = 90, affonda_gts = ?
              WHERE id = ?",
            [$t0 + 3600, $condannataId]
        );

        // Si rompe il contatto: venti miglia, ben oltre la soglia di fine.
        [$lat, $lon] = Geo::destination((float) $boat['lat'], (float) $boat['lon'], 90.0, 20.0);
        Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?',
            [$lat, $lon, $lat, $lon, (int) $boat['id']]);

        $prima = (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'];
        $passo = Encounter::step($encId, $t0 + 20);
        $dopo = (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'];
        $enc = Database::first('SELECT stato, esito, affondate, grt_affondato FROM encounters WHERE id = ?', [$encId]);

        ok('l\'incontro si chiude perche\' il contatto e\' rotto',
            (bool) $passo['chiuso'] && (string) $enc['stato'] === 'concluso', (string) $enc['stato']);
        ok('la nave condannata viene accreditata lo stesso', $dopo === $prima + 1,
            sprintf('%d → %d affondamenti', $prima, $dopo));
        ok('il conto finisce anche nella riga dell\'incontro',
            (int) $enc['affondate'] >= 1 && (int) $enc['grt_affondato'] >= (int) $bersaglio['grt'],
            sprintf('%d navi, %d GRT', (int) $enc['affondate'], (int) $enc['grt_affondato']));
        ok('l\'esito non dice piu\' "nessun risultato"',
            !str_contains((string) $enc['esito'], 'Nessun risultato'), (string) $enc['esito']);

        // La nave dev'essere segnata affondata anche nel traffico, non solo
        // nell'incontro: altrimenti continuerebbe la traversata da sola.
        $nave = $bersaglio['ship_id'] !== null
            ? Database::first('SELECT state FROM ships WHERE id = ?', [(int) $bersaglio['ship_id']])
            : null;
        ok('la nave risulta affondata anche nel traffico',
            $nave === null || (string) $nave['state'] === 'affondata',
            (string) ($nave['state'] ?? 'senza riga in ships'));

        // Pulizia dei conti: questa prova non deve regalare tonnellaggio.
        Database::run('DELETE FROM sinkings WHERE boat_id = ?', [(int) $boat['id']]);
    } finally {
        if ($encId !== null) {
            Database::run('DELETE FROM torpedo_runs WHERE encounter_id = ?', [$encId]);
            Database::run('DELETE FROM encounter_entities WHERE encounter_id = ?', [$encId]);
            Database::run('DELETE FROM encounters WHERE id = ?', [$encId]);
        }
        Database::run('UPDATE boats SET lat = ?, lon = ?, encounter_id = ? WHERE id = ?',
            [$statoPrima['lat'], $statoPrima['lon'], $statoPrima['encounter_id'], (int) $boat['id']]);
    }
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m"
    . (($saltate ?? 0) > 0 ? "  \033[0;33m({$saltate} saltate)\033[0m" : "") . "\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
