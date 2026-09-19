<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Acustica subacquea: l'idrofono e' il sensore che funziona quando gli occhi
 * non servono, ed e' con l'idrofono che i convogli si trovavano davvero.
 *
 * Modello classico del sonar passivo:
 *
 *      SNR = SL - TL - NL + DI - rumore proprio
 *
 * SL  livello della sorgente (quanto fa rumore il bersaglio)
 * TL  perdita per trasmissione (quanto si spegne il rumore con la distanza)
 * NL  rumore ambiente (il mare, la pioggia)
 * DI  guadagno dell'array (il GHG e' una schiera di idrofoni, non un orecchio)
 *
 * Taratura: un convoglio di quaranta navi a nove nodi si sente a circa trenta
 * miglia con mare moderato e a piu' di cinquanta con mare calmo; un mercantile
 * isolato a otto-venti miglia secondo il mare; un peschereccio a quattro. Sono
 * gli ordini di grandezza dei giornali di guerra.
 */
final class Acoustics
{
    /** Rapporto segnale-rumore minimo perche' l'idrofonista dica "contatto". */
    public const SOGLIA_SNR = 5.0;

    /** Guadagno della schiera GHG, in decibel. */
    public const DI_GHG = 15.0;
    /** Guadagno della base rotante KDB: meno direttiva, meno sensibile. */
    public const DI_KDB = 9.0;

    /**
     * Livello di sorgente di un'unita' (o di un gruppo).
     *
     * Il rumore cresce con la velocita' (le eliche lavorano di piu' e, oltre
     * una certa soglia, cavitano). Un gruppo di N navi somma le potenze, non i
     * decibel: da qui il termine logaritmico.
     */
    public static function sourceLevel(float $rumoreBase, float $speedKn, float $speedCrociera, int $unita = 1, int $eliche = 1): float
    {
        $rapporto = max(0.15, $speedKn / max(1.0, $speedCrociera));
        $sl = $rumoreBase + 18.0 * log10($rapporto);

        // Cavitazione: sopra il regime di crociera il rumore esplode.
        if ($rapporto > 1.05) {
            $sl += 12.0 * ($rapporto - 1.05);
        }
        if ($eliche > 1) {
            $sl += 2.5;
        }
        if ($unita > 1) {
            $sl += 10.0 * log10((float) $unita);
        }
        return $sl;
    }

    /**
     * Perdita per trasmissione: allargamento del fronte d'onda piu'
     * assorbimento. Se il suono deve attraversare lo strato termico, paga
     * un pedaggio pesante — ed e' il motivo per cui ci si nasconde sotto.
     */
    public static function transmissionLoss(float $distanzaNm, bool $attraversaStrato = false): float
    {
        $r = max(0.05, $distanzaNm);
        $tl = 60.0 + 20.0 * log10($r) + 0.28 * $r;
        if ($attraversaStrato) {
            $tl += 16.0;
        }
        return $tl;
    }

    /**
     * Rumore ambiente: il mare e' rumoroso, e col cattivo tempo non si sente
     * piu' niente. Scala di Knudsen, semplificata.
     */
    public static function ambientNoise(int $statoMare, float $precipitazione = 0.0): float
    {
        // Curva di Knudsen semplificata: fra mare calmo e mare grosso il
        // rumore del mare cresce di una ventina di decibel, non di trenta.
        return 48.0 + 3.6 * max(0, min(9, $statoMare)) + 5.0 * max(0.0, min(1.0, $precipitazione));
    }

    /**
     * Rumore proprio del battello: le nostre eliche, le pompe, i ventilatori.
     * In marcia silenziosa si spegne quasi tutto, e si sente il doppio.
     */
    public static function ownNoise(float $speedKn, bool $silenzioso, float $speedMax): float
    {
        $rapporto = max(0.0, $speedKn / max(1.0, $speedMax));
        $base = 2.0 + 26.0 * $rapporto ** 1.8;
        return $silenzioso ? $base * 0.35 : $base;
    }

    /**
     * Profondita' dello strato termico, in metri.
     *
     * D'estate alle medie latitudini lo strato e' netto e alto; d'inverno il
     * rimescolamento lo affonda o lo cancella del tutto. Sotto lo strato
     * l'ASDIC fatica a trovarvi — e voi faticate a sentire loro.
     * Restituisce 0 se lo strato non c'e'.
     */
    public static function layerDepth(float $lat, int $mese, int $statoMare): float
    {
        $estate = (cos(2 * M_PI * ($mese - 7) / 12.0) + 1.0) / 2.0;   // 1 a luglio, 0 a gennaio
        $tropicale = exp(-(($lat - 20.0) ** 2) / 1400.0);

        $forza = 0.25 + 0.6 * $estate + 0.35 * $tropicale;
        if ($statoMare >= 7) {
            $forza *= 0.45;                    // la burrasca rimescola tutto
        }
        if ($forza < 0.45) {
            return 0.0;                        // niente strato utilizzabile
        }
        return round(35.0 + 85.0 * $forza, 0);
    }

    /** Rapporto segnale-rumore di un contatto. */
    public static function snr(
        float $sourceLevel,
        float $distanzaNm,
        int $statoMare,
        float $precipitazione,
        float $rumoreProprio,
        bool $attraversaStrato = false,
        float $di = self::DI_GHG,
    ): float {
        return $sourceLevel
            - self::transmissionLoss($distanzaNm, $attraversaStrato)
            - self::ambientNoise($statoMare, $precipitazione)
            + $di
            - $rumoreProprio;
    }

    /**
     * Distanza alla quale un certo bersaglio diventa udibile: serve alla
     * plancia (e alle prove) per dire "oggi si sente a venti miglia".
     */
    public static function detectionRangeNm(
        float $sourceLevel,
        int $statoMare,
        float $precipitazione,
        float $rumoreProprio,
        bool $attraversaStrato = false,
        float $di = self::DI_GHG,
    ): float {
        $lo = 0.1;
        $hi = 120.0;
        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2;
            $snr = self::snr($sourceLevel, $mid, $statoMare, $precipitazione, $rumoreProprio, $attraversaStrato, $di);
            if ($snr > self::SOGLIA_SNR) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return round($lo, 1);
    }

    /**
     * Che cosa dice l'idrofonista. La classificazione non e' mai certa: si
     * sente il numero di eliche, il regime, e si tira a indovinare il resto.
     */
    public static function classifica(float $snr, int $unita, float $speedKn, int $eliche, Rng $rng): string
    {
        if ($unita >= 8) {
            return $snr > 22
                ? 'molte eliche, rumore di massa: un convoglio'
                : 'rumore confuso, molte sorgenti: forse un convoglio';
        }
        if ($unita > 1) {
            return $snr > 18 ? "{$unita} bastimenti in gruppo" : 'piu\' di una sorgente, imprecisata';
        }

        $veloce = $speedKn >= 13.0;
        if ($snr > 20) {
            $tipo = $veloce
                ? ($eliche > 1 ? 'unita\' veloce a due eliche: probabile nave da guerra' : 'unita\' veloce a un\'elica')
                : ($eliche > 1 ? 'bastimento a due eliche, andatura di crociera' : 'bastimento a un\'elica, andatura lenta');
            return $tipo;
        }
        if ($snr > 12) {
            return $veloce ? 'rumore d\'eliche rapido, poco definito' : 'rumore d\'eliche lento, poco definito';
        }
        return $rng->chance(0.5) ? 'rumore incerto al limite dell\'udibile' : 'qualcosa si sente, ma non si distingue';
    }
}
