<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Carriera;
use App\Game\Comandante;
use App\Game\Fleet;
use App\Sim\World;

/**
 * Il fascicolo personale: creazione del comandante, gradi, decorazioni, e
 * cosa farsene del prestigio guadagnato.
 */
final class CarrieraController
{
    /** Il fascicolo, o il modulo per aprirne uno. */
    public function comandante(Request $request): Response
    {
        $user = Auth::user();
        $cmd  = Comandante::corrente((int) $user['id']);

        if ($cmd === null) {
            return Response::html(view('carriera/creazione', [
                'title'    => 'Creazione del comandante',
                'basi'     => World::bases(),
                'eredita'  => Comandante::eredita((int) $user['id']),
                'ritratti' => \App\Game\Ritratto::elenco(),
                'fascicoli'=> Comandante::fascicoli((int) $user['id']),
                'clock'    => World::clock(),
            ]));
        }

        $boat = Fleet::ensureBoat((int) $user['id']);
        $prossimo = Carriera::prossimoLivello((int) $cmd['prestigio_tot']);

        return Response::html(view('carriera/fascicolo', [
            'title'        => 'Fascicolo — ' . $cmd['nome'],
            'cmd'          => $cmd,
            'boat'         => $boat,
            'grado'        => Carriera::gradoNome((int) $cmd['grado']),
            'prossimo'     => $prossimo,
            'decorazioni'  => Database::all(
                'SELECT a.*, t.nome, t.nome_it, t.note FROM awards a JOIN award_types t ON t.akey = a.akey
                 WHERE a.commander_id = ? ORDER BY t.ordine DESC', [(int) $cmd['id']]),
            'catalogo'     => Database::all('SELECT * FROM award_types ORDER BY ordine'),
            'miglioramenti'=> Carriera::miglioramenti((int) $boat['id'], (int) $cmd['grado']),
            'patrols'      => Database::all(
                'SELECT * FROM patrols WHERE commander_id = ? ORDER BY id DESC LIMIT 12', [(int) $cmd['id']]),
            'affondamenti' => Database::all(
                'SELECT * FROM sinkings WHERE commander_id = ? ORDER BY gts DESC LIMIT 15', [(int) $cmd['id']]),
            'clock'        => World::clock(),
        ]));
    }

    public function crea(Request $request): Response
    {
        $user = Auth::user();
        $res = Comandante::crea((int) $user['id'], [
            'nome'     => $request->str('nome'),
            'nato_il'  => $request->str('nato_il'),
            'nato_a'   => $request->str('nato_a'),
            'ritratto' => $request->str('ritratto', 'r1'),
            'base'     => $request->str('base', 'lorient'),
            'ritratto_key'  => $request->str('ritratto_key'),
            'nome_storico'  => $request->str('nome_storico') === '1',
            'file'          => $_FILES['ritratto'] ?? null,
            // Se l'ha gia' fatto il browser, il server non lo rifa'.
            'invecchia'     => $request->str('invecchia') === '1'
                               && $request->str('gia_invecchiata') !== '1',
        ]);

        if (!$res['ok']) {
            Session::flashInput($request->all());
            Session::flash('error', $res['error'] ?? 'Creazione non riuscita.');
            return redirect('/comandante');
        }

        if (($res['avviso'] ?? null) !== null) {
            Session::flash('error', $res['avviso']);
        } else {
            Session::flash('success', 'Assegnazione accettata. Presentati in flottiglia.');
        }
        return redirect('/base');
    }

    public function compra(Request $request): Response
    {
        $user = Auth::user();
        $cmd  = Comandante::corrente((int) $user['id']);
        if ($cmd === null) {
            return redirect('/comandante');
        }
        $boat = Fleet::ensureBoat((int) $user['id']);

        $res = Carriera::compra($cmd, $boat, $request->str('ukey'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Il cantiere installa: ' . $res['nome'] . '.'
            : ($res['error'] ?? 'Richiesta respinta.'));
        return redirect('/comandante');
    }

    public function addestra(Request $request): Response
    {
        $user = Auth::user();
        $cmd  = Comandante::corrente((int) $user['id']);
        if ($cmd === null) {
            return redirect('/comandante');
        }
        $boat = Fleet::ensureBoat((int) $user['id']);

        $res = Carriera::addestra($cmd, $boat, $request->str('specialita'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Corso concluso: ' . plurale((int) $res['uomini'], 'un uomo piu\' preparato.', '%d uomini piu\' preparati.')
            : ($res['error'] ?? 'Corso non possibile.'));
        return redirect('/comandante');
    }

    /**
     * Congedo volontario: si chiude la carriera da vivi.
     *
     * Il mestiere aveva due uscite, e il gioco ne offriva una sola. Chi
     * sopravviveva abbastanza veniva tolto dal mare e mandato a insegnare, a
     * comandare una flottiglia, a lavorare al BdU: Kretschmer, Lehmann-
     * Willenbrock, Topp, Luth, Schepke — di quelli che sono diventati un nome,
     * piu' di uno e' finito cosi'. Fino all'audit del 19/09/2026 in Atlantik
     * si poteva solo morire: Comandante::congeda() era scritta e non la
     * chiamava nessuno, e lo stato 'congedato' non poteva esistere — mentre
     * l'albo d'oro prometteva in testa "i comandanti che non sono tornati, e
     * quelli che si sono congedati".
     *
     * Si congeda dalla base, non dal mare: si lascia il comando in banchina.
     * E si scrive il nome per esteso, perche' e' definitivo come morire — il
     * fascicolo si chiude, il prestigio resta all'albo, e il comandante dopo
     * eredita la sua quota come se fosse caduto.
     */
    public function congeda(Request $request): Response
    {
        $user = Auth::user();
        $cmd  = Comandante::corrente((int) $user['id']);
        if ($cmd === null) {
            return redirect('/comandante');
        }

        $boat = Fleet::ensureBoat((int) $user['id']);
        if ((string) $boat['state'] !== 'base') {
            Session::flash('error', 'Il comando si lascia in banchina, non in mezzo all\'Atlantico: '
                . 'prima si rientra alla base.');
            return redirect('/comandante');
        }

        if (trim($request->str('conferma')) !== (string) $cmd['nome']) {
            Session::flash('error', 'Per chiudere una carriera bisogna scriverne il nome esatto: '
                . 'e\' una decisione che non si disfa.');
            return redirect('/comandante');
        }

        Comandante::congeda($cmd, World::now());
        \App\Support\Audit::log('carriera.congedo', (int) $user['id'], 'commander', (int) $cmd['id'], [
            'nome' => (string) $cmd['nome'],
            'grt'  => (int) $cmd['grt_affondato'],
        ], $request->ip());

        Session::flash('success', sprintf(
            '%s lascia il servizio attivo con %s GRT affondati. Il fascicolo si chiude e resta nell\'albo.',
            (string) $cmd['nome'], number_format((int) $cmd['grt_affondato'], 0, ',', '.')
        ));
        return redirect('/comandante');
    }

    /** L'albo d'oro: consultabile da chiunque, anche senza account. */
    public function albo(Request $request): Response
    {
        return Response::html(view('carriera/albo', [
            'title'      => 'Albo d\'oro',
            'caduti'     => Comandante::albo(60),
            'in_servizio'=> Comandante::classifica(20),
            'clock'      => World::clock(),
            'totali'     => Database::first(
                "SELECT COUNT(*) n, COALESCE(SUM(grt_affondato),0) grt FROM commanders WHERE stato <> 'attivo'"
            ),
        ]));
    }

    /** Il rapporto di missione consegnato al BdU. */
    public function rapporto(Request $request, string $id): Response
    {
        $user = Auth::user();
        $patrol = Database::first(
            'SELECT * FROM patrols WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );
        if ($patrol === null) {
            Session::flash('error', 'Rapporto non trovato.');
            return redirect('/base');
        }

        return Response::html(view('carriera/rapporto', [
            'title'  => 'Rapporto di missione n. ' . $patrol['number'],
            'patrol' => $patrol,
            'affondamenti' => Database::all('SELECT * FROM sinkings WHERE patrol_id = ? ORDER BY gts', [(int) $id]),
            'clock'  => World::clock(),
        ]));
    }
}
