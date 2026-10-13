<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\BoatSim;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Sectors;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * I naufraghi.
 *
 * Dopo un affondamento in mare restano le scialuppe, e che cosa farne era una
 * scelta del comandante. Molti davano la rotta per la terra piu' vicina, acqua
 * e gallette; alcuni prendevano a bordo il comandante o il direttore di
 * macchina, che interrogati a volte parlavano; i piu' se ne andavano, perche'
 * restare in superficie vicino al punto di un affondamento era il modo piu'
 * sicuro di farsi trovare. Dopo l'affare del Laconia (settembre 1942) Doenitz
 * proibi' ogni soccorso. Qui il mondo e' un eterno 1942, e il BdU non premia ne'
 * punisce: la scelta resta al comandante.
 *
 *   - le scialuppe stanno sul punto dell'affondamento; quanti ci sono saliti
 *     dipende da come e' andata giu' la nave e dal mare;
 *   - entro sei ore, in superficie e a due miglia, si puo' aiutarli (rotta per
 *     il porto o la costa piu' vicina, acqua e gallette: costa viveri, scalda il
 *     settore, e all'equipaggio fa bene) o prendere a bordo il comandante, che
 *     forse dice qualcosa di un convoglio;
 *   - giorni dopo se ne viene a sapere la sorte: piu' vicini alle rotte e alle
 *     coste, e con la rotta giusta, si salvano in piu'.
 */
final class Naufraghi
{
    private const FINESTRA_S = 6 * 3600;
    private const RAGGIO_NM = 2.0;

    /** Uomini a bordo, per genere di nave. */
    private const EQUIPAGGI = ['mercantile' => [32, 46], 'petroliera' => [38, 50], 'trasporto' => [120, 400],
        'ausiliaria' => [20, 60], 'scorta' => [60, 140]];

    /** Una nave e' andata giu': le scialuppe. */
    public static function nascono(int $sinkingId, array $boat, ?int $patrolId, array $e, int $gts, bool $esplosa): void
    {
        $cls = Traffic::classe((string) $e['class_key']);
        $rng = Rng::for(World::seed(), 'naufraghi', $sinkingId);
        [$min, $max] = self::EQUIPAGGI[(string) $cls['kind']] ?? [30, 45];
        $uomini = $rng->int($min, $max);
        $mare = (int) World::weather((float) $e['lat'], (float) $e['lon'], $gts)['sea_state'];
        // Chi sale sulle scialuppe: poco o niente se il carico e' saltato, meno
        // col mare grosso; il resto e' rimasto a bordo, o in acqua.
        $quota = $esplosa ? $rng->range(0.0, 0.2) : max(0.2, $rng->range(0.6, 0.95) - 0.06 * max(0, $mare - 3));
        $vivi = (int) round($uomini * $quota);
        if ($vivi === 0) {
            return;
        }
        Database::run(
            'INSERT IGNORE INTO naufraghi (sinking_id, boat_id, patrol_id, commander_id, nave, lat, lon, gts, equipaggio,
                                           scialuppe, nelle_scialuppe, destino_gts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sinkingId, (int) $boat['id'], $patrolId, $boat['commander_id'], (string) $e['name'],
             (float) $e['lat'], (float) $e['lon'], $gts, $uomini, max(1, (int) ceil($vivi / 20)), $vivi,
             $gts + $rng->int(2, 8) * 86400]
        );
    }

    /**
     * Le scialuppe che il battello puo' raggiungere adesso: sue, entro sei ore,
     * e a portata di vista.
     *
     * @return list<array<string,mixed>>
     */
    public static function inVista(array $boat, int $gts): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT * FROM naufraghi WHERE boat_id = ? AND stato = 'in_mare' AND gts >= ? ORDER BY gts DESC",
            [(int) $boat['id'], $gts - self::FINESTRA_S]
        ) as $n) {
            $n['distanza'] = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $n['lat'], (float) $n['lon']);
            if ($n['distanza'] <= 8.0) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Che cosa manca per avvicinarsi alle scialuppe.
     *
     * @return list<string>
     */
    public static function manca(array $boat, array $n): array
    {
        $manca = [];
        if ($boat['encounter_id'] !== null) {
            $manca[] = 'chiudere l\'incontro';
        }
        if ((string) $boat['mode'] !== 'superficie') {
            $manca[] = 'emergere';
        }
        if ((float) $n['distanza'] > self::RAGGIO_NM) {
            $manca[] = sprintf('avvicinarsi a due miglia (sono a %.1f)', (float) $n['distanza']);
        }
        return $manca;
    }

    /**
     * Dare la rotta, acqua e gallette.
     *
     * @return array{ok:bool, error?:string, testo?:string}
     */
    public static function aiuta(array $boat, int $id, int $gts): array
    {
        $n = self::trova($boat, $id, $gts);
        if ($n === null) {
            return ['ok' => false, 'error' => 'Le scialuppe non sono piu\' raggiungibili.'];
        }
        if ((int) $n['aiutati'] === 1) {
            return ['ok' => false, 'error' => 'Hanno gia\' avuto la rotta e quello che si poteva dare.'];
        }
        if (($manca = self::manca($boat, $n)) !== []) {
            return ['ok' => false, 'error' => 'Prima: ' . implode('; ', $manca) . '.'];
        }
        [$porto, $d, $ril] = self::portoVicino((float) $n['lat'], (float) $n['lon']);
        if (Database::run('UPDATE naufraghi SET aiutati = 1 WHERE id = ? AND aiutati = 0', [$id])->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Hanno gia\' avuto la rotta e quello che si poteva dare.'];
        }
        Database::run('UPDATE boats SET provisions_days = GREATEST(0, provisions_days - 0.3) WHERE id = ?', [(int) $boat['id']]);
        Database::run("UPDATE crew_members SET morale = LEAST(100, morale + 3) WHERE boat_id = ? AND health <> 'morto'", [(int) $boat['id']]);
        // Mezz'ora fermi in superficie sul punto di un affondamento: se ne
        // accorge qualcuno.
        Sectors::add((float) $boat['lat'], (float) $boat['lon'], 6.0, $gts, 'battello fermo sul punto di un affondamento');
        $testo = sprintf(
            'Ci avviciniamo alle scialuppe della %s: %d uomini. Il nostromo passa acqua, gallette e una bussola; '
            . 'il comandante da\' la rotta per %s, %.0f miglia per %03.0f. Gli uomini in coperta non dicono niente, '
            . 'ma la sera in mensa si parla d\'altro.',
            (string) $n['nave'], (int) $n['nelle_scialuppe'], $porto, $d, $ril
        );
        self::giornale($boat, $gts, $testo);
        return ['ok' => true, 'testo' => $testo];
    }

    /**
     * Prendere a bordo il comandante della nave.
     *
     * @return array{ok:bool, error?:string, testo?:string}
     */
    public static function prendiComandante(array $boat, int $id, int $gts): array
    {
        $n = self::trova($boat, $id, $gts);
        if ($n === null) {
            return ['ok' => false, 'error' => 'Le scialuppe non sono piu\' raggiungibili.'];
        }
        if ((int) $n['comandante_preso'] === 1) {
            return ['ok' => false, 'error' => 'Il comandante e\' gia\' a bordo.'];
        }
        if (($manca = self::manca($boat, $n)) !== []) {
            return ['ok' => false, 'error' => 'Prima: ' . implode('; ', $manca) . '.'];
        }
        if (Database::run('UPDATE naufraghi SET comandante_preso = 1, nelle_scialuppe = GREATEST(0, nelle_scialuppe - 1)
                            WHERE id = ? AND comandante_preso = 0', [$id])->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Il comandante e\' gia\' a bordo.'];
        }
        if ($boat['commander_id'] !== null) {
            Database::run('UPDATE commanders SET prestigio = prestigio + 40, prestigio_tot = prestigio_tot + 40 WHERE id = ?',
                [(int) $boat['commander_id']]);
        }
        // Interrogato, forse parla del convoglio piu' vicino.
        $rng = Rng::for(World::seed(), 'interrogatorio', $id);
        $base = World::port((string) $boat['home_port_key']);
        $testo = sprintf('Il comandante della %s sale a bordo: sara\' consegnato al rientro, a %s.',
            (string) $n['nave'], (string) ($base['name'] ?? 'la base'));
        $vicino = null;
        foreach (Traffic::nearby((float) $boat['lat'], (float) $boat['lon'], 800.0, $gts) as $u) {
            if ((string) $u['kind'] === 'convoglio' && ($vicino === null || $u['distanza'] < $vicino['distanza'])) {
                $vicino = $u;
            }
        }
        if ($vicino !== null && $rng->chance(0.5)) {
            $testo .= sprintf(' Interrogato, parla: un convoglio, il %s, dovrebbe trovarsi in %s, rotta %03.0f. (+40 prestigio)',
                (string) $vicino['nome'], Grid::toQuadrat((float) $vicino['lat'], (float) $vicino['lon'], 2) ?? '—', (float) $vicino['heading']);
        } else {
            $testo .= ' Interrogato, non dice niente che serva. (+40 prestigio)';
        }
        self::giornale($boat, $gts, $testo);
        return ['ok' => true, 'testo' => $testo];
    }

    /**
     * Il battito: la sorte delle scialuppe, giorni dopo.
     *
     * Si salvano in piu' vicino alle rotte e ai porti, d'estate e al sud, e con
     * la rotta giusta. La notizia arriva a chi le ha lasciate in mare.
     *
     * @return array{decisi:int}
     */
    public static function destino(int $gts): array
    {
        $n = 0;
        foreach (Database::all("SELECT * FROM naufraghi WHERE stato = 'in_mare' AND destino_gts <= ? LIMIT 50", [$gts]) as $r) {
            $rng = Rng::for(World::seed(), 'destino', (int) $r['id']);
            [$porto, $d] = self::portoVicino((float) $r['lat'], (float) $r['lon']);
            $vicinoRotta = Traffic::nearby((float) $r['lat'], (float) $r['lon'], 150.0, (int) $r['gts']) !== [];
            $p = 0.25 + ($vicinoRotta ? 0.3 : 0.0) + max(0.0, 0.25 * (1.0 - $d / 600.0))
                - max(0.0, ((float) $r['lat'] - 50.0) * 0.012)
                + ((int) $r['aiutati'] === 1 ? 0.2 : 0.0);
            $p = max(0.05, min(0.95, $p));
            $salvati = 0;
            for ($i = 0; $i < (int) $r['nelle_scialuppe']; $i++) {
                $salvati += $rng->chance($p) ? 1 : 0;
            }
            Database::run(
                'UPDATE naufraghi SET stato = ?, salvati = ? WHERE id = ?',
                [$salvati > 0 ? 'raccolti' : 'perduti', $salvati, (int) $r['id']]
            );
            $testo = $salvati > 0
                ? sprintf('Dalla stampa nemica: dei %d uomini della %s, %d sono stati raccolti dopo %d giorni in mare.',
                    (int) $r['equipaggio'], (string) $r['nave'], $salvati, intdiv($gts - (int) $r['gts'], 86400))
                : sprintf('Della %s non si e\' piu\' saputo niente: nessuna scialuppa e\' stata ritrovata.', (string) $r['nave']);
            Database::run(
                'INSERT INTO radio_messages (destinatario, dest_boat_id, tipo, testo, gts, durata_s) VALUES ("battello", ?, "comunicato", ?, ?, 0)',
                [(int) $r['boat_id'], $testo, $gts]
            );
            $boat = Database::first("SELECT * FROM boats WHERE id = ? AND state = 'mare'", [(int) $r['boat_id']]);
            if ($boat !== null) {
                self::giornale($boat, $gts, $testo);
            }
            $n++;
        }
        return ['decisi' => $n];
    }

    /** @return array{0:string,1:float,2:float} il porto o la costa amica piu' vicina, distanza e rilevamento */
    private static function portoVicino(float $lat, float $lon): array
    {
        $meglio = ['la costa', INF, 0.0];
        foreach (World::ports() as $p) {
            if ((string) $p['kind'] === 'base') {
                continue;               // non li si manda a Lorient
            }
            $d = Geo::distanceNm($lat, $lon, (float) $p['lat'], (float) $p['lon']);
            if ($d < $meglio[1]) {
                $meglio = [(string) $p['name'], $d, Geo::bearing($lat, $lon, (float) $p['lat'], (float) $p['lon'])];
            }
        }
        return $meglio;
    }

    private static function trova(array $boat, int $id, int $gts): ?array
    {
        foreach (self::inVista($boat, $gts) as $n) {
            if ((int) $n['id'] === $id) {
                return $n;
            }
        }
        return null;
    }

    private static function giornale(array $boat, int $gts, string $testo): void
    {
        $patrol = Patrol::corrente((int) $boat['id']);
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'naufraghi', 'severity' => 'nota', 'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']), 'text' => $testo,
            ], (int) $patrol['id'], (int) $boat['id']);
        }
    }
}
