<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Consumi: nafta, batteria, aria, viveri.
 *
 * NAFTA — la curva non e' inventata: si ricava dai dati storici di autonomia.
 * La potenza richiesta cresce col cubo della velocita', piu' un consumo fisso
 * di bordo (illuminazione, compressori, cucina), quindi
 *
 *      consumo [t/h] = a + b · v³
 *
 * Con DUE autonomie documentate (per il Tipo VII C: 8.500 nm a 10 nodi e
 * 6.500 nm a 12 nodi) il sistema di due equazioni si risolve e da'
 * a ≈ 0,029 t/h e b ≈ 1,04·10⁻⁴. Alla velocita' massima di 17,7 nodi quella
 * curva predice circa 3.300 miglia di autonomia: il valore che le fonti
 * riportano per il VII C a tutta forza. La calibrazione regge.
 *
 * BATTERIA — le batterie al piombo peggiorano con l'intensita' di scarica
 * (effetto Peukert), quindi la quarta potenza descrive i dati meglio del cubo:
 *
 *      scarica [%/h] = consumo di bordo + c · v⁴
 *
 * Calibrata sull'autonomia subacquea documentata (VII C: 80 nm a 4 nodi), la
 * curva predice circa un'ora e mezza alla massima velocita' subacquea — che e'
 * esattamente quanto reggevano davvero.
 */
final class Consumption
{
    /** Consumo fisso di bordo in immersione, in punti percentuali di batteria all'ora. */
    private const BATTERIA_BORDO = 0.6;

    /** Quota del consumo fisso sul totale a velocita' di crociera (ricavata dal Tipo VII C). */
    private const QUOTA_FISSA = 0.218;

    /** Ore di immersione continua prima che l'aria diventi critica, senza cartucce di potassa. */
    private const ORE_ARIA = 30.0;

    /**
     * Coefficienti [a, b] della curva del consumo di nafta.
     *
     * @param array<string,mixed> $type riga di uboat_types
     * @return array{0:float,1:float}
     */
    public static function fuelCoeff(array $type): array
    {
        $fuel = (float) $type['fuel_t'];
        $v1   = (float) $type['range1_kn'];
        $r1   = (float) $type['range1_nm'];
        $c1   = $fuel / ($r1 / $v1);                       // t/h alla velocita' 1

        $r2 = $type['range2_nm'] ?? null;
        $v2 = $type['range2_kn'] ?? null;

        if ($r2 !== null && $v2 !== null && (float) $v2 > 0 && (float) $v2 !== $v1) {
            $v2 = (float) $v2;
            $c2 = $fuel / ((float) $r2 / $v2);
            $b  = ($c2 - $c1) / ($v2 ** 3 - $v1 ** 3);
            $a  = $c1 - $b * $v1 ** 3;
            if ($a >= 0 && $b > 0) {
                return [$a, $b];
            }
        }

        // Un solo punto documentato: si usa la proporzione fissa/variabile del
        // Tipo VII C, l'unico tipo per cui abbiamo due autonomie certe.
        $a = self::QUOTA_FISSA * $c1;
        $b = ($c1 - $a) / $v1 ** 3;
        return [$a, $b];
    }

    /** Nafta consumata in un'ora a una data velocita', in tonnellate. */
    public static function fuelPerHour(array $type, float $kn, bool $ricarica = false): float
    {
        [$a, $b] = self::fuelCoeff($type);
        $t = $a + $b * max(0.0, $kn) ** 3;
        // Caricare le batterie tiene i diesel sotto sforzo anche da fermi.
        return $ricarica ? $t * 1.35 + 0.02 : $t;
    }

    /** Autonomia residua in miglia alla velocita' data, con la nafta che resta. */
    public static function rangeLeftNm(array $type, float $fuelT, float $kn): float
    {
        $perHour = self::fuelPerHour($type, $kn);
        if ($perHour <= 0) {
            return 0.0;
        }
        return ($fuelT / $perHour) * $kn;
    }

    /** Coefficiente c della curva di scarica. */
    public static function batteryCoeff(array $type): float
    {
        $v = max(0.1, (float) $type['sub_range_kn']);
        $ore = max(0.1, (float) $type['sub_range_nm'] / $v);
        $scaricaTotale = 100.0 / $ore;                     // %/h a quella velocita'
        return max(1e-6, ($scaricaTotale - self::BATTERIA_BORDO) / $v ** 4);
    }

    /** Scarica della batteria in un'ora, in punti percentuali. */
    public static function batteryDrainPerHour(array $type, float $kn, bool $silenzioso = false): float
    {
        $c = self::batteryCoeff($type);
        $bordo = self::BATTERIA_BORDO * ($silenzioso ? 0.45 : 1.0);  // pompe ferme, ventilazione al minimo
        return $bordo + $c * max(0.0, $kn) ** 4;
    }

    /** Ore di autonomia subacquea residue alla velocita' data. */
    public static function submergedHoursLeft(array $type, float $batteryPct, float $kn, bool $silenzioso = false): float
    {
        $d = self::batteryDrainPerHour($type, $kn, $silenzioso);
        return $d <= 0 ? 999.0 : $batteryPct / $d;
    }

    /**
     * Ricarica in un'ora, in punti percentuali. In superficie i diesel
     * spingono e caricano insieme: piu' si corre, meno resta per le batterie.
     * A velocita' bassa il ciclo completo richiede cinque-sei ore, come in mare.
     */
    public static function batteryChargePerHour(array $type, float $kn): float
    {
        $max = max(0.0, (float) $type['speed_surf_kn']);
        $quota = 1.0 - min(1.0, $kn / max(1.0, $max * 0.8));
        return max(0.0, 19.0 * $quota);
    }

    /** Consumo d'aria in un'ora di immersione, in punti percentuali. */
    public static function airDrainPerHour(int $crew, int $crewRef, bool $silenzioso = false): float
    {
        $fattore = $crewRef > 0 ? $crew / $crewRef : 1.0;
        // In marcia silenziosa gli uomini liberi stanno in cuccetta e respirano meno.
        return (100.0 / self::ORE_ARIA) * $fattore * ($silenzioso ? 0.85 : 1.0);
    }

    /** Recupero d'aria in un'ora di superficie (o di respiratore). */
    public static function airGainPerHour(): float
    {
        return 400.0;   // un quarto d'ora di ventilazione e l'aria e' di nuovo buona
    }

    /** Anidride carbonica percepita, ricavata dall'aria residua. */
    public static function co2FromAir(float $airPct): float
    {
        return round(max(0.0, min(8.0, (100.0 - $airPct) * 0.055)), 2);
    }

    /** Come sta l'aria, detto a parole. */
    public static function statoAria(float $airPct): string
    {
        return match (true) {
            $airPct > 70 => 'buona',
            $airPct > 45 => 'pesante',
            $airPct > 25 => 'cattiva: mal di testa',
            $airPct > 10 => 'irrespirabile: uomini a terra',
            default      => 'critica',
        };
    }
}
