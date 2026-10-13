<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\BoatSim;
use App\Sim\Encounter;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Torpedo;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * La dottrina d'ingaggio: il I.WO al posto del comandante.
 *
 * Una delle quattro decisioni fondanti di Atlantik: il mondo non aspetta, e se
 * il comandante non c'e' il battello lo conduce il Primo Ufficiale, secondo la
 * dottrina che il comandante gli ha lasciato. Fino al 09/10/2026 il I.WO sapeva
 * soltanto disimpegnare: un attacco non lo cominciava mai, e un convoglio
 * passato mentre il comandante dormiva passava e basta.
 *
 * Tre dottrine, scelte dal comandante per il suo battello:
 *
 *   - prudente: il I.WO non attacca. Tiene la guardia, e basta. E' il default;
 *   - isolate: attacca le navi sole, fuori dai convogli e senza scorta;
 *   - convogli: attacca anche i convogli.
 *
 * Il I.WO attacca come attaccherebbe un ufficiale giovane e prudente:
 * avvicinamento in superficie di notte e a quota periscopica di giorno, verso un
 * punto davanti al bersaglio; un ventaglio di tre siluri quando il bersaglio e'
 * vicino e mostra il fianco, con la stima della distanza, dell'angolo e della
 * velocita' fatta da lui (Torpedo::stimaIwo, con i suoi errori); poi giu', piano
 * e in silenzio, lontano dalla scorta piu' vicina. Una salva per incontro: il
 * secondo attacco, a convoglio in allarme, e' una scelta da comandante.
 *
 * Il comandante che torna riprende il comando appena apre la stazione
 * d'attacco (encounters.condotta). I colleghi mossi dal motore (Colleghi) non
 * hanno un comandante che torna: per loro il I.WO e' il comandante.
 */
final class Dottrina
{
    public const DOTTRINE = [
        'prudente' => ['nome' => 'Prudente', 'spiega' => 'il I.WO non attacca: tiene la guardia, si immerge davanti agli aerei, e ti aspetta'],
        'isolate'  => ['nome' => 'Navi isolate', 'spiega' => 'il I.WO attacca le navi sole, senza scorta; i convogli li lascia a te'],
        'convogli' => ['nome' => 'Convogli', 'spiega' => 'il I.WO attacca anche i convogli, con un ventaglio, e poi scende'],
    ];

    /** Quando lanciare: entro questa distanza, col bersaglio che mostra il fianco. */
    private const LANCIO_NM = 1.1;
    /** ...e comunque entro questa, qualunque sia l'angolo. */
    private const LANCIO_SUBITO_NM = 0.6;
    /** Senza un lancio, dopo quanto (tempo d'incontro) il I.WO rompe il contatto; e in ogni caso. */
    private const RINUNCIA_S = 6 * 3600;
    private const TETTO_S = 8 * 3600;

    /** La dottrina di un battello. I colleghi attaccano i convogli. */
    public static function di(array $boat): string
    {
        if (Colleghi::eCollega($boat)) {
            return 'convogli';
        }
        $d = (string) ($boat['dottrina'] ?? 'prudente');
        return isset(self::DOTTRINE[$d]) ? $d : 'prudente';
    }

    /** @return array{ok:bool, error?:string} */
    public static function imposta(int $boatId, string $dottrina): array
    {
        if (!isset(self::DOTTRINE[$dottrina])) {
            return ['ok' => false, 'error' => 'Dottrina sconosciuta.'];
        }
        Database::run('UPDATE boats SET dottrina = ? WHERE id = ?', [$dottrina, $boatId]);
        return ['ok' => true];
    }

    /**
     * Il comandante non e' alla console. Un collega non c'e' mai.
     */
    public static function assente(array $boat): bool
    {
        if (Colleghi::eCollega($boat)) {
            return true;
        }
        $u = Database::first(
            'SELECT last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) AS presente FROM users WHERE id = ?',
            [max(0, GameConfig::int('avvisi.presenza_s', 180)), (int) $boat['user_id']]
        );
        return $u === null || (int) $u['presente'] !== 1;
    }

    /**
     * Il battito: per ogni battello in mare il cui comandante non c'e', se la
     * dottrina lo permette e c'e' un bersaglio a portata, il I.WO da' battaglia.
     *
     * @return array{aperti:int}
     */
    public static function cerca(int $gts): array
    {
        $aperti = 0;
        $raggio = (float) GameConfig::get('combat.raggio_nm', 10.0);
        $battelli = Database::all(
            "SELECT b.* FROM boats b
              WHERE b.state = 'mare' AND b.encounter_id IS NULL
                AND (b.dottrina <> 'prudente' OR EXISTS (SELECT 1 FROM users u WHERE u.id = b.user_id AND u.role = 'motore'))
                AND NOT EXISTS (SELECT 1 FROM patrols p WHERE p.boat_id = b.id AND p.state = 'in_corso' AND p.tipo = 'addestramento')"
        );
        foreach ($battelli as $boat) {
            $dottrina = self::di($boat);
            if ($dottrina === 'prudente' || !self::assente($boat) || !self::pronto($boat)) {
                continue;
            }
            $bersaglio = self::bersaglio($boat, $dottrina, $gts, $raggio);
            if ($bersaglio === null) {
                continue;
            }
            $res = Encounter::apri($boat, ['convoy_id' => $bersaglio['convoy_id'], 'ship_id' => $bersaglio['ship_id']], $gts);
            if (!$res['ok']) {
                continue;
            }
            Database::run("UPDATE encounters SET condotta = 'iwo' WHERE id = ?", [(int) $res['encounter_id']]);
            $patrol = Patrol::corrente((int) $boat['id']);
            if ($patrol !== null) {
                BoatSim::save([
                    'gts' => $gts, 'kind' => 'iwo', 'severity' => 'attenzione',
                    'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                    'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                    'text' => Colleghi::eCollega($boat)
                        ? sprintf('Si da\' battaglia %s.', $bersaglio['nome'])
                        : sprintf(
                            'Il comandante non e\' in plancia. Secondo la dottrina lasciata («%s»), il I.WO da\' battaglia %s.',
                            mb_strtolower(self::DOTTRINE[$dottrina]['nome']), $bersaglio['nome']
                        ),
                    'avviso' => 'contatti',
                ], (int) $patrol['id'], (int) $boat['id']);
            }
            $aperti++;
        }
        return ['aperti' => $aperti];
    }

    /** Siluri nei tubi, batteria e scafo per un attacco. */
    private static function pronto(array $boat): bool
    {
        return Torpedo::tubiPronti((int) $boat['id']) !== []
            && (float) $boat['battery_pct'] >= 35.0
            && (float) $boat['hull_stress'] < 60.0;
    }

    /**
     * Il bersaglio piu' vicino fra i contatti in mano, se la dottrina lo
     * consente e se e' a portata (la distanza vera: e' quella che controlla
     * l'apertura dell'incontro).
     *
     * @return array{convoy_id:?int, ship_id:?int, nome:string}|null
     */
    private static function bersaglio(array $boat, string $dottrina, int $gts, float $raggio): ?array
    {
        $contatti = Database::all(
            "SELECT * FROM contacts WHERE boat_id = ? AND perso = 0 AND last_gts >= ?
               AND (convoy_id IS NOT NULL OR ship_id IS NOT NULL)",
            [(int) $boat['id'], $gts - 3600]
        );
        $meglio = null;
        foreach ($contatti as $c) {
            if ($c['convoy_id'] !== null) {
                if ($dottrina !== 'convogli') {
                    continue;
                }
                $cv = Database::first("SELECT * FROM convoys WHERE id = ? AND state = 'in_mare'", [(int) $c['convoy_id']]);
                if ($cv === null || (int) $cv['esercitazione'] === 1) {
                    continue;
                }
                $p = Traffic::pos($cv, $gts);
                $nome = sprintf('al convoglio %s %d', (string) $cv['serie'], (int) $cv['numero']);
            } else {
                $sh = Database::first("SELECT * FROM ships WHERE id = ? AND state = 'in_mare'", [(int) $c['ship_id']]);
                // Solo navi sole e non militari: una scorta isolata non e' un bersaglio da I.WO.
                if ($sh === null || $sh['convoy_id'] !== null || (string) Traffic::classe((string) $sh['class_key'])['kind'] === 'scorta') {
                    continue;
                }
                $p = Traffic::pos($sh, $gts);
                $nome = 'a una nave isolata' . ((string) $c['classe_est'] !== '' ? ' (' . (string) $c['classe_est'] . ')' : '');
            }
            if ($p === null) {
                continue;
            }
            $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $p['lat'], (float) $p['lon']);
            if ($d <= $raggio && ($meglio === null || $d < $meglio['d'])) {
                $meglio = ['convoy_id' => $c['convoy_id'] !== null ? (int) $c['convoy_id'] : null,
                    'ship_id' => $c['ship_id'] !== null ? (int) $c['ship_id'] : null, 'nome' => $nome, 'd' => $d];
            }
        }
        if ($meglio === null) {
            return null;
        }
        unset($meglio['d']);
        return $meglio;
    }

    /**
     * Una decisione del I.WO in un incontro che conduce lui. La chiama il
     * battito, prima di far avanzare l'incontro.
     *
     * @return array{fase:string, testo?:string}
     */
    public static function conduci(int $encId): array
    {
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        if ($enc === null || (string) $enc['stato'] === 'concluso' || (string) $enc['condotta'] !== 'iwo') {
            return ['fase' => 'nessuna'];
        }
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $enc['boat_id']]);
        if ($boat === null || (string) $boat['state'] !== 'mare') {
            return ['fase' => 'nessuna'];
        }
        $type = World::type((string) $boat['type_key']);
        $gts = (int) $enc['last_step_gts'];
        $entita = Encounter::entita($encId);

        $lanciati = (int) Database::first('SELECT COUNT(*) n FROM torpedo_runs WHERE encounter_id = ?', [$encId])['n'];
        $durata = $gts - (int) $enc['started_gts'];

        // Un tetto al tempo. Nei primi quattro giorni in esercizio un collega
        // ha inseguito una nave isolata per novantun ore d'incontro senza mai
        // arrivare a tiro (13/10/2026): l'incontro non si chiudeva, il
        // battello restava fermo nel tempo del mondo e ogni battito ne
        // recuperava centinaia di passi. Un ufficiale vero, dopo sei ore
        // senza un lancio, rompe il contatto; dopo otto in tutto, comunque.
        if (($lanciati === 0 && (int) $enc['allarme'] === 0 && $durata > self::RINUNCIA_S) || $durata > self::TETTO_S) {
            return self::rinuncia($enc, $boat, $gts, $lanciati > 0
                ? 'Il I.WO rompe il contatto: il battello si allontana in silenzio.'
                : 'Il I.WO rinuncia: in sei ore il bersaglio non si e\' lasciato portare a tiro.');
        }

        if ($lanciati > 0 || (int) $enc['allarme'] === 1) {
            return self::evadi($enc, $boat, $type, $entita);
        }

        // --- il bersaglio: il piu' grosso fra i vicini ---------------------------
        $bersaglio = null;
        $punteggio = -1.0;
        foreach ($entita as $e) {
            if ((string) $e['ruolo'] === 'scorta') {
                continue;
            }
            $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
            $p = (float) $e['grt'] / (1.0 + $d);
            if ($p > $punteggio) {
                $punteggio = $p;
                $bersaglio = $e + ['d' => $d];
            }
        }
        if ($bersaglio === null) {
            return ['fase' => 'nessuna'];
        }

        $d = (float) $bersaglio['d'];
        $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], $gts);
        $notte = (float) $cielo['luce'] < 0.25;

        // Di giorno l'attacco e' in immersione: senza batteria non si comincia.
        if (!$notte && (float) $boat['battery_pct'] < 20.0) {
            return self::rinuncia($enc, $boat, $gts, 'Il I.WO rinuncia: di giorno l\'attacco si fa sotto, e la batteria e\' quasi vuota.');
        }

        // --- il lancio ---------------------------------------------------------------
        // Di notte si lancia dalla superficie: era l'attacco degli assi, e
        // l'angolo conta meno perche' ci si avvicina a piacere.
        $ril = Geo::bearing((float) $boat['lat'], (float) $boat['lon'], (float) $bersaglio['lat'], (float) $bersaglio['lon']);
        $aob = abs(Geo::bearingDelta((float) $bersaglio['heading'], Geo::normBearing($ril + 180.0)));
        $aQuota = (float) $boat['depth_m'] <= 16.0;
        $angoloMin = $notte ? 25.0 : 40.0;
        if ($aQuota && ($d <= self::LANCIO_SUBITO_NM || ($d <= self::LANCIO_NM && $aob >= $angoloMin && $aob <= 180.0 - $angoloMin))) {
            $tubi = array_slice(array_map(static fn (array $t): int => (int) $t['tubo'], Torpedo::tubiPronti((int) $boat['id'])), 0, 3);
            if ($tubi !== []) {
                $r = Encounter::lancia($enc, $boat, [
                    'entity_id' => (int) $bersaglio['id'], 'tubi' => $tubi, 'spoletta' => 'contatto',
                    'quota' => (int) $bersaglio['grt'] < 3000 ? 3 : 4, 'ventaglio' => count($tubi) > 1 ? 2.0 : 0.0,
                ], $gts);
                if ($r['ok']) {
                    return ['fase' => 'lancio', 'testo' => (string) $r['testo']];
                }
            }
        }

        // --- l'avvicinamento -----------------------------------------------------------
        // Verso un punto davanti al bersaglio: un convoglio non lo si insegue,
        // lo si aspetta sulla sua rotta.
        [$pla, $plo] = Geo::destination((float) $bersaglio['lat'], (float) $bersaglio['lon'],
            (float) $bersaglio['heading'], max(0.3, min(2.0, $d * 0.5)));
        $rotta = Geo::bearing((float) $boat['lat'], (float) $boat['lon'], $pla, $plo);
        // Di notte in superficie fino al lancio; di giorno a quota periscopica,
        // e piu' piano da vicino. Prima, di notte, sotto le miglia e mezza ci si
        // immergeva e si rallentava a tre nodi: una nave a sette si allontanava,
        // si riemergeva, e si ricominciava per sempre.
        if ($notte) {
            [$quota, $nodi] = [0.0, $d > 3.0 ? 14.0 : 10.0];
        } else {
            [$quota, $nodi] = [12.0, $d > 2.0 ? 7.0 : 4.0];
        }
        Database::run(
            'UPDATE boats SET ordered_heading = ?, ordered_speed_kn = ?, ordered_depth_m = ?, silent = 0 WHERE id = ?',
            [$rotta, min($nodi, (float) $type['speed_surf_kn']), $quota, (int) $boat['id']]
        );
        return ['fase' => 'avvicinamento'];
    }

    /** Il I.WO chiude l'incontro e lo dice nel giornale. */
    private static function rinuncia(array $enc, array $boat, int $gts, string $testo): array
    {
        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'iwo', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']), 'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }
        Encounter::chiudi((int) $enc['id'], $testo, $gts);
        return ['fase' => 'rinuncia', 'testo' => $testo];
    }

    /**
     * Dopo il lancio, o scoperti: giu' alla quota di prova, due nodi, silenzio,
     * prua lontano dalla scorta piu' vicina.
     *
     * @param list<array<string,mixed>> $entita
     * @return array{fase:string, testo?:string}
     */
    private static function evadi(array $enc, array $boat, array $type, array $entita): array
    {
        $vicina = null;
        foreach ($entita as $e) {
            if ((string) $e['ruolo'] !== 'scorta') {
                continue;
            }
            $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
            if ($vicina === null || $d < $vicina['d']) {
                $vicina = $e + ['d' => $d];
            }
        }
        $rotta = $vicina !== null
            ? Geo::bearing((float) $vicina['lat'], (float) $vicina['lon'], (float) $boat['lat'], (float) $boat['lon'])
            : (float) $boat['heading'];
        $quota = (float) $type['test_depth_m'];
        $prima = (float) $boat['ordered_depth_m'] < $quota - 1.0;
        Database::run(
            'UPDATE boats SET ordered_heading = ?, ordered_speed_kn = 2, ordered_depth_m = ?, silent = 1 WHERE id = ?',
            [$rotta, $quota, (int) $boat['id']]
        );
        $testo = null;
        if ($prima && $enc['patrol_id'] !== null) {
            $testo = sprintf('Il I.WO porta il battello a %.0f metri: due nodi, marcia silenziosa, prua %03.0f.', $quota, $rotta);
            BoatSim::save([
                'gts' => (int) $enc['last_step_gts'], 'kind' => 'iwo', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }
        return ['fase' => 'evasione'] + ($testo !== null ? ['testo' => $testo] : []);
    }

    /**
     * Il comandante e' tornato in plancia: riprende il comando dell'incontro.
     */
    public static function riprendi(array $enc, array $boat): bool
    {
        if ((string) ($enc['condotta'] ?? 'comandante') !== 'iwo' || Colleghi::eCollega($boat)) {
            return false;
        }
        // La finestra di condotta riparte da adesso: era stata fissata quando
        // il I.WO ha aperto l'incontro, e scaduta avrebbe fatto disimpegnare il
        // comandante appena arrivato (revisione del 13/10/2026).
        Database::run(
            "UPDATE encounters SET condotta = 'comandante', finestra_fine = GREATEST(finestra_fine, ?) WHERE id = ? AND condotta = 'iwo'",
            [time() + max(5, GameConfig::int('combat.finestra_min', 25)) * 60, (int) $enc['id']]
        );
        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => (int) $enc['last_step_gts'], 'kind' => 'iwo', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => 'Il comandante e\' in plancia e riprende il comando: il I.WO gli passa la situazione.',
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }
        return true;
    }
}
