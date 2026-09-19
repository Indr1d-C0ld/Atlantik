<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Sole, luna, crepuscoli — e il livello di luce che ne deriva.
 *
 * Non e' astronomia per gusto dell'astronomia: la luce decide se ti vedono.
 * Il crepuscolo nautico e' il momento d'oro dell'attacco in superficie (la nave
 * si staglia contro il cielo chiaro, l'U-Boot e' nel buio), la luna piena alta
 * e' la peggiore nemica dell'attacco notturno, la luna nuova la migliore alleata.
 *
 * Algoritmi: posizione solare NOAA semplificata e posizione lunare a bassa
 * precisione (Meeus, cap. 47). Precisione dell'ordine del minuto d'arco per il
 * sole e di qualche decimo di grado per la luna: piu' che sufficiente qui.
 */
final class Astro
{
    /** Giorno giuliano da un istante unix. */
    public static function julianDay(int $ts): float
    {
        return $ts / 86400.0 + 2440587.5;
    }

    /** Secoli giuliani dall'epoca J2000.0. */
    private static function t(int $ts): float
    {
        return (self::julianDay($ts) - 2451545.0) / 36525.0;
    }

    // --- Sole ------------------------------------------------------------------

    /**
     * @return array{alt:float,az:float,dec:float,ha:float}
     *         altezza e azimut in gradi (azimut 0 = nord, 90 = est)
     */
    public static function sun(int $ts, float $lat, float $lon): array
    {
        $t = self::t($ts);

        $L0 = self::norm360(280.46646 + 36000.76983 * $t + 0.0003032 * $t * $t);
        $M  = self::norm360(357.52911 + 35999.05029 * $t - 0.0001537 * $t * $t);
        $Mr = deg2rad($M);

        $C = (1.914602 - 0.004817 * $t - 0.000014 * $t * $t) * sin($Mr)
           + (0.019993 - 0.000101 * $t) * sin(2 * $Mr)
           + 0.000289 * sin(3 * $Mr);

        $trueLong = $L0 + $C;
        $omega    = 125.04 - 1934.136 * $t;
        $lambda   = $trueLong - 0.00569 - 0.00478 * sin(deg2rad($omega));

        $eps = 23.439291 - 0.0130042 * $t
             + 0.00256 * cos(deg2rad($omega));

        $dec = rad2deg(asin(sin(deg2rad($eps)) * sin(deg2rad($lambda))));
        $ra  = rad2deg(atan2(cos(deg2rad($eps)) * sin(deg2rad($lambda)), cos(deg2rad($lambda))));

        $ha = self::hourAngle($ts, $lon, $ra);

        return ['alt' => self::altitude($lat, $dec, $ha), 'az' => self::azimuth($lat, $dec, $ha), 'dec' => $dec, 'ha' => $ha];
    }

    // --- Luna ------------------------------------------------------------------

    /**
     * @return array{alt:float,az:float,phase:float,illum:float,age_days:float}
     *         phase 0 = luna nuova, 0.5 = piena; illum = frazione illuminata 0-1
     */
    public static function moon(int $ts, float $lat, float $lon): array
    {
        $t = self::t($ts);

        // Longitudine media, anomalia media, elongazione (Meeus, forma ridotta).
        $Lp = self::norm360(218.316 + 481267.8813 * $t);
        $M  = self::norm360(357.5291 + 35999.0503 * $t);          // anomalia del Sole
        $Mp = self::norm360(134.963 + 477198.8676 * $t);          // anomalia della Luna
        $D  = self::norm360(297.8502 + 445267.1115 * $t);         // elongazione
        $F  = self::norm360(93.272 + 483202.0175 * $t);           // argomento di latitudine

        $lambda = $Lp
            + 6.289 * sin(deg2rad($Mp))
            - 1.274 * sin(deg2rad(2 * $D - $Mp))
            + 0.658 * sin(deg2rad(2 * $D))
            - 0.186 * sin(deg2rad($M))
            - 0.059 * sin(deg2rad(2 * $Mp - 2 * $D))
            - 0.057 * sin(deg2rad($Mp - 2 * $D + $M))
            + 0.053 * sin(deg2rad($Mp + 2 * $D))
            + 0.046 * sin(deg2rad(2 * $D - $M))
            + 0.041 * sin(deg2rad($Mp - $M));

        $beta = 5.128 * sin(deg2rad($F))
            + 0.281 * sin(deg2rad($Mp + $F))
            - 0.278 * sin(deg2rad($F - $Mp))
            - 0.173 * sin(deg2rad($F - 2 * $D));

        $eps = deg2rad(23.439291 - 0.0130042 * $t);
        $lam = deg2rad($lambda);
        $bet = deg2rad($beta);

        $ra  = rad2deg(atan2(sin($lam) * cos($eps) - tan($bet) * sin($eps), cos($lam)));
        $dec = rad2deg(asin(sin($bet) * cos($eps) + cos($bet) * sin($eps) * sin($lam)));

        $ha = self::hourAngle($ts, $lon, $ra);

        // Fase: angolo di elongazione dal Sole.
        $phaseAngle = self::norm360($D + 6.289 * sin(deg2rad($Mp)) - 2.1 * sin(deg2rad($M)));
        $illum = (1 - cos(deg2rad($phaseAngle))) / 2;
        $phase = $phaseAngle / 360.0;

        return [
            'alt'      => self::altitude($lat, $dec, $ha),
            'az'       => self::azimuth($lat, $dec, $ha),
            'phase'    => $phase,
            'illum'    => $illum,
            'age_days' => $phase * 29.530588,
        ];
    }

    /** Nome italiano della fase, come lo scriverebbe il navigatore. */
    public static function phaseName(float $phase): string
    {
        $p = fmod($phase + 1.0, 1.0);
        return match (true) {
            $p < 0.0625 || $p >= 0.9375 => 'luna nuova',
            $p < 0.1875 => 'luna crescente',
            $p < 0.3125 => 'primo quarto',
            $p < 0.4375 => 'gibbosa crescente',
            $p < 0.5625 => 'luna piena',
            $p < 0.6875 => 'gibbosa calante',
            $p < 0.8125 => 'ultimo quarto',
            default     => 'luna calante',
        };
    }

    // --- Luce ------------------------------------------------------------------

    /**
     * Livello di luce 0-1 dove 1 e' pieno giorno sereno.
     *
     * Le soglie sono quelle nautiche reali: crepuscolo civile 0/-6°,
     * nautico -6/-12°, astronomico -12/-18°. Sotto i -18° e' notte piena e
     * conta solo la luna (e un fondo di luce stellare).
     *
     * @param float $cloud copertura nuvolosa 0-1
     */
    public static function lightLevel(int $ts, float $lat, float $lon, float $cloud = 0.0): float
    {
        $sun  = self::sun($ts, $lat, $lon);
        $moon = self::moon($ts, $lat, $lon);
        return self::lightFrom($sun['alt'], $moon['alt'], $moon['illum'], $cloud);
    }

    /** Come lightLevel ma su valori gia' calcolati (evita di rifare i conti). */
    public static function lightFrom(float $sunAlt, float $moonAlt, float $moonIllum, float $cloud = 0.0): float
    {
        $cloud = max(0.0, min(1.0, $cloud));

        if ($sunAlt >= 0.0) {
            // Giorno: la nuvolosita' toglie poco, l'occhio compensa.
            $l = 1.0 - 0.25 * $cloud;
        } elseif ($sunAlt >= -6.0) {          // crepuscolo civile
            $l = self::lerp(0.30, 1.0, ($sunAlt + 6.0) / 6.0) * (1.0 - 0.3 * $cloud);
        } elseif ($sunAlt >= -12.0) {         // crepuscolo nautico
            $l = self::lerp(0.10, 0.30, ($sunAlt + 12.0) / 6.0) * (1.0 - 0.35 * $cloud);
        } elseif ($sunAlt >= -18.0) {         // crepuscolo astronomico
            $l = self::lerp(0.02, 0.10, ($sunAlt + 18.0) / 6.0) * (1.0 - 0.4 * $cloud);
        } else {
            $l = 0.008;                        // luce delle stelle
        }

        // Contributo lunare: conta solo se la luna e' sopra l'orizzonte, cresce
        // con l'altezza e con la frazione illuminata, e le nuvole lo spengono.
        if ($moonAlt > 0.0 && $sunAlt < -6.0) {
            $h = min(1.0, $moonAlt / 45.0);
            $luna = 0.085 * $moonIllum * (0.35 + 0.65 * $h) * (1.0 - 0.85 * $cloud);
            $l = max($l, $l + $luna);
        }

        return max(0.0, min(1.0, $l));
    }

    /** Etichetta della fase del giorno, per la plancia. */
    public static function dayPhase(float $sunAlt): string
    {
        return match (true) {
            $sunAlt >= 6.0   => 'giorno',
            $sunAlt >= 0.0   => 'sole basso',
            $sunAlt >= -6.0  => 'crepuscolo civile',
            $sunAlt >= -12.0 => 'crepuscolo nautico',
            $sunAlt >= -18.0 => 'crepuscolo astronomico',
            default          => 'notte',
        };
    }

    // --- Comodita' -------------------------------------------------------------

    /** Tempo siderale di Greenwich in gradi. */
    private static function gmst(int $ts): float
    {
        $jd = self::julianDay($ts);
        $d  = $jd - 2451545.0;
        return self::norm360(280.46061837 + 360.98564736629 * $d);
    }

    private static function hourAngle(int $ts, float $lon, float $ra): float
    {
        return self::norm180(self::gmst($ts) + $lon - $ra);
    }

    private static function altitude(float $lat, float $dec, float $ha): float
    {
        $p = deg2rad($lat);
        $d = deg2rad($dec);
        $h = deg2rad($ha);
        return rad2deg(asin(sin($p) * sin($d) + cos($p) * cos($d) * cos($h)));
    }

    private static function azimuth(float $lat, float $dec, float $ha): float
    {
        $p = deg2rad($lat);
        $d = deg2rad($dec);
        $h = deg2rad($ha);
        $az = atan2(-sin($h), cos($p) * tan($d) - sin($p) * cos($h));
        return self::norm360(rad2deg($az));
    }

    private static function norm360(float $a): float
    {
        $a = fmod($a, 360.0);
        return $a < 0 ? $a + 360.0 : $a;
    }

    private static function norm180(float $a): float
    {
        return fmod(self::norm360($a) + 180.0, 360.0) - 180.0;
    }

    private static function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * max(0.0, min(1.0, $t));
    }
}
