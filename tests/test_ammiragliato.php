<?php

declare(strict_types=1);

/**
 * Atlantik — la guerra delle informazioni: l'Ammiragliato e il B-Dienst.
 *
 *   php tests/test_ammiragliato.php
 *
 * Fino al 09/10/2026 un convoglio teneva la rotta estratta alla partenza
 * qualunque cosa succedesse: dove gli U-Boot si facevano sentire il traffico
 * continuava a passare. Qui si verifica che:
 *
 *   - una deviazione nuova non teletrasporti il convoglio: accosta;
 *   - vicino ai porti la deviazione si stringe, e si arriva per il canale;
 *   - l'Ammiragliato devii il meno possibile, dalla parte che serve, e mai
 *     sulla terraferma; e non devii quando non c'e' pericolo;
 *   - una nave che lascia il convoglio resti dov'e';
 *   - il B-Dienst decifri, e il BdU lo dica a chi e' in mare.
 *
 * Il convoglio e' di prova; i pericoli si passano a mano invece di scaldare i
 * settori, che sono mondo condiviso. Il battito resta fermo.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Ammiragliato;
use App\Sim\Geo;
use App\Sim\Terra;
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

$lucchetto = fopen(__DIR__ . '/../storage/tick.lock', 'c');
if ($lucchetto === false || !flock($lucchetto, LOCK_EX)) {
    fwrite(STDERR, "Non riesco a prendere il lucchetto del battito.\n");
    exit(2);
}

$suffisso = substr((string) time(), -6);
$gts = World::now();
$convogli = [];
$utente = null;
$bdienstPrima = Database::first('SELECT cvalue, ctype FROM game_config WHERE ckey = ?', ['ammiragliato.bdienst']);
register_shutdown_function(static function () use (&$convogli, &$utente, $lucchetto, $bdienstPrima): void {
    if ($bdienstPrima !== null) {
        GameConfig::set('ammiragliato.bdienst', (string) $bdienstPrima['cvalue'], (string) $bdienstPrima['ctype']);
    }
    foreach ($convogli as $c) {
        Database::run("DELETE FROM radio_messages WHERE destinatario = 'tutti' AND testo LIKE ?", ['%PROVA ' . $c['numero'] . '%']);
        Database::run('DELETE FROM convoys WHERE id = ?', [$c['id']]);
    }
    // Le navi che hanno lasciato il convoglio di prova (disperso) non se ne
    // vanno con lui: restavano in mare sulla rotta vera dell'HX (13/10/2026).
    Database::run('DELETE FROM ships WHERE name LIKE ?', ['Prova % ' . $GLOBALS['suffisso']]);
    if ($utente !== null) {
        foreach (Database::all('SELECT id FROM boats WHERE user_id = ?', [$utente]) as $b) {
            Database::run('DELETE FROM patrol_events WHERE boat_id = ?', [(int) $b['id']]);
            Database::run('DELETE FROM patrols WHERE boat_id = ?', [(int) $b['id']]);
            Database::run('DELETE FROM bdu_orders WHERE boat_id = ?', [(int) $b['id']]);
        }
        Database::run('DELETE FROM avvisi WHERE user_id = ?', [$utente]);
        Database::run('DELETE FROM users WHERE id = ?', [$utente]);
    }
    flock($lucchetto, LOCK_UN);
});

/** Un convoglio di prova sulla rotta HX, a una frazione della traversata. */
$nuovoConvoglio = static function (float $frazione, float $deviazione = 0.0) use (&$convogli, $gts, $suffisso): array {
    $durata = Traffic::durata('hx', 9.0);
    $partito = $gts - (int) ($durata * $frazione);
    $numero = ((int) $suffisso + count($convogli)) % 30000;
    Database::run(
        "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
         VALUES ('PROVA', ?, 'hx', 9.0, 3, ?, ?, 0, ?, 4)",
        [$numero, $partito, $partito + $durata, $deviazione]
    );
    $id = Database::lastInsertId();
    $convogli[] = ['id' => $id, 'numero' => $numero];
    return Database::first('SELECT * FROM convoys WHERE id = ?', [$id]);
};

// ==========================================================================
titolo('Una deviazione nuova non teletrasporta il convoglio');
// ==========================================================================

$cv = $nuovoConvoglio(0.4);
$prima = Traffic::pos($cv, $gts);
Database::run('UPDATE convoys SET dev_da = 0, deviazione = 70, dev_inizio_gts = ? WHERE id = ?', [$gts, (int) $cv['id']]);
$cv = Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv['id']]);
$subito = Traffic::pos($cv, $gts);
ok('nell\'istante dell\'ordine e\' dov\'era', Geo::distanceNm($prima['lat'], $prima['lon'], $subito['lat'], $subito['lon']) < 0.01);
$pBase = Traffic::posizione('hx', 9.0, (int) $cv['departed_gts'], $gts + 3600, 0.0);
$pUnOra = Traffic::pos($cv, $gts + 3600);
$scostamento = Geo::distanceNm($pBase['lat'], $pBase['lon'], $pUnOra['lat'], $pUnOra['lon']);
ok('dopo un\'ora si e\' scostato di quattro miglia e mezza: accosta, non salta', abs($scostamento - 4.5) < 0.3,
    sprintf('%.2f miglia', $scostamento));
$tutto = Traffic::deviazioneAl($cv, $gts + 30 * 3600);
ok('e arriva alla deviazione ordinata in una ventina d\'ore', abs($tutto - 70.0) < 0.01 && Traffic::deviazioneAl($cv, $gts + 18 * 3600) < 70.0,
    sprintf('a 18 ore %.1f, a 30 ore %.1f', Traffic::deviazioneAl($cv, $gts + 18 * 3600), $tutto));

// Vicino ai porti si stringe: alla partenza e all'arrivo si e' sulla rotta base.
$durata = Traffic::durata('hx', 9.0);
$pPartenza = Traffic::posizione('hx', 9.0, 0, 600, 90.0);
$bPartenza = Traffic::posizione('hx', 9.0, 0, 600, 0.0);
$pArrivo = Traffic::posizione('hx', 9.0, 0, $durata - 600, 90.0);
$bArrivo = Traffic::posizione('hx', 9.0, 0, $durata - 600, 0.0);
ok('alla partenza e all\'arrivo la deviazione e\' zero', Geo::distanceNm($pPartenza['lat'], $pPartenza['lon'], $bPartenza['lat'], $bPartenza['lon']) < 1.0
    && Geo::distanceNm($pArrivo['lat'], $pArrivo['lon'], $bArrivo['lat'], $bArrivo['lon']) < 1.0);
$pMeta = Traffic::posizione('hx', 9.0, 0, (int) ($durata / 2), 90.0);
$bMeta = Traffic::posizione('hx', 9.0, 0, (int) ($durata / 2), 0.0);
ok('a meta\' oceano e\' piena: centoquarantaquattro miglia', abs(Geo::distanceNm($pMeta['lat'], $pMeta['lon'], $bMeta['lat'], $bMeta['lon']) - 144.0) < 1.0);

// ==========================================================================
titolo('L\'Ammiragliato devia dove serve, e del meno possibile');
// ==========================================================================

$cv2 = $nuovoConvoglio(0.3);
ok('senza pericoli noti non si tocca niente', Ammiragliato::devia($cv2, $gts, []) === null);
$lontano = [['lat' => 30.0, 'lon' => -20.0]];
ok('con un pericolo lontano nemmeno', Ammiragliato::devia(Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv2['id']]), $gts, $lontano) === null);

// Un U-Boot localizzato sulla rotta, trecento miglia avanti.
$avanti = Traffic::posizione('hx', 9.0, (int) $cv2['departed_gts'], $gts + (int) (300 / 9.0 * 3600), 0.0);
$pericolo = [['lat' => $avanti['lat'], 'lon' => $avanti['lon']]];
$cv2 = Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv2['id']]);
$nuova = Ammiragliato::devia($cv2, $gts, $pericolo);
$cv2 = Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv2['id']]);
ok('con un U-Boot sulla rotta, si devia', $nuova !== null && (float) $cv2['deviazione'] === $nuova && (int) $cv2['dev_inizio_gts'] === $gts,
    'deviazione ' . ($nuova ?? '—'));
// La rotta nuova, a deviazione raggiunta, passa a piu' di centoventi miglia.
$minimo = INF;
for ($t = $gts + 1800; $t <= $gts + (int) (700 / 9.0 * 3600); $t += 1800) {
    $p = Traffic::posizione('hx', 9.0, (int) $cv2['departed_gts'], $t, (float) $nuova);
    $minimo = min($minimo, Geo::distanceNm($p['lat'], $p['lon'], $avanti['lat'], $avanti['lon']));
}
ok('e la rotta nuova passa a piu\' di centoventi miglia', $minimo >= 119.0, sprintf('%.0f miglia', $minimo));
ok('del meno possibile: la deviazione piu\' piccola che basta', abs((float) $nuova) <= 90.0 && abs((float) $nuova) >= 25.0
    && ($minimo < 200.0 || abs((float) $nuova) === 25.0), sprintf('%.0f (%.0f miglia di lato)', (float) $nuova, abs((float) $nuova) * 1.6));
ok('una seconda occhiata, con la rotta gia\' al largo, non cambia niente', Ammiragliato::devia($cv2, $gts + 600, $pericolo) === null);
$cv2 = Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv2['id']]);
ok('passato il pericolo, si torna sulla rotta', Ammiragliato::devia($cv2, $gts + 1200, []) === 0.0);

// Mai sulla terraferma: un pericolo sotto la costa del Portogallo, sulla rotta
// di Gibilterra, deve far girare il convoglio verso il largo, non verso terra.
$durOg = Traffic::durata('og', 8.0);
$partOg = $gts - (int) ($durOg * 0.45);
Database::run(
    "INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione, navi_iniziali)
     VALUES ('PROVA', ?, 'og', 8.0, 3, ?, ?, 0, 0, 4)",
    [((int) $suffisso + 50) % 30000, $partOg, $partOg + $durOg]
);
$og = Database::first('SELECT * FROM convoys WHERE id = ?', [Database::lastInsertId()]);
$convogli[] = ['id' => (int) $og['id'], 'numero' => (int) $og['numero']];
$avOg = Traffic::posizione('og', 8.0, $partOg, $gts + (int) (250 / 8.0 * 3600), 0.0);
$nuovaOg = Ammiragliato::devia($og, $gts, [['lat' => $avOg['lat'], 'lon' => $avOg['lon']]]);
$terra = false;
$prec = null;
for ($t = $gts; $t <= $og['eta_gts']; $t += 3600) {
    $p = Traffic::posizione('og', 8.0, $partOg, $t, (float) ($nuovaOg ?? 0.0));
    if ($p === null) {
        break;
    }
    if ($prec !== null && Terra::attraversa($prec[0], $prec[1], $p['lat'], $p['lon'])) {
        $terra = true;
    }
    $prec = [$p['lat'], $p['lon']];
}
ok('sulla rotta di Gibilterra devia senza mai passare sulla terraferma', $nuovaOg !== null && !$terra,
    sprintf('deviazione %s', $nuovaOg ?? '—'));

// ==========================================================================
titolo('Una nave che lascia il convoglio resta dov\'e\'');
// ==========================================================================

$cv3 = $nuovoConvoglio(0.5, 60.0);
Database::run(
    "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
     VALUES (?, 'britannica', 'cargo_medio', ?, 1, 1, 'mercantile', 'hx', 9.0, ?, ?, 5000, 'grano')",
    ['Prova Ritardataria ' . $suffisso, (int) $cv3['id'], (int) $cv3['departed_gts'], (int) $cv3['eta_gts']]
);
$nave = Database::lastInsertId();
$pc = Traffic::pos($cv3, $gts);
Database::run("UPDATE convoys SET navi_iniziali = 20 WHERE id = ?", [(int) $cv3['id']]);
// Il convoglio ridotto all'osso si disperde (Traffic::ensure): le navi proseguono da sole.
Traffic::ensure($gts);
$sh = Database::first('SELECT * FROM ships WHERE id = ?', [$nave]);
$ps = $sh['convoy_id'] === null ? Traffic::pos($sh, $gts) : null;
ok('disperso il convoglio deviato, la nave resta sulla sua rotta deviata',
    $ps !== null && Geo::distanceNm($pc['lat'], $pc['lon'], $ps['lat'], $ps['lon']) < 0.5,
    $ps === null ? 'ancora in convoglio' : sprintf('%.2f miglia, deviazione %.1f', Geo::distanceNm($pc['lat'], $pc['lon'], $ps['lat'], $ps['lon']), (float) $sh['deviazione']));

// Una nave colpita che va a fondo mentre e' ancora nel convoglio deviato: va a
// fondo dov'e' il convoglio, non sulla rotta base (revisione del 13/10/2026).
$cv5 = $nuovoConvoglio(0.5, 60.0);
Database::run(
    "INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico, affonda_gts, integrita)
     VALUES (?, 'britannica', 'cargo_medio', ?, 1, 1, 'mercantile', 'hx', 9.0, ?, ?, 5000, 'grano', ?, 0)",
    ['Prova Agonia ' . $suffisso, (int) $cv5['id'], (int) $cv5['departed_gts'], (int) $cv5['eta_gts'], $gts - 60]);
$agonizza = Database::lastInsertId();
\App\Sim\Danni::agonia($gts);
$giu = Database::first('SELECT * FROM ships WHERE id = ?', [$agonizza]);
$doveCv = Traffic::pos($cv5, $gts - 60);
ok('una nave che affonda in un convoglio deviato affonda dov\'e\' il convoglio', (string) $giu['state'] === 'affondata'
    && Geo::distanceNm((float) $giu['lat_ultima'], (float) $giu['lon_ultima'], $doveCv['lat'], $doveCv['lon']) < 1.0,
    sprintf('%.1f miglia dal convoglio', Geo::distanceNm((float) $giu['lat_ultima'], (float) $giu['lon_ultima'], $doveCv['lat'], $doveCv['lon'])));

// ==========================================================================
titolo('Il B-Dienst');
// ==========================================================================

// Un battello di prova in mare, che deve ricevere il comunicato.
$reg = \App\Auth\Auth::register('prova bdienst ' . $suffisso, 'bdienst' . $suffisso . '@esempio.invalid', 'kommandant42', '127.0.0.1');
$utente = (int) $reg['user_id'];
Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$utente]);
\App\Game\Comandante::crea($utente, ['nome' => 'Bdienst Prova ' . $suffisso, 'nato_il' => '01/02/1912', 'nato_a' => 'Kiel', 'ritratto' => 'r1', 'base' => 'lorient']);
$boat = \App\Game\Fleet::ensureBoat($utente, 'VIIC');
\App\Game\Patrol::depart(Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]));

GameConfig::set('ammiragliato.bdienst', '1', 'float');
$cv4 = $nuovoConvoglio(0.35);
$av4 = Traffic::posizione('hx', 9.0, (int) $cv4['departed_gts'], $gts + (int) (300 / 9.0 * 3600), 0.0);
Ammiragliato::devia($cv4, $gts, [['lat' => $av4['lat'], 'lon' => $av4['lon']]]);
$cv4 = Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $cv4['id']]);
ok('una deviazione decifrata si sapra\' fra sei e diciotto ore', $cv4['bdienst_gts'] !== null
    && (int) $cv4['bdienst_gts'] - $gts >= 6 * 3600 && (int) $cv4['bdienst_gts'] - $gts <= 18 * 3600,
    $cv4['bdienst_gts'] !== null ? sprintf('%.0f ore', ((int) $cv4['bdienst_gts'] - $gts) / 3600) : 'mai');
Ammiragliato::bdienst($gts);
ok('prima di allora il BdU non sa niente', Database::first(
    "SELECT 1 FROM radio_messages WHERE destinatario = 'tutti' AND testo LIKE ?", ['%PROVA ' . $cv4['numero'] . '%']) === null);
$quando = (int) $cv4['bdienst_gts'];
Ammiragliato::bdienst($quando);
$msg = Database::first("SELECT * FROM radio_messages WHERE destinatario = 'tutti' AND testo LIKE ?", ['%PROVA ' . $cv4['numero'] . '%']);
$pVera = Traffic::pos($cv4, $quando);
ok('poi il comunicato, con la posizione vera del convoglio in quel momento', $msg !== null && $pVera !== null
    && Geo::distanceNm((float) $msg['lat'], (float) $msg['lon'], $pVera['lat'], $pVera['lon']) < 0.5,
    $msg !== null ? mb_substr((string) $msg['testo'], 0, 100) : 'nessuno');
$riga = Database::first("SELECT text FROM patrol_events WHERE boat_id = ? AND kind = 'bdu' AND text LIKE ? ORDER BY id DESC LIMIT 1",
    [(int) $boat['id'], '%PROVA ' . $cv4['numero'] . '%']);
ok('e nel giornale di chi e\' in mare, con distanza e rilevamento', $riga !== null && str_contains((string) $riga['text'], 'miglia per'),
    $riga !== null ? mb_substr((string) $riga['text'], -60) : 'nessuna riga');
ok('una volta sola', (int) Database::first("SELECT COUNT(*) n FROM radio_messages WHERE destinatario = 'tutti' AND testo LIKE ?",
    ['%PROVA ' . $cv4['numero'] . '%'])['n'] === 1 && Database::first('SELECT bdienst_gts FROM convoys WHERE id = ?', [(int) $cv4['id']])['bdienst_gts'] === null);

echo "\n" . ($falliti === 0 ? "\033[0;32mTutte le verifiche superate.\033[0m" : "\033[0;31m{$falliti} verifiche fallite.\033[0m") . "\n";
exit($falliti === 0 ? 0 : 1);
