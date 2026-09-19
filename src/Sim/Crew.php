<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * L'equipaggio: chi sta a bordo, quanto vale, quanto e' stanco, come sta.
 *
 * Tre guardie da quattro ore, come si e' sempre fatto: chi smonta dorme nella
 * cuccetta di chi monta (il "letto caldo"). Gli ufficiali di macchina, il
 * radiotelegrafista, il nostromo e il cuoco stanno fuori dal giro dei quarti e
 * lavorano a giornata.
 *
 * Il rendimento di una stazione non e' la competenza da sola: e'
 * competenza x (1 - stanchezza) x morale. Un equipaggio bravo ma sfinito
 * sbaglia quanto uno scarso e riposato — ed e' il motivo per cui le patrol
 * lunghe finivano male anche senza che nessuno sparasse.
 */
final class Crew
{
    /** Guardia in servizio in questo momento (1-3). */
    public static function currentWatch(int $gts): int
    {
        $ore = max(1, GameConfig::int('crew.watch_hours', 4));
        return (int) (((int) floor($gts / ($ore * 3600))) % 3) + 1;
    }

    /** Crea il ruolino di un battello nuovo. */
    public static function generate(int $boatId, array $type, int $gts, ?Rng $rng = null): int
    {
        $rng ??= Rng::for(World::seed(), 'equipaggio', $boatId);
        $ruoli = Names::ruoli();
        $uomini = (int) $type['crew_max'] - 1;   // il comandante e' il giocatore
        $organico = Names::organico($uomini);

        // Le disposizioni piu' piccole non hanno tutti i compartimenti.
        $compartimenti = array_column(
            Database::all('SELECT ckey FROM boat_compartments WHERE boat_id = ?', [$boatId]),
            'ckey'
        );

        $n = 0;
        $turnoRotante = 1;
        foreach ($organico as $ruolo => $quanti) {
            $def = $ruoli[$ruolo];
            for ($i = 0; $i < $quanti; $i++) {
                $stazione = in_array($def['station'], $compartimenti, true) ? $def['station'] : 'zentrale';
                $turno = $def['watch'];
                if ($turno > 0 && in_array($ruolo, ['macchinista_diesel', 'macchinista_elettrico', 'silurista', 'zentrale', 'marinaio'], true)) {
                    $turno = $turnoRotante;
                    $turnoRotante = $turnoRotante % 3 + 1;
                }

                [$cmin, $cmax] = $def['competence'];
                Database::run(
                    'INSERT INTO crew_members (boat_id, name, rank_key, rank_name, role_key, role_name, station,
                                               watch_no, competence, fatigue, morale, joined_gts)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                    [
                        $boatId, Names::nome($rng), $def['rank_key'], $def['rank'], $ruolo, $def['name'],
                        $stazione, $turno, round($rng->range($cmin, $cmax), 2),
                        round($rng->range(62, 82), 2), $gts,
                    ]
                );
                $n++;
            }
        }
        return $n;
    }

    /** @return list<array<string,mixed>> */
    public static function roster(int $boatId): array
    {
        return Database::all(
            "SELECT * FROM crew_members WHERE boat_id = ? AND health <> 'morto'
             ORDER BY FIELD(role_key,'iwo','iiwo','li','navigatore','nostromo','radiotelegrafista',
                            'macchinista_diesel','macchinista_elettrico','silurista','zentrale','cuoco','marinaio'), name",
            [$boatId]
        );
    }

    /**
     * Sintesi utile al motore: quanti uomini, come stanno, e quanto rende
     * ciascuna specialita'.
     *
     * @return array{uomini:int,feriti:int,morale:float,fatica:float,competenza:float,specialita:array<string,float>}
     */
    public static function aggregate(int $boatId): array
    {
        $righe = Database::all(
            "SELECT role_key, health, AVG(competence) comp, AVG(fatigue) fat, AVG(morale) mor, COUNT(*) n
             FROM crew_members WHERE boat_id = ? AND health <> 'morto'
             GROUP BY role_key, health",
            [$boatId]
        );

        $uomini = 0; $feriti = 0;
        $sommaC = 0.0; $sommaF = 0.0; $sommaM = 0.0;
        $spec = [];

        foreach ($righe as $r) {
            $n = (int) $r['n'];
            $uomini += $n;
            if ((string) $r['health'] !== 'ok') {
                $feriti += $n;
            }
            $sommaC += (float) $r['comp'] * $n;
            $sommaF += (float) $r['fat'] * $n;
            $sommaM += (float) $r['mor'] * $n;

            $ruolo = (string) $r['role_key'];
            $resa = self::resa((float) $r['comp'], (float) $r['fat'], (float) $r['mor'])
                * ((string) $r['health'] === 'ok' ? 1.0 : 0.45);
            $spec[$ruolo] = ($spec[$ruolo] ?? 0.0) + $resa * $n;
        }

        if ($uomini === 0) {
            return ['uomini' => 0, 'feriti' => 0, 'morale' => 0.0, 'fatica' => 100.0, 'competenza' => 0.0, 'specialita' => []];
        }

        // La resa di una specialita' e' la somma dei suoi uomini, normalizzata
        // su un organico di riferimento: piu' uomini bravi, piu' lavoro fatto.
        foreach ($spec as $k => $v) {
            $spec[$k] = round($v, 2);
        }

        return [
            'uomini'     => $uomini,
            'feriti'     => $feriti,
            'morale'     => round($sommaM / $uomini, 2),
            'fatica'     => round($sommaF / $uomini, 2),
            'competenza' => round($sommaC / $uomini, 2),
            'specialita' => $spec,
        ];
    }

    /**
     * Adegua l'organico a un battello diverso: chi avanza sbarca (va a un
     * altro equipaggio, come accadeva), e se ne servono altri arrivano uomini
     * nuovi — piu' verdi di quelli che c'erano.
     */
    public static function adeguaOrganico(int $boatId, array $type, ?Rng $rng = null): int
    {
        $rng ??= Rng::for(World::seed(), 'organico', $boatId, (string) $type['type_key']);
        $voluti = max(1, (int) $type['crew_max'] - 1);
        $attuali = (int) (Database::first(
            "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health <> 'morto'",
            [$boatId]
        )['n'] ?? 0);

        if ($attuali === 0) {
            return self::generate($boatId, $type, World::now(), $rng);
        }

        if ($attuali > $voluti) {
            // Sbarcano per primi i meno esperti fra i marinai.
            Database::run(
                "DELETE FROM crew_members WHERE boat_id = ? AND role_key = 'marinaio'
                 ORDER BY competence ASC LIMIT " . ($attuali - $voluti),
                [$boatId]
            );
            return $voluti - $attuali;
        }

        $ruoli = Names::ruoli();
        $def = $ruoli['marinaio'];
        for ($i = $attuali; $i < $voluti; $i++) {
            Database::run(
                'INSERT INTO crew_members (boat_id, name, rank_key, rank_name, role_key, role_name, station,
                                           watch_no, competence, fatigue, morale, joined_gts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [
                    $boatId, Names::nome($rng), $def['rank_key'], $def['rank'], 'marinaio', $def['name'],
                    'prua', $rng->int(1, 3), round($rng->range(20, 55), 2), round($rng->range(58, 75), 2), World::now(),
                ]
            );
        }
        return $voluti - $attuali;
    }

    /** Resa individuale 0-1: competenza penalizzata da stanchezza e morale basso. */
    public static function resa(float $competenza, float $fatica, float $morale): float
    {
        $c = max(0.0, min(100.0, $competenza)) / 100.0;
        $f = 1.0 - max(0.0, min(100.0, $fatica)) / 145.0;          // sfinito = -69%
        $m = 0.6 + 0.4 * (max(0.0, min(100.0, $morale)) / 100.0);   // morale a terra = -40%
        return max(0.05, $c * $f * $m);
    }

    /**
     * Fa passare il tempo sull'equipaggio: chi e' di guardia si stanca, chi
     * riposa recupera, e il morale si muove secondo com'e' la vita a bordo.
     *
     * Su intervalli lunghi non si guarda quale guardia e' in servizio adesso —
     * i quarti ruotano ogni quattro ore, quindi ciascuno passa in coperta un
     * terzo del tempo. Guardare solo l'istante finale darebbe un equipaggio
     * eternamente fresco o eternamente a pezzi, secondo il minuto in cui
     * capita il calcolo.
     *
     * Il morale tende a un valore di equilibrio dettato dalle condizioni: e'
     * questo che impedisce derive assurde in su o in giu' e che rende il
     * logoramento di una patrol lunga una cosa lenta e inevitabile.
     *
     * @param array{aria:float,viveri:float,mare:int,giorni:int,avarie:int,superficie:bool,allarme:bool} $cond
     */
    public static function step(int $boatId, float $ore, int $gts, array $cond): void
    {
        if ($ore <= 0) {
            return;
        }

        // Stanchezza. Il riposo NON e' una sottrazione fissa ma un recupero
        // proporzionale: piu' si e' a pezzi, piu' rende la cuccetta. Cosi' la
        // stanchezza trova un equilibrio invece di scivolare a zero o sfondare
        // il tetto, e l'equilibrio si alza col mare grosso, con l'aria pesante
        // e con l'allarme — che e' esattamente cio' che logorava gli equipaggi.
        $suGuardia = 3.4 + 0.45 * max(0, $cond['mare'] - 3) + ($cond['allarme'] ? 2.6 : 0.0);
        if ($cond['aria'] < 45) {
            $suGuardia += 1.4;
        }
        $recupero = 0.062;                        // quota smaltita per ora di riposo
        if ($cond['mare'] >= 7) {
            $recupero *= 0.55;                    // col mare grosso non si dorme
        }
        if ($cond['aria'] < 45) {
            $recupero *= 0.7;
        }

        $oreQuarto = max(1, GameConfig::int('crew.watch_hours', 4));
        if ($ore <= $oreQuarto) {
            // Intervallo breve: conta chi e' davvero di guardia adesso.
            $guardia = self::currentWatch($gts);
            $inServizio = "IF(watch_no IN (0, {$guardia}), 1, 0)";
        } else {
            // Intervallo lungo: i quarti ruotano, ciascuno passa in coperta un
            // terzo del tempo; chi lavora a giornata poco piu' della meta'.
            $inServizio = 'IF(watch_no = 0, 0.55, 0.3333)';
        }

        // Morale: valore di equilibrio dato dalle condizioni di bordo.
        $equilibrio = 78.0;
        $equilibrio -= 0.75 * max(0, $cond['giorni'] - 10);          // le settimane pesano
        $equilibrio -= 3.5 * min(6, $cond['avarie']);                // il battello che si sfascia
        $equilibrio -= $cond['viveri'] <= 0 ? 22.0 : ($cond['viveri'] < 5 ? 7.0 : 0.0);
        $equilibrio -= $cond['aria'] < 30 ? 18.0 : ($cond['aria'] < 55 ? 7.0 : 0.0);
        $equilibrio -= $cond['mare'] >= 7 ? 9.0 : 0.0;
        $equilibrio -= $cond['allarme'] ? 25.0 : 0.0;
        $equilibrio -= $cond['superficie'] ? 0.0 : 6.0;              // l'aria aperta fa bene
        $equilibrio = max(5.0, min(95.0, $equilibrio));

        // Ci si avvicina all'equilibrio del 3,5% all'ora: in mezza giornata si
        // sente, in una settimana e' fatta.
        $k = min(0.9, 0.035 * $ore);

        Database::run(
            'UPDATE crew_members
             SET fatigue = LEAST(100, GREATEST(0, fatigue
                     + (' . $inServizio . ' * ' . round($suGuardia, 4)
                     . ' - (1 - ' . $inServizio . ') * ' . round($recupero, 5) . ' * fatigue) * ?)),
                 morale  = LEAST(100, GREATEST(0, morale + (? - morale) * ?))
             WHERE boat_id = ? AND health <> "morto"',
            [$ore, $equilibrio, $k, $boatId]
        );
    }

    /** Effetto del morale sul battello: sotto una certa soglia si sbaglia. */
    public static function statoMorale(float $morale): string
    {
        return match (true) {
            $morale >= 80 => 'ottimo',
            $morale >= 62 => 'buono',
            $morale >= 45 => 'provato',
            $morale >= 28 => 'basso',
            default       => 'al limite',
        };
    }

    /** Come sopra, ma riferito a tutto l'equipaggio ("gli uomini sono..."). */
    public static function statoFaticaPlurale(float $fatica): string
    {
        return match (true) {
            $fatica <= 20 => 'riposati',
            $fatica <= 45 => 'in ordine',
            $fatica <= 68 => 'stanchi',
            $fatica <= 85 => 'sfiniti',
            default       => 'allo stremo',
        };
    }

    public static function statoFatica(float $fatica): string
    {
        return match (true) {
            $fatica <= 20 => 'riposato',
            $fatica <= 45 => 'in ordine',
            $fatica <= 68 => 'stanco',
            $fatica <= 85 => 'sfinito',
            default       => 'allo stremo',
        };
    }
}
