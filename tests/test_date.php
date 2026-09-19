<?php

declare(strict_types=1);

/**
 * Le date: come si scrivono e a che ora si riferiscono.
 *
 *   php tests/test_date.php
 *
 * Due regole, tenute ferme qui perche' altrove si dimenticano:
 *
 *   1. Una data si scrive all'italiana, GG/MM/AAAA. Vale per l'ora reale e
 *      vale per la data di bordo, che prima usava i punti alla tedesca.
 *   2. Un orario reale e' l'ora di Roma. Mai UTC, mai il fuso di chi guarda.
 *      La data di gioco e' un'altra cosa: quella vive nel 1939-45 e non ha
 *      niente a che vedere con l'orologio del server.
 *
 * L'ultima parte e' una passata statica sulle viste: una colonna DATETIME
 * stampata cosi' com'e' esce nella forma del database (2026-09-19 03:35:58),
 * che non e' ne' italiana ne' leggibile. E' successo in cinque punti del
 * pannello di amministrazione.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Sim\Clock;

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

// --- 1. La forma italiana ----------------------------------------------------
titolo('Forma della data');

$t = mktime(14, 7, 3, 5, 22, 1913);
ok('fmt_dt scrive GG/MM/AAAA HH:MM', fmt_dt($t) === '22/05/1913 14:07', fmt_dt($t));
ok('fmt_dt con i secondi', fmt_dt($t, true) === '22/05/1913 14:07:03', fmt_dt($t, true));
ok('fmt_date scrive solo la data', fmt_date($t) === '22/05/1913', fmt_date($t));
ok('una stringa DATETIME del database diventa italiana',
    fmt_dt('2026-09-19 03:35:58') === '19/09/2026 03:35', fmt_dt('2026-09-19 03:35:58'));
ok('una colonna DATE diventa italiana', fmt_date('1913-05-22') === '22/05/1913', fmt_date('1913-05-22'));
ok('il vuoto non diventa il 1970', fmt_dt(null) === '—' && fmt_date('') === '—' && fmt_dt('0000-00-00 00:00:00') === '—');

// --- 2. La data di bordo -----------------------------------------------------
titolo('Data di bordo (di gioco)');

$clock = new Clock(1000, 0, 30);
ok('anche la data di bordo e\' GG/MM/AAAA HH:MM',
    preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $clock->format(0)) === 1, $clock->format(0));
ok('senza ora, GG/MM/AAAA',
    preg_match('#^\d{2}/\d{2}/\d{4}$#', $clock->format(0, false)) === 1, $clock->format(0, false));
ok('la data di bordo sta nell\'anno di campagna',
    str_ends_with($clock->format(0, false), (string) Clock::ANNO_CAMPAGNA), $clock->format(0, false));

// --- 3. Si legge quello che si scrive ----------------------------------------
titolo('Date scritte da chi gioca');

ok('22/05/1913 diventa 1913-05-22', data_it_a_iso('22/05/1913') === '1913-05-22');
ok('si accettano punto e trattino',
    data_it_a_iso('22.05.1913') === '1913-05-22' && data_it_a_iso('22-05-1913') === '1913-05-22');
ok('si accetta anche la forma ISO incollata da fuori', data_it_a_iso('1913-05-22') === '1913-05-22');
ok('una cifra sola per giorno e mese', data_it_a_iso('2/5/1913') === '1913-05-02');
ok('il 31 febbraio non esiste', data_it_a_iso('31/02/1913') === null);
ok('quello che non e\' una data torna vuoto',
    data_it_a_iso('pippo') === null && data_it_a_iso('') === null && data_it_a_iso('13/13/1913') === null);
ok('giro completo: si scrive, si conserva, si rilegge uguale',
    fmt_date(data_it_a_iso('22/05/1913')) === '22/05/1913');

// --- 4. L'ora reale e' quella di Roma ----------------------------------------
titolo('Fuso orario');

ok('il fuso applicativo e\' Europe/Rome', rome_tz()->getName() === 'Europe/Rome', rome_tz()->getName());
ok('il fuso di PHP e\' quello applicativo',
    date_default_timezone_get() === rome_tz()->getName(), date_default_timezone_get());

// Un istante noto: 14/11/2023 22:13:20 UTC, cioe' le 23:13 a Roma.
$istante = 1700000000;
$aRoma = (new \DateTimeImmutable('@' . $istante))->setTimezone(new \DateTimeZone('Europe/Rome'))->format('d/m/Y H:i');
ok('un istante si vede all\'ora di Roma, non UTC',
    fmt_dt($istante) === $aRoma && fmt_dt($istante) !== gmdate('d/m/Y H:i', $istante),
    fmt_dt($istante) . '  (UTC sarebbe ' . gmdate('d/m/Y H:i', $istante) . ')');

// Se qualcuno spostasse il fuso di PHP, l'helper deve reggere lo stesso.
$prima = date_default_timezone_get();
date_default_timezone_set('UTC');
$conPhpAUtc = fmt_dt($istante);
date_default_timezone_set($prima);
ok('l\'helper resta su Roma anche con PHP messo su UTC', $conPhpAUtc === $aRoma, $conPhpAUtc);

// Le DATETIME del database sono scritte da NOW(): se il database vivesse in un
// altro fuso, ogni data del pannello sarebbe sfasata di due ore senza dirlo.
$r = Database::first('SELECT NOW() AS adesso');
$scarto = abs(strtotime((string) $r['adesso']) - time());
ok('l\'orologio del database e\' allineato con quello applicativo',
    $scarto <= 120, "scarto {$scarto} s — DB {$r['adesso']}, app " . date('Y-m-d H:i:s'));

// --- 5. Passata sulle viste --------------------------------------------------
titolo('Nessuna data grezza nelle viste');

$radice = dirname(__DIR__) . '/views';
$viste = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));
$grezze = [];
$controllate = 0;

foreach ($viste as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $controllate++;
    $breve = substr($file->getPathname(), strlen(dirname($radice)) + 1);
    foreach (file($file->getPathname()) as $n => $riga) {
        // Una colonna temporale stampata senza passare da un formattatore.
        // Il riferimento conta solo se viene STAMPATO: "$m['inviato_at'] !== null"
        // e' un controllo, non una data a video.
        if (preg_match('/(?:e\(|<\?=\s*)\$\w+\[\'(?:\w*_at|nato_il)\'\](?!\s*[!=]==?)/', $riga) === 1
            && !str_contains($riga, 'fmt_dt(') && !str_contains($riga, 'fmt_date(')) {
            $grezze[] = $breve . ':' . ($n + 1);
        }
        // Formati di visualizzazione che non sono italiani.
        if (str_contains($riga, "'Y-m-d") || str_contains($riga, "'d.m.Y")) {
            $grezze[] = $breve . ':' . ($n + 1) . ' (formato non italiano)';
        }
    }
}

ok('nessuna colonna temporale stampata grezza', $grezze === [],
    $grezze === [] ? "{$controllate} viste" : implode(' | ', $grezze));

// --- 6. Dove il punto ci vuole ----------------------------------------------
titolo("L'ora di bordo col punto");

// Il Kriegstagebuch e' un documento di bordo scritto da un ufficiale tedesco, e
// l'orologio in testata e' l'ora che quell'ufficiale leggerebbe sull'orologio
// di plancia: li' il punto e' voluto. Dappertutto altrove sarebbe una svista.
ok('il giornale scrive GG.MM.AAAA HH:MM',
    preg_match('#^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$#', $clock->formatDiario(0)) === 1,
    $clock->formatDiario(0));
ok('senza ora, GG.MM.AAAA',
    preg_match('#^\d{2}\.\d{2}\.\d{4}$#', $clock->formatDiario(0, false)) === 1,
    $clock->formatDiario(0, false));
ok('stesso istante, cambia solo il separatore',
    str_replace('.', '/', $clock->formatDiario(12345)) === $clock->format(12345),
    $clock->format(12345) . '  vs  ' . $clock->formatDiario(12345));

$radiceProg = dirname(__DIR__);
$leggi = static fn (string $f): string => (string) file_get_contents($radiceProg . '/' . $f);

// Ogni posto col punto ha due facce: quella che disegna il server e quella che
// il JavaScript riscrive da vivo. Se le due usano formattatori diversi, la data
// cambia forma da sola al primo aggiornamento — ed e' successo: il giornale in
// centrale era col punto finche' non arrivava la prima risposta dell'API.
$slot = [
    "l'orologio in testata" => [
        'views/partials/intestazione_battello.php',
        '#data-campo="ora"[^>]*>\s*<\?=\s*e\(\$clock->formatDiario\(#',
    ],
    "l'orologio in testata, riscritto dall'API" => [
        'src/Controllers/PlanciaController.php',
        "#'ora'\s*=>\s*\\\$c\['clock'\]->formatDiario\(\\\$c\['now'\]\)#",
    ],
    "l'orologio alla stazione d'attacco" => [
        'views/game/attacco.php',
        '#data-campo="ora"[^>]*>\s*<\?=\s*e\(\$clock->formatDiario\(#',
    ],
    "l'orologio alla stazione d'attacco, riscritto dall'API" => [
        'src/Controllers/CombattimentoController.php',
        "#'ora'\s*=>\s*World::clock\(\)->formatDiario\(#",
    ],
    "la cronaca alla stazione d'attacco" => [
        'views/game/attacco.php',
        '#<span class="ora"><\?=\s*e\(\$clock->formatDiario\(#',
    ],
    "la cronaca alla stazione d'attacco, riscritta dall'API" => [
        'src/Controllers/CombattimentoController.php',
        "#'ora'\s*=>\s*World::clock\(\)->formatDiario\(\(int\) \\\$e\['gts'\]\)#",
    ],
    'il giornale in centrale' => [
        'views/game/zentrale.php',
        '#<span class="ora"><\?=\s*e\(\$clock->formatDiario\(#',
    ],
    'il giornale in centrale, riscritto dall\'API' => [
        'src/Controllers/PlanciaController.php',
        "#'ora'\s*=>\s*\\\$c\['clock'\]->formatDiario\(\(int\) \\\$e\['gts'\]\)#",
    ],
];
foreach ($slot as $cosa => [$file, $regola]) {
    ok("{$cosa}: col punto", preg_match($regola, $leggi($file)) === 1, $file);
}

// I punti non devono uscire da qui.
$ammessi = [
    'src/Sim/Clock.php',
    'views/game/ktb.php',
    'views/game/zentrale.php',
    'views/game/attacco.php',
    'views/partials/intestazione_battello.php',
    'src/Controllers/RifinituraController.php',
    'src/Controllers/PlanciaController.php',
    'src/Controllers/CombattimentoController.php',
];
$fuoriPosto = [];
foreach (['src', 'views', 'bin'] as $ramo) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radiceProg . '/' . $ramo));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $breve = substr($f->getPathname(), strlen($radiceProg) + 1);
        $testo = (string) file_get_contents($f->getPathname());
        if ((str_contains($testo, 'formatDiario(') || str_contains($testo, "'d.m.Y"))
            && !in_array($breve, $ammessi, true)) {
            $fuoriPosto[] = $breve;
        }
    }
}
ok("il punto non esce dall'ora di bordo e dal giornale", $fuoriPosto === [], implode(' | ', $fuoriPosto));

// Due posti sono interamente di bordo: il giornale e la stazione d'attacco.
// Li' non deve restare nessuna data all'italiana, ne' disegnata dal server ne'
// mandata dall'API.
ok("nella pagina del giornale ogni data e' del giornale",
    !str_contains($leggi('views/game/ktb.php'), '$clock->format('),
    substr_count($leggi('views/game/ktb.php'), '$clock->formatDiario(') . ' date, tutte col punto');
ok("alla stazione d'attacco ogni data e' di bordo",
    !str_contains($leggi('views/game/attacco.php'), '$clock->format(')
    && !str_contains($leggi('src/Controllers/CombattimentoController.php'), 'clock()->format('));

// Il formattatore italiano resta uno solo, e il suo formato e' quello.
$clockSrc = $leggi('src/Sim/Clock.php');
ok("in Clock ci sono due formattatori e non di piu'",
    substr_count($clockSrc, 'public function format') === 2
    && substr_count($clockSrc, "'d/m/Y") === 2
    && substr_count($clockSrc, "'d.m.Y") === 2);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
