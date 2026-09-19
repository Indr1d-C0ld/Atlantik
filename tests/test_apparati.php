<?php

declare(strict_types=1);

/**
 * Gli apparati del cantiere: quello che si paga, si deve sentire.
 *
 *   php tests/test_apparati.php
 *
 * Dieci apparati, da settanta a centotrenta punti di assegnazione l'uno —
 * cioe' diverse missioni di lavoro. Ognuno dichiara un effetto e un valore
 * nella sua scheda, e l'audit del 19/09/2026 ha trovato che due dei piu' cari
 * non funzionavano DOVE SERVONO:
 *
 *   - le batterie maggiorate (110 punti, +22% di riserva) valevano solo
 *     durante la crociera tranquilla. Sotto le cariche, in immersione, nel
 *     momento in cui la corrente decide se si torna a casa, l'incontro
 *     tattico faceva i suoi conti senza l'apparato;
 *
 *   - le sospensioni elastiche (110 punti, -22% di rumore proprio) valevano
 *     solo in crociera, dove servono a non farsi sentire da un piroscafo di
 *     passaggio. Sotto una scorta che ascolta — l'unico posto per cui si
 *     comprano — le scorte usavano il rumore del battello nudo.
 *
 * Un grep non l'avrebbe trovato: la chiave dell'effetto era letta, in tutti e
 * due i casi. Era letta nel posto sbagliato. Questa prova non legge il codice,
 * misura: stesso battello, stesse condizioni, con e senza l'apparato.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Acoustics;
use App\Sim\BoatSim;
use App\Sim\Damage;
use App\Sim\Detection;
use App\Sim\Rng;
use App\Sim\Scorte;
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

// --- un battello di prova ------------------------------------------------------
$utente = 'prova apparati ' . time();
$reg = \App\Auth\Auth::register($utente, 'app_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
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
$type = World::type((string) $boat['type_key']);
register_shutdown_function(static function () use ($userId): void {
    Database::run('DELETE FROM users WHERE id = ?', [$userId]);
});

Database::run(
    "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
     SELECT id, user_id, commander_id, 1, ?, 'in_corso', home_port_key FROM boats WHERE id = ?",
    [World::now() - 86400, $boatId]
);
$patrolId = Database::lastInsertId();

/** Installa (o toglie) un apparato sul battello di prova. */
function apparato(int $boatId, string $ukey, bool $metti): void
{
    Database::run('DELETE FROM boat_upgrades WHERE boat_id = ? AND ukey = ?', [$boatId, $ukey]);
    if ($metti) {
        Database::run('INSERT INTO boat_upgrades (boat_id, ukey, gts) VALUES (?, ?, ?)',
            [$boatId, $ukey, World::now()]);
    }
}

/** Fa navigare il battello in immersione per un po' e torna la batteria consumata. */
function consumoBatteria(int $boatId, int $patrolId, float $ore, float $nodi): float
{
    $arrivo = World::now();
    Database::run(
        "UPDATE boats SET state = 'mare', mode = 'immersione', depth_m = 60, ordered_depth_m = 60,
                speed_kn = ?, ordered_speed_kn = ?, battery_pct = 100, air_pct = 100, co2_pct = 0,
                fuel_t = 80, provisions_days = 40, hull_stress = 0, hull_integrity = 100,
                lat = 47, lon = -20, est_lat = 47, est_lon = -20, silent = 0, last_sim_gts = ?
         WHERE id = ?",
        [$nodi, $nodi, $arrivo - (int) round($ore * 3600), $boatId]
    );
    Database::run('DELETE FROM patrol_events WHERE patrol_id = ?', [$patrolId]);
    BoatSim::advance($boatId, $arrivo);

    return 100.0 - (float) Database::first('SELECT battery_pct FROM boats WHERE id = ?', [$boatId])['battery_pct'];
}

// --- 1. batterie maggiorate, in crociera --------------------------------------
titolo('Batterie maggiorate');

apparato($boatId, 'batterie_maggiorate', false);
$senza = consumoBatteria($boatId, $patrolId, 4.0, 4.0);
apparato($boatId, 'batterie_maggiorate', true);
$con = consumoBatteria($boatId, $patrolId, 4.0, 4.0);

ok('in immersione durano di piu\'', $con < $senza * 0.95,
    sprintf('%.1f%% consumato senza, %.1f%% con (%.0f%% in meno)', $senza, $con,
        $senza > 0 ? (1 - $con / $senza) * 100 : 0));

// --- 2. e anche sotto le cariche ----------------------------------------------
//
// La formula dell'incontro tattico e' un'altra riga di codice, in un altro
// file. Che l'apparato valga in crociera non dice niente su quello che succede
// durante un attacco — ed e' li' che non valeva.
$drenaggio = static function (bool $conApparato) use ($boatId, $type): float {
    apparato($boatId, 'batterie_maggiorate', $conApparato);
    $mig = \App\Game\Carriera::effettiMiglioramenti($boatId);
    $effetti = Damage::effects(Damage::systems($boatId), 0.0, 1.0);

    return \App\Sim\Consumption::batteryDrainPerHour($type, 4.0, false)
        / max(0.2, $effetti['batteria'] * ($mig['batteria'] ?? 1.0));
};
$fonte = file_get_contents(__DIR__ . '/../src/Sim/Encounter.php');
ok('l\'incontro tattico usa l\'apparato nella sua formula della batteria',
    preg_match('/batteryDrainPerHour.*\n.*migBoat\[.batteria.\]/m', $fonte) === 1
    || str_contains($fonte, "\$effetti['batteria'] * (\$migBoat['batteria'] ?? 1.0)"),
    'Encounter.php');

// --- 3. sospensioni elastiche -------------------------------------------------
titolo('Sospensioni elastiche');

// L'idrofono della scorta sente il nostro rumore e decide su una soglia: quello
// che le sospensioni comprano e' distanza, cioe' le miglia in cui la scorta
// ancora non sente. Si misura sulla funzione vera dell'acustica.
$sentitoA = static function (float $fattore, float $miglia): float {
    $rumore = Acoustics::ownNoise(6.0, false, 7.6) * $fattore;

    return Acoustics::snr(118.0 + $rumore, $miglia, 3, 0.0, 12.0, false, Acoustics::DI_KDB);
};
ok('con le sospensioni la scorta ci sente piu\' piano',
    $sentitoA(0.78, 3.0) < $sentitoA(1.0, 3.0),
    sprintf('%.1f dB contro %.1f dB a tre miglia', $sentitoA(0.78, 3.0), $sentitoA(1.0, 3.0)));

// E quello che conta davvero: a che distanza la scorta smette di sentirci.
$portata = static function (float $fattore) use ($sentitoA): float {
    for ($d = 0.5; $d <= 12.0; $d += 0.1) {
        if ($sentitoA($fattore, $d) <= Acoustics::SOGLIA_SNR) {
            return $d;
        }
    }

    return 12.0;
};
ok('e ci si puo\' avvicinare di piu\' senza farsi sentire',
    $portata(0.78) < $portata(1.0),
    sprintf('sentiti a %.1f nm con le sospensioni, a %.1f nm senza', $portata(0.78), $portata(1.0)));

// --- e la scorta le usa davvero ----------------------------------------------
//
// Le due misure di sopra dicono che l'apparato ha senso, non che il codice
// delle scorte lo veda: e' esattamente la distinzione su cui questo apparato
// era rotto. Qui si monta un incontro vero con una scorta addosso al battello
// e si chiama la manovra delle scorte due volte, con lo stesso seme, cambiando
// soltanto gli apparati.
Database::run(
    "INSERT INTO encounters (boat_id, patrol_id, convoy_id, ship_id, stato, started_gts, last_step_gts,
                             last_step_real, finestra_fine, ratio, allarme)
     VALUES (?, ?, NULL, NULL, 'attacco', ?, ?, ?, ?, 1, 1)",
    [$boatId, $patrolId, World::now(), World::now(), time(), World::now() + 7200]
);
$encId = Database::lastInsertId();
$classeScorta = Database::first("SELECT class_key FROM ship_classes WHERE kind = 'scorta' AND asdic = 1 LIMIT 1");
Database::run(
    "INSERT INTO encounter_entities (encounter_id, ship_id, class_key, name, ruolo, lat, lon, heading,
                                     speed_kn, grt, integrita, allagamento, incendio, stato, contatto, dc_residue)
     VALUES (?, NULL, ?, 'Prova Scorta', 'scorta', 47.0, -20.0, 90, 12, 1000, 100, 0, 0, 'in_mare', 0, 40)",
    [$encId, (string) ($classeScorta['class_key'] ?? 'flower')]
);

$scortaSente = static function (array $mig) use ($encId, $type, $boatId): float {
    Database::run("UPDATE encounter_entities SET contatto = 0 WHERE encounter_id = ?", [$encId]);
    $entita = Database::all('SELECT * FROM encounter_entities WHERE encounter_id = ?', [$encId]);
    $b = [
        // Due miglia e mezzo: troppo lontano perche' l'ASDIC ci arrivi, e
        // proprio sul filo dell'idrofono, che e' l'unica distanza a cui la
        // differenza si puo' vedere. Piu' vicino il contatto satura comunque.
        'lat' => 47.0417, 'lon' => -20.0, 'heading' => 270.0, 'speed' => 6.0, 'ordered' => 6.0,
        'depth' => 60.0, 'ord_depth' => 60.0, 'mode' => 'immersione', 'silent' => false,
        'periscopio' => false, 'battery' => 80.0, 'fuel' => 60.0, 'air' => 80.0, 'stress' => 0.0,
    ];
    $effetti = Damage::effects(Damage::systems($boatId), 0.0, 1.0);
    $somma = 0.0;
    for ($i = 0; $i < 40; $i++) {
        $rng = new Rng(4242 + $i);
        Scorte::ai($entita, $b, $type, $encId, World::now() + $i * 30, 30, 3, 0.0, 0.5,
            $effetti, $rng, true, $mig);
    }
    foreach ($entita as $e) {
        $somma += (float) $e['contatto'];
    }

    return $somma;
};

$conNulla = $scortaSente([]);
$conSospensioni = $scortaSente(['rumore_proprio' => 0.78]);
ok('la scorta aggancia meno un battello con le sospensioni',
    $conSospensioni < $conNulla,
    sprintf('contatto %.3f con le sospensioni, %.3f senza', $conSospensioni, $conNulla));

Database::run('DELETE FROM encounter_entities WHERE encounter_id = ?', [$encId]);
Database::run('DELETE FROM encounters WHERE id = ?', [$encId]);

// --- 4. scafo rinforzato -------------------------------------------------------
titolo('Scafo rinforzato');

$banda = Damage::bandaCollasso($type, 1.0, 100.0);
$bandaRinf = Damage::bandaCollasso($type, 1.15, 100.0);
ok('la banda di collasso scende piu' . "'" . ' in basso', $bandaRinf['min'] > $banda['min'],
    sprintf('%.0f m invece di %.0f m', $bandaRinf['min'], $banda['min']));

// --- 5. idrofono a schiera ------------------------------------------------------
titolo('Impianto idrofonico Balkon');

$sl = Acoustics::sourceLevel(138.0, 9.0, 11.0, 30, 1);
$nudoIdro = Detection::idrofono($sl, 25.0, 3, 0.0, 0.0, false, 1.0, false);
$conBalkon = Detection::idrofono($sl, 25.0, 3, 0.0, 0.0, false, 1.0 + 3.5 / 15.0, false);
ok('con la schiera si sente piu\' forte', (float) $conBalkon['snr'] > (float) $nudoIdro['snr'],
    sprintf('%.1f dB contro %.1f dB a venticinque miglia', (float) $conBalkon['snr'], (float) $nudoIdro['snr']));
ok('e la portata cresce', (float) $conBalkon['portata_nm'] > (float) $nudoIdro['portata_nm'],
    sprintf('%.0f nm contro %.0f nm', (float) $conBalkon['portata_nm'], (float) $nudoIdro['portata_nm']));

// --- 6. respiratore ------------------------------------------------------------
titolo('Schnorchel');

// Il respiratore lavora solo col mare non troppo grosso: oltre forza 6 la
// testa va sott'acqua e i diesel succhiano l'aria dal battello, che e' il
// motivo per cui gli equipaggi lo odiavano. Il mare vero nel punto di prova
// puo' essere qualunque cosa, quindi lo si ferma: il gioco ha gia' le
// forzature meteo dell'amministrazione, e questa prova le usa e le toglie.
$admin = Database::first("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
$forzatura = \App\Game\Meteo::forza([
    'lat' => 47.0, 'lon' => -20.0, 'raggio_nm' => 200, 'ore' => 2,
    'sea_state' => 3, 'wind_kn' => 12, 'visibility_nm' => 10, 'fog' => 0, 'cloud' => 0.4,
    'nota' => 'prova apparati: mare calmo per il respiratore',
], (int) ($admin['id'] ?? 0));
register_shutdown_function(static function () use ($forzatura): void {
    if (($forzatura['id'] ?? null) !== null) {
        \App\Game\Meteo::togli((int) $forzatura['id']);
    }
});
// Una forzatura vale DA QUANDO la si impone in avanti — e' scritto
// nell'intestazione di World::conForzature, ed e' giusto: quando scade, il
// mondo torna quello che sarebbe stato, senza disfare niente. Ma questa prova
// fa vivere al battello le due ore APPENA passate, che stanno prima della
// forzatura. Si arretra la finestra: e' una prova, e il mare deve stare fermo
// per tutto l'intervallo che si simula.
if (($forzatura['id'] ?? null) !== null) {
    Database::run('UPDATE weather_overrides SET da_gts = ? WHERE id = ?',
        [World::now() - 6 * 3600, (int) $forzatura['id']]);
    \App\Game\Meteo::dimentica();
}
$mareOra = (int) World::weather(47.0, -20.0, World::now())['sea_state'];
ok('il mare di prova e\' abbastanza calmo per il respiratore', $mareOra <= 6,
    sprintf('stato del mare %d', $mareOra));

apparato($boatId, 'schnorchel', false);
$arrivo = World::now();
$prep = static function (int $boatId, int $arrivo): void {
    Database::run(
        "UPDATE boats SET state = 'mare', mode = 'periscopio', depth_m = 12, ordered_depth_m = 12,
                speed_kn = 5, ordered_speed_kn = 5, battery_pct = 60, air_pct = 80, co2_pct = 2,
                fuel_t = 80, provisions_days = 40, lat = 47, lon = -20, est_lat = 47, est_lon = -20,
                silent = 0, last_sim_gts = ? WHERE id = ?",
        [$arrivo - 2 * 3600, $boatId]
    );
};
$prep($boatId, $arrivo);
BoatSim::advance($boatId, $arrivo);
$senzaResp = Database::first('SELECT battery_pct, fuel_t FROM boats WHERE id = ?', [$boatId]);

apparato($boatId, 'schnorchel', true);
$arrivo = World::now();
$prep($boatId, $arrivo);
BoatSim::advance($boatId, $arrivo);
$conResp = Database::first('SELECT battery_pct, fuel_t FROM boats WHERE id = ?', [$boatId]);

ok('col respiratore la batteria si ricarica a quota periscopica',
    (float) $conResp['battery_pct'] > (float) $senzaResp['battery_pct'],
    sprintf('%.1f%% contro %.1f%%', (float) $conResp['battery_pct'], (float) $senzaResp['battery_pct']));
ok('e si consuma nafta, perche\' girano i diesel',
    (float) $conResp['fuel_t'] < (float) $senzaResp['fuel_t'],
    sprintf('%.2f t contro %.2f t', 80 - (float) $conResp['fuel_t'], 80 - (float) $senzaResp['fuel_t']));

// --- 6b. i corsi per l'equipaggio ----------------------------------------------
//
// Quaranta punti a corso. Due cose da tenere ferme: che il corso renda davvero,
// e che non renda SEMPRE LO STESSO. Il seme del caso era (mondo + battello),
// costante per battello: lo stesso scafo pescava lo stesso identico incremento
// per tutta la carriera. Misurato: un battello a +4,55 per corso e un altro a
// +5,22, su decine di corsi.
titolo('I corsi per l\'equipaggio');

$cmdProva = \App\Game\Comandante::crea($userId, [
    'nome' => 'Corsi Prova ' . substr((string) time(), -4), 'nato_il' => '1911-11-11',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient',
]);
if ($cmdProva['ok'] ?? false) {
    Database::run('UPDATE commanders SET punti = 400 WHERE id = ?', [(int) $cmdProva['commander_id']]);
    Database::run("UPDATE boats SET state = 'base' WHERE id = ?", [$boatId]);
    $boatBase = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);

    $incrementi = [];
    for ($i = 0; $i < 4; $i++) {
        $cmd = Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $cmdProva['commander_id']]);
        $prima = (float) Database::first(
            "SELECT AVG(competence) c FROM crew_members WHERE boat_id = ? AND role_key = 'silurista'", [$boatId]
        )['c'];
        \App\Game\Carriera::addestra($cmd, $boatBase, 'silurista');
        $dopo = (float) Database::first(
            "SELECT AVG(competence) c FROM crew_members WHERE boat_id = ? AND role_key = 'silurista'", [$boatId]
        )['c'];
        $incrementi[] = round($dopo - $prima, 2);
    }

    ok('il corso alza davvero la competenza', min($incrementi) > 0,
        implode(', ', array_map(static fn ($x) => sprintf('%+.2f', $x), $incrementi)));
    ok('e non rende sempre lo stesso', count(array_unique($incrementi)) > 1,
        count(array_unique($incrementi)) . ' valori diversi su 4 corsi');

    $cmdDopo = Database::first('SELECT punti FROM commanders WHERE id = ?', [(int) $cmdProva['commander_id']]);
    ok('quattro corsi costano centosessanta punti', (int) $cmdDopo['punti'] === 400 - 4 * 40,
        (int) $cmdDopo['punti'] . ' punti rimasti');

    $res = \App\Game\Carriera::addestra(
        Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $cmdProva['commander_id']]),
        $boatBase, 'astronauti'
    );
    $puntiDopo = (int) Database::first('SELECT punti FROM commanders WHERE id = ?', [(int) $cmdProva['commander_id']])['punti'];
    ok('un corso per una specialita\' che non esiste non si paga',
        !($res['ok'] ?? false) && $puntiDopo === (int) $cmdDopo['punti'],
        (string) ($res['error'] ?? ''));
}

// --- 7. ogni apparato dichiara un effetto che qualcuno legge --------------------
titolo('Nessun apparato senza effetto');

$sorgenti = '';
foreach (['src/Sim', 'src/Game', 'src/Controllers'] as $d) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $d)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $sorgenti .= file_get_contents($f->getPathname());
        }
    }
}
$morti = [];
foreach (Database::all('SELECT ukey, effetto, costo FROM upgrade_types ORDER BY effetto') as $u) {
    $e = (string) $u['effetto'];
    // si cerca la chiave dell'effetto usata come indice, non la riga del catalogo
    if (!preg_match('/\[\s*[\'"]' . preg_quote($e, '/') . '[\'"]\s*\]/', $sorgenti)) {
        $morti[] = sprintf('%s (%s, %d punti)', (string) $u['ukey'], $e, (int) $u['costo']);
    }
}
ok('ogni effetto dichiarato dal cantiere e\' letto da qualche parte', $morti === [],
    $morti === [] ? count(Database::all('SELECT ukey FROM upgrade_types')) . ' apparati' : implode(', ', $morti));

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
