<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Geo;
use App\Sim\World;

/**
 * Le forzature del meteo: la mano dell'amministratore sul tempo.
 *
 * Il tempo, in questo gioco, e' una funzione pura: stesso seme, stesso
 * istante, stesso posto, stesso tempo — e' quello che rende il mondo uguale
 * per tutti e ricalcolabile all'indietro. Una forzatura NON tocca quella
 * funzione: le si siede sopra. Si dice "in questo cerchio, da adesso a
 * stasera, il mare e' forza 7 e la visibilita' due miglia", e fuori di li'
 * resta tutto com'era, da solo, senza dover disfare niente.
 *
 * Si dicono solo i campi che si vogliono dire: quello che si lascia vuoto
 * continua a venire dal modello. Cosi' si puo' calare la nebbia su un
 * convoglio senza inventarsi anche la pressione.
 *
 * Nota: e' uno strumento da stanza dei bottoni, e si vede. Ogni forzatura
 * porta chi l'ha messa, quando, e una nota; la pagina del meteo dice se
 * quello che si sta guardando e' vero o forzato.
 */
final class Meteo
{
    /** I campi che una forzatura puo' sovrascrivere. */
    public const CAMPI = ['wind_kn', 'wind_dir', 'sea_state', 'visibility_nm', 'fog', 'cloud'];

    /** @var list<array<string,mixed>>|null */
    private static ?array $cache = null;

    /** Le forzature in vigore adesso, lette una volta sola per richiesta. */
    public static function attive(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            return self::$cache = Database::all(
                'SELECT * FROM weather_overrides ORDER BY id DESC LIMIT 200'
            );
        } catch (\Throwable) {
            // Prima della migrazione 0027 la tabella non c'e': il mondo va
            // avanti lo stesso, semplicemente senza forzature.
            return self::$cache = [];
        }
    }

    public static function dimentica(): void
    {
        self::$cache = null;
    }

    /**
     * La forzatura che vale in un punto a un certo istante, se c'e'.
     *
     * Vince la piu' recente: se due si sovrappongono, l'ultima messa e'
     * quella che conta, che e' l'ordine in cui uno se le aspetta.
     *
     * @return array<string,mixed>|null
     */
    public static function forzaturaIn(float $lat, float $lon, int $gts): ?array
    {
        foreach (self::attive() as $f) {
            if ($gts < (int) $f['da_gts']) {
                continue;
            }
            if ($f['scadenza_gts'] !== null && $gts > (int) $f['scadenza_gts']) {
                continue;
            }
            if ($f['lat'] !== null && $f['lon'] !== null && $f['raggio_nm'] !== null) {
                $d = Geo::distanceNm($lat, $lon, (float) $f['lat'], (float) $f['lon']);
                if ($d > (float) $f['raggio_nm']) {
                    continue;
                }
            }
            return $f;
        }
        return null;
    }

    /**
     * Applica al meteo calcolato quello che la forzatura ha da dire.
     *
     * @param array<string,mixed> $meteo
     * @return array<string,mixed>
     */
    public static function applica(array $meteo, float $lat, float $lon, int $gts): array
    {
        $f = self::forzaturaIn($lat, $lon, $gts);
        if ($f === null) {
            return $meteo;
        }

        foreach (self::CAMPI as $campo) {
            if ($f[$campo] === null) {
                continue;
            }
            $meteo[$campo] = match ($campo) {
                'sea_state' => max(0, min(9, (int) $f[$campo])),
                'fog'       => (bool) $f[$campo],
                default     => (float) $f[$campo],
            };
        }

        // Il mare forzato porta con se' l'onda e la scala Beaufort: lasciarle
        // indietro darebbe un mare forza 8 con onde da forza 2, e chi legge la
        // plancia se ne accorge.
        if ($f['sea_state'] !== null) {
            $s = (int) $meteo['sea_state'];
            $meteo['wave_m'] = round([0.0, 0.1, 0.4, 0.9, 1.9, 3.1, 4.6, 6.6, 9.3, 12.5][min(9, max(0, $s))], 2);
        }
        if ($f['wind_kn'] !== null) {
            $v = (float) $meteo['wind_kn'];
            $meteo['beaufort'] = (int) min(12, floor(($v / 3.01) ** (2 / 3)));
        }
        if ($f['fog'] !== null && (bool) $f['fog'] && $f['visibility_nm'] === null) {
            $meteo['visibility_nm'] = min((float) $meteo['visibility_nm'], 0.5);
        }

        $meteo['forzato'] = true;

        return $meteo;
    }

    /**
     * Mette una forzatura.
     *
     * @param array<string,mixed> $dati
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function forza(array $dati, int $adminId): array
    {
        $gts = World::now();
        $durata = max(0, (int) ($dati['ore'] ?? 0));

        $valori = [];
        foreach (self::CAMPI as $campo) {
            $v = $dati[$campo] ?? null;
            $valori[$campo] = ($v === null || $v === '') ? null : $v;
        }
        if (array_filter($valori, static fn ($v): bool => $v !== null) === []) {
            return ['ok' => false, 'error' => 'Una forzatura che non forza niente non serve: indica almeno un valore.'];
        }

        $lat = ($dati['lat'] ?? '') === '' ? null : (float) $dati['lat'];
        $lon = ($dati['lon'] ?? '') === '' ? null : (float) $dati['lon'];
        $raggio = ($dati['raggio_nm'] ?? '') === '' ? null : max(1, (int) $dati['raggio_nm']);
        if (($lat === null) !== ($lon === null)) {
            return ['ok' => false, 'error' => 'Servono tutte e due le coordinate, o nessuna.'];
        }
        if ($lat !== null && $raggio === null) {
            return ['ok' => false, 'error' => 'Con un centro ci vuole un raggio.'];
        }

        Database::run(
            'INSERT INTO weather_overrides
                (lat, lon, raggio_nm, da_gts, scadenza_gts, wind_kn, wind_dir, sea_state,
                 visibility_nm, fog, cloud, nota, creato_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $lat, $lon, $raggio, $gts,
                $durata > 0 ? $gts + $durata * 3600 : null,
                $valori['wind_kn'] === null ? null : (float) $valori['wind_kn'],
                $valori['wind_dir'] === null ? null : (float) $valori['wind_dir'],
                $valori['sea_state'] === null ? null : (int) $valori['sea_state'],
                $valori['visibility_nm'] === null ? null : (float) $valori['visibility_nm'],
                $valori['fog'] === null ? null : (int) (bool) $valori['fog'],
                $valori['cloud'] === null ? null : (float) $valori['cloud'],
                mb_substr(trim((string) ($dati['nota'] ?? '')), 0, 255) ?: null,
                $adminId,
            ]
        );
        self::dimentica();

        return ['ok' => true, 'id' => Database::lastInsertId()];
    }

    /** Toglie una forzatura, e il tempo torna quello che sarebbe stato. */
    public static function togli(int $id): void
    {
        Database::run('DELETE FROM weather_overrides WHERE id = ?', [$id]);
        self::dimentica();
    }

    /** Toglie tutto: il pulsante che rimette il mondo com'era. */
    public static function togliTutte(): int
    {
        $n = (int) (Database::first('SELECT COUNT(*) n FROM weather_overrides')['n'] ?? 0);
        Database::run('DELETE FROM weather_overrides');
        self::dimentica();
        return $n;
    }
}
