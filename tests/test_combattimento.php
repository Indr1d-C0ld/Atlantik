<?php

declare(strict_types=1);

/**
 * Atlantik — le tre meccaniche di combattimento che nessuno provava.
 *
 *   php tests/test_combattimento.php
 *
 * Nascono dall'audit del 22/09/2026, che ha censito quali funzionalita'
 * dichiarate nel README non fossero toccate da nessuna prova. Erano tre, e
 * tutte e tre stanno nel momento peggiore della partita — quando le scorte ti
 * hanno in mano:
 *
 *   il Bold, la cartuccia che riempie l'acqua di bolle e fa attaccare l'ASDIC
 *   a una nuvola invece che a uno scafo;
 *
 *   il rivelatore radar (Metox, Naxos), che canta quando un aereo ci illumina
 *   e regala i secondi per andare sotto;
 *
 *   lo sganciamento, cioe' la sola via d'uscita che non passa dal fondo.
 *
 * Tutte e tre erano implementate e funzionanti: l'audit le ha trovate giuste.
 * Il rischio non era che fossero rotte, era che niente le tenesse tali.
 *
 * L'incontro qui si costruisce a mano invece di pescare un convoglio vero: cosi'
 * la prova non dipende da che cosa c'e' in mare in questo momento, e le scorte
 * hanno i valori di contatto che servono a distinguere un caso dall'altro.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Sim\Encounter;
use App\Sim\World;

$falliti = 0;
function verifica(string $che, mixed $atteso, mixed $ottenuto): void
{
    global $falliti;
    if ($atteso === $ottenuto) { printf("  \033[0;32mok\033[0m    %s\n", $che); return; }
    $falliti++;
    printf("  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n",
        $che, var_export($atteso, true), var_export($ottenuto, true));
}
function titolo(string $t): void { printf("\n  \033[0;90m%s\033[0m\n", $t); }

$utente = 'prova combat ' . time();
$reg = \App\Auth\Auth::register($utente, 'cmb_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$userId = (int) ($reg['user_id'] ?? 0);
if ($userId > 0) {
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$userId]);
}
register_shutdown_function(static function () use ($userId): void {
    if ($userId > 0) { Database::run('DELETE FROM users WHERE id = ?', [$userId]); }
});

$cmd = $userId > 0 ? \App\Game\Comandante::crea($userId, [
    'nome' => 'Combat Prova ' . substr((string) time(), -5), 'nato_il' => '1912-11-30',
    'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient',
]) : ['ok' => false];
$boat = $userId > 0 && ($cmd['ok'] ?? false) ? \App\Game\Fleet::ensureBoat($userId) : null;
if ($boat === null) { echo "  \033[0;90mniente battello: prova saltata\033[0m\n"; exit(0); }
$boatId = (int) $boat['id'];
Lock::prendi('boat:' . $boatId, 5);
register_shutdown_function(static fn () => Lock::lascia('boat:' . $boatId));

$gts = World::now();
Database::run(
    'UPDATE boats SET state = "mare", mode = "immersione", depth_m = 60, lat = 48.0, lon = -20.0,
            last_sim_gts = ? WHERE id = ?',
    [$gts, $boatId]
);

// Un incontro costruito a mano: due scorte, una che ci sta sopra e una che
// ci ha appena persi. Servono diversi perche' il Bold inganna in proporzione
// inversa a quanto e' saldo il contatto.
Database::run(
    'INSERT INTO encounters (boat_id, patrol_id, stato, allarme, started_gts, last_step_gts,
                             last_step_real, finestra_fine, ratio)
     VALUES (?, NULL, "evasione", 1, ?, ?, ?, ?, 1)',
    [$boatId, $gts, $gts, time(), $gts + 4 * 3600]
);
$encId = Database::lastInsertId();
Database::run('UPDATE boats SET encounter_id = ? WHERE id = ?', [$encId, $boatId]);
$scorta = static function (string $nome, float $contatto) use ($encId, $gts): int {
    Database::run(
        'INSERT INTO encounter_entities (encounter_id, class_key, name, ruolo, lat, lon, heading,
                                         speed_kn, grt, integrita, stato, contatto, manovra, dc_residue)
         VALUES (?, "corvetta_flower", ?, "scorta", 48.02, -20.0, 180, 14, 950, 100, "in_mare", ?, "caccia", 40)',
        [$encId, $nome, $contatto]
    );
    return Database::lastInsertId();
};
$addosso = $scorta('Scorta Addosso', 0.90);
$lontana = $scorta('Scorta Incerta', 0.20);
$enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);

$contatto = static fn (int $id): float => (float) (Database::first(
    'SELECT contatto FROM encounter_entities WHERE id = ?', [$id]
)['contatto'] ?? -1);
$boldInStiva = static fn (): float => (float) (\App\Game\Outfitting::stores($GLOBALS['boatId'] ?? 0)['bold'] ?? 0);
$GLOBALS['boatId'] = $boatId;

titolo('Bold — la nuvola di bolle');

// 1. In superficie non si spara: il Bold si lancia da un tubo in immersione.
Database::run('UPDATE boats SET mode = "superficie" WHERE id = ?', [$boatId]);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$r = Encounter::bold($enc, $b, $gts);
verifica('in superficie il Bold si rifiuta', false, (bool) $r['ok']);

// 2. Senza cartucce non si spara.
Database::run('UPDATE boats SET mode = "immersione" WHERE id = ?', [$boatId]);
Database::run("UPDATE boat_stores SET qty = 0 WHERE boat_id = ? AND item_key = 'bold'", [$boatId]);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$r = Encounter::bold($enc, $b, $gts);
verifica('senza cartucce il Bold si rifiuta', false, (bool) $r['ok']);

// 3. Con le cartucce si spara, e se ne consuma una sola.
Database::run("UPDATE boat_stores SET qty = 5 WHERE boat_id = ? AND item_key = 'bold'", [$boatId]);
$prima = (float) (\App\Game\Outfitting::stores($boatId)['bold'] ?? 0);
$b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
$r = Encounter::bold($enc, $b, $gts);
$dopo = (float) (\App\Game\Outfitting::stores($boatId)['bold'] ?? 0);
verifica('in immersione e con le cartucce, il Bold parte', true, (bool) $r['ok']);
verifica('e se ne consuma esattamente una', 1.0, round($prima - $dopo, 3));

// 4. Chi ci sta sopra non abbocca facilmente, chi ha il contatto incerto si'.
//    Non si pretende l'esito di un singolo tiro, che e' casuale: si spara piu'
//    volte e si guarda che la scorta incerta ceda prima di quella addosso.
Database::run('UPDATE encounter_entities SET contatto = 0.90 WHERE id = ?', [$addosso]);
Database::run('UPDATE encounter_entities SET contatto = 0.20 WHERE id = ?', [$lontana]);
Database::run("UPDATE boat_stores SET qty = 40 WHERE boat_id = ? AND item_key = 'bold'", [$boatId]);
$cedutaIncerta = 0; $cedutaAddosso = 0;
for ($i = 0; $i < 30; $i++) {
    Database::run('UPDATE encounter_entities SET contatto = 0.90, manovra = "caccia" WHERE id = ?', [$addosso]);
    Database::run('UPDATE encounter_entities SET contatto = 0.20, manovra = "caccia" WHERE id = ?', [$lontana]);
    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
    Encounter::bold($enc, $b, $gts + $i * 60);
    if ($contatto($lontana) < 0.20) { $cedutaIncerta++; }
    if ($contatto($addosso) < 0.90) { $cedutaAddosso++; }
}
verifica('il Bold inganna qualcuno', true, $cedutaIncerta + $cedutaAddosso > 0);
verifica('e inganna piu\' spesso chi ha il contatto incerto', true, $cedutaIncerta > $cedutaAddosso);

// 5. Chi abbocca perde il contatto e si mette a cercare.
Database::run('UPDATE encounter_entities SET contatto = 0.20, manovra = "caccia" WHERE id = ?', [$lontana]);
for ($i = 0; $i < 20 && $contatto($lontana) >= 0.20; $i++) {
    $b = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
    Encounter::bold($enc, $b, $gts + 5000 + $i * 60);
}
$e = Database::first('SELECT contatto, manovra FROM encounter_entities WHERE id = ?', [$lontana]);
verifica('chi abbocca perde gran parte del contatto', true, (float) $e['contatto'] < 0.20);
verifica('e passa dalla caccia alla ricerca', 'ricerca', (string) $e['manovra']);

titolo('Rivelatore radar — i secondi che salvano');

// L'apparato non e' un ornamento: se canta, l'attacco aereo non avviene.
// Qui si verifica che il catalogo lo dichiari e che il motore lo legga.
$app = Database::all("SELECT ukey, effetto, valore FROM upgrade_types WHERE effetto = 'avviso_aereo' ORDER BY valore");
verifica('a catalogo ci sono due rivelatori radar', 2, count($app));
verifica('il Metox avvisa meno del Naxos', true,
    count($app) === 2 && (float) $app[0]['valore'] < (float) $app[1]['valore']);
$sorgente = file_get_contents(__DIR__ . '/../src/Sim/BoatSim.php');
verifica('l\'avviso scatta solo se l\'aereo usa il radar', true,
    (bool) preg_match('/avviso_aereo.{0,200}cls\[.radar.\]/s', $sorgente));
verifica('e se scatta, l\'attacco aereo viene saltato', true,
    str_contains($sorgente, 'if (!$avvisati && $s[\'depth\'] < 8.0)'));

titolo('Sganciamento — la via d\'uscita che non passa dal fondo');

// Far passare il tempo di un incontro senza chiedere il futuro: si arretrano
// tutti e due gli orologi, quello di gioco e quello reale, e il passo recupera.
//
// Le prime versioni di questa prova chiamavano step($encId, $gts + 600). Due
// guai, trovati dall'audit del 23/09/2026: l'incontro nasceva con
// last_step_real a zero, e su un incontro cosi' il primo passo si limita ad
// accendere l'orologio e restituisce zero passi — la verifica «col contatto
// saldo non ci si sgancia» passava senza che succedesse niente. E quando
// Encounter::step ha avuto la guardia che non lo lascia passare davanti al
// mondo, i passi partivano o no secondo quanti decimi di secondo reali la
// prova aveva impiegato ad arrivare fin li'.
$passaTempo = static function (int $secondi) use ($encId): void {
    Database::run(
        'UPDATE encounters SET last_step_gts = last_step_gts - ?, last_step_real = last_step_real - ? WHERE id = ?',
        [$secondi, $secondi, $encId]
    );
    Encounter::step($encId);
};


// Le scorte stanno a OTTO miglia in tutte e due le prove che seguono, e la
// distanza non si tocca piu'. E' il punto della cosa: oltre le quattro miglia
// che lo sganciamento richiede, ma sotto le quattordici oltre le quali
// l'incontro finisce da solo per lontananza.
//
// Il primo tentativo di questa prova le metteva a venti miglia, e passava:
// solo che passava perche' l'incontro si chiudeva per distanza, non perche'
// ci si fosse sganciati. Il difetto iniettato apposta — sganciamento reso
// impossibile — non la faceva fallire. Tenendo ferma la distanza, l'unica
// cosa che cambia fra i due casi e' il contatto, ed e' quello che si misura.
Database::run('UPDATE boats SET lat = 48.02, lon = -20.0 WHERE id = ?', [$boatId]);
Database::run('UPDATE encounter_entities SET lat = 48.155, lon = -20.0 WHERE encounter_id = ?', [$encId]);
Database::run('UPDATE encounters SET allarme = 1, stato = "evasione" WHERE id = ?', [$encId]);

// 1. Contatto ancora saldo: da li' non ci si sfila.
Database::run('UPDATE encounter_entities SET contatto = 0.60, manovra = "caccia" WHERE encounter_id = ?', [$encId]);
$passaTempo(600);
$e = Database::first('SELECT stato FROM encounters WHERE id = ?', [$encId]);
verifica('col contatto saldo non ci si sgancia', true, (string) $e['stato'] !== 'concluso');

// 2. Stessa distanza, contatto perso: adesso si'.
//
// Le scorte si rimettono a otto miglia: nel passo di prima, col contatto
// saldo, hanno cacciato e si sono avvicinate — che e' proprio quello che
// devono fare — e da sotto le quattro miglia sganciarsi non si puo'.
Database::run('UPDATE encounter_entities SET lat = 48.155, lon = -20.0 WHERE encounter_id = ?', [$encId]);
Database::run('UPDATE boats SET lat = 48.02, lon = -20.0 WHERE id = ?', [$boatId]);
Database::run('UPDATE encounter_entities SET contatto = 0.01, manovra = "ricerca" WHERE encounter_id = ?', [$encId]);
Database::run('UPDATE encounters SET allarme = 1 WHERE id = ?', [$encId]);
$passaTempo(600);
$e = Database::first('SELECT stato FROM encounters WHERE id = ?', [$encId]);
$b = Database::first('SELECT encounter_id FROM boats WHERE id = ?', [$boatId]);
verifica('perso il contatto, alla stessa distanza, ci si sgancia', 'concluso', (string) $e['stato']);
verifica('e il battello torna libero', null, $b['encounter_id']);

titolo('Un tubo, un siluro');

// Audit del 23/09/2026. L'elenco dei tubi arrivava dal modulo senza togliere i
// doppioni, e ogni ripetizione pescava la stessa riga: con tubi[]=1 scritto sei
// volte in un POST, da UN siluro ne uscivano SEI, e il contatore della missione
// ne segnava sei. Qui si pretende la conservazione: siluri a bordo + corse
// registrate = siluri imbarcati, qualunque cosa contenga l'ordine.
$g2 = World::now();
Database::run('UPDATE boats SET mode = "periscopio", depth_m = 12, ordered_depth_m = 12, lat = 50.0, lon = -30.0 WHERE id = ?', [$boatId]);
\App\Sim\Torpedo::imbarca($boatId, World::type((string) $boat['type_key']), \App\Sim\Torpedo::caricoStandard(World::type((string) $boat['type_key']), 0));
Database::run(
    'INSERT INTO encounters (boat_id, patrol_id, stato, allarme, started_gts, last_step_gts, last_step_real, finestra_fine, ratio)
     VALUES (?, NULL, "attacco", 0, ?, ?, ?, ?, 1)',
    [$boatId, $g2, $g2, time(), $g2 + 3600]
);
$enc2 = Database::lastInsertId();
Database::run('UPDATE boats SET encounter_id = ? WHERE id = ?', [$enc2, $boatId]);
Database::run(
    'INSERT INTO encounter_entities (encounter_id, class_key, name, ruolo, lat, lon, heading, speed_kn, grt, integrita)
     VALUES (?, "cargo_medio", "Bersaglio Prova", "mercantile", 50.009, -30.0, 90, 8, 5100, 100)',
    [$enc2]
);
$bersaglio2 = Database::lastInsertId();
$aBordo = static fn (): int => (int) (Database::first(
    "SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ? AND stato <> 'lanciato'", [$boatId]
)['n'] ?? 0);
$corse = static fn (): int => (int) (Database::first('SELECT COUNT(*) n FROM torpedo_runs WHERE boat_id = ?', [$boatId])['n'] ?? 0);
$imbarcati = $aBordo() + $corse();

$r = Encounter::lancia(
    Database::first('SELECT * FROM encounters WHERE id = ?', [$enc2]),
    Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]),
    ['entity_id' => $bersaglio2, 'tubi' => [1, 1, 1, 1, 1, 1], 'spoletta' => 'contatto', 'quota' => 4, 'ventaglio' => 2],
    $g2
);
verifica('lo stesso tubo sei volte: parte comunque', true, (bool) $r['ok']);
verifica('ma parte UN siluro, non sei', 1, $corse());
verifica('e i siluri si conservano', $imbarcati, $aBordo() + $corse());
verifica('e il testo lo dice al singolare', true, str_starts_with((string) ($r['testo'] ?? ''), 'Lanciato un siluro'));

echo "\n";
if ($falliti === 0) { echo "\033[0;32mTutte le verifiche superate.\033[0m\n"; exit(0); }
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
