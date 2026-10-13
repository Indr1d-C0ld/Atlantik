<?php

declare(strict_types=1);

/**
 * Atlantik — l'addestramento tattico (Agru-Front).
 *
 *   php tests/test_addestramento.php
 *
 * Nasce dal conto del 09/10/2026: zero affondamenti in tutta la storia del
 * mondo in esercizio. La stazione d'attacco non si impara al fronte, e nessun
 * comandante vero ci andava senza essere passato dal Baltico. Qui si verifica:
 *
 *   - la partenza: il battello nel Baltico, davanti a un convoglio
 *     d'esercitazione gia' in contatto, senza toccare il traffico di guerra;
 *   - nell'incontro, i siluri colpiscono senza danno e le cariche non fanno
 *     male ma si contano;
 *   - alla fine, il giudizio dell'istruttore, il ritorno in base, le casse
 *     com'erano, la competenza e il premio (una volta sola);
 *   - un convoglio lasciato passare chiude l'esercitazione da solo;
 *   - l'esercitazione non conta fra le missioni di guerra.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Addestramento;
use App\Sim\Detection;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Rng;
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

// Il battito non deve chiudere l'esercitazione mentre la si guarda.
$lucchetto = fopen(__DIR__ . '/../storage/tick.lock', 'c');
if ($lucchetto === false || !flock($lucchetto, LOCK_EX)) {
    fwrite(STDERR, "Non riesco a prendere il lucchetto del battito.\n");
    exit(2);
}

$suffisso = substr((string) time(), -6);
$reg = \App\Auth\Auth::register('prova agru ' . $suffisso, 'agru' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
\App\Game\Comandante::crea($userId, ['nome' => 'Agru Prova ' . $suffisso, 'nato_il' => '1914-03-02',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($userId);
$id = (int) $boat['id'];
$convogli = [];
register_shutdown_function(static function () use ($userId, $id, &$convogli, $lucchetto): void {
    Database::run('DELETE FROM addestramenti WHERE boat_id = ?', [$id]);
    foreach ($convogli as $c) {
        Database::run('DELETE FROM encounter_entities WHERE encounter_id IN (SELECT id FROM encounters WHERE convoy_id = ?)', [$c]);
        Database::run('DELETE FROM torpedo_runs WHERE encounter_id IN (SELECT id FROM encounters WHERE convoy_id = ?)', [$c]);
        Database::run('DELETE FROM encounters WHERE convoy_id = ?', [$c]);
        Database::run('DELETE FROM convoys WHERE id = ?', [$c]);
    }
    // Missioni, giornale e incontri non hanno chiave esterna sui battelli:
    // vanno via a mano, o resterebbero a sporcare le statistiche.
    Database::run('DELETE FROM torpedo_runs WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM encounter_entities WHERE encounter_id IN (SELECT id FROM encounters WHERE boat_id = ?)', [$id]);
    Database::run('DELETE FROM encounters WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM patrols WHERE boat_id = ?', [$id]);
    Database::run('DELETE FROM avvisi WHERE user_id = ?', [$userId]);
    Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    flock($lucchetto, LOCK_UN);
});

$leggi = static fn (): array => Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
$cmdId = (int) Database::first('SELECT id FROM commanders WHERE user_id = ?', [$userId])['id'];

// ==========================================================================
titolo('Si parte per il Baltico');
// ==========================================================================

$prima = $leggi();
// Quattro siluri gia' spesi: l'esercitazione imbarca il carico pieno di
// teste d'esercitazione, e al ritorno devono tornare i dieci di prima.
Database::run('DELETE FROM boat_torpedoes WHERE boat_id = ? ORDER BY id DESC LIMIT 4', [$id]);
$siluriPrima = Database::all('SELECT tkey, tubo, stato FROM boat_torpedoes WHERE boat_id = ? ORDER BY id', [$id]);
Database::run('UPDATE boats SET fuel_t = 41.5, provisions_days = 23 WHERE id = ?', [$id]);
$trafficoPrima = Traffic::stato(World::now());
$campagnaPrima = \App\Game\Statistiche::campagna(World::now());

$r = Addestramento::parti($leggi());
ok('dalla base si parte', $r['ok'], $r['error'] ?? '');
$b = $leggi();
$patrol = Database::first('SELECT * FROM patrols WHERE id = ?', [(int) ($r['patrol_id'] ?? 0)]);
$a = Addestramento::corrente($id);
$convogli[] = (int) ($a['convoy_id'] ?? 0);
ok('il battello e\' in mare nella baia di Danzica', (string) $b['state'] === 'mare'
    && Geo::distanceNm((float) $b['lat'], (float) $b['lon'], 54.9, 18.9) < 60,
    sprintf('%.2f, %.2f', (float) $b['lat'], (float) $b['lon']));
ok('la missione e\' un addestramento, senza numero di guerra',
    $patrol !== null && (string) $patrol['tipo'] === 'addestramento' && (int) $patrol['number'] === 0);
$cv = $a !== null ? Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $a['convoy_id']]) : null;
$navi = $cv !== null ? Database::all('SELECT * FROM ships WHERE convoy_id = ?', [(int) $cv['id']]) : [];
ok('c\'e\' il convoglio d\'esercitazione, con due cacciasommergibili',
    $cv !== null && (int) $cv['esercitazione'] === 1
    && count(array_filter($navi, static fn (array $n): bool => $n['ruolo'] === 'scorta')) === 2,
    count($navi) . ' navi');
$ctt = Database::first('SELECT * FROM contacts WHERE boat_id = ? AND convoy_id = ?', [$id, (int) ($cv['id'] ?? 0)]);
$pc = $cv !== null ? Traffic::posizione('agru', (float) $cv['speed_kn'], (int) $cv['departed_gts'], World::now(), 0.0) : null;
$d = $pc !== null ? Geo::distanceNm((float) $b['lat'], (float) $b['lon'], $pc['lat'], $pc['lon']) : 99.0;
ok('e il contatto e\' gia\' in mano, a distanza d\'attacco', $ctt !== null && $d < 10.0, sprintf('%.1f miglia', $d));
ok('non entra nel conto del traffico di guerra', Traffic::stato(World::now())['convogli'] === $trafficoPrima['convogli']);
$rngA = Rng::for(1, 'prova aerei');
$aria = Detection::aereo(Traffic::dati()['aria'], (float) $b['lat'], (float) $b['lon'], 1.0, 2, 0.0, $rngA);
ok('nel Baltico non volano aerei nemici', $aria['probabilita'] === 0.0, (string) ($aria['zona'] ?? 'nessuna zona'));
ok('una seconda partenza non si puo\'', !Addestramento::parti($leggi())['ok']);

// ==========================================================================
titolo('L\'attacco: teste d\'esercitazione e cariche di segnalazione');
// ==========================================================================

$gts = World::now();
$res = Encounter::apri($leggi(), ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
ok('si da\' battaglia al convoglio d\'esercitazione', (bool) $res['ok'], (string) ($res['error'] ?? ''));
$encId = (int) ($res['encounter_id'] ?? 0);
$t0 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];

$bersaglio = Database::first(
    "SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' ORDER BY grt DESC LIMIT 1", [$encId]);
// Il battello a un miglio e mezzo dal bersaglio, a quota periscopica: da piu'
// lontano, dopo il colpo, le scorte non lo troverebbero e l'incontro si
// chiuderebbe da se' («ci hanno perso»).
[$vla, $vlo] = Geo::destination((float) $bersaglio['lat'], (float) $bersaglio['lon'], 0.0, 1.5);
Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, depth_m = 12, ordered_depth_m = 12,
                mode = "periscopio" WHERE id = ?', [$vla, $vlo, $vla, $vlo, $id]);
// Un siluro a duecento metri dal bersaglio, puntato addosso: va a segno per geometria.
[$sla, $slo] = Geo::destination((float) $bersaglio['lat'], (float) $bersaglio['lon'], 270.0, 0.11);
Database::run(
    "INSERT INTO torpedo_runs (encounter_id, boat_id, tkey, tubo, lanciato_gts, lat, lon, heading, speed_kn, quota_m,
                               spoletta, corsa_max_m, percorso_m, esito)
     VALUES (?, ?, 'G7e_T2', 1, ?, ?, ?, 90, 30, 4, 'contatto', 5000, 800, 'in_corsa')",
    [$encId, $id, $t0, $sla, $slo]
);
Database::run('UPDATE encounters SET allarme = 0, allarme_gts = NULL WHERE id = ?', [$encId]);
Encounter::step($encId, $t0 + 40);
$corsa = Database::first('SELECT * FROM torpedo_runs WHERE encounter_id = ? ORDER BY id DESC LIMIT 1', [$encId]);
$dopo = Database::first('SELECT * FROM encounter_entities WHERE id = ?', [(int) $bersaglio['id']]);
ok('il siluro va a segno', (string) $corsa['esito'] === 'colpito', (string) $corsa['esito'] . ' — ' . (string) $corsa['nota']);
ok('e la nave non ha un graffio', (float) $dopo['integrita'] >= 100.0 && (string) $dopo['stato'] !== 'affonda',
    sprintf('integrita %.0f, %s', (float) $dopo['integrita'], (string) $dopo['stato']));
ok('il giornale dice che era una testa d\'esercitazione',
    Database::first("SELECT 1 FROM patrol_events WHERE patrol_id = ? AND text LIKE '%esercitazione: passa sotto%'",
        [(int) $patrol['id']]) !== null);

// Gli UJ in caccia: il battello resta davanti al primo, a quota periscopica,
// finche' una carica non gli cade abbastanza vicino da contare.
$stressPrima = (float) $leggi()['hull_stress'];
$guastiPrima = (int) Database::first("SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'", [$id])['n'];
$compPrimaCariche = Database::all('SELECT integrity, flooding FROM boat_compartments WHERE boat_id = ? ORDER BY seq', [$id]);
Database::run("UPDATE encounter_entities SET contatto = 1.0 WHERE encounter_id = ? AND ruolo = 'scorta'", [$encId]);
Database::run('UPDATE encounters SET allarme = 1 WHERE id = ?', [$encId]);
$t = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];
// A giri: si mette il battello davanti alla prua e si lascia all'UJ il
// tempo di passargli sopra. Davanti, non sotto: proprio sotto, l'ASDIC e' cieco.
// Fino a trenta passaggi, con le cariche rifornite a ogni giro: quante ne
// cadono vicino e' questione di fortuna (a dodici giri e' capitato che trenta
// cariche cadessero tutte lontano), e la verifica e' su che cosa succede
// quando una cade vicino, non su quando.
for ($giro = 0; $giro < 30; $giro++) {
    $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
    if ((float) $enc['danno_simulato'] > 0.0 || (string) $enc['stato'] === 'concluso') {
        break;
    }
    $uj = Database::first("SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo = 'scorta' ORDER BY id LIMIT 1", [$encId]);
    [$bla, $blo] = Geo::destination((float) $uj['lat'], (float) $uj['lon'], (float) $uj['heading'], 0.3);
    // A dodici metri: le cariche non si regolano piu' su di dieci, quindi la
    // quota la indovinano quasi sempre, e la prova non resta in mano al caso.
    Database::run('UPDATE boats SET lat = ?, lon = ?, depth_m = 12, ordered_depth_m = 12, mode = "periscopio", speed_kn = 0,
                    ordered_speed_kn = 0, periscopio_alzato = 0, hull_stress = 0 WHERE id = ?', [$bla, $blo, $id]);
    Database::run("UPDATE encounter_entities SET contatto = 1.0, manovra = 'attacco', dc_residue = 40, ultimo_attacco_gts = NULL WHERE id = ?", [(int) $uj['id']]);
    for ($k = 0; $k < 10; $k++) {
        $t += 60;
        Encounter::step($encId, $t);
        if ((float) Database::first('SELECT danno_simulato FROM encounters WHERE id = ?', [$encId])['danno_simulato'] > 0.0) {
            break;
        }
    }
}
$enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
$b = $leggi();
ok('gli UJ lanciano, e una carica arriva vicina: in guerra avrebbe fatto male', (float) $enc['danno_simulato'] > 0.0,
    sprintf('%d cariche, %.1f%% di sollecitazione simulata', (int) $enc['cariche_subite'], (float) $enc['danno_simulato']));
ok('ma qui lo scafo non la sente', (float) $b['hull_stress'] < 1.0, sprintf('sollecitazione %.1f', (float) $b['hull_stress']));
ok('niente avarie e niente falle', (int) Database::first("SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'", [$id])['n'] === $guastiPrima
    && Database::all('SELECT integrity, flooding FROM boat_compartments WHERE boat_id = ? ORDER BY seq', [$id]) === $compPrimaCariche);
ok('il giornale la racconta come carica di segnalazione',
    Database::first("SELECT 1 FROM patrol_events WHERE patrol_id = ? AND text LIKE 'Carica di segnalazione%'", [(int) $patrol['id']]) !== null);
$cannone = Encounter::cannone($enc, $leggi(), (int) $bersaglio['id'], 3, $t);
ok('il cannone resta coperto', !$cannone['ok'], (string) ($cannone['error'] ?? ''));

// ==========================================================================
titolo('Il giudizio, e il ritorno');
// ==========================================================================

$prestigioPrima = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
$compPrima = (float) Database::first('SELECT AVG(competence) c FROM crew_members WHERE boat_id = ?', [$id])['c'];
if ((string) Database::first('SELECT stato FROM encounters WHERE id = ?', [$encId])['stato'] !== 'concluso') {
    Encounter::chiudi($encId, 'fine della prova', $t);
}
$a = Database::first('SELECT * FROM addestramenti WHERE patrol_id = ?', [(int) $patrol['id']]);
$det = json_decode((string) $a['dettaglio'], true);
$b = $leggi();
ok('alla chiusura dell\'incontro l\'istruttore giudica', $a['finito_gts'] !== null && $a['giudizio'] !== null,
    (string) $a['giudizio'] . ', ' . (string) $a['punteggio'] . ' punti — ' . (string) $a['rapporto']);
ok('conta un lancio e un colpo, e l\'avvicinamento non visto', (int) $det['lanci'] === 1 && (int) $det['colpi'] === 1
    && $det['visto_prima_del_lancio'] === false, json_encode(array_intersect_key($det, array_flip(['lanci', 'colpi', 'visto_prima_del_lancio']))));
ok('il battello e\' di nuovo in base, a Lorient', (string) $b['state'] === 'base' && $b['encounter_id'] === null
    && Geo::distanceNm((float) $b['lat'], (float) $b['lon'], 47.748, -3.367) < 1.0);
ok('con la nafta e i viveri che aveva', abs((float) $b['fuel_t'] - 41.5) < 0.01 && abs((float) $b['provisions_days'] - 23) < 0.01,
    sprintf('%.1f t, %.0f giorni', (float) $b['fuel_t'], (float) $b['provisions_days']));
$siluriDopo = Database::all('SELECT tkey, tubo, stato FROM boat_torpedoes WHERE boat_id = ? ORDER BY id', [$id]);
ok('e i siluri di prima, uno per uno', $siluriDopo === $siluriPrima, count($siluriDopo) . ' siluri');
ok('la missione d\'addestramento e\' chiusa', (string) Database::first('SELECT state FROM patrols WHERE id = ?', [(int) $patrol['id']])['state'] === 'conclusa');
ok('il convoglio d\'esercitazione non e\' piu\' in mare', (string) Database::first('SELECT state FROM convoys WHERE id = ?', [(int) $cv['id']])['state'] === 'arrivato');
$superato = in_array((string) $a['giudizio'], ['ottimo', 'buono', 'sufficiente'], true);
$prestigioDopo = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
$compDopo = (float) Database::first('SELECT AVG(competence) c FROM crew_members WHERE boat_id = ?', [$id])['c'];
if ($superato) {
    ok('superato la prima volta: prestigio', $prestigioDopo === $prestigioPrima + 60, $prestigioPrima . ' → ' . $prestigioDopo);
    ok('e competenza all\'equipaggio', $compDopo > $compPrima, sprintf('%.1f → %.1f', $compPrima, $compDopo));
} else {
    ok('non superato: niente premio', $prestigioDopo === $prestigioPrima);
}
ok('una seconda chiusura non rifa\' niente', Addestramento::concludi((int) $patrol['id'], $t + 60, null) === null);

// ==========================================================================
titolo('Il giudizio, nei numeri');
// ==========================================================================

// Una seconda esercitazione, per guardare la tabella dei punti con i numeri
// scritti a mano nell'incontro.
$r2 = Addestramento::parti($leggi());
$p2 = (int) ($r2['patrol_id'] ?? 0);
$a2 = Addestramento::corrente($id);
$convogli[] = (int) ($a2['convoy_id'] ?? 0);
$res2 = Encounter::apri($leggi(), ['convoy_id' => (int) $a2['convoy_id'], 'ship_id' => null], World::now());
$e2 = (int) ($res2['encounter_id'] ?? 0);
$t2 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$e2])['last_step_gts'];
foreach ([['colpito', $t2 + 100], ['esaurito', $t2 + 110], ['esaurito', $t2 + 120]] as [$esito, $quando]) {
    Database::run(
        "INSERT INTO torpedo_runs (encounter_id, boat_id, tkey, tubo, lanciato_gts, lat, lon, heading, speed_kn, quota_m,
                                   spoletta, corsa_max_m, percorso_m, esito)
         VALUES (?, ?, 'G7e_T2', 1, ?, 55, 19, 90, 30, 4, 'contatto', 5000, 5000, ?)", [$e2, $id, $quando, $esito]);
}
Database::run('UPDATE encounters SET allarme_gts = ?, danno_simulato = 30 WHERE id = ?', [$t2 + 50, $e2]);
$v = Addestramento::valuta($p2, 0.0, null);
ok('visto prima del lancio, un colpo su tre, cariche a meta\': insufficiente',
    $v['punteggio'] === 0 + 10 + 13 + 10 && $v['giudizio'] === 'insufficiente',
    $v['punteggio'] . ' punti, ' . $v['giudizio']);
Database::run('UPDATE encounters SET allarme_gts = NULL, danno_simulato = 0 WHERE id = ?', [$e2]);
$v = Addestramento::valuta($p2, 0.0, null);
ok('non visto, un colpo su tre, nessuna carica: buono', $v['punteggio'] === 30 + 23 + 20 && $v['giudizio'] === 'buono',
    $v['punteggio'] . ' punti, ' . $v['giudizio']);
Database::run('UPDATE encounters SET danno_simulato = 99 WHERE id = ?', [$e2]);
$v = Addestramento::valuta($p2, 5.0, null);
ok('chi in guerra sarebbe andato a fondo non passa', $v['giudizio'] === 'insufficiente' && $v['dettaglio']['affondato'] === true,
    $v['rapporto']);
Encounter::chiudi($e2, 'fine', World::now());
$premioDopo = (int) Database::first('SELECT prestigio FROM commanders WHERE id = ?', [$cmdId])['prestigio'];
ok('il premio si prende una volta sola', !$superato || $premioDopo === $prestigioDopo, $prestigioDopo . ' → ' . $premioDopo);

// ==========================================================================
titolo('Il convoglio lasciato passare');
// ==========================================================================

$r3 = Addestramento::parti($leggi());
$a3 = Addestramento::corrente($id);
$convogli[] = (int) ($a3['convoy_id'] ?? 0);
Database::run('UPDATE convoys SET eta_gts = ? WHERE id = ?', [World::now() - 10, (int) $a3['convoy_id']]);
$m = Addestramento::mantieni(World::now());
$fine3 = Database::first('SELECT * FROM addestramenti WHERE id = ?', [(int) $a3['id']]);
ok('il battito chiude l\'esercitazione', $m['conclusi'] >= 1 && $fine3['finito_gts'] !== null && (string) $leggi()['state'] === 'base');
ok('senza niente da giudicare', (string) $fine3['giudizio'] === 'non concluso', (string) $fine3['rapporto']);

// ==========================================================================
titolo('Interrotta dal comandante');
// ==========================================================================

// Visto dal vivo il 09/10/2026: ingaggiato e subito interrotto, l'istruttore
// dava «insufficiente», perche' l'incontro chiudendosi concludeva prima che
// arrivasse il motivo.
Addestramento::parti($leggi());
$a4 = Addestramento::corrente($id);
$convogli[] = (int) ($a4['convoy_id'] ?? 0);
$res4 = Encounter::apri($leggi(), ['convoy_id' => (int) $a4['convoy_id'], 'ship_id' => null], World::now());
$i4 = Addestramento::interrompi($leggi());
$fine4 = Database::first('SELECT * FROM addestramenti WHERE id = ?', [(int) $a4['id']]);
ok('interrotta a incontro aperto, senza lanci: non concluso, non insufficiente',
    $i4['ok'] && (string) $fine4['giudizio'] === 'non concluso', (string) $fine4['giudizio'] . ' — ' . (string) $fine4['rapporto']);
ok('l\'incontro e\' chiuso e il battello in base', (string) Database::first('SELECT stato FROM encounters WHERE id = ?',
    [(int) ($res4['encounter_id'] ?? 0)])['stato'] === 'concluso' && (string) $leggi()['state'] === 'base'
    && $leggi()['encounter_id'] === null);

// ==========================================================================
titolo('Quello che la revisione del 13/10/2026 ha trovato');
// ==========================================================================

// Un convoglio d'esercitazione con cinque piroscafi stava sotto la soglia dei
// convogli «ridotti all'osso», e il generatore del traffico lo disperdeva.
$r5 = Addestramento::parti($leggi());
$a5 = Addestramento::corrente($id);
$convogli[] = (int) ($a5['convoy_id'] ?? 0);
ok('una seconda partenza, mentre la prima e\' in corso, non parte', !Addestramento::parti(
    array_merge($leggi(), ['state' => 'base']))['ok'], 'doppio clic');
ok('e c\'e\' una sola missione d\'addestramento aperta', (int) Database::first(
    "SELECT COUNT(*) n FROM patrols WHERE boat_id = ? AND tipo = 'addestramento' AND state = 'in_corso'", [$id])['n'] === 1);
Database::run("UPDATE ships SET state = 'arrivata' WHERE convoy_id = ? AND ruolo <> 'scorta' ORDER BY id DESC LIMIT 2", [(int) $a5['convoy_id']]);
Traffic::ensure(World::now());
ok('il convoglio d\'esercitazione, anche con pochi piroscafi, non si disperde',
    (string) Database::first('SELECT state FROM convoys WHERE id = ?', [(int) $a5['convoy_id']])['state'] === 'in_mare');
// Perduto sotto la quota di collasso durante l'esercitazione: resta perduto.
Database::run("UPDATE boats SET state = 'perduto' WHERE id = ?", [$id]);
Database::run("UPDATE patrols SET state = 'perduta' WHERE id = ?", [(int) $a5['patrol_id']]);
Addestramento::concludi((int) $a5['patrol_id'], World::now(), null);
ok('un battello perduto in esercitazione non torna in banchina', (string) $leggi()['state'] === 'perduto'
    && (string) Database::first('SELECT state FROM patrols WHERE id = ?', [(int) $a5['patrol_id']])['state'] === 'perduta');
Database::run("UPDATE boats SET state = 'base' WHERE id = ?", [$id]);

// ==========================================================================
titolo('Non e\' una missione di guerra');
// ==========================================================================

$numeri = \App\Game\Profilo::numeri($cmdId);
ok('il fascicolo non la conta fra le uscite', (int) $numeri['uscite'] === 0, (string) $numeri['uscite']);
ok('e la prima missione vera avra\' il numero uno', (int) Database::first(
    "SELECT COALESCE(MAX(number), 0) + 1 AS n FROM patrols WHERE boat_id = ? AND tipo = 'guerra'", [$id])['n'] === 1);
$st = \App\Game\Statistiche::campagna(World::now());
ok('le statistiche pubbliche non contano le sue uscite ne\' i suoi siluri',
    $st['patrol'] === $campagnaPrima['patrol'] && $st['siluri_a_segno'] === $campagnaPrima['siluri_a_segno'],
    sprintf('missioni %d → %d, siluri a segno %d → %d', $campagnaPrima['patrol'], $st['patrol'],
        $campagnaPrima['siluri_a_segno'], $st['siluri_a_segno']));

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
