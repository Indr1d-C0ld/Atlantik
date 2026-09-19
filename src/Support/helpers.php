<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

/**
 * Funzioni globali di comodo. Caricate una sola volta dal bootstrap.
 */

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('app_url_prefix')) {
    function app_url_prefix(): string
    {
        return (string) ($GLOBALS['__url_prefix'] ?? '');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        $prefix = app_url_prefix();
        if ($path === '/') {
            return $prefix === '' ? '/' : $prefix . '/';
        }
        return $prefix . $path;
    }
}

if (!function_exists('asset')) {
    /** URL di un file sotto assets/, con `?v=<mtime>` per invalidare la cache del browser a ogni deploy. */
    function asset(string $path): string
    {
        $base = (string) ($GLOBALS['__base_path'] ?? '');
        $rel  = ltrim($path, '/');
        $abs  = rtrim((string) ($GLOBALS['__project_root'] ?? ''), '/') . '/assets/' . $rel;
        $v    = is_file($abs) ? (string) filemtime($abs) : null;
        return $base . '/assets/' . $rel . ($v !== null ? '?v=' . $v : '');
    }
}

if (!function_exists('root_url')) {
    /** URL sotto la radice del deploy, senza il front controller. */
    function root_url(string $path = '/'): string
    {
        return (string) ($GLOBALS['__base_path'] ?? '') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('view')) {
    /** @param array<string,mixed> $data */
    function view(string $name, array $data = [], ?string $layout = 'layout'): string
    {
        return View::render($name, $data, $layout);
    }
}

if (!function_exists('partial')) {
    /** Rende views/partials/<name>.php senza layout. @param array<string,mixed> $data */
    function partial(string $name, array $data = []): string
    {
        return View::renderPartial('partials/' . ltrim($name, '/'), $data);
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::old($key, $default);
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $default = null): mixed
    {
        return Session::getFlash($key, $default);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('logger')) {
    function logger(string $message, string $level = 'info'): void
    {
        $root = (string) ($GLOBALS['__project_root'] ?? sys_get_temp_dir());
        $dir = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/app.log';

        // Il diario lo scrivono due utenti diversi: il web (www-data) e il cron
        // del battito (l'utente di sistema). Se nasce con i permessi di uno solo,
        // l'altro scrive nel vuoto — ed e' successo davvero: per giorni gli
        // errori del battito non sono finiti da nessuna parte, perche' la
        // chiamata era silenziata con la chiocciola e non si lamentava.
        $nuovo = !is_file($file);

        // Rotazione a taglia: un diario che cresce per sempre prima o poi
        // riempie il disco e nel frattempo non lo legge piu' nessuno.
        if (!$nuovo && (int) @filesize($file) > 4 * 1024 * 1024) {
            @rename($file, $dir . '/app-' . date('Ymd-His') . '.log');
            $nuovo = true;
            foreach (array_slice(array_reverse(glob($dir . '/app-*.log') ?: []), 5) as $vecchio) {
                @unlink($vecchio);
            }
        }

        $line = sprintf("[%s] %s: %s\n", date('c'), strtoupper($level), $message);
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            // Meglio il diario di sistema che il silenzio.
            error_log('atlantik: ' . rtrim($line));
            return;
        }
        if ($nuovo) {
            @chmod($file, 0664);
        }
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): \App\Core\Response
    {
        return \App\Core\Response::redirect(url($path));
    }
}

if (!function_exists('rome_tz')) {
    function rome_tz(): \DateTimeZone
    {
        static $tz = null;
        return $tz ??= new \DateTimeZone((string) Config::get('app.timezone', 'Europe/Rome'));
    }
}

if (!function_exists('fmt_dt')) {
    /**
     * Formatta un istante nel formato italiano, ora di Roma: "GG/MM/AAAA HH:MM".
     * Accetta stringhe DATETIME del DB (già ora di Roma), timestamp unix o DateTimeInterface.
     * I valori "naïve" (senza fuso) sono interpretati nel fuso applicativo, non convertiti.
     */
    function fmt_dt(mixed $value, bool $withSeconds = false, string $fallback = '—'): string
    {
        $fmt = $withSeconds ? 'd/m/Y H:i:s' : 'd/m/Y H:i';
        try {
            if ($value instanceof \DateTimeInterface) {
                $dt = \DateTimeImmutable::createFromInterface($value)->setTimezone(rome_tz());
            } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $dt = (new \DateTimeImmutable('@' . (int) $value))->setTimezone(rome_tz());
            } else {
                $s = trim((string) $value);
                if ($s === '' || str_starts_with($s, '0000-00-00')) {
                    return $fallback;
                }
                // DATETIME del DB: niente fuso nella stringa => è già ora di Roma.
                $dt = new \DateTimeImmutable($s, rome_tz());
            }
        } catch (\Throwable) {
            return $fallback;
        }
        return $dt->format($fmt);
    }
}

if (!function_exists('fmt_date')) {
    /** Solo la data: "GG/MM/AAAA". */
    function fmt_date(mixed $value, string $fallback = '—'): string
    {
        $out = fmt_dt($value, false, $fallback);
        return $out === $fallback ? $fallback : explode(' ', $out)[0];
    }
}

if (!function_exists('data_it_a_iso')) {
    /**
     * Legge una data scritta all'italiana — "22/05/1913" — e la restituisce
     * nella forma che vuole il database: "1913-05-22". Null se non e' una data
     * vera (il 31 febbraio non lo e').
     *
     * Accetta anche il punto e il trattino come separatori, e la forma ISO
     * gia' fatta: chi incolla una data presa altrove non deve indovinare.
     */
    function data_it_a_iso(string $valore): ?string
    {
        $v = trim($valore);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1) {
            [$a, $me, $g] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $v, $m) === 1) {
            [$g, $me, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($me, $g, $a)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $a, $me, $g);
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string,mixed>|null */
    function auth_user(): ?array
    {
        return \App\Auth\Auth::user();
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        return \App\Auth\Auth::check();
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return \App\Auth\Auth::isAdmin();
    }
}
