<?php

declare(strict_types=1);

/**
 * Atlantik — battito del mondo.
 *
 * Da cron, ogni minuto:
 *   * * * * * /usr/bin/php <installazione>/bin/tick.php >/dev/null 2>&1
 *
 * Fa avanzare tutti i battelli in mare fino all'ora attuale. Non e' l'unico
 * modo in cui il mondo procede: anche la richiesta del giocatore fa avanzare
 * il proprio battello (avanzamento pigro). Il tick serve perche' il mondo
 * cammini anche per chi non e' collegato — che e' tutto il punto di una
 * crociera a tempo compresso.
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Sim\BoatSim;
use App\Sim\Encounter;
use App\Sim\Traffic;
use App\Sim\World;

$avvio = microtime(true);
$lock  = $projectRoot . '/storage/tick.lock';

// Un solo tick per volta: se il precedente e' ancora in corso si esce subito.
$fp = fopen($lock, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$id = null;
try {
    if (!World::exists()) {
        exit(0);
    }

    Database::run(
        'INSERT INTO tick_runs (started_at, ok) VALUES (NOW(3), 0)'
    );
    $id = Database::lastInsertId();

    // Ogni fase del mondo sta in piedi da sola.
    //
    // Il 18/09/2026 una collisione di nome fra due navi generate ha fatto
    // saltare Traffic::ensure(), e con esso tutto il battito: per quel minuto
    // nessun battello si e' mosso. Un guasto nel popolamento dell'oceano non
    // deve poter fermare i battelli dei giocatori, che sono la cosa che non
    // puo' aspettare. Ogni fase ora si arrangia: se cade, cade da sola, lascia
    // detto perche', e il battito va avanti.
    $guasti = [];
    $fase = static function (string $nome, callable $f, array $difetto) use (&$guasti): array {
        try {
            return $f();
        } catch (\Throwable $e) {
            $guasti[] = $nome . ': ' . $e->getMessage();
            logger('tick: fase ' . $nome . ' — ' . $e->getMessage(), 'error');
            return $difetto;
        }
    };

    // Il mare va tenuto popolato: convogli che salpano, navi che arrivano.
    $traffico = $fase('traffico', static fn (): array => Traffic::ensure(World::now()),
        ['convogli' => 0, 'navi' => 0, 'arrivati' => 0]);

    // Il BdU lavora anche di notte: comunicati, aree operative, gruppi.
    $bdu = $fase('bdu', static fn (): array => \App\Game\Bdu::mantieni(World::now()),
        ['comunicati' => 0, 'aree' => 0, 'aperti' => 0]);

    // Chi era stato colpito e non era affondato subito: qualcuno non ce la fa.
    $agonia = $fase('agonia', static fn (): array => \App\Sim\Danni::agonia(World::now()),
        ['affondate' => 0, 'grt' => 0]);

    // Incontri in corso: il mondo non si ferma perche' il comandante non e'
    // davanti allo schermo. Scaduta la finestra di condotta, prende il
    // Primo Ufficiale e disimpegna.
    $incontri = 0;
    foreach (Database::all("SELECT * FROM encounters WHERE stato <> 'concluso'") as $enc) {
        try {
            Encounter::passoAutomatico((int) $enc['id']);
            $incontri++;
        } catch (\Throwable $e) {
            logger('tick: incontro ' . $enc['id'] . ' — ' . $e->getMessage(), 'error');
        }
    }

    $fatti = 0;
    $errori = 0;
    $miglia = 0.0;
    $eventi = 0;

    foreach (Database::all("SELECT id FROM boats WHERE state = 'mare'") as $b) {
        try {
            $r = BoatSim::advance((int) $b['id']);
            $fatti++;
            $miglia += $r['dist_nm'];
            $eventi += $r['events'];
        } catch (\Throwable $e) {
            // Un battello che sparisce fra la lettura dell'elenco e il suo
            // turno non e' un guasto: e' un account cancellato mentre il
            // battito era gia' partito. Succede a ogni pulizia delle prove, e
            // marcava il battito come fallito nella diagnostica.
            $c = Database::first('SELECT id FROM boats WHERE id = ?', [(int) $b['id']]);
            if ($c === null) {
                continue;
            }
            $errori++;
            logger('tick: battello ' . $b['id'] . ' — ' . $e->getMessage(), 'error');
        }
    }

    // Manutenzione leggera: i freni scaduti non servono a nessuno.
    $fase('freni', static function (): array { \App\Core\RateLimiter::gc(); return []; }, []);

    // Posta in uscita: qualche messaggio per battito. Chi non e' partito al
    // primo colpo riparte da qui, con attesa crescente (audit A6).
    $posta = $fase('posta', static fn (): array => \App\Core\Posta::smista(), ['inviati' => 0]);

    // Potatura: una volta ogni mezz'ora, non a ogni battito. Il naviglio
    // arrivato in porto e i diari dei battiti vecchi non servono piu' a
    // nessuno, e crescendo rallenterebbero tutto (audit A4).
    $potato = ['navi' => 0, 'convogli' => 0, 'battiti' => 0, 'radio' => 0];
    if ((int) date('i') % 30 === 7) {
        $potato = $fase('potatura', static function (): array {
            $p = Traffic::pota(World::now()) + ['battiti' => 0];
            $p['battiti'] = Database::run(
                'DELETE FROM tick_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 5000'
            )->rowCount();
            \App\Core\Posta::pota(30);

            // Le trasmissioni dei battelli che non ci sono piu'. La chiave
            // esterna azzera boat_id invece di portarsi via la riga, cosi' un
            // account cancellato lascia in giro i suoi rapporti al BdU e le sue
            // segnalazioni al branco: roba che non ha piu' un mittente e non
            // serve piu' a nessuno. I comunicati del BdU (destinatario "tutti")
            // e le sue risposte ("battello") nascono senza battello mittente e
            // restano dove sono.
            $p['radio'] = Database::run(
                'DELETE FROM radio_messages
                  WHERE boat_id IS NULL AND destinatario IN ("bdu", "branco") LIMIT 2000'
            )->rowCount();
            \App\Game\Emblema::potaOrfani();
            \App\Game\Ritratto::potaOrfani();
            return $p;
        }, $potato);
    }

    $ms = (int) round((microtime(true) - $avvio) * 1000);
    Database::run(
        'UPDATE tick_runs SET finished_at = NOW(3), ok = ?, duration_ms = ?, tasks = ? WHERE id = ?',
        [
            $errori === 0 && $guasti === [] ? 1 : 0,
            $ms,
            json_encode([
                'comunicati'     => $bdu['comunicati'],
                'aree_assegnate' => $bdu['aree'],
                'branchi_aperti' => $bdu['aperti'],
                'convogli_nuovi' => $traffico['convogli'],
                'potati_navi'    => $potato['navi'],
                'potati_convogli' => $potato['convogli'],
                'potati_battiti' => $potato['battiti'],
                'potati_radio'   => $potato['radio'] ?? 0,
                'posta_inviata'  => $posta['inviati'],
                'posta_in_coda'  => $fase('conteggio posta',
                    static fn (): array => \App\Core\Posta::stato(), ['in_coda' => 0])['in_coda'],
                'navi_nuove'     => $traffico['navi'],
                'arrivati'       => $traffico['arrivati'],
                'convogli_dispersi' => $traffico['dispersi'] ?? 0,
                'agonia_navi'    => $agonia['affondate'],
                'agonia_grt'     => $agonia['grt'],
                'incontri' => $incontri,
                'battelli' => $fatti,
                'errori'   => $errori,
                'miglia'   => round($miglia, 1),
                'eventi'   => $eventi,
                'gts'      => World::now(),
                'guasti'   => $guasti,
            ], JSON_UNESCAPED_UNICODE),
            $id,
        ]
    );
} catch (\Throwable $e) {
    logger('tick fallito: ' . $e->getMessage(), 'error');
    if ($id !== null) {
        try {
            Database::run(
                'UPDATE tick_runs SET finished_at = NOW(3), ok = 0, note = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 255), $id]
            );
        } catch (\Throwable) {
        }
    }
    exit(1);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
