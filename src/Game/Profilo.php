<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * Il fascicolo di un comandante, come lo vedono gli altri.
 *
 * In flottiglia ci si conosce: chi sei, su che battello sei, quanto hai
 * portato a casa e quanto ci hai messo. Questo raccoglie quello che di un
 * comandante e' pubblico — e tutto, qui, e' pubblico tranne l'indirizzo di
 * posta del suo account, che non esce da questa classe ne' da nessun'altra.
 *
 * La divisione degli affondamenti fra naviglio militare e naviglio civile non
 * e' un dettaglio contabile: era la distinzione su cui si misurava la guerra al
 * traffico. Affondare una scorta era un fatto d'armi, affondare un mercantile
 * era il mestiere, e i due numeri stanno bene separati.
 */
final class Profilo
{
    /**
     * Tutto quello che si sa di un comandante.
     *
     * @return array<string,mixed>|null
     */
    public static function di(int $commanderId): ?array
    {
        $cmd = Database::first(
            'SELECT c.*, u.username FROM commanders c
             LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?',
            [$commanderId]
        );
        if ($cmd === null) {
            return null;
        }

        $boat = Database::first(
            "SELECT * FROM boats WHERE commander_id = ? ORDER BY id DESC LIMIT 1",
            [$commanderId]
        );

        return [
            'cmd'          => $cmd,
            'grado'        => Carriera::gradoNome((int) $cmd['grado']),
            'ritratto'     => Ritratto::di($cmd),
            'boat'         => $boat,
            'tipo'         => $boat !== null ? World::type((string) $boat['type_key']) : null,
            'emblema'      => $boat !== null ? Emblema::di($boat) : null,
            'numeri'       => self::numeri($commanderId),
            'piu_lunga'    => self::piuLunga($commanderId),
            'decorazioni'  => Database::all(
                'SELECT a.gts, a.motivazione, t.nome, t.nome_it, t.note, t.ordine
                   FROM awards a JOIN award_types t ON t.akey = a.akey
                  WHERE a.commander_id = ? ORDER BY t.ordine DESC, a.gts',
                [$commanderId]
            ),
            'trofei'       => Database::all(
                'SELECT h.gts, h.dettaglio, t.nome, t.descrizione
                   FROM achievements h JOIN achievement_types t ON t.akey = h.akey
                  WHERE h.commander_id = ? ORDER BY h.gts DESC',
                [$commanderId]
            ),
            'per_genere'   => self::perGenere($commanderId),
            'per_bandiera' => self::perBandiera($commanderId),
            'migliori'     => Database::all(
                'SELECT nome, grt, class_key, bandiera, gts, quadrat FROM sinkings
                  WHERE commander_id = ? ORDER BY grt DESC LIMIT 8',
                [$commanderId]
            ),
            'missioni'     => Database::all(
                "SELECT id, number, departed_gts, returned_gts, state, distance_nm,
                        affondate, grt_affondato, siluri_lanciati, area_quadrat
                   FROM patrols WHERE commander_id = ? ORDER BY id DESC LIMIT 15",
                [$commanderId]
            ),
        ];
    }

    /**
     * I numeri di una carriera.
     *
     * Il tonnellaggio da solo premia chi resta fuori piu' a lungo: qui accanto
     * ci sono anche le tonnellate per siluro e per missione, che e' il modo in
     * cui si distingue un comandante bravo da uno fortunato.
     *
     * @return array<string,mixed>
     */
    public static function numeri(int $commanderId): array
    {
        $p = Database::first(
            "SELECT COUNT(*) uscite,
                    SUM(state <> 'in_corso') concluse,
                    COALESCE(SUM(distance_nm), 0) miglia,
                    COALESCE(SUM(surfaced_nm), 0) miglia_sup,
                    COALESCE(SUM(submerged_nm), 0) miglia_sub,
                    COALESCE(SUM(siluri_lanciati), 0) siluri,
                    COALESCE(SUM(fuel_used_t), 0) nafta,
                    COALESCE(MAX(max_depth_m), 0) quota_max
               FROM patrols WHERE commander_id = ?",
            [$commanderId]
        ) ?? [];

        $s = Database::first(
            'SELECT COUNT(*) navi, COALESCE(SUM(grt), 0) grt FROM sinkings WHERE commander_id = ?',
            [$commanderId]
        ) ?? [];

        $navi = (int) ($s['navi'] ?? 0);
        $grt  = (int) ($s['grt'] ?? 0);
        $siluri = (int) ($p['siluri'] ?? 0);
        $concluse = (int) ($p['concluse'] ?? 0);
        $miglia = (float) ($p['miglia'] ?? 0);

        return [
            'uscite'        => (int) ($p['uscite'] ?? 0),
            'concluse'      => $concluse,
            'miglia'        => $miglia,
            'miglia_sup'    => (float) ($p['miglia_sup'] ?? 0),
            'miglia_sub'    => (float) ($p['miglia_sub'] ?? 0),
            'siluri'        => $siluri,
            'nafta'         => (float) ($p['nafta'] ?? 0),
            'quota_max'     => (float) ($p['quota_max'] ?? 0),
            'navi'          => $navi,
            'grt'           => $grt,
            'grt_siluro'    => $siluri > 0 ? $grt / $siluri : null,
            'grt_missione'  => $concluse > 0 ? $grt / $concluse : null,
            'grt_mille_miglia' => $miglia > 0 ? $grt / ($miglia / 1000) : null,
            'colpi_a_segno' => $siluri > 0 ? 100 * self::siluriASegno($commanderId) / $siluri : null,
        ];
    }

    private static function siluriASegno(int $commanderId): int
    {
        return (int) (Database::first(
            "SELECT COUNT(*) n FROM torpedo_runs t
               JOIN patrols p ON p.boat_id = t.boat_id AND p.commander_id = ?
              WHERE t.esito = 'colpito'",
            [$commanderId]
        )['n'] ?? 0);
    }

    /**
     * La missione piu' lunga.
     *
     * "Piu' lunga" ha due sensi e tutti e due contano: i giorni passati fuori e
     * le miglia percorse. Una crociera nei Caraibi e' lunga di miglia, una
     * caccia nel Nord Atlantico e' lunga di giorni.
     *
     * @return array{giorni:?array<string,mixed>,miglia:?array<string,mixed>}
     */
    public static function piuLunga(int $commanderId): array
    {
        return [
            'giorni' => Database::first(
                "SELECT *, (COALESCE(returned_gts, ?) - departed_gts) / 86400.0 AS giorni
                   FROM patrols WHERE commander_id = ?
                  ORDER BY giorni DESC LIMIT 1",
                [World::now(), $commanderId]
            ),
            'miglia' => Database::first(
                'SELECT * FROM patrols WHERE commander_id = ? ORDER BY distance_nm DESC LIMIT 1',
                [$commanderId]
            ),
        ];
    }

    /**
     * Affondamenti divisi per genere di naviglio.
     *
     * Le classi del gioco hanno un "kind": mercantile, petroliera, trasporto,
     * scorta, ausiliaria. Militare e' quello che navigava armato per mestiere —
     * le scorte — e il resto e' naviglio civile requisito alla guerra. La nave
     * civetta fa storia a se': era un mercantile, ed era armata.
     *
     * @return array<string,mixed>
     */
    public static function perGenere(int $commanderId): array
    {
        $righe = Database::all(
            'SELECT s.class_key, s.grt, c.kind, c.name, c.armata
               FROM sinkings s LEFT JOIN ship_classes c ON c.class_key = s.class_key
              WHERE s.commander_id = ?',
            [$commanderId]
        );

        $gruppi = [
            'militare' => ['etichetta' => 'Naviglio militare', 'navi' => 0, 'grt' => 0, 'classi' => []],
            'civile'   => ['etichetta' => 'Naviglio mercantile e civile', 'navi' => 0, 'grt' => 0, 'classi' => []],
        ];
        foreach ($righe as $r) {
            $kind = (string) ($r['kind'] ?? 'mercantile');
            $g = ($kind === 'scorta' || $kind === 'ausiliaria') ? 'militare' : 'civile';
            $gruppi[$g]['navi']++;
            $gruppi[$g]['grt'] += (int) $r['grt'];
            $k = (string) $r['class_key'];
            $gruppi[$g]['classi'][$k] ??= ['nome' => (string) ($r['name'] ?? $k), 'key' => $k, 'navi' => 0, 'grt' => 0];
            $gruppi[$g]['classi'][$k]['navi']++;
            $gruppi[$g]['classi'][$k]['grt'] += (int) $r['grt'];
        }
        foreach ($gruppi as &$g) {
            usort($g['classi'], static fn (array $a, array $b): int => $b['navi'] <=> $a['navi']);
        }
        return $gruppi;
    }

    /**
     * Affondamenti per bandiera.
     *
     * I neutrali stanno a parte, e non per pignoleria: affondare naviglio
     * neutrale era un problema politico prima che militare, e nel gioco costa
     * prestigio invece di darne.
     *
     * @return list<array<string,mixed>>
     */
    public static function perBandiera(int $commanderId): array
    {
        $righe = Database::all(
            'SELECT COALESCE(bandiera, ?) bandiera, COUNT(*) navi, COALESCE(SUM(grt), 0) grt
               FROM sinkings WHERE commander_id = ?
              GROUP BY bandiera ORDER BY grt DESC',
            ['sconosciuta', $commanderId]
        );
        foreach ($righe as &$r) {
            $r['neutrale'] = in_array((string) $r['bandiera'], ['neutrale', 'panamense'], true);
            $r['nome'] = self::nomeBandiera((string) $r['bandiera']);
        }
        return $righe;
    }

    public static function nomeBandiera(string $flag): string
    {
        return match ($flag) {
            'britannica'   => 'Gran Bretagna',
            'statunitense' => 'Stati Uniti',
            'norvegese'    => 'Norvegia',
            'canadese'     => 'Canada',
            'greca'        => 'Grecia',
            'olandese'     => 'Paesi Bassi',
            'panamense'    => 'Panama',
            'neutrale'     => 'neutrale',
            default        => $flag,
        };
    }

    /** La classe di una nave affondata, per mostrarne la sagoma. */
    public static function classe(string $key): array
    {
        return Traffic::classe($key);
    }
}
