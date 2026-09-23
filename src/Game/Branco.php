<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Contacts;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\World;

/**
 * Rudeltaktik: la muta di lupi.
 *
 * Il BdU apre una finestra operativa su un quadrato e ci raduna i battelli
 * disponibili. Chi avvista il convoglio manda il segnale di contatto, guadagna
 * prestigio per il pedinamento e guida gli altri sul posto; l'attacco
 * concentrato arriva la notte dopo. Il convoglio e' lo stesso per tutti e si
 * logora davvero: le navi affondate da uno spariscono per tutti.
 *
 * Il branco resta FACOLTATIVO. Chi preferisce cacciare da solo ha un
 * moltiplicatore di prestigio personale piu' alto e nessun obbligo di
 * segnalazione — ma nessuno verra' ad aiutarlo.
 */
final class Branco
{
    /** Nomi storici dei gruppi operativi. */
    private const NOMI = [
        'Raubgraf', 'Drossel', 'Wolf', 'Seewolf', 'Prien', 'Rösselsprung', 'Eisbär', 'Habicht',
        'Markgraf', 'Mordbrenner', 'Panther', 'Pfeil', 'Reissewolf', 'Schlagetot', 'Steinbrinck',
        'Stürmer', 'Trutz', 'Veilchen', 'Westmark', 'Amsel', 'Meise', 'Specht', 'Star',
    ];

    /** @return array<string,mixed>|null il branco a cui appartiene un battello */
    public static function corrente(int $boatId): ?array
    {
        return Database::first(
            "SELECT w.* FROM wolfpacks w
             JOIN wolfpack_members m ON m.wolfpack_id = w.id
             WHERE m.boat_id = ? AND m.uscito_gts IS NULL AND w.stato <> 'sciolto'
             ORDER BY w.id DESC LIMIT 1",
            [$boatId]
        );
    }

    /** @return list<array<string,mixed>> branchi aperti a cui ci si puo' unire */
    public static function aperti(int $gts): array
    {
        return Database::all(
            "SELECT w.*, (SELECT COUNT(*) FROM wolfpack_members m WHERE m.wolfpack_id = w.id AND m.uscito_gts IS NULL) AS membri
             FROM wolfpacks w WHERE w.stato <> 'sciolto' AND w.chiude_gts > ? ORDER BY w.aperto_gts DESC",
            [time()]
        );
    }

    /**
     * Il BdU apre un nuovo gruppo operativo su uno sbarramento.
     * Lo fa dove passa il traffico, non a caso.
     */
    public static function apri(int $gts, ?float $lat = null, ?float $lon = null, int $indice = 0): ?array
    {
        // L'indice entra nel seme: due gruppi aperti nello stesso istante
        // devono avere nome e sbarramento diversi, non essere gemelli.
        $rng = Rng::for(World::seed(), 'branco', $gts, $indice);

        if ($lat === null || $lon === null) {
            // Sbarramenti tipici: la rotta dei convogli nel mezzo dell'oceano,
            // dove non arriva la copertura aerea.
            $punti = [[54.0, -32.0], [52.0, -28.0], [56.0, -35.0], [50.0, -24.0], [58.0, -30.0], [48.0, -22.0]];
            [$lat, $lon] = $punti[$rng->int(0, count($punti) - 1)];
            $lat += $rng->range(-1.5, 1.5);
            $lon += $rng->range(-2.5, 2.5);
        }

        $nome = self::NOMI[$rng->int(0, count(self::NOMI) - 1)];
        $esiste = Database::first("SELECT id FROM wolfpacks WHERE nome = ? AND stato <> 'sciolto'", [$nome]);
        if ($esiste !== null) {
            $nome .= ' II';
        }

        // ATTENZIONE: chiude_gts, nonostante il suffisso, contiene tempo REALE
        // (time()), mentre aperto_gts nella stessa riga e' tempo di gioco. E'
        // voluto — la chiave branco.durata_ore e' dichiarata «in ore reali» dalla
        // migrazione 0010, perche' in un gioco asincrono i giocatori devono avere
        // tempo vero per collegarsi e unirsi — e tutti i confronti su questa
        // colonna usano time(). Chi la confrontasse con World::now() vedrebbe
        // tutti i gruppi scaduti da cinquant'anni. Annotato dall'audit del
        // 23/09/2026, che ci e' cascato per primo.
        $ore = max(6, GameConfig::int('branco.durata_ore', 72));
        Database::run(
            'INSERT INTO wolfpacks (nome, quadrat, lat, lon, aperto_gts, chiude_gts, stato)
             VALUES (?, ?, ?, ?, ?, ?, "raccolta")',
            [$nome, Grid::toQuadrat($lat, $lon, 2) ?? '—', $lat, $lon, $gts, time() + $ore * 3600]
        );
        $id = Database::lastInsertId();

        // Il BdU lo comunica a tutti.
        Database::run(
            'INSERT INTO radio_messages (destinatario, tipo, testo, quadrat, lat, lon, gts, durata_s)
             VALUES ("tutti", "comunicato", ?, ?, ?, ?, ?, 0)',
            [
                sprintf('Gruppo "%s" in formazione sullo sbarramento in quadrato %s. '
                    . 'I battelli in zona si annuncino e prendano posizione.', $nome, Grid::toQuadrat($lat, $lon, 2) ?? '—'),
                Grid::toQuadrat($lat, $lon, 2), $lat, $lon, $gts,
            ]
        );

        return Database::first('SELECT * FROM wolfpacks WHERE id = ?', [$id]);
    }

    /** @return array{ok:bool, error?:string, nome?:string} */
    public static function entra(array $boat, int $wolfpackId, int $gts): array
    {
        if ((string) $boat['state'] !== 'mare') {
            return ['ok' => false, 'error' => 'Ci si unisce a un gruppo dal mare.'];
        }
        if (self::corrente((int) $boat['id']) !== null) {
            return ['ok' => false, 'error' => 'Sei gia' . "'" . ' inquadrato in un gruppo.'];
        }

        $w = Database::first("SELECT * FROM wolfpacks WHERE id = ? AND stato <> 'sciolto'", [$wolfpackId]);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Gruppo non piu' . "'" . ' attivo.'];
        }

        Database::run(
            'INSERT INTO wolfpack_members (wolfpack_id, boat_id, commander_id, entrato_gts) VALUES (?, ?, ?, ?)',
            [$wolfpackId, (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null, $gts]
        );
        Database::run('UPDATE boats SET wolfpack_id = ? WHERE id = ?', [$wolfpackId, (int) $boat['id']]);
        Database::run("UPDATE wolfpacks SET stato = 'operativo' WHERE id = ? AND stato = 'raccolta'", [$wolfpackId]);

        // Ordine di posizionamento.
        Database::run(
            'INSERT INTO bdu_orders (boat_id, commander_id, wolfpack_id, tipo, quadrat, lat, lon, testo,
                                     emesso_gts, scade_gts, stato, prestigio, punti)
             VALUES (?, ?, ?, "area", ?, ?, ?, ?, ?, ?, "accettato", 220, 30)',
            [
                (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null, $wolfpackId,
                (string) $w['quadrat'], (float) $w['lat'], (float) $w['lon'],
                sprintf('Gruppo "%s": prendere posizione sullo sbarramento in quadrato %s e segnalare ogni contatto. '
                    . 'Chi tiene il contatto guida gli altri.', (string) $w['nome'], (string) $w['quadrat']),
                $gts, $gts + 4 * 86400,
            ]
        );

        return ['ok' => true, 'nome' => (string) $w['nome']];
    }

    public static function esci(array $boat, int $gts): array
    {
        $w = self::corrente((int) $boat['id']);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Non sei inquadrato in nessun gruppo.'];
        }
        Database::run(
            'UPDATE wolfpack_members SET uscito_gts = ? WHERE wolfpack_id = ? AND boat_id = ? AND uscito_gts IS NULL',
            [$gts, (int) $w['id'], (int) $boat['id']]
        );
        Database::run('UPDATE boats SET wolfpack_id = NULL WHERE id = ?', [(int) $boat['id']]);
        return ['ok' => true, 'nome' => (string) $w['nome']];
    }

    /**
     * Segnalazione di contatto: gli altri del branco ricevono il punto e
     * possono convergere. Chi segnala prende il premio del Fuehlungshalter —
     * altrimenti nessuno lo farebbe mai, perche' pedinare non affonda nulla.
     */
    public static function segnalaContatto(array $branco, array $boat, float $lat, float $lon, int $gts): int
    {
        $contatto = Database::first(
            "SELECT * FROM contacts WHERE boat_id = ? AND perso = 0 AND convoy_id IS NOT NULL
             ORDER BY last_gts DESC LIMIT 1",
            [(int) $boat['id']]
        );

        $membri = Database::all(
            'SELECT m.*, b.uboat_number FROM wolfpack_members m JOIN boats b ON b.id = m.boat_id
             WHERE m.wolfpack_id = ? AND m.uscito_gts IS NULL AND m.boat_id <> ?',
            [(int) $branco['id'], (int) $boat['id']]
        );

        $avvisati = 0;
        foreach ($membri as $m) {
            $altro = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $m['boat_id']]);
            if ($altro === null || (string) $altro['state'] !== 'mare') {
                continue;
            }

            // Il compagno riceve il punto segnalato: un contatto di seconda
            // mano, meno preciso del proprio ma abbastanza per fare rotta.
            $rng = Rng::for(World::seed(), 'segnalazione', (int) $boat['id'], (int) $m['boat_id'], $gts);
            Contacts::upsert((int) $altro['id'], null, [
                'kind'       => $contatto !== null && $contatto['convoy_id'] !== null ? 'convoglio' : 'nave',
                'id'         => (int) ($contatto['convoy_id'] ?? $contatto['ship_id'] ?? 0),
                'sensore'    => 'radiogoniometro',
                'bearing'    => Geo::bearing((float) $altro['lat'], (float) $altro['lon'], $lat, $lon),
                'distanza'   => Geo::distanceNm((float) $altro['lat'], (float) $altro['lon'], $lat, $lon),
                'est_lat'    => (float) $altro['est_lat'],
                'est_lon'    => (float) $altro['est_lon'],
                'classe_est' => 'segnalazione di ' . (string) $boat['uboat_number'],
                'snr'        => 20.0,
            ], $gts, $rng);
            $avvisati++;
        }

        Database::run(
            'UPDATE wolfpack_members SET contatti = contatti + 1 WHERE wolfpack_id = ? AND boat_id = ?',
            [(int) $branco['id'], (int) $boat['id']]
        );
        if ($boat['commander_id'] !== null) {
            $premio = GameConfig::int('branco.premio_contatto', 180);
            Database::run(
                'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ?,
                        segnalazioni = segnalazioni + 1 WHERE id = ?',
                [$premio, $premio, (int) $boat['commander_id']]
            );
        }

        return $avvisati;
    }

    /** Chiude i gruppi scaduti e ne apre di nuovi: lo fa il tick. */
    public static function mantieni(int $gts): array
    {
        $chiusi = Database::run(
            "UPDATE wolfpacks SET stato = 'sciolto' WHERE stato <> 'sciolto' AND chiude_gts < ?",
            [time()]
        )->rowCount();

        if ($chiusi > 0) {
            Database::run(
                "UPDATE boats SET wolfpack_id = NULL WHERE wolfpack_id IN
                 (SELECT id FROM wolfpacks WHERE stato = 'sciolto')"
            );
            Database::run(
                "UPDATE wolfpack_members SET uscito_gts = ? WHERE uscito_gts IS NULL AND wolfpack_id IN
                 (SELECT id FROM wolfpacks WHERE stato = 'sciolto')",
                [$gts]
            );
        }

        $attivi = (int) (Database::first("SELECT COUNT(*) n FROM wolfpacks WHERE stato <> 'sciolto'")['n'] ?? 0);
        $aperti = 0;
        while ($attivi + $aperti < 2) {
            if (self::apri($gts, null, null, $attivi + $aperti) === null) {
                break;
            }
            $aperti++;
        }

        return ['chiusi' => $chiusi, 'aperti' => $aperti];
    }

    /** @return list<array<string,mixed>> */
    public static function membri(int $wolfpackId): array
    {
        return Database::all(
            'SELECT m.*, b.uboat_number, b.state, c.nome AS comandante
             FROM wolfpack_members m
             JOIN boats b ON b.id = m.boat_id
             LEFT JOIN commanders c ON c.id = m.commander_id
             WHERE m.wolfpack_id = ? AND m.uscito_gts IS NULL ORDER BY m.grt DESC',
            [$wolfpackId]
        );
    }
}
