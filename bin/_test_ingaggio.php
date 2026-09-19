<?php

declare(strict_types=1);

/**
 * Uso interno delle prove: porta il battello di un utente 'prova *' addosso a
 * un convoglio e gli mette in mano il contatto. Rifiuta ogni altro utente.
 *
 *   php bin/_test_ingaggio.php "<username>" [contatto|bersaglio|encounter]
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Sim\Contacts;
use App\Sim\Geo;
use App\Sim\Rng;
use App\Sim\Traffic;
use App\Sim\World;

$username = $_SERVER['argv'][1] ?? '';
$cosa     = $_SERVER['argv'][2] ?? 'contatto';

if ($username === '' || !preg_match('/^prova[ _]/', $username)) {
    fwrite(STDERR, "Rifiuto: solo utenti 'prova_*' o 'prova *'.\n");
    exit(1);
}

$boat = Database::first(
    'SELECT b.* FROM boats b JOIN users u ON u.id = b.user_id WHERE u.username = ?',
    [$username]
);
if ($boat === null) {
    fwrite(STDERR, "Nessun battello per {$username}.\n");
    exit(1);
}

if ($cosa === 'bersaglio') {
    $migliore = 0;
    $dmin = 99.0;
    foreach (Database::all(
        "SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' AND stato = 'in_mare'",
        [(int) $boat['encounter_id']]
    ) as $e) {
        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
        if ($d < $dmin) {
            $dmin = $d;
            $migliore = (int) $e['id'];
        }
    }
    echo $migliore;
    exit(0);
}

if ($cosa === 'encounter') {
    echo (int) (Database::first(
        'SELECT COUNT(*) n FROM encounter_entities WHERE encounter_id = ?',
        [(int) $boat['encounter_id']]
    )['n'] ?? 0);
    exit(0);
}

if ($cosa === 'aperto') {
    echo $boat['encounter_id'] === null ? 'chiuso' : 'aperto';
    exit(0);
}

if ($cosa === 'siluri') {
    echo (int) (Database::first(
        'SELECT COUNT(*) n FROM torpedo_runs WHERE boat_id = ?',
        [(int) $boat['id']]
    )['n'] ?? 0);
    exit(0);
}

// Predisposizione: battello a quattro miglia da un convoglio, contatto in mano.
$gts = World::now();
Traffic::ensure($gts);

// Il convoglio deve esserci: se il mare e' momentaneamente vuoto in questa
// finestra si insiste, altrimenti la prova fallirebbe per un motivo che non
// c'entra nulla con quello che sta verificando.
$cv = null;
for ($tentativo = 0; $tentativo < 3 && $cv === null; $tentativo++) {
    // Il convoglio PIU' GROSSO fra quelli in mare, non il primo che capita: un
    // convoglio mangiato dalle prove precedenti darebbe un quadro tattico da
    // dieci unita' e la prova fallirebbe per un motivo che non c'entra niente.
    $cv = Database::first(
        "SELECT c.*, (SELECT COUNT(*) FROM ships s
                       WHERE s.convoy_id = c.id AND s.state = 'in_mare') AS unita
           FROM convoys c
          WHERE c.state = 'in_mare' AND c.departed_gts <= ? AND c.eta_gts >= ?
          ORDER BY unita DESC LIMIT 1",
        [$gts, $gts]
    );
    if ($cv === null) {
        Traffic::ensure($gts);
    }
}
if ($cv === null) {
    fwrite(STDERR, "Nessun convoglio in mare dopo tre tentativi.\n");
    exit(1);
}

$p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
[$lat, $lon] = Geo::destination($p['lat'], $p['lon'], Geo::normBearing($p['heading'] + 40), 4.0);

Database::run(
    'UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, depth_m = 12, ordered_depth_m = 12,
            mode = "periscopio", speed_kn = 3, ordered_speed_kn = 3, last_sim_gts = ?, encounter_id = NULL
     WHERE id = ?',
    [$lat, $lon, $lat, $lon, $gts, (int) $boat['id']]
);

$patrol = Database::first(
    "SELECT id FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
    [(int) $boat['id']]
);

$res = Contacts::upsert((int) $boat['id'], $patrol !== null ? (int) $patrol['id'] : null, [
    'kind'       => 'convoglio',
    'id'         => (int) $cv['id'],
    'sensore'    => 'idrofono',
    'bearing'    => Geo::bearing($lat, $lon, $p['lat'], $p['lon']),
    'distanza'   => 4.0,
    'est_lat'    => $lat,
    'est_lon'    => $lon,
    'classe_est' => 'convoglio',
    'navi'       => 30,
    'snr'        => 25.0,
], $gts, Rng::for(1, 'prova'));

echo (int) $res['id'];
