<?php

declare(strict_types=1);

namespace App\Cli;

use App\Core\Database;
use App\Sim\World;

/** Carica i dati storici da db/seed/ dentro le tabelle. Idempotente. */
final class Seeder
{
    public function __construct(private string $projectRoot)
    {
    }

    /** @return list<string> */
    public function all(): array
    {
        $log = [];
        $log[] = $this->uboatTypes();
        $log[] = $this->ports();
        $log[] = $this->shipClasses();
        $log[] = $this->siluri();
        $log[] = $this->carriera();
        $log[] = $this->trofei();
        $log[] = $this->rotte();
        $log[] = $this->quadrati();
        World::forget();
        return $log;
    }

    private function uboatTypes(): string
    {
        /** @var list<array<string,mixed>> $righe */
        $righe = require $this->projectRoot . '/db/seed/uboat_types.php';
        foreach ($righe as $r) {
            $campi = array_keys($r);
            $sql = 'INSERT INTO uboat_types (' . implode(', ', $campi) . ') VALUES ('
                . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                    array_filter($campi, static fn (string $c): bool => $c !== 'type_key')));
            Database::run($sql, array_values($r));
        }
        return count($righe) . ' tipi di U-Boot';
    }

    private function ports(): string
    {
        /** @var list<array<string,mixed>> $righe */
        $righe = require $this->projectRoot . '/db/seed/ports.php';
        foreach ($righe as $r) {
            $campi = array_keys($r);
            $sql = 'INSERT INTO ports (' . implode(', ', $campi) . ') VALUES ('
                . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                    array_filter($campi, static fn (string $c): bool => $c !== 'port_key')));
            Database::run($sql, array_values($r));
        }
        return count($righe) . ' porti (' . count(array_filter($righe, static fn (array $p): bool => $p['kind'] === 'base')) . ' basi)';
    }

    private function shipClasses(): string
    {
        /** @var list<array<string,mixed>> $righe */
        $righe = require $this->projectRoot . '/db/seed/ship_classes.php';
        foreach ($righe as $r) {
            $campi = array_keys($r);
            $sql = 'INSERT INTO ship_classes (' . implode(', ', $campi) . ') VALUES ('
                . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                    array_filter($campi, static fn (string $c): bool => $c !== 'class_key')));
            Database::run($sql, array_values($r));
        }
        return count($righe) . ' classi di naviglio e velivoli';
    }

    private function siluri(): string
    {
        /** @var list<array<string,mixed>> $righe */
        $righe = require $this->projectRoot . '/db/seed/siluri.php';
        foreach ($righe as $r) {
            $campi = array_keys($r);
            $sql = 'INSERT INTO torpedo_types (' . implode(', ', $campi) . ') VALUES ('
                . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                    array_filter($campi, static fn (string $c): bool => $c !== 'tkey')));
            Database::run($sql, array_values($r));
        }
        return count($righe) . ' tipi di siluro';
    }

    private function carriera(): string
    {
        foreach (['decorazioni' => 'award_types', 'miglioramenti' => 'upgrade_types'] as $file => $tabella) {
            /** @var list<array<string,mixed>> $righe */
            $righe = require $this->projectRoot . '/db/seed/' . $file . '.php';
            $chiave = $tabella === 'award_types' ? 'akey' : 'ukey';
            foreach ($righe as $r) {
                $campi = array_keys($r);
                $sql = 'INSERT INTO ' . $tabella . ' (' . implode(', ', $campi) . ') VALUES ('
                    . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                    . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                        array_filter($campi, static fn (string $c): bool => $c !== $chiave)));
                Database::run($sql, array_values($r));
            }
        }
        $d = require $this->projectRoot . '/db/seed/decorazioni.php';
        $m = require $this->projectRoot . '/db/seed/miglioramenti.php';
        $g = require $this->projectRoot . '/db/seed/gradi.php';
        return count($d) . ' decorazioni, ' . count($m) . ' miglioramenti tecnici, ' . count($g) . ' livelli di anzianita\'';
    }

    private function trofei(): string
    {
        /** @var list<array<string,mixed>> $righe */
        $righe = require $this->projectRoot . '/db/seed/trofei.php';
        foreach ($righe as $r) {
            $r += ['nascosto' => 0, 'nota' => null];
            $campi = array_keys($r);
            $sql = 'INSERT INTO achievement_types (' . implode(', ', $campi) . ') VALUES ('
                . implode(', ', array_fill(0, count($campi), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})",
                    array_filter($campi, static fn (string $c): bool => $c !== 'akey')));
            Database::run($sql, array_values($r));
        }
        return count($righe) . ' trofei';
    }

    private function rotte(): string
    {
        $d = require $this->projectRoot . '/db/seed/rotte.php';
        return count($d['rotte']) . ' rotte mercantili, ' . count($d['serie']) . ' serie di convogli, '
            . count($d['aria']) . ' zone di copertura aerea';
    }

    private function quadrati(): string
    {
        $tab = require $this->projectRoot . '/db/seed/marinequadrat.php';
        $alta = count(array_filter($tab, static fn (array $q): bool => $q['confidence'] === 'alta'));
        return count($tab) . ' grandi quadrati Marinequadrat (' . $alta . ' ancorati a riferimenti storici, il resto ricostruito)';
    }
}
