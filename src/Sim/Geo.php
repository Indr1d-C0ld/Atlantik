<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Geometria sulla sfera. Distanze in miglia nautiche, angoli in gradi veri
 * (0 = nord, 90 = est), coordinate in gradi decimali (nord e est positivi).
 */
final class Geo
{
    /** Raggio terrestre in miglia nautiche (sfera media). */
    public const R_NM = 3440.065;

    /** Distanza ortodromica in miglia nautiche (formula dell'emisenoverso). */
    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad(self::normLonDelta($lon2 - $lon1));

        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return self::R_NM * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** Rilevamento iniziale della rotta ortodromica, in gradi veri 0-360. */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad(self::normLonDelta($lon2 - $lon1));

        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return self::normBearing(rad2deg(atan2($y, $x)));
    }

    /**
     * Punto raggiunto partendo da (lat,lon) su rotta $bearing per $distNm.
     *
     * @return array{0:float,1:float} [lat, lon]
     */
    public static function destination(float $lat, float $lon, float $bearing, float $distNm): array
    {
        $d  = $distNm / self::R_NM;
        $b  = deg2rad(self::normBearing($bearing));
        $p1 = deg2rad($lat);
        $l1 = deg2rad($lon);

        $p2 = asin(sin($p1) * cos($d) + cos($p1) * sin($d) * cos($b));
        $l2 = $l1 + atan2(sin($b) * sin($d) * cos($p1), cos($d) - sin($p1) * sin($p2));

        return [rad2deg($p2), self::normLon(rad2deg($l2))];
    }

    /**
     * Distanza minima fra un punto e il segmento percorso fra due punti, in
     * miglia nautiche.
     *
     * Serve per i siluri: a trenta nodi con passo di dieci secondi un siluro
     * avanza centocinquanta metri per passo, mentre un bersaglio e' largo
     * sessanta. Controllare solo i punti di arrivo significherebbe passare
     * attraverso le navi senza toccarle — quindi si controlla la traiettoria,
     * non le fotografie.
     */
    public static function distanzaDaSegmento(
        float $latA, float $lonA,
        float $latB, float $lonB,
        float $latP, float $lonP,
    ): float {
        // Piano locale in miglia nautiche, con l'origine in A.
        $kLat = 60.0;
        $kLon = 60.0 * cos(deg2rad(($latA + $latB) / 2));

        $bx = ($lonB - $lonA) * $kLon;
        $by = ($latB - $latA) * $kLat;
        $px = (self::normLon($lonP - $lonA)) * $kLon;
        $py = ($latP - $latA) * $kLat;

        $len2 = $bx * $bx + $by * $by;
        if ($len2 < 1e-12) {
            return sqrt($px * $px + $py * $py);
        }

        $t = max(0.0, min(1.0, ($px * $bx + $py * $by) / $len2));
        $dx = $px - $t * $bx;
        $dy = $py - $t * $by;
        return sqrt($dx * $dx + $dy * $dy);
    }

    /** Differenza di rilevamento piu' breve, con segno, in [-180,180]. */
    public static function bearingDelta(float $from, float $to): float
    {
        $d = fmod($to - $from + 540.0, 360.0) - 180.0;
        return $d;
    }

    public static function normBearing(float $b): float
    {
        $b = fmod($b, 360.0);
        return $b < 0 ? $b + 360.0 : $b;
    }

    public static function normLon(float $lon): float
    {
        $lon = fmod($lon + 180.0, 360.0);
        if ($lon < 0) {
            $lon += 360.0;
        }
        return $lon - 180.0;
    }

    private static function normLonDelta(float $d): float
    {
        return fmod($d + 540.0, 360.0) - 180.0;
    }

    /** "52°14,3' N  019°47,6' W" — la forma in cui si leggono le carte. */
    public static function formatLat(float $lat): string
    {
        return self::formatDeg(abs($lat), 2) . ' ' . ($lat >= 0 ? 'N' : 'S');
    }

    public static function formatLon(float $lon): string
    {
        return self::formatDeg(abs($lon), 3) . ' ' . ($lon >= 0 ? 'E' : 'W');
    }

    private static function formatDeg(float $v, int $pad): string
    {
        // Si arrotonda ai decimi di primo PRIMA di separare gradi e primi.
        //
        // Fino al 23/09/2026 si separava prima e si arrotondava dopo, e da
        // 52,9994° usciva «52°60,0'»: i primi erano 59,96, number_format li
        // portava a 60,0 e il grado non scattava. Una coordinata che nessuna
        // carta ammette, una posizione su milleduecento, e finiva nel giornale
        // di guerra. Contando in decimi di primo il riporto lo fa l'aritmetica.
        $decimi = (int) round($v * 600.0);
        $deg = intdiv($decimi, 600);
        $min = ($decimi % 600) / 10.0;
        // I primi sempre su due cifre, come sulle carte: «45°06,0'» e non «45°6,0'».
        return str_pad((string) $deg, $pad, '0', STR_PAD_LEFT) . '°'
            . str_replace('.', ',', str_pad(number_format($min, 1, '.', ''), 4, '0', STR_PAD_LEFT)) . "'";
    }
}
