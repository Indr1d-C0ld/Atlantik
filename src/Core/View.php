<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private static string $viewPath = '';

    public static function setPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $name, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::renderPartial($name, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderPartial($layout, $data + [
            'content' => $content,
            'title'   => $data['title'] ?? Config::get('app.name', 'Atlantik'),
            'sfondo'  => self::sfondo($name),
        ]);
    }

    /**
     * Quale fondale sta dietro alla pagina.
     *
     * La carta illustrata della Kriegsmarine fa da sfondo dove non si misura
     * niente — ingresso, arruolamento, flottiglia, albo d'oro. Sulla plancia e
     * al tavolo di carteggio no: la' la carta e' uno strumento, e quella
     * immagine non regge la misura (docs/AUDIT.md, punto 16).
     *
     * L'elenco e' delle postazioni di bordo, non delle cartelle: statistiche,
     * trofei, bacheca e cantiere stanno in views/game/ ma si leggono a terra,
     * e la' il fondale ci sta. Elencare le eccezioni sarebbe stato piu' corto;
     * elencare le postazioni e' piu' difficile da sbagliare quando se ne
     * aggiunge una.
     *
     * La regola sta qui e non nei controller perche' e' una scelta di
     * presentazione, e perche' cosi' e' una riga sola da leggere invece di
     * venti da cercare.
     */
    private const PLANCIA = [
        'game/zentrale', 'game/carta', 'game/contatti', 'game/attacco',
        'game/battello', 'game/equipaggio', 'game/radio', 'game/bdu', 'game/ktb',
    ];

    private static function sfondo(string $vista): string
    {
        $vista = ltrim($vista, '/');
        if (in_array($vista, self::PLANCIA, true) || str_starts_with($vista, 'admin')) {
            return '';
        }
        return 'carta';
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function renderPartial(string $name, array $data = []): string
    {
        $file = self::$viewPath . '/' . ltrim($name, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vista non trovata: {$name} ({$file})");
        }

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
