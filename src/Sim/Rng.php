<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Generatore pseudocasuale deterministico.
 *
 * Il motore deve poter ricalcolare due volte lo stesso intervallo di tempo e
 * ottenere lo stesso risultato: e' cio' che rende l'avanzamento pigro sicuro
 * (il tick e la richiesta del giocatore possono fare lo stesso lavoro senza
 * divergere) e il diario di bordo ricostruibile. Quindi niente mt_rand: ogni
 * estrazione nasce da un seme esplicito (seme del mondo, entita', istante).
 *
 * Algoritmo: SplitMix64 — veloce, senza stato condiviso, buona dispersione.
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        // Lo stato zero e' il punto fisso di xorshift: si sostituisce.
        $this->state = $seed !== 0 ? $seed : 0x2545F4914F6CDD1D;
    }

    /**
     * Costruisce un generatore da una chiave testuale: il modo normale di
     * usarlo e' Rng::for($worldSeed, 'boat', $boatId, $tick).
     */
    public static function for(int $worldSeed, string|int ...$parts): self
    {
        $key = $worldSeed . '|' . implode('|', $parts);
        // I primi 8 byte dell'hash diventano il seme. Si compone da due meta'
        // a 32 bit in ordine di rete: il risultato e' identico su qualunque
        // macchina, e resta un intero positivo (niente passaggi dal virgola
        // mobile, che romperebbero la riproducibilita').
        $bytes = substr(hash('sha256', $key, true), 0, 8);
        $hi = unpack('N', substr($bytes, 0, 4))[1] ?? 0;
        $lo = unpack('N', substr($bytes, 4, 4))[1] ?? 0;
        return new self((($hi & 0x7FFFFFFF) << 32) | $lo);
    }

    /**
     * Prossimo intero a 64 bit.
     *
     * Algoritmo xorshift64 piu' una mescolata finale. Niente moltiplicazioni a
     * 64 bit: in PHP traboccherebbero in virgola mobile, e un generatore che
     * perde precisione smette di essere deterministico — che qui e' l'unica
     * cosa che conta davvero. Scorrimenti e XOR restano interi per definizione.
     */
    public function next(): int
    {
        $x = $this->state;
        $x ^= $x << 13;
        $x ^= self::ushr($x, 7);
        $x ^= $x << 17;
        $this->state = $x;

        // Mescolata finale: i fattori sono scelti in modo che nessun prodotto
        // superi i 63 bit (32 bit x 31 bit e 32 bit x 27 bit), e il risultato
        // viene comunque mascherato. Cosi' si resta interi sempre.
        $lo = (($x & 0xFFFFFFFF) * 0x2545F491) & PHP_INT_MAX;
        $hi = ((self::ushr($x, 32)) * 0x045D9F3B) & PHP_INT_MAX;
        return ($lo ^ ($hi << 16) ^ $x);
    }

    /** Reale in [0,1). */
    public function float(): float
    {
        return (self::ushr($this->next(), 11)) / (float) (1 << 53);
    }

    /** Reale in [$min,$max). */
    public function range(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    /** Intero in [$min,$max] inclusi. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int) floor($this->float() * (float) ($max - $min + 1));
    }

    /** Vero con probabilita' $p (0..1). */
    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    /** Estrazione normale (media 0, deviazione 1) con Box-Muller. */
    public function gauss(): float
    {
        $u1 = max(1e-12, $this->float());
        $u2 = $this->float();
        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /** @param list<mixed> $items */
    public function pick(array $items): mixed
    {
        return $items === [] ? null : $items[$this->int(0, count($items) - 1)];
    }

    // --- utilita' -------------------------------------------------------------

    /** Scorrimento a destra senza segno. */
    private static function ushr(int $v, int $n): int
    {
        if ($n === 0) {
            return $v;
        }
        return ($v >> $n) & ~(PHP_INT_MIN >> ($n - 1));
    }
}
