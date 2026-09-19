<?php

declare(strict_types=1);

/**
 * Coerenza fra quello che il gioco promette e quello che il gioco fa.
 *
 *   php tests/test_coerenza.php
 *
 * Non prova nessuna meccanica: prova che non ci siano promesse a vuoto. Nasce
 * dall'audit del 19/09/2026, dove sono venute fuori tre cose della stessa
 * famiglia — un pannello che mostra colonne che nessuno scrive, tre manopole
 * che non muovono niente, e un campo che il JavaScript scriveva in un elemento
 * inesistente. Nessuna di queste rompe una pagina: tutte e tre fanno credere
 * una cosa falsa, ed e' peggio.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;

$falliti = 0;

function titolo(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function saltata(string $titolo, string $perche): void
{
    echo "  \033[0;33m--\033[0m    {$titolo}  \033[0;90m{$perche}\033[0m\n";
}
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

$radice = dirname(__DIR__);

/** Tutto il codice, in una stringa sola: serve a chiedere "qualcuno lo nomina?". */
$sorgenti = static function (array $estensioni) use ($radice): string {
    $testo = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!$f->isFile() || preg_match('#/(vendor|storage|\.git|db/migrations|tests)/#', $p)) {
            continue;
        }
        foreach ($estensioni as $e) {
            if (str_ends_with($p, $e)) {
                $testo .= file_get_contents($p);
                break;
            }
        }
    }
    return $testo;
};

$codice = $sorgenti(['.php', '.js']);

// --- 1. Le manopole muovono qualcosa? ---------------------------------------
titolo('Le manopole del pannello');

$morte = [];
foreach (array_keys(GameConfig::all()) as $chiave) {
    // La chiave compare almeno una volta oltre alla riga che la definisce?
    if (substr_count($codice, $chiave) < 1) {
        $morte[] = $chiave;
    }
}
ok('ogni chiave di bilanciamento viene letta da qualcuno', $morte === [],
    $morte === [] ? count(GameConfig::all()) . ' chiavi' : implode(', ', $morte));

// --- 2. Il JavaScript scrive in elementi che esistono ------------------------
titolo('I campi che il JavaScript riscrive');

$viste = $sorgenti(['.php']);
$orfani = [];
foreach (glob($radice . '/assets/js/*.js') as $js) {
    preg_match_all("/scrivi\('([a-z_0-9]+)'/", (string) file_get_contents($js), $m);
    foreach (array_unique($m[1]) as $campo) {
        if (!str_contains($viste, 'data-campo="' . $campo . '"')) {
            $orfani[] = basename($js) . ':' . $campo;
        }
    }
}
ok('ogni campo riscritto dal JavaScript ha un posto dove andare', $orfani === [],
    implode(', ', $orfani));

// --- 3. Colonne mostrate e mai scritte ---------------------------------------
titolo('Colonne che si mostrano e che nessuno riempie');

// Le colonne di stato dei compartimenti sono il caso che ha fatto nascere la
// prova: si vedono in pagina e nessuna riga di codice le scrive. Finche' e'
// cosi', la pagina deve dirlo — e questa prova controlla che lo dica.
$compartimenti = (string) file_get_contents($radice . '/views/game/battello.php');
$scritte = preg_match('/boat_compartments\s+SET\s+(?!integrity = 100)/i', $codice) === 1;
if ($scritte) {
    ok('i compartimenti sono collegati: la pagina non deve piu\' dire il contrario',
        !str_contains($compartimenti, 'non è ancora collegato'),
        'il danno ai compartimenti adesso si scrive: togliere l\'avviso dalla pagina');
} else {
    ok('finche\' i compartimenti non sono collegati, la pagina lo dichiara',
        str_contains($compartimenti, 'non è ancora collegato'),
        'nessuno scrive integrity/flooding/fire: la pagina non puo\' far finta di niente');
}

// --- 3b. Gli indici di unicita' filtrano davvero? ----------------------------
//
// Non e' una domanda oziosa. Tre garanzie di unicita' del gioco — il numero di
// un battello vivo, il nome e il ritratto di un comandante in servizio — sono
// indici UNIQUE costruiti sopra colonne VIRTUALI generate. Un DROP COLUMN
// "istantaneo" su quelle tabelle lascia l'indice nel catalogo, visibile in
// SHOW INDEX, e SPENTO. Successo davvero con la migrazione 0036: due battelli
// vivi potevano portare lo stesso numero, senza un errore, senza un avviso.
//
// Qui non si guarda il catalogo: si prova a violare la regola e si pretende
// che il database dica di no.
titolo('Le garanzie del database, messe alla prova');

$violabile = static function (string $tabella, string $colonna, callable $riga) use (&$falliti): bool {
    $primo = null;
    $secondo = null;
    try {
        $primo = $riga('AAA' . substr((string) microtime(true), -6));
        $secondo = $riga('BBB' . substr((string) microtime(true), -6));
        if ($primo === null || $secondo === null) {
            return false;
        }
        $valore = Database::first("SELECT {$colonna} v FROM {$tabella} WHERE id = ?", [$primo]);
        try {
            Database::run("UPDATE {$tabella} SET {$colonna} = ? WHERE id = ?", [(string) $valore['v'], $secondo]);
            return true;    // accettato: l'indice non filtra
        } catch (PDOException $e) {
            return $e->getCode() !== '23000';
        }
    } finally {
        foreach ([$primo, $secondo] as $id) {
            if ($id !== null) {
                Database::run("DELETE FROM {$tabella} WHERE id = ?", [$id]);
            }
        }
    }
};

$modello = Database::first("SELECT * FROM boats LIMIT 1");
if ($modello === null) {
    saltata('il numero di un battello vivo non si puo\' rubare', 'nessun battello da cui copiare');
} else {
    $rotto = $violabile('boats', 'uboat_number', static function (string $n) use ($modello): ?int {
        Database::run(
            'INSERT INTO boats (user_id, type_key, uboat_number, state, home_port_key, flotilla,
                                lat, lon, est_lat, est_lon, heading, fuel_t, battery_pct, air_pct,
                                provisions_days, last_sim_gts)
             VALUES (?, ?, ?, "base", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $modello['user_id'], (string) $modello['type_key'], 'U-9' . substr($n, 3),
                (string) $modello['home_port_key'], (string) $modello['flotilla'],
                $modello['lat'], $modello['lon'], $modello['est_lat'], $modello['est_lon'],
                $modello['heading'], $modello['fuel_t'], $modello['battery_pct'], $modello['air_pct'],
                $modello['provisions_days'], $modello['last_sim_gts'],
            ]
        );
        return Database::lastInsertId();
    });
    ok('il numero di un battello vivo non si puo\' rubare', !$rotto,
        $rotto ? 'l\'indice uq_vivo_numero e\' nel catalogo ma non filtra piu\'' : 'uq_vivo_numero filtra');
}

// --- 4. Nessuna promessa a una fase gia' chiusa ------------------------------
titolo('Promesse rimaste indietro');

$fasiChiuse = ['F0', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7'];
$promesse = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/views'));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $breve = substr($f->getPathname(), strlen($radice) + 1);
    foreach (file($f->getPathname()) as $n => $riga) {
        foreach ($fasiChiuse as $fase) {
            if (preg_match('/\b(in|con|col)\s+' . $fase . '\b/', $riga) === 1) {
                $promesse[] = $breve . ':' . ($n + 1);
            }
        }
    }
}
ok('nessuna pagina rimanda a una fase gia\' consegnata', $promesse === [],
    implode(' | ', $promesse));

// --- 5. Import inutilizzati --------------------------------------------------
titolo('Ingombro');

$inutili = [];
foreach (glob($radice . '/src/*/*.php') as $f) {
    $s = (string) file_get_contents($f);
    $corpo = preg_replace('/^use\s+[^;]+;\s*$/m', '', $s) ?? $s;
    preg_match_all('/^use\s+([\w\\\\]+);$/m', $s, $m);
    foreach ($m[1] as $pieno) {
        // strrpos torna false quando non c'e' nessuna barra — e (int) false e'
        // zero, che tagliava la prima lettera: "PDO" diventava "DO" e la prova
        // segnalava import inutilizzati che erano invece usatissimi.
        $taglio = strrpos($pieno, '\\');
        $corto = $taglio === false ? $pieno : substr($pieno, $taglio + 1);
        if (preg_match('/\b' . preg_quote($corto, '/') . '\b/', $corpo) !== 1) {
            $inutili[] = basename($f) . ': ' . $corto;
        }
    }
}
ok('nessun import dichiarato e mai usato', $inutili === [],
    $inutili === [] ? '' : implode(', ', array_slice($inutili, 0, 6)));

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
