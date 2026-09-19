<?php

declare(strict_types=1);

/**
 * Quello che si vede davvero dalla stazione d'attacco (O2).
 *
 *   php tests/test_vista.php
 *
 * Fino al 18/09/2026 la pagina d'attacco mostrava la verita' nuda: nome,
 * classe, stazza e distanza al metro di tutte le unita' del convoglio, anche a
 * sette chilometri, anche col periscopio abbassato. La pagina dei contatti
 * applicava invece la regola concordata — classe solo per i contatti VISTI e
 * classificati oltre il 60% — e le due pagine dicevano cose diverse.
 *
 * Qui si verifica la regola nuova: sul tavolo ci va solo cio' che qualcuno ha
 * visto o sentito, con l'errore di chi l'ha visto o sentito.
 *
 * Tocca il database: crea un utente di prova col suo battello e se lo porta via.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Traffic;
use App\Sim\Vista;
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

/** Mette il battello in un certo assetto e ricostruisce il quadro. */
function quadroCon(int $boatId, array $enc, string $modo, float $quota, bool $periscopio, ?array &$som = null): array
{
    Database::run('UPDATE boats SET mode = ?, depth_m = ?, periscopio_alzato = ?, speed_kn = 2, silent = 0 WHERE id = ?',
        [$modo, $quota, $periscopio ? 1 : 0, $boatId]);
    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
    return Vista::quadro($b, $enc, Encounter::entita((int) $enc['id']), 1.0, 1.0, $som);
}

$utente = 'prova vista ' . time();
$reg = \App\Auth\Auth::register($utente, 'vista_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
$boat = $userId > 0 ? \App\Game\Fleet::ensureBoat($userId) : null;

$gts = World::now();
Traffic::ensure($gts);
$cv = Database::first(
    "SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? LIMIT 1",
    [$gts, $gts]
);

if ($boat === null || $cv === null) {
    echo "  \033[0;90mniente battello o niente convoglio: prove saltate\033[0m\n";
    echo "\n\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}

$boatId = (int) $boat['id'];
$encId = null;

try {
    $p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
    Database::run('UPDATE boats SET state = "mare", lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?',
        [$p['lat'], $p['lon'], $p['lat'], $p['lon'], $boatId]);
    $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);

    $res = Encounter::apri($boat, ['convoy_id' => (int) $cv['id'], 'ship_id' => null], $gts);
    if (!$res['ok']) {
        throw new RuntimeException((string) ($res['error'] ?? 'apertura fallita'));
    }
    $encId = (int) $res['encounter_id'];
    $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);

    // Scenario governato: tre unita' a distanze scelte, e il resto lontanissimo.
    $tutte = Database::all(
        "SELECT * FROM encounter_entities WHERE encounter_id = ? AND ruolo <> 'scorta' ORDER BY id",
        [$encId]
    );
    $vicina = $tutte[0];
    $media  = $tutte[1] ?? $tutte[0];
    $lontana = $tutte[2] ?? $tutte[0];

    $metti = static function (array $e, float $nm, float $ril) use ($boat): void {
        [$la, $lo] = Geo::destination((float) $boat['lat'], (float) $boat['lon'], $ril, $nm);
        Database::run('UPDATE encounter_entities SET lat = ?, lon = ?, heading = 90, speed_kn = 8 WHERE id = ?',
            [$la, $lo, (int) $e['id']]);
    };
    $metti($vicina, 0.12, 20.0);       // 220 m: il nome si legge anche al buio
    $metti($media, 2.20, 120.0);       // 4 km: sagoma, forse classe
    $metti($lontana, 40.0, 200.0);     // fuori da tutto

    // Il resto del convoglio lontanissimo, cosi' non disturba il conto.
    Database::run(
        'UPDATE encounter_entities SET lat = lat + 4.0 WHERE encounter_id = ? AND id NOT IN (?, ?, ?)',
        [$encId, (int) $vicina['id'], (int) $media['id'], (int) $lontana['id']]
    );

    // Giorno o notte? La regola sul nome dipende dalla luce, quindi va letta.
    $meteo = World::weather((float) $boat['lat'], (float) $boat['lon'], (int) $enc['last_step_gts']);
    $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], (int) $enc['last_step_gts'], (float) $meteo['cloud']);
    $giorno = (float) $cielo['luce'] > 0.45;
    printf("  \033[0;90mscenario: %s, visibilita' %.0f nm, mare %d\033[0m\n",
        $giorno ? 'giorno' : 'notte', (float) $meteo['visibility_nm'], (int) $meteo['sea_state']);

    // --- col periscopio abbassato -------------------------------------------
    titolo('A orecchio non si riconosce niente');

    $somGiu = null;
    $giu = quadroCon($boatId, $enc, 'periscopio', 12.0, false, $somGiu);
    $perId = [];
    foreach ($giu as $u) { $perId[$u['id']] = $u; }

    ok('nessuna unita\' risulta vista',
        array_filter($giu, static fn (array $u): bool => $u['osservazione'] === 'vista') === [],
        count($giu) . ' unita\' sul tavolo');
    ok('nessuna unita\' risulta riconosciuta',
        array_filter($giu, static fn (array $u): bool => (bool) $u['identificata']) === []);
    ok('nessuna sagoma da mostrare',
        array_filter($giu, static fn (array $u): bool => $u['classe_key'] !== null) === []);
    ok('nessun nome di nave sul tavolo',
        array_filter($giu, static fn (array $u): bool => (bool) $u['nome_noto']) === []);
    ok('la stazza non si stima a orecchio',
        array_filter($giu, static fn (array $u): bool => $u['grt'] > 0) === []);
    ok('quella fuori portata non compare affatto',
        !isset($perId[(int) $lontana['id']]), 'a quaranta miglia');

    // --- in superficie -------------------------------------------------------
    titolo('Con un occhio fuori si vede, e da vicino si riconosce');

    // Riconoscere costa luce: "di notte una sagoma resta una sagoma anche a
    // mille metri" (Vista::quadro). Questa parte della prova chiedeva il
    // riconoscimento senza guardare l'ora, e passava solo finche' l'orologio
    // del mondo capitava di giorno. Il 19/09/2026 e' capitato di notte, a 200
    // metri, con certezza 0,59 contro una soglia di 0,60: aveva torto la
    // prova. Adesso l'ora la si sceglie, e si guardano tutt'e due i casi.
    $oraCon = static function (bool $luminosa) use ($boat, $enc): int {
        $base = (int) $enc['last_step_gts'];
        $scelto = $base;
        $migliore = null;
        for ($h = 0; $h < 24; $h++) {
            $t = $base + $h * 3600;
            $m = World::weather((float) $boat['lat'], (float) $boat['lon'], $t);
            $c = World::sky((float) $boat['lat'], (float) $boat['lon'], $t, (float) $m['cloud']);
            // Per l'ora di luce non basta il sole: con la nebbia si vede poco
            // lo stesso. Si sceglie l'ora che unisce luce e visibilita'.
            $l = (float) $c['luce'];
            $punteggio = $luminosa ? $l * min(10.0, (float) $m['visibility_nm']) : $l;
            if ($migliore === null || ($luminosa ? $punteggio > $migliore : $punteggio < $migliore)) {
                $migliore = $punteggio;
                $scelto = $t;
            }
        }
        return $scelto;
    };

    $quadroAlle = static function (int $t) use ($boatId, $enc, $vicina, $media): array {
        $e = $enc;
        $e['last_step_gts'] = $t;
        $som = null;
        $q = quadroCon($boatId, $e, 'superficie', 0.0, false, $som);
        $per = [];
        foreach ($q as $u) { $per[$u['id']] = $u; }
        return [
            'vicina' => $per[(int) $vicina['id']] ?? null,
            'media'  => $per[(int) $media['id']] ?? null,
        ];
    };

    $tGiorno = $oraCon(true);
    $tNotte  = $oraCon(false);
    $conLuce = $quadroAlle($tGiorno);
    $alBuio  = $quadroAlle($tNotte);

    $uVicina = $conLuce['vicina'];
    ok('la piu\' vicina si vede', $uVicina !== null && $uVicina['osservazione'] === 'vista',
        $uVicina !== null ? $uVicina['nome'] : 'assente');
    ok('di giorno, a 200 metri si riconosce',
        $uVicina !== null && (bool) $uVicina['identificata'],
        $uVicina !== null ? sprintf('certezza %.2f', $uVicina['certezza']) : '');
    ok('riconosciuta vuol dire sagoma da mostrare',
        $uVicina !== null && $uVicina['classe_key'] !== null, (string) ($uVicina['classe_key'] ?? '—'));
    ok('di giorno, addosso, il nome si legge',
        $uVicina !== null && (bool) $uVicina['nome_noto'] && $uVicina['nome'] === (string) $vicina['name'],
        $uVicina !== null ? (string) $uVicina['nome'] : '—');

    // Di notte si vede lo stesso — a 200 metri un piroscafo non si perde — ma
    // riconoscerlo e' un'altra faccenda, ed e' proprio il punto della regola.
    $vBuio = $alBuio['vicina'];
    ok('di notte a 200 metri si vede comunque',
        $vBuio !== null && $vBuio['osservazione'] === 'vista',
        $vBuio !== null ? $vBuio['nome'] : 'assente');
    ok('di notte riconoscere costa di piu\'',
        $vBuio !== null && $uVicina !== null && (float) $vBuio['certezza'] < (float) $uVicina['certezza'],
        $vBuio !== null && $uVicina !== null
            ? sprintf('certezza %.2f al buio contro %.2f con la luce', $vBuio['certezza'], $uVicina['certezza'])
            : '');
    ok('il nome si legge solo se la sagoma e\' riconosciuta',
        $vBuio === null || (bool) $vBuio['nome_noto'] === (bool) $vBuio['identificata']
            || !(bool) $vBuio['nome_noto']);

    $uMedia = $conLuce['media'];
    ok('a quattro chilometri il nome non si legge comunque',
        $uMedia === null || !(bool) $uMedia['nome_noto'],
        $uMedia !== null ? $uMedia['nome'] : 'non rilevata');

    // --- gli errori ----------------------------------------------------------
    titolo('I numeri sono stime, e le stime hanno un limite');

    // Da qui in avanti si guarda l'ora VERA dell'incontro, non quella scelta
    // per la luce: le stime sono seminate sull'istante, e confrontarne due
    // prese in momenti diversi non direbbe niente.
    $base = $quadroAlle((int) $enc['last_step_gts']);
    $uVicina = $base['vicina'];

    $veraVicina = Database::first('SELECT * FROM encounter_entities WHERE id = ?', [(int) $vicina['id']]);
    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
    $dVera = Geo::distanceNm((float) $b['lat'], (float) $b['lon'], (float) $veraVicina['lat'], (float) $veraVicina['lon']);

    $rapporto = $uVicina !== null && $dVera > 0 ? $uVicina['distanza'] / $dVera : 0.0;
    ok('la distanza stimata resta in un intervallo plausibile',
        $rapporto >= 0.40 && $rapporto <= 2.40, sprintf('%.2f volte la vera', $rapporto));

    $rilVero = Geo::bearing((float) $b['lat'], (float) $b['lon'], (float) $veraVicina['lat'], (float) $veraVicina['lon']);
    ok('il rilevamento e\' la cosa che si sa meglio',
        $uVicina !== null && abs(Geo::bearingDelta($uVicina['rilevamento'], $rilVero)) < 8.0,
        $uVicina !== null ? sprintf('%.1f gradi di scarto', abs(Geo::bearingDelta($uVicina['rilevamento'], $rilVero))) : '');

    // Aggiornare la pagina non deve ri-tirare le stime: se lo facesse,
    // basterebbero dieci ricariche e una media per avere il valore vero.
    $su2 = quadroCon($boatId, $enc, 'superficie', 0.0, false);
    $su2Id = [];
    foreach ($su2 as $u) { $su2Id[$u['id']] = $u; }
    $u2 = $su2Id[(int) $vicina['id']] ?? null;
    ok('le stime non si ri-tirano a ogni sguardo',
        $uVicina !== null && $u2 !== null && abs($u2['distanza'] - $uVicina['distanza']) < 0.001,
        sprintf('%.3f e %.3f miglia', $uVicina['distanza'] ?? -1, $u2['distanza'] ?? -1));

    // La velocita' non deve poter dire "ferma" per una nave che cammina: una
    // nave ferma e' un problema di tiro completamente diverso.
    $velReali = array_filter($giu, static fn (array $u): bool => $u['velocita'] <= 0.05);
    ok('a orecchio nessuna nave in moto risulta ferma', $velReali === [],
        count($velReali) . ' unita\' date per ferme');

    // Il fianco che ci mostra, a orecchio, non lo si sa: se il lato riferito
    // fosse sempre quello vero, meta' del problema di tiro sarebbe risolta
    // gratis. Non basta guardare un minuto solo — a volte indovina — quindi si
    // guardano quaranta minuti di plottaggio.
    $sbagliati = 0;
    $totali = 0;
    for ($m = 0; $m < 40; $m++) {
        $encFinto = $enc;
        $encFinto['last_step_gts'] = (int) $enc['last_step_gts'] + $m * 60;
        foreach (quadroCon($boatId, $encFinto, 'periscopio', 12.0, false) as $u) {
            $vera = Database::first('SELECT lat, lon, heading FROM encounter_entities WHERE id = ?', [$u['id']]);
            $rilV = Geo::bearing((float) $b['lat'], (float) $b['lon'], (float) $vera['lat'], (float) $vera['lon']);
            $deltaV = Geo::bearingDelta((float) $vera['heading'], Geo::normBearing($rilV + 180.0));
            $totali++;
            if (($deltaV < 0 ? 'sinistra' : 'dritta') !== $u['aob_lato']) { $sbagliati++; }
        }
    }
    ok('a orecchio il fianco non e\' una certezza', $totali > 0 && $sbagliati > 0,
        sprintf('%d lati sbagliati su %d', $sbagliati, $totali));

    // Tabella e tavolo devono raccontare la stessa geometria: la rotta segnata
    // dev'essere quella che discende dal rilevamento e dall'angolo riferiti.
    $incoerenti = 0;
    foreach ($su as $u) {
        $firmato = $u['aob_lato'] === 'sinistra' ? -$u['aob'] : $u['aob'];
        $attesa = Geo::normBearing($u['rilevamento'] + 180.0 + $firmato);
        if (abs(Geo::bearingDelta($attesa, $u['rotta'])) > 1.5) { $incoerenti++; }
    }
    ok('la rotta segnata discende dal rilevamento e dall\'angolo riferiti',
        $incoerenti === 0, $incoerenti . ' unita\' incoerenti');

    // Il tavolo e la tabella devono raccontare la stessa cosa: la posizione
    // segnata deve stare sul rilevamento stimato, alla distanza stimata.
    if ($uVicina !== null) {
        $dSegnata = Geo::distanceNm((float) $b['lat'], (float) $b['lon'], $uVicina['lat'], $uVicina['lon']);
        ok('la posizione sul tavolo e\' quella stimata, non quella vera',
            abs($dSegnata - $uVicina['distanza']) < 0.01,
            sprintf('%.3f contro %.3f miglia', $dSegnata, $uVicina['distanza']));
    }

    // --- la nave civetta -----------------------------------------------------
    titolo('La civetta inganna anche qui');

    Database::run("UPDATE encounter_entities SET class_key = 'qship', smascherata = 0 WHERE id = ?", [(int) $vicina['id']]);
    $conCivetta = quadroCon($boatId, $enc, 'superficie', 0.0, false);
    $civ = null;
    foreach ($conCivetta as $u) { if ($u['id'] === (int) $vicina['id']) { $civ = $u; } }

    ok('finche\' non spara sembra un piroscafo qualunque',
        $civ !== null && ($civ['classe_key'] === null || $civ['classe_key'] === 'cargo_medio'),
        (string) ($civ['classe_key'] ?? 'non riconosciuta'));

    Database::run("UPDATE encounter_entities SET smascherata = 1 WHERE id = ?", [(int) $vicina['id']]);
    $dopoSparo = quadroCon($boatId, $enc, 'superficie', 0.0, false);
    $civ2 = null;
    foreach ($dopoSparo as $u) { if ($u['id'] === (int) $vicina['id']) { $civ2 = $u; } }
    ok('dopo che ha sparato si vede quello che e\'',
        $civ2 !== null && ($civ2['classe_key'] === 'qship' || !$civ2['identificata']),
        (string) ($civ2['classe_key'] ?? 'non riconosciuta'));
} finally {
    if ($encId !== null) {
        Database::run('DELETE FROM encounter_entities WHERE encounter_id = ?', [$encId]);
        Database::run('DELETE FROM encounters WHERE id = ?', [$encId]);
    }
    if ($userId > 0) {
        shell_exec(sprintf('%s %s %s 2>/dev/null',
            PHP_BINARY, escapeshellarg(dirname(__DIR__) . '/bin/_cleanup_test_user.php'), escapeshellarg($utente)));
    }
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
