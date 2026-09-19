<?php

declare(strict_types=1);

/**
 * Lo stesso mondo per tutti, comunque ci si colleghi.
 *
 *   php tests/test_equita.php
 *
 * Atlantik avanza in modo pigro: il battello sta fermo nel database finche'
 * qualcuno non apre una pagina, e a quel punto la simulazione recupera il tempo
 * passato. E' una scelta buona — non serve un processo che macina in eterno —
 * ma porta con se' una domanda a cui bisogna saper rispondere di si':
 *
 *     due comandanti identici, uno che ricarica la plancia ogni trenta secondi
 *     e uno che torna una volta al giorno, dopo dodici ore di gioco devono
 *     trovarsi nello stesso punto, con la stessa nafta e lo stesso giornale?
 *
 * Il 19/09/2026 la risposta era no, e per tre motivi distinti:
 *
 *   1. si avanzava anche per frazioni di sotto-passo. Con un rapporto 1:30,
 *      dieci secondi reali fanno trecento secondi di gioco: due pagine aperte a
 *      pochi secondi di distanza chiedevano un avanzamento piu' corto del
 *      sotto-passo, il consumo finiva sotto la risoluzione della colonna e si
 *      perdeva. Misurato: centoquindici miglia percorse senza consumare una
 *      goccia di nafta;
 *
 *   2. le colonne dello stato avevano due decimali, e ogni salvataggio ne
 *      buttava via il resto. Chi salvava spesso consumava di piu';
 *
 *   3. la memoria del tempo precedente era una variabile locale, azzerata a
 *      ogni chiamata: la burrasca, che vuole sei sotto-passi di fila sopra
 *      forza 8, non si annotava MAI per chi guardava il ponte.
 *
 * Questa prova tiene ferma la risposta. Tocca il database: crea un battello di
 * prova, lo fa vivere quattro volte dallo stesso stato e se lo porta via.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\BoatSim;
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

// --- un battello di prova, che si porta via alla fine ------------------------
$utente = 'prova equita ' . time();
$reg = \App\Auth\Auth::register($utente, 'eq' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
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

register_shutdown_function(static function () use ($utente): void {
    @shell_exec(sprintf('php %s %s 2>/dev/null',
        escapeshellarg(dirname(__DIR__) . '/bin/_cleanup_test_user.php'), escapeshellarg($utente)));
});

$ORE = 12;
$arrivo = World::now();
$partenza = $arrivo - $ORE * 3600;
$sub = World::substepSeconds();

Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
     SELECT ?, user_id, commander_id, 1, ?, 'in_corso', home_port_key FROM boats WHERE id = ?",
    [$boatId, $partenza, $boatId]
);
$patrolId = Database::lastInsertId();

// Lo stato di partenza non e' solo la riga del battello: l'equipaggio si
// stanca, la stiva si svuota, i settori si scaldano. Se non si rimette a posto
// anche quello, il secondo ritmo parte da un mondo diverso dal primo e la
// prova misura la propria contaminazione invece della simulazione. Ci si e'
// arrivati per davvero: l'esito cambiava a seconda dell'ordine in cui si
// eseguivano i ritmi.
$ciurmaIniziale = Database::all('SELECT id, fatigue, morale, health FROM crew_members WHERE boat_id = ?', [$boatId]);
$stiva = Database::all('SELECT id, qty FROM boat_stores WHERE boat_id = ?', [$boatId]);
$settori = Database::all('SELECT quadrat, heat, updated_gts FROM sectors');

/** Riporta il battello — e il mondo intorno — esattamente allo stato di partenza. */
$reimposta = static function () use ($boatId, $patrolId, $partenza, $ciurmaIniziale, $stiva, $settori): void {
    foreach ($ciurmaIniziale as $m) {
        Database::run('UPDATE crew_members SET fatigue = ?, morale = ?, health = ? WHERE id = ?',
            [$m['fatigue'], $m['morale'], $m['health'], (int) $m['id']]);
    }
    foreach ($stiva as $v) {
        Database::run('UPDATE boat_stores SET qty = ? WHERE id = ?', [$v['qty'], (int) $v['id']]);
    }
    Database::run('DELETE FROM sectors');
    foreach ($settori as $q) {
        Database::run('INSERT INTO sectors (quadrat, heat, updated_gts) VALUES (?, ?, ?)',
            [$q['quadrat'], $q['heat'], $q['updated_gts']]);
    }

    Database::run(
        "UPDATE boats SET state='mare', mode='superficie', lat=47.0, lon=-8.0, est_lat=47.0, est_lon=-8.0,
                est_error_nm=0, heading=270, ordered_heading=270, speed_kn=10, ordered_speed_kn=10,
                depth_m=0, ordered_depth_m=0, fuel_t=100, battery_pct=100, air_pct=100, co2_pct=0,
                provisions_days=60, hull_stress=0, hull_integrity=100, last_sim_gts=?, silent=0,
                submerged_since=NULL, auto_dive_fine_gts=NULL, auto_dive_quota=NULL, last_fix_gts=NULL
         WHERE id = ?",
        [$partenza, $boatId]
    );
    Database::run(
        'UPDATE patrols SET distance_nm=0, surfaced_nm=0, submerged_nm=0, fuel_used_t=0, max_depth_m=0 WHERE id = ?',
        [$patrolId]
    );
    Database::run('DELETE FROM patrol_events WHERE patrol_id = ?', [$patrolId]);
    // Un'avaria uguale per tutti i ritmi: la riparazione e' l'altra grandezza
    // che si accumula passo per passo, e per finire vuole ore. Senza metterla
    // qui, il fatto che finisse a un ritmo e non all'altro si e' visto solo
    // perche' un'avaria e' capitata per caso.
    Database::run("UPDATE boat_systems SET state='ok', repair_progress=0, condition_pct=100 WHERE boat_id = ?", [$boatId]);
    Database::run('DELETE FROM contacts WHERE boat_id = ?', [$boatId]);
    Database::run("UPDATE boat_systems SET state='avaria', repair_progress=0
                   WHERE boat_id = ? AND skey = 'idrofono'", [$boatId]);
    Database::run('UPDATE boat_compartments SET integrity=100, flooding=0, fire=0, sealed=0 WHERE boat_id = ?', [$boatId]);
};

/** Fa vivere al battello le stesse ore, col ritmo di collegamento dato. */
$vivi = static function (int $passo) use ($boatId, $patrolId, $partenza, $arrivo, $reimposta): array {
    $reimposta();
    if ($passo === 0) {
        BoatSim::advance($boatId, $arrivo);
    } else {
        $t = $partenza;
        while ($t < $arrivo) {
            $t = min($arrivo, $t + $passo);
            BoatSim::advance($boatId, $t);
        }
    }
    $b = Database::first('SELECT lat, lon, fuel_t, battery_pct, air_pct, hull_stress, last_sim_gts FROM boats WHERE id = ?', [$boatId]);
    $p = Database::first('SELECT distance_nm FROM patrols WHERE id = ?', [$patrolId]);
    $e = Database::all('SELECT kind, gts FROM patrol_events WHERE patrol_id = ? ORDER BY gts, kind', [$patrolId]);
    $rip = Database::first("SELECT state, repair_progress FROM boat_systems WHERE boat_id = ? AND skey = 'idrofono'", [$boatId]);
    return [
        'idrofono' => (string) ($rip['state'] ?? '—'),
        'lavoro'   => (float) ($rip['repair_progress'] ?? 0),
        'miglia'   => (float) $p['distance_nm'],
        'nafta'    => (float) $b['fuel_t'],
        'batteria' => (float) $b['battery_pct'],
        'stress'   => (float) $b['hull_stress'],
        'lat'      => (float) $b['lat'],
        'lon'      => (float) $b['lon'],
        'fino_a'   => (int) $b['last_sim_gts'],
        'giornale' => implode(' ', array_map(static fn (array $r): string => $r['kind'] . '@' . $r['gts'], $e)),
    ];
};

titolo("Dodici ore di gioco, quattro ritmi di collegamento");

$casi = [
    'un collegamento solo'   => 0,
    'ogni sotto-passo'       => $sub,
    'ogni sotto-passo e due terzi' => (int) round($sub * 1.4),
    'piu' . "' spesso del sotto-passo" => (int) round($sub / 5),
];
// --- il mondo intanto gira ----------------------------------------------------
//
// bin/tick.php passa ogni minuto e fa partire convogli, ne fa arrivare altri,
// raffredda i settori. Se una di quelle cose capita in mezzo ai quattro ritmi,
// il quarto non naviga nello stesso oceano del primo e la prova segnala una
// disparita' che non c'e'. Non si puo' fermare il battito — e non si deve:
// e' il mondo vero. Si guarda se e' cambiato, e in quel caso si ricomincia.
$improntaTraffico = static function (): string {
    $c = Database::first("SELECT COUNT(*) n, COALESCE(SUM(id), 0) s, COALESCE(SUM(deviazione * 100), 0) d
                          FROM convoys WHERE state = 'in_mare'");
    $n = Database::first("SELECT COUNT(*) n, COALESCE(SUM(id), 0) s FROM ships WHERE state = 'in_mare'");
    return implode('|', [$c['n'], $c['s'], $c['d'], $n['n'], $n['s']]);
};

$ris = [];
$tentativi = 0;
do {
    $tentativi++;
    $prima = $improntaTraffico();
    $ris = [];
    foreach ($casi as $nome => $passo) {
        $ris[$nome] = $vivi($passo);
    }
    $stabile = $improntaTraffico() === $prima;
} while (!$stabile && $tentativi < 4);

if (!$stabile) {
    echo "\n  \033[0;33m--\033[0m    il traffico in mare e' cambiato a ogni tentativo: prova non conclusiva\n";
    echo "        (bin/tick.php gira ogni minuto: riprovare, o fermare il battito per la durata della prova)\n\n";
    exit(0);
}
if ($tentativi > 1) {
    printf("  \033[0;90m(ripetuta %d volte: il battito del mondo aveva cambiato le carte in tavola)\033[0m\n", $tentativi);
}

$rif = $ris['un collegamento solo'];

foreach ($ris as $nome => $r) {
    ok("{$nome}: arriva allo stesso istante", $r['fino_a'] === $rif['fino_a'],
        (string) $r['fino_a']);
}

// Le grandezze fisiche: si ammette lo scarto dell'ultima cifra salvata, non di
// piu'. Un metro su centoquindici miglia e' rumore di arrotondamento; un
// decimo di miglio sarebbe un mondo diverso.
foreach (['miglia' => 0.01, 'nafta' => 0.01, 'batteria' => 0.05, 'stress' => 0.05] as $campo => $tolleranza) {
    $peggio = 0.0;
    $chi = '';
    foreach ($ris as $nome => $r) {
        $d = abs($r[$campo] - $rif[$campo]);
        if ($d > $peggio) { $peggio = $d; $chi = $nome; }
    }
    ok("il ritmo non cambia: {$campo}", $peggio <= $tolleranza,
        sprintf('scarto massimo %.5f (%s), tollerato %.2f', $peggio, $chi ?: '—', $tolleranza));
}

$dPos = 0.0;
foreach ($ris as $r) {
    $dPos = max($dPos, \App\Sim\Geo::distanceNm($rif['lat'], $rif['lon'], $r['lat'], $r['lon']));
}
ok('il ritmo non cambia: la posizione', $dPos <= 0.01,
    sprintf('scarto massimo %.5f miglia (%.0f cm)', $dPos, $dPos * 1852 * 100));

// --- il giornale --------------------------------------------------------------
titolo('Lo stesso giornale di guerra');

$uguali = true;
$diverso = '';
foreach ($ris as $nome => $r) {
    if ($r['giornale'] !== $rif['giornale']) {
        $uguali = false;
        $diverso = $nome;
    }
}
ok('le stesse righe, nello stesso ordine, alla stessa ora', $uguali,
    $uguali ? substr_count($rif['giornale'], '@') . ' righe' : "diverge con «{$diverso}»");
if (!$uguali && getenv('ATL_DIFF') !== false) {
    foreach ($ris as $nome => $r) {
        printf("  %-32s %s\n", $nome, $r['giornale']);
    }
    echo "\n";
}

// --- le riparazioni ------------------------------------------------------------
titolo('Lo stesso lavoro in coperta');

$statiRip = [];
$lavori = [];
foreach ($ris as $nome => $r) {
    $statiRip[$r['idrofono']] = true;
    $lavori[] = $r['lavoro'];
}
ok('l\'avaria si chiude (o resta aperta) allo stesso modo per tutti i ritmi',
    count($statiRip) === 1, implode(', ', array_keys($statiRip)));
ok('e le ore-uomo spese sono le stesse',
    max($lavori) - min($lavori) < 0.01,
    sprintf('scarto massimo %.5f ore su %.2f', max($lavori) - min($lavori), max($lavori)));

// --- il caso che ha fatto nascere la prova -------------------------------------
titolo('Il consumo non sparisce nel salvataggio');

$breve = $vivi((int) round($sub / 5));
ok('un battello che naviga consuma nafta anche a spezzoni brevi',
    $breve['nafta'] < 100.0 && $breve['miglia'] > 50.0,
    sprintf('%.2f miglia con %.3f t consumate', $breve['miglia'], 100.0 - $breve['nafta']));

// --- e il motore non avanza per frazioni ---------------------------------------
titolo('Si avanza solo per sotto-passi interi');

$reimposta();
$r1 = BoatSim::advance($boatId, $partenza + (int) ($sub / 2));
ok('mezzo sotto-passo non muove niente', (int) $r1['steps'] === 0,
    'il resto aspetta il giro dopo, invece di perdersi');
$dopo = Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId]);
ok('e non sposta nemmeno l\'orologio del battello', (int) $dopo['last_sim_gts'] === $partenza);

$r2 = BoatSim::advance($boatId, $partenza + $sub * 3);
ok('tre sotto-passi ne fanno tre', (int) $r2['steps'] === 3, $r2['steps'] . ' passi');

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
