<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Marinequadrat — il sistema di quadrettatura della Kriegsmarine
 * (Gradnetzmeldeverfahren). Il comandante non pensa in latitudine e longitudine:
 * pensa in "Quadrat AL 0278".
 *
 * GEOMETRIA (documentata):
 *   - Grande quadrato identificato da due lettere, lato ~486 miglia nautiche
 *     (~900 km).
 *   - Suddivisione ricorsiva in matrici 3x3 numerate 1-9 per righe:
 *         1 2 3
 *         4 5 6
 *         7 8 9
 *   - Quattro cifre = quattro livelli: 486 -> 162 -> 54 -> 18 -> 6 nm.
 *     Sei caratteri (due lettere, quattro cifre) individuano un punto con la
 *     precisione di circa 6 miglia: quella con cui si davano i contatti.
 *
 * LETTERE: la tabella completa delle sigle non e' documentata in modo
 * accessibile. Qui il reticolo e' esatto e le sigle sono DATI
 * (db/seed/marinequadrat.php): quattro quadrati sono ancorati a riferimenti
 * storici, gli altri sono ricostruiti e marcati come tali. Correggerli in
 * seguito e' una modifica di dati, non di codice. Vedi docs/FONTI.md.
 */
final class Grid
{
    /** Altezza di un grande quadrato, in gradi di latitudine (8° = 480 nm ~ 486). */
    public const BAND_DEG = 8.0;
    /** Larghezza di un grande quadrato, in gradi di longitudine. */
    public const COL_DEG = 12.0;
    /** Latitudine del bordo superiore della prima banda. */
    public const LAT_TOP = 73.0;
    /** Longitudine del bordo occidentale della prima colonna. */
    public const LON_WEST = -72.0;

    /** @var array<string,array{row:int,col:int,nota:string,confidence:string}>|null */
    private static ?array $byCode = null;
    /** @var array<string,string>|null  "riga:colonna" => sigla */
    private static ?array $byCell = null;

    /** Carica la tabella delle sigle (una volta per processo). */
    private static function load(): void
    {
        if (self::$byCode !== null) {
            return;
        }
        $file = ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2)) . '/db/seed/marinequadrat.php';
        /** @var array<string,array{row:int,col:int,nota:string,confidence:string}> $tab */
        $tab = is_file($file) ? require $file : [];

        self::$byCode = $tab;
        self::$byCell = [];
        foreach ($tab as $code => $cell) {
            self::$byCell[$cell['row'] . ':' . $cell['col']] = $code;
        }
    }

    /** Riga (0 = la piu' settentrionale) della latitudine data. */
    public static function rowOf(float $lat): int
    {
        return (int) floor((self::LAT_TOP - $lat) / self::BAND_DEG);
    }

    /** Colonna (0 = la piu' occidentale) della longitudine data. */
    public static function colOf(float $lon): int
    {
        return (int) floor((Geo::normLon($lon) - self::LON_WEST) / self::COL_DEG);
    }

    /**
     * Da coordinate a quadrato, con il numero di cifre voluto (0-4).
     * Con 0 cifre restituisce solo la sigla del grande quadrato.
     * Restituisce null fuori dall'area coperta dalla tabella.
     */
    public static function toQuadrat(float $lat, float $lon, int $digits = 4): ?string
    {
        self::load();

        $row = self::rowOf($lat);
        $col = self::colOf($lon);
        $code = self::$byCell[$row . ':' . $col] ?? null;
        if ($code === null) {
            return null;
        }

        // Posizione relativa dentro il grande quadrato, in [0,1).
        $top  = self::LAT_TOP - $row * self::BAND_DEG;
        $left = self::LON_WEST + $col * self::COL_DEG;
        $fy = ($top - $lat) / self::BAND_DEG;            // 0 = bordo nord
        $fx = (Geo::normLon($lon) - $left) / self::COL_DEG; // 0 = bordo ovest

        $out = $code;
        if ($digits > 0) {
            $out .= ' ';
        }
        for ($i = 0; $i < $digits; $i++) {
            $r = min(2, max(0, (int) floor($fy * 3)));
            $c = min(2, max(0, (int) floor($fx * 3)));
            $out .= (string) ($r * 3 + $c + 1);
            $fy = $fy * 3 - $r;
            $fx = $fx * 3 - $c;
        }
        return $out;
    }

    /**
     * Da quadrato a coordinate: restituisce il CENTRO del riquadro e la sua
     * estensione, cosi' chi disegna la carta sa anche quanto e' grande.
     *
     * @return array{lat:float,lon:float,lat_span:float,lon_span:float,code:string,digits:int}|null
     */
    public static function fromQuadrat(string $quadrat): ?array
    {
        self::load();

        $q = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $quadrat) ?? '');
        if (!preg_match('/^([A-Z]{2})([0-9]{0,4})$/', $q, $m)) {
            return null;
        }
        [$all, $code, $digits] = $m;

        $cell = self::$byCode[$code] ?? null;
        if ($cell === null) {
            return null;
        }

        $top  = self::LAT_TOP - $cell['row'] * self::BAND_DEG;
        $left = self::LON_WEST + $cell['col'] * self::COL_DEG;
        $h = self::BAND_DEG;
        $w = self::COL_DEG;

        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            if ($d < 1 || $d > 9) {
                return null;
            }
            $r = intdiv($d - 1, 3);
            $c = ($d - 1) % 3;
            $h /= 3;
            $w /= 3;
            $top  -= $r * $h;
            $left += $c * $w;
        }

        return [
            'lat'      => $top - $h / 2,
            'lon'      => Geo::normLon($left + $w / 2),
            'lat_span' => $h,
            'lon_span' => $w,
            'code'     => $code,
            'digits'   => $len,
        ];
    }

    /** Sigla del grande quadrato che contiene il punto, o null. */
    public static function bigSquare(float $lat, float $lon): ?string
    {
        return self::toQuadrat($lat, $lon, 0);
    }

    /** @return array<string,array{row:int,col:int,nota:string,confidence:string}> */
    public static function table(): array
    {
        self::load();
        return self::$byCode ?? [];
    }

    /** Lato del grande quadrato in miglia nautiche alla latitudine data (per la carta). */
    public static function squareSideNm(float $lat): float
    {
        return self::COL_DEG * 60.0 * cos(deg2rad($lat));
    }
}
