<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HomeController
{
    /** La pagina d'ingresso: cos'e' Atlantik, e le due porte (accesso / arruolamento). */
    public function index(Request $request): Response
    {
        if (Auth::check() && Auth::status() === 'active') {
            return Response::redirect(url('/base'));
        }

        // Numeri veri del mondo in corso. La pagina d'ingresso di un mondo
        // persistente deve dire che il mondo c'e' ed e' acceso: quante navi
        // stanno navigando adesso, quanti convogli, quanta stazza e' stata
        // mandata a fondo finora.
        $stats = [
            'comandanti' => 0, 'naviglio_in_mare' => 0, 'convogli' => 0,
            'grt_in_mare' => 0, 'grt_affondato' => 0, 'navi_affondate' => 0,
        ];
        try {
            $stats['comandanti'] = (int) (Database::first(
                "SELECT COUNT(*) AS n FROM users WHERE status = 'active'"
            )['n'] ?? 0);
            $c = \App\Game\Statistiche::campagna(\App\Sim\World::now());
            foreach (['naviglio_in_mare', 'convogli', 'grt_in_mare', 'grt_affondato', 'navi_affondate'] as $k) {
                $stats[$k] = (int) ($c[$k] ?? 0);
            }
        } catch (\Throwable) {
            // Prima delle migrazioni, o col mondo non ancora seminato, la
            // pagina deve comunque aprirsi: si mostrano gli zeri.
        }

        return Response::html(view('home', [
            'title' => 'Battaglia dell\'Atlantico',
            'stats' => $stats,
        ]));
    }

    /** Sonda di servizio: usata dal monitoraggio e dal tick per sapere se l'app e' viva. */
    public function health(Request $request): Response
    {
        $db = false;
        try {
            $db = Database::isReachable();
        } catch (\Throwable) {
        }
        return Response::json([
            'app'  => 'atlantik',
            'ok'   => $db,
            'db'   => $db,
            'time' => date('c'),
        ], $db ? 200 : 503);
    }
}
