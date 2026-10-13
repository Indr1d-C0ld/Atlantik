<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\BoatSim;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * Le missioni speciali del BdU.
 *
 * Non tutte le uscite erano caccia al tonnellaggio. Tre lavori diversi, che
 * cambiano il modo di stare in mare:
 *
 *   - la posa di mine davanti a un porto nemico. Il battello parte con meno
 *     siluri (tre mine TMB nello spazio di uno), deve arrivare a quattro miglia
 *     dal punto, in immersione, e posarle. Il campo minato resta: per settimane
 *     ogni nave che passa rischia, e quelle che saltano sono accreditate a chi
 *     l'ha posato (campiMinati, nel battito). Halifax, Reykjavik, Freetown e New
 *     York furono minati davvero da U-Boot;
 *   - il battello meteorologico: stare in un quadrato e mandare il rapporto
 *     meteorologico (Kurzsignal 'meteo', alla radio) almeno ogni otto ore, sei
 *     volte. Ogni rapporto e' un rischio radiogoniometrico — e' esattamente il
 *     prezzo che pagavano;
 *   - lo sbarco di agenti dell'Abwehr: arrivare a tre miglia da una costa, di
 *     notte, in superficie, a macchine quasi ferme, e mandare a terra il
 *     battellino. In Irlanda e in Islanda gli U-Boot sbarcarono agenti davvero
 *     (Islanda: U-252, aprile 1942).
 *
 * Il BdU le offre a chi e' in porto, qualcuna ogni pochi giorni; il comandante
 * ne accetta una, e parte con quella. Una missione non portata a termine prima
 * del rientro e' fallita, e costa un poco di prestigio.
 */
final class MissioniSpeciali
{
    public const TIPI = [
        'mine'   => 'Posa di mine',
        'meteo'  => 'Battello meteorologico',
        'agenti' => 'Sbarco di agenti',
    ];

    /** Porti da minare: la rotta che ci passa e da che parte. Il punto e' a dodici miglia dal porto, sulla rotta. */
    private const PORTI = [
        ['luogo' => 'negli approcci di Halifax',            'rotta' => 'hx',          'capo' => 'partenza'],
        ['luogo' => 'nel Faxaflói, davanti a Reykjavík',    'rotta' => 'islanda',     'capo' => 'partenza'],
        ['luogo' => 'negli approcci di Freetown',           'rotta' => 'sl',          'capo' => 'partenza'],
        ['luogo' => 'negli approcci di New York',           'rotta' => 'caraibi_usa', 'capo' => 'arrivo'],
    ];

    private const SBARCHI = [
        ['luogo' => 'in Irlanda, nella baia di Dingle',  'lat' => 52.10, 'lon' => -10.30],
        ['luogo' => 'in Irlanda, nella baia di Donegal', 'lat' => 54.60, 'lon' => -8.60],
        ['luogo' => 'sulla costa orientale dell\'Islanda', 'lat' => 65.30, 'lon' => -13.60],
    ];

    private const METEO = [
        ['luogo' => 'a sud della Groenlandia',       'lat' => 57.0, 'lon' => -35.0],
        ['luogo' => 'a ponente dell\'Irlanda',        'lat' => 53.0, 'lon' => -24.0],
        ['luogo' => 'nell\'Atlantico centrale',      'lat' => 47.0, 'lon' => -33.0],
    ];

    /** Mine TMB: tre nello spazio di un siluro. */
    private const MINE = 24;
    private const RAGGIO_MINE_NM = 4.0;
    private const RAGGIO_AGENTI_NM = 3.0;
    private const RAGGIO_METEO_NM = 60.0;
    private const METEO_OGNI_S = 8 * 3600;

    /** La missione accettata o in corso di un battello. */
    public static function attiva(int $boatId): ?array
    {
        return Database::first(
            "SELECT * FROM missioni_speciali WHERE boat_id = ? AND stato IN ('accettata','in_corso') ORDER BY id DESC LIMIT 1",
            [$boatId]
        );
    }

    /**
     * Le offerte del BdU per chi e' in porto. Si rinnovano ogni pochi giorni di
     * gioco: due lavori a caso fra i tre, con luoghi a caso.
     *
     * @return list<array<string,mixed>>
     */
    public static function offerte(array $boat, int $gts): array
    {
        if ($boat['commander_id'] === null || (string) $boat['state'] !== 'base' || self::attiva((int) $boat['id']) !== null) {
            return [];
        }
        Database::run(
            "UPDATE missioni_speciali SET stato = 'scaduta', chiusa_gts = ? WHERE boat_id = ? AND stato = 'offerta' AND scade_gts < ?",
            [$gts, (int) $boat['id'], $gts]
        );
        $ogni = max(1, GameConfig::int('missioni.ogni_giorni', 4)) * 86400;
        $periodo = intdiv($gts, $ogni);
        // Le offerte del periodo ci sono gia' (anche solo in parte): si
        // restituiscono quelle. L'unicita' su battello-periodo-tipo fa il resto
        // se due pagine arrivano insieme.
        if (Database::first('SELECT 1 FROM missioni_speciali WHERE boat_id = ? AND periodo = ?', [(int) $boat['id'], $periodo]) !== null) {
            return Database::all(
                "SELECT * FROM missioni_speciali WHERE boat_id = ? AND stato = 'offerta' ORDER BY id", [(int) $boat['id']]);
        }
        $rng = Rng::for(World::seed(), 'missioni', (int) $boat['id'], $periodo);
        $tipi = array_keys(self::TIPI);
        $scelti = [$tipi[$rng->int(0, 2)]];
        do {
            $altro = $tipi[$rng->int(0, 2)];
        } while ($altro === $scelti[0]);
        $scelti[] = $altro;
        foreach ($scelti as $tipo) {
            $m = self::componi($tipo, $rng);
            Database::run(
                'INSERT IGNORE INTO missioni_speciali (commander_id, boat_id, periodo, tipo, luogo, lat, lon, quadrat, obiettivo, premio, punti, offerta_gts, scade_gts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $boat['commander_id'], (int) $boat['id'], $periodo, $tipo, $m['luogo'], $m['lat'], $m['lon'],
                 Grid::toQuadrat($m['lat'], $m['lon']), $m['obiettivo'], $m['premio'], $m['punti'], $gts, ($periodo + 1) * $ogni]
            );
        }
        return Database::all("SELECT * FROM missioni_speciali WHERE boat_id = ? AND stato = 'offerta' ORDER BY id", [(int) $boat['id']]);
    }

    /** @return array{luogo:string, lat:float, lon:float, obiettivo:int, premio:int, punti:int} */
    private static function componi(string $tipo, Rng $rng): array
    {
        switch ($tipo) {
            case 'mine':
                $p = self::PORTI[$rng->int(0, count(self::PORTI) - 1)];
                $durata = Traffic::durata($p['rotta'], 1.0);
                $dodici = 12 * 3600;      // dodici miglia a un nodo
                $pos = Traffic::posizione($p['rotta'], 1.0, 0, $p['capo'] === 'partenza' ? $dodici : $durata - $dodici, 0.0);
                return ['luogo' => $p['luogo'], 'lat' => round($pos['lat'], 4), 'lon' => round($pos['lon'], 4),
                    'obiettivo' => self::MINE, 'premio' => 400, 'punti' => 60];
            case 'agenti':
                $s = self::SBARCHI[$rng->int(0, count(self::SBARCHI) - 1)];
                return ['luogo' => $s['luogo'], 'lat' => $s['lat'], 'lon' => $s['lon'], 'obiettivo' => $rng->int(2, 4),
                    'premio' => 350, 'punti' => 50];
            default:
                $m = self::METEO[$rng->int(0, count(self::METEO) - 1)];
                return ['luogo' => $m['luogo'], 'lat' => $m['lat'], 'lon' => $m['lon'], 'obiettivo' => 6,
                    'premio' => 360, 'punti' => 50];
        }
    }

    /** @return array{ok:bool, error?:string} */
    public static function accetta(array $boat, int $missioneId): array
    {
        $m = Database::first(
            "SELECT * FROM missioni_speciali WHERE id = ? AND boat_id = ? AND stato = 'offerta'", [$missioneId, (int) $boat['id']]);
        if ($m === null || (string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'Offerta non piu\' valida.'];
        }
        Database::run("UPDATE missioni_speciali SET stato = 'accettata' WHERE id = ?", [$missioneId]);
        Database::run(
            "UPDATE missioni_speciali SET stato = 'scaduta', chiusa_gts = ? WHERE boat_id = ? AND stato = 'offerta'",
            [World::now(), (int) $boat['id']]
        );
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public static function rinuncia(array $boat): array
    {
        $m = self::attiva((int) $boat['id']);
        if ($m === null || (string) $m['stato'] !== 'accettata') {
            return ['ok' => false, 'error' => 'Nessuna missione da rimettere: in mare non si rinuncia, si fallisce.'];
        }
        Database::run("UPDATE missioni_speciali SET stato = 'scaduta', chiusa_gts = ? WHERE id = ?", [World::now(), (int) $m['id']]);
        return ['ok' => true];
    }

    /**
     * Alla partenza: la missione accettata diventa in corso, e per le mine si
     * sbarcano i siluri che le mine sostituiscono.
     */
    public static function allaPartenza(array $boat, int $patrolId, int $gts): void
    {
        $m = Database::first(
            "SELECT * FROM missioni_speciali WHERE boat_id = ? AND stato = 'accettata' ORDER BY id DESC LIMIT 1", [(int) $boat['id']]);
        if ($m === null) {
            return;
        }
        Database::run("UPDATE missioni_speciali SET stato = 'in_corso', patrol_id = ? WHERE id = ?", [$patrolId, (int) $m['id']]);
        if ((string) $m['tipo'] === 'mine') {
            $posti = (int) ceil((int) $m['obiettivo'] / 3);
            Database::run(
                "DELETE FROM boat_torpedoes WHERE boat_id = ? AND posizione NOT IN ('tubo_prua','tubo_poppa') ORDER BY id DESC LIMIT " . $posti,
                [(int) $boat['id']]
            );
        }
        $testo = match ((string) $m['tipo']) {
            'mine' => sprintf('Missione speciale del BdU: posare %d mine TMB %s, quadrato %s, in immersione, entro quattro miglia dal punto. '
                . 'Per far posto alle mine si sono lasciati a terra %d siluri.', (int) $m['obiettivo'], $m['luogo'], $m['quadrat'],
                (int) ceil((int) $m['obiettivo'] / 3)),
            'agenti' => sprintf('Missione speciale del BdU: a bordo %d agenti dell\'Abwehr, da sbarcare %s (%s): di notte, in superficie, '
                . 'a macchine quasi ferme, entro tre miglia dalla costa.', (int) $m['obiettivo'], $m['luogo'], $m['quadrat']),
            default => sprintf('Missione speciale del BdU: battello meteorologico %s, quadrato %s. Sei rapporti meteo dal quadrato, '
                . 'uno ogni otto ore almeno: la Luftwaffe e i battelli in mare ne hanno bisogno.', $m['luogo'], $m['quadrat']),
        };
        BoatSim::save([
            'gts' => $gts, 'kind' => 'ordine_bdu', 'severity' => 'nota', 'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
            'quadrat' => $m['quadrat'], 'text' => $testo,
        ], $patrolId, (int) $boat['id']);
    }

    /**
     * Che cosa manca, adesso, per compiere la missione: serve alla centrale.
     *
     * @return array{missione:array<string,mixed>, distanza:float, rilevamento:float, pronta:bool, manca:list<string>}|null
     */
    public static function situazione(array $boat, int $gts): ?array
    {
        $m = self::attiva((int) $boat['id']);
        if ($m === null || (string) $m['stato'] !== 'in_corso' || (string) $boat['state'] !== 'mare') {
            return null;
        }
        $d = Geo::distanceNm((float) $boat['est_lat'], (float) $boat['est_lon'], (float) $m['lat'], (float) $m['lon']);
        $ril = Geo::bearing((float) $boat['est_lat'], (float) $boat['est_lon'], (float) $m['lat'], (float) $m['lon']);
        $vero = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $m['lat'], (float) $m['lon']);
        $manca = [];
        switch ((string) $m['tipo']) {
            case 'mine':
                if ($vero > self::RAGGIO_MINE_NM) {
                    $manca[] = 'arrivare a quattro miglia dal punto';
                }
                if ((string) $boat['mode'] === 'superficie') {
                    $manca[] = 'immergersi: le mine si posano dai tubi, sotto';
                }
                break;
            case 'agenti':
                $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], $gts);
                if ($vero > self::RAGGIO_AGENTI_NM) {
                    $manca[] = 'arrivare a tre miglia dalla costa';
                }
                if ((string) $boat['mode'] !== 'superficie') {
                    $manca[] = 'emergere: il battellino va a remi';
                }
                if ((float) $cielo['luce'] >= 0.25) {
                    $manca[] = 'aspettare la notte';
                }
                if ((float) $boat['speed_kn'] > 2.0) {
                    $manca[] = 'fermare le macchine (due nodi al massimo)';
                }
                break;
            default:
                if ($vero > self::RAGGIO_METEO_NM) {
                    $manca[] = 'portarsi nel quadrato (entro sessanta miglia)';
                }
                $manca[] = sprintf('mandare il rapporto meteo dalla radio (%d su %d)', (int) $m['fatto'], (int) $m['obiettivo']);
                break;
        }
        return ['missione' => $m, 'distanza' => $d, 'rilevamento' => $ril,
            'pronta' => $manca === [] && (string) $m['tipo'] !== 'meteo', 'manca' => $manca];
    }

    /**
     * L'azione: posare le mine, sbarcare gli agenti.
     *
     * @return array{ok:bool, error?:string, testo?:string}
     */
    public static function esegui(array $boat, int $gts): array
    {
        $s = self::situazione($boat, $gts);
        if ($s === null) {
            return ['ok' => false, 'error' => 'Nessuna missione speciale in corso.'];
        }
        if (!$s['pronta']) {
            return ['ok' => false, 'error' => 'Non ancora: ' . implode('; ', $s['manca']) . '.'];
        }
        $m = $s['missione'];
        // Una volta sola: chi arriva secondo (un doppio clic) trova la
        // missione gia' compiuta e non posa un secondo campo.
        if (Database::run("UPDATE missioni_speciali SET stato = 'compiuta', chiusa_gts = ? WHERE id = ? AND stato = 'in_corso'",
            [$gts, (int) $m['id']])->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Missione gia\' compiuta.'];
        }
        if ((string) $m['tipo'] === 'mine') {
            Database::run(
                'INSERT INTO campi_minati (missione_id, boat_id, commander_id, patrol_id, luogo, lat, lon, raggio_nm, mine, posato_gts, scade_gts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 3.0, ?, ?, ?)',
                [(int) $m['id'], (int) $boat['id'], $boat['commander_id'], $m['patrol_id'], (string) $m['luogo'],
                 (float) $boat['lat'], (float) $boat['lon'], (int) $m['obiettivo'], $gts, $gts + 45 * 86400]
            );
            $testo = sprintf('Mine posate %s: %d TMB sul fondo, in quadrato %s. Il campo restera\' attivo per settimane: '
                . 'ogni nave che ci salta sopra sara\' accreditata a noi.', (string) $m['luogo'], (int) $m['obiettivo'],
                Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']));
        } else {
            $testo = sprintf('Il battellino torna dalla riva: %d agenti a terra %s, senza che nessuno se ne sia accorto.',
                (int) $m['obiettivo'], (string) $m['luogo']);
        }
        self::compi($m, $boat, $gts, $testo);
        return ['ok' => true, 'testo' => $testo];
    }

    /**
     * Un rapporto meteorologico trasmesso (Radio::trasmetti). Conta se si e'
     * nel quadrato e se dall'ultimo che ha contato sono passate otto ore.
     */
    public static function rapportoMeteo(array $boat, int $gts): ?string
    {
        $m = self::attiva((int) $boat['id']);
        if ($m === null || (string) $m['stato'] !== 'in_corso' || (string) $m['tipo'] !== 'meteo') {
            return null;
        }
        if (Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $m['lat'], (float) $m['lon']) > self::RAGGIO_METEO_NM) {
            return 'Il rapporto non conta per la missione: il quadrato assegnato e\' piu\' in la\'.';
        }
        if ($m['ultimo_gts'] !== null && $gts - (int) $m['ultimo_gts'] < self::METEO_OGNI_S) {
            return 'Il rapporto e\' arrivato, ma per la missione ne conta uno ogni otto ore.';
        }
        // Il conto e l'intervallo si controllano nello stesso aggiornamento: due
        // rapporti mandati insieme non contano due volte.
        $contato = Database::run(
            "UPDATE missioni_speciali SET fatto = fatto + 1, ultimo_gts = ?
              WHERE id = ? AND stato = 'in_corso' AND (ultimo_gts IS NULL OR ultimo_gts <= ?)",
            [$gts, (int) $m['id'], $gts - self::METEO_OGNI_S]
        )->rowCount();
        if ($contato !== 1) {
            return 'Il rapporto e\' arrivato, ma per la missione ne conta uno ogni otto ore.';
        }
        $fatto = (int) Database::first('SELECT fatto FROM missioni_speciali WHERE id = ?', [(int) $m['id']])['fatto'];
        if ($fatto >= (int) $m['obiettivo']
            && Database::run("UPDATE missioni_speciali SET stato = 'compiuta', chiusa_gts = ? WHERE id = ? AND stato = 'in_corso'",
                [$gts, (int) $m['id']])->rowCount() === 1) {
            self::compi($m, $boat, $gts, sprintf('Sesto rapporto meteorologico %s: il BdU ringrazia, missione compiuta.', (string) $m['luogo']));
            return 'Missione meteorologica compiuta.';
        }
        return sprintf('Rapporto meteorologico %d di %d.', $fatto, (int) $m['obiettivo']);
    }

    private static function compi(array $m, array $boat, int $gts, string $testo): void
    {
        // Chi chiama ha gia' preso la missione (esegui) o la prende qui: il
        // premio si paga una volta.
        Database::run("UPDATE missioni_speciali SET stato = 'compiuta', chiusa_gts = COALESCE(chiusa_gts, ?), fatto = obiettivo WHERE id = ?",
            [$gts, (int) $m['id']]);
        if ($boat['commander_id'] !== null) {
            Database::run(
                'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ?, punti = punti + ? WHERE id = ?',
                [(int) $m['premio'], (int) $m['premio'], (int) $m['punti'], (int) $boat['commander_id']]
            );
        }
        if ($m['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'missione', 'severity' => 'nota', 'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => $testo . sprintf(' (+%d prestigio, +%d punti)', (int) $m['premio'], (int) $m['punti']),
            ], (int) $m['patrol_id'], (int) $boat['id']);
        }
    }

    /**
     * La missione finisce con la missione: al rientro o alla perdita, quella
     * non compiuta e' fallita.
     */
    public static function allaFine(int $boatId, int $gts): void
    {
        $m = Database::first("SELECT * FROM missioni_speciali WHERE boat_id = ? AND stato = 'in_corso'", [$boatId]);
        if ($m === null) {
            return;
        }
        Database::run("UPDATE missioni_speciali SET stato = 'fallita', chiusa_gts = ? WHERE id = ?", [$gts, (int) $m['id']]);
        Database::run('UPDATE commanders SET prestigio = GREATEST(0, prestigio - 60) WHERE id = ?', [(int) $m['commander_id']]);
    }

    /**
     * Il battito: le navi che passano sui campi minati.
     *
     * Ogni nave rischia una volta per campo, con una probabilita' che cala man
     * mano che le mine si consumano. Chi salta viene colpita come da un siluro
     * fuori dall'incontro (Danni::agonia la fa affondare e la accredita a chi
     * ha posato il campo); chi non affonda resta ferita.
     *
     * @return array{colpite:int}
     */
    public static function campiMinati(int $gts): array
    {
        $out = ['colpite' => 0];
        $campi = Database::all('SELECT * FROM campi_minati WHERE scade_gts > ? AND mine > 0', [$gts]);
        if ($campi === []) {
            return $out;
        }
        $pPiena = (float) GameConfig::get('missioni.mina_p', 0.12);
        // Non basta guardare dov'e' la nave adesso: fra un battito e l'altro
        // passa mezz'ora di gioco, e un convoglio a nove nodi fa quasi cinque
        // miglia — attraversava il campo senza mai esserci dentro all'istante
        // del battito. Si guarda il tratto percorso, ogni cinque minuti, con
        // un margine per il battito in ritardo (revisione del 13/10/2026).
        $tratto = World::clock()->gameSecondsFor(90);
        $unita = Traffic::attivi($gts - $tratto, $gts);
        foreach ($campi as $c) {
            foreach ($unita as $u) {
                $vicina = INF;
                for ($t = $gts - $tratto; $t <= $gts; $t += 300) {
                    $p = Traffic::pos($u, $t);
                    if ($p !== null) {
                        $vicina = min($vicina, Geo::distanceNm($p['lat'], $p['lon'], (float) $c['lat'], (float) $c['lon']));
                    }
                }
                if ($vicina > (float) $c['raggio_nm'] + 1.0) {
                    continue;
                }
                $navi = (string) $u['tipo_unita'] === 'convoglio'
                    ? Database::all("SELECT * FROM ships WHERE convoy_id = ? AND state = 'in_mare' AND affonda_gts IS NULL", [(int) $u['id']])
                    : Database::all("SELECT * FROM ships WHERE id = ? AND state = 'in_mare' AND affonda_gts IS NULL", [(int) $u['id']]);
                foreach ($navi as $n) {
                    $nuovo = Database::run('INSERT IGNORE INTO campi_minati_passaggi (campo_id, ship_id) VALUES (?, ?)',
                        [(int) $c['id'], (int) $n['id']])->rowCount();
                    if ($nuovo === 0 || (int) $c['mine'] <= 0) {
                        continue;
                    }
                    $rng = Rng::for(World::seed(), 'mina', (int) $c['id'], (int) $n['id']);
                    if (!$rng->chance($pPiena * (int) $c['mine'] / self::MINE)) {
                        continue;
                    }
                    $c['mine'] = (int) $c['mine'] - 1;
                    Database::run('UPDATE campi_minati SET mine = mine - 1 WHERE id = ?', [(int) $c['id']]);
                    Database::run('UPDATE campi_minati_passaggi SET colpita = 1 WHERE campo_id = ? AND ship_id = ?', [(int) $c['id'], (int) $n['id']]);
                    $affonda = $rng->chance(0.6);
                    // Il danno si somma a quello che c'era (non lo si ripara), e il
                    // merito di una nave gia' colpita da un altro resta a lui.
                    // L'ordine conta: MariaDB valuta le assegnazioni da sinistra,
                    // e danno_boat_id va toccato per ultimo.
                    Database::run(
                        "UPDATE ships SET integrita = LEAST(integrita, ?), allagamento = GREATEST(allagamento, ?),
                                danno_arma = IF(danno_boat_id IS NULL, 'mina', danno_arma),
                                danno_patrol_id = IF(danno_boat_id IS NULL, ?, danno_patrol_id),
                                danno_commander_id = IF(danno_boat_id IS NULL, ?, danno_commander_id),
                                danno_gts = COALESCE(danno_gts, ?), danno_boat_id = COALESCE(danno_boat_id, ?), affonda_gts = ?
                          WHERE id = ?",
                        [$affonda ? 0.0 : round($rng->range(25.0, 60.0), 1), $affonda ? 90.0 : round($rng->range(20.0, 50.0), 1),
                         $c['patrol_id'], $c['commander_id'], $gts, $c['boat_id'],
                         $affonda ? $gts + $rng->int(300, 3600) : null, (int) $n['id']]
                    );
                    $out['colpite']++;
                }
            }
        }
        return $out;
    }
}
