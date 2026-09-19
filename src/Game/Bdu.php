<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Sectors;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * Il Befehlshaber der U-Boote: il regista del mondo condiviso.
 *
 * Assegna le aree operative, emette i comunicati, forma i gruppi, chiede i
 * rapporti meteo (che erano davvero uno dei compiti dei battelli — e ogni
 * rapporto meteo e' una trasmissione che l'HF/DF puo' agganciare).
 *
 * Gli ordini non sono obbligatori: si possono rifiutare e cacciare per conto
 * proprio. Costa prestigio, ma e' una scelta del comandante.
 */
final class Bdu
{
    /** Ore di gioco che un pedinamento deve durare per essere assolto. */
    private const PEDINAMENTO_ORE = 6;

    /** Miglia entro cui si considera mantenuto il contatto. */
    private const PEDINAMENTO_NM = 45.0;

    /** @return list<array<string,mixed>> */
    public static function ordini(int $boatId, bool $soloAperti = false): array
    {
        return Database::all(
            'SELECT * FROM bdu_orders WHERE (boat_id = ? OR boat_id IS NULL)'
            . ($soloAperti ? " AND stato IN ('aperto','accettato')" : '')
            . ' ORDER BY emesso_gts DESC LIMIT 25',
            [$boatId]
        );
    }

    /** Assegna un'area operativa a un battello che ne e' privo. */
    public static function assegnaArea(array $boat, int $gts): ?array
    {
        $aperto = Database::first(
            "SELECT id FROM bdu_orders WHERE boat_id = ? AND tipo = 'area' AND stato IN ('aperto','accettato')",
            [(int) $boat['id']]
        );
        if ($aperto !== null) {
            return null;
        }

        $rng = Rng::for(World::seed(), 'area', (int) $boat['id'], $gts);

        // Dove mandare il battello: dove passa il traffico e dove il mare non
        // e' ancora troppo caldo. Il BdU sa tutte e due le cose.
        $candidati = [
            ['lat' => 54.0, 'lon' => -32.0, 'nome' => 'rotta dei convogli, Atlantico centrale'],
            ['lat' => 51.0, 'lon' => -24.0, 'nome' => 'approcci occidentali, largo'],
            ['lat' => 47.0, 'lon' => -18.0, 'nome' => 'rotta di Gibilterra'],
            ['lat' => 57.0, 'lon' => -28.0, 'nome' => 'passaggio settentrionale'],
            ['lat' => 44.0, 'lon' => -14.0, 'nome' => 'largo del Portogallo'],
            ['lat' => 35.0, 'lon' => -20.0, 'nome' => 'rotta di Freetown'],
        ];

        // Aree gia' assegnate ad altri battelli: il BdU distribuisce i mezzi,
        // non li ammassa tutti sullo stesso quadrato.
        $occupate = array_column(Database::all(
            "SELECT quadrat FROM bdu_orders WHERE tipo = 'area' AND stato IN ('aperto','accettato') AND boat_id <> ?",
            [(int) $boat['id']]
        ), 'quadrat');

        $migliore = null;
        $punteggio = -1e9;
        foreach ($candidati as $c) {
            $heat = Sectors::heat(Sectors::key($c['lat'], $c['lon']), $gts);
            $traffico = count(Traffic::nearby($c['lat'], $c['lon'], 180, $gts));
            $distanza = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], $c['lat'], $c['lon']);
            $quadratoC = Grid::toQuadrat($c['lat'], $c['lon'], 2) ?? '';
            $affollata = in_array($quadratoC, $occupate, true) ? 45 : 0;
            $p = $traffico * 12 - $heat * 1.4 - $distanza / 30 - $affollata + $rng->range(0, 25);
            if ($p > $punteggio) {
                $punteggio = $p;
                $migliore = $c;
            }
        }
        if ($migliore === null) {
            return null;
        }

        $quadrat = Grid::toQuadrat($migliore['lat'], $migliore['lon'], 2) ?? '—';
        $testo = sprintf(
            'Portarsi in quadrato %s (%s) e operare contro il traffico. Segnalare i contatti. '
            . 'Rapporto meteo giornaliero a discrezione del comandante.',
            $quadrat, $migliore['nome']
        );

        Database::run(
            'INSERT INTO bdu_orders (boat_id, commander_id, tipo, quadrat, lat, lon, testo, emesso_gts, scade_gts,
                                     stato, prestigio, punti)
             VALUES (?, ?, "area", ?, ?, ?, ?, ?, ?, "aperto", 260, 35)',
            [
                (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                $quadrat, $migliore['lat'], $migliore['lon'], $testo, $gts, $gts + 12 * 86400,
            ]
        );

        $id = Database::lastInsertId();

        // Se il battello e' gia' in mare, l'area finisce anche nella missione
        // in corso: e' quella che il fascicolo pubblico mostra accanto a ogni
        // uscita.
        Database::run(
            "UPDATE patrols SET area_quadrat = ? WHERE boat_id = ? AND state = 'in_corso'",
            [$quadrat, (int) $boat['id']]
        );

        return Database::first('SELECT * FROM bdu_orders WHERE id = ?', [$id]);
    }

    /**
     * Ordine di pedinamento: tieni il contatto e continua a segnalare.
     *
     * E' il mestiere del Fuehlungshalter, ed e' il piu' ingrato che ci fosse:
     * si sta attaccati a un convoglio per ore senza attaccarlo, si trasmette —
     * e ogni trasmissione e' un rilevamento regalato all'HF/DF — perche' gli
     * altri possano arrivare. Chi pedinava non affondava niente, e senza un
     * premio del BdU non l'avrebbe fatto nessuno.
     *
     * L'ordine nasce da solo quando un battello segnala per radio un convoglio:
     * il BdU risponde chiedendo di restarci attaccato. Non e' obbligatorio —
     * come tutti gli ordini si puo' rifiutare, e costa.
     *
     * @return array<string,mixed>|null l'ordine emesso, o null se non serviva
     */
    public static function ordinaPedinamento(array $boat, int $convoyId, int $gts): ?array
    {
        $aperto = Database::first(
            "SELECT id FROM bdu_orders WHERE boat_id = ? AND tipo = 'pedinamento'
                    AND stato IN ('aperto','accettato')",
            [(int) $boat['id']]
        );
        if ($aperto !== null) {
            return null;   // uno per volta: non si pedinano due convogli insieme
        }

        $cv = Database::first(
            "SELECT * FROM convoys WHERE id = ? AND state = 'in_mare'",
            [$convoyId]
        );
        if ($cv === null) {
            return null;
        }

        // Quanto puo' durare: fino all'arrivo del convoglio, e comunque non
        // piu' di un giorno di gioco. Nessuno regge di piu' a quel mestiere.
        $scade = min((int) $cv['eta_gts'], $gts + 86400);
        if ($scade - $gts < self::PEDINAMENTO_ORE * 3600) {
            return null;   // arriva troppo presto: non c'e' niente da pedinare
        }

        $pos = Traffic::posizione(
            (string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts,
            (float) ($cv['deviazione'] ?? 0)
        );
        if ($pos === null) {
            return null;
        }

        $quadrat = Grid::toQuadrat($pos['lat'], $pos['lon']);
        $testo = sprintf(
            'Contatto ricevuto. Mantenere il contatto con %s %s in quadrato %s e continuare a segnalare. '
            . 'NON attaccare finche\' gli altri non sono sul posto: %d ore di pedinamento.',
            (string) $cv['serie'], (string) $cv['numero'], $quadrat ?? '—', self::PEDINAMENTO_ORE
        );

        Database::run(
            'INSERT INTO bdu_orders (boat_id, commander_id, convoy_id, tipo, quadrat, lat, lon, testo,
                                     emesso_gts, scade_gts, stato, prestigio, punti)
             VALUES (?, ?, ?, "pedinamento", ?, ?, ?, ?, ?, ?, "aperto", 340, 45)',
            [
                (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                (int) $cv['id'], $quadrat, $pos['lat'], $pos['lon'], $testo, $gts, $scade,
            ]
        );

        return Database::first('SELECT * FROM bdu_orders WHERE id = ?', [Database::lastInsertId()]);
    }

    /** Accetta o rifiuta un ordine. */
    public static function rispondi(array $boat, int $ordineId, bool $accetta, int $gts): array
    {
        $o = Database::first(
            "SELECT * FROM bdu_orders WHERE id = ? AND boat_id = ? AND stato = 'aperto'",
            [$ordineId, (int) $boat['id']]
        );
        if ($o === null) {
            return ['ok' => false, 'error' => 'Ordine non piu\' valido.'];
        }

        Database::run(
            'UPDATE bdu_orders SET stato = ? WHERE id = ?',
            [$accetta ? 'accettato' : 'rifiutato', $ordineId]
        );

        // Rifiutare si puo', e costa: il BdU prende nota.
        if (!$accetta && $boat['commander_id'] !== null) {
            Database::run(
                'UPDATE commanders SET prestigio = prestigio - 60 WHERE id = ?',
                [(int) $boat['commander_id']]
            );
        }

        return ['ok' => true, 'accettato' => $accetta, 'testo' => (string) $o['testo']];
    }

    /**
     * Verifica gli ordini in corso: se il battello e' arrivato nell'area
     * assegnata, l'ordine e' assolto e si incassa.
     *
     * @return list<string> eventi da annotare
     */
    public static function verifica(array $boat, int $gts): array
    {
        $eventi = [];
        foreach (Database::all(
            "SELECT * FROM bdu_orders WHERE boat_id = ? AND stato = 'accettato' AND tipo IN ('area','pedinamento')",
            [(int) $boat['id']]
        ) as $o) {
            if ((string) $o['tipo'] === 'pedinamento') {
                // Il bersaglio si muove: non basta arrivare da qualche parte,
                // bisogna restarci attaccati. Si misura la distanza dal
                // convoglio DOVE E' ADESSO, non dal punto segnalato allora.
                foreach (self::verificaPedinamento($boat, $o, $gts) as $e) {
                    $eventi[] = $e;
                }
                continue;
            }
            if ($o['lat'] === null) {
                continue;
            }
            $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $o['lat'], (float) $o['lon']);
            if ($d > 90.0) {
                if ($o['scade_gts'] !== null && $gts > (int) $o['scade_gts']) {
                    Database::run("UPDATE bdu_orders SET stato = 'scaduto' WHERE id = ?", [(int) $o['id']]);
                    $eventi[] = 'Ordine scaduto: l\'area assegnata non e\' stata raggiunta in tempo.';
                }
                continue;
            }

            Database::run("UPDATE bdu_orders SET stato = 'assolto' WHERE id = ?", [(int) $o['id']]);
            if ($boat['commander_id'] !== null) {
                Database::run(
                    'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ?, punti = punti + ?
                     WHERE id = ?',
                    [(int) $o['prestigio'], (int) $o['prestigio'], (int) $o['punti'], (int) $boat['commander_id']]
                );
            }
            $eventi[] = sprintf(
                'Raggiunta l\'area assegnata in quadrato %s: ordine assolto (+%d prestigio, +%d punti).',
                (string) $o['quadrat'], (int) $o['prestigio'], (int) $o['punti']
            );
        }
        return $eventi;
    }

    /**
     * Un pedinamento in corso: si tiene, si perde, o si porta a termine.
     *
     * @param array<string,mixed> $boat
     * @param array<string,mixed> $o
     * @return list<string>
     */
    private static function verificaPedinamento(array $boat, array $o, int $gts): array
    {
        $chiudi = static function (string $stato) use ($o): void {
            Database::run('UPDATE bdu_orders SET stato = ? WHERE id = ?', [$stato, (int) $o['id']]);
        };

        $cv = $o['convoy_id'] !== null
            ? Database::first('SELECT * FROM convoys WHERE id = ?', [(int) $o['convoy_id']])
            : null;
        if ($cv === null) {
            $chiudi('scaduto');
            return [];
        }

        $pos = Traffic::posizione(
            (string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts,
            (float) ($cv['deviazione'] ?? 0)
        );

        // Il convoglio e' arrivato, o e' stato distrutto: il pedinamento
        // finisce comunque. Se si e' stati attaccati abbastanza a lungo, vale.
        $ore = ($gts - (int) $o['emesso_gts']) / 3600.0;
        if ($pos === null || (string) $cv['state'] !== 'in_mare') {
            if ($ore >= self::PEDINAMENTO_ORE) {
                $chiudi('assolto');
                self::paga($boat, $o);
                return [sprintf(
                    'Il convoglio e\' fuori dalla partita: pedinamento concluso (+%d prestigio, +%d punti).',
                    (int) $o['prestigio'], (int) $o['punti']
                )];
            }
            $chiudi('scaduto');
            return ['Il convoglio pedinato e\' uscito di scena prima del tempo: ordine chiuso senza merito.'];
        }

        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], $pos['lat'], $pos['lon']);

        if ($d > self::PEDINAMENTO_NM * 2.5) {
            $chiudi('scaduto');
            return [sprintf(
                'Contatto perduto: il convoglio e\' a %.0f miglia e non lo si riprende piu\'. Pedinamento fallito.',
                $d
            )];
        }

        if ($o['scade_gts'] !== null && $gts > (int) $o['scade_gts']) {
            $chiudi('scaduto');
            return ['Pedinamento scaduto: il tempo utile e\' finito.'];
        }

        if ($ore >= self::PEDINAMENTO_ORE && $d <= self::PEDINAMENTO_NM) {
            $chiudi('assolto');
            self::paga($boat, $o);
            return [sprintf(
                'Sei ore attaccati al convoglio, a %.0f miglia: il BdU ha quello che gli serviva '
                . '(+%d prestigio, +%d punti).',
                $d, (int) $o['prestigio'], (int) $o['punti']
            )];
        }

        return [];
    }

    /** Incassa un ordine assolto. @param array<string,mixed> $boat @param array<string,mixed> $o */
    private static function paga(array $boat, array $o): void
    {
        if ($boat['commander_id'] === null) {
            return;
        }
        Database::run(
            'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ?, punti = punti + ?
             WHERE id = ?',
            [(int) $o['prestigio'], (int) $o['prestigio'], (int) $o['punti'], (int) $boat['commander_id']]
        );
    }

    /**
     * Comunicato di situazione: quello che il BdU dice a tutti.
     * Si emette una volta al giorno di gioco.
     */
    public static function comunicato(int $gts): ?string
    {
        $ultimo = Database::first(
            "SELECT gts FROM radio_messages WHERE destinatario = 'tutti' AND tipo = 'comunicato'
             ORDER BY gts DESC LIMIT 1"
        );
        if ($ultimo !== null && $gts - (int) $ultimo['gts'] < 86400) {
            return null;
        }

        $s = Traffic::stato($gts);
        $aff = Database::first('SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings');
        $persi = (int) (Database::first("SELECT COUNT(*) n FROM boats WHERE state = 'perduto'")['n'] ?? 0);
        $inMare = (int) (Database::first("SELECT COUNT(*) n FROM boats WHERE state = 'mare'")['n'] ?? 0);
        $caldi = Sectors::caldi($gts, 3);

        $testo = sprintf(
            'Situazione: %d battelli in mare, %d perduti dall\'inizio delle operazioni. '
            . 'Naviglio nemico affondato dall\'arma: %d unita\' per %s tonnellate. '
            . 'In mare si contano %d convogli e %d navi isolate.',
            $inMare, $persi, (int) ($aff['n'] ?? 0), number_format((float) ($aff['grt'] ?? 0), 0, ',', '.'),
            $s['convogli'], $s['isolate']
        );

        if ($caldi !== []) {
            $testo .= ' Sorveglianza nemica intensa nei quadrati: '
                . implode(', ', array_map(static fn (array $c): string => $c['quadrat'], $caldi)) . '.';
        }

        Database::run(
            'INSERT INTO radio_messages (destinatario, tipo, testo, gts, durata_s)
             VALUES ("tutti", "comunicato", ?, ?, 0)',
            [$testo, $gts]
        );

        return $testo;
    }

    /** Manutenzione periodica: comunicati, aree, gruppi. Chiamata dal tick. */
    public static function mantieni(int $gts): array
    {
        $out = ['comunicati' => 0, 'aree' => 0];

        if (self::comunicato($gts) !== null) {
            $out['comunicati']++;
        }

        foreach (Database::all("SELECT * FROM boats WHERE state = 'mare'") as $b) {
            if (self::assegnaArea($b, $gts) !== null) {
                $out['aree']++;
            }
        }

        $branchi = Branco::mantieni($gts);
        return array_merge($out, $branchi);
    }
}
