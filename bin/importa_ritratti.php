<?php

declare(strict_types=1);

/**
 * Importa una raccolta di ritratti di comandanti da una cartella.
 *
 *   php bin/importa_ritratti.php --prova [cartella]   legge e mostra, non scrive
 *   php bin/importa_ritratti.php [cartella]           importa davvero
 *
 * Cartella di default: ~/Scaricati/Comandanti
 *
 * DA DOVE VIENE QUESTA ROBA
 *
 * Da una raccolta messa insieme A MANO dal proprietario del gioco. Non e' stata
 * scaricata da qui: bin/scarica_ritratti.php, che prende da Wikimedia Commons,
 * resta l'altra strada ed e' quella che porta con se' le licenze verificate.
 *
 * Di questi file non si conosce la provenienza singola, e il gioco NON la
 * inventa: ogni voce importata da qui e' marcata `fonte: 'raccolta'` e la
 * dichiarazione che compare sotto il ritratto lo dice apertamente, invece di
 * attribuire una licenza che nessuno ha verificato. Dire "non lo so" e' una
 * informazione; dire una licenza a caso sarebbe una bugia.
 *
 * I NOMI
 *
 * Non c'e' un elenco di nomi: c'e' il nome del file. Il lettore qui sotto lo
 * smonta — cognome, particelle, nomi di battesimo, marcatori da buttare — e
 * rimette insieme un nome leggibile. Funziona quasi sempre e sbaglia qualche
 * volta: e' scritto nella scheda di ogni voce (`nome_ricavato`), cosi' chi
 * guarda sa che quel nome viene da un nome di file e non da un registro.
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

const LATO = 320;
const TAGLIO_ALTO = 0.15;        // il volto sta in alto: si taglia da li', non dal centro

/** Particelle nobiliari e preposizioni: non prendono la maiuscola e non sono nomi. */
const PARTICELLE = ['von', 'van', 'zu', 'zur', 'und', 'de', 'der', 'den', 'dem', 'di', 'la', 'le'];

/** Pezzi che compaiono nei nomi di file ma non sono parti del nome. */
const MARCATORI = ['pow', 'child', 'jr', 'sr'];

/** Nomi di file che non sono nomi di persona. */
const NON_NOMI = ['capt'];

function umlaut(string $s): string
{
    // Il digramma si converte SOLO se non e' preceduto da vocale: in "bauer" la
    // "ue" e' la coda di "bau" piu' "er", non una u con la dieresi. Sbagliare
    // qui vuol dire scrivere "Baüer", ed e' il genere di errore che si nota.
    return preg_replace_callback('/(^|[^aeiou])(ue|oe|ae)/u', static function (array $m): string {
        return $m[1] . match ($m[2]) { 'ue' => 'ü', 'oe' => 'ö', 'ae' => 'ä' };
    }, $s) ?? $s;
}

/**
 * Maiuscole, trattini e particelle.
 *
 * Il trattino tiene insieme i nomi doppi — Hans-Wilhelm, Arco-Zinneberg — ma
 * non deve incollarsi a una particella: "Hans-Wilhelm-von" non esiste, esiste
 * "Hans-Wilhelm von". Attorno a una particella il trattino diventa spazio.
 */
function maiuscola(string $s): string
{
    $parti = explode('-', $s);
    $out = '';
    foreach ($parti as $i => $p) {
        $particella = in_array(mb_strtolower($p), PARTICELLE, true);
        $testo = $particella ? mb_strtolower($p) : mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
        if ($i > 0) {
            $prima = in_array(mb_strtolower($parti[$i - 1]), PARTICELLE, true);
            $out .= ($particella || $prima) ? ' ' : '-';
        }
        $out .= $testo;
    }
    return $out;
}

/** @return array{nome:string,cognome:string}|null */
function nomeDaFile(string $file): ?array
{
    $base = preg_replace('/\.[a-z]+$/i', '', $file) ?? $file;
    $pezzi = array_values(array_filter(explode('_', mb_strtolower($base)),
        static fn (string $p): bool => $p !== ''));

    // Fuori i marcatori: prigionia, numero del battello, date, cifre.
    $pezzi = array_values(array_filter($pezzi, static fn (string $p): bool =>
        !in_array($p, MARCATORI, true)
        && !preg_match('/^u\d+$/', $p)
        && !preg_match('/^\d+$/', $p)
        && !preg_match('/^[a-z]{3}-\d{4}$/', $p)));

    // La cifra in coda distingue due scatti della stessa persona, non fa parte
    // del nome: brandi1 e brandi sono lo stesso uomo.
    $pezzi = array_map(static fn (string $p): string => preg_replace('/\d+$/', '', $p) ?: $p, $pezzi);
    $pezzi = array_map(static fn (string $p): string => preg_replace('/-+/', '-', trim($p, '-')) ?: '', $pezzi);
    $pezzi = array_values(array_filter($pezzi, static fn (string $p): bool => $p !== ''));

    if ($pezzi === [] || in_array($pezzi[0], NON_NOMI, true)) {
        return null;
    }

    $battesimo = null;
    if (count($pezzi) === 1) {
        $cognome = $pezzi;
    } elseif (in_array($pezzi[0], PARTICELLE, true)) {
        // Particella in testa: il cognome prende le particelle e il primo pezzo
        // che non lo e' — "von der Esch" — e quel che resta e' il nome.
        $cognome = [];
        while ($pezzi !== [] && in_array($pezzi[0], PARTICELLE, true)) {
            $cognome[] = array_shift($pezzi);
        }
        if ($pezzi !== []) { $cognome[] = array_shift($pezzi); }
        $battesimo = $pezzi === [] ? null : implode(' ', $pezzi);
    } elseif (in_array($pezzi[count($pezzi) - 1], PARTICELLE, true)) {
        // "harpe_richard_von" → Richard von Harpe.
        $particella = array_pop($pezzi);
        $battesimo = array_pop($pezzi);
        $cognome = array_merge([$particella], $pezzi);
    } else {
        // Il cognome e' il PRIMO pezzo; quello che viene dopo sono i nomi di
        // battesimo, nell'ordine. Una particella dopo il cognome se lo porta
        // dietro — "Heusinger von Waldegg" — ma solo se dopo la particella
        // resta ancora qualcosa da usare come nome: in "ahlefeld von hunold"
        // la particella chiude il cognome e Hunold e' il nome.
        $cognome = [array_shift($pezzi)];
        while ($pezzi !== [] && in_array($pezzi[0], PARTICELLE, true)) {
            $particella = array_shift($pezzi);
            if (count($pezzi) > 1) {
                $cognome[] = $particella;
                $cognome[] = array_shift($pezzi);
            } else {
                array_unshift($cognome, $particella);
                break;
            }
        }
        $battesimo = $pezzi === [] ? null : implode(' ', $pezzi);
    }

    $cognomeT = implode(' ', array_map(
        static fn (string $p): string => maiuscola(umlaut($p)), $cognome));
    $battesimoT = $battesimo === null ? null : implode(' ', array_map(
        static fn (string $p): string => maiuscola(umlaut($p)), explode(' ', $battesimo)));

    return [
        'nome'    => $battesimoT === null ? $cognomeT : $battesimoT . ' ' . $cognomeT,
        'cognome' => $cognomeT,
    ];
}

// --- avvio -------------------------------------------------------------------

$argomenti = array_values(array_filter(array_slice($_SERVER['argv'], 1),
    static fn (string $a): bool => $a !== '--prova'));
$prova = in_array('--prova', $_SERVER['argv'], true);
$sorgente = rtrim($argomenti[0] ?? (getenv('HOME') . '/Scaricati/Comandanti'), '/');

if (!is_dir($sorgente)) {
    fwrite(STDERR, "Cartella non trovata: {$sorgente}\n");
    exit(1);
}

$file = array_values(array_filter(scandir($sorgente) ?: [],
    static fn (string $f): bool => (bool) preg_match('/\.(jpe?g|png|webp)$/i', $f)));
sort($file);
fwrite(STDERR, sprintf("%d immagini in %s\n", count($file), $sorgente));

$dir = $projectRoot . '/assets/img/ritratti';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$nuovo = [];
$scartati = [];
$perCognome = [];

foreach ($file as $f) {
    $n = nomeDaFile($f);
    if ($n === null) {
        $scartati[] = $f . ' (non e\' un nome)';
        continue;
    }

    $chiave = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(
        iconv('UTF-8', 'ASCII//TRANSLIT', $n['nome']) ?: $n['nome']
    )) ?? '';
    $chiave = trim((string) $chiave, '_');
    if ($chiave === '') {
        $scartati[] = $f . ' (chiave vuota)';
        continue;
    }

    // Due file per la stessa persona: si tiene il primo e si dichiara.
    if (isset($nuovo[$chiave])) {
        $scartati[] = $f . ' (doppione di ' . $chiave . ')';
        continue;
    }

    $voce = [
        'nome'          => $n['nome'],
        'cognome'       => $n['cognome'],
        'file'          => 'img/ritratti/' . $chiave . '.webp',
        'sorgente'      => $f,
        'fonte'         => 'raccolta',
        'nome_ricavato' => true,
    ];

    if (!$prova) {
        $tmp = $sorgente . '/' . $f;
        $info = @getimagesize($tmp);
        if ($info === false) {
            $scartati[] = $f . ' (non e\' un\'immagine)';
            continue;
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
            default        => false,
        };
        if (!$src instanceof GdImage) {
            $scartati[] = $f . ' (GD non la apre)';
            continue;
        }
        [$w, $h] = $info;
        $lato = min($w, $h);
        $dx = (int) (($w - $lato) / 2);
        $dy = (int) max(0, ($h - $lato) * TAGLIO_ALTO);
        $out = imagecreatetruecolor(LATO, LATO);
        imagecopyresampled($out, $src, 0, 0, $dx, $dy, LATO, LATO, $lato, $lato);
        imagedestroy($src);
        imagewebp($out, $dir . '/' . $chiave . '.webp', 86);
        imagedestroy($out);
    }

    $nuovo[$chiave] = $voce;
    // Si indicizzano tutti i pezzi del cognome: "Lehmann-Willenbrock" deve
    // riconoscersi anche in un file che si chiama solo "willenbrock".
    foreach (preg_split('/[\s-]+/u', mb_strtolower($n['cognome'])) ?: [] as $pezzo) {
        if (mb_strlen($pezzo) >= 4 && !in_array($pezzo, PARTICELLE, true)) {
            $perCognome[$pezzo] = $chiave;
        }
    }
}

// --- si unisce a quello che c'era gia' ---------------------------------------
//
// Il repertorio scaricato da Commons resta, ma SOLO per le persone che questa
// raccolta non ha: due ritratti diversi della stessa persona sarebbero due
// voci scegliibili da due giocatori diversi, e non e' quello che vogliamo.

$vecchio = is_file($projectRoot . '/db/seed/ritratti.php')
    ? (array) require $projectRoot . '/db/seed/ritratti.php'
    : [];

$tenuti = 0;
$sovrascritti = [];
foreach ($vecchio as $k => $v) {
    $parole = preg_split('/[\s-]+/u', mb_strtolower((string) $v['nome'])) ?: [];
    $gia = isset($nuovo[$k]);
    foreach ($parole as $pezzo) {
        if (mb_strlen($pezzo) >= 4 && isset($perCognome[$pezzo])) { $gia = true; }
    }
    if ($gia) {
        $sovrascritti[] = (string) $v['nome'];
        continue;
    }
    $cognome = (string) end($parole);
    $nuovo[$k] = $v + ['fonte' => 'commons', 'nome_ricavato' => false, 'cognome' => ucfirst($cognome)];
    $tenuti++;
}

uasort($nuovo, static fn (array $a, array $b): int =>
    strcmp((string) ($a['cognome'] ?? ''), (string) ($b['cognome'] ?? ''))
    ?: strcmp((string) $a['nome'], (string) $b['nome']));

fwrite(STDERR, sprintf(
    "  %d ritratti dalla raccolta, %d da Commons conservati, %d di Commons sostituiti, %d scartati.\n",
    count($nuovo) - $tenuti, $tenuti, count($sovrascritti), count($scartati)
));
foreach (array_slice($scartati, 0, 12) as $x) { fwrite(STDERR, "    fuori: {$x}\n"); }

if ($prova) {
    $i = 0;
    foreach ($nuovo as $k => $v) {
        if ($i++ >= 25) { break; }
        printf("%-40s %-34s %s\n", $k, $v['nome'], $v['fonte']);
    }
    exit(0);
}

$php = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "/**\n * Ritratti storici dei comandanti di U-Boot.\n *\n"
    . " * GENERATO — non si modifica a mano.\n *\n"
    . " * Due provenienze, e la differenza conta:\n *\n"
    . " *   'commons'  scaricato da Wikimedia Commons con bin/scarica_ritratti.php.\n"
    . " *              Porta con se' licenza e stringa di attribuzione verificate,\n"
    . " *              e il gioco le mostra sotto il ritratto perche' e' una\n"
    . " *              condizione delle licenze CC.\n *\n"
    . " *   'raccolta' importato da una raccolta messa insieme a mano, con\n"
    . " *              bin/importa_ritratti.php. La provenienza del singolo file\n"
    . " *              NON e' verificata, e il gioco lo dice invece di attribuire\n"
    . " *              una licenza che nessuno ha controllato.\n *\n"
    . " * 'nome_ricavato' => true vuol dire che il nome viene dal nome del file,\n"
    . " * smontato da un lettore automatico: e' giusto quasi sempre e sbagliato\n"
    . " * qualche volta.\n */\n\n"
    . 'return ' . var_export($nuovo, true) . ";\n";
file_put_contents($projectRoot . '/db/seed/ritratti.php', $php);

fwrite(STDERR, sprintf("\n%d ritratti in repertorio.\n", count($nuovo)));
