<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\GameConfig;

/**
 * Rilevamento: il vero cuore del gioco.
 *
 * Tutto e' un problema di informazione asimmetrica — chi vede per primo, vive.
 * Il modello e' SIMMETRICO: le stesse formule con cui noi vediamo loro li
 * governano quando cercano noi. Cambiano solo le altezze degli occhi, le
 * sagome e gli strumenti.
 */
final class Detection
{
    /** Altezze d'occhio, in metri. */
    public const H_TORRETTA   = 4.5;    // vedette in torretta di un Tipo VII
    public const H_PERISCOPIO = 1.0;
    public const H_PONTE_SCORTA = 12.0;
    public const H_PONTE_MERCANTILE = 9.0;

    /** Sagome viste dal nemico, come frazione della sagoma in superficie. */
    public const S_SUPERFICIE  = 1.00;
    public const S_SEMIMMERSO  = 0.42;   // "a fior d'acqua": solo la torretta
    public const S_PERISCOPIO  = 0.11;
    public const S_IMMERSO     = 0.0;

    /**
     * Distanza dell'orizzonte geometrico fra due altezze, in miglia nautiche.
     * E' il limite che nessuna vista buona puo' superare: piu' in la' c'e' la
     * curvatura della Terra.
     */
    public static function orizzonteNm(float $hOsservatore, float $hBersaglio): float
    {
        return 2.08 * (sqrt(max(0.1, $hOsservatore)) + sqrt(max(0.1, $hBersaglio)));
    }

    /**
     * Fattore di luce per l'avvistamento visivo.
     *
     * Di giorno si vede fino all'orizzonte; di notte senza luna si vede un
     * decimo. Il crepuscolo nautico e' la fase d'oro dell'attacco in
     * superficie: chi sta a ponente vede il bersaglio stagliato contro il
     * cielo chiaro, e resta lui stesso nel buio.
     */
    public static function fattoreLuce(float $luce): float
    {
        // La luce va da 0,008 (notte senza luna) a 1,0 (pieno giorno). La
        // radice comprime la scala: fra notte fonda e plenilunio c'e' un
        // fattore due e mezzo, fra plenilunio e giorno un fattore tre.
        return max(0.06, min(1.0, 0.06 + 0.94 * sqrt(max(0.0, min(1.0, $luce)))));
    }

    /**
     * Distanza alla quale un bersaglio diventa visibile.
     *
     * @param float $hOsservatore altezza dell'occhio, in metri
     * @param float $hBersaglio   altezza della parte piu' alta del bersaglio
     * @param float $sagoma       quanto e' grande cio' che emerge (0-1)
     */
    public static function portataVisiva(
        float $hOsservatore,
        float $hBersaglio,
        float $sagoma,
        float $visibilitaNm,
        float $luce,
        int $statoMare,
        float $qualitaVedette = 1.0,
        bool $nebbia = false,
    ): float {
        if ($sagoma <= 0.0) {
            return 0.0;
        }

        $limite = min(self::orizzonteNm($hOsservatore, $hBersaglio), $visibilitaNm);
        $r = $limite * self::fattoreLuce($luce);

        // Una sagoma piccola si perde molto prima dell'orizzonte: un
        // periscopio non e' una torretta rimpicciolita, e' un'altra cosa.
        $r *= $sagoma ** 0.7;

        // Il mare grosso nasconde: fra un'onda e l'altra il bersaglio sparisce,
        // e gli spruzzi accecano le vedette.
        $r *= max(0.25, 1.0 - 0.085 * max(0, $statoMare - 2));

        // Vedette esperte e riposate contro vedette stanche. La bravura allunga
        // la portata utile, ma NON puo' spostare l'orizzonte: oltre quello c'e'
        // la curvatura della Terra, e non la si supera con gli occhi buoni.
        // (Quanto in fretta si accorgono di qualcosa dentro la portata lo
        // decide probabilitaVista, dove la qualita' conta di nuovo.)
        $r *= 0.55 + 0.55 * max(0.0, min(1.5, $qualitaVedette));
        $r = min($r, $limite);

        if ($nebbia) {
            $r = min($r, $visibilitaNm * 0.8);
        }

        return round(max(0.0, $r * (float) GameConfig::get('detect.scala_vista', 1.0)), 2);
    }

    /**
     * Probabilita' di avvistare in un intervallo, data la portata utile.
     * Non e' una soglia netta: piu' si e' dentro la portata, prima si vede.
     */
    public static function probabilitaVista(float $distanzaNm, float $portataNm, float $minuti, float $qualita = 1.0): float
    {
        if ($portataNm <= 0.0 || $distanzaNm > $portataNm) {
            return 0.0;
        }
        $vicinanza = 1.0 - ($distanzaNm / $portataNm);
        $tasso = 0.9 * $vicinanza ** 1.6 * max(0.25, min(1.6, $qualita));
        return 1.0 - exp(-$tasso * ($minuti / 5.0));
    }

    /**
     * Il fumo si vede prima dello scafo: i mercantili a carbone mal condotti
     * ne facevano parecchio, e la colonna si scorge oltre l'orizzonte.
     */
    public static function portataFumo(float $portataVisiva, float $luce, int $unita): float
    {
        if ($luce < 0.25) {
            return 0.0;                              // di notte il fumo non si vede
        }
        return $portataVisiva * (1.55 + 0.02 * min(40, $unita));
    }

    /**
     * Radar di superficie alleato (tipo 271 centimetrico e successivi).
     * Non gli importa della luce; gli importa del mare, che riempie lo schermo
     * di ritorni parassiti e nasconde una torretta.
     */
    public static function portataRadar(float $sagoma, int $statoMare, bool $attivo = true): float
    {
        if (!$attivo || $sagoma <= 0.0) {
            return 0.0;
        }
        $base = 9.0 * (0.25 + 0.75 * $sagoma);       // torretta a circa 8 miglia con mare piatto
        return round(max(0.0, $base * max(0.2, 1.0 - 0.11 * max(0, $statoMare - 1))), 2);
    }

    /**
     * Rilevamento idrofonico di un'unita' o di un gruppo.
     *
     * @return array{udito:bool,snr:float,portata_nm:float}
     */
    public static function idrofono(
        float $sourceLevel,
        float $distanzaNm,
        int $statoMare,
        float $precipitazione,
        float $rumoreProprio,
        bool $attraversaStrato,
        float $qualitaAscolto = 1.0,
        bool $guasto = false,
    ): array {
        if ($guasto) {
            return ['udito' => false, 'snr' => -99.0, 'portata_nm' => 0.0];
        }

        $di = Acoustics::DI_GHG * (float) GameConfig::get('detect.scala_idrofono', 1.0)
            * (0.85 + 0.25 * max(0.0, min(1.4, $qualitaAscolto)));

        $snr = Acoustics::snr($sourceLevel, $distanzaNm, $statoMare, $precipitazione, $rumoreProprio, $attraversaStrato, $di);

        return [
            'udito'      => $snr > Acoustics::SOGLIA_SNR,
            'snr'        => round($snr, 1),
            'portata_nm' => Acoustics::detectionRangeNm($sourceLevel, $statoMare, $precipitazione, $rumoreProprio, $attraversaStrato, $di),
        ];
    }

    /**
     * Sagoma del battello vista da fuori, in base al modo e alla quota.
     */
    public static function sagomaBattello(string $modo, float $quota, bool $periscopioAlzato = false): float
    {
        if ($modo === 'superficie') {
            return $quota < 1.0 ? self::S_SUPERFICIE : self::S_SEMIMMERSO;
        }
        if ($modo === 'periscopio') {
            return $periscopioAlzato ? self::S_PERISCOPIO : self::S_IMMERSO;
        }
        return self::S_IMMERSO;
    }

    /**
     * La baffa del periscopio.
     *
     * Il tubo in se' e' un dito che spunta dall'acqua: quasi niente. Quello che
     * tradisce e' la scia — la piccola onda bianca che la corsa lascia dietro,
     * tanto piu' evidente quanto piu' si va forte e quanto piu' il mare e'
     * liscio. Con mare forza 4 e oltre si perde fra le creste e non conta piu'.
     *
     * Restituisce un fattore moltiplicativo da applicare alla sagoma: 1.0
     * quando non si vede nulla di piu' del tubo.
     */
    public static function baffaPeriscopio(float $velocitaKn, int $statoMare): float
    {
        if ($velocitaKn <= 1.5 || $statoMare >= 4) {
            return 1.0;
        }
        // Cresce col quadrato della velocita' (e' un fenomeno di onda) e si
        // spegne man mano che il mare si alza.
        $onda = min(1.0, (($velocitaKn - 1.5) / 4.5) ** 2);
        $liscio = max(0.0, (4 - $statoMare) / 4.0);
        return 1.0 + 2.2 * $onda * $liscio;
    }

    /**
     * Probabilita' oraria che un aereo da pattugliamento compaia sulla
     * verticale, in base alla zona, al calore del settore e all'ora.
     *
     * @param list<array<string,mixed>> $zone
     * @return array{probabilita:float,classe:?string,zona:?string}
     */
    public static function aereo(array $zone, float $lat, float $lon, float $luce, int $statoMare, float $heat, Rng $rng): array
    {
        $migliore = null;
        $prob = 0.0;

        foreach ($zone as $z) {
            $d = Geo::distanceNm($lat, $lon, (float) $z['lat'], (float) $z['lon']);
            if ($d > (float) $z['raggio_nm']) {
                continue;
            }
            // Densita' massima al centro della zona, in calo verso il bordo.
            $p = (float) $z['intensita'] * (1.0 - 0.75 * ($d / (float) $z['raggio_nm']));
            if ($p > $prob) {
                $prob = $p;
                $migliore = $z;
            }
        }

        if ($migliore === null) {
            return ['probabilita' => 0.0, 'classe' => null, 'zona' => null];
        }

        // Di notte volano in meno, ma quelli che volano hanno il radar e il
        // faro Leigh: meno probabile, molto piu' pericoloso.
        $prob *= $luce > 0.25 ? 1.0 : 0.45;
        // Col mare grosso il pattugliamento si dirada.
        $prob *= max(0.3, 1.0 - 0.07 * max(0, $statoMare - 4));
        // Dove si e' fatto rumore, il cielo si popola.
        $prob *= 1.0 + 0.9 * max(0.0, min(1.0, $heat / 100.0));

        $classi = $migliore['classi'];
        if ($luce <= 0.25 && in_array('wellington_leigh', $classi, true)) {
            $classe = 'wellington_leigh';
        } else {
            $classe = (string) $classi[$rng->int(0, count($classi) - 1)];
        }

        return ['probabilita' => $prob, 'classe' => $classe, 'zona' => (string) $migliore['nome']];
    }

    /** Errore di stima della distanza a occhio: grande, e sempre in difetto o in eccesso. */
    public static function erroreDistanza(float $distanzaNm, string $sensore, Rng $rng): float
    {
        $quota = match ($sensore) {
            'vista'     => 0.18,
            'radar'     => 0.04,
            'idrofono'  => 0.45,
            'fumo'      => 0.55,
            default     => 0.35,
        };
        return round(max(0.1, $distanzaNm * $quota * abs($rng->gauss())), 2);
    }
}
