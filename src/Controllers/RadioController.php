<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Bdu;
use App\Game\Branco;
use App\Game\Comandante;
use App\Game\Fleet;
use App\Game\Radio;
use App\Game\Rifornimento;
use App\Game\Statistiche;
use App\Sim\BoatSim;
use App\Sim\Grid;
use App\Sim\World;

/**
 * Funkraum e comando: la radio, gli ordini del BdU, i branchi, il
 * rifornimento in mare, e la bacheca della mensa ufficiali.
 */
final class RadioController
{
    private function ctx(): array
    {
        $user = Auth::user();
        $boat = Fleet::ensureBoat((int) $user['id']);
        // Anche in base: li' non si naviga, ma il cantiere ripara.
        if (in_array((string) $boat['state'], ['mare', 'base'], true) && $boat['encounter_id'] === null) {
            BoatSim::advance((int) $boat['id']);
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $boat['id']]);
        }
        return [
            'user'  => $user,
            'boat'  => $boat,
            'cmd'   => Comandante::corrente((int) $user['id']),
            'type'  => World::type((string) $boat['type_key']),
            'clock' => World::clock(),
            'now'   => World::now(),
        ];
    }

    public function radio(Request $request): Response
    {
        $c = $this->ctx();
        $c['messaggi']   = Radio::inArrivo($c['boat'], 30);
        $c['fix']        = Radio::fixSubiti((int) $c['boat']['id'], 8);
        $c['kurz']       = Radio::KURZSIGNALE;
        $c['branco']     = Branco::corrente((int) $c['boat']['id']);
        $c['quadrat']    = Grid::toQuadrat((float) $c['boat']['est_lat'], (float) $c['boat']['est_lon']) ?? '—';
        $c['title']      = 'Funkraum';
        $c['illum']      = (string) $c['boat']['mode'] !== 'superficie' ? 'notte' : '';
        return Response::html(view('game/radio', $c));
    }

    public function trasmetti(Request $request): Response
    {
        $c = $this->ctx();
        $kurz = $request->str('kurz');
        $res = Radio::trasmetti(
            $c['boat'],
            $request->str('tipo', 'rapporto'),
            $request->str('testo'),
            $kurz !== '' ? $kurz : null
        );

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Trasmissione non riuscita.');
            return redirect('/radio');
        }

        $msg = sprintf('Trasmesso: %d secondi di antenna.', $res['durata']);
        if ($res['fix'] !== null) {
            $msg .= sprintf(' Attenzione: la trasmissione e\' stata intercettata (%d rilevamenti). %s',
                $res['fix']['rilevamenti'], $res['fix']['reazione']);
            Session::flash('warning', $msg);
        } else {
            Session::flash('success', $msg . ' Nessuna intercettazione rilevata — per quanto se ne sa.');
        }
        return redirect('/radio');
    }

    public function bdu(Request $request): Response
    {
        $c = $this->ctx();
        $c['ordini']   = Bdu::ordini((int) $c['boat']['id']);
        $c['branco']   = Branco::corrente((int) $c['boat']['id']);
        $c['branchi']  = Branco::aperti($c['now']);
        $c['membri']   = $c['branco'] !== null ? Branco::membri((int) $c['branco']['id']) : [];
        $c['rdv']      = Rifornimento::corrente((int) $c['boat']['id']);
        $c['title']    = 'Comando — BdU';
        $c['illum']    = '';
        return Response::html(view('game/bdu', $c));
    }

    public function rispondiOrdine(Request $request): Response
    {
        $c = $this->ctx();
        $res = Bdu::rispondi($c['boat'], $request->int('ordine'), $request->int('accetta') === 1, $c['now']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['accettato'] ? 'Ordine accettato.' : 'Ordine rifiutato: il BdU prende nota, e costa.')
            : ($res['error'] ?? 'Risposta non registrata.'));
        return redirect('/bdu');
    }

    public function entraBranco(Request $request): Response
    {
        $c = $this->ctx();
        $res = Branco::entra($c['boat'], $request->int('branco'), $c['now']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Inquadrato nel gruppo "' . $res['nome'] . '". Prendere posizione e segnalare i contatti.'
            : ($res['error'] ?? 'Non è stato possibile unirsi al gruppo.'));
        return redirect('/bdu');
    }

    public function esciBranco(Request $request): Response
    {
        $c = $this->ctx();
        $res = Branco::esci($c['boat'], $c['now']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Uscito dal gruppo "' . $res['nome'] . '": si caccia da soli.'
            : ($res['error'] ?? 'Operazione non riuscita.'));
        return redirect('/bdu');
    }

    public function rifornimento(Request $request): Response
    {
        $c = $this->ctx();
        $res = Rifornimento::richiedi($c['boat'], $c['now']);
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Richiesta non inoltrata.');
            return redirect('/bdu');
        }
        Session::flash('success', sprintf(
            'Appuntamento fissato in quadrato %s fra il %s e il %s. La richiesta e\' passata per radio: '
            . 'se qualcuno ascoltava, adesso lo sa anche lui.',
            (string) $res['rdv']['quadrat'],
            World::clock()->format((int) $res['rdv']['apertura_gts']),
            World::clock()->format((int) $res['rdv']['scadenza_gts'])
        ));
        return redirect('/bdu');
    }

    /** Statistiche di campagna: pubbliche. */
    public function statistiche(Request $request): Response
    {
        $gts = World::now();
        return Response::html(view('game/statistiche', [
            'title'      => 'Statistiche di campagna',
            'stat'       => Statistiche::campagna($gts),
            'classifica' => Statistiche::classifica(20),
            'convogli'   => Statistiche::convogliColpiti(10),
            'clock'      => World::clock(),
            'now'        => $gts,
        ]));
    }

    /** La firma con cui il comando affigge i suoi comunicati. */
    public const COMANDO = 'Comando';

    /**
     * Bacheca della mensa ufficiali: si scrive solo da terra, e si legge
     * quella della PROPRIA flottiglia.
     *
     * La mensa e' un posto fisico: la 7. U-Flottille mangia a Saint-Nazaire e
     * la 11. a Bergen, e quello che si dice a Bergen a Saint-Nazaire non si
     * sente. La flottiglia era gia' scritta su ogni messaggio ma non filtrava
     * niente: la pagina si chiamava "di flottiglia" e le mostrava tutte.
     *
     * L'eccezione sono i comunicati del comando, che arrivano dappertutto: il
     * BdU non parla a una mensa sola.
     */
    public function bacheca(Request $request): Response
    {
        $c = $this->ctx();
        if ($request->isPost()) {
            if ((string) $c['boat']['state'] !== 'base') {
                Session::flash('error', 'Alla bacheca si scrive in mensa, non in mare.');
                return redirect('/bacheca');
            }
            $testo = trim($request->str('testo'));
            if ($testo !== '') {
                Database::run(
                    'INSERT INTO bacheca (user_id, commander_id, flottiglia, testo) VALUES (?, ?, ?, ?)',
                    [
                        (int) $c['user']['id'],
                        $c['cmd'] !== null ? (int) $c['cmd']['id'] : null,
                        (string) $c['boat']['flotilla'],
                        mb_substr($testo, 0, 1000),
                    ]
                );
            }
            return redirect('/bacheca');
        }

        return Response::html(view('game/bacheca', array_merge($c, [
            'title' => 'Mensa ufficiali',
            'flottiglia' => (string) $c['boat']['flotilla'],
            'messaggi' => Database::all(
                'SELECT b.*, u.username, c.nome AS comandante FROM bacheca b
                 JOIN users u ON u.id = b.user_id LEFT JOIN commanders c ON c.id = b.commander_id
                 WHERE b.flottiglia = ? OR b.flottiglia = ?
                 ORDER BY b.id DESC LIMIT 60',
                [(string) $c['boat']['flotilla'], self::COMANDO]
            ),
        ])));
    }

    /**
     * Toglie un messaggio dalla bacheca.
     *
     * Due mani possono farlo: quella di chi l'ha scritto, che si rimangia una
     * cosa detta di getto, e quella dell'amministratore, che modera. La
     * seconda finisce nel registro, la prima no: rimangiarsi una propria frase
     * non e' un provvedimento.
     *
     * Non si cancella niente in silenzio quando non si ha il diritto: si dice.
     */
    public function bachecaRimuovi(Request $request): Response
    {
        $dove = $request->str('dove') === 'admin' ? '/admin/comunicazioni' : '/bacheca';
        $m = Database::first('SELECT * FROM bacheca WHERE id = ?', [$request->int('messaggio')]);

        if ($m === null) {
            Session::flash('error', 'Quel messaggio non c\'e\' piu\'.');
            return redirect($dove);
        }

        $mio   = (int) $m['user_id'] === (int) Auth::id();
        $admin = Auth::isAdmin();
        if (!$mio && !$admin) {
            Session::flash('error', 'Dalla bacheca si toglie quello che si e\' scritto, non quello degli altri.');
            return redirect($dove);
        }

        Database::run('DELETE FROM bacheca WHERE id = ?', [(int) $m['id']]);

        if ($admin && !$mio) {
            \App\Support\Audit::log('admin.bacheca_rimossa', (int) Auth::id(), 'bacheca', (int) $m['id'], [
                'autore' => (int) $m['user_id'],
                'testo'  => mb_substr((string) $m['testo'], 0, 120),
            ], $request->ip());
        }

        Session::flash('success', 'Messaggio tolto dalla bacheca.');
        return redirect($dove);
    }
}
