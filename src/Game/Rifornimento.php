<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Sectors;
use App\Sim\Torpedo;
use App\Sim\World;

/**
 * Rifornimento in mare dal Tipo XIV, la "vacca da latte".
 *
 * Alto rischio e alta ricompensa. Si chiede l'appuntamento al BdU — e la
 * richiesta e' una trasmissione, quindi si paga subito in radiogoniometria —
 * si riceve un quadrato e una finestra oraria, ci si va consumando nafta, e il
 * trasferimento si fa in superficie con mare non oltre forza 5.
 *
 * NOTA (audit del 19/09/2026): qui c'era scritto che il trasferimento "richiede
 * ore di superficie immobile", e c'era pure la manopola per regolarle
 * (rifornimento.ore_min). Nel codice quelle ore non sono mai esistite: chi
 * arriva all'appuntamento in superficie e con mare buono si rifornisce
 * sull'istante. La manopola e' stata tolta perche' non muoveva niente; la
 * meccanica dell'attesa resta da fare, ed e' una decisione di gioco, non una
 * correzione. E' il momento piu' vulnerabile della vita di un U-Boot: gli
 * alleati, leggendo il
 * traffico cifrato, arrivarono a conoscere gli appuntamenti prima dei
 * partecipanti, e nel 1943 li sterminarono quasi tutti.
 */
final class Rifornimento
{
    /** @return array<string,mixed>|null */
    public static function corrente(int $boatId): ?array
    {
        return Database::first(
            "SELECT * FROM rendezvous WHERE boat_id = ? AND stato = 'fissato' ORDER BY id DESC LIMIT 1",
            [$boatId]
        );
    }

    /**
     * Chiede un appuntamento. Passa dalla radio: il conto lo si paga subito.
     *
     * @return array{ok:bool, error?:string, rdv?:array, radio?:array}
     */
    public static function richiedi(array $boat, int $gts): array
    {
        if ((string) $boat['state'] !== 'mare') {
            return ['ok' => false, 'error' => 'Il rifornimento in mare si chiede dal mare.'];
        }
        if (self::corrente((int) $boat['id']) !== null) {
            return ['ok' => false, 'error' => 'Hai gia\' un appuntamento fissato.'];
        }

        $radio = Radio::trasmetti($boat, 'kurzsignal', '', 'rifornimento');
        if (!$radio['ok']) {
            return ['ok' => false, 'error' => $radio['error'] ?? 'Trasmissione non riuscita.'];
        }

        $rng = Rng::for(World::seed(), 'rdv', (int) $boat['id'], $gts);

        // Il punto d'incontro sta al largo, lontano dalle rotte aeree: da
        // duecento a quattrocento miglia da dove siamo, verso il centro oceano.
        $distanza = $rng->range(180, 420);
        $rotta = Geo::normBearing(270 + $rng->range(-60, 60));
        [$lat, $lon] = Geo::destination((float) $boat['lat'], (float) $boat['lon'], $rotta, $distanza);
        $lat = max(25.0, min(58.0, $lat));
        $lon = max(-48.0, min(-12.0, $lon));

        $apertura = $gts + (int) ($rng->range(1.2, 2.8) * 86400);
        $scadenza = $apertura + 3 * 86400;

        Database::run(
            'INSERT INTO rendezvous (boat_id, quadrat, lat, lon, apertura_gts, scadenza_gts, nafta_t, siluri)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $boat['id'], Grid::toQuadrat($lat, $lon, 3) ?? '—', $lat, $lon,
                $apertura, $scadenza, round($rng->range(35, 60), 1), $rng->int(2, 4),
            ]
        );
        $rdv = Database::first('SELECT * FROM rendezvous WHERE id = ?', [Database::lastInsertId()]);

        Database::run(
            'INSERT INTO bdu_orders (boat_id, commander_id, tipo, quadrat, lat, lon, testo, emesso_gts, scade_gts, stato, prestigio, punti)
             VALUES (?, ?, "rifornimento", ?, ?, ?, ?, ?, ?, "accettato", 120, 15)',
            [
                (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                (string) $rdv['quadrat'], $lat, $lon,
                sprintf('Appuntamento col battello cisterna in quadrato %s fra il %s e il %s. '
                    . 'Presentarsi in superficie, mare permettendo. Nessuna trasmissione sul posto.',
                    (string) $rdv['quadrat'], World::clock()->format($apertura), World::clock()->format($scadenza)),
                $gts, $scadenza,
            ]
        );

        return ['ok' => true, 'rdv' => $rdv, 'radio' => $radio];
    }

    /**
     * Verifica l'appuntamento: se siamo sul posto, nella finestra, in
     * superficie e con mare accettabile, il trasferimento comincia e in
     * qualche ora si e' riforniti.
     *
     * @return list<string> eventi
     */
    public static function verifica(array $boat, int $gts): array
    {
        $rdv = self::corrente((int) $boat['id']);
        if ($rdv === null) {
            return [];
        }

        if ($gts > (int) $rdv['scadenza_gts']) {
            Database::run("UPDATE rendezvous SET stato = 'mancato' WHERE id = ?", [(int) $rdv['id']]);
            return ['Appuntamento mancato: il battello cisterna non aspetta oltre la finestra concordata.'];
        }
        if ($gts < (int) $rdv['apertura_gts']) {
            return [];
        }

        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $rdv['lat'], (float) $rdv['lon']);
        if ($d > 4.0) {
            return [];
        }
        if ((string) $boat['mode'] !== 'superficie') {
            return [];
        }

        $meteo = World::weather((float) $boat['lat'], (float) $boat['lon'], $gts);
        if ((int) $meteo['sea_state'] > 5) {
            return ['Il battello cisterna e\' in vista, ma con questo mare le manichette non si possono passare. Si aspetta.'];
        }

        // Ultra: dove il mare e' caldo, l'appuntamento puo' essere gia' noto.
        $rng = Rng::for(World::seed(), 'rdv_esito', (int) $rdv['id'], $gts);
        $heat = Sectors::heat(Sectors::key((float) $boat['lat'], (float) $boat['lon']), $gts);
        if ($rng->chance(min(0.5, 0.06 + $heat / 260.0))) {
            Database::run("UPDATE rendezvous SET stato = 'compromesso' WHERE id = ?", [(int) $rdv['id']]);
            Sectors::add((float) $boat['lat'], (float) $boat['lon'], 30.0, $gts, 'appuntamento compromesso');
            return [
                'Il punto d\'incontro e\' scoperto: aerei sul posto prima ancora del battello cisterna. '
                . 'Niente rifornimento, e adesso sanno dove siamo.',
            ];
        }

        // Trasferimento.
        $type = World::type((string) $boat['type_key']);
        $nafta = min((float) $rdv['nafta_t'], (float) $type['fuel_t'] - (float) $boat['fuel_t']);
        Database::run(
            'UPDATE boats SET fuel_t = LEAST(?, fuel_t + ?), provisions_days = LEAST(?, provisions_days + 14) WHERE id = ?',
            [(float) $type['fuel_t'], $nafta, (float) $type['provisions_days'], (int) $boat['id']]
        );

        // Siluri: si passano quelli di riserva, e non e' un lavoro da poco.
        $siluri = (int) $rdv['siluri'];
        $carico = Torpedo::caricoStandard($type, 0);
        $tipoSiluro = array_key_first($carico);
        for ($i = 0; $i < $siluri; $i++) {
            Database::run(
                'INSERT INTO boat_torpedoes (boat_id, tkey, posizione, stato) VALUES (?, ?, "riserva_interna", "pronto")',
                [(int) $boat['id'], $tipoSiluro]
            );
        }

        Database::run("UPDATE rendezvous SET stato = 'concluso' WHERE id = ?", [(int) $rdv['id']]);
        Database::run(
            "UPDATE bdu_orders SET stato = 'assolto' WHERE boat_id = ? AND tipo = 'rifornimento' AND stato = 'accettato'",
            [(int) $boat['id']]
        );
        if ($boat['commander_id'] !== null) {
            Database::run(
                'UPDATE commanders SET prestigio = prestigio + 120, prestigio_tot = prestigio_tot + 120, punti = punti + 15
                 WHERE id = ?',
                [(int) $boat['commander_id']]
            );
        }

        return [sprintf(
            'Rifornimento eseguito: %.0f tonnellate di nafta, %s, viveri per due settimane. '
            . 'Ore di superficie immobile a fianco della cisterna, e nessuno che respirava.',
            $nafta, plurale($siluri, 'un siluro', '%d siluri')
        )];
    }
}
