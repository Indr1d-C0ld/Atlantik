<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\BoatSim;
use App\Sim\Contacts;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Torpedo;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * L'addestramento tattico: l'Agru-Front nel Baltico.
 *
 * Nessun comandante andava al fronte senza esserci passato. L'Ausbildungsgruppe
 * fuer Frontunterseeboote, a Hela e Gotenhafen, faceva attaccare ai battelli
 * nuovi convogli d'esercitazione nella baia di Danzica: vecchi piroscafi in
 * colonna, cacciasommergibili della scuola a fare la scorta, siluri con la testa
 * d'esercitazione — senza carica, regolati per passare sotto la chiglia e
 * tornare a galla a fine corsa, dove una motobarca li ripescava. Un istruttore a
 * bordo giudicava avvicinamento, lancio e disimpegno, e chi non passava ripeteva.
 *
 * Qui e' un'uscita vera, con la stessa simulazione della guerra: il battello e'
 * trasferito nel Baltico e messo davanti a un convoglio d'esercitazione, il
 * comandante gli da' battaglia dalla stazione d'attacco. Le differenze:
 *
 *   - i siluri colpiscono e non fanno danno (Encounter, «testa d'esercitazione»);
 *   - le cariche dei cacciasommergibili sono di segnalazione: non fanno danno,
 *     ma quello che avrebbero fatto in guerra si conta, e pesa sul giudizio;
 *   - niente cannone, niente aerei, niente BdU: la missione e' di tipo
 *     'addestramento' e non conta fra quelle di guerra;
 *   - quello che si consuma (siluri, nafta, viveri, aria, batteria) torna come
 *     prima: le casse del fronte non si toccano per un'esercitazione.
 *
 * Finito l'incontro — o passato il convoglio senza che lo si attacchi —
 * l'istruttore scrive il giudizio, il battello torna in base e l'equipaggio
 * guadagna competenza. Il primo addestramento superato vale anche prestigio.
 */
final class Addestramento
{
    /** Dove si mette il battello rispetto al convoglio: avanti sulla rotta, e di lato. */
    private const AVANTI_NM = 7.0;
    private const DI_LATO_NM = 3.0;
    /** A che punto della sua rotta e' il convoglio quando il battello arriva. */
    private const FRAZIONE_ROTTA = 0.18;
    /** Velocita' del convoglio d'esercitazione, in nodi: quella di un SC. */
    private const VELOCITA_KN = 7.0;

    /** Nomi dei vecchi piroscafi che facevano da bersaglio. */
    private const BERSAGLI = ['Weichsel', 'Memel', 'Pregel', 'Samland', 'Oliva', 'Zoppot', 'Putzig', 'Rixhoeft',
        'Leba', 'Stolp', 'Ruegenwalde', 'Kolberg', 'Swinemuende', 'Wollin', 'Usedom', 'Frisches Haff'];

    /** L'addestramento in corso per un battello, o null. */
    public static function corrente(int $boatId): ?array
    {
        return Database::first(
            "SELECT a.* FROM addestramenti a JOIN patrols p ON p.id = a.patrol_id
              WHERE a.boat_id = ? AND a.finito_gts IS NULL AND p.state = 'in_corso' ORDER BY a.id DESC LIMIT 1",
            [$boatId]
        );
    }

    /** La missione e' un addestramento. */
    public static function eAddestramento(?int $patrolId): bool
    {
        if ($patrolId === null) {
            return false;
        }
        $p = Database::first('SELECT tipo FROM patrols WHERE id = ?', [$patrolId]);
        return $p !== null && (string) $p['tipo'] === 'addestramento';
    }

    /** @return list<array<string,mixed>> gli addestramenti conclusi di un comandante, dal piu' recente */
    public static function storia(int $commanderId, int $quanti = 5): array
    {
        return Database::all(
            'SELECT * FROM addestramenti WHERE commander_id = ? AND finito_gts IS NOT NULL ORDER BY id DESC LIMIT ' . max(1, $quanti),
            [$commanderId]
        );
    }

    /**
     * Si parte per il Baltico.
     *
     * @return array{ok:bool, error?:string, patrol_id?:int}
     */
    public static function parti(array $boat): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'L\'addestramento si chiede dalla base, col battello in banchina.'];
        }
        $cmd = Comandante::corrente((int) $boat['user_id']);
        if ($cmd === null) {
            return ['ok' => false, 'error' => 'Senza comandante non si va da nessuna parte.'];
        }
        if ($boat['commander_id'] === null) {
            Database::run('UPDATE boats SET commander_id = ? WHERE id = ?', [(int) $cmd['id'], (int) $boat['id']]);
        }

        // Tutto o niente, e una volta sola. Il battello si «prende» per primo
        // con un aggiornamento condizionale: un doppio clic faceva partire due
        // esercitazioni, e un errore a meta' lasciava una missione
        // d'addestramento aperta per sempre (revisione del 13/10/2026).
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $preso = Database::run("UPDATE boats SET state = 'mare' WHERE id = ? AND state = 'base'", [(int) $boat['id']])->rowCount();
            if ($preso !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Il battello e\' gia\' partito.'];
            }
            $esito = self::prepara($boat, $cmd);
            $pdo->commit();
            return $esito;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{ok:bool, patrol_id:int} */
    private static function prepara(array $boat, array $cmd): array
    {
        $type = World::type((string) $boat['type_key']);
        $now = World::now();
        $rng = Rng::for(World::seed(), 'addestramento', (int) $boat['id'], $now);

        // Quello che si consuma torna com'era: lo si mette da parte adesso.
        $riserva = [
            'barca' => Database::first(
                'SELECT fuel_t, provisions_days, senza_viveri_gts, battery_pct, air_pct, co2_pct FROM boats WHERE id = ?',
                [(int) $boat['id']]
            ),
            'siluri' => Database::all(
                'SELECT tkey, posizione, tubo, stato, ricarica_fine_gts FROM boat_torpedoes WHERE boat_id = ? ORDER BY id',
                [(int) $boat['id']]
            ),
            'stiva' => Database::all('SELECT item_key, qty FROM boat_stores WHERE boat_id = ?', [(int) $boat['id']]),
        ];

        Database::run(
            'INSERT INTO patrols (boat_id, user_id, commander_id, number, tipo, base_key, departed_gts, state, note)
             VALUES (?, ?, ?, 0, "addestramento", ?, ?, "in_corso", ?)',
            [(int) $boat['id'], (int) $boat['user_id'], (int) $cmd['id'], (string) $boat['home_port_key'], $now,
             'Addestramento tattico, Agru-Front']
        );
        $patrolId = Database::lastInsertId();

        // --- il convoglio d'esercitazione ----------------------------------------
        $durata = Traffic::durata('agru', self::VELOCITA_KN);
        $partito = $now - (int) round($durata * self::FRAZIONE_ROTTA);
        $numero = (int) (Database::first("SELECT COALESCE(MAX(numero), 0) + 1 n FROM convoys WHERE serie = 'AGRU'")['n'] ?? 1);
        $navi = $rng->int(5, 7);
        $scorte = 2;
        Database::run(
            'INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts, zigzag, deviazione,
                                  navi_iniziali, esercitazione)
             VALUES ("AGRU", ?, "agru", ?, 3, ?, ?, 1, 0, ?, 1)',
            [$numero, self::VELOCITA_KN, $partito, $partito + $durata, $navi + $scorte]
        );
        $convoyId = Database::lastInsertId();

        // I nomi delle navi sono unici in tutto il mondo: se quello del
        // piroscafo e' gia' in mare (un'altra esercitazione), si numera.
        $nome = static function (string $base) use ($numero): string {
            foreach ([$base, $base . ' II', $base . ' III', $base . ' ' . $numero] as $n) {
                if (Database::first('SELECT 1 FROM ships WHERE name = ?', [$n]) === null) {
                    return $n;
                }
            }
            return $base . ' ' . bin2hex(random_bytes(2));
        };
        $bersagli = self::BERSAGLI;
        for ($i = count($bersagli) - 1; $i > 0; $i--) {
            $j = $rng->int(0, $i);
            [$bersagli[$i], $bersagli[$j]] = [$bersagli[$j], $bersagli[$i]];
        }
        for ($i = 0; $i < $navi; $i++) {
            $classe = $rng->chance(0.6) ? 'tramp_piccolo' : 'cargo_medio';
            Database::run(
                'INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn,
                                    departed_gts, eta_gts, grt, carico)
                 VALUES (?, "tedesca", ?, ?, ?, ?, ?, "agru", ?, ?, ?, ?, "zavorra")',
                [$nome($bersagli[$i]),
                 $classe, $convoyId, $i % 3 + 1, intdiv($i, 3) + 1, $i === 0 ? 'commodoro' : 'mercantile',
                 self::VELOCITA_KN, $partito, $partito + $durata,
                 (int) round((float) Traffic::classe($classe)['grt'] * $rng->range(0.8, 1.1))]
            );
        }
        // I cacciasommergibili della scuola erano pescherecci trasformati: gli UJ.
        for ($i = 0; $i < $scorte; $i++) {
            Database::run(
                'INSERT INTO ships (name, flag, class_key, convoy_id, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
                 VALUES (?, "tedesca", "trawler_armato", ?, "scorta", "agru", ?, ?, ?, ?)',
                [$nome('UJ ' . (1101 + $i + 2 * (($numero - 1) % 40))), $convoyId, self::VELOCITA_KN, $partito,
                 $partito + $durata, (int) Traffic::classe('trawler_armato')['grt']]
            );
        }

        // --- il battello, davanti al convoglio --------------------------------------
        $pc = Traffic::posizione('agru', self::VELOCITA_KN, $partito, $now, 0.0);
        [$la, $lo] = Geo::destination((float) $pc['lat'], (float) $pc['lon'], (float) $pc['heading'], self::AVANTI_NM);
        [$la, $lo] = Geo::destination($la, $lo, Geo::normBearing((float) $pc['heading'] - 90.0), self::DI_LATO_NM);

        Torpedo::imbarca((int) $boat['id'], $type, Torpedo::caricoStandard($type, 0));
        Database::run(
            'UPDATE boats SET state = "mare", lat = ?, lon = ?, est_lat = ?, est_lon = ?, est_error_nm = 0,
                    heading = ?, ordered_heading = ?, speed_kn = 0, ordered_speed_kn = 0, depth_m = 0, ordered_depth_m = 0,
                    mode = "superficie", silent = 0, periscopio_alzato = 0, periscopio_gts = NULL,
                    fuel_t = ?, battery_pct = 100, air_pct = 100, co2_pct = 0, encounter_id = NULL,
                    last_fix_gts = ?, submerged_since = NULL, last_sim_gts = ?, wolfpack_id = NULL, version = version + 1
             WHERE id = ?',
            [$la, $lo, $la, $lo, Geo::normBearing((float) $pc['heading'] + 180.0), Geo::normBearing((float) $pc['heading'] + 180.0),
             (float) $type['fuel_t'], $now, $now, (int) $boat['id']]
        );
        Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [(int) $boat['id']]);
        Database::run('DELETE FROM contacts WHERE boat_id = ?', [(int) $boat['id']]);

        // L'istruttore indica il convoglio: il contatto c'e' dal primo minuto.
        $ril = Geo::bearing($la, $lo, (float) $pc['lat'], (float) $pc['lon']);
        $dist = Geo::distanceNm($la, $lo, (float) $pc['lat'], (float) $pc['lon']);
        Contacts::upsert((int) $boat['id'], $patrolId, [
            'kind' => 'convoglio', 'id' => $convoyId, 'sensore' => 'radiogoniometro',
            'bearing' => $ril, 'distanza' => $dist, 'est_lat' => $la, 'est_lon' => $lo,
            'classe_est' => 'convoglio d\'esercitazione', 'navi' => $navi + $scorte, 'snr' => 20.0,
        ], $now, $rng);

        Database::run(
            'INSERT INTO addestramenti (commander_id, boat_id, patrol_id, convoy_id, iniziato_gts, dettaglio)
             VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $cmd['id'], (int) $boat['id'], $patrolId, $convoyId, $now,
             json_encode(['riserva' => $riserva], JSON_UNESCAPED_UNICODE)]
        );

        BoatSim::save([
            'gts' => $now, 'kind' => 'addestramento', 'severity' => 'nota', 'lat' => $la, 'lon' => $lo,
            'quadrat' => Grid::toQuadrat($la, $lo),
            'text' => sprintf(
                'Trasferiti nel Baltico per l\'addestramento tattico con l\'Agru-Front. Il convoglio d\'esercitazione '
                . 'AGRU %d — %d piroscafi e due cacciasommergibili — procede per %03.0f a %.0f nodi: per noi %03.0f gradi, '
                . '%.0f miglia. L\'istruttore e\' in centrale. Teste d\'esercitazione nei siluri; le cariche degli UJ sono '
                . 'di segnalazione, ma contano come se fossero vere.',
                $numero, $navi, (float) $pc['heading'], self::VELOCITA_KN, $ril, $dist
            ),
        ], $patrolId, (int) $boat['id']);
        BoatSim::save([
            'gts' => $now + 60, 'kind' => 'iwo', 'severity' => 'nota', 'lat' => $la, 'lon' => $lo,
            'quadrat' => Grid::toQuadrat($la, $lo),
            'text' => 'Il I.WO: «Il convoglio ci viene incontro. Dalla stazione d\'attacco si da\' battaglia: '
                . 'immersione a quota periscopica, soluzione, lancio, e poi giu\' a lasciarli passare. '
                . 'L\'istruttore guarda tre cose — se ci vedono prima del lancio, quanti siluri vanno a segno, '
                . 'e quanto ci costerebbero le loro cariche.»',
        ], $patrolId, (int) $boat['id']);

        return ['ok' => true, 'patrol_id' => $patrolId];
    }

    /**
     * Chiamata alla chiusura di ogni incontro: se era un'esercitazione, e'
     * finita anche lei.
     */
    public static function dopoIncontro(int $patrolId, int $gts): void
    {
        if (self::eAddestramento($patrolId)) {
            self::concludi($patrolId, $gts, null);
        }
    }

    /**
     * Il battito: un convoglio d'esercitazione passato senza che nessuno lo
     * attacchi chiude l'esercitazione.
     *
     * @return array{conclusi:int}
     */
    public static function mantieni(int $gts): array
    {
        $n = 0;
        $aperti = Database::all(
            "SELECT a.patrol_id, b.encounter_id, c.eta_gts
               FROM addestramenti a
               JOIN patrols p ON p.id = a.patrol_id AND p.state = 'in_corso'
               JOIN boats b ON b.id = a.boat_id
               LEFT JOIN convoys c ON c.id = a.convoy_id
              WHERE a.finito_gts IS NULL"
        );
        foreach ($aperti as $a) {
            if ($a['encounter_id'] !== null) {
                continue;               // si sta combattendo: finira' con l'incontro
            }
            if ($a['eta_gts'] === null || (int) $a['eta_gts'] < $gts) {
                self::concludi((int) $a['patrol_id'], $gts, 'Il convoglio d\'esercitazione e\' passato senza essere attaccato.');
                $n++;
            }
        }
        return ['conclusi' => $n];
    }

    /**
     * Il comandante chiede di tornare prima.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function interrompi(array $boat): array
    {
        $a = self::corrente((int) $boat['id']);
        if ($a === null) {
            return ['ok' => false, 'error' => 'Nessun addestramento in corso.'];
        }
        $gts = World::now();
        // Prima il giudizio, col motivo, poi l'incontro: chiudendosi
        // l'incontro concluderebbe l'esercitazione da se', senza sapere che
        // l'ha interrotta il comandante — e chi si ferma prima di lanciare
        // si ritrovava «insufficiente» invece di «non concluso».
        self::concludi((int) $a['patrol_id'], $gts, 'Esercitazione interrotta dal comandante.');
        if ($boat['encounter_id'] !== null) {
            Encounter::chiudi((int) $boat['encounter_id'], 'Esercitazione interrotta dal comandante.', $gts);
        }
        return ['ok' => true];
    }

    /**
     * Il giudizio dell'istruttore, il ritorno in base, le casse rimesse a posto.
     * Una volta sola per addestramento: le chiamate successive non fanno niente.
     */
    public static function concludi(int $patrolId, int $gts, ?string $motivo): ?array
    {
        $a = Database::first('SELECT * FROM addestramenti WHERE patrol_id = ?', [$patrolId]);
        if ($a === null || $a['finito_gts'] !== null) {
            return null;
        }
        // Chi arriva per secondo trova la riga gia' presa e se ne va.
        $presa = Database::run(
            'UPDATE addestramenti SET finito_gts = ? WHERE id = ? AND finito_gts IS NULL',
            [$gts, (int) $a['id']]
        )->rowCount();
        if ($presa === 0) {
            return null;
        }

        $boatId = (int) $a['boat_id'];
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
        // Si rientra e si rimettono le casse solo se l'esercitazione e' ancora
        // la missione in corso e il battello e' in mare: un battello perduto
        // sotto la quota di collasso resta perduto, e la sua missione perduta.
        $patrolRiga = Database::first('SELECT state FROM patrols WHERE id = ?', [$patrolId]);
        $vivo = $boat !== null && (string) $boat['state'] === 'mare'
            && $patrolRiga !== null && (string) $patrolRiga['state'] === 'in_corso';
        $esito = self::valuta($patrolId, $boat !== null ? (float) $boat['hull_stress'] : 0.0, $motivo);

        // --- il ritorno, e le casse come prima -----------------------------------------
        $riserva = (array) (json_decode((string) $a['dettaglio'], true)['riserva'] ?? []);
        if ($vivo) {
            $base = World::port((string) $boat['home_port_key']);
            $r = (array) ($riserva['barca'] ?? []);
            Database::run(
                'UPDATE boats SET state = "base", lat = ?, lon = ?, est_lat = ?, est_lon = ?, est_error_nm = 0,
                        speed_kn = 0, ordered_speed_kn = 0, depth_m = 0, ordered_depth_m = 0, mode = "superficie",
                        silent = 0, periscopio_alzato = 0, periscopio_gts = NULL, encounter_id = NULL, battle_stations = 0,
                        fuel_t = ?, provisions_days = ?, senza_viveri_gts = ?, battery_pct = ?, air_pct = ?, co2_pct = ?,
                        last_sim_gts = ?, version = version + 1
                 WHERE id = ?',
                [
                    (float) ($base['lat'] ?? $boat['lat']), (float) ($base['lon'] ?? $boat['lon']),
                    (float) ($base['lat'] ?? $boat['lat']), (float) ($base['lon'] ?? $boat['lon']),
                    (float) ($r['fuel_t'] ?? $boat['fuel_t']), (float) ($r['provisions_days'] ?? $boat['provisions_days']),
                    $r['senza_viveri_gts'] ?? null, (float) ($r['battery_pct'] ?? 100), (float) ($r['air_pct'] ?? 100),
                    (float) ($r['co2_pct'] ?? 0), $gts, $boatId,
                ]
            );
            if (isset($riserva['siluri']) && is_array($riserva['siluri'])) {
                Database::run('DELETE FROM boat_torpedoes WHERE boat_id = ?', [$boatId]);
                foreach ($riserva['siluri'] as $s) {
                    Database::run(
                        'INSERT INTO boat_torpedoes (boat_id, tkey, posizione, tubo, stato, ricarica_fine_gts) VALUES (?, ?, ?, ?, ?, ?)',
                        [$boatId, $s['tkey'], $s['posizione'], $s['tubo'], $s['stato'], $s['ricarica_fine_gts']]
                    );
                }
            }
            foreach ((array) ($riserva['stiva'] ?? []) as $s) {
                Database::run('UPDATE boat_stores SET qty = ? WHERE boat_id = ? AND item_key = ?',
                    [(float) $s['qty'], $boatId, (string) $s['item_key']]);
            }
            Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [$boatId]);
            Database::run('DELETE FROM contacts WHERE boat_id = ?', [$boatId]);
        }

        // Il convoglio d'esercitazione rientra a Gotenhafen: non serve piu'.
        if ($a['convoy_id'] !== null) {
            Database::run("UPDATE convoys SET state = 'arrivato', eta_gts = LEAST(eta_gts, ?) WHERE id = ?", [$gts, (int) $a['convoy_id']]);
            Database::run("UPDATE ships SET state = 'arrivata' WHERE convoy_id = ? AND state = 'in_mare'", [(int) $a['convoy_id']]);
        }

        // --- quello che si guadagna ----------------------------------------------------
        $superato = in_array($esito['giudizio'], ['ottimo', 'buono', 'sufficiente'], true);
        $premio = 0;
        if ($superato) {
            $comp = max(0, GameConfig::int('addestramento.competenza', 3));
            Database::run(
                "UPDATE crew_members SET competence = LEAST(100, competence + ?) WHERE boat_id = ? AND health <> 'morto'",
                [$comp, $boatId]
            );
            $giaSuperato = Database::first(
                "SELECT COUNT(*) n FROM addestramenti WHERE commander_id = ? AND id <> ?
                    AND giudizio IN ('ottimo','buono','sufficiente')",
                [(int) $a['commander_id'], (int) $a['id']]
            );
            if ((int) ($giaSuperato['n'] ?? 0) === 0) {
                $premio = max(0, GameConfig::int('addestramento.premio', 60));
                Database::run(
                    'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ? WHERE id = ?',
                    [$premio, $premio, (int) $a['commander_id']]
                );
            }
        }

        $rapporto = $esito['rapporto'] . ($superato
            ? sprintf(' Equipaggio piu\' affiatato: +%d di competenza a ogni uomo.', max(0, GameConfig::int('addestramento.competenza', 3)))
              . ($premio > 0 ? sprintf(' Primo addestramento superato: +%d prestigio.', $premio) : '')
            : ' Si ripete quando il comandante vuole.');
        $dettaglio = $esito['dettaglio'] + ['premio' => $premio];

        Database::run(
            'UPDATE addestramenti SET punteggio = ?, giudizio = ?, dettaglio = ?, rapporto = ? WHERE id = ?',
            [$esito['punteggio'], $esito['giudizio'], json_encode($dettaglio, JSON_UNESCAPED_UNICODE), $rapporto, (int) $a['id']]
        );
        Database::run(
            "UPDATE patrols SET state = 'conclusa', returned_gts = ?, rapporto = ? WHERE id = ? AND state = 'in_corso'",
            [$gts, $rapporto, $patrolId]
        );
        if ($vivo) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'addestramento', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => 'Giudizio dell\'istruttore: ' . strtoupper($esito['giudizio']) . '. ' . $rapporto,
            ], $patrolId, $boatId);
        }

        return $esito + ['premio' => $premio];
    }

    /**
     * Il giudizio, dai numeri dell'incontro.
     *
     * Cento punti in tutto: trenta all'avvicinamento non visto, cinquanta al
     * tiro (dieci per aver colpito, quaranta in proporzione ai siluri a segno),
     * venti al disimpegno, che si perdono man mano che le cariche — in guerra
     * — avrebbero sollecitato lo scafo. Chi in guerra sarebbe andato a fondo
     * non passa, qualunque sia il resto.
     *
     * @return array{punteggio:int, giudizio:string, rapporto:string, dettaglio:array<string,mixed>}
     */
    public static function valuta(int $patrolId, float $stressIniziale, ?string $motivo): array
    {
        $incontri = Database::all('SELECT * FROM encounters WHERE patrol_id = ? ORDER BY id', [$patrolId]);
        $lanci = Database::all(
            'SELECT r.* FROM torpedo_runs r JOIN encounters e ON e.id = r.encounter_id WHERE e.patrol_id = ? ORDER BY r.lanciato_gts',
            [$patrolId]
        );
        $nLanci = count($lanci);
        $colpi = count(array_filter($lanci, static fn (array $r): bool => (string) $r['esito'] === 'colpito'));
        $primoLancio = $nLanci > 0 ? (int) $lanci[0]['lanciato_gts'] : null;
        $visto = false;
        $danno = 0.0;
        foreach ($incontri as $e) {
            $danno += (float) $e['danno_simulato'];
            if ($e['allarme_gts'] !== null && ($primoLancio === null || (int) $e['allarme_gts'] < $primoLancio)) {
                $visto = true;
            }
        }
        $affondato = $stressIniziale + $danno >= 100.0;

        $dettaglio = ['incontri' => count($incontri), 'lanci' => $nLanci, 'colpi' => $colpi,
            'visto_prima_del_lancio' => $visto, 'danno_simulato' => round($danno, 1), 'affondato' => $affondato];

        if ($incontri === [] || $nLanci === 0) {
            // Senza lanci si giudica solo chi ha lasciato finire l'incontro da
            // se': chi ha interrotto, o ha lasciato passare il convoglio, non
            // ha fatto un attacco cattivo, non l'ha fatto e basta.
            $giudizio = $incontri === [] || $motivo !== null ? 'non concluso' : 'insufficiente';
            $rapporto = $incontri === [] || $motivo !== null
                ? ($motivo ?? 'Nessun attacco.') . ' Non c\'e\' niente da giudicare.'
                : 'Ci si e\' avvicinati ma non si e\' lanciato: un attacco che non arriva al lancio non e\' un attacco.';
            return ['punteggio' => 0, 'giudizio' => $giudizio, 'rapporto' => $rapporto, 'dettaglio' => $dettaglio];
        }

        $pAvvicinamento = $visto ? 0 : 30;
        $pTiro = ($colpi > 0 ? 10 : 0) + (int) round(40 * $colpi / $nLanci);
        $pDisimpegno = $affondato ? 0 : (int) round(20 * max(0.0, 1.0 - $danno / 60.0));
        $punti = $pAvvicinamento + $pTiro + $pDisimpegno;
        $giudizio = match (true) {
            $affondato    => 'insufficiente',
            $punti >= 80  => 'ottimo',
            $punti >= 60  => 'buono',
            $punti >= 40  => 'sufficiente',
            default       => 'insufficiente',
        };

        $frasi = [];
        $frasi[] = $visto
            ? 'Gli UJ vi hanno scoperti prima del lancio: in guerra il convoglio avrebbe accostato e la scorta vi sarebbe stata addosso.'
            : 'Avvicinamento riuscito senza farsi scoprire.';
        $frasi[] = $colpi === 0
            ? sprintf('%s, nessuno a segno: la soluzione va rifatta con calma, e il lancio va fatto piu\' vicino.',
                plurale($nLanci, 'Un siluro', '%d siluri'))
            : sprintf('%s su %d a segno.', plurale($colpi, 'Un colpo', '%d colpi'), $nLanci);
        $frasi[] = match (true) {
            $affondato     => 'Con le cariche vere il battello sarebbe andato a fondo: il disimpegno va rifatto da capo.',
            $danno <= 0.0  => 'Nessuna carica vicina: disimpegno pulito.',
            $danno < 20.0  => 'Qualche carica vicina: in guerra, danni leggeri.',
            default        => sprintf('Le cariche vi hanno trovati: in guerra lo scafo avrebbe incassato un %.0f%% di sollecitazione.', $danno),
        };

        return [
            'punteggio' => $punti,
            'giudizio' => $giudizio,
            'rapporto' => implode(' ', $frasi),
            'dettaglio' => $dettaglio + ['punti' => ['avvicinamento' => $pAvvicinamento, 'tiro' => $pTiro, 'disimpegno' => $pDisimpegno]],
        ];
    }
}
