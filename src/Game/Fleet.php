<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\Rng;
use App\Sim\Torpedo;
use App\Sim\World;

/**
 * Assegnazione del battello e della flottiglia.
 *
 * In F1 ogni account riceve un battello d'esordio: un Tipo VII B stanco, come
 * toccava a un comandante nuovo. La scelta del tipo, l'allestimento e i gradi
 * arrivano in F5; qui serve solo avere un battello con cui uscire in mare.
 */
final class Fleet
{
    /**
     * Intervalli di numerazione storicamente plausibili per tipo.
     *
     * Non sono inventati: la Kriegsmarine assegnava i numeri per commessa, e a
     * un tipo corrispondono blocchi precisi. U-45..U-55, U-73..U-76, U-83..U-87
     * e U-99..U-102 sono tutti Tipo VII B — e infatti U-99 era di Kretschmer e
     * U-100 di Schepke. U-2501 in su sono i Tipo XXI, gli ultimi.
     *
     * Correzione del 18/09/2026: fra i IID c'era anche U-56..U-63, che erano
     * pero' dei II C. La serie D vera comincia da U-137.
     *
     * @var array<string,list<array{0:int,1:int}>>
     */
    private const NUMERI = [
        'IID'     => [[137, 152]],
        'VIIB'    => [[45, 55], [73, 76], [83, 87], [99, 102]],
        'VIIC'    => [[69, 72], [77, 82], [88, 98], [201, 300], [351, 458]],
        'VIIC41'  => [[292, 300], [925, 1058]],
        'IXB'     => [[64, 65], [103, 111], [122, 124]],
        'IXC40'   => [[167, 170], [183, 194], [525, 550]],
        'IXD2'    => [[177, 182], [195, 200], [847, 852]],
        'XXI'     => [[2501, 2600]],
    ];

    /** Battello assegnato a un utente (al piu' uno attivo per account). */
    public static function boatOf(int $userId): ?array
    {
        return Database::first(
            "SELECT * FROM boats WHERE user_id = ? AND state <> 'perduto' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /**
     * Completa un battello a cui manca qualcosa.
     *
     * I battelli assegnati prima che esistessero compartimenti, sistemi ed
     * equipaggio (F1) non hanno nulla di tutto cio': qui vengono allestiti
     * senza perdere la missione in corso. E' idempotente: su un battello
     * completo non fa niente.
     */
    public static function ensureFitted(array $boat): array
    {
        $id = (int) $boat['id'];
        $type = World::type((string) $boat['type_key']);
        $root = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        $toccato = false;

        $comp = (int) (Database::first('SELECT COUNT(*) n FROM boat_compartments WHERE boat_id = ?', [$id])['n'] ?? 0);
        if ($comp === 0) {
            Damage::install($id, $type, $root);
            $toccato = true;
        }

        $uomini = (int) (Database::first(
            "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health <> 'morto'",
            [$id]
        )['n'] ?? 0);
        if ($uomini === 0) {
            Crew::generate($id, $type, World::now());
            $toccato = true;
        }

        $siluri = (int) (Database::first('SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ?', [$id])['n'] ?? 0);
        if ($siluri === 0) {
            Torpedo::imbarca($id, $type, Torpedo::caricoStandard($type, 0));
            $toccato = true;
        }

        $scorte = (int) (Database::first('SELECT COUNT(*) n FROM boat_stores WHERE boat_id = ?', [$id])['n'] ?? 0);
        if ($scorte === 0) {
            \App\Game\Outfitting::load(
                ['id' => $id, 'state' => 'base'],
                $type,
                \App\Game\Outfitting::standard($type)
            );
            $toccato = true;
        }

        $finale = $toccato
            ? (Database::first('SELECT * FROM boats WHERE id = ?', [$id]) ?? $boat)
            : $boat;

        // La barra di navigazione mostra la stazione d'attacco solo quando c'e'
        // davvero qualcosa da combattere.
        $GLOBALS['__incontro'] = $finale['encounter_id'] !== null;

        // E le stazioni che esistono solo in mare — centrale, carteggio,
        // ascolto — in porto si mostrano spente invece che rimandare in
        // silenzio alla flottiglia, che sembrava un guasto.
        $GLOBALS['__in_mare'] = (string) $finale['state'] === 'mare';

        return $finale;
    }

    /** Assegna il battello d'esordio se l'utente non ne ha. */
    public static function ensureBoat(int $userId, string $typeKey = 'VIIB', string $baseKey = 'lorient'): array
    {
        $esistente = self::boatOf($userId);
        if ($esistente !== null) {
            return self::ensureFitted($esistente);
        }

        $type = World::type($typeKey);
        $cmd  = \App\Game\Comandante::corrente($userId);

        // La base la sceglie il comandante quando lo si crea, e il battello
        // glielo si assegna LI'. Prima si guardava solo il parametro, che
        // nessuno passava: chiunque avesse scelto Bordeaux o Bergen si
        // ritrovava ormeggiato a Lorient, col fascicolo che dichiarava una
        // flottiglia e il battello che ne portava un'altra.
        $chiave = $cmd !== null && (string) ($cmd['base_key'] ?? '') !== ''
            ? (string) $cmd['base_key']
            : $baseKey;

        $base = World::port($chiave) ?? World::port($baseKey) ?? World::port('lorient');
        if ($base === null) {
            throw new \RuntimeException('Nessuna base disponibile: eseguire "php bin/console.php world:seed".');
        }

        $numero = self::assegnaNumero($typeKey, $userId);

        Database::run(
            'INSERT INTO boats (user_id, commander_id, type_key, uboat_number, flotilla, home_port_key, state,
                                lat, lon, est_lat, est_lon, heading, fuel_t, battery_pct, air_pct,
                                provisions_days, last_sim_gts)
             VALUES (?, ?, ?, ?, ?, ?, "base", ?, ?, ?, ?, 0, ?, 100, 100, ?, ?)',
            [
                $userId, $cmd !== null ? (int) $cmd['id'] : null, $typeKey, $numero,
                (string) ($base['flotillas'] ?? 'U-Flottille'),
                (string) $base['port_key'],
                (float) $base['lat'], (float) $base['lon'],
                (float) $base['lat'], (float) $base['lon'],
                (float) $type['fuel_t'], (float) $type['provisions_days'],
                World::now(),
            ]
        );

        $boatId = Database::lastInsertId();

        // Il battello non e' una riga di tabella: ha compartimenti, sistemi che
        // si possono rompere, un equipaggio con nome e cognome, e una stiva.
        $root = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        Damage::install($boatId, $type, $root);
        Crew::generate($boatId, $type, World::now());
        Torpedo::imbarca($boatId, $type, Torpedo::caricoStandard($type, 0));
        \App\Game\Outfitting::load(
            ['id' => $boatId, 'state' => 'base'],
            $type,
            \App\Game\Outfitting::standard($type)
        );

        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
        if ($boat === null) {
            throw new \RuntimeException('Assegnazione del battello non riuscita.');
        }
        return $boat;
    }

    /** Un numero libero dentro gli intervalli del tipo. */
    private static function assegnaNumero(string $typeKey, int $userId): string
    {
        $intervalli = self::NUMERI[$typeKey] ?? [[201, 999]];
        $rng = Rng::for(World::seed(), 'numero', $userId, $typeKey);

        for ($tentativi = 0; $tentativi < 200; $tentativi++) {
            [$da, $a] = $intervalli[$rng->int(0, count($intervalli) - 1)];
            $n = 'U-' . $rng->int($da, $a);
            // Un numero appartiene solo a un battello ancora a galla: quando
            // un U-Boot si perde il suo numero torna al mare, come e' giusto
            // in un mondo che va avanti per anni.
            $preso = Database::first(
                "SELECT id FROM boats WHERE uboat_number = ? AND state <> 'perduto'", [$n]
            );
            if ($preso === null) {
                return $n;
            }
        }
        // Tutti presi: si scende sotto la numerazione storica invece di fallire.
        return 'U-' . (9000 + $userId);
    }
}
