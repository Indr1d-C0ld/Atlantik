<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\World;

/**
 * Il giornale di guerra pubblico.
 *
 * Le comunita' dei giochi di sommergibili vivono di resoconti di missione: chi ha
 * passato tre settimane nel Nord Atlantico vuole raccontarlo, con la rotta e i
 * numeri. Qui il comandante pubblica il giornale di una missione di guerra
 * conclusa, e ottiene un collegamento da dare a chi vuole. La pagina e'
 * impaginata come un Kriegstagebuch vero, a quattro colonne — data e ora,
 * posizione, vento e mare, avvenimenti — con la carta della rotta disegnata
 * dalle stesse coste del gioco, gli affondamenti e il rapporto del BdU.
 *
 * Mai le missioni in corso: pubblicare dove si e' adesso sarebbe dirlo agli
 * altri giocatori. Mai l'addestramento. Il collegamento ha un gettone non
 * indovinabile, e il comandante lo puo' ritirare.
 */
final class GiornalePubblico
{
    /** Le righe che vanno nel documento: le note, non il rumore di bordo. */
    private const ESCLUSE = ['posizione', 'waypoint', 'punto_nave', 'rotta'];

    /** @return array{ok:bool, error?:string, token?:string} */
    public static function pubblica(int $userId, int $patrolId): array
    {
        $p = Database::first('SELECT * FROM patrols WHERE id = ? AND user_id = ?', [$patrolId, $userId]);
        if ($p === null) {
            return ['ok' => false, 'error' => 'Missione non trovata.'];
        }
        if ((string) $p['state'] === 'in_corso') {
            return ['ok' => false, 'error' => 'Una missione in corso non si pubblica: direbbe a tutti dove sei.'];
        }
        if ((string) ($p['tipo'] ?? 'guerra') !== 'guerra') {
            return ['ok' => false, 'error' => 'Si pubblicano le missioni di guerra, non l\'addestramento.'];
        }
        if ($p['pubblica_token'] !== null) {
            return ['ok' => true, 'token' => (string) $p['pubblica_token']];
        }
        $token = bin2hex(random_bytes(12));
        Database::run('UPDATE patrols SET pubblica_token = ? WHERE id = ?', [$token, $patrolId]);
        return ['ok' => true, 'token' => $token];
    }

    public static function ritira(int $userId, int $patrolId): bool
    {
        return Database::run('UPDATE patrols SET pubblica_token = NULL WHERE id = ? AND user_id = ?', [$patrolId, $userId])
            ->rowCount() > 0;
    }

    /**
     * Tutto quello che serve alla pagina, o null se il gettone non vale.
     *
     * @return array<string,mixed>|null
     */
    public static function dati(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{24}$/', $token)) {
            return null;
        }
        $p = Database::first(
            "SELECT * FROM patrols WHERE pubblica_token = ? AND state <> 'in_corso' AND tipo = 'guerra'", [$token]);
        if ($p === null) {
            return null;
        }
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $p['boat_id']]);
        $cmd = $p['commander_id'] !== null
            ? Database::first('SELECT c.*, u.role FROM commanders c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [(int) $p['commander_id']])
            : null;
        $eventi = Database::all('SELECT * FROM patrol_events WHERE patrol_id = ? ORDER BY gts, id', [(int) $p['id']]);

        $righe = [];
        $traccia = [];
        foreach ($eventi as $e) {
            if ($e['lat'] !== null && $e['lon'] !== null) {
                $traccia[] = [(float) $e['lat'], (float) $e['lon']];
            }
            if (in_array((string) $e['kind'], self::ESCLUSE, true) || (string) $e['severity'] === 'info') {
                continue;
            }
            $tempo = null;
            if ($e['lat'] !== null) {
                $m = World::weather((float) $e['lat'], (float) $e['lon'], (int) $e['gts']);
                $tempo = sprintf('%s %d, mare %d', \App\Sim\Weather::rosa((float) $m['wind_dir']), (int) $m['beaufort'], (int) $m['sea_state']);
            }
            $righe[] = $e + ['tempo' => $tempo];
        }

        $affondate = Database::all('SELECT * FROM sinkings WHERE patrol_id = ? ORDER BY gts', [(int) $p['id']]);
        return [
            'patrol'    => $p,
            'boat'      => $boat,
            'cmd'       => $cmd,
            'tipo'      => $boat !== null ? World::type((string) $boat['type_key']) : null,
            'righe'     => $righe,
            'affondate' => $affondate,
            'giorni'    => $p['returned_gts'] !== null ? ((int) $p['returned_gts'] - (int) $p['departed_gts']) / 86400 : null,
            'carta'     => self::carta($traccia, array_map(static fn (array $a): array => [(float) $a['lat'], (float) $a['lon']], $affondate)),
        ];
    }

    /**
     * La carta della rotta in SVG: le coste del gioco (assets/js/coste.js)
     * ritagliate attorno alla rotta, la traccia, e una croce per ogni nave
     * affondata. Proiezione equirettangolare, come la carta di bordo.
     *
     * @param list<array{0:float,1:float}> $traccia
     * @param list<array{0:float,1:float}> $croci
     */
    public static function carta(array $traccia, array $croci): string
    {
        if (count($traccia) < 2) {
            return '';
        }
        $lats = array_column($traccia, 0);
        $lons = array_column($traccia, 1);
        $margine = 2.0;
        [$s, $n, $w, $e] = [min($lats) - $margine, max($lats) + $margine, min($lons) - $margine * 1.6, max($lons) + $margine * 1.6];
        $kx = cos(deg2rad(($s + $n) / 2));
        $larghezza = 720.0;
        $scala = $larghezza / max(0.5, ($e - $w) * $kx);
        $altezza = max(240.0, min(720.0, ($n - $s) * $scala));
        if (($n - $s) * $scala > 720.0) {
            $scala = 720.0 / ($n - $s);
            $larghezza = ($e - $w) * $kx * $scala;
        }
        $xy = static fn (float $la, float $lo): string => sprintf('%.1f,%.1f', ($lo - $w) * $kx * $scala, ($n - $la) * $scala);

        $coste = '';
        $radice = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        preg_match_all('/\{b:\[([^\]]*)\],p:\[([^\]]*)\]/', (string) @file_get_contents($radice . '/assets/js/coste.js'), $m, PREG_SET_ORDER);
        foreach ($m as [, $b, $pp]) {
            [$bs, $bn, $bw, $be] = array_map('floatval', explode(',', $b));
            if ($bn < $s || $bs > $n || $be < $w || $bw > $e) {
                continue;
            }
            $p = array_map('floatval', explode(',', $pp));
            $punti = [];
            for ($i = 0; $i + 1 < count($p); $i += 2) {
                $punti[] = $xy($p[$i], $p[$i + 1]);
            }
            $coste .= '<polygon points="' . implode(' ', $punti) . '"/>';
        }

        $rotta = implode(' ', array_map(static fn (array $q): string => $xy($q[0], $q[1]), $traccia));
        $segni = '';
        foreach ($croci as [$la, $lo]) {
            [$x, $y] = array_map('floatval', explode(',', $xy($la, $lo)));
            $segni .= sprintf('<path d="M%.1f %.1fl8 8m0 -8l-8 8" />', $x - 4, $y - 4);
        }
        [$x0, $y0] = array_map('floatval', explode(',', $xy($traccia[0][0], $traccia[0][1])));

        return sprintf(
            '<svg class="ktb-carta" viewBox="0 0 %.0f %.0f" role="img" aria-label="Carta della rotta">'
            . '<rect width="100%%" height="100%%" class="ktb-carta-mare"/><g class="ktb-carta-coste">%s</g>'
            . '<polyline class="ktb-carta-rotta" points="%s"/><circle class="ktb-carta-base" cx="%.1f" cy="%.1f" r="4"/>'
            . '<g class="ktb-carta-croci">%s</g></svg>',
            $larghezza, $altezza, $coste, $rotta, $x0, $y0, $segni
        );
    }
}
