<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Sectors;
use App\Sim\Traffic;

/** Le statistiche della campagna: il vero indicatore della battaglia. */
final class Statistiche
{
    public static function campagna(int $gts): array
    {
        $aff = Database::first('SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings');
        $battelli = Database::first(
            "SELECT COUNT(*) n, SUM(state='mare') mare, SUM(state='perduto') persi FROM boats"
        );
        $cmd = Database::first(
            "SELECT COUNT(*) n, SUM(stato='attivo') attivi, SUM(stato<>'attivo') chiusi FROM commanders"
        );
        $traffico = Traffic::stato($gts);
        $patrol = Database::first(
            "SELECT COUNT(*) n, COALESCE(SUM(distance_nm),0) nm, COALESCE(SUM(siluri_lanciati),0) siluri FROM patrols"
        );
        $colpi = Database::first(
            "SELECT COUNT(*) n, SUM(esito='colpito') colpiti FROM torpedo_runs"
        );

        $persi = (int) ($battelli['persi'] ?? 0);
        $grt = (int) ($aff['grt'] ?? 0);

        return [
            'navi_affondate'   => (int) ($aff['n'] ?? 0),
            'grt_affondato'    => $grt,
            'battelli'         => (int) ($battelli['n'] ?? 0),
            'battelli_in_mare' => (int) ($battelli['mare'] ?? 0),
            'battelli_persi'   => $persi,
            // Il numero che conta davvero: quanta stazza costa ogni U-Boot perduto.
            'scambio'          => $persi > 0 ? (int) round($grt / $persi) : null,
            'comandanti'       => (int) ($cmd['n'] ?? 0),
            'comandanti_attivi'=> (int) ($cmd['attivi'] ?? 0),
            'fascicoli_chiusi' => (int) ($cmd['chiusi'] ?? 0),
            'patrol'           => (int) ($patrol['n'] ?? 0),
            'miglia'           => (float) ($patrol['nm'] ?? 0),
            'siluri_lanciati'  => (int) ($patrol['siluri'] ?? 0),
            'siluri_a_segno'   => (int) ($colpi['colpiti'] ?? 0),
            'percentuale_colpi'=> (int) ($colpi['n'] ?? 0) > 0
                ? round(100 * (int) ($colpi['colpiti'] ?? 0) / (int) $colpi['n'], 1)
                : null,
            'naviglio_in_mare' => $traffico['navi'],
            'grt_in_mare'      => $traffico['grt_mare'],
            'convogli'         => $traffico['convogli'],
            'settori_caldi'    => Sectors::caldi($gts, 10),
        ];
    }

    /** Classifica per tonnellaggio: comandanti in servizio e caduti insieme. */
    public static function classifica(int $limite = 20): array
    {
        return Database::all(
            "SELECT c.nome, c.grado, c.stato, c.patrols, c.affondate, c.grt_affondato, c.segnalazioni, u.username
             FROM commanders c JOIN users u ON u.id = c.user_id
             WHERE c.grt_affondato > 0 OR c.patrols > 0
             ORDER BY c.grt_affondato DESC LIMIT " . max(1, min(100, $limite))
        );
    }

    /** I convogli piu' martoriati. */
    public static function convogliColpiti(int $limite = 10): array
    {
        return Database::all(
            'SELECT c.serie, c.numero, c.affondate, c.navi_iniziali, c.rotta_key, c.state
             FROM convoys c WHERE c.affondate > 0 ORDER BY c.affondate DESC LIMIT ' . max(1, min(50, $limite))
        );
    }
}
