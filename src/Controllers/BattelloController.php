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
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\Grid;
use App\Sim\World;

/**
 * Lo stato materiale della missione: il battello (compartimenti, sistemi,
 * avarie, riparazioni), l'equipaggio (ruolino, turni, morale) e, in bunker,
 * il cantiere e l'allestimento.
 */
final class BattelloController
{
    private function ctx(): array
    {
        $user = Auth::user();
        $boat = Fleet::ensureBoat((int) $user['id']);

        if ((string) $boat['state'] === 'mare') {
            BoatSim::advance((int) $boat['id']);
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);
        }

        return [
            'user'  => $user,
            'boat'  => $boat,
            'type'  => World::type((string) $boat['type_key']),
            'clock' => World::clock(),
            'now'   => World::now(),
        ];
    }

    public function battello(Request $request): Response
    {
        $c = $this->ctx();
        $id = (int) $c['boat']['id'];

        $sistemi = Damage::systems($id);
        $c['sistemi']       = $sistemi;
        $c['compartimenti'] = Database::all('SELECT * FROM boat_compartments WHERE boat_id = ? ORDER BY seq', [$id]);
        $c['effetti']       = Damage::effects($sistemi, (float) $c['boat']['hull_stress'],
            \App\Game\Carriera::effettiMiglioramenti((int) $c['boat']['id'])['quota_max'] ?? 1.0);
        $c['scorte']        = Outfitting::stores($id);
        $c['voci']          = Outfitting::voci();
        $c['ciurma']        = Crew::aggregate($id);
        $c['title']         = 'Battello — ' . $c['boat']['uboat_number'];
        $c['illum']         = (string) $c['boat']['mode'] !== 'superficie' ? 'notte' : '';

        return Response::html(view('game/battello', $c));
    }

    public function equipaggio(Request $request): Response
    {
        $c = $this->ctx();
        $id = (int) $c['boat']['id'];

        $c['ruolino'] = Crew::roster($id);
        $c['ciurma']  = Crew::aggregate($id);
        $c['guardia'] = Crew::currentWatch($c['now']);
        $c['title']   = 'Equipaggio';
        $c['illum']   = (string) $c['boat']['mode'] !== 'superficie' ? 'notte' : '';

        return Response::html(view('game/equipaggio', $c));
    }

    /**
     * Sigilla o riapre una paratia.
     *
     * E' la decisione piu' dura che ci sia a bordo, e il gioco non la fa
     * sembrare gratis: se il compartimento e' allagato oltre un terzo, chi e'
     * dentro resta dentro. Si puo' fare solo in mare — in bacino l'acqua non
     * c'e' e la paratia non serve a niente.
     */
    public function paratia(Request $request): Response
    {
        $c = $this->ctx();
        if ((string) $c['boat']['state'] !== 'mare') {
            Session::flash('error', 'Le paratie si manovrano in mare, non all\'ormeggio.');
            return redirect('/battello');
        }

        $res = \App\Sim\Compartimenti::sigilla(
            (int) $c['boat']['id'],
            $request->str('compartimento'),
            $request->str('azione') === 'sigilla'
        );

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Ordine non eseguito.');
            return redirect('/battello');
        }

        $patrol = \App\Game\Patrol::corrente((int) $c['boat']['id']);
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => World::now(), 'kind' => 'avaria', 'severity' => 'allarme',
                'lat' => (float) $c['boat']['lat'], 'lon' => (float) $c['boat']['lon'],
                'quadrat' => Grid::toQuadrat((float) $c['boat']['lat'], (float) $c['boat']['lon']),
                'text' => 'Ordine del comandante: ' . $res['evento'],
            ], (int) $patrol['id'], (int) $c['boat']['id']);
        }

        Session::flash('success', $res['evento'] ?? 'Fatto.');
        return redirect('/battello');
    }

    /** Il comandante indica su cosa deve lavorare la squadra di riparazione. */
    public function riparazione(Request $request): Response
    {
        $c = $this->ctx();
        $skey = $request->str('skey');

        if ($skey === '') {
            Database::run('UPDATE boats SET repair_focus = NULL WHERE id = ?', [(int) $c['boat']['id']]);
            Session::flash('success', 'La squadra torna alla priorita\' automatica.');
            return redirect('/battello');
        }

        $s = Database::first('SELECT * FROM boat_systems WHERE boat_id = ? AND skey = ?', [(int) $c['boat']['id'], $skey]);
        if ($s === null) {
            Session::flash('error', 'Sistema sconosciuto.');
            return redirect('/battello');
        }
        if ((int) $s['repairable_sea'] !== 1) {
            Session::flash('error', (string) $s['name'] . ': non si ripara a mare. Serve il cantiere.');
            return redirect('/battello');
        }

        Database::run('UPDATE boats SET repair_focus = ? WHERE id = ?', [$skey, (int) $c['boat']['id']]);

        $patrol = Patrol::corrente((int) $c['boat']['id']);
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => $c['now'], 'kind' => 'ordine', 'severity' => 'info',
                'lat' => (float) $c['boat']['lat'], 'lon' => (float) $c['boat']['lon'],
                'quadrat' => null,
                'text' => 'Ordine del comandante: squadra di riparazione su ' . $s['name'] . '.',
            ], (int) $patrol['id'], (int) $c['boat']['id']);
        }

        Session::flash('success', 'Squadra di riparazione su ' . $s['name'] . '.');
        return redirect('/battello');
    }

    /** Turni: sposta un uomo di guardia. */
    public function turno(Request $request): Response
    {
        $c = $this->ctx();
        $crewId = $request->int('crew_id');
        $turno  = max(0, min(3, $request->int('watch')));

        $m = Database::first('SELECT * FROM crew_members WHERE id = ? AND boat_id = ?', [$crewId, (int) $c['boat']['id']]);
        if ($m === null) {
            Session::flash('error', 'Uomo non trovato a ruolino.');
            return redirect('/equipaggio');
        }

        Database::run('UPDATE crew_members SET watch_no = ? WHERE id = ?', [$turno, $crewId]);
        Session::flash('success', sprintf('%s passa %s.', $m['name'], $turno === 0 ? 'al lavoro a giornata' : "alla {$turno}ª guardia"));
        return redirect('/equipaggio');
    }

    // --- In bunker -------------------------------------------------------------

    public function cantiere(Request $request): Response
    {
        $c = $this->ctx();
        if ((string) $c['boat']['state'] !== 'base') {
            return redirect('/battello');
        }

        $c['tipi']      = array_filter(World::types(), static fn (array $t): bool => (int) $t['playable'] === 1);
        $c['grado']     = 0;   // in F5 arriva il grado vero del comandante
        $c['scorte']    = Outfitting::stores((int) $c['boat']['id']);
        $c['voci']      = Outfitting::voci();
        $c['capacita']  = Outfitting::capacita($c['type']);
        $c['usato']     = Outfitting::spazioUsato($c['scorte']);
        $c['title']     = 'Cantiere e allestimento';
        $c['emblemi']   = \App\Game\Emblema::catalogo((int) $c['boat']['id']);
        $c['emblema']   = \App\Game\Emblema::di($c['boat']);

        return Response::html(view('game/cantiere', $c));
    }

    /** Emblema di torretta: uno dal repertorio. */
    public function emblemaScegli(Request $request): Response
    {
        $c = $this->ctx();
        if ((string) $c['boat']['state'] !== 'base') {
            Session::flash('error', "L'emblema si dipinge in bunker, non in mezzo all'Atlantico.");
            return redirect('/battello');
        }
        $chiave = $request->str('chiave');
        $res = $chiave === ''
            ? \App\Game\Emblema::togli((int) $c['boat']['id'])
            : \App\Game\Emblema::scegli((int) $c['boat']['id'], $chiave);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($chiave === '' ? 'Torretta ripulita.' : 'Emblema dipinto sulla torretta.')
            : ($res['error'] ?? 'Non si puo\' fare.'));
        return redirect('/cantiere');
    }

    /** Emblema di torretta: uno portato da casa. */
    public function emblemaCarica(Request $request): Response
    {
        $c = $this->ctx();
        if ((string) $c['boat']['state'] !== 'base') {
            Session::flash('error', "L'emblema si dipinge in bunker, non in mezzo all'Atlantico.");
            return redirect('/battello');
        }
        $res = \App\Game\Emblema::carica((int) $c['boat']['id'], $_FILES['emblema'] ?? []);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Emblema dipinto sulla torretta.'
            : ($res['error'] ?? 'Caricamento non riuscito.'));
        return redirect('/cantiere');
    }

    public function cambiaTipo(Request $request): Response
    {
        $c = $this->ctx();
        $res = Outfitting::changeType($c['boat'], $request->str('type_key'), 0, (string) $GLOBALS['__project_root']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Assegnato un ' . $res['tipo'] . '. Compartimenti, sistemi ed equipaggio adeguati.'
            : ($res['error'] ?? 'Cambio non riuscito.'));
        return redirect('/cantiere');
    }

    public function allestimento(Request $request): Response
    {
        $c = $this->ctx();
        $carico = [];
        foreach (array_keys(Outfitting::voci()) as $k) {
            $carico[$k] = (float) $request->input($k, 0);
        }

        $res = Outfitting::load($c['boat'], $c['type'], $carico);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? sprintf('Carico imbarcato: %.0f unita\' di stiva su %d.', $res['spazio'], $res['capacita'])
            : ($res['error'] ?? 'Carico non valido.'));
        return redirect('/cantiere');
    }
}
