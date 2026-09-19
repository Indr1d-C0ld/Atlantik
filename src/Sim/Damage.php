<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * Avarie, pressione, riparazioni.
 *
 * Tre sorgenti di guai, come nella realta':
 *   1. l'uso — ogni ora di esercizio e' un'ora di usura, e il rischio cresce
 *      con i giorni di missione, col regime forzato, col mare grosso e con la
 *      stanchezza di chi sta alle macchine;
 *   2. la pressione — sotto la quota di prova lo scafo lavora, e il lavoro
 *      lascia il segno: deformazioni che non si raddrizzano piu' e che
 *      abbassano la quota di collasso per sempre;
 *   3. il combattimento — arriva in F4; qui c'e' gia' il canale per riceverlo.
 *
 * Le riparazioni le fa l'equipaggio, con le mani e i ricambi che ha: alcune
 * cose si aggiustano a mare, altre no. Un periscopio piegato non si raddrizza
 * in mezzo all'Atlantico.
 */
final class Damage
{
    /** Installa compartimenti e sistemi su un battello nuovo. */
    public static function install(int $boatId, array $type, string $projectRoot): void
    {
        $layout = (string) ($type['layout'] ?? 'VII');
        /** @var array<string,list<array<string,mixed>>> $disposizioni */
        $disposizioni = require $projectRoot . '/db/seed/compartimenti.php';
        /** @var list<array<string,mixed>> $sistemi */
        $sistemi = require $projectRoot . '/db/seed/sistemi.php';

        $comp = $disposizioni[$layout] ?? $disposizioni['VII'];
        $chiaviComp = [];
        foreach ($comp as $c) {
            $chiaviComp[] = $c['ckey'];
            Database::run(
                'INSERT INTO boat_compartments (boat_id, ckey, name, seq) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), seq = VALUES(seq)',
                [$boatId, $c['ckey'], $c['name'], $c['seq']]
            );
        }

        foreach ($sistemi as $s) {
            // Si installa solo cio' che quel battello ha davvero.
            if ($s['skey'] === 'tubi_poppa' && (int) $type['tubes_stern'] === 0) {
                continue;
            }
            if ($s['skey'] === 'cannone' && empty($type['deck_gun'])) {
                continue;
            }
            $compartimento = in_array($s['compartment'], $chiaviComp, true) ? $s['compartment'] : 'zentrale';

            Database::run(
                'INSERT INTO boat_systems (boat_id, skey, name, category, compartment, specialty,
                                           repairable_sea, repair_hours, failure_rate, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category),
                        compartment = VALUES(compartment), specialty = VALUES(specialty),
                        repairable_sea = VALUES(repairable_sea), repair_hours = VALUES(repair_hours),
                        failure_rate = VALUES(failure_rate), note = VALUES(note)',
                [
                    $boatId, $s['skey'], $s['name'], $s['category'], $compartimento, $s['specialty'],
                    $s['repairable_sea'], $s['repair_hours'], $s['failure_rate'], $s['note'],
                ]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public static function systems(int $boatId): array
    {
        return Database::all(
            "SELECT * FROM boat_systems WHERE boat_id = ? ORDER BY FIELD(category,'propulsione','governo','scoperta','scafo','armamento'), name",
            [$boatId]
        );
    }

    /** Rimette tutto a nuovo: cantiere di base fra una missione e l'altra. */
    public static function overhaul(int $boatId): void
    {
        Database::run("UPDATE boat_systems SET condition_pct = 100, state = 'ok', repair_progress = 0 WHERE boat_id = ?", [$boatId]);
        // Anche le paratie sigillate si riaprono in bacino: e' il solo posto
        // dove si puo' fare, e chi e' rimasto dentro non torna comunque.
        Compartimenti::revisiona($boatId);
        // Le deformazioni dello scafo restano: quelle il cantiere le raddrizza
        // solo in parte, ed e' giusto che il battello invecchi.
        Database::run('UPDATE boats SET hull_integrity = LEAST(100, hull_integrity + 6), hull_stress = GREATEST(0, hull_stress * 0.55) WHERE id = ?', [$boatId]);
    }

    /**
     * Estrazione delle avarie per un intervallo.
     *
     * @param array<string,mixed> $cond  mare, giorni di missione, regime, resa dell'equipaggio
     * @return list<array{skey:string,name:string,compartment:string,grave:bool,riparabile:bool}>
     */
    public static function rollFailures(array $systems, float $ore, array $cond, Rng $rng): array
    {
        $scala = (float) GameConfig::get('damage.rate_scale', 1.0);
        $rotti = [];

        foreach ($systems as $s) {
            if ((string) $s['state'] !== 'ok') {
                continue;
            }
            $lambda = (float) $s['failure_rate'] * $scala * $ore;

            // Usura della missione: dopo tre settimane tutto e' piu' fragile.
            $lambda *= 1.0 + 0.030 * max(0, (int) $cond['giorni'] - 7);

            // Regime: i diesel a tutta forza si rompono molto piu' spesso.
            if ((string) $s['category'] === 'propulsione') {
                $lambda *= 0.55 + 1.8 * (float) $cond['carico'];
            }

            // Mare grosso: scuote tutto, e in coperta rovina cannone e antenne.
            $lambda *= 1.0 + 0.10 * max(0, (int) $cond['mare'] - 4);

            // Equipaggio: la manutenzione la fanno le stesse mani che riparano.
            $resa = (float) ($cond['specialita'][$s['specialty']] ?? 1.0);
            $lambda *= max(0.55, 1.6 - 0.6 * min(2.0, $resa));

            // Un sistema gia' malandato si rompe piu' facilmente.
            $lambda *= 1.0 + (100.0 - (float) $s['condition_pct']) / 90.0;

            if ($rng->chance(min(0.5, $lambda))) {
                $grave = $rng->chance(0.22);
                $rotti[] = [
                    'skey'       => (string) $s['skey'],
                    'name'       => (string) $s['name'],
                    'compartment'=> (string) $s['compartment'],
                    'grave'      => $grave,
                    'riparabile' => (bool) $s['repairable_sea'],
                ];
            }
        }
        return $rotti;
    }

    /** Applica un'avaria a database. */
    public static function applyFailure(int $boatId, string $skey, bool $grave, int $gts): void
    {
        Database::run(
            "UPDATE boat_systems
             SET state = ?, condition_pct = ?, repair_progress = 0, last_failure_gts = ?
             WHERE boat_id = ? AND skey = ?",
            [$grave ? 'distrutto' : 'avaria', $grave ? 0 : round(max(5.0, 45.0 - $gts % 17), 2), $gts, $boatId, $skey]
        );
    }

    /**
     * Danno da pressione. Oltre la quota di prova lo scafo accumula
     * sollecitazione; oltre l'intervallo di collasso e' un'altra storia, e per
     * ora si limita a fare molto male.
     *
     * @return array{stress:float,evento:?string}
     */
    public static function pressureStep(float $quota, array $type, float $stress, float $ore, Rng $rng): array
    {
        $prova = (float) $type['test_depth_m'];
        if ($quota <= $prova || $ore <= 0) {
            // Sotto la quota di prova lo scafo "riposa": una parte del lavoro
            // elastico si riassorbe, la deformazione permanente no.
            return ['stress' => max(0.0, $stress - 0.4 * $ore), 'evento' => null];
        }

        $scala = (float) GameConfig::get('damage.pressure_scale', 1.0);
        $crushMin = (float) $type['crush_depth_min_m'];
        $eccesso = ($quota - $prova) / max(1.0, $crushMin - $prova);   // 0 = quota di prova, 1 = inizio collasso

        $aumento = $scala * $ore * (3.0 * $eccesso + 9.0 * $eccesso ** 2);
        $stress = min(100.0, $stress + $aumento);

        $evento = null;
        // Rivetti che saltano, guarnizioni che cedono: il rumore che nessuno
        // a bordo dimentica. Raro: se capitasse a ogni quarto d'ora non
        // farebbe piu' paura a nessuno.
        if ($rng->chance(min(0.25, 0.05 * $eccesso * $ore))) {
            $evento = $eccesso >= 0.9 ? 'scafo_grave' : 'scafo_lamenti';
        }

        return ['stress' => round($stress, 2), 'evento' => $evento];
    }

    /**
     * Lavoro di riparazione. La squadra lavora sul sistema indicato dal
     * comandante; se non ne ha indicato uno, sul piu' importante fra quelli
     * riparabili.
     *
     * @return array{skey:?string,riparato:bool,progresso:float}
     */
    public static function repairStep(int $boatId, array $systems, float $ore, array $cond, ?string $focus, array &$progressi = []): array
    {
        $candidati = array_values(array_filter(
            $systems,
            static fn (array $s): bool => (string) $s['state'] !== 'ok' && (int) $s['repairable_sea'] === 1
        ));
        if ($candidati === []) {
            return ['skey' => null, 'riparato' => false, 'progresso' => 0.0];
        }

        $priorita = ['propulsione' => 0, 'governo' => 1, 'scoperta' => 2, 'armamento' => 3, 'scafo' => 4];
        usort($candidati, static fn (array $a, array $b): int
            => ($priorita[$a['category']] ?? 9) <=> ($priorita[$b['category']] ?? 9));

        $sistema = $candidati[0];
        if ($focus !== null) {
            foreach ($candidati as $c) {
                if ((string) $c['skey'] === $focus) {
                    $sistema = $c;
                    break;
                }
            }
        }

        // Ore-uomo prodotte: la specialita' giusta rende molto di piu'.
        // Ore-uomo prodotte davvero. Non tutti gli uomini della specialita'
        // possono metterci le mani insieme: in un locale motori ci stanno in
        // tre, e uno tiene la lampada. Il tetto e' basso apposta — le
        // riparazioni serie devono costare ore, non minuti.
        $resa = (float) ($cond['specialita'][$sistema['specialty']] ?? 0.6);
        $uominiEquivalenti = max(0.25, min(2.5, $resa * 0.6));
        // Con mare grosso e in immersione profonda si lavora peggio.
        $penalita = 1.0 - 0.05 * max(0, (int) $cond['mare'] - 5);
        $oreUomo = $ore * $uominiEquivalenti * max(0.4, $penalita);

        // Senza ricambi si arrangiano, ma ci mettono il doppio.
        if ((float) ($cond['ricambi'] ?? 0) <= 0) {
            $oreUomo *= 0.5;
        }

        // Il progresso vive qui dentro per tutta la durata dell'avanzamento:
        // l'elenco dei sistemi e' una fotografia presa all'inizio, e rileggerlo
        // da li' farebbe ripartire la riparazione da zero a ogni sotto-passo.
        $chiave = (string) $sistema['skey'];
        $partenza = $progressi[$chiave] ?? (float) $sistema['repair_progress'];
        $progresso = $partenza + $oreUomo;
        // Un'avaria grave costa quasi il doppio: non si rimette a posto, si
        // rimette in piedi.
        $necessarie = max(0.5, (float) $sistema['repair_hours'])
            * ((string) $sistema['state'] === 'distrutto' ? 1.8 : 1.0);

        if ($progresso >= $necessarie) {
            Database::run(
                "UPDATE boat_systems SET state = 'ok', condition_pct = LEAST(100, GREATEST(55, condition_pct + 55)),
                        repair_progress = 0 WHERE boat_id = ? AND skey = ?",
                [$boatId, (string) $sistema['skey']]
            );
            unset($progressi[$chiave]);
            return ['skey' => $chiave, 'riparato' => true, 'progresso' => $necessarie];
        }

        $progressi[$chiave] = $progresso;
        Database::run(
            'UPDATE boat_systems SET repair_progress = ? WHERE boat_id = ? AND skey = ?',
            [round($progresso, 2), $boatId, $chiave]
        );
        return ['skey' => $chiave, 'riparato' => false, 'progresso' => round($progresso, 2)];
    }

    /**
     * Che cosa comporta lo stato dei sistemi, tradotto in numeri che il moto e
     * i consumi possono usare.
     *
     * @return array{diesel_ko:bool,vel_superficie:float,vel_immersione:float,quota_controllo:float,
     *               batteria:float,aria:float,quota_max:float,guasti:int,elenco:list<string>}
     */
    public static function effects(array $systems, float $hullStress = 0.0, float $rinforzoScafo = 1.0): array
    {
        $stato = [];
        foreach ($systems as $s) {
            $stato[(string) $s['skey']] = (string) $s['state'];
        }
        $rotto = static fn (string $k): bool => ($stato[$k] ?? 'ok') !== 'ok';

        // Con un diesel fuori uso si va a meta' potenza. Con tutti e due il
        // battello non e' fermo: si striscia in superficie coi motori
        // elettrici, mangiando batteria — una soluzione disperata che pero'
        // si usava davvero per rientrare.
        $dieselKo = $rotto('diesel_1') && $rotto('diesel_2');
        $velSup = 1.0;
        if ($rotto('diesel_1')) { $velSup -= 0.45; }
        if ($rotto('diesel_2')) { $velSup -= 0.45; }
        $velSup = $dieselKo ? 0.22 : max(0.0, $velSup);

        $velImm = 1.0;
        if ($rotto('emotore_1')) { $velImm -= 0.45; }
        if ($rotto('emotore_2')) { $velImm -= 0.45; }
        $velImm = max(0.0, $velImm);

        $controllo = 1.0;
        if ($rotto('timoni_orizz')) { $controllo *= 0.35; }
        if ($rotto('casse')) { $controllo *= 0.6; }
        if ($rotto('pompe')) { $controllo *= 0.8; }

        $batteria = $rotto('batterie') ? 0.55 : 1.0;
        $aria     = $rotto('compressori') ? 0.55 : 1.0;

        // Lo scafo che ha lavorato troppo non torna quello di prima.
        $quotaMax = max(0.45, (1.0 - $hullStress / 190.0 - ($rotto('scafo') ? 0.25 : 0.0)) * $rinforzoScafo);

        $elenco = [];
        foreach ($systems as $s) {
            if ((string) $s['state'] !== 'ok') {
                $elenco[] = (string) $s['name'];
            }
        }

        return [
            'diesel_ko'       => $dieselKo,
            'vel_superficie'  => $velSup,
            'vel_immersione'  => $velImm,
            'quota_controllo' => $controllo,
            'batteria'        => $batteria,
            'aria'            => $aria,
            'quota_max'       => $quotaMax,
            'guasti'          => count($elenco),
            'elenco'          => $elenco,
        ];
    }
}
