<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\AuthMail;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController
{
    // --- Arruolamento --------------------------------------------------------

    public function showRegister(Request $request): Response
    {
        return Response::html(view('auth/register', [
            'title'       => 'Arruolamento',
            'open'        => Auth::registrationOpen(),
            'minPassword' => Auth::minPasswordLength(),
        ]));
    }

    public function register(Request $request): Response
    {
        // Freno all'iscrizione: 5 tentativi per indirizzo IP ogni 30 minuti.
        if (!RateLimiter::hit('reg:' . $request->ip(), 5, 1800)) {
            Session::flash('error', "Troppi tentativi di iscrizione da questa connessione. Riprova fra mezz'ora.");
            return redirect('/arruolamento');
        }

        $username = $request->str('username');
        $email    = $request->str('email');
        $password = $request->str('password');
        $confirm  = $request->str('password_confirm');

        if ($password !== $confirm) {
            Session::flashInput($request->all());
            Session::flash('error', 'Le due password non coincidono.');
            return redirect('/arruolamento');
        }

        $res = Auth::register($username, $email, $password, $request->ip());
        if (!$res['ok']) {
            Session::flashInput($request->all());
            Session::flash('error', $res['error'] ?? 'Iscrizione non riuscita.');
            return redirect('/arruolamento');
        }

        $sent = AuthMail::sendVerification((int) $res['user_id'], mb_strtolower(trim($email)), trim($username), (string) $res['token']);
        AuthMail::notifyAdmin((int) $res['user_id'], trim($username), mb_strtolower(trim($email)));

        Session::flash('email', mb_strtolower(trim($email)));
        if (!$sent['ok']) {
            // Non e' piu' un fallimento: il messaggio e' in coda e riparte da
            // solo. Si avverte solo perche' non arrivera' nello stesso minuto.
            Session::flash('warning', 'Account creato. Il messaggio di conferma e\' in coda di spedizione: '
                . 'se non arriva entro qualche minuto, usa il pulsante qui sotto per richiederne un altro.');
        }
        return redirect('/verifica-inviata');
    }

    /**
     * Il modulo per chiedere il collegamento di recupero.
     */
    public function recuperoForm(Request $request): Response
    {
        if (Auth::check()) {
            return redirect('/base');
        }
        return Response::html(view('auth/recupero_richiesta', ['title' => 'Password dimenticata']));
    }

    /**
     * Manda il collegamento — o fa finta, se l'indirizzo non risulta.
     *
     * La risposta e' sempre la stessa: dire "questo indirizzo non c'e'"
     * regalerebbe a chiunque un modo per sapere chi e' iscritto.
     */
    public function recuperoInvia(Request $request): Response
    {
        $res = Auth::richiediRecupero($request->str('email'), $request->ip());
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Richiesta non valida.');
            Session::flashInput(['email' => $request->str('email')]);
            return redirect('/recupero-richiesta');
        }

        Session::flash('success', 'Se quell\'indirizzo risulta iscritto, il collegamento è partito. '
            . 'Controlla la posta, anche fra lo spam.');
        return redirect('/accesso');
    }

    /**
     * La pagina dove si scrive la password nuova, aperta dal collegamento.
     */
    public function recuperoForm2(Request $request): Response
    {
        $token = $request->str('token');
        $stato = Auth::recuperoValido($token);

        return Response::html(view('auth/recupero', [
            'title'  => 'Password nuova',
            'token'  => $token,
            'valido' => (bool) $stato['ok'],
            'errore' => $stato['error'] ?? null,
        ]));
    }

    /** Scrive la password nuova e fa cadere le sessioni aperte. */
    public function recuperoSalva(Request $request): Response
    {
        $token = $request->str('token');
        $pw    = (string) $request->input('password', '');
        $pw2   = (string) $request->input('password_confirm', '');

        if ($pw !== $pw2) {
            Session::flash('error', 'Le due password non coincidono.');
            return redirect('/recupero?token=' . urlencode($token));
        }

        $res = Auth::rifaiPassword($token, $pw, $request->ip());
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non è stato possibile cambiare la password.');
            return redirect('/recupero?token=' . urlencode($token));
        }

        Session::flash('success', 'Password cambiata. Le sessioni aperte sono state chiuse: entra con quella nuova.');
        return redirect('/accesso');
    }

    public function verificationSent(Request $request): Response
    {
        return Response::html(view('auth/verify_sent', [
            'title' => 'Conferma in arrivo',
            'email' => (string) flash('email', ''),
        ]));
    }

    /** Apertura del collegamento ricevuto per posta. */
    public function verify(Request $request): Response
    {
        $res = Auth::verifyEmail($request->str('token'), $request->ip());

        return Response::html(view('auth/verify_result', [
            'title' => $res['ok'] ? 'Indirizzo confermato' : 'Verifica non riuscita',
            'ok'    => $res['ok'],
            'error' => $res['error'] ?? null,
            'user'  => $res['user'] ?? null,
        ]));
    }

    /** Nuovo invio del collegamento di verifica. */
    public function resend(Request $request): Response
    {
        $login = $request->str('login');

        if (!RateLimiter::hit('resend:' . $request->ip(), 5, 1800)) {
            Session::flash('error', "Troppe richieste di rinvio. Riprova fra mezz'ora.");
            return redirect('/accesso');
        }

        $row = Database::first(
            "SELECT id, username, email, status, verify_sent_at FROM users
             WHERE (username = ? OR email = ?) AND status = 'pending'",
            [$login, mb_strtolower($login)]
        );

        // Risposta identica in ogni caso: non si rivela chi e' iscritto e chi no.
        if ($row !== null) {
            $lastSent = $row['verify_sent_at'] !== null ? strtotime((string) $row['verify_sent_at']) : 0;
            if (time() - $lastSent >= 120) {
                $token = Auth::issueToken((int) $row['id'], 'verify_email', $request->ip());
                AuthMail::sendVerification((int) $row['id'], (string) $row['email'], (string) $row['username'], $token);
            }
        }

        Session::flash('success', 'Se l\'account esiste ed e\' in attesa di conferma, il collegamento è stato inviato di nuovo.');
        return redirect('/accesso');
    }

    // --- Accesso -------------------------------------------------------------

    public function showLogin(Request $request): Response
    {
        return Response::html(view('auth/login', ['title' => 'Accesso']));
    }

    public function login(Request $request): Response
    {
        $login = $request->str('login');

        // Due freni: uno per IP (chi prova molti account) e uno per nome utente
        // (chi martella un account solo da piu' indirizzi).
        if (!RateLimiter::hit('login:ip:' . $request->ip(), 20, 900)
            || !RateLimiter::hit('login:user:' . mb_strtolower($login), 10, 900)) {
            Session::flash('error', 'Troppi tentativi di accesso. Attendi qualche minuto.');
            return redirect('/accesso');
        }

        $res = Auth::attempt($login, $request->str('password'), $request->ip());

        if (!$res['ok']) {
            Session::flashInput(['login' => $login]);
            Session::flash('error', $res['error'] ?? 'Accesso non riuscito.');
            if (!empty($res['need_verify'])) {
                Session::flash('need_verify', $login);
            }
            return redirect('/accesso');
        }

        RateLimiter::clear('login:user:' . mb_strtolower($login));
        return redirect('/base');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return redirect('/');
    }
}
