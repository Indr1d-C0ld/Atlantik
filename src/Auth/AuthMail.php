<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Posta;

/**
 * Le e-mail dell'autenticazione. Testo semplice (il Mailer non fa HTML),
 * nel tono del dispaccio: e' la prima cosa che il giocatore legge di Atlantik.
 */
final class AuthMail
{
    /** URL assoluto pubblico, necessario nei collegamenti delle e-mail. */
    private static function publicUrl(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.public_url', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    /** @return array{ok:bool, error?:string} */
    public static function sendVerification(int $userId, string $email, string $username, string $token): array
    {
        $link = self::publicUrl('/verifica?token=' . $token);
        $ttl  = (int) \App\Core\GameConfig::int('auth.verify_ttl_hours', 48);

        $subject = 'Atlantik — conferma del tuo arruolamento';
        $body = <<<TXT
        BEFEHLSHABER DER U-BOOTE
        Ufficio arruolamenti — Atlantik

        {$username},

        la tua domanda e' stata registrata. Per prendere servizio conferma
        l'indirizzo di posta aprendo questo collegamento:

        {$link}

        Il collegamento resta valido {$ttl} ore. Se scade, puoi chiederne un
        altro dalla pagina di accesso.

        Se non hai richiesto nulla, ignora questo messaggio: senza conferma
        l'account non viene attivato.

        --
        Atlantik — simulazione della Battaglia dell'Atlantico
        Messaggio automatico: non rispondere a questo indirizzo.
        TXT;

        // Priorita' 1: e' l'unica porta d'ingresso al gioco. Se l'SMTP non
        // risponde adesso il messaggio resta in coda e riparte da solo, invece
        // di sparire come faceva prima (audit A6).
        $res = Posta::invia($email, $subject, $body, 'verifica', 1);

        // Il contatore segna il momento in cui il messaggio e' stato PRESO IN
        // CARICO: e' quello che regola il freno sui rinvii.
        Database::run(
            'UPDATE users SET verify_sent_at = NOW(), verify_count = verify_count + 1 WHERE id = ?',
            [$userId]
        );
        if (!$res['ok']) {
            logger('verifica per user ' . $userId . ' messa in coda: ' . ($res['error'] ?? '?'), 'warning');
        }
        return $res;
    }

    /**
     * Avviso all'amministratore di una nuova iscrizione. Non deve mai bloccare
     * la registrazione: se fallisce, resta solo una riga di log.
     */
    public static function notifyAdmin(int $userId, string $username, string $email): void
    {
        if (!Config::get('notify.new_registration', true)) {
            return;
        }
        $to = (string) Config::get('notify.admin_email', '');
        if ($to === '') {
            return;
        }

        $body = <<<TXT
        Nuova iscrizione ad Atlantik.

          utente : {$username}
          e-mail : {$email}
          id     : {$userId}
          quando : %s

        L'account resta 'pending' finche' l'interessato non conferma
        l'indirizzo dal collegamento ricevuto.

        Console: php bin/console.php user:list
        TXT;

        // Priorita' bassa: l'amministratore puo' aspettare, il giocatore no.
        Posta::invia($to, "Atlantik — nuova iscrizione: {$username}", sprintf($body, fmt_dt(time())), 'avviso_admin', 7);
        Database::run('UPDATE users SET admin_notified_at = NOW() WHERE id = ?', [$userId]);
    }

}
