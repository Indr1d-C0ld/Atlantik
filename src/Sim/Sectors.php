<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * Calore di settore: la reazione alleata.
 *
 * Il mondo non invecchia lungo il calendario di guerra (decisione di progetto:
 * periodo fisso contenitore), quindi la difficolta' non puo' salire con gli
 * anni. Sale invece DOVE SI COMBATTE: ogni affondamento, ogni trasmissione
 * radio intercettata, ogni avvistamento fa alzare il calore del settore, e un
 * settore caldo significa piu' scorte, scorte migliori, piu' aerei e convogli
 * deviati. E' quello che faceva davvero l'Ammiragliato.
 *
 * Il calore si smaltisce da solo col tempo: sparire per una settimana e
 * tornare e' una tattica valida.
 */
final class Sectors
{
    /** Chiave di settore: grande quadrato piu' la prima cifra (un nono di quadrato). */
    public static function key(float $lat, float $lon): string
    {
        $q = Grid::toQuadrat($lat, $lon, 1);
        return $q ?? 'XX 0';
    }

    /** Calore attuale, gia' scontato del decadimento. */
    public static function heat(string $key, int $gts): float
    {
        $row = Database::first('SELECT heat, updated_gts FROM sectors WHERE quadrat = ?', [$key]);
        if ($row === null) {
            return 0.0;
        }
        return self::decaduto((float) $row['heat'], (int) $row['updated_gts'], $gts);
    }

    private static function decaduto(float $heat, int $daGts, int $aGts): float
    {
        $giorni = max(0.0, ($aGts - $daGts) / 86400.0);
        $tasso = (float) GameConfig::get('heat.decadimento_giorno', 6.0);
        return max(0.0, $heat - $tasso * $giorni);
    }

    /** Aggiunge calore a un settore (e lo scrive gia' decaduto al momento attuale). */
    public static function add(float $lat, float $lon, float $punti, int $gts, ?string $nota = null): float
    {
        $key = self::key($lat, $lon);
        $attuale = self::heat($key, $gts);
        $nuovo = max(0.0, min(100.0, $attuale + $punti));

        Database::run(
            'INSERT INTO sectors (quadrat, heat, updated_gts, note) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE heat = VALUES(heat), updated_gts = VALUES(updated_gts),
                                     note = COALESCE(VALUES(note), note)',
            [$key, round($nuovo, 2), $gts, $nota]
        );
        return $nuovo;
    }

    /** Come si dice a parole quanto e' caldo un settore. */
    public static function stato(float $heat): string
    {
        return match (true) {
            $heat >= 75 => 'battuto palmo a palmo',
            $heat >= 50 => 'molto sorvegliato',
            $heat >= 28 => 'sorvegliato',
            $heat >= 12 => 'in allerta',
            default     => 'tranquillo',
        };
    }

    /** I settori piu' caldi, per la carta e per le statistiche. */
    public static function caldi(int $gts, int $limite = 12): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM sectors ORDER BY heat DESC LIMIT 60') as $r) {
            $h = self::decaduto((float) $r['heat'], (int) $r['updated_gts'], $gts);
            if ($h < 1.0) {
                continue;
            }
            $out[] = ['quadrat' => (string) $r['quadrat'], 'heat' => round($h, 1), 'stato' => self::stato($h)];
            if (count($out) >= $limite) {
                break;
            }
        }
        return $out;
    }
}
