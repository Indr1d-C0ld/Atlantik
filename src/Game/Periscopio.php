<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\Lock;
use App\Sim\BoatSim;
use App\Sim\Grid;

/**
 * Il periscopio d'osservazione, in crociera.
 *
 * Fino al 29/09/2026 il periscopio esisteva soltanto nella stazione d'attacco:
 * in crociera, a quota periscopica, la simulazione lo considerava sempre fuori
 * — si vedeva e si era visti — e il comandante non aveva un posto dove
 * alzarlo, abbassarlo o guardarci dentro. Il giocatore lo cercava e trovava
 * l'idrofono.
 *
 * A bordo i periscopi erano due. Quello d'osservazione, in camera di manovra,
 * con la lente grande e il campo largo: si usava in navigazione, anche contro
 * gli aerei. Quello d'attacco, in torretta, con la testa sottile che lascia
 * poca baffa: si usava sotto il convoglio. Qui si comanda il primo; il
 * secondo resta alla stazione d'attacco, che ha le sue regole (il I.WO lo
 * abbassa da solo dopo cinque minuti).
 *
 * Alzato: si vede fino all'orizzonte del periscopio, ma la testa e la baffa
 * sono una sagoma, piccola, che una vedetta attenta o un aereo possono
 * cogliere. Abbassato: ciechi, e invisibili; resta l'idrofono.
 */
final class Periscopio
{
    /** Dove sta il battello rispetto al periscopio. */
    public const IN_SUPERFICIE = 'superficie';
    public const A_QUOTA       = 'periscopio';
    public const TROPPO_SOTTO  = 'immersione';

    /**
     * @return array{posizione:string, alzato:bool, guasto:bool, stato_apparato:string}
     */
    public static function stato(array $boat): array
    {
        $st = Database::first(
            "SELECT state FROM boat_systems WHERE boat_id = ? AND skey = 'periscopio_osc'",
            [(int) $boat['id']]
        );
        $statoApparato = $st !== null ? (string) $st['state'] : 'ok';
        $posizione = (string) $boat['mode'];
        return [
            'posizione'      => $posizione,
            'alzato'         => $posizione === self::A_QUOTA && (bool) ($boat['periscopio_alzato'] ?? 0),
            'guasto'         => $statoApparato !== 'ok',
            'stato_apparato' => $statoApparato,
        ];
    }

    /**
     * Alza o abbassa il periscopio d'osservazione.
     *
     * Si porta prima il battello all'ora attuale, poi si cambia lo stato sotto
     * il lucchetto del battello: l'avanzamento pigro salva periscopio_alzato
     * insieme al resto, e un ordine dato a meta' di un avanzamento verrebbe
     * sovrascritto dal salvataggio. Con il lucchetto l'ordine arriva dopo.
     *
     * @return array{ok:bool, error?:string, testo?:string}
     */
    public static function comanda(array $boat, bool $alza): array
    {
        if ((string) $boat['state'] !== 'mare') {
            return ['ok' => false, 'error' => 'In porto il periscopio resta dentro.'];
        }
        if ($boat['encounter_id'] !== null) {
            return ['ok' => false, 'error' => 'Durante l\'attacco il periscopio si comanda dalla stazione d\'attacco.'];
        }

        $id = (int) $boat['id'];
        BoatSim::advance($id);
        if (!Lock::prendi('boat:' . $id, 5)) {
            return ['ok' => false, 'error' => 'La centrale e\' occupata: riprova fra un momento.'];
        }
        try {
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$id]);
            $stato = self::stato($boat);
            if ($alza) {
                if ($stato['posizione'] !== self::A_QUOTA) {
                    return ['ok' => false, 'error' => $stato['posizione'] === self::IN_SUPERFICIE
                        ? 'In superficie si guarda dalla torretta: il periscopio serve da sotto.'
                        : 'Troppo profondi: il periscopio si alza a quota periscopica, dodici-quattordici metri.'];
                }
                if ($stato['guasto']) {
                    return ['ok' => false, 'error' => 'Il periscopio d\'osservazione e\' in avaria: prima va riparato.'];
                }
            }
            if ($stato['alzato'] === $alza) {
                return ['ok' => true, 'testo' => $alza ? 'Il periscopio e\' gia\' fuori.' : 'Il periscopio e\' gia\' dentro.'];
            }

            Database::run(
                'UPDATE boats SET periscopio_alzato = ?, periscopio_gts = NULL, version = version + 1 WHERE id = ?',
                [$alza ? 1 : 0, $id]
            );
        } finally {
            Lock::lascia('boat:' . $id);
        }

        $testo = $alza
            ? 'Periscopio fuori. Giro d\'orizzonte.'
            : 'Periscopio dentro. Ciechi, e invisibili: resta l\'idrofono.';
        $patrol = Patrol::corrente($id);
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => (int) $boat['last_sim_gts'], 'kind' => 'periscopio', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => $testo,
            ], (int) $patrol['id'], $id);
        }
        return ['ok' => true, 'testo' => $testo];
    }

    /** L'altezza dell'occhio, o null se da qui non si vede niente. */
    public static function occhio(array $boat, array $stato): ?float
    {
        return BoatSim::occhio($stato['posizione'], $stato['alzato'], $stato['guasto']);
    }
}
