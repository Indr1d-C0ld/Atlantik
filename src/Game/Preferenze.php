<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;

/**
 * Preferenze del giocatore.
 *
 * Sono scelte di comodo, non di gioco: come si disegna la carta, non quanto
 * lontano si sente l'idrofono. Vivono in una colonna JSON su users, perche'
 * sono poche e si leggono sempre tutte insieme.
 *
 * Ogni chiave ha un elenco chiuso di valori ammessi: quello che arriva dal
 * browser non entra mai in tabella senza passare di qui.
 */
final class Preferenze
{
    /** @var array<string,array{valori:list<string>,difetto:string}> */
    private const AMMESSE = [
        'carta.stile' => [
            'valori'  => ['piena', 'essenziale'],
            'difetto' => 'piena',
        ],
    ];

    /** @return array<string,string> */
    public static function tutte(int $userId): array
    {
        $r = Database::first('SELECT preferenze FROM users WHERE id = ?', [$userId]);
        $grezze = $r === null || $r['preferenze'] === null
            ? []
            : (array) json_decode((string) $r['preferenze'], true);

        $out = [];
        foreach (self::AMMESSE as $chiave => $regola) {
            $v = isset($grezze[$chiave]) ? (string) $grezze[$chiave] : '';
            $out[$chiave] = in_array($v, $regola['valori'], true) ? $v : $regola['difetto'];
        }
        return $out;
    }

    public static function get(int $userId, string $chiave): string
    {
        return self::tutte($userId)[$chiave] ?? (self::AMMESSE[$chiave]['difetto'] ?? '');
    }

    /** @return array{ok:bool, error?:string} */
    public static function set(int $userId, string $chiave, string $valore): array
    {
        if (!isset(self::AMMESSE[$chiave])) {
            return ['ok' => false, 'error' => 'Preferenza sconosciuta.'];
        }
        if (!in_array($valore, self::AMMESSE[$chiave]['valori'], true)) {
            return ['ok' => false, 'error' => 'Valore non ammesso.'];
        }

        $attuali = self::tutte($userId);
        $attuali[$chiave] = $valore;
        Database::run('UPDATE users SET preferenze = ? WHERE id = ?', [json_encode($attuali), $userId]);
        return ['ok' => true];
    }

    /** @return list<string> */
    public static function valori(string $chiave): array
    {
        return self::AMMESSE[$chiave]['valori'] ?? [];
    }
}
