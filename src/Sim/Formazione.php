<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;

/**
 * La formazione del convoglio.
 *
 * Fuori dall'incontro un convoglio e' una funzione matematica: un centro che
 * si sposta lungo una rotta. Quando si arriva a distanza di lancio quel centro
 * non basta piu' — serve sapere dove sta ogni scafo, perche' e' fra quegli
 * scafi che si entra.
 *
 * Qui il convoglio viene disposto nella formazione vera: colonne distanziate
 * circa mille iarde, navi in colonna a seicento, scorte sul perimetro. Sono le
 * distanze di ordinanza della Royal Navy nel 1942, e sono anche il motivo per
 * cui un attacco dall'interno del convoglio funzionava: dentro, l'ASDIC delle
 * scorte e' cieco fra gli scafi dei mercantili.
 */
final class Formazione
{
    /** Dispone il convoglio in formazione attorno al suo centro. */
    public static function materializza(int $encId, int $convoyId, array $pos, Rng $rng): void
    {
        $cv = Database::first('SELECT * FROM convoys WHERE id = ?', [$convoyId]);
        $colonne = max(1, (int) $cv['colonne']);
        $rotta = (float) $pos['heading'];

        // Spaziatura reale: mille iarde fra le colonne, seicento fra le navi
        // in colonna. Un convoglio di quaranta navi occupa parecchie miglia
        // quadrate di oceano, ed e' per questo che ci si infila dentro.
        $passoColonna = 1000 / 2025.0;   // ~0,49 nm
        $passoFila    = 600 / 2025.0;    // ~0,30 nm

        $navi = Database::all(
            "SELECT * FROM ships WHERE convoy_id = ? AND state = 'in_mare' AND ruolo <> 'scorta' ORDER BY colonna, fila",
            [$convoyId]
        );
        foreach ($navi as $n) {
            $col = (int) ($n['colonna'] ?? 1) - ($colonne + 1) / 2.0;
            $fil = (int) ($n['fila'] ?? 1) - 1;

            [$lat, $lon] = Geo::destination($pos['lat'], $pos['lon'], Geo::normBearing($rotta + 90), $col * $passoColonna);
            [$lat, $lon] = Geo::destination($lat, $lon, Geo::normBearing($rotta + 180), $fil * $passoFila);

            // Una nave gia' ferita si ritrova ferita: il danno che era uscito
            // dall'incontro precedente rientra in questo. E' il motivo per cui
            // vale la pena tornare a cercare una nave colpita e non affondata.
            Database::run(
                'INSERT INTO encounter_entities (encounter_id, ship_id, class_key, name, ruolo, lat, lon, heading,
                                                 speed_kn, grt, integrita, allagamento, incendio)
                 VALUES (?, ?, ?, ?, "mercantile", ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $encId, (int) $n['id'], (string) $n['class_key'], (string) $n['name'],
                    $lat, $lon, $rotta, (float) $n['speed_kn'], (int) $n['grt'],
                    (float) ($n['integrita'] ?? 100.0), (float) ($n['allagamento'] ?? 0.0),
                    (float) ($n['incendio'] ?? 0.0),
                ]
            );
        }

        // Le scorte: davanti, sui fianchi e in coda, come nello schema di
        // protezione dei gruppi di scorta oceanici.
        $scorte = Database::all(
            "SELECT * FROM ships WHERE convoy_id = ? AND state = 'in_mare' AND ruolo = 'scorta'",
            [$convoyId]
        );
        $stazioni = [[0, 3.2], [45, 2.6], [-45, 2.6], [90, 2.2], [-90, 2.2], [180, 2.8], [135, 2.5], [-135, 2.5]];
        foreach ($scorte as $i => $sc) {
            $st = $stazioni[$i % count($stazioni)];
            [$lat, $lon] = Geo::destination($pos['lat'], $pos['lon'], Geo::normBearing($rotta + $st[0]), $st[1]);
            $cls = Traffic::classe((string) $sc['class_key']);

            Database::run(
                'INSERT INTO encounter_entities (encounter_id, ship_id, class_key, name, ruolo, lat, lon, heading,
                                                 speed_kn, grt, dc_residue, manovra)
                 VALUES (?, ?, ?, ?, "scorta", ?, ?, ?, ?, ?, ?, "stazione")',
                [
                    $encId, (int) $sc['id'], (string) $sc['class_key'], (string) $sc['name'],
                    $lat, $lon, $rotta, (float) $cv['speed_kn'], (int) $cls['grt'], (int) $cls['dc_carica'],
                ]
            );
        }
    }
}
