<?php

declare(strict_types=1);

/**
 * Atlantik — i viveri.
 *
 *   php tests/test_viveri.php
 *
 * Nasce da una prova sul campo del proprietario (29/09/2026): «non si puo'
 * modificare la quantita' di viveri imbarcati; e come influenzano il gioco?».
 * Due difetti e una promessa a vuoto:
 *
 *   - la partenza riscriveva i viveri col pieno del tipo, e la scelta fatta in
 *     cantiere spariva. Con una scappatoia: si riducevano i viveri per fare
 *     spazio in stiva, e si partiva lo stesso con la dispensa piena;
 *   - i viveri toccavano soltanto il morale, e a dispensa vuota si poteva
 *     restare in mare a oltranza;
 *   - il cantiere prometteva «finiti i viveri, la missione e' finita», e non lo
 *     era; il tipo d'ordine «rientro» esisteva e nessuno lo emetteva.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Game\Outfitting;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Crew;
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

$utenti = [];
register_shutdown_function(static function () use (&$utenti): void {
    foreach ($utenti as $u) { Database::run('DELETE FROM users WHERE id = ?', [$u]); }
});
$nuovo = static function (string $etichetta) use (&$utenti): array {
    $reg = \App\Auth\Auth::register('prova viveri ' . $etichetta . substr((string) time(), -5),
        'viv' . $etichetta . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
    $uid = (int) $reg['user_id'];
    $utenti[] = $uid;
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
    \App\Game\Comandante::crea($uid, ['nome' => 'Viveri Prova ' . strtoupper($etichetta) . substr((string) time(), -4),
        'nato_il' => '1912-11-30', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
    return \App\Game\Fleet::ensureBoat($uid);
};

// ==========================================================================
titolo('La partenza rispetta l\'allestimento');
// ==========================================================================

$b = $nuovo('a');
$T = World::type((string) $b['type_key']);
$carico = Outfitting::standard($T);
$carico['viveri'] = 20;
$r = Outfitting::load($b, $T, $carico);
ok('in cantiere si imbarcano venti giorni di viveri', (bool) $r['ok'], $r['error'] ?? '');
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $b['id']]));
$dopo = (float) Database::first('SELECT provisions_days FROM boats WHERE id = ?', [(int) $b['id']])['provisions_days'];
ok('e in mare ce ne sono venti, non il pieno del tipo', abs($dopo - 20.0) < 0.001,
    sprintf('%.1f giorni (pieno del tipo: %d)', $dopo, (int) $T['provisions_days']));

// La scappatoia: zero viveri per fare spazio in stiva.
$b2 = $nuovo('b');
$carico2 = Outfitting::standard($T);
$carico2['viveri'] = 0;
Outfitting::load($b2, $T, $carico2);
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $b2['id']]));
$vuoti = (float) Database::first('SELECT provisions_days FROM boats WHERE id = ?', [(int) $b2['id']])['provisions_days'];
ok('chi lascia i viveri a terra per fare spazio parte senza viveri', $vuoti < 0.001, sprintf('%.1f giorni', $vuoti));

// Chi non ha mai allestito parte col carico standard.
$b3 = $nuovo('c');
Database::run("DELETE FROM boat_stores WHERE boat_id = ? AND item_key = 'viveri'", [(int) $b3['id']]);
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $b3['id']]));
$pieni = (float) Database::first('SELECT provisions_days FROM boats WHERE id = ?', [(int) $b3['id']])['provisions_days'];
ok('senza allestimento si parte col pieno', abs($pieni - (float) $T['provisions_days']) < 0.001, sprintf('%.1f giorni', $pieni));

// Segnalazione del 07/10/2026: «non viene ancora permesso di modificare il
// numero dei viveri all'imbarco». Il cantiere li tagliava in silenzio alla
// dotazione normale: chi ne scriveva di piu' se li ritrovava come prima.
$b4 = $nuovo('d');
$T4 = World::type((string) $b4['type_key']);
$normale = (int) $T4['provisions_days'];
$carico4 = Outfitting::standard($T4);
$carico4['viveri'] = $normale + 20;
$r4 = Outfitting::load($b4, $T4, $carico4);
$imbarcati = (float) Database::first("SELECT qty_max FROM boat_stores WHERE boat_id = ? AND item_key = 'viveri'", [(int) $b4['id']])['qty_max'];
ok('in cantiere si stipano piu\' viveri della dotazione normale', (bool) $r4['ok'] && abs($imbarcati - ($normale + 20)) < 0.001,
    sprintf('%.0f giorni, la dotazione normale e\' %d%s', $imbarcati, $normale, isset($r4['error']) ? ' — ' . $r4['error'] : ''));
Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $b4['id']]));
$inMare = (float) Database::first('SELECT provisions_days FROM boats WHERE id = ?', [(int) $b4['id']])['provisions_days'];
ok('e si parte con quelli', abs($inMare - ($normale + 20)) < 0.001, sprintf('%.0f giorni', $inMare));

$b5 = $nuovo('e');
// Per stiparne il doppio si fa spazio: ricambi, potassa e ossigeno restano a terra.
$carico5 = array_merge(Outfitting::standard($T4), ['ricambi' => 0, 'potassa' => 0, 'ossigeno' => 0]);
$carico5['viveri'] = 999;
$r5 = Outfitting::load($b5, $T4, $carico5);
$tetto = (float) Database::first("SELECT qty_max FROM boat_stores WHERE boat_id = ? AND item_key = 'viveri'", [(int) $b5['id']])['qty_max'];
ok('oltre il doppio non si va, e il cantiere lo dice', abs($tetto - Outfitting::viveriMax($T4)) < 0.001
    && (bool) array_filter($r5['note'] ?? [], static fn (string $n): bool => str_contains($n, 'Viveri')),
    sprintf('%.0f giorni; %s', $tetto, implode('; ', $r5['note'] ?? [])));

$fuori = [];
foreach (World::types() as $tipo) {
    if ((int) $tipo['playable'] === 1 && Outfitting::spazioUsato(Outfitting::standard($tipo)) > Outfitting::capacita($tipo)) {
        $fuori[] = $tipo['type_key'];
    }
}
ok('la dotazione standard sta nella stiva di ogni tipo', $fuori === [], $fuori === [] ? '' : implode(', ', $fuori));

// ==========================================================================
titolo('Il cibo fresco');
// ==========================================================================

// Tre equipaggi nelle stesse condizioni e con lo stesso morale di partenza, al
// tredicesimo, quattordicesimo e quindicesimo giorno di missione. Le settimane
// pesano sempre allo stesso modo da un giorno all'altro; il fresco finisce fra
// il quattordicesimo e il quindicesimo, e quel salto deve vedersi.
$tre = [13 => (int) $b['id'], 14 => (int) $b2['id'], 15 => (int) $b3['id']];
$morale = [];
foreach ($tre as $giorni => $id) {
    Database::run('UPDATE crew_members SET morale = 50, fatigue = 30 WHERE boat_id = ?', [$id]);
    Crew::step($id, 24.0, World::now(), ['aria' => 90, 'viveri' => 30, 'mare' => 3, 'giorni' => $giorni,
        'avarie' => 0, 'superficie' => true, 'allarme' => false]);
    $morale[$giorni] = (float) Database::first('SELECT AVG(morale) m FROM crew_members WHERE boat_id = ?', [$id])['m'];
}
$passo = $morale[13] - $morale[14];
$salto = $morale[14] - $morale[15];
ok('finito il fresco il morale cala di colpo', $salto > 2 * max(0.05, $passo),
    sprintf('dal 13 al 14: %.2f · dal 14 al 15: %.2f', $passo, $salto));

// ==========================================================================
titolo('Il digiuno');
// ==========================================================================

$bD = (int) $b['id'];
Lock::prendi('boat:' . $bD, 10);
register_shutdown_function(static fn () => Lock::lascia('boat:' . $bD));
Database::run("UPDATE crew_members SET health = 'ok', morale = 70, fatigue = 20 WHERE boat_id = ?", [$bD]);
Database::run("UPDATE boats SET provisions_days = 0.5, senza_viveri_gts = NULL, lat = 50, lon = -30, est_lat = 50, est_lon = -30,
               speed_kn = 5, ordered_speed_kn = 5, depth_m = 0, ordered_depth_m = 0, mode = 'superficie' WHERE id = ?", [$bD]);
// Due giorni di mare con mezza giornata di viveri. Il recupero di un battello
// non va oltre le 72 ore di gioco, quindi la settimana di digiuno non si
// simula a salti (ogni salto ricoprirebbe gli stessi due giorni che finiscono
// adesso): la si ottiene retrodatando l'inizio del digiuno, poi altri due giorni.
Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - 2 * 86400, $bD]);
BoatSim::advance($bD);
$bb = Database::first('SELECT provisions_days, senza_viveri_gts FROM boats WHERE id = ?', [$bD]);
ok('la dispensa e\' vuota', (float) $bb['provisions_days'] <= 0.001, (string) $bb['provisions_days']);
ok('e il battello ricorda da quando', $bb['senza_viveri_gts'] !== null
    && (int) $bb['senza_viveri_gts'] > World::now() - 2 * 86400 && (int) $bb['senza_viveri_gts'] < World::now() - 86400,
    $bb['senza_viveri_gts'] !== null ? sprintf('da %.1f giorni', (World::now() - (int) $bb['senza_viveri_gts']) / 86400) : 'mai');
$sani = (int) Database::first("SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health <> 'ok'", [$bD])['n'];
ok('il primo giorno e mezzo di digiuno non ammala nessuno', $sani === 0, "$sani malati");
Database::run('UPDATE boats SET senza_viveri_gts = ?, last_sim_gts = ? WHERE id = ?',
    [World::now() - 7 * 86400, World::now() - 2 * 86400, $bD]);
BoatSim::advance($bD);
$bb = Database::first('SELECT provisions_days, senza_viveri_gts FROM boats WHERE id = ?', [$bD]);
$malati = (int) Database::first("SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health <> 'ok'", [$bD])['n'];
$uomini = (int) Database::first('SELECT COUNT(*) n FROM crew_members WHERE boat_id = ?', [$bD])['n'];
ok('dopo una settimana di digiuno qualcuno si e\' ammalato', $malati > 0, "$malati su $uomini");
ok('ma non tutti: ci si ammala un poco al giorno', $malati < $uomini, "$malati su $uomini");
$ordini = Database::all("SELECT stato, testo FROM bdu_orders WHERE boat_id = ? AND tipo = 'rientro'", [$bD]);
ok('il BdU ha ordinato il rientro', count($ordini) >= 1);
ok('una volta sola, non a ogni passo', count($ordini) === 1, count($ordini) . ' ordini');
$kinds = array_column(Database::all('SELECT kind FROM patrol_events WHERE boat_id = ?', [$bD]), 'kind');
ok('il giornale lo racconta', in_array('viveri_finiti', $kinds, true) && in_array('ordine_bdu', $kinds, true));
$testo = Crew::descriviViveri(0.0, null, (int) $bb['senza_viveri_gts'], World::now());
ok('la centrale dice da quanto si digiuna', str_contains($testo, 'finiti da') && str_contains($testo, 'si ammalano'), $testo);

// Un uomo malato rende meno: la squadra ne risente.
$resaPrima = array_sum(Crew::aggregate($bD)['specialita']);
Database::run("UPDATE crew_members SET health = 'ok' WHERE boat_id = ?", [$bD]);
$resaSani = array_sum(Crew::aggregate($bD)['specialita']);
ok('con i malati la squadra rende meno', $resaPrima < $resaSani, sprintf('%.1f contro %.1f', $resaPrima, $resaSani));

// ==========================================================================
titolo('Il rientro');
// ==========================================================================

$base = World::port((string) $b['home_port_key']);
Database::run('UPDATE boats SET lat = ?, lon = ? WHERE id = ?', [(float) $base['lat'], (float) $base['lon'], $bD]);
Lock::lascia('boat:' . $bD);
$rientro = Patrol::dock(Database::first('SELECT * FROM boats WHERE id = ?', [$bD]));
ok('il battello attracca', (bool) ($rientro['ok'] ?? false), $rientro['error'] ?? '');
$stato = Database::first("SELECT stato FROM bdu_orders WHERE boat_id = ? AND tipo = 'rientro'", [$bD]);
ok('e l\'ordine di rientro e\' assolto', ($stato['stato'] ?? '') === 'assolto', (string) ($stato['stato'] ?? '—'));
ok('in porto nessuno digiuna', Database::first('SELECT senza_viveri_gts FROM boats WHERE id = ?', [$bD])['senza_viveri_gts'] === null);

// ==========================================================================
titolo('Quello che dice la centrale');
// ==========================================================================

$g = World::now();
ok('col fresco', Crew::descriviViveri(40.0, $g - 3 * 86400, null, $g) === '40,0 g · fresco per altri 11 giorni',
    Crew::descriviViveri(40.0, $g - 3 * 86400, null, $g));
ok('l\'ultimo giorno di fresco, al singolare', str_ends_with(Crew::descriviViveri(40.0, $g - 13 * 86400, null, $g), 'fresco per un altro giorno'),
    Crew::descriviViveri(40.0, $g - 13 * 86400, null, $g));
ok('a conserve', Crew::descriviViveri(12.5, $g - 30 * 86400, null, $g) === '12,5 g', Crew::descriviViveri(12.5, $g - 30 * 86400, null, $g));
ok('appena finiti', Crew::descriviViveri(0.0, null, $g - 3600, $g) === 'finiti', Crew::descriviViveri(0.0, null, $g - 3600, $g));

echo "\n";
if ($falliti === 0) { echo "\033[0;32mTutte le verifiche superate.\033[0m\n"; exit(0); }
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
