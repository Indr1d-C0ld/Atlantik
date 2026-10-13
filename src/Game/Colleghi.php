<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Geo;
use App\Sim\Names;
use App\Sim\Rng;
use App\Sim\Torpedo;
use App\Sim\World;

/**
 * I colleghi: U-Boot mossi dal motore.
 *
 * Atlantik e' un mondo persistente pensato per molti comandanti, e con pochi
 * l'Atlantico e' vuoto: i comunicati del BdU non parlano di nessuno, l'albo
 * non si riempie, il branco — Rudeltaktik, il cuore della guerra dei convogli —
 * non si raduna mai, perche' servono almeno due battelli. I colleghi sono
 * quei battelli.
 *
 * Ogni collega e' un account di sistema che non puo' entrare (role 'motore',
 * status 'suspended'), con un comandante e un battello veri, e vive con le
 * regole di tutti: parte quando il cantiere ha finito, accetta l'area del BdU,
 * ci va e pattuglia, segnala i convogli per radio — e la segnalazione arriva
 * ai branchi e agli avvisi per posta dei giocatori —, li attacca col I.WO
 * (Dottrina, che per lui e' il comandante), rientra quando mancano nafta,
 * siluri o viveri, e se non torna lascia il posto a un comandante nuovo. Il
 * gioco li dichiara: nel fascicolo, nell'albo, nelle classifiche.
 *
 * Non sono giocatori finti e non devono sembrarlo: non scrivono in mensa e non
 * hanno ritratti storici. Sono la flottiglia che c'e' intorno.
 */
final class Colleghi
{
    /** Basi dei colleghi, a turno: le due flottiglie grandi e Brest. */
    private const BASI = ['lorient', 'saint_nazaire', 'brest', 'la_pallice'];
    private const TIPI = ['VIIC', 'VIIC', 'IXC40', 'VIIC'];

    /** @var array<int,bool> */
    private static array $memo = [];

    public static function eCollega(array $boat): bool
    {
        $uid = (int) ($boat['user_id'] ?? 0);
        if (!isset(self::$memo[$uid])) {
            $u = Database::first('SELECT role FROM users WHERE id = ?', [$uid]);
            self::$memo[$uid] = $u !== null && (string) $u['role'] === 'motore';
        }
        return self::$memo[$uid];
    }

    /**
     * Il battito: tiene in servizio i colleghi voluti e li fa vivere.
     *
     * @return array{in_servizio:int, partiti:int, rientrati:int, nuovi:int}
     */
    public static function mantieni(int $gts): array
    {
        $esito = ['in_servizio' => 0, 'partiti' => 0, 'rientrati' => 0, 'nuovi' => 0];
        $voluti = max(0, GameConfig::int('colleghi.quanti', 4));
        $utenti = Database::all("SELECT * FROM users WHERE role = 'motore' ORDER BY id");

        for ($i = count($utenti); $i < $voluti; $i++) {
            $utenti[] = self::nuovoUtente($i + 1);
        }

        foreach (array_slice($utenti, 0, $voluti) as $i => $u) {
            self::vivi($u, $i, $gts, $esito);
        }
        // Quelli oltre il numero voluto (se lo si abbassa) non ripartono, ma
        // chi e' in mare va riportato a casa: lasciato a se stesso attaccava
        // ancora, e non rientrava mai.
        foreach (array_slice($utenti, $voluti) as $u) {
            $boat = Fleet::boatOf((int) $u['id']);
            if ($boat !== null && (string) $boat['state'] === 'mare' && $boat['encounter_id'] === null
                && self::inMare($boat, $gts, true)) {
                $esito['rientrati']++;
            }
        }
        return $esito;
    }

    /**
     * Un giro di vita di un collega: comandante, battello, e quello che fa
     * adesso — in porto o in mare.
     *
     * @param array<string,mixed> $u     l'account di sistema del collega
     * @param array<string,int>   $esito contatori del battito
     */
    public static function vivi(array $u, int $i, int $gts, array &$esito): void
    {
        $uid = (int) $u['id'];
        $cmd = Comandante::corrente($uid);
        if ($cmd === null) {
            $cmd = self::nuovoComandante($uid, $i, $gts);
            if ($cmd === null) {
                return;
            }
            $esito['nuovi'] = ($esito['nuovi'] ?? 0) + 1;
        }
        $boat = Fleet::ensureBoat($uid, self::TIPI[$i % count(self::TIPI)], self::BASI[$i % count(self::BASI)]);
        $esito['in_servizio'] = ($esito['in_servizio'] ?? 0) + 1;

        if ((string) $boat['state'] === 'base') {
            if (self::partenza($boat, $gts)) {
                $esito['partiti'] = ($esito['partiti'] ?? 0) + 1;
            }
        } elseif ((string) $boat['state'] === 'mare' && $boat['encounter_id'] === null) {
            if (self::inMare($boat, $gts)) {
                $esito['rientrati'] = ($esito['rientrati'] ?? 0) + 1;
            }
        }
    }

    /** Un account di sistema per un collega. Non entra: niente password utile, status sospeso. */
    private static function nuovoUtente(int $n): array
    {
        $nome = 'collega ' . $n;
        Database::run(
            "INSERT INTO users (username, email, password_hash, status, role, email_verified_at)
             VALUES (?, ?, ?, 'suspended', 'motore', NOW())",
            [$nome, 'collega' . $n . '@motore.invalid', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]
        );
        return Database::first('SELECT * FROM users WHERE id = ?', [Database::lastInsertId()]);
    }

    private static function nuovoComandante(int $uid, int $i, int $gts): ?array
    {
        $rng = Rng::for(World::seed(), 'collega', $uid, $gts);
        for ($tentativi = 0; $tentativi < 20; $tentativi++) {
            $res = Comandante::crea($uid, [
                'nome' => Names::nome($rng), 'nato_il' => sprintf('%02d/%02d/19%02d', $rng->int(1, 28), $rng->int(1, 12), $rng->int(9, 17)),
                'nato_a' => ['Kiel', 'Wilhelmshaven', 'Hamburg', 'Bremen', 'Lübeck', 'Stettin', 'Danzig', 'Flensburg'][$rng->int(0, 7)],
                'ritratto' => 'r' . $rng->int(1, 4), 'base' => self::BASI[$i % count(self::BASI)],
            ]);
            if ($res['ok']) {
                return Comandante::corrente($uid);
            }
        }
        return null;
    }

    /** In porto: si parte quando il cantiere ha finito e la sosta e' fatta. */
    private static function partenza(array $boat, int $gts): bool
    {
        $ultimo = Database::first(
            "SELECT returned_gts FROM patrols WHERE boat_id = ? AND tipo = 'guerra' ORDER BY id DESC LIMIT 1",
            [(int) $boat['id']]
        );
        $sosta = max(0, GameConfig::int('colleghi.sosta_giorni', 6)) * 86400;
        if ($ultimo !== null && $ultimo['returned_gts'] !== null && $gts - (int) $ultimo['returned_gts'] < $sosta) {
            return false;
        }
        $lavori = Database::first("SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'", [(int) $boat['id']]);
        if ((int) ($lavori['n'] ?? 0) > 0 && ($ultimo === null || $gts - (int) $ultimo['returned_gts'] < 3 * $sosta)) {
            return false;               // il cantiere non ha finito: si aspetta (ma non per sempre)
        }
        return Patrol::depart($boat)['ok'];
    }

    /**
     * In mare: rotta, area, radio, branco, rientro.
     *
     * @return bool true se il battello e' rientrato in porto
     */
    private static function inMare(array $boat, int $gts, bool $congedo = false): bool
    {
        $id = (int) $boat['id'];
        $type = World::type((string) $boat['type_key']);
        $base = World::port((string) $boat['home_port_key']);

        // --- si rientra? ------------------------------------------------------------
        $inv = Torpedo::inventario($id);
        $dBase = $base !== null ? Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $base['lat'], (float) $base['lon']) : 0.0;
        $ordineRientro = Database::first(
            "SELECT 1 FROM bdu_orders WHERE boat_id = ? AND tipo = 'rientro' AND stato IN ('aperto','accettato')", [$id]);
        $rientro = $congedo
            || (float) $boat['fuel_t'] < 0.4 * (float) $type['fuel_t']
            || $inv['tubi'] + $inv['riserve'] === 0
            || (float) $boat['provisions_days'] < 8.0
            || $ordineRientro !== null;

        if ($rientro) {
            if ($dBase <= Patrol::RAGGIO_PORTO_NM) {
                return Patrol::dock($boat)['ok'];
            }
            $rotta = Patrol::route($id);
            $ultimo = $rotta !== [] ? $rotta[count($rotta) - 1] : null;
            $versoCasa = $ultimo !== null && $base !== null
                && Geo::distanceNm((float) $ultimo['lat'], (float) $ultimo['lon'], (float) $base['lat'], (float) $base['lon']) < 1.0;
            if (!$versoCasa) {
                $r = Patrol::rottaBase($boat);
                if ($r['trovata']) {
                    Patrol::setRoute($id, $r['punti']);
                }
                Patrol::orders($boat, 10.0, 0.0, false);
            }
            return false;
        }

        // --- l'area del BdU -------------------------------------------------------
        $area = Database::first(
            "SELECT * FROM bdu_orders WHERE boat_id = ? AND tipo IN ('area','pedinamento') AND stato IN ('aperto','accettato')
              ORDER BY emesso_gts DESC LIMIT 1",
            [$id]
        );
        if ($area !== null && (string) $area['stato'] === 'aperto') {
            Bdu::rispondi($boat, (int) $area['id'], true, $gts);
        }

        // --- il branco: se c'e' un gruppo aperto non troppo lontano, ci si unisce ---
        if ($boat['wolfpack_id'] === null) {
            foreach (Branco::aperti($gts) as $w) {
                if ($w['lat'] !== null
                    && Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $w['lat'], (float) $w['lon']) > 600.0) {
                    continue;
                }
                if (Branco::entra($boat, (int) $w['id'], $gts)['ok']) {
                    break;
                }
            }
        }

        // --- un convoglio in vista si segnala ------------------------------------------
        $convoglio = Database::first(
            "SELECT * FROM contacts WHERE boat_id = ? AND perso = 0 AND convoy_id IS NOT NULL AND last_gts >= ?
              ORDER BY last_gts DESC LIMIT 1",
            [$id, $gts - 1800]
        );
        if ($convoglio !== null && (string) $boat['mode'] === 'superficie') {
            $giaDetto = Database::first(
                "SELECT 1 FROM radio_messages WHERE boat_id = ? AND tipo = 'contatto' AND gts >= ?",
                [$id, $gts - 6 * 3600]
            );
            if ($giaDetto === null) {
                Radio::trasmetti($boat, 'contatto', '', 'contatto');
            }
        }

        // --- la rotta ------------------------------------------------------------------------
        // Un convoglio decifrato dal B-Dienst nelle ultime dodici ore, a meno di
        // trecento miglia: ci si va, piu' avanti sulla sua rotta.
        $bdienst = null;
        foreach (Database::all(
            "SELECT * FROM radio_messages WHERE destinatario = 'tutti' AND tipo = 'comunicato' AND lat IS NOT NULL
               AND testo LIKE 'B-Dienst:%' AND gts >= ? AND id > ? ORDER BY gts DESC LIMIT 5",
            [$gts - 12 * 3600, (int) ($boat['bdienst_msg_id'] ?? 0)]
        ) as $m) {
            if (Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $m['lat'], (float) $m['lon']) <= 300.0) {
                $bdienst = $m;
                break;
            }
        }
        // Ogni notizia si segue una volta: si fa rotta sul punto, e arrivati
        // li' o il convoglio c'e' e lo si sente, o non c'e' piu' e si torna
        // all'area.
        if ($bdienst !== null) {
            Database::run('UPDATE boats SET bdienst_msg_id = ? WHERE id = ?', [(int) $bdienst['id'], $id]);
            $adeguata = Patrol::adegua($boat, [['lat' => (float) $bdienst['lat'], 'lon' => (float) $bdienst['lon']]]);
            if ($adeguata['punti'] !== []) {
                Patrol::setRoute($id, $adeguata['punti']);
            }
            Patrol::orders($boat, 12.0, 0.0, false);
            return false;
        }
        if (Patrol::route($id) !== []) {
            // C'e' ancora strada da fare: macchine avanti, se sono ferme (alla
            // partenza il battello esce dal bunker con le macchine a zero).
            if ((float) $boat['ordered_speed_kn'] < 5.0) {
                Patrol::orders($boat, 10.0, 0.0, false);
            }
            return false;
        }
        $meta = Database::first(
            "SELECT * FROM bdu_orders WHERE boat_id = ? AND tipo IN ('area','pedinamento') AND stato = 'accettato'
              AND lat IS NOT NULL ORDER BY emesso_gts DESC LIMIT 1",
            [$id]
        );
        $rng = Rng::for(World::seed(), 'pattuglia', $id, intdiv($gts, 3600));
        if ($meta !== null && Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $meta['lat'], (float) $meta['lon']) > 30.0) {
            $centro = [(float) $meta['lat'], (float) $meta['lon']];
            $punti = [['lat' => $centro[0], 'lon' => $centro[1]]];
            $nodi = 10.0;
        } else {
            // Nell'area, o senza area: un rettangolo di pattuglia attorno a dove si e'.
            $centro = $meta !== null ? [(float) $meta['lat'], (float) $meta['lon']] : [(float) $boat['lat'], (float) $boat['lon']];
            $punti = [];
            $giro = $rng->range(0, 360);
            foreach ([0, 90, 180, 270] as $k) {
                [$la, $lo] = Geo::destination($centro[0], $centro[1], Geo::normBearing($giro + $k), 35.0);
                $punti[] = ['lat' => $la, 'lon' => $lo];
            }
            $nodi = 7.0;
        }
        $adeguata = Patrol::adegua($boat, $punti);
        if ($adeguata['punti'] !== []) {
            Patrol::setRoute($id, $adeguata['punti']);
        }
        Patrol::orders($boat, $nodi, 0.0, false);
        return false;
    }
}
