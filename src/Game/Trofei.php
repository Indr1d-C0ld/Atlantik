<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\World;

/**
 * Trofei: riconoscimenti del gioco.
 *
 * Distinti di proposito dalle decorazioni storiche: quelle le conferisce il
 * BdU secondo criteri d'epoca, questi premiano il MODO in cui si gioca — la
 * pazienza dell'agguato, la disciplina con la radio, il mestiere del
 * navigatore. La verifica si fa ai momenti naturali (rientro, affondamento,
 * fine incontro) e non a ogni battito: sono cose che si guardano quando si tira
 * una riga, non di continuo.
 */
final class Trofei
{
    /** @return list<array<string,mixed>> */
    public static function catalogo(): array
    {
        return Database::all('SELECT * FROM achievement_types ORDER BY categoria, ordine');
    }

    /** @return array<string,array<string,mixed>> */
    public static function ottenuti(int $userId): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT a.*, t.nome, t.descrizione, t.categoria, t.nota
             FROM achievements a JOIN achievement_types t ON t.akey = a.akey
             WHERE a.user_id = ? ORDER BY a.gts DESC',
            [$userId]
        ) as $r) {
            $out[(string) $r['akey']] = $r;
        }
        return $out;
    }

    /** Assegna un trofeo, se non c'e' gia'. */
    public static function assegna(int $userId, ?int $commanderId, string $akey, ?string $dettaglio = null): bool
    {
        $tipo = Database::first('SELECT akey FROM achievement_types WHERE akey = ?', [$akey]);
        if ($tipo === null) {
            return false;
        }
        $gia = Database::first('SELECT id FROM achievements WHERE user_id = ? AND akey = ?', [$userId, $akey]);
        if ($gia !== null) {
            return false;
        }

        Database::run(
            'INSERT INTO achievements (user_id, commander_id, akey, gts, dettaglio) VALUES (?, ?, ?, ?, ?)',
            [$userId, $commanderId, $akey, World::now(), $dettaglio !== null ? mb_substr($dettaglio, 0, 255) : null]
        );
        return true;
    }

    /**
     * Passata di verifica: si chiama al rientro in base e alla fine di un
     * incontro. Restituisce i trofei appena conquistati.
     *
     * @return list<array{akey:string,nome:string,descrizione:string}>
     */
    public static function verifica(array $user, ?array $cmd, array $boat, ?array $patrol = null): array
    {
        $userId = (int) $user['id'];
        $cmdId = $cmd !== null ? (int) $cmd['id'] : null;
        $gia = array_keys(self::ottenuti($userId));
        $nuovi = [];

        $prova = static function (string $akey, callable $cond, ?string $dettaglio = null) use (&$nuovi, $gia, $userId, $cmdId): void {
            if (in_array($akey, $gia, true)) {
                return;
            }
            $d = $cond();
            if ($d === false) {
                return;
            }
            if (self::assegna($userId, $cmdId, $akey, is_string($d) ? $d : $dettaglio)) {
                $t = Database::first('SELECT * FROM achievement_types WHERE akey = ?', [$akey]);
                if ($t !== null) {
                    $nuovi[] = ['akey' => $akey, 'nome' => (string) $t['nome'], 'descrizione' => (string) $t['descrizione']];
                }
            }
        };

        $boatId = (int) $boat['id'];

        // --- caccia -------------------------------------------------------------
        $aff = Database::first(
            'SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings WHERE boat_id = ?',
            [$boatId]
        );
        $prova('primo_sangue', static fn () => (int) ($aff['n'] ?? 0) >= 1);
        $prova('dieci_navi', static fn () => (int) ($aff['n'] ?? 0) >= 10);
        $prova('centomila', static fn () => $cmd !== null && (int) $cmd['grt_affondato'] >= 100000,
            $cmd !== null ? number_format((float) $cmd['grt_affondato'], 0, ',', '.') . ' GRT' : null);

        $prova('petroliera', static function () use ($boatId) {
            $r = Database::first(
                "SELECT s.nome FROM sinkings s JOIN ship_classes c ON c.class_key = s.class_key
                 WHERE s.boat_id = ? AND c.kind = 'petroliera'
                   AND s.carico IS NOT NULL AND s.carico <> 'in zavorra' LIMIT 1",
                [$boatId]
            );
            return $r === null ? false : (string) $r['nome'];
        });

        $prova('scorta', static function () use ($boatId) {
            $r = Database::first(
                "SELECT s.nome FROM sinkings s JOIN ship_classes c ON c.class_key = s.class_key
                 WHERE s.boat_id = ? AND c.kind = 'scorta' LIMIT 1",
                [$boatId]
            );
            return $r === null ? false : (string) $r['nome'];
        });

        $prova('notte_perfetta', static function () use ($boatId) {
            $r = Database::first(
                'SELECT affondate FROM encounters WHERE boat_id = ? AND affondate >= 3 ORDER BY affondate DESC LIMIT 1',
                [$boatId]
            );
            return $r === null ? false : $r['affondate'] . ' navi in un solo incontro';
        });

        $prova('cannoniere', static function () use ($boatId) {
            $r = Database::first("SELECT nome FROM sinkings WHERE boat_id = ? AND arma = 'cannone' LIMIT 1", [$boatId]);
            return $r === null ? false : (string) $r['nome'];
        });

        // --- navigazione ----------------------------------------------------------
        $prova('traversata', static function () use ($boatId) {
            $r = Database::first('SELECT MAX(distance_nm) d FROM patrols WHERE boat_id = ?', [$boatId]);
            return ((float) ($r['d'] ?? 0)) >= 5000 ? sprintf('%.0f miglia', (float) $r['d']) : false;
        });

        if ($patrol !== null) {
            $prova('navigatore', static fn () => (float) $boat['est_error_nm'] < 2.0,
                sprintf('errore di %.1f miglia al rientro', (float) $boat['est_error_nm']));
            $prova('equipaggio', static function () use ($boatId, $patrol) {
                $giorni = ((int) ($patrol['returned_gts'] ?? 0) - (int) $patrol['departed_gts']) / 86400;
                if ($giorni < 21) {
                    return false;
                }
                $m = Database::first("SELECT AVG(morale) m FROM crew_members WHERE boat_id = ? AND health <> 'morto'", [$boatId]);
                return ((float) ($m['m'] ?? 0)) > 70 ? sprintf('morale %.0f dopo %.0f giorni', (float) $m['m'], $giorni) : false;
            });
            $prova('ritorno', static function () use ($boatId) {
                $r = Database::first("SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state = 'distrutto'", [$boatId]);
                return ((int) ($r['n'] ?? 0)) > 0 ? 'rientrato con avarie gravi' : false;
            });
            $prova('silenzio', static function () use ($boatId, $patrol) {
                $affondate = (int) (Database::first('SELECT COUNT(*) n FROM sinkings WHERE patrol_id = ?', [(int) $patrol['id']])['n'] ?? 0);
                if ($affondate < 1) {
                    return false;
                }
                $radio = (int) (Database::first(
                    'SELECT COUNT(*) n FROM radio_messages WHERE boat_id = ? AND gts >= ? AND gts <= ?',
                    [$boatId, (int) $patrol['departed_gts'], (int) ($patrol['returned_gts'] ?? World::now())]
                )['n'] ?? 0);
                return $radio === 0 ? plurale($affondate, 'una nave affondata', '%d navi affondate') . ', zero trasmissioni' : false;
            });
        }

        $prova('profondo', static function () use ($boatId, $boat) {
            $tipo = World::type((string) $boat['type_key']);
            $r = Database::first('SELECT MAX(max_depth_m) d FROM patrols WHERE boat_id = ?', [$boatId]);
            return ((float) ($r['d'] ?? 0)) > (float) $tipo['test_depth_m']
                ? sprintf('%.0f metri', (float) $r['d']) : false;
        });

        $prova('rifornito', static function () use ($boatId) {
            $r = Database::first("SELECT id FROM rendezvous WHERE boat_id = ? AND stato = 'concluso' LIMIT 1", [$boatId]);
            return $r !== null;
        });

        // --- sopravvivenza ----------------------------------------------------------
        $prova('cariche', static function () use ($boatId) {
            $r = Database::first('SELECT MAX(cariche_subite) c FROM encounters WHERE boat_id = ?', [$boatId]);
            return ((int) ($r['c'] ?? 0)) >= 50 ? $r['c'] . ' cariche in un solo incontro' : false;
        });

        $prova('sganciato', static function () use ($boatId) {
            $r = Database::first(
                "SELECT id FROM encounters WHERE boat_id = ? AND allarme = 1 AND stato = 'concluso'
                 AND esito IS NOT NULL LIMIT 1",
                [$boatId]
            );
            return $r !== null;
        });

        $prova('aereo_scampato', static function () use ($boatId) {
            $r = Database::first(
                "SELECT id FROM patrol_events WHERE boat_id = ? AND kind = 'radar_warner' LIMIT 1",
                [$boatId]
            );
            return $r !== null;
        });

        // --- comando ------------------------------------------------------------------
        if ($cmd !== null) {
            $prova('ritterkreuz', static function () use ($cmd) {
                $r = Database::first("SELECT id FROM awards WHERE commander_id = ? AND akey = 'ritterkreuz'", [(int) $cmd['id']]);
                return $r !== null;
            });
            $prova('fuehlungshalter', static fn () => (int) $cmd['segnalazioni'] >= 5,
                $cmd['segnalazioni'] . ' segnalazioni');
            $prova('dieci_patrol', static fn () => (int) $cmd['patrols'] >= 10);
            $prova('erede', static function () use ($userId) {
                $r = Database::first("SELECT COUNT(*) n FROM commanders WHERE user_id = ? AND stato <> 'attivo'", [$userId]);
                return ((int) ($r['n'] ?? 0)) > 0;
            });
        }

        // --- mestiere ------------------------------------------------------------------
        $prova('idrofonista', static function () use ($boatId) {
            $r = Database::first(
                "SELECT range_nm FROM contacts WHERE boat_id = ? AND sensore = 'idrofono'
                 AND target_kind = 'convoglio' AND range_nm > 30 LIMIT 1",
                [$boatId]
            );
            return $r === null ? false : sprintf('%.0f miglia', (float) $r['range_nm']);
        });

        $prova('riparatore', static function () use ($boatId) {
            $r = Database::first("SELECT COUNT(*) n FROM patrol_events WHERE boat_id = ? AND kind = 'riparazione'", [$boatId]);
            return ((int) ($r['n'] ?? 0)) >= 10 ? $r['n'] . ' riparazioni' : false;
        });

        return $nuovi;
    }
}
