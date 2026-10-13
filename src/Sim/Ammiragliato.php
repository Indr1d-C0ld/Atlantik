<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * La guerra delle informazioni: l'Ammiragliato e il B-Dienst.
 *
 * La battaglia dell'Atlantico si vinceva e si perdeva soprattutto sulle carte:
 * un convoglio che non si trova non si affonda. A Londra la Submarine Tracking
 * Room metteva insieme i punti radiogoniometrici delle trasmissioni degli
 * U-Boot, gli avvistamenti e gli affondamenti, e faceva girare i convogli al
 * largo. Nel 1942 lo faceva quasi alla cieca su Ultra — da febbraio a dicembre
 * la chiave Triton degli U-Boot non si leggeva — e quindi coi radiogoniometri:
 * trasmettere costava caro proprio per questo. Dall'altra parte il B-Dienst
 * leggeva i cifrari navali alleati dei convogli, e il BdU sapeva spesso dove
 * stava passando il traffico.
 *
 * Qui:
 *   - l'Ammiragliato guarda la rotta di ogni convoglio ogni sei ore di gioco;
 *     se nelle prossime settecento miglia passa vicino a un pericolo noto — un
 *     settore caldo (avvistamenti, affondamenti, trasmissioni) o un punto
 *     radiogoniometrico recente — lo fa deviare, del meno possibile, dalla
 *     parte buona, e mai sopra la terraferma. Il convoglio non salta: accosta
 *     (Traffic::deviazioneAl);
 *   - una deviazione su tre la legge il B-Dienst, e qualche ora dopo il BdU la
 *     comunica a tutti i battelli in mare, con la posizione stimata: nel
 *     giornale di guerra, negli avvisi per posta, e ai colleghi, che ci vanno.
 *
 * Il calore dei settori c'era gia' (Sectors) e governava aerei e scorte; ora
 * governa anche dove passa il traffico. Chi trasmette troppo vede i convogli
 * girargli intorno.
 */
final class Ammiragliato
{
    /** Ogni quanto si guarda la rotta di un convoglio, in secondi di gioco. */
    private const OGNI_S = 6 * 3600;
    /** Quanto avanti si guarda, e ogni quanto si campiona la rotta futura. */
    private const ORIZZONTE_NM = 700.0;
    private const CAMPIONE_NM = 25.0;
    /** Una deviazione cosi' piccola e' quella della partenza, non dell'Ammiragliato. */
    private const PICCOLA = 2.0;
    /** Quanto avanti la rotta deviata deve restare in mare. */
    private const TERRA_NM = 1000.0;
    /** Le deviazioni possibili, dalla piu' piccola: 40, 72, 112 e 144 miglia di lato. */
    private const CANDIDATE = [25.0, 45.0, 70.0, 90.0];

    /**
     * Il battito.
     *
     * @return array{valutati:int, deviati:int, decifrati:int}
     */
    public static function valuta(int $gts): array
    {
        $esito = ['valutati' => 0, 'deviati' => 0, 'decifrati' => 0];
        $pericoli = self::pericoli($gts);

        $convogli = Database::all(
            "SELECT * FROM convoys c
              WHERE c.state = 'in_mare' AND c.esercitazione = 0 AND c.departed_gts <= ? AND c.eta_gts >= ?
                AND (c.dev_valutata_gts IS NULL OR c.dev_valutata_gts <= ?)
                AND NOT EXISTS (SELECT 1 FROM encounters e WHERE e.convoy_id = c.id AND e.stato <> 'concluso')
              ORDER BY c.id LIMIT 40",
            [$gts, $gts, $gts - self::OGNI_S]
        );
        foreach ($convogli as $cv) {
            $esito['valutati']++;
            if (self::devia($cv, $gts, $pericoli) !== null) {
                $esito['deviati']++;
            }
        }

        $esito['decifrati'] = self::bdienst($gts);
        return $esito;
    }

    /**
     * Guarda la rotta di un convoglio e, se serve, lo fa deviare. Torna la
     * deviazione nuova, o null se resta com'e'.
     *
     * @param list<array{lat:float,lon:float}> $pericoli
     */
    public static function devia(array $cv, int $gts, array $pericoli): ?float
    {
        Database::run('UPDATE convoys SET dev_valutata_gts = ? WHERE id = ?', [$gts, (int) $cv['id']]);
        $nuova = self::scegli($cv, $gts, $pericoli);
        if ($nuova === null) {
            return null;
        }
        // Si parte da dove si e' adesso: la deviazione in corso, a meta' accostata.
        $ora = round(Traffic::deviazioneAl($cv, $gts), 2);
        $rng = Rng::for(World::seed(), 'bdienst', (int) $cv['id'], $gts);
        $decifra = $rng->chance((float) GameConfig::get('ammiragliato.bdienst', 0.35));
        Database::run(
            'UPDATE convoys SET dev_da = ?, deviazione = ?, dev_inizio_gts = ?, bdienst_gts = ? WHERE id = ?',
            [$ora, $nuova, $gts, $decifra ? $gts + $rng->int(6, 18) * 3600 : null, (int) $cv['id']]
        );
        return $nuova;
    }

    /**
     * Quello che l'Ammiragliato sa: i settori caldi e i punti radiogoniometrici
     * delle ultime dodici ore.
     *
     * @return list<array{lat:float,lon:float}>
     */
    public static function pericoli(int $gts): array
    {
        $soglia = (float) GameConfig::get('ammiragliato.soglia_calore', 30.0);
        $out = [];
        foreach (Sectors::caldi($gts, 40) as $s) {
            if ((float) $s['heat'] < $soglia) {
                continue;
            }
            $c = Grid::fromQuadrat((string) $s['quadrat']);
            if ($c !== null) {
                $out[] = ['lat' => (float) $c['lat'], 'lon' => (float) $c['lon']];
            }
        }
        foreach (Database::all('SELECT lat, lon FROM hfdf_fixes WHERE gts >= ? AND lat IS NOT NULL', [$gts - 12 * 3600]) as $f) {
            $out[] = ['lat' => (float) $f['lat'], 'lon' => (float) $f['lon']];
        }
        return $out;
    }

    /**
     * La deviazione nuova, o null se va bene quella che c'e'.
     *
     * Una deviazione e' buona se nelle prossime settecento miglia tiene il
     * convoglio lontano dai pericoli noti e nelle prossime mille resta in mare
     * (le trecento in piu' sono quelle che servono per tornare sulla rotta, a
     * pericolo passato). Se quella attuale e' buona si prova a tornare verso la
     * rotta base — l'Ammiragliato non teneva un convoglio a centocinquanta
     * miglia di lato piu' del necessario —; se non lo e', fra le possibili si
     * prende quella buona che cambia di meno. Se nessuna evita i pericoli, quella
     * che ci passa piu' lontano senza toccare terra. La rotta base (zero) e'
     * sempre in mare.
     *
     * @param list<array{lat:float,lon:float}> $pericoli
     */
    public static function scegli(array $cv, int $gts, array $pericoli): ?float
    {
        $raggio = (float) GameConfig::get('ammiragliato.raggio_nm', 120.0);
        $attuale = (float) $cv['deviazione'];
        $ora = Traffic::deviazioneAl($cv, $gts);
        $lontano = fn (float $d): float => $pericoli === [] ? INF : self::distanza($cv, $gts, $d, $pericoli);

        $candidate = [0.0];
        foreach (self::CANDIDATE as $c) {
            $candidate[] = $c;
            $candidate[] = -$c;
        }

        if ($lontano($attuale) >= $raggio && self::inMare($cv, $gts, $attuale)) {
            // Le deviazioni piccole sono quelle estratte alla partenza (al
            // massimo due miglia): non sono dell'Ammiragliato, e restano.
            if (abs($attuale) <= self::PICCOLA) {
                return null;
            }
            // Pericolo passato: si torna verso la rotta, del tutto se si puo'.
            usort($candidate, static fn (float $a, float $b): int => abs($a) <=> abs($b));
            foreach ($candidate as $c) {
                if (abs($c) >= abs($attuale) - 0.01) {
                    break;
                }
                if ($lontano($c) >= $raggio && self::inMare($cv, $gts, $c)) {
                    return $c;
                }
            }
            return null;
        }

        usort($candidate, static fn (float $a, float $b): int => abs($a - $ora) <=> abs($b - $ora));
        $meglio = null;
        $piuLontano = self::inMare($cv, $gts, $attuale) ? $lontano($attuale) : -1.0;
        foreach ($candidate as $c) {
            if (abs($c - $attuale) < 0.01) {
                continue;
            }
            $d = $lontano($c);
            if ($d <= $piuLontano || !self::inMare($cv, $gts, $c)) {
                continue;
            }
            if ($d >= $raggio) {
                return $c;                      // la piu' piccola che basta
            }
            $meglio = $c;
            $piuLontano = $d;
        }
        return $meglio;
    }

    /**
     * La distanza minima fra la rotta futura (con una deviazione) e i pericoli.
     *
     * @param list<array{lat:float,lon:float}> $pericoli
     */
    private static function distanza(array $cv, int $gts, float $deviazione, array $pericoli): float
    {
        $min = INF;
        foreach (self::campioni($cv, $gts, $deviazione) as [$la, $lo]) {
            foreach ($pericoli as $p) {
                $min = min($min, Geo::distanceNm($la, $lo, $p['lat'], $p['lon']));
            }
        }
        return $min;
    }

    /**
     * La rotta con quella deviazione non tocca terra nelle prossime mille
     * miglia: le settecento che si guardano per i pericoli e le trecento che
     * servono per tornare sulla rotta. Sulla rotta di Gibilterra, guardando
     * solo le prime settecento, si finiva sul Portogallo (test_ammiragliato,
     * 09/10/2026).
     */
    private static function inMare(array $cv, int $gts, float $deviazione): bool
    {
        $prima = null;
        foreach (self::campioni($cv, $gts, $deviazione, self::TERRA_NM) as $p) {
            if ($prima !== null && Terra::attraversa($prima[0], $prima[1], $p[0], $p[1])) {
                return false;
            }
            $prima = $p;
        }
        return true;
    }

    /**
     * Punti della rotta futura, ogni venticinque miglia, fino all'orizzonte o
     * all'arrivo.
     *
     * @return list<array{0:float,1:float}>
     */
    private static function campioni(array $cv, int $gts, float $deviazione, float $miglia = self::ORIZZONTE_NM): array
    {
        $v = max(1.0, (float) $cv['speed_kn']);
        $fine = min((int) $cv['eta_gts'], $gts + (int) ($miglia / $v * 3600));
        $passo = (int) (self::CAMPIONE_NM / $v * 3600);
        $out = [];
        for ($t = $gts + $passo; $t <= $fine; $t += $passo) {
            $p = Traffic::posizione((string) $cv['rotta_key'], $v, (int) $cv['departed_gts'], $t, $deviazione);
            if ($p !== null) {
                $out[] = [$p['lat'], $p['lon']];
            }
        }
        return $out;
    }

    /**
     * Il B-Dienst ha decifrato una deviazione: il BdU la comunica a tutti i
     * battelli in mare, con la posizione del convoglio adesso.
     */
    public static function bdienst(int $gts): int
    {
        $n = 0;
        foreach (Database::all(
            "SELECT * FROM convoys WHERE state = 'in_mare' AND bdienst_gts IS NOT NULL AND bdienst_gts <= ?",
            [$gts]
        ) as $cv) {
            Database::run('UPDATE convoys SET bdienst_gts = NULL WHERE id = ?', [(int) $cv['id']]);
            $p = Traffic::pos($cv, $gts);
            if ($p === null) {
                continue;
            }
            $quadrat = Grid::toQuadrat($p['lat'], $p['lon'], 2);
            $testo = sprintf(
                'B-Dienst: il convoglio %s %d e\' stato deviato. Posizione stimata adesso in %s, rotta %03.0f, %.0f nodi.',
                (string) $cv['serie'], (int) $cv['numero'], $quadrat ?? '—', $p['heading'], (float) $cv['speed_kn']
            );
            Database::run(
                'INSERT INTO radio_messages (destinatario, tipo, testo, quadrat, lat, lon, gts, durata_s)
                 VALUES ("tutti", "comunicato", ?, ?, ?, ?, ?, 0)',
                [$testo, $quadrat, $p['lat'], $p['lon'], $gts]
            );
            // E nel giornale di chi e' in mare: e' li' che si legge, ed e' da
            // li' che parte l'avviso per posta.
            foreach (Database::all(
                "SELECT b.id, b.lat, b.lon, p.id patrol_id FROM boats b
                   JOIN patrols p ON p.boat_id = b.id AND p.state = 'in_corso' AND p.tipo = 'guerra'
                  WHERE b.state = 'mare'"
            ) as $b) {
                BoatSim::save([
                    'gts' => $gts, 'kind' => 'bdu', 'severity' => 'nota',
                    'lat' => (float) $b['lat'], 'lon' => (float) $b['lon'], 'quadrat' => $quadrat,
                    'text' => 'Dal BdU: ' . $testo . sprintf(' Da qui %.0f miglia per %03.0f.',
                        Geo::distanceNm((float) $b['lat'], (float) $b['lon'], $p['lat'], $p['lon']),
                        Geo::bearing((float) $b['lat'], (float) $b['lon'], $p['lat'], $p['lon'])),
                ], (int) $b['patrol_id'], (int) $b['id']);
            }
            $n++;
        }
        return $n;
    }
}
