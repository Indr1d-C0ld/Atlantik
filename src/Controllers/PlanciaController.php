<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Fleet;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Acoustics;
use App\Sim\Consumption;
use App\Sim\Contacts;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Movement;
use App\Sim\Sectors;
use App\Sim\World;

/**
 * La plancia: Zentrale, tavolo di carteggio, giornale di guerra, e gli ordini.
 *
 * Ogni richiesta porta prima il battello all'ora attuale (avanzamento pigro):
 * cosi' il giocatore vede sempre lo stato vero anche se il tick da cron e'
 * indietro di qualche minuto.
 */
final class PlanciaController
{
    /** Contesto comune: battello aggiornato, tipo, patrol, meteo, cielo. */
    private function ctx(): array
    {
        $user = Auth::user();
        $boat = Fleet::ensureBoat((int) $user['id']);

        // Si avanza anche in porto: la' non si naviga ma il cantiere ripara,
        // e con questa guardia ferma a 'mare' un battello in base non veniva
        // toccato da nessuno (segnalazione del 21/09/2026).
        if (in_array((string) $boat['state'], ['mare', 'base'], true)) {
            BoatSim::advance((int) $boat['id']);
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);
        }

        $type   = World::type((string) $boat['type_key']);
        $patrol = Patrol::corrente((int) $boat['id']);
        $clock  = World::clock();
        $now    = World::now();

        $lat = (float) $boat['lat'];
        $lon = (float) $boat['lon'];
        $meteo = World::weather($lat, $lon, $now);
        $cielo = World::sky($lat, $lon, $now, (float) $meteo['cloud']);

        return compact('user', 'boat', 'type', 'patrol', 'clock', 'now', 'meteo', 'cielo');
    }

    /**
     * Le stazioni che esistono solo in mare.
     *
     * In porto la centrale e' vuota, il tavolo di carteggio non ha niente da
     * riportare e l'idrofono sente il molo. Prima il rimando alla flottiglia
     * era muto: si cliccava e si tornava indietro senza una parola, e sembrava
     * un guasto. Adesso lo dice.
     */
    private function soloInMare(array $c, string $cosa): ?Response
    {
        if ((string) $c['boat']['state'] === 'mare') {
            return null;
        }
        Session::flash('info', $cosa . ' si apre quando il battello e\' in mare: '
            . 'in porto non c\'e\' guardia da montare. Si molla dalla flottiglia.');
        return redirect('/base');
    }

    /** Illuminazione della plancia: acciaio di giorno, rosso di notte. */
    private function illum(array $c): string
    {
        if ((string) $c['boat']['state'] !== 'mare') {
            return '';
        }
        if ((string) $c['boat']['mode'] !== 'superficie') {
            return 'notte';
        }
        return (float) $c['cielo']['luce'] < 0.12 ? 'notte' : '';
    }

    public function zentrale(Request $request): Response
    {
        $c = $this->ctx();

        $fermo = $this->soloInMare($c, 'La centrale');
        if ($fermo !== null) {
            return $fermo;
        }

        $type = $c['type'];
        $boat = $c['boat'];

        $c['rotta']     = Patrol::route((int) $boat['id']);
        $c['autonomia'] = Consumption::rangeLeftNm($type, (float) $boat['fuel_t'], max(6.0, (float) $boat['speed_kn']));
        $c['ore_sub']   = Consumption::submergedHoursLeft($type, (float) $boat['battery_pct'], max(2.0, (float) $boat['speed_kn']), (bool) $boat['silent']);
        $c['max_kn']    = Movement::maxSpeed($type, (string) $boat['mode'], (int) $c['meteo']['sea_state']);
        $c['quadrat']   = Grid::toQuadrat((float) $boat['est_lat'], (float) $boat['est_lon']) ?? '—';
        $c['effetti']   = Damage::effects(Damage::systems((int) $boat['id']), (float) $boat['hull_stress'],
            \App\Game\Carriera::effettiMiglioramenti((int) $boat['id'])['quota_max'] ?? 1.0);
        $c['ciurma']    = Crew::aggregate((int) $boat['id']);
        $c['ktb']       = $c['patrol'] !== null ? Patrol::ktb((int) $c['patrol']['id'], 14) : [];
        $c['title']     = 'Zentrale — ' . $boat['uboat_number'];
        $c['illum']     = $this->illum($c);

        return Response::html(view('game/zentrale', $c));
    }

    public function carta(Request $request): Response
    {
        $c = $this->ctx();
        $fermo = $this->soloInMare($c, 'Il tavolo di carteggio');
        if ($fermo !== null) {
            return $fermo;
        }
        $c['rotta']    = Patrol::route((int) $c['boat']['id']);
        $c['contatti'] = Contacts::attivi((int) $c['boat']['id'], 20);
        $c['quadrat']  = Grid::toQuadrat((float) $c['boat']['est_lat'], (float) $c['boat']['est_lon']) ?? '—';
        $c['stile']    = \App\Game\Preferenze::get((int) $c['user']['id'], 'carta.stile');
        $c['title']    = 'Tavolo di carteggio';
        $c['illum']   = $this->illum($c);
        return Response::html(view('game/carta', $c));
    }

    /** Horchraum: la stanza dell'ascolto, piu' quello che riportano le vedette. */
    /** Stile del disegno della carta: scelta di comodo, non di gioco. */
    public function preferenzaCarta(Request $request): Response
    {
        $res = \App\Game\Preferenze::set(
            (int) Auth::user()['id'],
            'carta.stile',
            $request->str('stile')
        );
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Preferenza non applicata.');
        }
        return redirect('/carta');
    }

    public function contatti(Request $request): Response
    {
        $c = $this->ctx();
        $fermo = $this->soloInMare($c, 'La stanza dell\'ascolto');
        if ($fermo !== null) {
            return $fermo;
        }

        $boat = $c['boat'];
        $type = $c['type'];
        $meteo = $c['meteo'];
        $data = $c['clock']->date($c['now']);

        $c['contatti'] = Contacts::recenti((int) $boat['id'], 30);

        // Che cosa si riuscirebbe a sentire adesso, con questo mare e con
        // questa andatura: e' l'informazione che decide se restare in ascolto
        // o rassegnarsi a non sentire niente.
        $rumoreProprio = Acoustics::ownNoise(
            (float) $boat['speed_kn'], (bool) $boat['silent'], (float) $type['speed_sub_kn']
        );
        if ((string) $boat['mode'] === 'superficie' && (float) $boat['speed_kn'] > 1.0) {
            $rumoreProprio += 22.0;
        }
        $strato = Acoustics::layerDepth((float) $boat['lat'], (int) $data->format('n'), (int) $meteo['sea_state']);

        $c['ascolto'] = [
            'rumore_proprio' => round($rumoreProprio, 1),
            'strato_m'       => $strato,
            'sotto_strato'   => $strato > 0 && (float) $boat['depth_m'] > $strato + 10,
            'portata_convoglio' => Acoustics::detectionRangeNm(
                Acoustics::sourceLevel(138, 9, 9.5, 40), (int) $meteo['sea_state'], (float) $meteo['precip'], $rumoreProprio
            ),
            'portata_nave' => Acoustics::detectionRangeNm(
                Acoustics::sourceLevel(138, 9.5, 9.5, 1), (int) $meteo['sea_state'], (float) $meteo['precip'], $rumoreProprio
            ),
        ];

        $c['vista'] = [
            'portata_grande' => \App\Sim\Detection::portataVisiva(
                \App\Sim\Detection::H_TORRETTA, 30.0, 1.0,
                (float) $meteo['visibility_nm'], (float) $c['cielo']['luce'], (int) $meteo['sea_state'], 1.0, (bool) $meteo['fog']
            ),
            'nostra_sagoma' => \App\Sim\Detection::sagomaBattello((string) $boat['mode'], (float) $boat['depth_m'], true),
            'ci_vedono' => \App\Sim\Detection::portataVisiva(
                \App\Sim\Detection::H_PONTE_SCORTA,
                (string) $boat['mode'] === 'superficie' ? 5.0 : 1.0,
                \App\Sim\Detection::sagomaBattello((string) $boat['mode'], (float) $boat['depth_m'], true),
                (float) $meteo['visibility_nm'], (float) $c['cielo']['luce'], (int) $meteo['sea_state'], 1.15, (bool) $meteo['fog']
            ),
        ];

        $c['settore'] = [
            'quadrat' => Sectors::key((float) $boat['est_lat'], (float) $boat['est_lon']),
            'heat'    => Sectors::heat(Sectors::key((float) $boat['lat'], (float) $boat['lon']), $c['now']),
        ];
        $c['settore']['stato'] = Sectors::stato($c['settore']['heat']);

        $c['title'] = 'Ascolto e avvistamenti';
        $c['illum'] = $this->illum($c);

        return Response::html(view('game/contatti', $c));
    }

    public function ktb(Request $request): Response
    {
        $c = $this->ctx();
        $patrolId = $request->int('patrol', 0);

        if ($patrolId > 0) {
            $p = Database::first('SELECT * FROM patrols WHERE id = ? AND user_id = ?', [$patrolId, (int) $c['user']['id']]);
        } else {
            $p = $c['patrol'] ?? Database::first(
                'SELECT * FROM patrols WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $c['user']['id']]
            );
        }

        $c['patrol_scelta'] = $p;
        $c['righe']  = $p !== null ? Patrol::ktb((int) $p['id'], 400) : [];

        // Il naviglio affondato, indicizzato per l'ora esatta: nel giornale la
        // riga dell'affondamento porta la stessa ora, e cosi' si sa QUALE nave
        // era senza doverlo indovinare dal testo.
        $c['affondate'] = [];
        if ($p !== null) {
            foreach (Database::all(
                'SELECT gts, nome, class_key, grt FROM sinkings WHERE patrol_id = ?',
                [(int) $p['id']]
            ) as $a) {
                $c['affondate'][(int) $a['gts']] = $a;
            }
        }
        $c['patrols'] = Database::all(
            'SELECT id, number, departed_gts, returned_gts, state, distance_nm FROM patrols WHERE user_id = ? ORDER BY id DESC',
            [(int) $c['user']['id']]
        );
        $c['title'] = 'Giornale di guerra';
        $c['illum'] = $this->illum($c);
        return Response::html(view('game/ktb', $c));
    }

    // --- Azioni ---------------------------------------------------------------

    public function uscita(Request $request): Response
    {
        $c = $this->ctx();
        $res = \App\Game\Patrol::depart($c['boat']);
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Uscita non riuscita.');
            return redirect('/base');
        }
        Session::flash('success', 'Mollati gli ormeggi. Buona caccia, Herr Kaleun.');
        return redirect('/zentrale');
    }

    public function rientro(Request $request): Response
    {
        $c = $this->ctx();
        $res = \App\Game\Patrol::dock($c['boat']);
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Rientro non riuscito.');
            return redirect('/zentrale');
        }
        Session::flash('success', 'Battello in porto. Patrol conclusa.');
        return redirect('/base');
    }

    public function ordini(Request $request): Response
    {
        $c = $this->ctx();
        $fermo = $this->soloInMare($c, 'Gli ordini alla centrale');
        if ($fermo !== null) {
            return $fermo;
        }

        $speed  = $request->input('speed') !== null ? (float) $request->input('speed') : null;
        $depth  = $request->input('depth') !== null ? (float) $request->input('depth') : null;
        $silent = $request->input('silent') !== null ? (bool) $request->int('silent') : null;

        $res = \App\Game\Patrol::orders($c['boat'], $speed, $depth, $silent);

        if ($request->wantsJson()) {
            return Response::json($res, $res['ok'] ? 200 : 422);
        }
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Ordine impartito: ' . $res['note'] : ($res['error'] ?? 'Ordine non valido.'));
        return redirect('/zentrale');
    }

    public function rotta(Request $request): Response
    {
        $c = $this->ctx();
        $fermo = $this->soloInMare($c, 'Il carteggio della rotta');
        if ($fermo !== null) {
            return $fermo;
        }

        $raw = (string) $request->input('waypoints', '[]');
        /** @var mixed $dati */
        $dati = json_decode($raw, true);
        if (!is_array($dati)) {
            $msg = 'Rotta non leggibile.';
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => $msg], 422)
                : redirect('/carta');
        }

        $wp = [];
        foreach (array_slice($dati, 0, 24) as $p) {
            if (!is_array($p) || !isset($p['lat'], $p['lon'])) {
                continue;
            }
            $wp[] = ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon']];
        }

        $n = \App\Game\Patrol::setRoute((int) $c['boat']['id'], $wp);

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'waypoints' => $n]);
        }
        Session::flash('success', $n > 0 ? "Rotta tracciata: {$n} punti." : 'Rotta cancellata.');
        return redirect('/carta');
    }

    /** Stato completo per la plancia, in JSON: e' quello che interroga il polling. */
    public function stato(Request $request): Response
    {
        $c = $this->ctx();
        $boat = $c['boat'];
        $type = $c['type'];
        $lat = (float) $boat['est_lat'];
        $lon = (float) $boat['est_lon'];

        $rotta = array_map(static fn (array $w): array => [
            'seq' => (int) $w['seq'], 'lat' => (float) $w['lat'], 'lon' => (float) $w['lon'], 'label' => $w['label'],
        ], Patrol::route((int) $boat['id']));

        $effetti = Damage::effects(Damage::systems((int) $boat['id']), (float) $boat['hull_stress'],
            \App\Game\Carriera::effettiMiglioramenti((int) $boat['id'])['quota_max'] ?? 1.0);
        $ciurma  = Crew::aggregate((int) $boat['id']);

        return Response::json([
            'ok'   => true,
            'ora'  => $c['clock']->formatDiario($c['now']),
            'gts'  => $c['now'],
            'battello' => [
                'numero'   => $boat['uboat_number'],
                'tipo'     => $type['name'],
                'stato'    => $boat['state'],
                'modo'     => $boat['mode'],
                'quadrat'  => Grid::toQuadrat($lat, $lon),
                'lat'      => $lat,
                'lon'      => $lon,
                'lat_txt'  => Geo::formatLat($lat),
                'lon_txt'  => Geo::formatLon($lon),
                'errore_nm'=> (float) $boat['est_error_nm'],
                'rotta'    => (float) $boat['heading'],
                'velocita' => (float) $boat['speed_kn'],
                'vel_ord'  => (float) $boat['ordered_speed_kn'],
                'quota'    => (float) $boat['depth_m'],
                'quota_ord'=> (float) $boat['ordered_depth_m'],
                'silenzio' => (bool) $boat['silent'],
                'nafta_t'  => (float) $boat['fuel_t'],
                'nafta_pct'=> round(100 * (float) $boat['fuel_t'] / max(0.1, (float) $type['fuel_t']), 1),
                'batteria' => (float) $boat['battery_pct'],
                'aria'     => (float) $boat['air_pct'],
                'co2'      => (float) $boat['co2_pct'],
                'viveri'   => (float) $boat['provisions_days'],
                'autonomia_nm' => round(Consumption::rangeLeftNm($type, (float) $boat['fuel_t'], 10), 0),
                'ore_immersione' => round(Consumption::submergedHoursLeft($type, (float) $boat['battery_pct'], max(2.0, (float) $boat['speed_kn']), (bool) $boat['silent']), 1),
            ],
            'materiale' => [
                'avarie' => $effetti['guasti'],
                'elenco' => $effetti['elenco'],
                'morale' => $ciurma['morale'],
                'fatica' => $ciurma['fatica'],
                'stress' => (float) $boat['hull_stress'],
            ],
            'meteo' => $c['meteo'],
            'cielo' => $c['cielo'],
            'rotta_pianificata' => $rotta,
            'contatti' => array_map(static fn (array $k): array => [
                'id'       => (int) $k['id'],
                'tipo'     => (string) $k['target_kind'],
                'sensore'  => (string) $k['sensore'],
                'rilevamento' => (float) $k['bearing'],
                'distanza' => $k['range_nm'] !== null ? (float) $k['range_nm'] : null,
                'classe'   => $k['classe_est'],
                'certezza' => (float) $k['certezza'],
                'perso'    => (bool) $k['perso'],
                'lat'      => $k['lat_est'] !== null ? (float) $k['lat_est'] : null,
                'lon'      => $k['lon_est'] !== null ? (float) $k['lon_est'] : null,
            ], Contacts::attivi((int) $boat['id'], 20)),
            'ktb' => array_map(fn (array $e): array => [
                'ora'  => $c['clock']->formatDiario((int) $e['gts']),
                'kind' => $e['kind'],
                'sev'  => $e['severity'],
                'quadrat' => $e['quadrat'],
                'text' => $e['text'],
            ], $c['patrol'] !== null ? Patrol::ktb((int) $c['patrol']['id'], 20) : []),
        ]);
    }
}
