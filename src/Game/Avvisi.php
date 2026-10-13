<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Posta;
use App\Sim\World;

/**
 * Avvisi per posta: quello che succede in mare mentre il comandante non c'e'.
 *
 * La fase tattica si gioca in una finestra di tempo reale, e gli avvisi del
 * browser (bordo.js) suonano solo a pagina aperta: un convoglio che passava
 * mentre il comandante era altrove passava e basta, perche' nessuno da' battaglia
 * al suo posto se non si comincia. Chi lo chiede, dal fascicolo, riceve un
 * messaggio quando:
 *
 *   - c'e' un contatto (la vedetta, l'idrofono, una segnalazione del branco);
 *   - c'e' un pericolo (un aereo, una scorta che ci ha visti, lo scafo);
 *   - il BdU ordina qualcosa.
 *
 * Le righe del giornale lasciano un avviso (nota()); il battito le raccoglie
 * (smista()) e decide: preferenze spente, comandante gia' alla console, troppo
 * presto dopo l'ultimo messaggio della stessa categoria, tetto giornaliero.
 * Quelle trattenute dall'intervallo restano in attesa e partono insieme al
 * messaggio successivo; quelle troppo vecchie per servire si lasciano cadere.
 * Ogni avviso non partito dice perche'.
 */
final class Avvisi
{
    public const CATEGORIE = [
        'contatti' => ['nome' => 'Contatti', 'spiega' => 'una nave o un convoglio in vista, all\'idrofono o segnalato dal branco', 'pagina' => '/contatti'],
        'pericoli' => ['nome' => 'Pericoli', 'spiega' => 'un aereo, una scorta che ci ha visti, lo scafo che cede', 'pagina' => '/zentrale'],
        'bdu'      => ['nome' => 'Ordini del BdU', 'spiega' => 'un ordine o un messaggio del comando', 'pagina' => '/bdu'],
    ];

    /** Righe del giornale che sono un pericolo, per tipo (e gravita' minima). */
    private const PERICOLI = [
        'aereo'        => 'allarme',
        'avvistati'    => 'allarme',
        'scafo'        => 'allarme',
        'perdita'      => 'allarme',
        'combattimento' => 'allarme',
        'radar_warner' => 'attenzione',
    ];

    /**
     * La categoria di una riga del giornale, o null se non merita un avviso.
     *
     * I contatti si segnano esplicitamente ('avviso' => 'contatti'), perche'
     * la stessa riga «contatto» dice anche che un contatto si e' perso o si e'
     * rinforzato: avvisa solo quella che lo apre.
     *
     * @param array<string,mixed> $e
     */
    public static function categoria(array $e): ?string
    {
        if (isset($e['avviso']) && isset(self::CATEGORIE[(string) $e['avviso']])) {
            return (string) $e['avviso'];
        }
        $kind = (string) ($e['kind'] ?? '');
        $grav = (string) ($e['severity'] ?? '');
        if (isset(self::PERICOLI[$kind])) {
            $ordine = ['info' => 0, 'nota' => 1, 'attenzione' => 2, 'allarme' => 3];
            return ($ordine[$grav] ?? 0) >= $ordine[self::PERICOLI[$kind]] ? 'pericoli' : null;
        }
        return in_array($kind, ['ordine_bdu', 'bdu'], true) ? 'bdu' : null;
    }

    /**
     * Una riga del giornale appena scritta: se merita un avviso, lo si mette
     * da parte per il battito. Costa una lettura solo per le righe che contano.
     *
     * @param array<string,mixed> $e
     */
    public static function nota(array $e, int $boatId, ?int $eventId): void
    {
        $cat = self::categoria($e);
        if ($cat === null) {
            return;
        }
        $b = Database::first('SELECT b.user_id, u.role FROM boats b JOIN users u ON u.id = b.user_id WHERE b.id = ?', [$boatId]);
        if ($b === null || (string) $b['role'] === 'motore') {
            return;                         // un collega del motore non legge la posta
        }
        Database::run(
            'INSERT INTO avvisi (user_id, boat_id, event_id, categoria, testo, quadrat, gts) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $b['user_id'], $boatId, $eventId, $cat, mb_substr((string) $e['text'], 0, 500),
             $e['quadrat'] ?? null, (int) $e['gts']]
        );
    }

    /** La preferenza di una categoria: 'avvisi.contatti' e cosi' via. */
    public static function chiave(string $categoria): string
    {
        return 'avvisi.' . $categoria;
    }

    /**
     * Il lavoro del battito.
     *
     * @return array{inviati:int, trattenuti:int, saltati:int}
     */
    public static function smista(): array
    {
        $esito = ['inviati' => 0, 'trattenuti' => 0, 'saltati' => 0];

        $esito['saltati'] += Database::run(
            "UPDATE avvisi SET saltato = 'scaduto'
              WHERE mail_id IS NULL AND saltato IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [max(1, GameConfig::int('avvisi.scadenza_min', 45))]
        )->rowCount();

        $aperti = Database::all(
            'SELECT * FROM avvisi WHERE mail_id IS NULL AND saltato IS NULL ORDER BY user_id, categoria, id LIMIT 500'
        );
        $gruppi = [];
        foreach ($aperti as $a) {
            $gruppi[(int) $a['user_id'] . '|' . (string) $a['categoria']][] = $a;
        }

        $presenza   = max(0, GameConfig::int('avvisi.presenza_s', 180));
        $intervallo = max(0, GameConfig::int('avvisi.intervallo_min', 30));
        $tetto      = max(0, GameConfig::int('avvisi.max_24h', 12));

        foreach ($gruppi as $chiave => $righe) {
            [$userId, $cat] = explode('|', $chiave);
            $userId = (int) $userId;
            $ids = array_map(static fn (array $a): int => (int) $a['id'], $righe);

            $u = Database::first(
                'SELECT id, email, status, email_verified_at,
                        last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) AS presente
                   FROM users WHERE id = ?',
                [$presenza, $userId]
            );
            $motivo = match (true) {
                $u === null || (string) $u['status'] !== 'active' || $u['email_verified_at'] === null
                    || !filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL) => 'senza indirizzo',
                Preferenze::get($userId, self::chiave($cat)) !== 'si' => 'spento',
                (int) $u['presente'] === 1                            => 'presente',
                default                                              => null,
            };
            if ($motivo === null && $tetto > 0) {
                $mandati = Database::first(
                    'SELECT COUNT(DISTINCT mail_id) n FROM avvisi
                      WHERE user_id = ? AND mail_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                    [$userId]
                );
                if ((int) ($mandati['n'] ?? 0) >= $tetto) {
                    $motivo = 'tetto';
                }
            }
            if ($motivo !== null) {
                self::segna($ids, null, $motivo);
                $esito['saltati'] += count($ids);
                continue;
            }

            // Troppo presto dall'ultimo messaggio della stessa categoria: si
            // aspetta, e partiranno insieme al prossimo.
            $ultimo = Database::first(
                'SELECT MAX(m.created_at) t FROM avvisi a JOIN mail_queue m ON m.id = a.mail_id
                  WHERE a.user_id = ? AND a.categoria = ? AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                [$userId, $cat, $intervallo]
            );
            if ($intervallo > 0 && $ultimo !== null && $ultimo['t'] !== null) {
                $esito['trattenuti'] += count($ids);
                continue;
            }

            [$oggetto, $corpo] = self::messaggio($cat, $righe);
            $mailId = Posta::accoda((string) $u['email'], $oggetto, $corpo, 'avviso', 6);
            self::segna($ids, $mailId, null);
            $esito['inviati']++;
        }
        return $esito;
    }

    /** @param list<int> $ids */
    private static function segna(array $ids, ?int $mailId, ?string $saltato): void
    {
        if ($ids === []) {
            return;
        }
        $segnaposto = implode(',', array_fill(0, count($ids), '?'));
        Database::run(
            "UPDATE avvisi SET mail_id = ?, saltato = ? WHERE id IN ({$segnaposto})",
            [$mailId, $saltato, ...$ids]
        );
    }

    /**
     * Oggetto e testo del messaggio. Testo semplice, nel tono del dispaccio.
     *
     * @param list<array<string,mixed>> $righe
     * @return array{0:string,1:string}
     */
    public static function messaggio(string $cat, array $righe): array
    {
        $boat = Database::first('SELECT uboat_number FROM boats WHERE id = ?', [(int) $righe[0]['boat_id']]);
        $numero = $boat !== null ? (string) $boat['uboat_number'] : 'il tuo battello';
        $n = count($righe);
        $ultimo = $righe[$n - 1];
        $dove = $ultimo['quadrat'] !== null ? ' in ' . (string) $ultimo['quadrat'] : '';

        $oggetto = $numero . ' — ' . match ($cat) {
            'contatti' => $n === 1 ? 'contatto' . $dove : $n . ' contatti' . $dove,
            'pericoli' => $n === 1 ? 'pericolo' . $dove : $n . ' allarmi' . $dove,
            default    => $n === 1 ? 'dal BdU' : $n . ' messaggi dal BdU',
        };

        $orologio = World::clock();
        $elenco = '';
        foreach ($righe as $r) {
            $elenco .= $orologio->formatDiario((int) $r['gts'])
                . ($r['quadrat'] !== null ? ', ' . $r['quadrat'] : '') . "\n"
                . wordwrap((string) $r['testo'], 72) . "\n\n";
        }

        $base = rtrim((string) Config::get('app.public_url', ''), '/');
        $link = $base . self::CATEGORIE[$cat]['pagina'];
        $profilo = $base . '/comandante/profilo#avvisi';
        $consiglio = match ($cat) {
            'contatti' => "Si da' battaglia dalla stazione d'attacco, entro dieci miglia dal bersaglio.\n"
                . "Un contatto che nessuno insegue, prima o poi si perde.",
            'pericoli' => "Il I.WO ha gia' fatto quello che poteva. Il resto spetta al comandante.",
            default    => "Gli ordini del BdU si leggono in plancia, alla pagina del comando.",
        };

        $corpo = "BEFEHLSHABER DER U-BOOTE\nAvviso per {$numero}\n\n{$elenco}{$consiglio}\n\nAlla console: {$link}\n\n"
            . "--\nHai chiesto tu questi avvisi. Si spengono, una categoria per volta, dal\n"
            . "tuo fascicolo: {$profilo}\nNon partono quando sei gia' alla console.\n";
        return [$oggetto, $corpo];
    }
}
