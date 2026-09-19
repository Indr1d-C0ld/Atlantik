<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Fleet;
use App\Game\Outfitting;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Torpedo;
use App\Sim\World;

/**
 * La stazione d'attacco: il periscopio, il calcolatore di lancio, il cannone,
 * e poi le ore sotto le cariche.
 */
final class CombattimentoController
{
    private function ctx(): array
    {
        $user = Auth::user();
        $boat = Fleet::ensureBoat((int) $user['id']);
        $enc  = Encounter::corrente((int) $boat['id']);

        // Se c'e' un incontro in corso, il tempo lo fa scorrere lui.
        if ($enc !== null) {
            Encounter::step((int) $enc['id']);
            $enc = Encounter::corrente((int) $boat['id']);
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);
        }

        return ['user' => $user, 'boat' => $boat, 'enc' => $enc, 'type' => World::type((string) $boat['type_key'])];
    }

    /**
     * Dati dell'incontro nella forma in cui li vede il comandante.
     *
     * "Nella forma in cui li vede" adesso e' letterale: il quadro lo costruisce
     * App\Sim\Vista, che ci mette dentro solo cio' che qualcuno ha visto o
     * sentito, con l'errore di chi l'ha visto o sentito. Prima questa funzione
     * passava alla pagina la verita' nuda — nome, classe e distanza al metro di
     * tutto il convoglio, anche col periscopio abbassato.
     */
    private function quadro(array $boat, array $enc, ?array &$sommario = null): array
    {
        $ciurma = \App\Sim\Crew::aggregate((int) $boat['id']);
        return \App\Sim\Vista::quadro(
            $boat, $enc,
            Encounter::entita((int) $enc['id']),
            min(1.4, ($ciurma['specialita']['marinaio'] ?? 3.0) / 6.0 + 0.45),
            min(1.4, ($ciurma['specialita']['radiotelegrafista'] ?? 1.2) / 1.8 + 0.35),
            $sommario,
        );
    }

    public function attacco(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            Session::flash('info', 'Nessun incontro in corso: si ingaggia da un contatto in mano.');
            return redirect('/contatti');
        }

        $c['unita']   = $this->quadro($c['boat'], $c['enc'], $sommario);
        $c['quadro_sommario'] = $sommario;
        $c['tubi']    = Torpedo::tubiPronti((int) $c['boat']['id']);
        $c['inventario'] = Torpedo::inventario((int) $c['boat']['id']);
        $c['tipi_siluro'] = Torpedo::tipi();
        $c['scorte']  = Outfitting::stores((int) $c['boat']['id']);
        $c['siluri_in_corsa'] = Database::all(
            "SELECT * FROM torpedo_runs WHERE encounter_id = ? AND esito = 'in_corsa'", [(int) $c['enc']['id']]
        );
        $c['clock']   = World::clock();
        $c['now']     = (int) $c['enc']['last_step_gts'];
        $c['finestra_s'] = max(0, (int) $c['enc']['finestra_fine'] - time());
        $c['cronaca'] = $c['enc']['patrol_id'] !== null
            ? Database::all(
                "SELECT * FROM patrol_events WHERE patrol_id = ? AND kind IN ('combattimento','lancio','cannone','bold','avaria','iwo')
                 ORDER BY id DESC LIMIT 18", [(int) $c['enc']['patrol_id']])
            : [];
        $c['title'] = 'Stazione d\'attacco';
        $c['illum'] = (string) $c['enc']['stato'] === 'evasione' ? 'alarm' : 'notte';

        return Response::html(view('game/attacco', $c));
    }

    /** Apre l'incontro a partire da un contatto. */
    public function ingaggia(Request $request): Response
    {
        $c = $this->ctx();
        $contatto = Database::first(
            'SELECT * FROM contacts WHERE id = ? AND boat_id = ?',
            [$request->int('contatto'), (int) $c['boat']['id']]
        );
        if ($contatto === null) {
            Session::flash('error', 'Contatto sconosciuto.');
            return redirect('/contatti');
        }

        $res = Encounter::apri($c['boat'], $contatto, World::now());
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Ingaggio non riuscito.');
            return redirect('/contatti');
        }

        Session::flash('success', 'Stazione di combattimento. Il battello e\' in avvicinamento.');
        return redirect('/attacco');
    }

    /** Ordini di manovra durante l'incontro. */
    public function manovra(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }

        $campi = [];
        $vals = [];
        if ($request->input('rotta') !== null) {
            $campi[] = 'ordered_heading = ?';
            $vals[] = Geo::normBearing((float) $request->input('rotta'));
        }
        if ($request->input('speed') !== null) {
            $campi[] = 'ordered_speed_kn = ?';
            $vals[] = max(0.0, min((float) $c['type']['speed_surf_kn'], (float) $request->input('speed')));
        }
        if ($request->input('depth') !== null) {
            $campi[] = 'ordered_depth_m = ?';
            $vals[] = max(0.0, min((float) $c['type']['crush_depth_max_m'], (float) $request->input('depth')));
        }
        if ($request->input('silent') !== null) {
            $campi[] = 'silent = ?';
            $vals[] = $request->int('silent') ? 1 : 0;
        }

        if ($campi !== []) {
            $vals[] = (int) $c['boat']['id'];
            Database::run('UPDATE boats SET ' . implode(', ', $campi) . ' WHERE id = ?', $vals);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true]);
        }
        return redirect('/attacco');
    }

    public function lancia(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }

        $tubi = array_values(array_filter(array_map('intval', (array) $request->input('tubi', []))));
        $stima = null;
        if ($request->input('aob') !== null && $request->input('distanza') !== null && $request->input('velocita') !== null) {
            $stima = [
                'aob'      => max(0.0, min(180.0, (float) $request->input('aob'))),
                'distanza' => max(0.05, (float) $request->input('distanza') / 1852.0),
                'velocita' => max(0.0, min(30.0, (float) $request->input('velocita'))),
            ];
        }

        $res = Encounter::lancia($c['enc'], $c['boat'], [
            'entity_id' => $request->int('bersaglio'),
            'tubi'      => $tubi,
            'spoletta'  => $request->str('spoletta', 'contatto'),
            'quota'     => (float) $request->input('quota', 4.0),
            'ventaglio' => (float) $request->input('ventaglio', 0.0),
            'stima'     => $stima,
        ], (int) $c['enc']['last_step_gts']);

        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['testo'] : ($res['error'] ?? 'Lancio non riuscito.'));
        return redirect('/attacco');
    }

    public function cannone(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }
        $res = Encounter::cannone($c['enc'], $c['boat'], $request->int('bersaglio'), $request->int('colpi', 6), (int) $c['enc']['last_step_gts']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['testo'] : ($res['error'] ?? 'Tiro non riuscito.'));
        return redirect('/attacco');
    }

    public function bold(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }
        $res = Encounter::bold($c['enc'], $c['boat'], (int) $c['enc']['last_step_gts']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['testo'] : ($res['error'] ?? 'Bold non disponibile.'));
        return redirect('/attacco');
    }

    /** Periscopio fuori o dentro: si vede, o non si e' visti. */
    public function periscopio(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }
        $alza = $request->str('stato') === 'alza';
        $res = Encounter::periscopio($c['boat'], $alza, (int) $c['enc']['last_step_gts']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? ($res['testo'] ?? '') : ($res['error'] ?? 'Comando non eseguito.'));
        return redirect('/attacco');
    }

    public function disimpegna(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return redirect('/zentrale');
        }
        Encounter::chiudi((int) $c['enc']['id'], 'Disimpegno ordinato dal comandante.', (int) $c['enc']['last_step_gts']);
        Session::flash('success', 'Disimpegno. Il battello torna alla navigazione di crociera.');
        return redirect('/zentrale');
    }

    /** Stato dell'incontro per il polling: fa anche avanzare la simulazione. */
    public function stato(Request $request): Response
    {
        $c = $this->ctx();
        if ($c['enc'] === null) {
            return Response::json(['ok' => true, 'incontro' => null]);
        }

        $boat = $c['boat'];
        $enc = $c['enc'];

        return Response::json([
            'ok' => true,
            'incontro' => [
                'id'        => (int) $enc['id'],
                'stato'     => (string) $enc['stato'],
                'allarme'   => (bool) $enc['allarme'],
                'ora'       => World::clock()->formatDiario((int) $enc['last_step_gts']),
                'finestra_s'=> max(0, (int) $enc['finestra_fine'] - time()),
                'affondate' => (int) $enc['affondate'],
                'grt'       => (int) $enc['grt_affondato'],
                'cariche'   => (int) $enc['cariche_subite'],
            ],
            'battello' => [
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'rotta' => (float) $boat['heading'], 'velocita' => (float) $boat['speed_kn'],
                'quota' => (float) $boat['depth_m'], 'quota_ord' => (float) $boat['ordered_depth_m'],
                'modo' => (string) $boat['mode'], 'silenzio' => (bool) $boat['silent'],
                'batteria' => (float) $boat['battery_pct'], 'aria' => (float) $boat['air_pct'],
                'stress' => (float) $boat['hull_stress'],
                'quadrat' => Grid::toQuadrat((float) $boat['est_lat'], (float) $boat['est_lon']),
            ],
            'unita' => $this->quadro($boat, $enc),
            'siluri' => array_map(static fn (array $r): array => [
                'tubo' => (int) $r['tubo'], 'lat' => (float) $r['lat'], 'lon' => (float) $r['lon'],
                'rotta' => (float) $r['heading'], 'percorso' => (int) $r['percorso_m'], 'corsa' => (int) $r['corsa_max_m'],
            ], Database::all("SELECT * FROM torpedo_runs WHERE encounter_id = ? AND esito = 'in_corsa'", [(int) $enc['id']])),
            'cronaca' => array_map(static fn (array $e): array => [
                'ora' => World::clock()->formatDiario((int) $e['gts']), 'testo' => (string) $e['text'],
            ], $enc['patrol_id'] !== null ? Database::all(
                "SELECT * FROM patrol_events WHERE patrol_id = ? AND kind IN ('combattimento','lancio','cannone','bold','avaria','iwo')
                 ORDER BY id DESC LIMIT 12", [(int) $enc['patrol_id']]) : []),
        ]);
    }
}
