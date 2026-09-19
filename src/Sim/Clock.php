<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * I due orologi di Atlantik.
 *
 * CROCIERA: il mondo gira sempre, a tempo compresso. Rapporto di default 1:30
 * (un minuto reale = trenta minuti di gioco), quindi una patrol storica di
 * quattro-sei settimane dura una decina di giorni reali.
 *
 * TATTICO: quando scatta un contatto il rapporto scende (1:4 in avvicinamento,
 * 1:1 in attacco o sotto le cariche). Qui c'e' solo la conversione; la macchina
 * a stati dell'incontro arriva in F4.
 *
 * L'istante di gioco (gts) e' un intero: secondi dall'inizio della campagna.
 * Monotono, non torna mai indietro. Il CALENDARIO invece e' ciclico: il mondo
 * e' ancorato al 1942 e non invecchia (decisione di progetto: periodo fisso
 * contenitore), quindi la data mostrata gira dentro l'anno di riferimento.
 * Cosi' restano vere le stagioni, le fasi lunari e le durate del giorno, e la
 * guerra non finisce mai.
 */
final class Clock
{
    /** Anno di ambientazione: l'anno di massima densita' operativa. */
    public const ANNO_CAMPAGNA = 1942;

    public const RATIO_CROCIERA    = 30;
    public const RATIO_TATTICO_AVV = 4;
    public const RATIO_TATTICO_ATT = 1;

    /** Istante reale (unix) e istante di gioco corrispondente all'inizio della campagna. */
    public function __construct(
        private int $epochRealTs,
        private int $epochGameTs,
        private int $ratio = self::RATIO_CROCIERA,
    ) {
    }

    public function ratio(): int
    {
        return $this->ratio;
    }

    /** Istante di gioco corrispondente a un istante reale. */
    public function gameAt(int $realTs): int
    {
        return $this->epochGameTs + (int) round(($realTs - $this->epochRealTs) * $this->ratio);
    }

    /** Istante di gioco adesso. */
    public function now(): int
    {
        return $this->gameAt(time());
    }

    /** Istante reale in cui il mondo raggiungera' (o ha raggiunto) un istante di gioco. */
    public function realAt(int $gameTs): int
    {
        return $this->epochRealTs + (int) round(($gameTs - $this->epochGameTs) / $this->ratio);
    }

    /** Quanti secondi reali valgono N secondi di gioco. */
    public function realSecondsFor(int $gameSeconds): int
    {
        return (int) round($gameSeconds / $this->ratio);
    }

    /** Quanti secondi di gioco valgono N secondi reali. */
    public function gameSecondsFor(int $realSeconds): int
    {
        return $realSeconds * $this->ratio;
    }

    // --- Calendario ------------------------------------------------------------

    /**
     * Data di gioco: l'istante di gioco proiettato nell'anno di campagna.
     *
     * L'anno gira su se stesso: superato il 31 dicembre si torna al 1° gennaio
     * dello stesso anno. Il salto si paga una volta ogni anno di gioco (circa
     * dodici giorni reali) e in cambio il teatro resta quello scelto.
     */
    public function date(int $gameTs): \DateTimeImmutable
    {
        $inizioAnno = (new \DateTimeImmutable(
            self::ANNO_CAMPAGNA . '-01-01 00:00:00',
            new \DateTimeZone('UTC')
        ))->getTimestamp();

        $durataAnno = self::secondiAnno(self::ANNO_CAMPAGNA);
        $dentro = (($gameTs % $durataAnno) + $durataAnno) % $durataAnno;

        return (new \DateTimeImmutable('@' . ($inizioAnno + $dentro)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** L'istante unix "astronomico" da passare ad Astro per un istante di gioco. */
    public function astroTs(int $gameTs): int
    {
        return $this->date($gameTs)->getTimestamp();
    }

    /**
     * Data e ora di bordo, in italiano: "14/03/1942 04:18".
     *
     * E' la forma buona per tutto quello che il gioco dice al giocatore: la
     * plancia, i rapporti, il fascicolo, l'albo. Il separatore e' la barra,
     * come si scrive una data in italiano.
     */
    public function format(int $gameTs, bool $conOra = true): string
    {
        return $this->date($gameTs)->format($conOra ? 'd/m/Y H:i' : 'd/m/Y');
    }

    /**
     * La stessa data come la scriverebbe chi tiene il giornale: "14.03.1942 04:18".
     *
     * I punti sono la forma tedesca, ed e' voluta: il Kriegstagebuch e' un
     * documento di bordo, scritto a mano da un ufficiale della Kriegsmarine, e
     * li' il colore vale piu' dell'uniformita'. Vale SOLO per il giornale di
     * guerra — la pagina, le ultime righe in centrale e il file esportato.
     * Ovunque altro si usa format(), e si scrive all'italiana.
     */
    public function formatDiario(int $gameTs, bool $conOra = true): string
    {
        return $this->date($gameTs)->format($conOra ? 'd.m.Y H:i' : 'd.m.Y');
    }

    /** Durata in forma leggibile: "3 g 04 h", "17 h 42 m", "8 min". */
    public static function durata(int $secondi): string
    {
        $secondi = max(0, $secondi);
        $g = intdiv($secondi, 86400);
        $h = intdiv($secondi % 86400, 3600);
        $m = intdiv($secondi % 3600, 60);
        if ($g > 0) {
            return sprintf('%d g %02d h', $g, $h);
        }
        if ($h > 0) {
            return sprintf('%d h %02d m', $h, $m);
        }
        // Sotto l'ora la "m" resta sola, e in una pagina dove ogni numero e'
        // in metri si legge come metri: qui si scrive per esteso. E sotto il
        // minuto non si dice "0", che sembra scaduto quando non lo e' ancora.
        if ($m < 1) {
            return 'meno di un minuto';
        }
        return sprintf('%d min', $m);
    }

    private static function secondiAnno(int $anno): int
    {
        $bisestile = ($anno % 4 === 0 && $anno % 100 !== 0) || $anno % 400 === 0;
        return ($bisestile ? 366 : 365) * 86400;
    }
}
