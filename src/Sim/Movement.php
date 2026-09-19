<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Moto del battello: velocita' effettiva, quota, correnti, scarroccio, e la
 * lenta divergenza fra dove sei e dove credi di essere.
 *
 * La posizione stimata non e' la posizione vera con un errore appiccicato
 * sopra: e' il risultato di una navigazione stimata onesta — rotta e velocita'
 * proprie, senza la corrente, che il battello non puo' misurare. L'errore
 * cresce percio' NELLA DIREZIONE DELLA CORRENTE, come accadeva davvero, piu'
 * un cammino casuale per gli errori di solcometro e di bussola.
 */
final class Movement
{
    /** Velocita' massima a quota periscopica: oltre, l'astronomo vibra e si vede la scia. */
    public const MAX_KN_PERISCOPIO = 6.0;

    /** Quota di riferimento della quota periscopica, in metri. */
    public const QUOTA_PERISCOPIO = 12.0;

    /**
     * Velocita' massima consentita ora: dipende dal modo, dal tipo e dal mare.
     * Con mare grosso in superficie non e' prudenza, e' fisica: la prua entra
     * nell'onda e il battello rallenta comunque.
     */
    public static function maxSpeed(array $type, string $mode, int $seaState): float
    {
        if ($mode === 'superficie') {
            $max = (float) $type['speed_surf_kn'];
            if ($seaState >= 7) {
                $max = min($max, 9.0);
            } elseif ($seaState >= 4) {
                $max *= 1.0 - 0.055 * ($seaState - 3);
            }
            return max(2.0, $max);
        }
        if ($mode === 'periscopio') {
            return min(self::MAX_KN_PERISCOPIO, (float) $type['speed_sub_kn']);
        }
        return (float) $type['speed_sub_kn'];
    }

    /** Modo corrispondente a una quota. */
    public static function modeForDepth(float $depth): string
    {
        if ($depth < 3.0) {
            return 'superficie';
        }
        if ($depth <= 16.0) {
            return 'periscopio';
        }
        return 'immersione';
    }

    /**
     * Avvicina la quota a quella ordinata nel tempo dato.
     * L'immersione rapida e' molto piu' veloce della discesa controllata.
     */
    public static function stepDepth(float $depth, float $ordered, array $type, int $dt, bool $rapida = false): float
    {
        if (abs($ordered - $depth) < 0.05) {
            return $ordered;
        }
        // Metri al secondo: l'immersione rapida porta a quota periscopica nel
        // tempo dichiarato dal tipo; la manovra normale e' circa un terzo.
        $veloce = self::QUOTA_PERISCOPIO / max(5.0, (float) $type['dive_time_s']);
        $rate = $rapida ? $veloce : $veloce * 0.35;
        if ($ordered < $depth) {
            $rate *= 0.8;           // riemergere e' piu' lento: si soffia con l'aria
        }
        $delta = $rate * $dt;
        return $ordered > $depth ? min($ordered, $depth + $delta) : max($ordered, $depth - $delta);
    }

    /**
     * Corrente superficiale nel punto: direzione (verso cui scorre) e nodi.
     * Modello a nuclei gaussiani sulle grandi correnti atlantiche reali.
     *
     * @return array{0:float,1:float} [direzione in gradi veri, nodi]
     */
    public static function current(float $lat, float $lon): array
    {
        $vx = 0.0;   // est
        $vy = 0.0;   // nord

        // Corrente del Golfo e Deriva nordatlantica: dalla Florida verso
        // nord-est, poi verso le isole britanniche e la Norvegia.
        $asse = 32.0 + 0.45 * ($lon + 76.0);              // latitudine dell'asse
        $forza = 3.0 * exp(-(($lat - $asse) ** 2) / 20.0) * exp(-(($lon + 62.0) ** 2) / 900.0);
        if ($lon > -82.0 && $lon < 5.0 && $lat > 24.0 && $lat < 66.0) {
            $dir = deg2rad(42.0 + 0.40 * ($lon + 74.0));   // ruota verso est salendo di latitudine
            $vx += $forza * sin($dir);
            $vy += $forza * cos($dir);
        }

        // Corrente del Labrador: fredda, da nord verso sud lungo Terranova.
        $lab = 1.1 * exp(-(($lon + 53.0) ** 2) / 90.0) * exp(-(($lat - 52.0) ** 2) / 320.0);
        $vy -= $lab;
        $vx -= $lab * 0.35;

        // Corrente delle Canarie: verso sud-ovest lungo il Marocco.
        $can = 0.7 * exp(-(($lon + 15.0) ** 2) / 110.0) * exp(-(($lat - 27.0) ** 2) / 260.0);
        $vy -= $can * 0.8;
        $vx -= $can * 0.6;

        // Corrente nord-equatoriale: verso ponente ai tropici.
        $eq = 0.9 * exp(-(($lat - 12.0) ** 2) / 120.0);
        $vx -= $eq;

        $kn = sqrt($vx * $vx + $vy * $vy);
        if ($kn < 0.02) {
            return [0.0, 0.0];
        }
        return [Geo::normBearing(rad2deg(atan2($vx, $vy))), $kn];
    }

    /**
     * Scarroccio: quanto il vento sposta il battello. In superficie la torre e
     * lo scafo offrono fianco al vento; in immersione l'effetto sparisce.
     *
     * @return array{0:float,1:float} [direzione, nodi]
     */
    public static function leeway(float $windDirDa, float $windKn, string $mode): array
    {
        if ($mode === 'immersione') {
            return [0.0, 0.0];
        }
        $fattore = $mode === 'superficie' ? 0.028 : 0.008;
        // Il vento "da" 270 spinge "verso" 90.
        return [Geo::normBearing($windDirDa + 180.0), $windKn * $fattore];
    }

    /**
     * Un passo di moto.
     *
     * @param array{lat:float,lon:float,heading:float,speed:float} $stato
     * @return array{lat:float,lon:float,heading:float,dist_nm:float,drift_nm:float,drift_dir:float}
     */
    public static function step(
        float $lat,
        float $lon,
        float $heading,
        float $speedKn,
        int $dtSeconds,
        float $windDirDa,
        float $windKn,
        string $mode,
    ): array {
        $ore = $dtSeconds / 3600.0;

        // Moto proprio.
        $dist = $speedKn * $ore;
        [$lat2, $lon2] = $dist > 0
            ? Geo::destination($lat, $lon, $heading, $dist)
            : [$lat, $lon];

        // Corrente e scarroccio, sommati come vettori.
        [$cDir, $cKn] = self::current($lat, $lon);
        [$lDir, $lKn] = self::leeway($windDirDa, $windKn, $mode);

        $dx = $cKn * sin(deg2rad($cDir)) + $lKn * sin(deg2rad($lDir));
        $dy = $cKn * cos(deg2rad($cDir)) + $lKn * cos(deg2rad($lDir));
        $driftKn = sqrt($dx * $dx + $dy * $dy);
        $driftDir = $driftKn > 0.001 ? Geo::normBearing(rad2deg(atan2($dx, $dy))) : 0.0;
        $driftNm = $driftKn * $ore;

        if ($driftNm > 0.0001) {
            [$lat2, $lon2] = Geo::destination($lat2, $lon2, $driftDir, $driftNm);
        }

        return [
            'lat'       => $lat2,
            'lon'       => $lon2,
            'heading'   => $heading,
            'dist_nm'   => $dist,
            'drift_nm'  => $driftNm,
            'drift_dir' => $driftDir,
        ];
    }

    /**
     * Passo della navigazione stimata.
     *
     * L'Obersteuermann non e' cieco: conosce le tavole delle correnti e stima a
     * occhio lo scarroccio, quindi una parte della deriva la mette in conto —
     * ma solo una parte, e non sa quale. Resta percio' un errore sistematico
     * nella direzione della corrente, piu' gli errori di solcometro e bussola.
     * E' il meccanismo per cui, dopo giorni di cielo coperto, la posizione
     * sulla carta e quella vera non coincidono piu'.
     *
     * @return array{0:float,1:float} [lat stimata, lon stimata]
     */
    public static function stepEstimated(
        float $estLat,
        float $estLon,
        float $heading,
        float $speedKn,
        int $dtSeconds,
        int $seaState,
        Rng $rng,
        float $driftDir = 0.0,
        float $driftNm = 0.0,
    ): array {
        $ore = $dtSeconds / 3600.0;

        // Errore del solcometro: cresce col mare grosso (l'elica cavita, la
        // velocita' letta non e' quella fatta buona).
        $erroreVel = 1.0 + $rng->gauss() * (0.012 + 0.006 * $seaState);
        $erroreRot = $rng->gauss() * (0.5 + 0.35 * $seaState);

        $dist = max(0.0, $speedKn * $erroreVel) * $ore;
        $lat = $estLat;
        $lon = $estLon;

        if ($dist > 0) {
            [$lat, $lon] = Geo::destination($lat, $lon, Geo::normBearing($heading + $erroreRot), $dist);
        }

        // Deriva messa in conto dal navigatore: fra meta' e quattro quinti di
        // quella vera, con la direzione approssimata.
        if ($driftNm > 0.0001) {
            $quota = $rng->range(0.45, 0.8);
            [$lat, $lon] = Geo::destination(
                $lat,
                $lon,
                Geo::normBearing($driftDir + $rng->gauss() * 12.0),
                $driftNm * $quota
            );
        }

        return [$lat, $lon];
    }

    /**
     * Si puo' fare il punto astronomico? Serve essere in superficie, con mare
     * non impossibile, cielo abbastanza sereno e un astro utilizzabile: il
     * sole abbastanza alto, oppure le stelle nel crepuscolo nautico (quando
     * si vedono insieme stelle e orizzonte: la finestra vera del sestante).
     */
    public static function canTakeFix(string $mode, int $seaState, float $cloud, float $sunAlt, bool $fog): bool
    {
        if ($mode !== 'superficie' || $fog || $seaState > 6 || $cloud > 0.55) {
            return false;
        }
        $sole   = $sunAlt >= 8.0 && $sunAlt <= 75.0;
        $stelle = $sunAlt <= -3.0 && $sunAlt >= -12.0;
        return $sole || $stelle;
    }

    /** Precisione del punto ottenuto, in miglia: dipende da mare e cielo. */
    public static function fixAccuracyNm(int $seaState, float $cloud, Rng $rng): float
    {
        $base = 0.4 + 0.22 * $seaState + 0.8 * $cloud;
        return round(max(0.2, $base * $rng->range(0.7, 1.4)), 2);
    }
}
