<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Meteo dell'Atlantico — campo continuo, deterministico, senza stato.
 *
 * Non ci sono "celle meteo" salvate a database: il tempo e' una FUNZIONE PURA
 * di (seme del mondo, istante di gioco, posizione). Due giocatori nello stesso
 * punto alla stessa ora vedono lo stesso mare, il tick non deve far avanzare
 * nulla, e il meteo di ieri o di domani si calcola quando serve. Il campo e'
 * continuo nello spazio e nel tempo: niente salti fra una cella e l'altra.
 *
 * Costruzione: un campo di pressione a rumore multi-ottava che DERIVA VERSO EST
 * (come i sistemi atlantici reali, 20-25 nodi), da cui si ricava il vento per
 * gradiente, e dal vento — con memoria delle ultime ore — lo stato del mare.
 * L'ampiezza stagionale e' quella vera: l'Atlantico del nord in gennaio e'
 * un'altra cosa rispetto ad agosto.
 */
final class Weather
{
    /** Deriva dei sistemi verso est, in gradi di longitudine all'ora. */
    private const DERIVA_LON_H = 0.62;   // ~25 nodi alle medie latitudini

    /**
     * Tutto il tempo in un punto e in un istante.
     *
     * @return array{
     *   pressure_hpa:float, wind_dir:float, wind_kn:float, beaufort:int,
     *   sea_state:int, wave_m:float, cloud:float, precip:float,
     *   visibility_nm:float, fog:bool, air_c:float, sea_c:float,
     *   descrizione:string
     * }
     */
    public static function at(int $seed, int $gameTs, float $lat, float $lon, ?\DateTimeImmutable $data = null): array
    {
        $mese = (int) ($data?->format('n') ?? 1);

        $p = self::pressure($seed, $gameTs, $lat, $lon, $mese);

        // Gradiente su una base di mezzo grado: abbastanza largo da non
        // amplificare il rumore, abbastanza stretto da seguire i fronti.
        $dLat = 0.5;
        $dLon = 0.5;
        $pN = self::pressure($seed, $gameTs, $lat + $dLat, $lon, $mese);
        $pS = self::pressure($seed, $gameTs, $lat - $dLat, $lon, $mese);
        $pE = self::pressure($seed, $gameTs, $lat, $lon + $dLon, $mese);
        $pW = self::pressure($seed, $gameTs, $lat, $lon - $dLon, $mese);

        // hPa per 100 km
        $kmLat = $dLat * 111.0;
        $kmLon = $dLon * 111.0 * max(0.15, cos(deg2rad($lat)));
        $gy = ($pN - $pS) / (2 * $kmLat) * 100.0;    // verso nord
        $gx = ($pE - $pW) / (2 * $kmLon) * 100.0;    // verso est

        $grad = sqrt($gx * $gx + $gy * $gy);
        $vento = min(75.0, 9.4 * $grad);             // nodi

        // Vento geostrofico: lungo le isobare, bassa pressione a sinistra
        // nell'emisfero nord, piu' un angolo d'afflusso per l'attrito del mare.
        $dirGrad = Geo::normBearing(rad2deg(atan2($gx, $gy)));   // verso la pressione crescente
        $segno   = $lat >= 0 ? 1.0 : -1.0;
        $dirDa   = Geo::normBearing($dirGrad + $segno * 90.0 + $segno * 20.0);

        // Un fondo di vento c'e' sempre: il mare piatto assoluto e' raro.
        $vento = max(3.0, $vento + 4.0 * self::noise($seed + 77, $lat / 6.0, $lon / 9.0, $gameTs / 36000.0));

        // Stato del mare: il mare ha memoria. Si pesa il vento delle ultime ore,
        // perche' l'onda non nasce ne' muore insieme alla raffica.
        $ventoEff = 0.55 * $vento
            + 0.30 * self::ventoSemplice($seed, $gameTs - 6 * 3600, $lat, $lon, $mese)
            + 0.15 * self::ventoSemplice($seed, $gameTs - 12 * 3600, $lat, $lon, $mese);

        $onda = 0.00656 * $ventoEff * $ventoEff;      // altezza significativa in metri
        $mare = self::douglas($onda);

        // Nuvole: legate alla depressione, con una componente propria.
        $cloud = self::clamp01(
            0.45 + (1013.0 - $p) / 45.0
            + 0.25 * self::noise($seed + 131, $lat / 5.0, $lon / 7.0, $gameTs / 28800.0)
        );

        $precip = self::clamp01(($cloud - 0.62) / 0.38) * self::clamp01((1010.0 - $p) / 22.0);

        // Nebbia: aria mite su acqua fredda. I Banchi di Terranova in primavera
        // e all'inizio dell'estate sono il posto piu' nebbioso dell'Atlantico.
        $nebbiaClima = self::climaNebbia($lat, $lon, $mese);
        $nebbiaRumore = self::clamp01(self::noise($seed + 313, $lat / 3.0, $lon / 4.0, $gameTs / 21600.0) * 0.5 + 0.5);
        $nebbia = $nebbiaClima > 0.0 && $vento < 12.0 && $nebbiaRumore > (1.0 - $nebbiaClima);

        $vis = 20.0;
        $vis -= 12.0 * $precip;
        $vis -= 4.0 * max(0.0, $cloud - 0.5);
        if ($nebbia) {
            $vis = min($vis, 0.3 + 1.2 * $nebbiaRumore);
        }
        $vis = max(0.15, min(22.0, $vis));

        $mareC = self::tempMare($lat, $lon, $mese);
        $ariaC = $mareC + 2.0 * self::noise($seed + 917, $lat / 4.0, $lon / 6.0, $gameTs / 43200.0) - 1.0;

        return [
            'pressure_hpa'  => round($p, 1),
            'wind_dir'      => round($dirDa, 0),
            'wind_kn'       => round($vento, 1),
            'beaufort'      => self::beaufort($vento),
            'sea_state'     => $mare,
            'wave_m'        => round($onda, 2),
            'cloud'         => round($cloud, 2),
            'precip'        => round($precip, 2),
            'visibility_nm' => round($vis, 1),
            'fog'           => $nebbia,
            'air_c'         => round($ariaC, 1),
            'sea_c'         => round($mareC, 1),
            'descrizione'   => self::descrizione(self::beaufort($vento), $mare, $vis, $nebbia, $precip, $cloud),
        ];
    }

    // --- Campo di pressione ----------------------------------------------------

    private static function pressure(int $seed, int $gameTs, float $lat, float $lon, int $mese): float
    {
        $ore = $gameTs / 3600.0;
        // I sistemi viaggiano verso est: a longitudine fissa, col passare del
        // tempo arriva cio' che stava a ponente.
        $lonS = $lon - self::DERIVA_LON_H * $ore;

        $amp = self::ampiezzaStagionale($lat, $mese);

        $v = 0.0;
        $v += 1.00 * self::noise($seed + 1, $lat / 9.0,  $lonS / 15.0, $ore / 900.0);
        $v += 0.55 * self::noise($seed + 2, $lat / 4.5,  $lonS / 7.5,  $ore / 400.0);
        $v += 0.28 * self::noise($seed + 3, $lat / 2.2,  $lonS / 3.8,  $ore / 180.0);

        // Anticiclone delle Azzorre: una presenza costante fra i 25 e i 40 gradi.
        $azzorre = 9.0 * exp(-(($lat - 33.0) ** 2) / 260.0) * exp(-(($lon + 28.0) ** 2) / 900.0);
        // Depressione d'Islanda: l'officina delle burrasche atlantiche.
        $islanda = -11.0 * exp(-(($lat - 62.0) ** 2) / 200.0) * exp(-(($lon + 22.0) ** 2) / 1100.0)
            * (1.0 + 0.5 * self::fattoreInverno($mese));

        return 1013.0 + $v * $amp / 1.83 + $azzorre + $islanda;
    }

    /** Vento senza memoria: serve solo per costruire la memoria del mare. */
    private static function ventoSemplice(int $seed, int $gameTs, float $lat, float $lon, int $mese): float
    {
        $d = 0.5;
        $pN = self::pressure($seed, $gameTs, $lat + $d, $lon, $mese);
        $pS = self::pressure($seed, $gameTs, $lat - $d, $lon, $mese);
        $pE = self::pressure($seed, $gameTs, $lat, $lon + $d, $mese);
        $pW = self::pressure($seed, $gameTs, $lat, $lon - $d, $mese);
        $kmLat = $d * 111.0;
        $kmLon = $d * 111.0 * max(0.15, cos(deg2rad($lat)));
        $gy = ($pN - $pS) / (2 * $kmLat) * 100.0;
        $gx = ($pE - $pW) / (2 * $kmLon) * 100.0;
        return max(3.0, min(75.0, 9.4 * sqrt($gx * $gx + $gy * $gy)));
    }

    /**
     * Ampiezza dei sistemi: massima alle latitudini della rotta dei convogli,
     * e molto maggiore d'inverno. E' il motivo per cui l'inverno 1942-43 fu
     * quello che fu.
     */
    private static function ampiezzaStagionale(float $lat, int $mese): float
    {
        $fascia = exp(-(($lat - 53.0) ** 2) / 700.0);        // culmine sulla rotta HX/SC
        $base   = 8.0 + 16.0 * $fascia;
        return $base * (0.66 + 0.46 * self::fattoreInverno($mese));
    }

    /** 1 a gennaio, 0 a luglio (emisfero nord). */
    private static function fattoreInverno(int $mese): float
    {
        return (cos(2 * M_PI * ($mese - 1) / 12.0) + 1.0) / 2.0;
    }

    // --- Climatologie ----------------------------------------------------------

    /** Propensione alla nebbia, 0-1. */
    private static function climaNebbia(float $lat, float $lon, int $mese): float
    {
        // Banchi di Terranova: aria della Corrente del Golfo sopra la fredda
        // Corrente del Labrador. Massimo fra aprile e agosto.
        $banchi = exp(-(($lat - 45.0) ** 2) / 90.0) * exp(-(($lon + 50.0) ** 2) / 260.0);
        $stagione = $mese >= 4 && $mese <= 8 ? 1.0 : 0.35;
        $nord = exp(-(($lat - 62.0) ** 2) / 260.0) * 0.45;   // mari d'Islanda e Groenlandia
        return self::clamp01(($banchi * 0.85 + $nord) * $stagione);
    }

    /** Temperatura superficiale del mare, in gradi. */
    private static function tempMare(float $lat, float $lon, int $mese): float
    {
        $t = 29.0 - 0.42 * abs($lat);                        // profilo per latitudine
        // Corrente del Golfo e Deriva nordatlantica: il ramo caldo che tiene
        // navigabili le rotte a nord-est.
        $golfo = 6.5 * exp(-(($lat - 45.0 - 0.25 * ($lon + 60.0)) ** 2) / 120.0);
        // Corrente del Labrador: il ramo freddo che scende lungo Terranova.
        $labrador = -5.0 * exp(-(($lat - 50.0) ** 2) / 150.0) * exp(-(($lon + 52.0) ** 2) / 200.0);
        $stagione = -3.2 * self::fattoreInverno($mese) + 1.6;
        return $t + $golfo + $labrador + $stagione;
    }

    // --- Scale -----------------------------------------------------------------

    public static function beaufort(float $kn): int
    {
        $soglie = [1, 4, 7, 11, 17, 22, 28, 34, 41, 48, 56, 64];
        $b = 0;
        foreach ($soglie as $i => $s) {
            if ($kn >= $s) {
                $b = $i + 1;
            }
        }
        return min(12, $b);
    }

    /** Scala Douglas dello stato del mare, dall'altezza d'onda significativa. */
    public static function douglas(float $onda_m): int
    {
        return match (true) {
            $onda_m < 0.01 => 0,
            $onda_m < 0.10 => 1,
            $onda_m < 0.50 => 2,
            $onda_m < 1.25 => 3,
            $onda_m < 2.50 => 4,
            $onda_m < 4.00 => 5,
            $onda_m < 6.00 => 6,
            $onda_m < 9.00 => 7,
            $onda_m < 14.0 => 8,
            default        => 9,
        };
    }

    public static function nomeMare(int $douglas): string
    {
        return [
            0 => 'calmo come uno specchio',
            1 => 'quasi calmo',
            2 => 'poco mosso',
            3 => 'mosso',
            4 => 'molto mosso',
            5 => 'agitato',
            6 => 'molto agitato',
            7 => 'grosso',
            8 => 'molto grosso',
            9 => 'tempestoso',
        ][$douglas] ?? '—';
    }

    public static function nomeVento(int $beaufort): string
    {
        return [
            0 => 'calma', 1 => 'bava di vento', 2 => 'brezza leggera', 3 => 'brezza tesa',
            4 => 'vento moderato', 5 => 'vento teso', 6 => 'vento fresco', 7 => 'vento forte',
            8 => 'burrasca', 9 => 'burrasca forte', 10 => 'tempesta', 11 => 'tempesta violenta',
            12 => 'uragano',
        ][$beaufort] ?? '—';
    }

    /** Rosa dei venti a sedici punte, come la direbbe l'ufficiale di guardia. */
    public static function rosa(float $dir): string
    {
        $punti = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        return $punti[(int) round(Geo::normBearing($dir) / 22.5) % 16];
    }

    private static function descrizione(int $bf, int $mare, float $vis, bool $nebbia, float $precip, float $cloud): string
    {
        $parti = [];
        $parti[] = self::nomeVento($bf);
        $parti[] = 'mare ' . self::nomeMare($mare);
        if ($nebbia) {
            $parti[] = 'nebbia fitta';
        } elseif ($precip > 0.45) {
            $parti[] = 'pioggia battente';
        } elseif ($precip > 0.15) {
            $parti[] = 'pioviggine';
        } elseif ($cloud > 0.75) {
            $parti[] = 'cielo coperto';
        } elseif ($cloud < 0.25) {
            $parti[] = 'cielo sereno';
        }
        if (!$nebbia && $vis < 6.0) {
            $parti[] = 'foschia';
        }
        return implode(', ', $parti);
    }

    // --- Rumore ----------------------------------------------------------------

    /**
     * Rumore di valore a tre dimensioni, in [-1,1], con interpolazione morbida.
     * Deterministico: stessa terna, stesso valore, per sempre.
     */
    private static function noise(int $seed, float $x, float $y, float $z): float
    {
        $x0 = (int) floor($x); $y0 = (int) floor($y); $z0 = (int) floor($z);
        $fx = self::smooth($x - $x0); $fy = self::smooth($y - $y0); $fz = self::smooth($z - $z0);

        $c = [];
        for ($i = 0; $i <= 1; $i++) {
            for ($j = 0; $j <= 1; $j++) {
                for ($k = 0; $k <= 1; $k++) {
                    $c[$i][$j][$k] = self::hash01($seed, $x0 + $i, $y0 + $j, $z0 + $k);
                }
            }
        }

        $x00 = self::lerp($c[0][0][0], $c[1][0][0], $fx);
        $x10 = self::lerp($c[0][1][0], $c[1][1][0], $fx);
        $x01 = self::lerp($c[0][0][1], $c[1][0][1], $fx);
        $x11 = self::lerp($c[0][1][1], $c[1][1][1], $fx);

        $y0i = self::lerp($x00, $x10, $fy);
        $y1i = self::lerp($x01, $x11, $fy);

        return self::lerp($y0i, $y1i, $fz) * 2.0 - 1.0;
    }

    /**
     * Hash intero -> [0,1). Tutta l'aritmetica resta dentro i 32 bit con
     * maschera esplicita: senza, la moltiplicazione trabocca dai 64 bit, PHP
     * passa al virgola mobile e il rumore smette di essere deterministico
     * (oltre a riempire i log di avvisi).
     */
    private static function hash01(int $seed, int $x, int $y, int $z): float
    {
        $s = ($seed ^ ($seed >> 16)) & 0xFFFF;
        $h = ($s * 0x9E3779B1
            + ($x & 0xFFFF) * 0x85EBCA77
            + ($y & 0xFFFF) * 0xC2B2AE3D
            + ($z & 0xFFFF) * 0x27D4EB2F) & 0xFFFFFFFF;

        $h ^= $h >> 15;
        $h = ($h * 0x2545F491) & 0xFFFFFFFF;
        $h ^= $h >> 13;
        $h = ($h * 0x045D9F3B) & 0xFFFFFFFF;
        $h ^= $h >> 16;

        return $h / 4294967296.0;
    }

    private static function smooth(float $t): float
    {
        return $t * $t * (3.0 - 2.0 * $t);
    }

    private static function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
