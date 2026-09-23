<?php

declare(strict_types=1);

/**
 * Atlantik — le regole che devono valere sempre.
 *
 *   php tests/test_invarianti.php                  versione compatta (~30 s)
 *   ATLANTIK_LUNGO=1 php tests/test_invarianti.php  versione lunga (~10 min)
 *
 * Le altre prove controllano scenari scelti da chi le ha scritte. Questa no:
 * mette in mare battelli con ordini casuali — anche assurdi — e salti di tempo
 * irregolari, apre incontri e ci spara dentro a caso, e dopo ogni passo
 * pretende che nessuna delle regole fisiche del gioco si sia rotta. Nafta fra
 * zero e il pieno, quota mai negativa, modalita' coerente con la quota, nessun
 * NAN, e soprattutto la conservazione dei siluri: a bordo piu' lanciati
 * uguale imbarcati.
 *
 * Nasce dalla nona revisione (23/09/2026), dove proprio quella conservazione
 * ha preso l'errore piu' grave della tornata: con lo stesso tubo ripetuto in
 * un ordine di lancio, un siluro ne diventava sei. Nessuna prova a scenario
 * poteva vederlo, perche' nessuno scenario ripeteva un tubo.
 *
 * Due avvertenze.
 *
 * Il gioco lega i suoi dadi all'ora del mondo, quindi lo stesso seme non
 * ripete mai lo stesso combattimento. Per regole che devono valere sempre va
 * bene — ogni esecuzione esplora casi nuovi — ma una violazione va riportata
 * con tutto quello che serve a rifarla: seme, istante, battello, azione.
 *
 * La prova gira sul server di esercizio, non su una copia. Il calore dei
 * settori e' mondo condiviso: qui si rimettono com'erano SOLO i settori in cui
 * sono passati i battelli e le navi della prova, piu' quelli confinanti. Una
 * prima versione ricaricava l'intera tabella, e con giocatori veri in mare
 * avrebbe cancellato il calore prodotto da loro nel frattempo.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Movement;
use App\Sim\Sectors;
use App\Sim\World;

$LUNGO = getenv('ATLANTIK_LUNGO') === '1';
$SEME  = (int) (getenv('ATLANTIK_SEME') ?: 19420923);
mt_srand($SEME);

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
$caso = static fn (float $a, float $b): float => $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
$pesca = static fn (array $a) => $a[array_rand($a)];
$nan = static fn ($v): bool => is_numeric($v) && (is_nan((float) $v) || is_infinite((float) $v));

// --- violazioni: si contano per tipo, se ne tengono i primi esempi ---------
$violazioni = [];
$esempi = [];
$viola = static function (string $cosa, string $contesto) use (&$violazioni, &$esempi): void {
    $violazioni[$cosa] = ($violazioni[$cosa] ?? 0) + 1;
    if ($violazioni[$cosa] <= 2) {
        $esempi[] = "{$cosa} — {$contesto}";
    }
};

// --- i settori toccati, e il loro stato di prima ---------------------------
$settoriPrima = [];
foreach (Database::all('SELECT * FROM sectors') as $r) {
    $settoriPrima[(string) $r['quadrat']] = $r;
}
$toccati = [];
$annota = static function (float $lat, float $lon) use (&$toccati): void {
    // Il settore e il suo intorno: un nono di grande quadrato misura 2,7 gradi
    // di latitudine per 4 di longitudine, e un punto radiogoniometrico puo'
    // cadere a qualche decina di miglia dal battello che l'ha provocato.
    foreach ([-2.7, 0.0, 2.7] as $dLat) {
        foreach ([-4.0, 0.0, 4.0] as $dLon) {
            $toccati[Sectors::key($lat + $dLat, $lon + $dLon)] = true;
        }
    }
};

$utenti = [];
register_shutdown_function(static function () use (&$utenti, &$toccati, $settoriPrima): void {
    foreach ($utenti as $u) {
        Database::run('DELETE FROM users WHERE id = ?', [$u]);
    }
    foreach (array_keys($toccati) as $k) {
        if (isset($settoriPrima[$k])) {
            $s = $settoriPrima[$k];
            Database::run('UPDATE sectors SET heat = ?, updated_gts = ?, note = ? WHERE quadrat = ?',
                [$s['heat'], $s['updated_gts'], $s['note'], $k]);
        } else {
            Database::run('DELETE FROM sectors WHERE quadrat = ?', [$k]);
        }
    }
});

$nuovoBattello = static function (string $etichetta) use (&$utenti): ?int {
    // Il nome utente sta dentro i 32 caratteri: «prova inv c3 137054».
    $nome = 'prova inv ' . $etichetta . ' ' . substr((string) time(), -6);
    $reg = \App\Auth\Auth::register($nome, 'inv' . preg_replace('/\W/', '', $etichetta) . '_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
    $uid = (int) ($reg['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $utenti[] = $uid;
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
    \App\Game\Comandante::crea($uid, ['nome' => 'Invarianti ' . ucfirst($etichetta) . ' ' . substr((string) time(), -4),
        'nato_il' => '1912-11-30', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
    $b = \App\Game\Fleet::ensureBoat($uid);
    Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $b['id']]));
    return (int) $b['id'];
};

// Le regole sul battello, valide in crociera e in combattimento.
$regoleBattello = static function (int $bid, string $ctx) use ($viola, $nan): void {
    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$bid]);
    $T = World::type((string) $b['type_key']);
    foreach ($b as $k => $v) {
        if ($nan($v)) { $viola("NAN in boats.$k", $ctx); }
    }
    $vivo = $b['state'] !== 'perduto';
    $vmax = max((float) $T['speed_surf_kn'], (float) $T['speed_sub_kn']);
    $chk = static function (bool $regola, string $cosa, string $valore) use ($viola, $ctx): void {
        if (!$regola) { $viola($cosa, "$ctx: $valore"); }
    };
    $chk(abs((float) $b['lat']) <= 90 && abs((float) $b['lon']) <= 180, 'coordinate fuori dal globo', "{$b['lat']},{$b['lon']}");
    $chk((float) $b['heading'] >= 0 && (float) $b['heading'] <= 360, 'rotta fuori da [0,360]', (string) $b['heading']);
    $chk((float) $b['speed_kn'] >= 0 && (float) $b['speed_kn'] <= $vmax + 0.5, 'velocita\' oltre il tipo', "{$b['speed_kn']} su $vmax");
    $chk((float) $b['depth_m'] >= 0, 'quota negativa', (string) $b['depth_m']);
    if ($vivo) {
        $chk((float) $b['depth_m'] <= (float) $T['crush_depth_max_m'], 'vivo oltre il collasso massimo', (string) $b['depth_m']);
        $atteso = Movement::modeForDepth((float) $b['depth_m']);
        $chk($b['mode'] === $atteso, 'modalita\' incoerente con la quota', "{$b['mode']} a {$b['depth_m']} m");
    }
    $chk((float) $b['fuel_t'] >= 0 && (float) $b['fuel_t'] <= (float) $T['fuel_t'] + 0.01, 'nafta fuori da [0, pieno]', (string) $b['fuel_t']);
    foreach (['battery_pct', 'air_pct', 'co2_pct', 'hull_integrity'] as $c) {
        $chk((float) $b[$c] >= 0 && (float) $b[$c] <= 100.0001, "$c fuori da [0,100]", (string) $b[$c]);
    }
    $chk((float) $b['provisions_days'] >= 0, 'viveri negativi', (string) $b['provisions_days']);
    $chk((int) $b['last_sim_gts'] <= World::now() + 1, 'orologio del battello nel futuro', (string) ((int) $b['last_sim_gts'] - World::now()));
    foreach (Database::all('SELECT name, competence, fatigue, morale FROM crew_members WHERE boat_id = ?', [$bid]) as $m) {
        foreach (['competence', 'fatigue', 'morale'] as $c) {
            $chk((float) $m[$c] >= 0 && (float) $m[$c] <= 100.0001, "equipaggio.$c fuori da [0,100]", "{$m['name']} {$m[$c]}");
        }
    }
    foreach (Database::all('SELECT skey, state, condition_pct, repair_progress FROM boat_systems WHERE boat_id = ?', [$bid]) as $s) {
        $chk((float) $s['condition_pct'] >= 0 && (float) $s['condition_pct'] <= 100.0001, 'condizione di un sistema fuori da [0,100]', "{$s['skey']} {$s['condition_pct']}");
        $chk((float) $s['repair_progress'] >= 0, 'avanzamento di riparazione negativo', "{$s['skey']} {$s['repair_progress']}");
    }
    foreach (Database::all('SELECT ckey, integrity, flooding, fire FROM boat_compartments WHERE boat_id = ?', [$bid]) as $c) {
        foreach (['integrity', 'flooding', 'fire'] as $f) {
            $chk((float) $c[$f] >= 0 && (float) $c[$f] <= 100.0001, "compartimento.$f fuori da [0,100]", "{$c['ckey']} {$c[$f]}");
        }
    }
    foreach (Database::all('SELECT item_key, qty, qty_max FROM boat_stores WHERE boat_id = ?', [$bid]) as $s) {
        $chk((float) $s['qty'] >= 0 && (float) $s['qty'] <= (float) $s['qty_max'] + 0.001, 'scorta fuori da [0, massimo]', "{$s['item_key']} {$s['qty']}");
    }
    $doppi = Database::all(
        "SELECT posizione, tubo, COUNT(*) n FROM boat_torpedoes WHERE boat_id = ? AND stato IN ('pronto','in_carica')
            AND posizione LIKE 'tubo%' GROUP BY posizione, tubo HAVING n > 1",
        [$bid]
    );
    $chk($doppi === [], 'due siluri nello stesso tubo', json_encode($doppi));
};

// Le righe di giornale: niente segnaposti rimasti, niente numeri impossibili.
$regoleGiornale = static function (array $bids) use ($viola): int {
    $ids = implode(',', array_map('intval', $bids)) ?: '0';
    $righe = Database::all("SELECT kind, text FROM patrol_events WHERE boat_id IN ($ids)");
    foreach ($righe as $e) {
        $t = (string) $e['text'];
        if ($t === '' || preg_match('/%[0-9.]*[sdfu]\b|\bNA[Nn]\b|\bINF\b|\bArray\b|\{[a-z_]+\}|60,0\'|\b1 (perduti|affondate|siluri|uomini|navi|scorte|cariche|colpi)\b/', $t)) {
            $viola('riga di giornale malformata', "[{$e['kind']}] " . mb_substr($t, 0, 110));
        }
    }
    return count($righe);
};

printf("  \033[0;90mversione %s, seme %d — per rifarla: ATLANTIK_SEME=%d%s php tests/test_invarianti.php\033[0m\n",
    $LUNGO ? 'lunga' : 'compatta', $SEME, $SEME, $LUNGO ? ' ATLANTIK_LUNGO=1' : '');

// ==========================================================================
titolo('In crociera: ordini a caso, tempo a salti');
// ==========================================================================

$nBattelli = $LUNGO ? 8 : 4;
$turni     = $LUNGO ? 60 : 12;
$oreMax    = $LUNGO ? 24.0 : 8.0;
$punti = [[50, -30], [45, -40], [55, -20], [42, -25], [58, -35], [48, -15], [52, -45], [46, -28]];
$crociera = [];
for ($i = 0; $i < $nBattelli; $i++) {
    $bid = $nuovoBattello("c$i");
    if ($bid === null) { continue; }
    [$la, $lo] = $punti[$i % count($punti)];
    Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ? WHERE id = ?', [$la, $lo, $la, $lo, $bid]);
    $crociera[] = $bid;
}

$passi = 0;
$ore = 0.0;
for ($turno = 0; $turno < $turni; $turno++) {
    foreach ($crociera as $bid) {
        $b = Database::first('SELECT * FROM boats WHERE id = ?', [$bid]);
        if ($b['state'] !== 'mare') { continue; }
        $T = World::type((string) $b['type_key']);
        // Per lo piu' ordini sensati, ogni tanto no: l'API li deve tagliare.
        $vel = mt_rand(0, 9) === 0 ? $caso(-20, 60) : $caso(0, (float) $T['speed_surf_kn']);
        $quota = mt_rand(0, 40) === 0 ? $caso(-50, -1)
            : (mt_rand(0, 2) === 0 ? 0.0 : $caso(0, (float) $T['test_depth_m'] * 0.95));
        Patrol::orders($b, $vel, $quota, mt_rand(0, 3) === 0);
        Database::run('UPDATE boats SET ordered_heading = ? WHERE id = ?', [round($caso(0, 359.9), 1), $bid]);

        $salto = mt_rand(0, 4) === 0 ? $caso(0.1, 1.0) : $caso(1.0, $oreMax);
        $ore += $salto;
        $ctx = sprintf('battello %d, turno %d, istante %d', $bid, $turno, World::now());
        Lock::prendi('boat:' . $bid, 10);
        Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - (int) ($salto * 3600), $bid]);
        try {
            $passi += (int) BoatSim::advance($bid)['steps'];
        } catch (\Throwable $ex) {
            $viola('eccezione nell\'avanzamento', $ctx . ' — ' . get_class($ex) . ': ' . mb_substr($ex->getMessage(), 0, 100));
        }
        Lock::lascia('boat:' . $bid);
        $b = Database::first('SELECT lat, lon FROM boats WHERE id = ?', [$bid]);
        $annota((float) $b['lat'], (float) $b['lon']);
        $regoleBattello($bid, $ctx);
    }
}
$righeCrociera = $regoleGiornale($crociera);

ok('la prova ha navigato davvero', $passi > 0 && $righeCrociera > 0,
    sprintf('%d battelli, %.0f ore di gioco, %d sotto-passi, %d righe di giornale', count($crociera), $ore, $passi, $righeCrociera));

// ==========================================================================
titolo('In combattimento: incontri costruiti a mano, azioni a caso');
// ==========================================================================

// Incontri SINTETICI: le unita' non hanno una nave vera collegata, cosi' niente
// tocca il traffico in esercizio. Il resto — siluri, scorte, affondamenti,
// giornale — gira sul codice vero.
$nIncontri = $LUNGO ? 10 : 3;
$azioni    = $LUNGO ? 120 : 40;
$mercantili = ['cargo_medio', 'cargo_grande', 'petroliera', 'frigorifera'];
$scorteCl   = ['corvetta_flower', 'ct_town', 'fregata_river'];
$classi = array_column(Database::all('SELECT class_key FROM ship_classes'), 'class_key');
$mercantili = array_values(array_intersect($mercantili, $classi)) ?: [$classi[0]];
$scorteCl   = array_values(array_intersect($scorteCl, $classi)) ?: [$classi[0]];

$combattimento = [];
$lanciRiusciti = 0;
$lanciRipetuti = 0;
$passiTattici = 0;
$corseChiuse = 0;
for ($n = 0; $n < $nIncontri; $n++) {
    $bid = $nuovoBattello("k$n");
    if ($bid === null) { continue; }
    $combattimento[] = $bid;
    $pat = Patrol::corrente($bid);
    $gts = World::now();
    $la = 50.0 + $n * 0.7;
    $lo = -30.0 - $n * 0.9;
    Database::run('UPDATE boats SET lat = ?, lon = ?, est_lat = ?, est_lon = ?, depth_m = 12, ordered_depth_m = 12,
                   mode = "periscopio", speed_kn = 3, ordered_speed_kn = 3, last_sim_gts = ? WHERE id = ?',
        [$la, $lo, $la, $lo, $gts, $bid]);
    Database::run('UPDATE boat_stores SET qty = qty_max WHERE boat_id = ?', [$bid]);
    $imbarcati = (int) (Database::first("SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ? AND stato <> 'lanciato'", [$bid])['n'] ?? 0);

    Database::run(
        'INSERT INTO encounters (boat_id, patrol_id, stato, allarme, started_gts, last_step_gts, last_step_real, finestra_fine, ratio)
         VALUES (?, ?, "avvicinamento", 0, ?, ?, ?, ?, 1)',
        [$bid, (int) $pat['id'], $gts, $gts, time(), $gts + 6 * 3600]
    );
    $encId = Database::lastInsertId();
    Database::run('UPDATE boats SET encounter_id = ?, battle_stations = 1 WHERE id = ?', [$encId, $bid]);
    $rotta = $caso(0, 359);
    for ($k = 0; $k < 5; $k++) {
        $cl = $pesca($mercantili);
        [$el, $eo] = Geo::destination($la, $lo, $caso(0, 359), $caso(0.6, 2.5));
        Database::run(
            'INSERT INTO encounter_entities (encounter_id, class_key, name, ruolo, lat, lon, heading, speed_kn, grt, integrita, allagamento, incendio)
             VALUES (?, ?, ?, "mercantile", ?, ?, ?, 8, ?, 100, 0, 0)',
            [$encId, $cl, "Prova Mercantile $n-$k", $el, $eo, $rotta, (int) \App\Sim\Traffic::classe($cl)['grt']]
        );
    }
    for ($k = 0; $k < 2; $k++) {
        $cl = $pesca($scorteCl);
        $c = \App\Sim\Traffic::classe($cl);
        [$el, $eo] = Geo::destination($la, $lo, $caso(0, 359), $caso(1.5, 4.5));
        Database::run(
            'INSERT INTO encounter_entities (encounter_id, class_key, name, ruolo, lat, lon, heading, speed_kn, grt, dc_residue, manovra)
             VALUES (?, ?, ?, "scorta", ?, ?, ?, 14, ?, ?, "stazione")',
            [$encId, $cl, "Prova Scorta $n-$k", $el, $eo, $rotta, (int) $c['grt'], (int) $c['dc_carica']]
        );
    }
    $annota($la, $lo);

    for ($a = 0; $a < $azioni; $a++) {
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        $b = Database::first('SELECT * FROM boats WHERE id = ?', [$bid]);
        if ($enc['stato'] === 'concluso' || $b['state'] !== 'mare') { break; }
        $bersagli = array_values(array_filter(Encounter::entita($encId), static fn ($e) => $e['ruolo'] !== 'scorta'));
        // I primi due gesti di ogni incontro non sono lasciati al caso.
        //
        // Il primo e' un lancio fatto come si deve: cosi' ogni esecuzione lancia
        // almeno un siluro, e la regola di conservazione ha sempre qualcosa da
        // contare. Il secondo e' un lancio con i tubi RIPETUTI apposta, a quota
        // periscopica e coi tubi carichi: e' l'input che moltiplicava i siluri.
        // La prima stesura lo lasciava ai tubi estratti a caso, e rimettendo il
        // difetto la prova restava verde tre volte su tre — col seme fisso i
        // lanci riusciti non ripetevano mai un tubo pronto. Una rete che non
        // tocca il buco per cui e' stata tesa.
        $azione = match ($a) {
            0 => 'lancio_pulito',
            1 => 'lancio_ripetuto',
            default => $pesca(['passo', 'passo', 'passo', 'lancia', 'lancia', 'manovra', 'manovra', 'bold', 'periscopio', 'cannone']),
        };
        $ctx = sprintf('incontro %d, azione %d (%s), istante %d', $encId, $a, $azione, (int) $enc['last_step_gts']);
        $esito = null;
        try {
            switch ($azione) {
                case 'lancio_pulito':
                    if ($bersagli !== []) {
                        $esito = Encounter::lancia($enc, $b, ['entity_id' => (int) $bersagli[0]['id'], 'tubi' => [1],
                            'spoletta' => 'contatto', 'quota' => 4, 'ventaglio' => 0], (int) $enc['last_step_gts']);
                    }
                    break;
                case 'lancio_ripetuto':
                    if ($bersagli !== []) {
                        $esito = Encounter::lancia($enc, $b, ['entity_id' => (int) $bersagli[0]['id'], 'tubi' => [2, 2, 3, 3, 2],
                            'spoletta' => 'contatto', 'quota' => 4, 'ventaglio' => 1], (int) $enc['last_step_gts']);
                        if (($esito['ok'] ?? false)) { $lanciRipetuti++; }
                    }
                    break;
                case 'passo':
                    // Il tempo passa arretrando gli orologi, mai chiedendo il futuro.
                    $sec = (int) $caso(10, 600);
                    Database::run('UPDATE encounters SET last_step_gts = last_step_gts - ?, last_step_real = last_step_real - ? WHERE id = ?',
                        [$sec, $sec, $encId]);
                    Database::run('UPDATE torpedo_runs SET lanciato_gts = lanciato_gts - ? WHERE encounter_id = ?', [$sec, $encId]);
                    $passiTattici += (int) Encounter::step($encId)['passi'];
                    break;
                case 'lancia':
                    if ($bersagli !== []) {
                        // Tubi a caso, ANCHE ripetuti: e' la ripetizione che moltiplicava i siluri.
                        $esito = Encounter::lancia($enc, $b, [
                            'entity_id' => (int) $pesca($bersagli)['id'],
                            'tubi' => [mt_rand(1, 6), mt_rand(1, 6), mt_rand(1, 6)],
                            'spoletta' => $pesca(['contatto', 'magnetica', 'boh']),
                            'quota' => $caso(-5, 30), 'ventaglio' => $caso(-2, 10),
                            'stima' => mt_rand(0, 1) ? null : ['aob' => $caso(0, 180), 'distanza' => $caso(0.05, 3), 'velocita' => $caso(0, 20)],
                        ], (int) $enc['last_step_gts']);
                    }
                    break;
                case 'manovra':
                    $esito = Patrol::orders($b, $caso(0, 18), $pesca([0.0, 12.0, $caso(20, 180)]), (bool) mt_rand(0, 1));
                    break;
                case 'bold':
                    $esito = Encounter::bold($enc, $b, (int) $enc['last_step_gts']);
                    break;
                case 'periscopio':
                    $esito = Encounter::periscopio($b, (bool) mt_rand(0, 1), (int) $enc['last_step_gts']);
                    break;
                case 'cannone':
                    if ($bersagli !== []) {
                        $esito = Encounter::cannone($enc, $b, (int) $pesca($bersagli)['id'], mt_rand(-3, 40), (int) $enc['last_step_gts']);
                    }
                    break;
            }
        } catch (\Throwable $ex) {
            $viola("eccezione in $azione", $ctx . ' — ' . get_class($ex) . ': ' . mb_substr($ex->getMessage(), 0, 100)
                . ' @' . basename($ex->getFile()) . ':' . $ex->getLine());
        }
        if (in_array($azione, ['lancia', 'lancio_pulito'], true) && is_array($esito) && ($esito['ok'] ?? false)) {
            $lanciRiusciti++;
        }

        // --- le regole ---
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        $b = Database::first('SELECT * FROM boats WHERE id = ?', [$bid]);
        $regoleBattello($bid, $ctx);
        foreach (['affondate', 'grt_affondato', 'cariche_subite'] as $c) {
            if ((float) $enc[$c] < 0) { $viola("incontro.$c negativo", $ctx); }
        }
        if ($enc['stato'] === 'concluso' && (int) ($b['encounter_id'] ?? 0) === $encId) {
            $viola('incontro concluso ma battello ancora legato', $ctx);
        }
        if ($enc['stato'] !== 'concluso' && $b['state'] === 'mare' && (int) ($b['encounter_id'] ?? 0) !== $encId) {
            $viola('incontro aperto ma battello slegato', $ctx);
        }
        foreach (Database::all('SELECT * FROM encounter_entities WHERE encounter_id = ?', [$encId]) as $e) {
            foreach ($e as $k => $v) {
                if ($nan($v)) { $viola("NAN in entita'.$k", $ctx); }
            }
            foreach (['integrita', 'allagamento', 'incendio'] as $c) {
                if ((float) $e[$c] < 0 || (float) $e[$c] > 100.0001) { $viola("entita'.$c fuori da [0,100]", "$ctx {$e['name']} {$e[$c]}"); }
            }
            if ((float) $e['contatto'] < 0 || (float) $e['contatto'] > 1.0001) { $viola('contatto fuori da [0,1]', "$ctx {$e['contatto']}"); }
            if ((int) $e['dc_residue'] < 0) { $viola('cariche residue negative', "$ctx {$e['name']}"); }
            if ((float) $e['speed_kn'] < 0) { $viola('velocita\' di un\'unita\' negativa', "$ctx {$e['speed_kn']}"); }
            $annota((float) $e['lat'], (float) $e['lon']);
        }
        // La conservazione dei siluri. I «lanciato» senza siluro sono segnaposti
        // di tubo vuoto, che la ricarica cancella: non si contano.
        $aBordo = (int) (Database::first("SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ? AND stato <> 'lanciato'", [$bid])['n'] ?? 0);
        $corse = (int) (Database::first('SELECT COUNT(*) n FROM torpedo_runs WHERE boat_id = ?', [$bid])['n'] ?? 0);
        if ($aBordo + $corse !== $imbarcati) {
            $viola('siluri non conservati', "$ctx: $aBordo a bordo + $corse corse, ne erano imbarcati $imbarcati");
        }
        $doppi = Database::all('SELECT nome, COUNT(*) n FROM sinkings WHERE boat_id = ? GROUP BY nome HAVING n > 1', [$bid]);
        if ($doppi !== []) { $viola('stessa unita\' accreditata due volte', "$ctx " . json_encode($doppi)); }
        $p = Database::first('SELECT affondate, grt_affondato FROM patrols WHERE id = ?', [(int) $pat['id']]);
        $s = Database::first('SELECT COUNT(*) n, COALESCE(SUM(grt), 0) g FROM sinkings WHERE patrol_id = ?', [(int) $pat['id']]);
        if ((int) $p['affondate'] !== (int) $s['n'] || (int) $p['grt_affondato'] !== (int) $s['g']) {
            $viola('missione e albo degli affondamenti non tornano', "$ctx: {$p['affondate']}/{$p['grt_affondato']} contro {$s['n']}/{$s['g']}");
        }
    }
    $corseChiuse += (int) (Database::first("SELECT COUNT(*) n FROM torpedo_runs WHERE boat_id = ? AND esito <> 'in_corsa'", [$bid])['n'] ?? 0);
}
$righeCombattimento = $regoleGiornale($combattimento);

ok('la prova ha combattuto davvero', $lanciRiusciti > 0 && $passiTattici > 0 && $corseChiuse > 0,
    sprintf('%d incontri, %d lanci riusciti, %d corse concluse, %d passi tattici, %d righe di giornale',
        count($combattimento), $lanciRiusciti, $corseChiuse, $passiTattici, $righeCombattimento));
ok('e ha lanciato coi tubi ripetuti', $lanciRipetuti === count($combattimento),
    sprintf('%d su %d incontri', $lanciRipetuti, count($combattimento)));

// ==========================================================================
titolo('Le regole');
// ==========================================================================

ok('nessuna regola rotta', $violazioni === [],
    $violazioni === [] ? 'crociera e combattimento' : implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($violazioni), $violazioni)));
foreach ($esempi as $e) {
    echo "        \033[0;90m· {$e}\033[0m\n";
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
