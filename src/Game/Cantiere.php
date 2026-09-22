<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;

/**
 * Il cantiere della base.
 *
 * A mare ripara l'equipaggio, e lo fa piano: sono gli stessi uomini che tengono
 * i quarti, con i ricambi che hanno in cassa e il mare che balla. In porto no:
 * il battello entra nel bunker e ci mettono le mani gli operai della
 * Kriegsmarinewerft, che sono tanti, riposati e con il magazzino dietro.
 *
 * Per questo la resa e' fissa e non dipende ne' dalla competenza dell'equipaggio
 * ne' dalla sua fatica: in banchina l'equipaggio e' in libera uscita.
 *
 * Segnalato dall'utente il 21/09/2026: "non sembra che il mio battello venga
 * riparato". Aveva ragione, e il difetto era piu' profondo di quanto sembrasse.
 * Le riparazioni vivevano solo dentro BoatSim::advance, che il battito applica
 * ai soli battelli in mare, e i controllori delle pagine saltavano del tutto
 * l'avanzamento quando il battello era in base. In porto quindi non girava
 * niente: la barra restava dov'era e "dai priorita'" salvava un valore che
 * nessuno andava mai a leggere. L'avaria si chiudeva solo alla partenza dopo,
 * di colpo, perche' Damage::overhaul rimette tutto a nuovo — e cosi' il tempo
 * passato in banchina non voleva dire niente.
 */
final class Cantiere
{
    /**
     * Ore-uomo che il cantiere produce per ogni ora di gioco.
     *
     * Il numero e' fisso perche' il bacino ha tutte le maestranze: qualunque
     * sia il lavoro, c'e' chi lo sa fare.
     *
     * A mare no, e la differenza non e' uniforme. Misurato il 22/09/2026 su un
     * equipaggio vero, la resa di Damage::repairStep va da 0,25 a 2,50
     * ore-uomo l'ora secondo la specialita' che il sistema richiede: i
     * macchinisti del diesel rendono 2,35 e i marinai 2,50, quindi per quei
     * sistemi il cantiere e' appena una volta e mezza piu' rapido. Ma la resa
     * dei radiotelegrafisti e' 0,33, e per una stazione radio il bacino va
     * nove volte piu' in fretta. Per i periscopi e lo scafo il
     * confronto non esiste proprio: a mare non si toccano.
     *
     * Tre e' quindi un valore che non svilisce l'equipaggio dove e' bravo e
     * che fa la differenza dove a bordo non c'e' nessuno di mestiere. I timoni
     * orizzontali, che sono quattro ore-uomo, se ne vanno in un'ora e venti di
     * gioco: abbastanza lento da far pagare in tempo un rientro malconcio.
     */
    private const RESA = 3.0;

    /** L'ordine con cui il cantiere affronta i lavori, se non gli si dice altro. */
    private const PRIORITA = ['propulsione' => 0, 'governo' => 1, 'scoperta' => 2, 'armamento' => 3, 'scafo' => 4];

    /**
     * Lavora per le ore di gioco indicate.
     *
     * Non prende il lucchetto: lo chiama BoatSim::avanza, che ce l'ha gia'.
     *
     * @return array{riparati:list<string>,inCorso:?string}
     */
    public static function lavora(int $boatId, float $ore, ?string $focus): array
    {
        $riparati = [];
        $inCorso  = null;
        if ($ore <= 0.0) {
            return ['riparati' => $riparati, 'inCorso' => $inCorso];
        }

        // Le ore-uomo di tutto il periodo si spendono in fila: finito un lavoro,
        // quel che avanza va sul successivo. Senza questo, un battello lasciato
        // in porto una notte si ritroverebbe un solo sistema a posto e il resto
        // intatto, perche' il grosso del tempo sarebbe stato buttato.
        $bilancio = $ore * self::RESA;

        // Il giro ha un tetto solo per non poter mai diventare infinito se un
        // sistema tornasse rotto mentre lo si ripara: i sistemi sono diciannove.
        for ($giro = 0; $giro < 25 && $bilancio > 0.0001; $giro++) {
            $rotti = Database::all(
                "SELECT skey, name, category, state, repair_hours, repair_progress
                   FROM boat_systems WHERE boat_id = ? AND state <> 'ok'",
                [$boatId]
            );
            if ($rotti === []) {
                break;
            }

            $sistema = self::scegli($rotti, $focus);
            $chiave  = (string) $sistema['skey'];

            // Un'avaria grave costa quasi il doppio, come a mare: non si rimette
            // a posto, si rimette in piedi.
            $necessarie = max(0.5, (float) $sistema['repair_hours'])
                * ((string) $sistema['state'] === 'distrutto' ? 1.8 : 1.0);
            $fatte = (float) $sistema['repair_progress'];
            $manca = max(0.0, $necessarie - $fatte);

            if ($manca <= $bilancio) {
                // In bacino il lavoro si finisce: non il rattoppo del 55 per
                // cento che si fa a mare, ma il sistema rimesso a nuovo.
                Database::run(
                    "UPDATE boat_systems SET state = 'ok', condition_pct = 100, repair_progress = 0
                      WHERE boat_id = ? AND skey = ?",
                    [$boatId, $chiave]
                );
                $bilancio -= $manca;
                $riparati[] = (string) $sistema['name'];

                // La priorita' e' servita: da qui in poi il cantiere torna a
                // decidere da solo, se no continuerebbe a puntare un sistema
                // che non e' piu' rotto.
                if ($focus === $chiave) {
                    Database::run('UPDATE boats SET repair_focus = NULL WHERE id = ?', [$boatId]);
                    $focus = null;
                }
                continue;
            }

            Database::run(
                'UPDATE boat_systems SET repair_progress = ? WHERE boat_id = ? AND skey = ?',
                [round($fatte + $bilancio, 5), $boatId, $chiave]
            );
            $inCorso  = (string) $sistema['name'];
            $bilancio = 0.0;
        }

        // Finiti i sistemi, il bacino si occupa dello scafo interno: le paratie
        // sigillate si riaprono solo qui — a mare non si riaprono per nessun
        // motivo, e chi e' rimasto dentro non torna comunque.
        if ($bilancio > 0.0001) {
            $esito = self::compartimenti($boatId, $bilancio);
            $riparati = array_merge($riparati, $esito['riparati']);
            $inCorso ??= $esito['inCorso'];
        }

        // Per ultima la manutenzione: i sistemi che funzionano ma sono logori.
        //
        // Non e' un lusso. La condizione moltiplica il tasso di guasto
        // (Damage, riga 138: piu' e' basso, piu' spesso si rompe), e fino al
        // 21/09/2026 a riportare tutto a cento ci pensava la partenza. Tolta
        // quella, senza manutenzione un sistema logoro non sarebbe mai piu'
        // tornato a nuovo: una china in un senso solo, con il battello che si
        // guasta sempre piu' spesso e nessun modo di rimediare.
        if ($bilancio > 0.0001) {
            $esito = self::manutenzione($boatId, $bilancio);
            $riparati = array_merge($riparati, $esito['riparati']);
            $inCorso ??= $esito['inCorso'];
        }

        return ['riparati' => $riparati, 'inCorso' => $inCorso];
    }

    /**
     * Revisione dei sistemi che funzionano ma sono consumati.
     *
     * @return array{riparati:list<string>,inCorso:?string}
     */
    private static function manutenzione(int $boatId, float $bilancio): array
    {
        $riparati = [];
        $inCorso  = null;

        for ($giro = 0; $giro < 25 && $bilancio > 0.0001; $giro++) {
            $s = Database::first(
                "SELECT skey, name, condition_pct, repair_progress FROM boat_systems
                  WHERE boat_id = ? AND state = 'ok' AND condition_pct < 100
                  ORDER BY condition_pct ASC LIMIT 1",
                [$boatId]
            );
            if ($s === null) {
                break;
            }

            // Rimettere a nuovo costa meno che rimettere in piedi: qui non c'e'
            // niente di rotto, si smonta, si pulisce e si cambia il consumato.
            $necessarie = max(0.25, (100.0 - (float) $s['condition_pct']) / 12.0);
            $fatte = (float) $s['repair_progress'];
            $manca = max(0.0, $necessarie - $fatte);

            if ($manca <= $bilancio) {
                Database::run(
                    "UPDATE boat_systems SET condition_pct = 100, repair_progress = 0
                      WHERE boat_id = ? AND skey = ?",
                    [$boatId, (string) $s['skey']]
                );
                $bilancio -= $manca;
                $riparati[] = (string) $s['name'];
                continue;
            }

            Database::run(
                'UPDATE boat_systems SET repair_progress = ? WHERE boat_id = ? AND skey = ?',
                [round($fatte + $bilancio, 5), $boatId, (string) $s['skey']]
            );
            $inCorso  = (string) $s['name'];
            $bilancio = 0.0;
        }

        return ['riparati' => $riparati, 'inCorso' => $inCorso];
    }

    /**
     * Il lavoro di bacino sui compartimenti.
     *
     * @return array{riparati:list<string>,inCorso:?string}
     */
    private static function compartimenti(int $boatId, float $bilancio): array
    {
        $riparati = [];
        $inCorso  = null;

        for ($giro = 0; $giro < 12 && $bilancio > 0.0001; $giro++) {
            $c = Database::first(
                'SELECT id, name, integrity, flooding, fire, sealed, repair_progress
                   FROM boat_compartments
                  WHERE boat_id = ? AND (integrity < 100 OR flooding > 0 OR fire > 0 OR sealed = 1)
                  ORDER BY sealed DESC, integrity ASC LIMIT 1',
                [$boatId]
            );
            if ($c === null) {
                break;
            }

            $necessarie = self::costoCompartimento($c);
            $fatte = (float) $c['repair_progress'];
            $manca = max(0.0, $necessarie - $fatte);

            if ($manca <= $bilancio) {
                Database::run(
                    'UPDATE boat_compartments SET integrity = 100, flooding = 0, fire = 0, sealed = 0,
                            repair_progress = 0 WHERE id = ?',
                    [(int) $c['id']]
                );
                $bilancio -= $manca;
                $riparati[] = (string) $c['name'];
                continue;
            }

            Database::run(
                'UPDATE boat_compartments SET repair_progress = ? WHERE id = ?',
                [round($fatte + $bilancio, 5), (int) $c['id']]
            );
            $inCorso  = (string) $c['name'];
            $bilancio = 0.0;
        }

        return ['riparati' => $riparati, 'inCorso' => $inCorso];
    }

    /**
     * Quanto costa rimettere in sesto un compartimento, in ore-uomo.
     *
     * Non sono numeri presi da un documento: non esiste un prontuario dei
     * tempi di bacino della Kriegsmarinewerft. Sono tarati sul resto del
     * gioco, e cioe' sulle ore-uomo dei sistemi, che vanno da due (la
     * mitragliera) a trenta (lo scafo resistente). Un compartimento sfondato e
     * sigillato viene a costare una ventina di ore-uomo: fra un diesel e lo
     * scafo, che e' dove sta.
     *
     * @param array<string,mixed> $c
     */
    private static function costoCompartimento(array $c): float
    {
        $costo = (100.0 - (float) $c['integrity']) / 8.0    // lamiere e rinforzi
               + (float) $c['flooding'] / 12.0              // svuotare e asciugare
               + (float) $c['fire'] / 15.0                  // quello che il fuoco ha annerito
               + ((int) $c['sealed'] === 1 ? 4.0 : 0.0);    // riaprire una paratia sigillata

        return max(0.5, $costo);
    }

    /**
     * Su che cosa si mettono. Se il comandante ha indicato un sistema si fa
     * quello, purche' sia ancora rotto.
     *
     * @param  list<array<string,mixed>> $rotti
     * @return array<string,mixed>
     */
    private static function scegli(array $rotti, ?string $focus): array
    {
        if ($focus !== null) {
            foreach ($rotti as $r) {
                if ((string) $r['skey'] === $focus) {
                    return $r;
                }
            }
        }

        usort($rotti, static fn (array $a, array $b): int
            => (self::PRIORITA[$a['category']] ?? 9) <=> (self::PRIORITA[$b['category']] ?? 9));

        return $rotti[0];
    }
}
