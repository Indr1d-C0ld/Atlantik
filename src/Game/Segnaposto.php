<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Le figure di segnaposto: medaglie, gradi, sagome.
 *
 * Sono ricostruzioni, non riferimenti. La differenza non e' accademica: una
 * tavola di sagome E' un manuale di riconoscimento — serve esattamente a
 * imparare a distinguere una corvetta Flower da una fregata Captain — e se le
 * proporzioni sono inventate insegna a riconoscere navi che non esistono.
 *
 * Percio' qui ogni figura esce sempre accompagnata dalla sua dichiarazione, e
 * il gioco non la usa mai come unica via per identificare qualcosa: il nome
 * scritto resta, la figura gli sta accanto.
 *
 * Vedi docs/FONTI.md e db/seed/segnaposto.php.
 */
final class Segnaposto
{
    public const DICHIARAZIONE = 'Ricostruzione, non riferimento documentale.';

    /** La riga che accompagna la figura, secondo da dove viene. */
    public static function dichiarazione(array $fig): string
    {
        $fonte = (string) ($fig['fonte'] ?? '');
        $doc   = (string) ($fig['documento'] ?? '');
        if ($fonte === 'documentale' && $doc !== '') {
            return 'Profilo da ' . $doc . '.';
        }
        if ($fonte === 'misurata' && $doc !== '') {
            return 'Sagoma in scala costruita su misure documentate: ' . $doc
                . ' Le proporzioni delle sovrastrutture sono ricostruite.';
        }
        return self::DICHIARAZIONE;
    }

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $registro = null;

    /**
     * Il registro, unione di due pezzi generati da due strumenti diversi.
     *
     *   segnaposto.php         figure ritagliate da una tavola o da un manuale
     *                          (bin/taglia_segnaposto.php)
     *   segnaposto_scorte.php  sagome costruite sulle misure documentate
     *                          (bin/disegna_navi.php)
     *
     * Tre produttori, un registro solo: cosi' la prova di coerenza vede tutto
     * insieme e nessuna figura puo' nascondersi in mezzo.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function registro(): array
    {
        if (self::$registro === null) {
            $unione = [];
            foreach (['segnaposto.php', 'segnaposto_scorte.php', 'segnaposto_aerei.php'] as $nome) {
                $file = dirname(__DIR__, 2) . '/db/seed/' . $nome;
                if (is_file($file)) {
                    /** @var array<string,array<string,mixed>> $r */
                    $r = require $file;
                    $unione += $r;
                }
            }
            self::$registro = $unione;
        }
        return self::$registro;
    }

    /**
     * La figura legata a un'entita' di gioco, se c'e'.
     *
     * @return array{file:string,soggetto:string,nota:string}|null
     */
    public static function per(string $lega): ?array
    {
        foreach (self::registro() as $chiave => $v) {
            if ((string) ($v['lega'] ?? '') === $lega) {
                return [
                    'file'      => 'img/segnaposto/' . (string) $v['file'],
                    'soggetto'  => (string) $v['soggetto'],
                    'nota'      => (string) ($v['nota'] ?? ''),
                    'fonte'     => (string) ($v['fonte'] ?? 'ricostruzione'),
                    'documento' => (string) ($v['documento'] ?? ''),
                    'chiave'    => (string) $chiave,
                ];
            }
        }
        return null;
    }

    /** @return array{file:string,soggetto:string,nota:string}|null */
    public static function chiave(string $chiave): ?array
    {
        $v = self::registro()[$chiave] ?? null;
        if ($v === null) {
            return null;
        }
        return [
            'file'      => 'img/segnaposto/' . (string) $v['file'],
            'soggetto'  => (string) $v['soggetto'],
            'nota'      => (string) ($v['nota'] ?? ''),
            'fonte'     => (string) ($v['fonte'] ?? 'ricostruzione'),
            'documento' => (string) ($v['documento'] ?? ''),
            'chiave'    => $chiave,
        ];
    }

    /** I file presenti sul disco, per la prova di coerenza. */
    public static function fileSuDisco(): array
    {
        $dir = dirname(__DIR__, 2) . '/assets/img/segnaposto';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/\.(webp|png|svg|jpg|jpeg)$/i', $f)) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }
}
