<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Game\Fleet;
use App\Sim\Consumption;
use App\Sim\World;

/**
 * La base di flottiglia: il porto sicuro. Qui si trova il battello assegnato,
 * si guarda com'e' andata l'ultima missione e si molla per la prossima.
 * Armamento, carriera e scelta del tipo arrivano in F5.
 */
final class BaseController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Senza comandante non si esce: prima l'uomo, poi il battello.
        $cmd = \App\Game\Comandante::corrente((int) $user['id']);
        if ($cmd === null) {
            return redirect('/comandante');
        }

        $boat = Fleet::ensureBoat((int) $user['id']);

        if ((string) $boat['state'] === 'mare') {
            return redirect('/zentrale');
        }

        // In banchina il cantiere lavora, e questa e' la pagina dove si aspetta
        // che finisca: senza questo avanzamento il lavoro andrebbe avanti solo
        // col battito di sistema, e chi guarda la base vedrebbe numeri vecchi.
        \App\Sim\BoatSim::advance((int) $boat['id']);
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);

        $type = World::type((string) $boat['type_key']);
        $base = World::port((string) $boat['home_port_key']);

        $patrols = Database::all(
            'SELECT * FROM patrols WHERE boat_id = ? ORDER BY id DESC LIMIT 10',
            [(int) $boat['id']]
        );
        $totali = Database::first(
            'SELECT COUNT(*) n, COALESCE(SUM(distance_nm),0) nm, COALESCE(SUM(fuel_used_t),0) t,
                    COALESCE(MAX(max_depth_m),0) q, COALESCE(SUM(siluri_lanciati),0) siluri
             FROM patrols WHERE boat_id = ? AND state <> "in_corso"',
            [(int) $boat['id']]
        );
        $bottino = Database::first(
            'SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings WHERE boat_id = ?',
            [(int) $boat['id']]
        );

        // Che cosa il cantiere non ha ancora finito. Dal 21/09/2026 la partenza
        // non rimette piu' niente a posto da sola: quello che e' rotto adesso
        // esce in mare insieme al battello, e il comandante deve saperlo prima
        // di mollare, non dopo.
        $inLavorazione = Database::all(
            "SELECT name, state FROM boat_systems WHERE boat_id = ? AND state <> 'ok'
              ORDER BY FIELD(category,'propulsione','governo','scoperta','scafo','armamento'), name",
            [(int) $boat['id']]
        );
        $compInLavorazione = Database::all(
            'SELECT name FROM boat_compartments
              WHERE boat_id = ? AND (integrity < 100 OR flooding > 0 OR fire > 0 OR sealed = 1)
              ORDER BY seq',
            [(int) $boat['id']]
        );

        return Response::html(view('base/flottiglia', [
            'title'     => 'Base di flottiglia',
            'inLavorazione' => $inLavorazione,
            'compInLavorazione' => $compInLavorazione,
            'user'      => $user,
            'boat'      => $boat,
            'type'      => $type,
            'base'      => $base,
            'patrols'   => $patrols,
            'totali'    => $totali,
            'bottino'   => $bottino,
            'affondamenti' => Database::all(
                'SELECT * FROM sinkings WHERE boat_id = ? ORDER BY gts DESC LIMIT 12', [(int) $boat['id']]
            ),
            'cmd'       => $cmd,
            'grado'     => \App\Game\Carriera::gradoNome((int) $cmd['grado']),
            'clock'     => World::clock(),
            'now'       => World::now(),
            'autonomia' => Consumption::rangeLeftNm($type, (float) $type['fuel_t'], 10),
        ]));
    }
}
