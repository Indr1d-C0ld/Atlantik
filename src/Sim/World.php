<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;

/** Accesso allo stato globale del mondo: seme, orologio, porti, tipi. */
final class World
{
    /** @var array<string,mixed>|null */
    private static ?array $row = null;
    private static ?Clock $clock = null;
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $types = null;
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $ports = null;

    /** @return array<string,mixed> */
    public static function row(): array
    {
        if (self::$row !== null) {
            return self::$row;
        }
        $row = Database::first('SELECT * FROM world WHERE id = 1');
        if ($row === null) {
            throw new \RuntimeException('Mondo non inizializzato: esegui "php bin/console.php world:init".');
        }
        return self::$row = $row;
    }

    public static function exists(): bool
    {
        try {
            return Database::first('SELECT id FROM world WHERE id = 1') !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function seed(): int
    {
        return (int) self::row()['seed'];
    }

    public static function clock(): Clock
    {
        if (self::$clock !== null) {
            return self::$clock;
        }
        $w = self::row();
        return self::$clock = new Clock(
            (int) $w['epoch_real_ts'],
            (int) $w['epoch_game_ts'],
            (int) $w['time_ratio'],
        );
    }

    /** Istante di gioco adesso. */
    public static function now(): int
    {
        return self::clock()->now();
    }

    /** Crea il mondo se non c'e'. */
    public static function init(?int $seed = null, ?string $note = null): array
    {
        $seed ??= (int) (Config::get('world.seed') ?? random_int(1, PHP_INT_MAX));
        $ratio = (int) (Config::get('world.time_ratio') ?? Clock::RATIO_CROCIERA);

        Database::run(
            'INSERT INTO world (id, seed, epoch_real_ts, epoch_game_ts, time_ratio, note)
             VALUES (1, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE seed = seed',
            [$seed, time(), $ratio, $note]
        );
        self::$row = null;
        self::$clock = null;
        return self::row();
    }

    /** @return array<string,array<string,mixed>> */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }
        $out = [];
        foreach (Database::all('SELECT * FROM uboat_types ORDER BY unlock_rank, type_key') as $r) {
            $out[(string) $r['type_key']] = $r;
        }
        return self::$types = $out;
    }

    /** @return array<string,mixed> */
    public static function type(string $key): array
    {
        $t = self::types()[$key] ?? null;
        if ($t === null) {
            throw new \RuntimeException("Tipo di battello sconosciuto: {$key}");
        }
        return $t;
    }

    /** @return array<string,array<string,mixed>> */
    public static function ports(): array
    {
        if (self::$ports !== null) {
            return self::$ports;
        }
        $out = [];
        foreach (Database::all('SELECT * FROM ports ORDER BY kind, name') as $r) {
            $out[(string) $r['port_key']] = $r;
        }
        return self::$ports = $out;
    }

    /** @return array<string,mixed>|null */
    public static function port(string $key): ?array
    {
        return self::ports()[$key] ?? null;
    }

    /** @return array<string,array<string,mixed>> solo le basi U-Boot */
    public static function bases(): array
    {
        return array_filter(self::ports(), static fn (array $p): bool => (string) $p['kind'] === 'base');
    }

    /** Meteo nel punto e nell'istante dati. */
    public static function weather(float $lat, float $lon, ?int $gts = null): array
    {
        $gts ??= self::now();
        return self::conForzature(
            Weather::at(self::seed(), $gts, $lat, $lon, self::clock()->date($gts)),
            $lat,
            $lon,
            $gts
        );
    }

    /**
     * Il meteo con la data di gioco gia' in mano, per chi gira in ciclo stretto.
     *
     * La simulazione di crociera calcola il tempo a ogni sotto-passo: farle
     * rifare la conversione dell'istante a ogni giro sarebbe uno spreco. Il
     * risultato e' lo stesso di weather(), forzature comprese.
     *
     * @return array<string,mixed>
     */
    public static function weatherCon(int $gts, float $lat, float $lon, \DateTimeImmutable $data): array
    {
        return self::conForzature(Weather::at(self::seed(), $gts, $lat, $lon, $data), $lat, $lon, $gts);
    }

    /**
     * Lo strato delle forzature, che sta SOPRA il modello e non dentro.
     *
     * Il tempo resta una funzione pura del seme e dell'istante; quello che
     * l'amministratore impone e' una correzione applicata dopo, in un cerchio
     * e per una finestra. Cosi' quando la forzatura scade il mondo torna
     * quello che sarebbe stato, senza che nessuno debba disfare niente.
     *
     * @param array<string,mixed> $meteo
     * @return array<string,mixed>
     */
    private static function conForzature(array $meteo, float $lat, float $lon, int $gts): array
    {
        return \App\Game\Meteo::applica($meteo, $lat, $lon, $gts);
    }

    /** Sole, luna e luce nel punto e nell'istante dati. */
    public static function sky(float $lat, float $lon, ?int $gts = null, ?float $cloud = null): array
    {
        $gts ??= self::now();
        $ts = self::clock()->astroTs($gts);
        $sun  = Astro::sun($ts, $lat, $lon);
        $moon = Astro::moon($ts, $lat, $lon);
        $cloud ??= (float) self::weather($lat, $lon, $gts)['cloud'];

        return [
            'sun_alt'    => round($sun['alt'], 2),
            'sun_az'     => round($sun['az'], 1),
            'moon_alt'   => round($moon['alt'], 2),
            'moon_illum' => round($moon['illum'], 3),
            'moon_phase' => Astro::phaseName($moon['phase']),
            'fase'       => Astro::dayPhase($sun['alt']),
            'luce'       => round(Astro::lightFrom($sun['alt'], $moon['alt'], $moon['illum'], $cloud), 3),
        ];
    }

    public static function substepSeconds(): int
    {
        return max(60, GameConfig::int('world.substep_s', 300));
    }

    public static function maxCatchupSeconds(): int
    {
        return max(3600, GameConfig::int('world.max_catchup_h', 72) * 3600);
    }

    /** Svuota le cache di processo (usata dai test e dalla console). */
    public static function forget(): void
    {
        self::$row = null;
        self::$clock = null;
        self::$types = null;
        self::$ports = null;
    }
}
