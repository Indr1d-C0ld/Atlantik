<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Posta;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Sim\World;
use App\Support\Audit;

/**
 * Pannello di amministrazione avanzato.
 *
 * Quattro cose che il pannello di base non faceva: guardare un account da
 * vicino, vedere da dove arrivano gli accessi, mandare una comunicazione, e
 * leggere una classifica seria.
 *
 * Tutte le rotte passano dal middleware 'admin'. Le azioni che cambiano
 * qualcosa finiscono nel registro di controllo: un amministratore che sospende
 * un account lascia traccia come chiunque altro.
 */
final class AdminController
{
    /** Quanti indirizzi mostrare nell'elenco delle origini. */
    private const ORIGINI = 40;

    // --- Utenti ---------------------------------------------------------------

    public function utenti(Request $request): Response
    {
        $cerca = trim($request->str('cerca'));
        $stato = $request->str('stato');

        $dove = [];
        $vals = [];
        if ($cerca !== '') {
            $dove[] = '(u.username LIKE ? OR u.email LIKE ?)';
            $vals[] = '%' . $cerca . '%';
            $vals[] = '%' . $cerca . '%';
        }
        if (in_array($stato, ['pending', 'active', 'suspended', 'banned'], true)) {
            $dove[] = 'u.status = ?';
            $vals[] = $stato;
        }
        $filtro = $dove === [] ? '' : ' WHERE ' . implode(' AND ', $dove);

        return Response::html(view('admin/utenti', [
            'title'  => 'Utenti',
            'cerca'  => $cerca,
            'stato'  => $stato,
            'utenti' => Database::all(
                'SELECT u.*,
                        (SELECT COUNT(*) FROM commanders c WHERE c.user_id = u.id) AS comandanti,
                        (SELECT COUNT(*) FROM boats b WHERE b.user_id = u.id) AS battelli,
                        (SELECT COUNT(*) FROM patrols p WHERE p.user_id = u.id) AS missioni,
                        (SELECT COALESCE(SUM(s.grt), 0) FROM sinkings s
                          JOIN boats b2 ON b2.id = s.boat_id WHERE b2.user_id = u.id) AS grt
                   FROM users u' . $filtro . ' ORDER BY u.id DESC LIMIT 200',
                $vals
            ),
            'conteggi' => Database::all('SELECT status, COUNT(*) n FROM users GROUP BY status'),
        ]));
    }

    /** La scheda di un account: tutto quello che il gioco sa di lui. */
    public function utente(Request $request, string $id): Response
    {
        $u = Database::first('SELECT * FROM users WHERE id = ?', [(int) $id]);
        if ($u === null) {
            Session::flash('error', 'Utente non trovato.');
            return redirect('/admin/utenti');
        }

        return Response::html(view('admin/utente', [
            'title'      => 'Account: ' . $u['username'],
            'u'          => $u,
            'comandanti' => Database::all(
                'SELECT * FROM commanders WHERE user_id = ? ORDER BY id DESC', [(int) $u['id']]
            ),
            'battelli'   => Database::all(
                'SELECT * FROM boats WHERE user_id = ? ORDER BY id DESC', [(int) $u['id']]
            ),
            'missioni'   => Database::all(
                'SELECT * FROM patrols WHERE user_id = ? ORDER BY id DESC LIMIT 20', [(int) $u['id']]
            ),
            'accessi'    => Database::all(
                "SELECT action, ip, created_at, meta FROM audit_log
                  WHERE actor_user_id = ? AND action LIKE 'auth.%'
                  ORDER BY id DESC LIMIT 30",
                [(int) $u['id']]
            ),
            'posta'      => Database::all(
                'SELECT oggetto, genere, inviato_at, rinunciato_at, tentativi FROM mail_queue
                  WHERE destinatario = ? ORDER BY id DESC LIMIT 10',
                [(string) $u['email']]
            ),
            'clock'      => World::clock(),
        ]));
    }

    /** Nota interna su un account: serve a ricordarsi perche' si e' fatto qualcosa. */
    public function nota(Request $request): Response
    {
        $id = $request->int('utente');
        $u = Database::first('SELECT id, username FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            Session::flash('error', 'Utente non trovato.');
            return redirect('/admin/utenti');
        }
        $nota = mb_substr(trim($request->str('nota')), 0, 2000);
        Database::run('UPDATE users SET note_admin = ? WHERE id = ?', [$nota === '' ? null : $nota, $id]);
        Audit::log('admin.nota', (int) Auth::id(), 'user', $id, [], $request->ip());
        Session::flash('success', 'Nota salvata.');
        return redirect('/admin/utente/' . $id);
    }

    // --- Accessi e origine ----------------------------------------------------

    /**
     * Da dove si entra.
     *
     * Gli indirizzi sono conservati in forma binaria (VARBINARY) e qui tornano
     * leggibili. Non c'e' geolocalizzazione e non ci sara': manderebbe l'indirizzo
     * di un giocatore a un servizio di terzi, che e' esattamente il genere di
     * cosa che questo progetto non fa.
     */
    public function accessi(Request $request): Response
    {
        $righe = Database::all(
            "SELECT a.id, a.action, a.ip, a.created_at, a.meta, a.actor_user_id, u.username
               FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
              WHERE a.action LIKE 'auth.%'
              ORDER BY a.id DESC LIMIT 200"
        );

        $perIp = Database::all(
            "SELECT a.ip,
                    COUNT(*) AS tentativi,
                    SUM(a.action = 'auth.login') AS riusciti,
                    SUM(a.action = 'auth.login_failed') AS falliti,
                    COUNT(DISTINCT a.actor_user_id) AS utenti,
                    MIN(a.created_at) AS primo,
                    MAX(a.created_at) AS ultimo
               FROM audit_log a
              WHERE a.action LIKE 'auth.%' AND a.ip IS NOT NULL
              GROUP BY a.ip ORDER BY tentativi DESC LIMIT " . self::ORIGINI
        );

        return Response::html(view('admin/accessi', [
            'title'   => 'Accessi e origine',
            'righe'   => $righe,
            'perIp'   => $perIp,
            'freni'   => Database::all(
                'SELECT rkey, hits, reset_at FROM rate_limits ORDER BY hits DESC LIMIT 20'
            ),
            'sospetti' => Database::all(
                "SELECT a.ip, COUNT(*) n FROM audit_log a
                  WHERE a.action = 'auth.login_failed' AND a.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  GROUP BY a.ip HAVING n >= 5 ORDER BY n DESC LIMIT 10"
            ),
        ]));
    }

    // --- Comunicazioni --------------------------------------------------------

    public function comunicazioni(Request $request): Response
    {
        return Response::html(view('admin/comunicazioni', [
            'title'    => 'Comunicazioni',
            'utenti'   => Database::all(
                "SELECT id, username, email, status FROM users WHERE status = 'active' ORDER BY username"
            ),
            'coda'     => Posta::stato(),
            'recenti'  => Database::all(
                "SELECT id, destinatario, oggetto, genere, inviato_at, rinunciato_at
                   FROM mail_queue ORDER BY id DESC LIMIT 15"
            ),
            // Quaranta e non dieci: da qui si modera, e moderare dieci righe
            // vuol dire non poter togliere la undicesima.
            'bacheca'  => Database::all(
                'SELECT b.*, c.nome FROM bacheca b LEFT JOIN commanders c ON c.id = b.commander_id
                  ORDER BY b.id DESC LIMIT 40'
            ),
        ]));
    }

    /**
     * Manda una comunicazione.
     *
     * Due strade, e sono diverse sul serio:
     *   posta     esce dal gioco e arriva in una casella vera. Passa dalla coda,
     *             quindi rispetta il tetto giornaliero del provider e ritenta da
     *             sola se il relay non risponde.
     *   bacheca   resta dentro il gioco, nella mensa ufficiali della flottiglia.
     *             Non costa niente e non disturba nessuno.
     */
    public function invia(Request $request): Response
    {
        $canale  = $request->str('canale');
        $a       = $request->str('destinatario');
        $oggetto = trim($request->str('oggetto'));
        $testo   = trim($request->str('testo'));

        if ($testo === '') {
            Session::flash('error', 'Il testo e\' vuoto.');
            return redirect('/admin/comunicazioni');
        }

        if ($canale === 'bacheca') {
            Database::run(
                'INSERT INTO bacheca (user_id, commander_id, flottiglia, testo) VALUES (?, NULL, ?, ?)',
                [(int) Auth::id(), 'Comando', mb_substr($testo, 0, 1000)]
            );
            Audit::log('admin.comunicazione', (int) Auth::id(), 'bacheca', null, ['canale' => 'bacheca'], $request->ip());
            Session::flash('success', 'Comunicato affisso in mensa ufficiali.');
            return redirect('/admin/comunicazioni');
        }

        if ($oggetto === '') {
            Session::flash('error', 'Una e-mail senza oggetto non la apre nessuno.');
            return redirect('/admin/comunicazioni');
        }

        $destinatari = [];
        if ($a === 'tutti') {
            foreach (Database::all("SELECT email FROM users WHERE status = 'active'") as $r) {
                $destinatari[] = (string) $r['email'];
            }
        } else {
            $u = Database::first('SELECT email FROM users WHERE id = ?', [(int) $a]);
            if ($u === null) {
                Session::flash('error', 'Destinatario non trovato.');
                return redirect('/admin/comunicazioni');
            }
            $destinatari[] = (string) $u['email'];
        }

        // Si accoda soltanto: il battito le manda qualche per volta, rispettando
        // il tetto. Un invio in massa non deve poter bruciare la quota in un colpo.
        foreach ($destinatari as $indirizzo) {
            Posta::accoda($indirizzo, $oggetto, $testo, 'comunicazione', 6);
        }
        Audit::log('admin.comunicazione', (int) Auth::id(), 'mail', null,
            ['canale' => 'posta', 'destinatari' => count($destinatari)], $request->ip());

        Session::flash('success', sprintf(
            '%d messagg%s in coda. Partono col battito, qualcuno per volta.',
            count($destinatari), count($destinatari) === 1 ? 'io' : 'i'
        ));
        return redirect('/admin/comunicazioni');
    }

    // --- Classifica -----------------------------------------------------------

    /**
     * La classifica, per quanti modi ci sono di essere bravi.
     *
     * Il tonnellaggio da solo premia chi resta fuori piu' a lungo. Qui si guarda
     * anche il rendimento — tonnellate per siluro, per missione, per giorno di
     * mare — e chi e' tornato a casa, che nel 1942 era la statistica che contava
     * davvero: tre equipaggi su quattro non lo fecero.
     */
    public function classifica(Request $request): Response
    {
        $base = 'FROM commanders c
                 LEFT JOIN users u ON u.id = c.user_id
                 LEFT JOIN boats b ON b.commander_id = c.id';

        return Response::html(view('admin/classifica', [
            'title'   => 'Classifica',
            'tonnellaggio' => Database::all(
                'SELECT c.*, u.username, b.uboat_number, b.state AS stato_battello,
                        (SELECT COUNT(*) FROM patrols p WHERE p.commander_id = c.id AND p.state <> \'in_corso\') AS missioni,
                        (SELECT COALESCE(SUM(p.siluri_lanciati),0) FROM patrols p WHERE p.commander_id = c.id) AS siluri,
                        (SELECT COALESCE(SUM(p.distance_nm),0) FROM patrols p WHERE p.commander_id = c.id) AS miglia
                 ' . $base . '
                 ORDER BY c.grt_affondato DESC, c.affondate DESC LIMIT 50'
            ),
            'decorati' => Database::all(
                'SELECT c.id, c.nome, u.username, COUNT(a.id) AS decorazioni,
                        MAX(a.gts) AS ultima
                   FROM commanders c
                   LEFT JOIN users u ON u.id = c.user_id
                   JOIN awards a ON a.commander_id = c.id
                  GROUP BY c.id ORDER BY decorazioni DESC, c.prestigio_tot DESC LIMIT 20'
            ),
            'convogli' => Database::all(
                "SELECT s.convoglio, COUNT(*) navi, COALESCE(SUM(s.grt),0) grt
                   FROM sinkings s WHERE s.convoglio IS NOT NULL
                  GROUP BY s.convoglio ORDER BY grt DESC LIMIT 15"
            ),
            'bersagli' => Database::all(
                "SELECT s.class_key, cl.name, COUNT(*) navi, COALESCE(SUM(s.grt),0) grt
                   FROM sinkings s LEFT JOIN ship_classes cl ON cl.class_key = s.class_key
                  GROUP BY s.class_key ORDER BY navi DESC LIMIT 15"
            ),
            // Chi non e' tornato. Nel 1942 era la statistica che contava di piu'.
            'perduti' => Database::all(
                "SELECT c.nome, u.username, c.grt_affondato, c.affondate, c.uscito_gts, c.sorte,
                        c.ultimo_quadrat, b.uboat_number
                   FROM commanders c LEFT JOIN users u ON u.id = c.user_id
                   LEFT JOIN boats b ON b.commander_id = c.id
                  WHERE c.stato <> 'attivo' ORDER BY c.grt_affondato DESC LIMIT 20"
            ),
            'campagna' => \App\Game\Statistiche::campagna(World::now()),
            'clock'    => World::clock(),
        ]));
    }
}
