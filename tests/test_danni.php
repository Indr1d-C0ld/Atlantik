<?php

declare(strict_types=1);

/**
 * Il danno che resta addosso alla nave (O1).
 *
 *   php tests/test_danni.php
 *
 * Fino al 18/09/2026 una nave colpita e non affondata tornava intera nel
 * traffico appena si rompeva il contatto: due siluri a segno e la mattina dopo
 * navigava come nuova. Queste prove verificano che adesso il danno esca
 * dall'incontro e resti nel mondo — rallenta, perde il convoglio, e magari
 * affonda piu' tardi, con la conferma del BdU a chi l'aveva colpita.
 *
 * Tocca il database: crea un utente di prova col suo battello e alla fine se lo
 * porta via. Non manda e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Danni;
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

// --- la curva, senza database ------------------------------------------------
titolo('Quanto rallenta e quanto rischia');

ok('una nave intatta non rallenta', Danni::fattoreVelocita(100, 0, 0) === 1.0);
ok('l\'allagamento pesa piu\' dell\'integrita\'',
    Danni::fattoreVelocita(100, 50, 0) < Danni::fattoreVelocita(50, 0, 0),
    sprintf('%.3f contro %.3f', Danni::fattoreVelocita(100, 50, 0), Danni::fattoreVelocita(50, 0, 0)));
ok('nessuna nave resta ferma in mezzo all\'Atlantico',
    Danni::fattoreVelocita(1, 100, 100) > 0.0, sprintf('%.3f', Danni::fattoreVelocita(1, 100, 100)));

ok('con poca acqua in stiva non si affonda dopo', Danni::probabilitaAffondamento(20, 90, 0, 'cereali') === 0.0);
ok('vicino alla soglia si affonda quasi sempre',
    Danni::probabilitaAffondamento(84, 20, 0, 'cereali') > 0.6,
    sprintf('%.2f', Danni::probabilitaAffondamento(84, 20, 0, 'cereali')));
ok('il legname tiene a galla',
    Danni::probabilitaAffondamento(70, 40, 0, 'legname') < Danni::probabilitaAffondamento(70, 40, 0, 'cereali'),
    sprintf('%.2f contro %.2f',
        Danni::probabilitaAffondamento(70, 40, 0, 'legname'),
        Danni::probabilitaAffondamento(70, 40, 0, 'cereali')));
ok('il minerale porta giu\' piu\' in fretta',
    Danni::probabilitaAffondamento(70, 40, 0, 'minerale di ferro') > Danni::probabilitaAffondamento(70, 40, 0, 'cereali'));

// --- il mondo ----------------------------------------------------------------
titolo('Il danno esce dall\'incontro');

$utente = 'prova danni ' . time();
$reg = \App\Auth\Auth::register($utente, 'danni_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}

$cmd = $userId > 0 ? \App\Game\Comandante::crea($userId, [
    'nome' => 'Danni Prova ' . time(), 'nato_il' => '1912-05-04', 'nato_a' => 'Kiel',
    'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];

$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;
$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts, $gts]
);

if ($boat === null || $cv === null) {
    echo "  \033[0;90mniente battello o niente convoglio: prove saltate\033[0m\n";
} else {
    $boatId = (int) $boat['id'];
    \App\Game\Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]));

    $p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
    Database::run('UPDATE boats SET state = "mare", lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?',
        [$p['lat'], $p['lon'], $p['lat'], $p['lon'], $boatId]);
    $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);

    $res = Encounter::apri($boat, ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
    ok('l\'incontro si apre', (bool) $res['ok'], (string) ($res['error'] ?? ''));
    $encId = (int) ($res['encounter_id'] ?? 0);
    $t0 = (int) Database::first('SELECT last_step_gts FROM encounters WHERE id = ?', [$encId])['last_step_gts'];

    // Due bersagli: uno malmesso, uno appena graffiato.
    $lista = Database::all(
        "SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' AND ship_id IS NOT NULL
         ORDER BY grt DESC LIMIT 2",
        [$encId]
    );
    $grave = $lista[0];
    $lieve = $lista[1] ?? null;

    $primaGrave = Database::first('SELECT * FROM ships WHERE id = ?', [(int) $grave['ship_id']]);
    $posPrima = Traffic::posizione((string) $primaGrave['rotta_key'], (float) $primaGrave['speed_kn'],
        (int) $primaGrave['departed_gts'], $t0);

    Database::run("UPDATE encounter_entities SET stato = 'danneggiata', integrita = 31, allagamento = 68, incendio = 6 WHERE id = ?",
        [(int) $grave['id']]);
    if ($lieve !== null) {
        Database::run("UPDATE encounter_entities SET stato = 'danneggiata', integrita = 94, allagamento = 5 WHERE id = ?",
            [(int) $lieve['id']]);
    }

    // Contatto rotto: venti miglia.
    [$lat, $lon] = Geo::destination((float) $boat['lat'], (float) $boat['lon'], 90.0, 20.0);
    Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?',
        [$lat, $lon, $lat, $lon, $boatId]);
    Encounter::step($encId, $t0 + 20);

    $dopoGrave = Database::first('SELECT * FROM ships WHERE id = ?', [(int) $grave['ship_id']]);

    ok('il danno e\' finito sulla nave, non solo sull\'incontro',
        (float) $dopoGrave['integrita'] < 40 && (float) $dopoGrave['allagamento'] > 60,
        sprintf('integrita %.0f, allagamento %.0f', (float) $dopoGrave['integrita'], (float) $dopoGrave['allagamento']));

    ok('rallenta davvero', (float) $dopoGrave['speed_kn'] < (float) $primaGrave['speed_kn'],
        sprintf('%.1f → %.1f kn', (float) $primaGrave['speed_kn'], (float) $dopoGrave['speed_kn']));

    // La prova sottile: la posizione e' un calcolo su rotta, velocita' e ora di
    // partenza. Cambiare la velocita' senza spostare l'ora di partenza farebbe
    // saltare la nave indietro di miglia, come se non avesse mai percorso
    // quello che ha percorso.
    $posDopo = Traffic::posizione((string) $dopoGrave['rotta_key'], (float) $dopoGrave['speed_kn'],
        (int) $dopoGrave['departed_gts'], $t0);
    $salto = ($posPrima !== null && $posDopo !== null)
        ? Geo::distanceNm($posPrima['lat'], $posPrima['lon'], $posDopo['lat'], $posDopo['lon'])
        : 99.0;
    ok('rallentando non salta di posizione', $salto < 0.2, sprintf('%.4f miglia di scarto', $salto));

    ok('il convoglio la lascia indietro',
        $dopoGrave['convoy_id'] === null && (int) $dopoGrave['ritardataria'] === 1,
        'convoglio perduto: ' . (string) ($dopoGrave['convoglio_perduto'] ?? '—'));

    ok('si sa chi l\'ha colpita', (int) $dopoGrave['danno_boat_id'] === $boatId
        && $dopoGrave['danno_patrol_id'] !== null && $dopoGrave['danno_commander_id'] !== null,
        sprintf('battello %s, missione %s', $dopoGrave['danno_boat_id'], $dopoGrave['danno_patrol_id'] ?? '-'));

    if ($lieve !== null) {
        $dopoLieve = Database::first('SELECT * FROM ships WHERE id = ?', [(int) $lieve['ship_id']]);
        ok('una graffiata resta in convoglio',
            $dopoLieve['convoy_id'] !== null && (int) $dopoLieve['ritardataria'] === 0);
        ok('una graffiata non e\' condannata', $dopoLieve['affonda_gts'] === null);
    }

    // Scrivere due volte non deve rallentarla due volte.
    $velocitaUna = (float) $dopoGrave['speed_kn'];
    Danni::registra(
        Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]),
        Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]),
        Database::all('SELECT * FROM encounter_entities WHERE encounter_id = ?', [$encId]),
        $t0 + 20
    );
    $velocitaDue = (float) Database::first('SELECT speed_kn FROM ships WHERE id = ?', [(int) $grave['ship_id']])['speed_kn'];
    ok('il danno non si scrive due volte', abs($velocitaDue - $velocitaUna) < 0.01,
        sprintf('%.1f → %.1f kn', $velocitaUna, $velocitaDue));

    // --- l'agonia -------------------------------------------------------------
    titolo('Chi non ce la fa, e chi lo viene a sapere');

    Database::run('UPDATE ships SET affonda_gts = ? WHERE id = ?', [World::now() - 60, (int) $grave['ship_id']]);
    $affPrima = (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'];
    $ag = Danni::agonia(World::now());
    $affDopo = (int) Database::first('SELECT COUNT(*) n FROM sinkings')['n'];

    ok('la nave condannata affonda al suo momento', (int) $ag['affondate'] >= 1, json_encode($ag));
    ok('finisce in archivio', $affDopo === $affPrima + 1, sprintf('%d → %d', $affPrima, $affDopo));

    $nave = Database::first('SELECT state, sunk_by FROM ships WHERE id = ?', [(int) $grave['ship_id']]);
    ok('risulta affondata nel traffico, non piu\' in mare',
        (string) $nave['state'] === 'affondata' && (int) $nave['sunk_by'] === $boatId, (string) $nave['state']);

    $radio = Database::first(
        "SELECT testo FROM radio_messages WHERE dest_boat_id = ? AND tipo = 'comunicato' ORDER BY id DESC LIMIT 1",
        [$boatId]
    );
    ok('il BdU lo comunica al battello che l\'ha colpita',
        $radio !== null && str_contains((string) $radio['testo'], (string) $grave['name']),
        $radio !== null ? mb_substr((string) $radio['testo'], 0, 60) . '…' : 'nessun radiogramma');

    $patrol = Database::first("SELECT * FROM patrols WHERE boat_id = ? ORDER BY id DESC LIMIT 1", [$boatId]);
    ok('la stazza va alla missione durante la quale l\'hanno colpita',
        $patrol !== null && (int) $patrol['affondate'] >= 1 && (int) $patrol['grt_affondato'] >= (int) $grave['grt'],
        sprintf('%d navi, %d GRT', (int) ($patrol['affondate'] ?? 0), (int) ($patrol['grt_affondato'] ?? 0)));

    // --- ritrovarla ferita ----------------------------------------------------
    titolo('Ritrovare una nave gia\' colpita');

    if ($lieve !== null) {
        Database::run("UPDATE boats SET encounter_id = NULL WHERE id = ?", [$boatId]);
        Database::run("UPDATE encounters SET stato = 'concluso' WHERE boat_id = ?", [$boatId]);
        $naveL = Database::first('SELECT * FROM ships WHERE id = ?', [(int) $lieve['ship_id']]);
        $pos2 = Traffic::posizione((string) $naveL['rotta_key'], (float) $naveL['speed_kn'], (int) $naveL['departed_gts'], World::now());
        if ($pos2 !== null) {
            Database::run('UPDATE boats SET lat = ?, lon = ? WHERE id = ?', [$pos2['lat'], $pos2['lon'], $boatId]);
            $b2 = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
            $r2 = Encounter::apri($b2, ['convoy_id' => null, 'ship_id' => (int) $naveL['id']], World::now());
            if ($r2['ok']) {
                $e2 = Database::first(
                    'SELECT integrita, allagamento FROM encounter_entities WHERE encounter_id = ? AND ship_id = ?',
                    [(int) $r2['encounter_id'], (int) $naveL['id']]
                );
                ok('la si ritrova con le ferite di prima',
                    $e2 !== null && abs((float) $e2['integrita'] - (float) $naveL['integrita']) < 0.1,
                    sprintf('integrita %.0f', (float) ($e2['integrita'] ?? -1)));
                Database::run('DELETE FROM encounter_entities WHERE encounter_id = ?', [(int) $r2['encounter_id']]);
                Database::run('DELETE FROM encounters WHERE id = ?', [(int) $r2['encounter_id']]);
            } else {
                saltata('la si ritrova con le ferite di prima', 'non ingaggiabile adesso');
            }
        } else {
            saltata('la si ritrova con le ferite di prima', 'gia\' arrivata in porto');
        }
    }
}

// --- convogli che si sciolgono ----------------------------------------------
titolo('Un convoglio ridotto all\'osso si disperde');

// Le ritardatarie tolgono navi ai convogli e gli affondamenti pure. Senza una
// regola che chiuda i convogli esauriti, dopo qualche mese l'Atlantico sarebbe
// pieno di convogli da cinque navi. Qui se ne costruisce uno ridotto apposta e
// si guarda che il mondo lo sciolga.
$rottaProva = 'hx';
$gtsP = World::now();
$durata = Traffic::durata($rottaProva, 9.0);
Database::run(
    'INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ("HX", 9990, ?, 9.0, 5, ?, ?, 1, 0, 40)',
    [$rottaProva, $gtsP - 3600, $gtsP + $durata]
);
$cvProva = Database::lastInsertId();
$nomiProva = [];
for ($i = 0; $i < 3; $i++) {
    $n = 'Prova Dispersione ' . $i . ' ' . bin2hex(random_bytes(3));
    $nomiProva[] = $n;
    Database::run(
        'INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn,
                            departed_gts, eta_gts, grt)
         VALUES (?, "britannica", "cargo_medio", ?, ?, 1, "mercantile", ?, 9.0, ?, ?, 5000)',
        [$n, $cvProva, $i + 1, $rottaProva, $gtsP - 3600, $gtsP + $durata]
    );
}
$scortaProva = 'HMS Prova ' . bin2hex(random_bytes(3));
Database::run(
    'INSERT INTO ships (name, flag, class_key, convoy_id, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
     VALUES (?, "britannica", "corvetta_flower", ?, "scorta", ?, 9.0, ?, ?, 925)',
    [$scortaProva, $cvProva, $rottaProva, $gtsP - 3600, $gtsP + $durata]
);

try {
    $esito = Traffic::ensure(World::now());
    $cvDopo = Database::first('SELECT state FROM convoys WHERE id = ?', [$cvProva]);
    ok('il convoglio esaurito viene sciolto', (string) $cvDopo['state'] === 'arrivato',
        (string) $cvDopo['state'] . ', dispersi ' . ($esito['dispersi'] ?? 0));

    $sopravvissute = Database::all(
        'SELECT convoy_id, state, convoglio_perduto FROM ships WHERE name IN (?, ?, ?)',
        $nomiProva
    );
    $indipendenti = array_filter($sopravvissute,
        static fn (array $r): bool => $r['convoy_id'] === null && (string) $r['state'] === 'in_mare');
    ok('le superstiti proseguono da sole, non spariscono',
        count($indipendenti) === 3, count($indipendenti) . ' su 3');
    ok('si ricordano il convoglio che hanno perso',
        ($sopravvissute[0]['convoglio_perduto'] ?? '') === 'HX9990',
        (string) ($sopravvissute[0]['convoglio_perduto'] ?? '—'));

    $sc = Database::first('SELECT state FROM ships WHERE name = ?', [$scortaProva]);
    ok('la scorta ha finito il suo lavoro e rientra', (string) $sc['state'] === 'arrivata',
        (string) $sc['state']);

    // Un convoglio ancora consistente non si tocca: un branco che ne affonda
    // tre non deve poterlo far sciogliere.
    $sano = Database::first(
        "SELECT c.id, (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare'
                        AND s.ruolo <> 'scorta') merc
           FROM convoys c WHERE c.state = 'in_mare' HAVING merc >= 8 ORDER BY merc LIMIT 1"
    );
    ok('un convoglio ancora in forze resta in mare',
        $sano !== null, $sano !== null ? $sano['merc'] . ' mercantili' : 'nessuno da controllare');
} finally {
    Database::run('DELETE FROM ships WHERE name IN (?, ?, ?)', $nomiProva);
    Database::run('DELETE FROM ships WHERE name = ?', [$scortaProva]);
    Database::run('DELETE FROM convoys WHERE id = ?', [$cvProva]);
}

// Pulizia: l'utente, il battello, la missione, gli affondamenti di prova.
if ($userId > 0) {
    shell_exec(sprintf('%s %s %s 2>/dev/null',
        PHP_BINARY, escapeshellarg(dirname(__DIR__) . '/bin/_cleanup_test_user.php'), escapeshellarg($utente)));
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m"
    . (($saltate ?? 0) > 0 ? "  \033[0;33m({$saltate} saltate)\033[0m" : "") . "\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
