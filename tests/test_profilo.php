<?php

declare(strict_types=1);

/**
 * Il fascicolo pubblico, i ritratti e gli emblemi.
 *
 *   php tests/test_profilo.php
 *
 * Tre regole da tenere ferme, e sono tutte della stessa famiglia: in flottiglia
 * non ci sono due cose uguali. Non due nomi di comandante, non due volti, non
 * due emblemi di torretta, non due U-Boot con lo stesso numero. Il vincolo sta
 * nel database e non in un controllo a mano, e queste prove lo verificano da
 * fuori — cioe' come lo incontrerebbe un giocatore.
 *
 * Tocca il database: crea due utenti di prova e se li porta via.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Core\Database;
use App\Game\Emblema;
use App\Game\Profilo;
use App\Game\Ritratto;

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

/**
 * Un caso che non si e' potuto esercitare.
 *
 * Prima queste righe chiamavano ok(..., true) e uscivano verdi come tutte le
 * altre: un caso che smette di essere provato — perche' il mondo in quel
 * momento non offre la situazione giusta — spariva senza che nessuno se ne
 * accorgesse. Adesso si vede che e' stato saltato, e alla fine si contano.
 */
function saltata(string $titolo, string $perche = ''): void
{
    global $saltate;
    $saltate = ($saltate ?? 0) + 1;
    echo "  \033[0;33m--\033[0m    {$titolo}" . ($perche !== '' ? "  \033[0;90m{$perche}\033[0m" : '') . "\n";
}

$radice = dirname(__DIR__);

// --- il repertorio -----------------------------------------------------------
titolo('Il repertorio dei ritratti');

$rep = Ritratto::repertorio();
ok('il repertorio non e\' vuoto', count($rep) > 0, count($rep) . ' ritratti');

$mancanti = [];
$senzaFonte = [];
$dopoGuerra = [];
$commonsSenzaLicenza = [];
foreach ($rep as $k => $v) {
    if (!is_file($radice . '/assets/' . $v['file'])) { $mancanti[] = $k; }
    if (!in_array((string) ($v['fonte'] ?? ''), ['commons', 'raccolta'], true)) { $senzaFonte[] = $k; }
    if (($v['anno_foto'] ?? null) !== null && (int) $v['anno_foto'] > 1946) { $dopoGuerra[] = $k; }
    // Chi viene da Commons DEVE portare la licenza: e' la condizione con cui si
    // e' scaricato. Chi viene dalla raccolta non ce l'ha, ed e' giusto che non
    // ce l'abbia — quello che non deve succedere e' che se ne inventi una.
    if ((string) ($v['fonte'] ?? '') === 'commons' && trim((string) ($v['licenza'] ?? '')) === '') {
        $commonsSenzaLicenza[] = $k;
    }
}
ok('ogni voce ha il suo file sul disco', $mancanti === [], implode(', ', array_slice($mancanti, 0, 3)));
ok('ogni voce dichiara da dove viene', $senzaFonte === [], implode(', ', array_slice($senzaFonte, 0, 3)));
ok('quelle da Commons portano la loro licenza', $commonsSenzaLicenza === [],
    implode(', ', array_slice($commonsSenzaLicenza, 0, 3)));
ok('nessuna fotografia del dopoguerra', $dopoGuerra === [], implode(', ', $dopoGuerra));

// Nessuna voce deve dichiarare una licenza che non ha: quella della raccolta si
// dichiara non verificata, e la frase mostrata al giocatore deve dirlo.
$bugie = [];
foreach ($rep as $k => $v) {
    if ((string) ($v['fonte'] ?? '') !== 'raccolta') { continue; }
    $d = Ritratto::dichiarazione(Ritratto::di(['ritratto_key' => $k, 'ritratto_file' => null]) ?? []);
    if (!str_contains($d, 'non verificata')) { $bugie[] = $k; }
}
ok('la raccolta dichiara che la provenienza non e\' verificata', $bugie === [],
    implode(', ', array_slice($bugie, 0, 3)));

// La dichiarazione mostrata sotto il ritratto deve contenere la licenza o
// l'attribuzione: e' una condizione delle licenze CC, non un abbellimento.
$primo = array_key_first($rep);
$d = Ritratto::dichiarazione(Ritratto::di(['ritratto_key' => $primo, 'ritratto_file' => null]) ?? []);
ok('la dichiarazione dice sempre qualcosa sulla fonte',
    str_contains($d, 'Fotografia storica') && mb_strlen($d) > 40, mb_substr($d, 0, 70) . '…');

titolo('Il repertorio degli emblemi');

$emb = Emblema::repertorio();
ok('il repertorio non e\' vuoto', count($emb) >= 30, count($emb) . ' emblemi');
$embMancanti = [];
foreach ($emb as $k => $v) {
    if (!is_file($radice . '/assets/img/emblemi/' . $v['file'])) { $embMancanti[] = $k; }
}
ok('ogni emblema ha il suo disegno', $embMancanti === [], implode(', ', array_slice($embMancanti, 0, 3)));

$svgRotti = [];
foreach ($emb as $k => $v) {
    $x = @simplexml_load_file($radice . '/assets/img/emblemi/' . $v['file']);
    if ($x === false) { $svgRotti[] = $k; }
}
ok('i disegni sono SVG validi', $svgRotti === [], implode(', ', array_slice($svgRotti, 0, 3)));

// --- unicita' ----------------------------------------------------------------
titolo('In flottiglia non ci sono due cose uguali');

$utenti = [];
$comandanti = [];
for ($i = 1; $i <= 2; $i++) {
    $u = 'prova profilo' . $i . ' ' . time() . $i;
    $reg = \App\Auth\Auth::register($u, 'pf' . $i . time() . '@esempio.invalid', 'kommandant42', '127.0.0.1');
    $uid = (int) ($reg['user_id'] ?? 0);
    if ($uid === 0) { continue; }
    Database::run("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?", [$uid]);
    \App\Game\Fleet::ensureBoat($uid);          // il comandante segue il battello, non viceversa
    $res = \App\Game\Comandante::crea($uid, [
        'nome' => 'Prova Fascicolo ' . $i . ' ' . time(), 'nato_il' => '1913-01-0' . $i,
        'nato_a' => 'Kiel', 'base' => 'lorient',
    ]);
    $utenti[] = $u;
    if ($res['ok']) { $comandanti[] = (int) $res['commander_id']; }
}

if (count($comandanti) < 2) {
    echo "  \033[0;90mnon si sono potuti creare due comandanti: prove saltate\033[0m\n";
} else {
    [$a, $b] = $comandanti;
    $chiave = array_key_first($rep);

    $r1 = Ritratto::scegli($a, $chiave);
    ok('il primo prende un volto libero', (bool) $r1['ok'], (string) ($r1['error'] ?? ''));

    $r2 = Ritratto::scegli($b, $chiave);
    ok('il secondo non puo\' prendere lo stesso volto', !$r2['ok'],
        (string) ($r2['error'] ?? 'accettato, e non doveva'));

    // Liberato, torna disponibile.
    Ritratto::togli($a);
    $r3 = Ritratto::scegli($b, $chiave);
    ok('liberato, il volto torna disponibile', (bool) $r3['ok'], (string) ($r3['error'] ?? ''));

    // Il nome storico: se e' gia' in servizio, il volto resta e il nome no.
    $nomeStorico = (string) $rep[$chiave]['nome'];
    Database::run('UPDATE commanders SET nome = ? WHERE id = ?', [$nomeStorico, $a]);
    Ritratto::togli($b);
    $r4 = Ritratto::scegli($b, $chiave, true);
    $nomeB = (string) Database::first('SELECT nome FROM commanders WHERE id = ?', [$b])['nome'];
    ok('il nome storico non scavalca un nome gia\' in servizio',
        (bool) $r4['ok'] && $nomeB !== $nomeStorico, 'si chiama ancora ' . $nomeB);

    // Con il nome libero, invece, lo prende davvero.
    Database::run('UPDATE commanders SET nome = ? WHERE id = ?', ['Libero ' . time(), $a]);
    Ritratto::togli($b);
    $r5 = Ritratto::scegli($b, $chiave, true);
    $nomeB2 = (string) Database::first('SELECT nome, nome_storico FROM commanders WHERE id = ?', [$b])['nome'];
    $flag = (int) Database::first('SELECT nome_storico FROM commanders WHERE id = ?', [$b])['nome_storico'];
    ok('col nome libero prende anche quello, e lo si dichiara',
        (bool) $r5['ok'] && $nomeB2 === $nomeStorico && $flag === 1, $nomeB2);

    // Una fotografia PORTATA DA CASA invece non e' unica: se due giocatori
    // caricano lo stesso file, sono affari loro. L'unicita' riguarda solo il
    // repertorio, che e' l'elenco di persone realmente esistite.
    $doppio = true;
    Database::run('UPDATE commanders SET ritratto_key = NULL, ritratto_file = ?, ritratto_hash = ? WHERE id = ?',
        [str_repeat('a', 64) . '.webp', str_repeat('a', 64), $a]);
    try {
        Database::run('UPDATE commanders SET ritratto_key = NULL, ritratto_file = ?, ritratto_hash = ? WHERE id = ?',
            [str_repeat('a', 64) . '.webp', str_repeat('a', 64), $b]);
    } catch (PDOException $e) {
        $doppio = false;
    }
    ok('la stessa fotografia caricata puo\' stare su due fascicoli', $doppio);

    // --- il fascicolo pubblico -----------------------------------------------
    titolo('Quello che il fascicolo racconta');

    Database::run('UPDATE commanders SET ritratto_file = NULL, ritratto_hash = NULL WHERE id IN (?, ?)', [$a, $b]);
    $boat = Database::first('SELECT * FROM boats WHERE commander_id = ?', [$a]);
    if ($boat !== null) {
        // Due affondamenti finti: una scorta e un mercantile neutrale.
        foreach ([['ct_town', 'britannica', 1190], ['cargo_medio', 'neutrale', 4200]] as [$cls, $flag2, $grt]) {
            Database::run(
                'INSERT INTO sinkings (boat_id, commander_id, nome, bandiera, class_key, grt, arma, gts, lat, lon)
                 VALUES (?, ?, ?, ?, ?, ?, "siluro", 0, 50, -20)',
                [(int) $boat['id'], $a, 'Prova ' . $cls . ' ' . time(), $flag2, $cls, $grt]
            );
        }

        $p = Profilo::di($a);
        ok('il fascicolo si compone', $p !== null);
        ok('distingue il naviglio militare da quello civile',
            (int) $p['per_genere']['militare']['navi'] === 1 && (int) $p['per_genere']['civile']['navi'] === 1,
            sprintf('%d militari, %d civili',
                (int) $p['per_genere']['militare']['navi'], (int) $p['per_genere']['civile']['navi']));
        ok('la stazza va nel gruppo giusto',
            (int) $p['per_genere']['militare']['grt'] === 1190 && (int) $p['per_genere']['civile']['grt'] === 4200);

        $bandiere = [];
        foreach ($p['per_bandiera'] as $x) { $bandiere[(string) $x['bandiera']] = $x; }
        ok('divide per bandiera', isset($bandiere['britannica'], $bandiere['neutrale']),
            implode(', ', array_keys($bandiere)));
        ok('segna quale bandiera e\' neutrale',
            ($bandiere['neutrale']['neutrale'] ?? false) === true
            && ($bandiere['britannica']['neutrale'] ?? true) === false);
        ok('conta le navi affondate', (int) $p['numeri']['navi'] === 2, (string) $p['numeri']['navi']);
        ok('le prede maggiori sono in ordine di stazza',
            count($p['migliori']) === 2 && (int) $p['migliori'][0]['grt'] === 4200);

        Database::run('DELETE FROM sinkings WHERE commander_id = ?', [$a]);
    }

    // --- emblemi e numeri di battello ----------------------------------------
    titolo('L\'emblema si porta in tanti, il numero no');

    // Molti segni di torretta erano di FLOTTIGLIA: il toro di Prien diventa
    // quello di tutta la 7. U-Flottille e lo portano decine di battelli. Un
    // emblema esclusivo sarebbe stato storicamente sbagliato.
    $battelli = Database::all('SELECT id FROM boats WHERE commander_id IN (?, ?)', [$a, $b]);
    if (count($battelli) >= 2) {
        $chiaveE = array_key_first($emb);
        $e1 = Emblema::scegli((int) $battelli[0]['id'], $chiaveE);
        $e2 = Emblema::scegli((int) $battelli[1]['id'], $chiaveE);
        ok('il primo adotta un emblema', (bool) $e1['ok'], (string) ($e1['error'] ?? ''));
        ok('anche il secondo puo\' portare lo stesso emblema', (bool) $e2['ok'],
            (string) ($e2['error'] ?? ''));

        $cat = Emblema::catalogo((int) $battelli[0]['id']);
        $voce = null;
        foreach ($cat as $x) { if ($x['chiave'] === $chiaveE) { $voce = $x; } }
        ok('il repertorio dice chi altro lo porta',
            $voce !== null && $voce['lo_portano'] !== [], implode(', ', $voce['lo_portano'] ?? []));

        Emblema::togli((int) $battelli[0]['id']);
        Emblema::togli((int) $battelli[1]['id']);
    }

    // Il vincolo se n'e' andato con la migrazione 0026: se qualcuno lo rimettesse,
    // la prova di sopra fallirebbe solo quando due battelli provano davvero a
    // condividere un segno. Qui si guarda lo schema, che e' dove stava.
    $indici = Database::all("SHOW INDEX FROM boats WHERE Column_name IN ('emblema_key', 'emblema_hash')");
    $esclusivi = array_values(array_filter($indici, static fn (array $i): bool => (int) $i['Non_unique'] === 0));
    ok('nello schema non c\'e\' piu\' un vincolo di unicita\' sull\'emblema',
        $esclusivi === [], implode(', ', array_column($esclusivi, 'Key_name')));

    // E il testo che il giocatore legge in cantiere deve dire la stessa cosa del
    // codice. Il 19/09/2026 diceva ancora il contrario: la regola era cambiata
    // da un pezzo e la pagina prometteva ancora un emblema per battello.
    $cantiere = (string) file_get_contents(dirname(__DIR__) . '/views/game/cantiere.php');
    $bugie = array_values(array_filter(
        ['Nessun emblema si porta in due', 'sparisce dal repertorio', 'un emblema per battello'],
        static fn (string $frase): bool => str_contains($cantiere, $frase)
    ));
    ok('il cantiere non promette un emblema esclusivo', $bugie === [], implode(' | ', $bugie));
    ok('il cantiere spiega che lo stesso emblema si porta in tanti',
        str_contains($cantiere, 'più torrette') && str_contains($cantiere, 'flottiglia'));

    // Fra i VIVI, non in assoluto: un battello perduto restituisce il suo numero,
    // come un comandante caduto restituisce nome e volto (migrazione 0025, che
    // indicizza vivo_numero = IF(state <> 'perduto', uboat_number, NULL)).
    // Questa prova chiedeva l'unicita' su tutta la flotta, perduti compresi: ha
    // retto finche' nessuno ha riusato un numero liberato, cioe' finche' nessuno
    // ha fatto quello che il gioco gli permette apposta.
    // Ogni emblema, dovunque compaia, si presenta tondo e con la lente attaccata.
    // Il 19/09/2026 il fascicolo ne mostrava uno quadrato, col fondo bianco del
    // file in vista, mentre in testata lo stesso file era tondo: la cornice
    // c'era in un posto e non nell'altro.
    $senzaCornice = [];
    $radiceViste = dirname(__DIR__) . '/views';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radiceViste));
    $immagini = 0;
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $breve = substr($f->getPathname(), strlen(dirname($radiceViste)) + 1);
        $testo = (string) file_get_contents($f->getPathname());
        // I delimitatori PHP diventano parentesi graffe: dentro un tag di stampa
        // c'e' un ">" (quello che lo chiude) che altrimenti chiuderebbe il tag
        // <img> prima del tempo, e meta' degli attributi sparirebbe dal
        // controllo. Nota per chi legge: un delimitatore di chiusura scritto
        // qui in un commento chiuderebbe il PHP per davvero, quindi non c'e'.
        $testo = strtr($testo, ['<?=' => '{{', '<?php' => '{{', '?>' => '}}']);
        preg_match_all('/<img\b[^>]*>/s', $testo, $tag);
        foreach ($tag[0] as $img) {
            // Solo le immagini che sono davvero un emblema.
            if (!preg_match('/src="[^"]*(?:emblema|\$em\[)/', $img)) {
                continue;
            }
            $immagini++;
            $tonda = str_contains($img, 'emblema-tondo') || str_contains($img, 'emblema-torretta');
            $lente = str_contains($img, 'data-emblema=');
            if (!$tonda || !$lente) {
                $senzaCornice[] = $breve . ($tonda ? '' : ' (cornice)') . ($lente ? '' : ' (lente)');
            }
        }
    }
    ok('ogni emblema a video e\' tondo e ha la lente', $senzaCornice === [],
        $senzaCornice === [] ? "{$immagini} immagini" : implode(' | ', $senzaCornice));

    $doppioNumero = (int) (Database::first(
        "SELECT COUNT(*) n FROM (SELECT uboat_number FROM boats WHERE state <> 'perduto'
                                  GROUP BY uboat_number HAVING COUNT(*) > 1) t"
    )['n'] ?? 0);
    ok('nessun numero di U-Boot ripetuto fra i battelli in servizio', $doppioNumero === 0, (string) $doppioNumero);

    // E il numero di un battello perduto deve poter tornare in mare.
    $riusati = (int) (Database::first(
        "SELECT COUNT(*) n FROM boats a JOIN boats b ON a.uboat_number = b.uboat_number AND a.id <> b.id
          WHERE a.state = 'perduto' AND b.state <> 'perduto'"
    )['n'] ?? 0);
    // Non e' una verifica ma un rilevamento: si scrive quanti numeri liberati
    // sono gia' tornati in mare, perche' e' il segno che la regola vive.
    printf("  \033[0;36m..\033[0m    numeri di battelli perduti gia' tornati in mare: %s\n",
        $riusati > 0 ? (string) $riusati : 'nessuno finora');

    $doppioNome = (int) (Database::first(
        "SELECT COUNT(*) n FROM (SELECT nome FROM commanders WHERE stato = 'attivo'
                                  GROUP BY nome HAVING COUNT(*) > 1) t"
    )['n'] ?? 0);
    ok('nessun nome ripetuto fra i comandanti in servizio', $doppioNome === 0, (string) $doppioNome);

    // --- quando si muore, il posto si libera ---------------------------------
    titolo('Chi cade restituisce il nome e il volto');

    Ritratto::togli($a);
    Ritratto::togli($b);
    $chiave2 = array_key_first($rep);
    $nomeMorto = 'Caduto Di Prova ' . time();

    Database::run('UPDATE commanders SET nome = ? WHERE id = ?', [$nomeMorto, $a]);
    $primo = Ritratto::scegli($a, $chiave2);
    ok('il primo prende volto e nome', (bool) $primo['ok'], (string) ($primo['error'] ?? ''));

    $bloccato = Ritratto::scegli($b, $chiave2);
    ok('finche\' e\' in servizio, il volto e\' suo', !$bloccato['ok']);

    $nomeBloccato = false;
    try {
        Database::run('UPDATE commanders SET nome = ? WHERE id = ?', [$nomeMorto, $b]);
    } catch (PDOException $e) {
        $nomeBloccato = $e->getCode() === '23000';
    }
    ok('e anche il nome e\' suo', $nomeBloccato);

    // Cade in azione.
    Database::run("UPDATE commanders SET stato = 'caduto', sorte = 'Caduto in prova' WHERE id = ?", [$a]);

    $liberato = Ritratto::scegli($b, $chiave2);
    ok('caduto lui, il volto torna disponibile', (bool) $liberato['ok'], (string) ($liberato['error'] ?? ''));

    $nomeLibero = true;
    try {
        Database::run('UPDATE commanders SET nome = ? WHERE id = ?', [$nomeMorto, $b]);
    } catch (PDOException $e) {
        $nomeLibero = false;
    }
    ok('e anche il nome torna disponibile', $nomeLibero);

    // Il caduto NON perde il suo ritratto: nell'albo d'oro resta il suo volto.
    $morto = Database::first('SELECT ritratto_key, nome FROM commanders WHERE id = ?', [$a]);
    ok('il caduto conserva il suo ritratto nel fascicolo',
        (string) ($morto['ritratto_key'] ?? '') === $chiave2, (string) ($morto['ritratto_key'] ?? '—'));
    ok('e conserva il suo nome', (string) $morto['nome'] === $nomeMorto);

    // Lo stesso vale per i battelli.
    titolo('Chi affonda restituisce il suo numero');

    $b1 = Database::first('SELECT * FROM boats WHERE commander_id = ?', [$a]);
    $b2 = Database::first('SELECT * FROM boats WHERE commander_id = ?', [$b]);
    if ($b1 !== null && $b2 !== null) {
        $numero = (string) $b1['uboat_number'];
        $numeroBloccato = false;
        try {
            Database::run('UPDATE boats SET uboat_number = ? WHERE id = ?', [$numero, (int) $b2['id']]);
        } catch (PDOException $e) {
            $numeroBloccato = $e->getCode() === '23000';
        }
        ok('e anche il numero e\' suo', $numeroBloccato,
            sprintf('b1=%s(%s) b2=%s(%s)', (string) $b1['uboat_number'], (string) $b1['state'],
                (string) $b2['uboat_number'], (string) $b2['state']));

        Database::run("UPDATE boats SET state = 'perduto' WHERE id = ?", [(int) $b1['id']]);

        $numeroLibero = true;
        try {
            Database::run('UPDATE boats SET uboat_number = ? WHERE id = ?', [$numero, (int) $b2['id']]);
        } catch (PDOException $e) {
            $numeroLibero = false;
        }
        ok('e anche il numero torna al mare', $numeroLibero);
    }
}

// --- due copie della stessa ricetta -------------------------------------------
titolo('Le due ricette dell\'invecchiamento concordano');

// L'invecchiamento esiste in due posti: in PHP per chi carica senza JavaScript,
// e in JavaScript perche' l'effetto si veda subito quando si accende la casella.
// Sono due copie della stessa cosa, ed e' il genere di duplicazione che si
// slega in silenzio: qualcuno ritara i numeri da una parte e l'anteprima
// comincia a raccontare una cosa diversa da quella che il server produce.
//
// Qui si controlla che i numeri che contano siano ancora gli stessi in tutte e
// due. Non e' una prova del risultato — per quella servirebbe eseguire il
// JavaScript — ma prende il caso che capita davvero.
$php = (string) file_get_contents($radice . '/src/Game/Ritratto.php');
$js  = (string) file_get_contents($radice . '/assets/js/invecchia.js');

$numeri = [
    'luminanza della galleria'      => '0.51',
    'contrasto della galleria'      => '0.215',
    'forza della vignettatura'      => '0.22',
    'esponente della vignettatura'  => '2.2',
    'quota della spinta d\'esposizione' => '0.85',
];
foreach ($numeri as $che => $valore) {
    ok('stesso valore di qua e di la\': ' . $che,
        str_contains($php, $valore) && str_contains($js, $valore), $valore);
}

ok('stessa grana in tutte e due', str_contains($php, 'random_int(-6, 6)') && str_contains($js, '* 13) - 6'));
ok('stessa virata finale in tutte e due',
    str_contains($php, 'IMG_FILTER_COLORIZE, 2, 1, -1')
    && str_contains($js, 'd[i] += 2; d[i + 1] += 1; d[i + 2] -= 1;'));
ok('stessa ricerca del contrasto per bisezione',
    str_contains($php, '$alto = 48') && str_contains($js, 'alto = 48'));

// E il server non deve rifare quello che ha gia' fatto il browser.
$prof = (string) file_get_contents($radice . '/src/Controllers/ProfiloController.php');
$carr = (string) file_get_contents($radice . '/src/Controllers/CarrieraController.php');
ok('il server non invecchia due volte',
    str_contains($prof, 'gia_invecchiata') && str_contains($carr, 'gia_invecchiata'));

// --- la galleria non e' una bacheca ------------------------------------------
titolo('Una fotografia caricata resta di chi l\'ha caricata');

// Il repertorio e' un elenco scritto (db/seed/ritratti.php): le fotografie
// portate da casa NON ci finiscono, e quindi non possono comparire fra le
// scelte di nessun altro. Qui lo si verifica dal di fuori, perche' e' il tipo
// di cosa che si rompe in silenzio il giorno in cui qualcuno sostituisce
// l'elenco con una lettura della cartella.
$impronta = str_repeat('c', 64);
$finta = $impronta . '.webp';
@file_put_contents($radice . '/assets/img/ritratti/caricati/' . $finta, 'finta');

$nelRepertorio = false;
foreach (Ritratto::repertorio() as $v) {
    if (str_contains((string) $v['file'], 'caricati/')) { $nelRepertorio = true; }
}
ok('nessun file caricato sta nel repertorio', !$nelRepertorio);

$nelCatalogo = false;
foreach (Ritratto::catalogo() as $v) {
    if (str_contains((string) $v['url'], 'caricati/')) { $nelCatalogo = true; }
}
ok('nessun file caricato compare fra le scelte', !$nelCatalogo);

// E la cartella dei caricati non dev'essere elencabile: il nome di ogni file e'
// un'impronta, ma un elenco renderebbe inutile l'impronta.
$htaccess = $radice . '/assets/img/ritratti/caricati/.htaccess';
ok('la cartella dei caricati vieta l\'elenco',
    is_file($htaccess) && str_contains((string) file_get_contents($htaccess), 'Options -Indexes'));

@unlink($radice . '/assets/img/ritratti/caricati/' . $finta);

// --- il filtro d'epoca --------------------------------------------------------
titolo('Rendere vecchia una fotografia nuova');

$prova = imagecreatetruecolor(64, 64);
imagefilledrectangle($prova, 0, 0, 64, 64, imagecolorallocate($prova, 70, 130, 180));
imagefilledellipse($prova, 32, 30, 34, 40, imagecolorallocate($prova, 224, 172, 138));
$primaC = imagecolorat($prova, 32, 30);
$primaSfondo = imagecolorat($prova, 2, 2);

App\Game\Ritratto::invecchia($prova);

$dopoC = imagecolorat($prova, 32, 30);
$dopoSfondo = imagecolorat($prova, 2, 2);
$r = ($dopoC >> 16) & 0xFF; $g = ($dopoC >> 8) & 0xFF; $b = $dopoC & 0xFF;

ok('il filtro cambia davvero l\'immagine', $dopoC !== $primaC);
// La virata dev'essere UN SOFFIO, non un seppia. Misurato sui 508 volti veri,
// lo scarto fra rosso e blu ha mediana zero: le dominanti che si vedono sono
// rumore di scansione, e vanno in tutte e due le direzioni. Un filtro che vira
// forte fa una cartolina, non una fotografia del 1942.
ok('toglie il colore e resta quasi neutro', $r >= $b && ($r - $b) <= 8,
    sprintf('R%d G%d B%d, scarto R-B %+d', $r, $g, $b, $r - $b));
ok('gli angoli restano piu\' scuri del centro',
    (($dopoSfondo >> 16) & 0xFF) < $r, sprintf('angolo %d, centro %d', ($dopoSfondo >> 16) & 0xFF, $r));

// La grana non deve poter portare un pixel fuori scala: un canale oltre 255
// verrebbe troncato e comparirebbero macchie.
$fuoriScala = false;
for ($y = 0; $y < 64; $y += 7) {
    for ($x = 0; $x < 64; $x += 7) {
        $c = imagecolorat($prova, $x, $y);
        foreach ([($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF] as $canale) {
            if ($canale < 0 || $canale > 255) { $fuoriScala = true; }
        }
    }
}
ok('nessun canale fuori scala', !$fuoriScala);
imagedestroy($prova);

// L'esposizione va portata verso quella della galleria: una fotografia moderna
// ben esposta e' molto piu' scura di una stampa del 1942, e nella griglia si
// vedrebbe che e' scura prima ancora che e' moderna.
$scura = imagecreatetruecolor(64, 64);
imagefilledrectangle($scura, 0, 0, 64, 64, imagecolorallocate($scura, 40, 40, 44));
App\Game\Ritratto::invecchia($scura);
$mediaDopo = 0.0;
for ($y = 4; $y < 60; $y += 4) {
    for ($x = 4; $x < 60; $x += 4) { $mediaDopo += ((imagecolorat($scura, $x, $y) >> 16) & 0xFF) / 255.0; }
}
$mediaDopo /= (14 * 14);
imagedestroy($scura);
ok('una fotografia scura viene portata verso la luminosita\' della galleria',
    $mediaDopo > 0.30, 'da 0,16 a ' . number_format($mediaDopo, 2, ',', ''));

foreach ($utenti as $u) {
    shell_exec(sprintf('%s %s %s 2>/dev/null',
        PHP_BINARY, escapeshellarg($radice . '/bin/_cleanup_test_user.php'), escapeshellarg($u)));
}

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m"
    . (($saltate ?? 0) > 0 ? "  \033[0;33m({$saltate} saltate)\033[0m" : "") . "\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
