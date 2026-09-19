<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/**
 * Il registro delle azioni, letto da chi deve risponderne.
 *
 * Audit::log() scrive da sempre tutto quello che l'amministrazione fa: chi ha
 * cambiato una chiave, chi ha cancellato un account, chi ha forzato il tempo,
 * chi ha tolto un messaggio dalla bacheca. Il 19/09/2026 si e' scoperto che di
 * quelle diciotto azioni l'interfaccia ne mostrava cinque: le due pagine che
 * dicevano "registro" filtravano su 'auth.%', ed erano un diario degli accessi.
 * Tutto il resto era scritto e invisibile — che e' il modo piu' silenzioso di
 * non avere un registro.
 *
 * Qui si leggono tutte, in italiano. Il dettaglio non si stampa com'e' scritto
 * — e' JSON, e il JSON in una tabella e' una parola sola larga quanto la riga —
 * ma si racconta; l'originale resta nel suggerimento del mouse per le volte che
 * serve davvero.
 */
final class Registro
{
    /** Le aree, per filtrare: il prefisso dell'azione, con un nome leggibile. */
    public const AREE = [
        'admin'        => 'Amministrazione',
        'auth'         => 'Accessi',
        'user'         => 'Account',
        'manutenzione' => 'Manutenzione',
    ];

    /** Che cosa e' successo, detto in italiano. */
    private const ETICHETTE = [
        'admin.bacheca_rimossa'       => 'Messaggio tolto dalla bacheca',
        'admin.comandante_rinominato' => 'Comandante rinominato',
        'admin.comunicazione'         => 'Comunicazione diramata',
        'admin.config'                => 'Manopola del motore girata',
        'admin.meteo_forzato'         => 'Tempo forzato',
        'admin.meteo_liberato'        => 'Forzatura del tempo tolta',
        'admin.mondo'                 => 'Leva sul mondo',
        'admin.nota'                  => 'Nota interna su un account',
        'admin.profilo_nota'          => 'Nota sul fascicolo di un comandante',
        'admin.ritratto_tolto'        => 'Ritratto rimosso',
        'admin.traffico_mix'          => 'Composizione del traffico cambiata',
        'admin.user'                  => 'Provvedimento su un account',
        'admin.user_cancellato'       => 'Account cancellato',
        'auth.login'                  => 'Accesso riuscito',
        'auth.login_failed'           => 'Accesso fallito',
        'auth.logout'                 => 'Uscita',
        'auth.register'               => 'Iscrizione',
        'auth.verify_email'           => 'Indirizzo confermato',
        'manutenzione.base_riallineata' => 'Battello riportato alla sua base',
        'user.rename'                 => 'Nome d\'accesso cambiato',
    ];

    /** Le azioni che meritano di saltare all'occhio. */
    private const GRAVI = [
        'admin.user_cancellato', 'admin.user', 'admin.meteo_forzato',
        'admin.config', 'admin.traffico_mix', 'auth.login_failed',
    ];

    public static function etichetta(string $azione): string
    {
        return self::ETICHETTE[$azione] ?? $azione;
    }

    public static function grave(string $azione): bool
    {
        return in_array($azione, self::GRAVI, true);
    }

    /**
     * Il dettaglio di una riga, raccontato invece che stampato.
     *
     * Non si inventa un vocabolario per ogni chiave possibile: si traducono i
     * nomi che ricorrono, si lascia leggibile il resto, e si taglia quello che
     * e' troppo lungo per una riga di tabella. L'originale sta nel
     * suggerimento.
     *
     * @return array{testo:string, grezzo:string}
     */
    public static function dettaglio(?string $meta): array
    {
        $grezzo = (string) $meta;
        $d = json_decode($grezzo, true);
        if (!is_array($d) || $d === []) {
            return ['testo' => '—', 'grezzo' => $grezzo];
        }

        $nomi = [
            'azione' => 'azione', 'chiave' => 'chiave', 'da' => 'da', 'a' => 'a',
            'username' => 'account', 'comandanti' => 'comandanti', 'battelli' => 'battelli',
            'missioni' => 'missioni', 'canale' => 'canale', 'autore' => 'autore',
            'testo' => 'testo', 'tolte' => 'tolte', 'motivo' => 'motivo',
            'da_porto' => 'dal porto', 'a_porto' => 'al porto',
            'da_flot' => 'dalla flottiglia', 'a_flot' => 'alla flottiglia',
            'lat' => 'lat', 'lon' => 'lon', 'raggio_nm' => 'raggio nm', 'ore' => 'ore',
            'mare' => 'mare', 'vento' => 'vento', 'nebbia' => 'nebbia', 'visibilita' => 'visibilità',
            'convogli' => 'convogli', 'isolate' => 'isolate', 'storici' => 'storici',
            'vecchio' => 'vecchio', 'nuovo' => 'nuovo', 'email' => 'indirizzo',
        ];

        $pezzi = [];
        foreach ($d as $k => $v) {
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            if (is_array($v)) {
                $v = implode(', ', array_map(
                    static fn ($vk, $vv): string => is_scalar($vv) ? "{$vk}: {$vv}" : (string) $vk,
                    array_keys($v),
                    array_values($v)
                ));
            }
            if ($v === true) {
                $v = 'sì';
            }
            $testo = mb_substr(trim((string) $v), 0, 90);
            $pezzi[] = ($nomi[(string) $k] ?? (string) $k) . ' ' . $testo;
        }

        return [
            'testo'  => $pezzi === [] ? '—' : implode(' · ', $pezzi),
            'grezzo' => $grezzo,
        ];
    }

    /**
     * Le righe del registro, filtrate come si chiede.
     *
     * @return array{righe:list<array<string,mixed>>, totale:int}
     */
    public static function righe(string $area = '', string $cerca = '', int $pagina = 1, int $perPagina = 60): array
    {
        $dove = [];
        $arg = [];

        if ($area !== '' && isset(self::AREE[$area])) {
            $dove[] = 'a.action LIKE ?';
            $arg[] = $area . '.%';
        }
        if ($cerca !== '') {
            $dove[] = '(a.action LIKE ? OR u.username LIKE ? OR a.meta LIKE ?)';
            $arg[] = '%' . $cerca . '%';
            $arg[] = '%' . $cerca . '%';
            $arg[] = '%' . $cerca . '%';
        }
        $clausola = $dove === [] ? '' : ' WHERE ' . implode(' AND ', $dove);

        $totale = (int) (Database::first(
            "SELECT COUNT(*) n FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id{$clausola}",
            $arg
        )['n'] ?? 0);

        $pagina = max(1, $pagina);
        $salto = ($pagina - 1) * $perPagina;

        $righe = Database::all(
            "SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
             {$clausola} ORDER BY a.id DESC LIMIT {$perPagina} OFFSET {$salto}",
            $arg
        );

        return ['righe' => $righe, 'totale' => $totale];
    }

    /** Quante righe per area: serve a sapere dove guardare. */
    public static function conteggi(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT SUBSTRING_INDEX(action, '.', 1) area, COUNT(*) n FROM audit_log GROUP BY area ORDER BY n DESC"
        ) as $r) {
            $out[(string) $r['area']] = (int) $r['n'];
        }
        return $out;
    }
}
