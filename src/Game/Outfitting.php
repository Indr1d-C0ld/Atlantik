<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\World;

/**
 * Allestimento: cosa si porta in mare, dentro lo spazio che c'e'.
 *
 * Su un Tipo VII lo spazio e' l'unica valuta vera. I viveri si appendono nei
 * corridoi, il pane ammuffisce sopra le cuccette, i siluri di riserva stanno
 * sotto gli uomini che dormono. Ogni giorno di viveri in piu' e' un ricambio in
 * meno; ogni cartuccia di potassa in piu' e' un'ora di immersione in piu' e un
 * colpo di cannone in meno. E' un problema di ottimizzazione vero, e va
 * risolto prima di mollare gli ormeggi.
 */
final class Outfitting
{
    /**
     * Le voci di carico. `spazio` e' il costo per unita'.
     *
     * @return array<string,array{nome:string,unita:string,spazio:float,max:int,note:string}>
     */
    public static function voci(): array
    {
        return [
            'viveri' => [
                'nome' => 'Viveri e acqua', 'unita' => 'giorni', 'spazio' => 4.0, 'max' => 120,
                'note' => 'I primi giorni pane fresco e verdura; poi conserve. Finiti i viveri, la missione e\' finita comunque.',
            ],
            'ricambi' => [
                'nome' => 'Ricambi e utensili', 'unita' => 'casse', 'spazio' => 2.0, 'max' => 40,
                'note' => 'Senza ricambi si ripara lo stesso, ma ci vuole il doppio del tempo.',
            ],
            'potassa' => [
                'nome' => 'Cartucce di potassa', 'unita' => 'cartucce', 'spazio' => 0.4, 'max' => 240,
                'note' => 'Assorbono l\'anidride carbonica: allungano di molto le ore che si possono stare sotto.',
            ],
            'ossigeno' => [
                'nome' => 'Bombole d\'ossigeno', 'unita' => 'bombole', 'spazio' => 1.5, 'max' => 48,
                'note' => 'L\'ultima risorsa quando l\'aria e\' finita e non si puo\' emergere.',
            ],
            'munizioni_cannone' => [
                'nome' => 'Munizioni per il cannone', 'unita' => 'colpi', 'spazio' => 0.30, 'max' => 250,
                'note' => 'Il modo economico di affondare una nave disarmata — e quello rapido di morire se armata non lo e\'.',
            ],
            'bold' => [
                'nome' => 'Cartucce Bold (Pillenwerfer)', 'unita' => 'cartucce', 'spazio' => 0.5, 'max' => 40,
                'note' => 'Sparate da un tubetto fuori bordo, generano una nuvola di bolle che all\'ASDIC somiglia a un sommergibile fermo. Si scappa mentre la scorta bombarda la nuvola.',
            ],
            'munizioni_flak' => [
                'nome' => 'Munizioni antiaeree', 'unita' => 'colpi', 'spazio' => 0.02, 'max' => 4000,
                'note' => 'Restare a combattere contro un aereo e\' quasi sempre l\'errore che uccide. Quasi.',
            ],
        ];
    }

    /** Spazio disponibile a bordo, in unita' di stiva. */
    public static function capacita(array $type): int
    {
        return (int) round((float) $type['disp_surf_t'] * 0.55);
    }

    /** Carico consigliato: quello che avrebbe imbarcato la flottiglia. */
    public static function standard(array $type): array
    {
        $haCannone = !empty($type['deck_gun']);
        return [
            'viveri'            => (int) $type['provisions_days'],
            'ricambi'           => 12,
            'potassa'           => 90,
            'ossigeno'          => 12,
            'munizioni_cannone' => $haCannone ? 120 : 0,
            'munizioni_flak'    => 1200,
            'bold'              => 12,
        ];
    }

    /** @return array<string,float> quantita' attuali */
    public static function stores(int $boatId): array
    {
        $out = [];
        foreach (Database::all('SELECT item_key, qty, qty_max FROM boat_stores WHERE boat_id = ?', [$boatId]) as $r) {
            $out[(string) $r['item_key']] = (float) $r['qty'];
        }
        return $out;
    }

    /** Spazio occupato da un carico. */
    public static function spazioUsato(array $carico): float
    {
        $voci = self::voci();
        $tot = 0.0;
        foreach ($carico as $k => $q) {
            $tot += max(0.0, (float) $q) * ($voci[$k]['spazio'] ?? 0);
        }
        return round($tot, 1);
    }

    /**
     * Imbarca un carico. Restituisce l'errore se non ci sta o se supera i
     * limiti di una singola voce.
     *
     * @param array<string,int|float> $carico
     * @return array{ok:bool, error?:string, spazio?:float, capacita?:int}
     */
    public static function load(array $boat, array $type, array $carico): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'L\'allestimento si fa in bunker, non in mare.'];
        }

        $voci = self::voci();
        $pulito = [];
        foreach ($voci as $k => $v) {
            $q = (float) ($carico[$k] ?? 0);
            if ($k === 'munizioni_cannone' && empty($type['deck_gun'])) {
                $q = 0;
            }
            if ($k === 'viveri') {
                $q = min($q, (float) $type['provisions_days']);
            }
            $pulito[$k] = max(0.0, min((float) $v['max'], $q));
        }

        $spazio = self::spazioUsato($pulito);
        $capacita = self::capacita($type);
        if ($spazio > $capacita) {
            return ['ok' => false, 'error' => sprintf(
                'Non ci sta: servono %.0f unita\' di stiva e ce ne sono %d. Qualcosa va lasciato a terra.',
                $spazio, $capacita
            )];
        }

        foreach ($pulito as $k => $q) {
            Database::run(
                'INSERT INTO boat_stores (boat_id, item_key, qty, qty_max) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), qty_max = VALUES(qty_max)',
                [(int) $boat['id'], $k, $q, $q]
            );
        }
        Database::run('UPDATE boats SET provisions_days = ? WHERE id = ?', [$pulito['viveri'], (int) $boat['id']]);

        return ['ok' => true, 'spazio' => $spazio, 'capacita' => $capacita];
    }

    /** Consuma una dotazione, senza scendere sotto zero. */
    public static function consume(int $boatId, string $item, float $qta): void
    {
        if ($qta <= 0) {
            return;
        }
        Database::run(
            'UPDATE boat_stores SET qty = GREATEST(0, qty - ?) WHERE boat_id = ? AND item_key = ?',
            [$qta, $boatId, $item]
        );
    }

    /**
     * Cambio di battello in bunker. Il tipo dev'essere sbloccato (in F5 lo
     * decidera' il grado; qui si usa unlock_rank come soglia provvisoria).
     */
    public static function changeType(array $boat, string $typeKey, int $rank, string $projectRoot): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'Il cambio di battello si fa in bunker.'];
        }
        $tipi = World::types();
        $type = $tipi[$typeKey] ?? null;
        if ($type === null || (int) $type['playable'] !== 1) {
            return ['ok' => false, 'error' => 'Tipo non disponibile.'];
        }
        if ((int) $type['unlock_rank'] > $rank) {
            return ['ok' => false, 'error' => 'Non hai ancora l\'anzianita\' per questo battello.'];
        }

        Database::run(
            'UPDATE boats SET type_key = ?, fuel_t = ?, provisions_days = ?, battery_pct = 100, air_pct = 100,
                    co2_pct = 0, hull_integrity = 100, hull_stress = 0, version = version + 1
             WHERE id = ?',
            [$typeKey, (float) $type['fuel_t'], (float) $type['provisions_days'], (int) $boat['id']]
        );

        // Compartimenti e sistemi cambiano col battello.
        Database::run('DELETE FROM boat_compartments WHERE boat_id = ?', [(int) $boat['id']]);
        Database::run('DELETE FROM boat_systems WHERE boat_id = ?', [(int) $boat['id']]);
        Damage::install((int) $boat['id'], $type, $projectRoot);

        // L'equipaggio segue il comandante, ma l'organico si adegua al nuovo tipo.
        Crew::adeguaOrganico((int) $boat['id'], $type);

        self::load(array_merge($boat, ['type_key' => $typeKey]), $type, self::standard($type));

        return ['ok' => true, 'tipo' => $type['name']];
    }
}
