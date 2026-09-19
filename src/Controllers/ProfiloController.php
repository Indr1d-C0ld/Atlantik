<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Comandante;
use App\Game\Profilo;
use App\Game\Ritratto;
use App\Sim\World;
use App\Support\Audit;

/**
 * Il fascicolo di un comandante, e il volto che ci si mette sopra.
 *
 * In flottiglia ci si conosce: il fascicolo di chiunque e' leggibile da
 * chiunque altro sia in servizio. Non e' pubblico al mondo — serve un account
 * attivo — perche' e' una cosa fra comandanti, non una vetrina.
 */
final class ProfiloController
{
    /** Il fascicolo di un comandante, come lo vedono gli altri. */
    public function mostra(Request $request, string $id): Response
    {
        $p = Profilo::di((int) $id);
        if ($p === null) {
            Session::flash('error', 'Nessun comandante con questo numero di fascicolo.');
            return redirect('/albo');
        }

        $mio = Comandante::corrente((int) Auth::user()['id']);

        return Response::html(view('carriera/profilo', [
            'title' => 'Fascicolo — ' . $p['cmd']['nome'],
            'p'     => $p,
            'e_mio' => $mio !== null && (int) $mio['id'] === (int) $p['cmd']['id'],
            'admin' => Auth::isAdmin(),
            'clock' => World::clock(),
        ]));
    }

    /** La pagina dove ci si sceglie il volto e si scrive due righe di se'. */
    public function modifica(Request $request): Response
    {
        $cmd = Comandante::corrente((int) Auth::user()['id']);
        if ($cmd === null) {
            Session::flash('info', 'Prima si prende servizio.');
            return redirect('/comandante');
        }

        return Response::html(view('carriera/profilo_modifica', [
            'title'    => 'Il tuo fascicolo',
            'cmd'      => $cmd,
            'ritratto' => Ritratto::di($cmd),
            'catalogo' => Ritratto::elenco((int) $cmd['id']),
            'azione'   => '/comandante',
            'admin'    => false,
        ]));
    }

    // --- le azioni -------------------------------------------------------------

    public function scegli(Request $request): Response
    {
        [$cmd, $errore] = $this->bersaglio($request);
        if ($cmd === null) {
            return $errore;
        }

        $res = Ritratto::scegli(
            (int) $cmd['id'],
            $request->str('ritratto'),
            $request->str('nome_storico') === '1'
        );

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Scelta non riuscita.');
        } elseif (($res['nome_storico'] ?? null) !== null) {
            Session::flash('success', 'Ritratto nel fascicolo, e da oggi ti chiami '
                . $res['nome_storico'] . ' — in omaggio, non per inganno.');
        } elseif (($res['error'] ?? null) !== null) {
            Session::flash('error', $res['error']);
        } else {
            Session::flash('success', 'Ritratto nel fascicolo.');
        }
        return $this->indietro($cmd, $request);
    }

    public function carica(Request $request): Response
    {
        [$cmd, $errore] = $this->bersaglio($request);
        if ($cmd === null) {
            return $errore;
        }
        // Se l'invecchiamento l'ha gia' applicato il browser — quello che si
        // vedeva nel riquadro e' esattamente quello che e' partito — non si
        // rifa': due volte si vede.
        $res = Ritratto::carica(
            (int) $cmd['id'],
            $_FILES['ritratto'] ?? [],
            $request->str('invecchia') === '1' && $request->str('gia_invecchiata') !== '1'
        );
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Fotografia nel fascicolo.' : ($res['error'] ?? 'Caricamento non riuscito.'));
        return $this->indietro($cmd, $request);
    }

    public function togli(Request $request): Response
    {
        [$cmd, $errore] = $this->bersaglio($request);
        if ($cmd === null) {
            return $errore;
        }
        Ritratto::togli((int) $cmd['id']);
        if (Auth::isAdmin() && (int) $cmd['user_id'] !== (int) Auth::id()) {
            Audit::log('admin.ritratto_tolto', (int) Auth::id(), 'commander', (int) $cmd['id'], [], $request->ip());
        }
        Session::flash('success', 'Ritratto tolto. Il volto torna disponibile per gli altri.');
        return $this->indietro($cmd, $request);
    }

    public function nota(Request $request): Response
    {
        [$cmd, $errore] = $this->bersaglio($request);
        if ($cmd === null) {
            return $errore;
        }
        $testo = trim($request->str('nota'));
        Database::run(
            'UPDATE commanders SET nota_pubblica = ?, profilo_gts = ? WHERE id = ?',
            [$testo === '' ? null : mb_substr($testo, 0, 500), World::now(), (int) $cmd['id']]
        );
        if (Auth::isAdmin() && (int) $cmd['user_id'] !== (int) Auth::id()) {
            Audit::log('admin.profilo_nota', (int) Auth::id(), 'commander', (int) $cmd['id'], [], $request->ip());
        }
        Session::flash('success', 'Fascicolo aggiornato.');
        return $this->indietro($cmd, $request);
    }

    // --- amministrazione -------------------------------------------------------

    /**
     * Lo stesso modulo, ma su un fascicolo altrui.
     *
     * Un amministratore puo' cambiare il ritratto e la nota di chiunque, e
     * togliere un'immagine caricata: serve quando qualcuno mette una fotografia
     * che non va bene. Ogni intervento finisce nel registro di controllo, col
     * nome di chi l'ha fatto: chi ha il potere lascia traccia come tutti.
     */
    public function adminModifica(Request $request, string $id): Response
    {
        $cmd = Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $id]);
        if ($cmd === null) {
            Session::flash('error', 'Comandante non trovato.');
            return redirect('/admin/utenti');
        }

        return Response::html(view('carriera/profilo_modifica', [
            'title'    => 'Fascicolo di ' . $cmd['nome'],
            'cmd'      => $cmd,
            'ritratto' => Ritratto::di($cmd),
            'catalogo' => Ritratto::elenco((int) $cmd['id']),
            'azione'   => '/admin/comandante/' . (int) $cmd['id'],
            'admin'    => true,
        ]));
    }

    /** Rinomina un comandante: solo l'amministratore, e il nome resta unico. */
    public function adminRinomina(Request $request): Response
    {
        [$cmd, $errore] = $this->bersaglio($request);
        if ($cmd === null) {
            return $errore;
        }
        $nuovo = trim($request->str('nome'));
        if (mb_strlen($nuovo) < 3) {
            Session::flash('error', 'Un nome di almeno tre lettere.');
            return $this->indietro($cmd, $request);
        }
        try {
            Database::run('UPDATE commanders SET nome = ? WHERE id = ?', [mb_substr($nuovo, 0, 64), (int) $cmd['id']]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Session::flash('error', 'C\'e\' gia\' un comandante con questo nome in servizio.');
                return $this->indietro($cmd, $request);
            }
            throw $e;
        }
        Audit::log('admin.comandante_rinominato', (int) Auth::id(), 'commander', (int) $cmd['id'],
            ['da' => $cmd['nome'], 'a' => $nuovo], $request->ip());
        Session::flash('success', 'Comandante rinominato.');
        return $this->indietro($cmd, $request);
    }

    // --- attrezzi --------------------------------------------------------------

    /**
     * Su quale fascicolo si sta agendo, e con quale diritto.
     *
     * Il proprio, sempre. Quello di un altro solo se si e' amministratori — e
     * il controllo si fa QUI, una volta, non sparso nelle azioni: una rotta a
     * cui ci si dimentica di aggiungerlo sarebbe una rotta aperta a tutti.
     *
     * @return array{0:?array<string,mixed>,1:?Response}
     */
    private function bersaglio(Request $request): array
    {
        $id = $request->int('comandante');
        if ($id > 0) {
            if (!Auth::isAdmin()) {
                return [null, Response::html(view('errors/generic', [
                    'title'   => 'Errore 403',
                    'status'  => 403,
                    'message' => 'Il fascicolo di un altro comandante lo modifica solo il comando.',
                ]), 403)];
            }
            $cmd = Database::first('SELECT * FROM commanders WHERE id = ?', [$id]);
            if ($cmd === null) {
                Session::flash('error', 'Comandante non trovato.');
                return [null, redirect('/admin/utenti')];
            }
            return [$cmd, null];
        }

        $cmd = Comandante::corrente((int) Auth::user()['id']);
        if ($cmd === null) {
            Session::flash('info', 'Prima si prende servizio.');
            return [null, redirect('/comandante')];
        }
        return [$cmd, null];
    }

    private function indietro(array $cmd, Request $request): Response
    {
        return redirect($request->int('comandante') > 0
            ? '/admin/comandante/' . (int) $cmd['id']
            : '/comandante/profilo');
    }
}
