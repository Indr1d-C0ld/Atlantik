<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\World;

/**
 * Azzeramento della campagna: tutti ricominciano.
 *
 * Chiesto dal proprietario il 13/10/2026, con la revisione che ha portato la
 * dottrina, i colleghi, l'Ammiragliato, le missioni speciali e i naufraghi:
 * «resetta ogni statistica di gioco: tutti cominciano adesso». Si cancella il
 * progresso di gioco — comandanti e fascicoli, albo d'oro, battelli, missioni,
 * giornali, affondamenti, decorazioni e trofei, branchi, radio, calore dei
 * settori, traffico — e l'orologio della campagna riparte da adesso, con un seme
 * nuovo. Restano gli account (che al primo accesso creano un comandante nuovo),
 * le loro preferenze, il registro dell'amministrazione, la posta e la
 * configurazione. Il traffico e i colleghi si ricreano da soli al primo battito.
 *
 * Le fotografie caricate dai giocatori non si cancellano: si spostano fuori dal
 * sito, in una cartella che si indica, perche' sono loro e non del gioco.
 *
 * Si prova a vuoto dentro una transazione che si annulla (prova()): dice che
 * cosa sparirebbe, e non tocca niente.
 */
final class Azzeramento
{
    /** Le tabelle del progresso di gioco, dalla foglia alla radice. */
    public const TABELLE = [
        'avvisi', 'naufraghi', 'campi_minati_passaggi', 'campi_minati', 'missioni_speciali', 'addestramenti',
        'torpedo_runs', 'encounter_entities', 'encounters', 'patrol_events', 'sinkings', 'contacts',
        'boat_waypoints', 'boat_torpedoes', 'boat_stores', 'boat_systems', 'boat_compartments', 'boat_upgrades',
        'crew_members', 'rendezvous', 'bdu_orders', 'hfdf_fixes', 'radio_messages', 'wolfpack_members', 'wolfpacks',
        'achievements', 'awards', 'bacheca', 'patrols', 'sectors', 'weather_overrides', 'ships', 'convoys',
        'boats', 'commanders',
    ];

    /**
     * Quante righe ci sono adesso, tabella per tabella.
     *
     * @return array<string,int>
     */
    public static function conta(): array
    {
        $out = [];
        foreach (self::TABELLE as $t) {
            $out[$t] = (int) (Database::first("SELECT COUNT(*) n FROM `{$t}`")['n'] ?? 0);
        }
        return $out;
    }

    /**
     * La prova a vuoto: cancella dentro una transazione, conta quello che resta,
     * e annulla.
     *
     * @return array{prima:array<string,int>, dopo:array<string,int>}
     */
    public static function prova(): array
    {
        $prima = self::conta();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::cancella();
            $dopo = self::conta();
        } finally {
            $pdo->rollBack();
            Database::run('SET FOREIGN_KEY_CHECKS = 1');
        }
        return ['prima' => $prima, 'dopo' => $dopo];
    }

    /**
     * Davvero. Le foto caricate vanno in $archivio (fuori dal sito).
     *
     * @return array{cancellate:array<string,int>, foto:int, seme:int}
     */
    public static function esegui(string $archivio): array
    {
        $prima = self::conta();
        $foto = self::mettiDaParteFoto($archivio);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::cancella();
            $seme = random_int(1, PHP_INT_MAX);
            Database::run(
                'UPDATE world SET seed = ?, epoch_real_ts = ?, epoch_game_ts = 0, note = ? WHERE id = 1',
                [$seme, time(), 'Ripartenza del ' . date('d/m/Y H:i') . ': nuova revisione, tutti ricominciano.']
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            Database::run('SET FOREIGN_KEY_CHECKS = 1');
        }
        World::forget();
        return ['cancellate' => $prima, 'foto' => $foto, 'seme' => $seme];
    }

    private static function cancella(): void
    {
        // Le chiavi esterne si sospendono: l'ordine c'e', ma qualche tabella
        // vecchia ha riferimenti incrociati (boats.encounter_id, ships.danno_*)
        // e una DELETE a tappeto non deve fermarsi a meta'.
        Database::run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABELLE as $t) {
            Database::run("DELETE FROM `{$t}`");
        }
        Database::run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Sposta fuori dal sito le fotografie e gli emblemi caricati. */
    private static function mettiDaParteFoto(string $archivio): int
    {
        $radice = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        $n = 0;
        foreach (['ritratti', 'emblemi'] as $cosa) {
            $da = $radice . '/assets/img/' . $cosa . '/caricati';
            $a = rtrim($archivio, '/') . '/' . $cosa;
            foreach (glob($da . '/*.webp') ?: [] as $f) {
                if (!is_dir($a) && !mkdir($a, 0700, true) && !is_dir($a)) {
                    throw new \RuntimeException('Non riesco a creare ' . $a);
                }
                // Copia e poi cancella: l'archivio sta di solito su un altro
                // disco, e rename() fra dischi diversi avvisa di non poter
                // conservare il proprietario (13/10/2026).
                $dest = $a . '/' . basename($f);
                if (!copy($f, $dest) || filesize($dest) !== filesize($f) || !unlink($f)) {
                    throw new \RuntimeException('Non riesco a spostare ' . $f);
                }
                $n++;
            }
        }
        return $n;
    }
}
