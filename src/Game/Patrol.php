<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\BoatSim;
use App\Sim\Damage;
use App\Sim\Torpedo;
use App\Game\Carriera;
use App\Game\Comandante;
use App\Game\Trofei;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Narrator;
use App\Sim\World;

/** Il ciclo della patrol: uscita, ordini, rientro. */
final class Patrol
{
    /** Quanto vicino alla base bisogna essere per entrare in porto. */
    public const RAGGIO_PORTO_NM = 6.0;

    public static function corrente(int $boatId): ?array
    {
        return Database::first(
            "SELECT * FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [$boatId]
        );
    }

    /**
     * Uscita in mare.
     *
     * @param list<array{lat:float,lon:float,label?:string}> $waypoints
     * @return array{ok:bool, error?:string, patrol_id?:int}
     */
    public static function depart(array $boat, array $waypoints = []): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'Il battello e\' gia\' in mare.'];
        }

        $comandante = Comandante::corrente((int) $boat['user_id']);
        if ($comandante === null) {
            return ['ok' => false, 'error' => 'Nessun comandante assegnato: il battello non esce senza.'];
        }
        if ($boat['commander_id'] === null) {
            Database::run('UPDATE boats SET commander_id = ? WHERE id = ?', [(int) $comandante['id'], (int) $boat['id']]);
            $boat['commander_id'] = (int) $comandante['id'];
        }

        $type = World::type((string) $boat['type_key']);
        $base = World::port((string) $boat['home_port_key']);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Base di partenza sconosciuta.'];
        }

        $now = World::now();
        $numero = (int) (Database::first(
            'SELECT COALESCE(MAX(number), 0) + 1 AS n FROM patrols WHERE boat_id = ?',
            [(int) $boat['id']]
        )['n'] ?? 1);

        Database::run(
            'INSERT INTO patrols (boat_id, user_id, commander_id, number, base_key, departed_gts, state)
             VALUES (?, ?, ?, ?, ?, ?, "in_corso")',
            [
                (int) $boat['id'], (int) $boat['user_id'],
                $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                $numero, (string) $base['port_key'], $now,
            ]
        );
        $patrolId = Database::lastInsertId();

        // Battello pronto: casse piene, batterie cariche, aria buona.
        Database::run(
            'UPDATE boats SET state = "mare", lat = ?, lon = ?, est_lat = ?, est_lon = ?, est_error_nm = 0,
                    heading = ?, speed_kn = 0, ordered_speed_kn = 0, depth_m = 0, ordered_depth_m = 0,
                    mode = "superficie", silent = 0, periscopio_alzato = 0, periscopio_gts = NULL,
                    fuel_t = ?, battery_pct = 100, air_pct = 100, co2_pct = 0,
                    provisions_days = ?, last_fix_gts = ?, submerged_since = NULL, last_sim_gts = ?, version = version + 1
             WHERE id = ?',
            [
                (float) $base['lat'], (float) $base['lon'], (float) $base['lat'], (float) $base['lon'],
                0.0, (float) $type['fuel_t'], (float) $type['provisions_days'], $now, $now, (int) $boat['id'],
            ]
        );

        Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [(int) $boat['id']]);

        // Uscita dal bunker: il cantiere ha rimesso a posto quello che si
        // poteva rimettere a posto, e la stiva e' stata riempita.
        Damage::overhaul((int) $boat['id']);
        Torpedo::imbarca((int) $boat['id'], $type, Torpedo::caricoStandard($type, 0));
        Database::run(
            'UPDATE boat_stores SET qty = qty_max WHERE boat_id = ?',
            [(int) $boat['id']]
        );
        Database::run(
            "UPDATE crew_members SET fatigue = GREATEST(0, fatigue - 70),
                    morale = LEAST(100, morale + 18), health = IF(health = 'ferito', 'ok', health),
                    patrols = patrols + 1
             WHERE boat_id = ? AND health <> 'morto'",
            [(int) $boat['id']]
        );

        BoatSim::save([
            'gts' => $now, 'kind' => 'partenza', 'severity' => 'nota',
            'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'],
            'quadrat' => Grid::toQuadrat((float) $base['lat'], (float) $base['lon']),
            'text' => Narrator::partenza((string) $base['name'], (string) $boat['flotilla']),
        ], $patrolId, (int) $boat['id']);

        // Prima uscita di questo comandante: l'ordine di missione arriva subito,
        // prima di mollare gli ormeggi, e il I.WO spiega il passo successivo.
        // Senza, il nuovo arrivato si ritrova in mezzo all'oceano senza sapere
        // ne' dove andare ne' come dirlo alla centrale (audit A9).
        if ($numero === 1) {
            $boat['state'] = 'mare';
            $boat['lat'] = (float) $base['lat'];
            $boat['lon'] = (float) $base['lon'];
            $ordine = Bdu::assegnaArea($boat, $now);

            if ($ordine !== null) {
                $distanza = Geo::distanceNm((float) $base['lat'], (float) $base['lon'],
                    (float) $ordine['lat'], (float) $ordine['lon']);
                $giorni = $distanza / (10.0 * 24.0);
                // Il nome della zona sta fra parentesi nel testo dell'ordine.
                $zona = preg_match('/\(([^)]+)\)/', (string) $ordine['testo'], $m) === 1
                    ? $m[1]
                    : 'zona operativa';

                BoatSim::save([
                    'gts' => $now, 'kind' => 'ordine_bdu', 'severity' => 'nota',
                    'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'],
                    'quadrat' => Grid::toQuadrat((float) $base['lat'], (float) $base['lon']),
                    'text' => Narrator::ordineMissione((string) $ordine['quadrat'], $zona, $distanza, $giorni),
                ], $patrolId, (int) $boat['id']);

                BoatSim::save([
                    'gts' => $now + 60, 'kind' => 'iwo', 'severity' => 'nota',
                    'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'],
                    'quadrat' => Grid::toQuadrat((float) $base['lat'], (float) $base['lon']),
                    'text' => Narrator::primoConsiglio((string) $ordine['quadrat']),
                ], $patrolId, (int) $boat['id']);
            }
        }

        if ($waypoints !== []) {
            self::setRoute((int) $boat['id'], $waypoints);
        }

        return ['ok' => true, 'patrol_id' => $patrolId];
    }

    /**
     * Sostituisce la rotta pianificata.
     *
     * @param list<array{lat:float,lon:float,label?:string}> $waypoints
     */
    public static function setRoute(int $boatId, array $waypoints): int
    {
        Database::run('DELETE FROM boat_waypoints WHERE boat_id = ? AND reached_gts IS NULL', [$boatId]);
        $seq = (int) (Database::first(
            'SELECT COALESCE(MAX(seq), 0) AS s FROM boat_waypoints WHERE boat_id = ?',
            [$boatId]
        )['s'] ?? 0);

        $n = 0;
        foreach ($waypoints as $wp) {
            $lat = max(-60.0, min(75.0, (float) $wp['lat']));
            $lon = Geo::normLon((float) $wp['lon']);
            $seq++;
            $n++;
            Database::run(
                'INSERT INTO boat_waypoints (boat_id, seq, lat, lon, label) VALUES (?, ?, ?, ?, ?)',
                [$boatId, $seq, $lat, $lon, $wp['label'] ?? Grid::toQuadrat($lat, $lon)]
            );
        }
        return $n;
    }

    /** @return list<array<string,mixed>> */
    public static function route(int $boatId, bool $soloDaFare = true): array
    {
        return Database::all(
            'SELECT * FROM boat_waypoints WHERE boat_id = ?' . ($soloDaFare ? ' AND reached_gts IS NULL' : '') . ' ORDER BY seq',
            [$boatId]
        );
    }

    /** Ordini di macchina e di quota. */
    public static function orders(array $boat, ?float $speed, ?float $depth, ?bool $silent): array
    {
        $type = World::type((string) $boat['type_key']);
        $campi = [];
        $vals  = [];
        $note  = [];

        if ($speed !== null) {
            $max = max((float) $type['speed_surf_kn'], (float) $type['speed_sub_kn']);
            $speed = max(0.0, min($max, $speed));
            $campi[] = 'ordered_speed_kn = ?';
            $vals[] = $speed;
            $note[] = sprintf('velocita\' %.1f nodi', $speed);
        }
        if ($depth !== null) {
            $depth = max(0.0, min((float) $type['crush_depth_max_m'], $depth));
            $campi[] = 'ordered_depth_m = ?';
            $vals[] = $depth;
            $note[] = $depth <= 0.5 ? 'emersione' : sprintf('quota %.0f metri', $depth);

            // Se c'era un'immersione d'emergenza in corso, l'ordine del
            // comandante la chiude: da adesso la quota la decide lui.
            //
            // Senza questo, il I.WO alla scadenza della finestra riportava il
            // battello alla quota di PRIMA dell'allarme aereo — cioe' a galla —
            // cancellando in silenzio l'ordine appena dato. Il comandante
            // ordinava sessanta metri e si ritrovava in superficie mezz'ora
            // dopo senza sapere perche'.
            $campi[] = 'auto_dive_fine_gts = NULL';
            $campi[] = 'auto_dive_quota = NULL';
        }
        if ($silent !== null) {
            $campi[] = 'silent = ?';
            $vals[] = $silent ? 1 : 0;
            $note[] = $silent ? 'marcia silenziosa' : 'fine marcia silenziosa';
        }
        if ($campi === []) {
            return ['ok' => false, 'error' => 'Nessun ordine impartito.'];
        }

        $vals[] = (int) $boat['id'];
        Database::run('UPDATE boats SET ' . implode(', ', $campi) . ', version = version + 1 WHERE id = ?', $vals);

        $patrol = self::corrente((int) $boat['id']);
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => World::now(), 'kind' => 'ordine', 'severity' => 'info',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => 'Ordine del comandante: ' . implode(', ', $note) . '.',
            ], (int) $patrol['id'], (int) $boat['id']);
        }

        return ['ok' => true, 'note' => implode(', ', $note)];
    }

    /** Rientro in base: si puo' solo se si e' davanti al porto. */
    public static function dock(array $boat): array
    {
        if ((string) $boat['state'] !== 'mare') {
            return ['ok' => false, 'error' => 'Il battello non e\' in mare.'];
        }
        $base = World::port((string) $boat['home_port_key']);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Base sconosciuta.'];
        }

        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $base['lat'], (float) $base['lon']);
        if ($d > self::RAGGIO_PORTO_NM) {
            return ['ok' => false, 'error' => sprintf(
                'Siete a %.0f miglia da %s: avvicinatevi a meno di %.0f miglia per entrare in porto.',
                $d, (string) $base['name'], self::RAGGIO_PORTO_NM
            )];
        }

        $patrol = self::corrente((int) $boat['id']);
        $now = World::now();

        if ($patrol !== null) {
            BoatSim::save([
                'gts' => $now, 'kind' => 'rientro', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => Narrator::rientro(
                    (string) $base['name'],
                    (float) $patrol['distance_nm'],
                    $now - (int) $patrol['departed_gts']
                ),
            ], (int) $patrol['id'], (int) $boat['id']);

            Database::run(
                "UPDATE patrols SET state = 'conclusa', returned_gts = ? WHERE id = ?",
                [$now, (int) $patrol['id']]
            );
        }

        Database::run(
            'UPDATE boats SET state = "base", speed_kn = 0, ordered_speed_kn = 0, depth_m = 0, ordered_depth_m = 0,
                    mode = "superficie", silent = 0, lat = ?, lon = ?, est_lat = ?, est_lon = ?, est_error_nm = 0,
                    last_sim_gts = ?, version = version + 1
             WHERE id = ?',
            [
                (float) $base['lat'], (float) $base['lon'], (float) $base['lat'], (float) $base['lon'],
                $now, (int) $boat['id'],
            ]
        );
        Database::run('DELETE FROM boat_waypoints WHERE boat_id = ?', [(int) $boat['id']]);

        // Fine missione: conto del prestigio, promozione, decorazioni, rapporto.
        $rapporto = null;
        $cmd = Comandante::corrente((int) $boat['user_id']);
        if ($cmd !== null && $patrol !== null) {
            $chiuso = Carriera::chiudiPatrol(
                $cmd,
                array_merge($patrol, ['returned_gts' => $now]),
                $boat,
                true
            );
            $rapporto = $chiuso;

            if ($chiuso['promosso'] || $chiuso['avanzato']) {
                BoatSim::save([
                    'gts' => $now, 'kind' => 'promozione', 'severity' => 'nota',
                    'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'], 'quadrat' => null,
                    'text' => $chiuso['promosso']
                        ? 'Promozione al grado di ' . $chiuso['grado'] . '.'
                        : 'Maturata l\'anzianita\' di livello ' . $chiuso['livello'] . '.',
                ], (int) $patrol['id'], (int) $boat['id']);
            }
            // Trofei: si guardano quando si tira una riga, non a ogni battito.
            $user = Database::first('SELECT * FROM users WHERE id = ?', [(int) $boat['user_id']]);
            if ($user !== null) {
                $boatAgg = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]) ?? $boat;
                foreach (Trofei::verifica($user, $cmd, $boatAgg, array_merge($patrol, ['returned_gts' => $now])) as $t) {
                    BoatSim::save([
                        'gts' => $now, 'kind' => 'trofeo', 'severity' => 'nota',
                        'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'], 'quadrat' => null,
                        'text' => 'Trofeo: ' . $t['nome'] . ' — ' . $t['descrizione'],
                    ], (int) $patrol['id'], (int) $boat['id']);
                }
            }

            foreach ($chiuso['decorazioni'] as $d) {
                BoatSim::save([
                    'gts' => $now, 'kind' => 'decorazione', 'severity' => 'nota',
                    'lat' => (float) $base['lat'], 'lon' => (float) $base['lon'], 'quadrat' => null,
                    'text' => 'Conferimento: ' . $d['nome'] . ' — ' . $d['nome_it'] . '.',
                ], (int) $patrol['id'], (int) $boat['id']);
            }
        }

        return ['ok' => true, 'patrol_id' => $patrol !== null ? (int) $patrol['id'] : null, 'rapporto' => $rapporto];
    }

    /** @return list<array<string,mixed>> le ultime righe del giornale di guerra */
    public static function ktb(int $patrolId, int $limit = 100, int $sinceId = 0): array
    {
        return Database::all(
            'SELECT * FROM patrol_events WHERE patrol_id = ? AND id > ? ORDER BY gts DESC, id DESC LIMIT ' . max(1, min(500, $limit)),
            [$patrolId, $sinceId]
        );
    }
}
