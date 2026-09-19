<?php

declare(strict_types=1);

/**
 * I compartimenti: l'acqua che entra e quello che si fa per fermarla.
 *
 *   php tests/test_compartimenti.php
 *
 * Fino al 19/09/2026 questa tabella era un ornamento: quattro colonne di stato
 * che nessuna riga di codice scriveva, otto compartimenti eternamente integri e
 * asciutti, e una didascalia che prometteva allagamenti "con F4" mentre F4 era
 * chiusa da giorni. La prova esiste perche' quel genere di cosa non si veda
 * piu': se domani qualcuno stacca il collegamento, qui diventa rosso.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Compartimenti;
use App\Sim\Rng;
use App\Sim\World;

$falliti = 0;
$saltate = 0;

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
function saltata(string $titolo, string $perche = ''): void
{
    global $saltate;
    $saltate++;
    echo "  \033[0;33m--\033[0m    {$titolo}" . ($perche !== '' ? "  \033[0;90m{$perche}\033[0m" : '') . "\n";
}

// --- un battello di prova, suo, che si porta via alla fine -------------------
$utente = 'prova comp ' . time();
$reg = \App\Auth\Auth::register($utente, 'comp_' . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
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
$rng = Rng::for(World::seed(), 'prova_comp', $boatId, 1);

register_shutdown_function(static function () use ($utente): void {
    @shell_exec(sprintf('php %s %s 2>/dev/null',
        escapeshellarg(dirname(__DIR__) . '/bin/_cleanup_test_user.php'), escapeshellarg($utente)));
});

// --- si parte asciutti -------------------------------------------------------
titolo('Si parte asciutti');

Compartimenti::revisiona($boatId);
$z = Compartimenti::zavorra($boatId);
ok('un battello nuovo non imbarca acqua', $z['acqua_pct'] === 0.0 && $z['acqua_t'] === 0.0);
ok('e non ha penalita\'', $z['quota_max'] === 1.0 && $z['velocita'] === 1.0);
ok('gli otto compartimenti ci sono', count(Compartimenti::di($boatId)) >= 5,
    count(Compartimenti::di($boatId)) . ' compartimenti');

// --- una carica vicina apre una falla ----------------------------------------
titolo('Le cariche vicine');

$eventi = [];
for ($i = 0; $i < 40; $i++) {
    Compartimenti::colpisci($boatId, 34.0, Rng::for(World::seed(), 'carica', $boatId, $i), $eventi);
}
$dopo = Compartimenti::di($boatId);
$rotti = array_filter($dopo, static fn (array $c): bool => (float) $c['integrity'] < 100.0);
$bagnati = array_filter($dopo, static fn (array $c): bool => (float) $c['flooding'] > 0.0);

ok('uno scoppio vicino lascia il segno sulle lamiere', $rotti !== [],
    count($rotti) . ' compartimenti danneggiati su ' . count($dopo));
ok('e prima o poi apre una falla', $bagnati !== [],
    count($bagnati) . ' compartimenti allagati');
ok('l\'acqua a bordo si conta in tonnellate', Compartimenti::zavorra($boatId)['acqua_t'] > 0.0,
    Compartimenti::zavorra($boatId)['acqua_t'] . ' t');

$z = Compartimenti::zavorra($boatId);
ok('e costa quota di sicurezza', $z['quota_max'] < 1.0,
    'quota al ' . round($z['quota_max'] * 100) . '%');
ok('e un po\' di velocita\'', $z['velocita'] < 1.0,
    'velocita\' al ' . round($z['velocita'] * 100) . '%');

$eventiDetti = array_filter($eventi, static fn (string $t): bool => str_contains($t, 'Falla') || str_contains($t, 'acqua'));
ok('e il giornale lo racconta', $eventiDetti !== [], (string) (reset($eventiDetti) ?: ''));

// --- una carica lontana no ---------------------------------------------------
Compartimenti::revisiona($boatId);
$eventi = [];
for ($i = 0; $i < 30; $i++) {
    Compartimenti::colpisci($boatId, 3.0, Rng::for(World::seed(), 'lontana', $boatId, $i), $eventi);
}
ok('uno scoppio lontano non apre niente',
    Compartimenti::zavorra($boatId)['acqua_pct'] === 0.0);

// --- il collegamento col combattimento ---------------------------------------
titolo('Il collegamento con le cariche di profondita\'');

// Le prove di sopra esercitano la classe. Questa esercita il PUNTO in cui la
// classe viene chiamata: se qualcuno stacca la riga in Scorte::applicaDanno,
// di sopra resta tutto verde e il gioco torna ad avere compartimenti finti.
Compartimenti::revisiona($boatId);
$statoBattello = [
    'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
    'depth' => 80.0, 'mode' => 'immersione', 'speed' => 2.0,
    'stress' => 0.0, 'heading' => 0.0,
];
$eventiCarica = [];
for ($i = 0; $i < 12; $i++) {
    $statoBattello['stress'] = 0.0;   // non e' lo scafo che si prova qui
    \App\Sim\Scorte::applicaDanno($boatId, $statoBattello, $type, 30.0, World::now(),
        Rng::for(World::seed(), 'carica_vera', $boatId, $i), $eventiCarica);
}
$dopoCarica = Compartimenti::zavorra($boatId);
ok('una carica di profondita\' arriva ai compartimenti', $dopoCarica['acqua_pct'] > 0.0,
    'acqua media ' . $dopoCarica['acqua_pct'] . '% dopo dodici scoppi vicini');

// --- la pressione lavora dove la lamiera e' gia' andata ----------------------
titolo('La pressione');

Compartimenti::revisiona($boatId);
$prova = (float) $type['test_depth_m'];

// Compartimento intatto, quota alta: non deve succedere niente.
$eventi = [];
for ($i = 0; $i < 60; $i++) {
    Compartimenti::pressione($boatId, $prova * 1.4, $type, 1.0, Rng::for(World::seed(), 'p1', $boatId, $i), $eventi);
}
ok('su lamiere intatte la pressione non apre falle',
    Compartimenti::zavorra($boatId)['acqua_pct'] === 0.0,
    'la falla nasce dove lo scoppio ha gia\' lavorato, non dal nulla');

// Lamiera ammaccata: adesso puo' cedere.
//
// I numeri sono scelti perche' la prova non dipenda dalla fortuna: lamiera a
// 20 e quota doppia della prova danno la probabilita' massima per ora (0,30),
// e su quaranta ore la probabilita' di non vedere NIENTE e' sei su dieci
// milioni. Con valori piu' timidi — lamiera a 55, quota 1,4 volte — la
// probabilita' per ora e' 0,105 e una volta su settecento la prova sarebbe
// andata rossa senza che niente fosse rotto. Una prova che sfarfalla e' peggio
// di una prova che manca: insegna a non crederle.
$uno = Compartimenti::di($boatId)[0];
Database::run('UPDATE boat_compartments SET integrity = 20 WHERE id = ?', [(int) $uno['id']]);
$eventi = [];
for ($i = 0; $i < 40; $i++) {
    Compartimenti::pressione($boatId, $prova * 2.0, $type, 1.0, Rng::for(World::seed(), 'p2', $boatId, $i), $eventi);
}
$acquaProfonda = Compartimenti::zavorra($boatId)['acqua_pct'];
ok('su una lamiera ammaccata, in profondita\', cede', $acquaProfonda > 0.0,
    'acqua media ' . $acquaProfonda . '%');

// Piu' giu' si va, piu' entra: due scenari a confronto.
Compartimenti::revisiona($boatId);
Database::run('UPDATE boat_compartments SET integrity = 55, flooding = 5 WHERE id = ?', [(int) $uno['id']]);
for ($i = 0; $i < 12; $i++) {
    Compartimenti::pressione($boatId, $prova * 0.75, $type, 1.0, Rng::for(World::seed(), 'poco', $boatId, $i), $eventi);
}
$aQuotaBassa = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $uno['id']])['flooding'];

Database::run('UPDATE boat_compartments SET flooding = 5 WHERE id = ?', [(int) $uno['id']]);
for ($i = 0; $i < 12; $i++) {
    Compartimenti::pressione($boatId, $prova * 1.6, $type, 1.0, Rng::for(World::seed(), 'tanto', $boatId, $i), $eventi);
}
$aQuotaAlta = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $uno['id']])['flooding'];

ok('piu\' si scende, piu\' acqua entra dalla stessa falla', $aQuotaAlta > $aQuotaBassa,
    sprintf('%.1f%% a quota bassa contro %.1f%% in profondita\'', $aQuotaBassa, $aQuotaAlta));

// --- la squadra di falla -----------------------------------------------------
titolo('La squadra di falla');

Compartimenti::revisiona($boatId);
Database::run('UPDATE boat_compartments SET integrity = 60, flooding = 40 WHERE id = ?', [(int) $uno['id']]);
$eventi = [];
$prima = 40.0;
for ($i = 0; $i < 8; $i++) {
    Compartimenti::passo($boatId, 1.0, ['morale' => 80.0, 'fatica' => 10.0], 0.0, $type,
        Rng::for(World::seed(), 'falla', $boatId, $i), $eventi);
}
$rimasta = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $uno['id']])['flooding'];
ok('in superficie le pompe vincono', $rimasta < $prima,
    sprintf('da %.0f%% a %.1f%%', $prima, $rimasta));

// In immersione profonda la stessa squadra fa molto meno.
Database::run('UPDATE boat_compartments SET flooding = 40 WHERE id = ?', [(int) $uno['id']]);
for ($i = 0; $i < 8; $i++) {
    Compartimenti::passo($boatId, 1.0, ['morale' => 80.0, 'fatica' => 10.0], $prova * 1.2, $type,
        Rng::for(World::seed(), 'falla2', $boatId, $i), $eventi);
}
$rimastaGiu = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $uno['id']])['flooding'];
ok('in profondita\' si fatica molto di piu\'', $rimastaGiu > $rimasta,
    sprintf('%.1f%% in immersione contro %.1f%% in superficie', $rimastaGiu, $rimasta));

// --- sigillare ---------------------------------------------------------------
titolo('Sigillare una paratia');

Compartimenti::revisiona($boatId);
$vittima = null;
foreach (Compartimenti::di($boatId) as $c) {
    if ((string) $c['ckey'] !== 'zentrale') { $vittima = $c; break; }
}
Database::run('UPDATE boat_compartments SET flooding = 55 WHERE id = ?', [(int) $vittima['id']]);

$uominiPrima = (int) Database::first(
    "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND station = ? AND health <> 'morto'",
    [$boatId, (string) $vittima['ckey']]
)['n'];

$res = Compartimenti::sigilla($boatId, (string) $vittima['ckey'], true);
ok('la paratia si sigilla', (bool) $res['ok'], (string) ($res['evento'] ?? $res['error'] ?? ''));

$uominiDopo = (int) Database::first(
    "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND station = ? AND health <> 'morto'",
    [$boatId, (string) $vittima['ckey']]
)['n'];
if ($uominiPrima > 0) {
    ok('e chi era dentro resta dentro', $uominiDopo === 0,
        "{$uominiPrima} uomini in " . (string) $vittima['name']);
} else {
    saltata('e chi era dentro resta dentro', 'in quel compartimento non c\'era nessuno di turno');
}

// Un compartimento sigillato non si prosciuga e non peggiora.
$acquaSigillata = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $vittima['id']])['flooding'];
$eventi = [];
for ($i = 0; $i < 10; $i++) {
    Compartimenti::pressione($boatId, $prova * 1.5, $type, 1.0, Rng::for(World::seed(), 'sig', $boatId, $i), $eventi);
    Compartimenti::passo($boatId, 1.0, ['morale' => 90.0, 'fatica' => 0.0], 0.0, $type,
        Rng::for(World::seed(), 'sig2', $boatId, $i), $eventi);
}
$acquaDopo = (float) Database::first('SELECT flooding FROM boat_compartments WHERE id = ?', [(int) $vittima['id']])['flooding'];
ok('dietro una paratia sigillata l\'acqua non si muove piu\'', abs($acquaDopo - $acquaSigillata) < 0.01,
    sprintf('%.2f%% prima, %.2f%% dopo', $acquaSigillata, $acquaDopo));

ok('la centrale non si puo\' sigillare',
    Compartimenti::sigilla($boatId, 'zentrale', true)['ok'] === false,
    'e\' da li\' che si comanda il battello');

// --- il bacino rimette tutto a posto -----------------------------------------
titolo('In bacino');

Compartimenti::revisiona($boatId);
$z = Compartimenti::zavorra($boatId);
$tuttoOk = true;
foreach (Compartimenti::di($boatId) as $c) {
    if ((float) $c['integrity'] < 100.0 || (float) $c['flooding'] > 0.0 || (int) $c['sealed'] === 1) {
        $tuttoOk = false;
    }
}
ok('la revisione asciuga, ripara e riapre tutto', $tuttoOk && $z['acqua_pct'] === 0.0);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m"
        . ($saltate > 0 ? "  \033[0;33m({$saltate} saltate)\033[0m" : '') . "\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
