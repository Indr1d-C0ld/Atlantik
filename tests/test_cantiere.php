<?php

declare(strict_types=1);

/**
 * Atlantik — il cantiere della base.
 *
 *   php tests/test_cantiere.php
 *
 * Segnalazione del 21/09/2026: "non sembra che il mio battello venga riparato,
 * segnala avaria al timone ma anche dando in priorita' lo stato resta fermo".
 *
 * Il difetto era che in porto non girava niente. Le riparazioni vivevano solo
 * dentro BoatSim::advance, il battito avanzava i soli battelli in mare, e i
 * controllori saltavano l'avanzamento quando il battello era in base. Il
 * pulsante "dai priorita'" salvava davvero il suo valore: semplicemente non
 * c'era nessuno dall'altra parte a leggerlo.
 *
 * Nessuna prova se ne era accorta perche' tutte provavano le riparazioni a
 * mare, dove funzionavano benissimo. Questa prova sta in porto.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Game\Cantiere;
use App\Sim\World;

$falliti = 0;
function verifica(string $che, mixed $atteso, mixed $ottenuto): void
{
    global $falliti;
    if ($atteso === $ottenuto) {
        printf("  \033[0;32mok\033[0m    %s\n", $che);
        return;
    }
    $falliti++;
    printf("  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n", $che, var_export($atteso, true), var_export($ottenuto, true));
}

// Una prova non tocca mai roba che non ha creato lei.
$utente = 'prova cantiere ' . time();
$reg = \App\Auth\Auth::register($utente, 'cant_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
register_shutdown_function(static function () use ($userId): void {
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);
    }
});

$cmd = $userId > 0 ? \App\Game\Comandante::crea($userId, [
    'nome' => 'Cantiere Prova ' . substr((string) time(), -5), 'nato_il' => '1912-11-30',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];
$boat = $userId > 0 && ($cmd['ok'] ?? false) ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) {
    echo "  \033[0;90mniente battello: prove saltate\033[0m\n";
    exit(0);
}
$boatId = (int) $boat['id'];

// Il battito di sistema lavora sugli stessi battelli: tenuto fuori, se no
// ripara lui mentre la prova misura.
Lock::prendi('boat:' . $boatId, 5);

$rompi = static function (string $skey, string $stato = 'avaria', float $cond = 40.0) use ($boatId): void {
    Database::run(
        "UPDATE boat_systems SET state = ?, condition_pct = ?, repair_progress = 0 WHERE boat_id = ? AND skey = ?",
        [$stato, $cond, $boatId, $skey]
    );
};
$leggi = static fn (string $skey): array => Database::first(
    'SELECT state, condition_pct, repair_progress FROM boat_systems WHERE boat_id = ? AND skey = ?',
    [$boatId, $skey]
) ?? [];

Database::run("UPDATE boats SET state = 'base', repair_focus = NULL WHERE id = ?", [$boatId]);

// --- 1. il cantiere avanza, e in proporzione al tempo -----------------------
// Il timone verticale sono cinque ore-uomo. Con la resa del cantiere, mezz'ora
// di gioco non basta a finirlo: deve pero' lasciare traccia.
$rompi('timone');
Cantiere::lavora($boatId, 0.5, null);
$dopo = $leggi('timone');
verifica('mezz\'ora di cantiere lascia traccia', true, (float) $dopo['repair_progress'] > 0.0);
verifica('mezz\'ora non basta a finire il timone', 'avaria', (string) $dopo['state']);
$primoAvanzamento = (float) $dopo['repair_progress'];

Cantiere::lavora($boatId, 0.5, null);
$dopo2 = $leggi('timone');
verifica('il lavoro si accumula, non riparte da zero', true, (float) $dopo2['repair_progress'] > $primoAvanzamento);

// --- 2. con tempo a sufficienza il lavoro si chiude, e per bene -------------
Cantiere::lavora($boatId, 6.0, null);
$fine = $leggi('timone');
verifica('con le ore giuste il timone e\' riparato', 'ok', (string) $fine['state']);
verifica('il cantiere finisce il lavoro, non lo rattoppa', 100.0, (float) $fine['condition_pct']);

// --- 3. la priorita' del comandante viene rispettata ------------------------
// La mitragliera (armamento) sta in fondo all'ordine automatico, i diesel
// (propulsione) in cima: senza priorita' il cantiere partirebbe dai diesel.
$rompi('diesel_1');
$rompi('flak');
Database::run("UPDATE boats SET repair_focus = 'flak' WHERE id = ?", [$boatId]);
Cantiere::lavora($boatId, 0.4, 'flak');
verifica('con la priorita\' si lavora sulla mitragliera', true, (float) $leggi('flak')['repair_progress'] > 0.0);
verifica('e non sui diesel', 0.0, (float) $leggi('diesel_1')['repair_progress']);

// --- 4. finita la priorita', il cantiere si rimette in proprio --------------
Cantiere::lavora($boatId, 3.0, 'flak');
verifica('la mitragliera e\' riparata', 'ok', (string) $leggi('flak')['state']);
$focus = Database::first('SELECT repair_focus FROM boats WHERE id = ?', [$boatId])['repair_focus'] ?? null;
verifica('la priorita\' servita si azzera da sola', null, $focus);

// --- 5. le ore che avanzano non si buttano ----------------------------------
// Il difetto sarebbe fermarsi al primo lavoro finito: chi lascia il battello
// in porto una notte si ritroverebbe un sistema a posto e il resto intatto.
$rompi('radio');
$rompi('pompe');
Cantiere::lavora($boatId, 12.0, null);
$rimasti = (int) (Database::first(
    "SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'",
    [$boatId]
)['n'] ?? 0);
verifica('dodici ore chiudono piu\' di un lavoro', 0, $rimasti);

// --- 6. i sistemi che a mare non si toccano, qui si riparano ---------------
// Sono i due periscopi e lo scafo resistente. Prima del 21/09/2026 avevano
// repair_hours a zero: nessuno li riparava, quindi nessuno aveva mai dovuto
// decidere quanto ci volesse.
foreach (['periscopio_att', 'periscopio_osc', 'scafo'] as $skey) {
    $ore = (float) (Database::first('SELECT repair_hours FROM boat_systems WHERE boat_id = ? AND skey = ?', [$boatId, $skey])['repair_hours'] ?? 0);
    verifica("{$skey}: ha un tempo di lavorazione", true, $ore > 0.0);
}
$rompi('periscopio_att');
Cantiere::lavora($boatId, 2.0, null);
verifica('il periscopio si lavora anche se "non a mare"', true, (float) $leggi('periscopio_att')['repair_progress'] > 0.0);
Cantiere::lavora($boatId, 20.0, null);
verifica('e con le ore giuste si ripara', 'ok', (string) $leggi('periscopio_att')['state']);

// --- 7. la strada vera: il battito, da un battello in base ------------------
// Non basta che la classe funzioni: deve girare dove conta. E' il difetto
// originale — il codice delle riparazioni era giusto, semplicemente in porto
// non lo chiamava nessuno.
Lock::lascia('boat:' . $boatId);
// Mezz'ora, non quattro ore: l'idrofono e' quattro ore-uomo e con quattro ore
// di cantiere sarebbe finito, riportando l'avanzamento a zero. Si misurerebbe
// zero e si direbbe "non ha lavorato", che e' esattamente il contrario del
// vero. Con mezz'ora il lavoro resta aperto e la traccia si vede.
$rompi('idrofono');
Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [World::now() - 1800, $boatId]);
\App\Sim\BoatSim::advance($boatId);
$idro = $leggi('idrofono');
verifica('il battito lavora anche su un battello in base', true, (float) $idro['repair_progress'] > 0.0);
verifica('e mezz\'ora non basta a chiudere l\'idrofono', 'avaria', (string) $idro['state']);

// --- 8. il recupero resta limitato -----------------------------------------
// Un battello dimenticato in porto per settimane non deve rimettersi a nuovo
// in un colpo solo appena qualcuno ricarica la pagina.
$indietro = World::now() - 400 * 3600;
Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [$indietro, $boatId]);
\App\Sim\BoatSim::advance($boatId);
$dopoSalto = (int) (Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId])['last_sim_gts'] ?? 0);
$avanzato = $dopoSalto - $indietro;
verifica('il recupero in porto avanza', true, $avanzato > 0);
verifica('ma non oltre il tetto del mondo', true, $avanzato <= World::maxCatchupSeconds());
verifica('quindi non arriva fino ad adesso in un colpo', true, $dopoSalto < World::now() - 1);

// --- 9. il bacino lavora anche sui compartimenti -----------------------------
// A mare la squadra di falla svuota l'acqua, ma l'integrita' e le paratie
// sigillate le rimette a posto solo il bacino.
Lock::prendi('boat:' . $boatId, 5);
$comp = Database::first('SELECT id, name FROM boat_compartments WHERE boat_id = ? ORDER BY seq LIMIT 1', [$boatId]);
Database::run('UPDATE boat_compartments SET integrity = 45, flooding = 20, sealed = 1, repair_progress = 0 WHERE id = ?', [(int) $comp['id']]);
Cantiere::lavora($boatId, 0.6, null);
$c1 = Database::first('SELECT sealed, repair_progress FROM boat_compartments WHERE id = ?', [(int) $comp['id']]);
verifica('il bacino comincia a lavorare sul compartimento', true, (float) $c1['repair_progress'] > 0.0);
verifica('ma in mezz\'ora la paratia e\' ancora sigillata', 1, (int) $c1['sealed']);

Cantiere::lavora($boatId, 12.0, null);
$c2 = Database::first('SELECT integrity, flooding, sealed FROM boat_compartments WHERE id = ?', [(int) $comp['id']]);
verifica('con le ore giuste la paratia si riapre', 0, (int) $c2['sealed']);
verifica('e il compartimento torna integro', 100.0, (float) $c2['integrity']);
verifica('e asciutto', 0.0, (float) $c2['flooding']);

// --- 9-bis. la manutenzione dei sistemi logori ------------------------------
// Un sistema che funziona ma e' consumato si guasta piu' spesso (la condizione
// moltiplica il tasso di guasto). Tolto il ripristino alla partenza, se il
// cantiere non lo revisionasse non tornerebbe a nuovo mai piu'.
Database::run("UPDATE boat_systems SET state = 'ok', condition_pct = 70, repair_progress = 0 WHERE boat_id = ? AND skey = 'cannone'", [$boatId]);
Cantiere::lavora($boatId, 4.0, null);
$can = $leggi('cannone');
verifica('il cantiere revisiona anche cio\' che non e\' rotto', 100.0, (float) $can['condition_pct']);
verifica('e lo lascia funzionante', 'ok', (string) $can['state']);

// Ma prima viene cio' che e' rotto: la manutenzione e' l'ultima della fila.
$rompi('pompe');
Database::run("UPDATE boat_systems SET state = 'ok', condition_pct = 60, repair_progress = 0 WHERE boat_id = ? AND skey = 'flak'", [$boatId]);
Cantiere::lavora($boatId, 0.3, null);
verifica('con poche ore si lavora sul rotto, non sul logoro', true, (float) $leggi('pompe')['repair_progress'] > 0.0);
verifica('e il logoro aspetta il suo turno', 60.0, (float) $leggi('flak')['condition_pct']);
Cantiere::lavora($boatId, 6.0, null);

// --- 10. la partenza non ripara piu' niente ---------------------------------
// Era la rete di sicurezza: Damage::overhaul rimetteva tutto a nuovo nel
// momento in cui si mollavano gli ormeggi, e cosi' il tempo passato in
// banchina non contava nulla. Chi rientrava a pezzi ripartiva come nuovo.
$rompi('diesel_2', 'avaria', 30.0);
Database::run('UPDATE boat_compartments SET sealed = 1 WHERE id = ?', [(int) $comp['id']]);
Lock::lascia('boat:' . $boatId);

$prima = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$esito = \App\Game\Patrol::depart($prima);
verifica('il battello esce lo stesso: decide il comandante', true, (bool) ($esito['ok'] ?? false));
verifica('il diesel rotto resta rotto anche dopo la partenza', 'avaria', (string) $leggi('diesel_2')['state']);
$cDopo = Database::first('SELECT sealed FROM boat_compartments WHERE id = ?', [(int) $comp['id']]);
verifica('e la paratia sigillata resta sigillata', 1, (int) $cDopo['sealed']);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
