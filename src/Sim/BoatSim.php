<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\Lock;
use App\Core\GameConfig;
use App\Game\Cantiere;
use App\Game\Outfitting;

/**
 * L'avanzamento del battello: il cuore del motore di crociera.
 *
 * Lo stesso codice serve il tick da cron e la richiesta del giocatore
 * (avanzamento pigro). E' idempotente rispetto all'istante d'arrivo: portare
 * il battello alle 14:00 due volte non produce due volte gli stessi eventi,
 * perche' l'avanzamento parte sempre da last_sim_gts e le estrazioni casuali
 * dipendono dal numero del sotto-passo, non dal momento in cui si calcola.
 */
final class BoatSim
{
    /** Secondi d'attesa per il lucchetto del battello prima di rinunciare. */
    private const ATTESA_LUCCHETTO = 3;

    /**
     * Porta un battello all'istante di gioco indicato (per difetto: adesso).
     *
     * Un battello alla volta: il battito del minuto e la richiesta del
     * giocatore sono processi diversi e possono capitare nello stesso istante.
     * Senza lucchetto partirebbero dallo stesso last_sim_gts e applicherebbero
     * due volte gli effetti della stessa finestra di tempo — righe di giornale
     * doppie, contatti aperti due volte, avarie contate due volte. Chi arriva
     * secondo non aspetta: se ne va e trovera' il lavoro gia' fatto.
     *
     * @return array{steps:int,from:int,to:int,dist_nm:float,events:int}
     */
    public static function advance(int $boatId, ?int $toGts = null): array
    {
        // Si aspetta qualche secondo invece di rinunciare subito: l'avanzamento
        // di un battello dura una ventina di millisecondi, quindi chi trova
        // occupato fa in tempo ad aspettare il suo turno e a mostrare uno stato
        // fresco. Il valore di ripiego serve solo se qualcosa e' andato storto
        // davvero — non deve capitare che il giocatore veda la plancia ferma.
        return Lock::con(
            'boat:' . $boatId,
            static fn (): array => self::avanza($boatId, $toGts),
            self::fermo($boatId),
            self::ATTESA_LUCCHETTO
        );
    }

    /** Esito nullo: il battello e' gia' in mano a qualcun altro. */
    private static function fermo(int $boatId): array
    {
        $r = Database::first('SELECT last_sim_gts FROM boats WHERE id = ?', [$boatId]);
        $g = $r === null ? 0 : (int) $r['last_sim_gts'];
        return ['steps' => 0, 'from' => $g, 'to' => $g, 'dist_nm' => 0.0, 'events' => 0];
    }

    /** @return array{steps:int,from:int,to:int,dist_nm:float,events:int} */
    private static function avanza(int $boatId, ?int $toGts = null): array
    {
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
        if ($boat === null) {
            throw new \RuntimeException("Battello {$boatId} inesistente.");
        }

        $clock = World::clock();
        $adesso = $clock->now();

        // L'orologio di un battello non passa mai davanti a quello del mondo.
        //
        // Chi chiama puo' chiedere di fermarsi prima — l'incontro tattico lo fa
        // — ma nessuno puo' chiedere di simulare il futuro: un battello portato
        // avanti resta poi fermo finche' il mondo non lo raggiunge, e nel
        // frattempo non si muove, non consuma e non incontra nessuno. Ci si e'
        // arrivati per davvero: una prova che avanzava "di due ore" continuava
        // a spingere in avanti un battello vero a ogni esecuzione.
        $toGts = min($toGts ?? $adesso, $adesso);
        $from = (int) $boat['last_sim_gts'];

        // Durante un incontro tattico comanda Encounter::step: se anche la
        // crociera avanzasse, il battello vivrebbe due volte lo stesso tempo.
        if ($boat['encounter_id'] !== null) {
            return ['steps' => 0, 'from' => $from, 'to' => $from, 'dist_nm' => 0.0, 'events' => 0];
        }

        if ((string) $boat['state'] !== 'mare') {
            // In porto non si naviga, ma si lavora: il cantiere ripara.
            //
            // Fino al 21/09/2026 qui si spostava solo l'orologio, e quindi un
            // battello in base non veniva riparato mai — l'avaria si chiudeva
            // di colpo alla partenza dopo. Il recupero si limita come a mare:
            // un battello dimenticato in porto per settimane non deve rimettersi
            // a nuovo in un colpo solo al primo che ricarica la pagina.
            $max = World::maxCatchupSeconds();
            if ($toGts - $from > $max) {
                $toGts = $from + $max;
            }
            if ($toGts > $from && (string) $boat['state'] === 'base') {
                Cantiere::lavora(
                    $boatId,
                    ($toGts - $from) / 3600.0,
                    $boat['repair_focus'] !== null ? (string) $boat['repair_focus'] : null
                );
            }
            Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [$toGts, $boatId]);
            return ['steps' => 0, 'from' => $from, 'to' => $toGts, 'dist_nm' => 0.0, 'events' => 0];
        }

        // Non si recupera piu' del dovuto in una volta sola: se il mondo e'
        // rimasto indietro per giorni, si avanza a scaglioni.
        $max = World::maxCatchupSeconds();
        if ($toGts - $from > $max) {
            $toGts = $from + $max;
        }
        if ($toGts <= $from) {
            return ['steps' => 0, 'from' => $from, 'to' => $from, 'dist_nm' => 0.0, 'events' => 0];
        }

        // --- si avanza SOLO per sotto-passi interi -----------------------------
        //
        // Il resto aspetta il giro dopo. Sembra un dettaglio ed e' la differenza
        // fra un mondo uguale per tutti e uno che premia chi ricarica la pagina.
        //
        // Con un rapporto di 1:30, dieci secondi reali fanno trecento secondi di
        // gioco: due pagine aperte a pochi secondi di distanza — cosa che succede
        // di continuo — chiedevano un avanzamento piu' corto del sotto-passo. E
        // un avanzamento piu' corto del sotto-passo fa due danni:
        //
        //   1. il consumo del passo parziale finisce sotto la risoluzione delle
        //      colonne (nafta 0,002 t contro due decimali) e si perde nel
        //      salvataggio. Misurato: un battello avanzato a spezzoni di un
        //      minuto di gioco ha percorso centoquindici miglia senza consumare
        //      una goccia di nafta;
        //
        //   2. il caso e' seminato sull'indice del sotto-passo, intdiv($t, $sub):
        //      con passi parziali lo stesso indice torna piu' volte, quindi la
        //      sequenza degli eventi — aerei, avarie, avvistamenti — dipende da
        //      come il tempo e' stato spezzato, non da quanto ne e' passato.
        //
        // Avanzando a quanti interi, lo stesso tempo di gioco produce sempre lo
        // stesso numero di passi con gli stessi indici, comunque uno si colleghi.
        $sub = World::substepSeconds();
        $interi = intdiv($toGts - $from, $sub);
        if ($interi < 1) {
            // Meno di un sotto-passo: non si simula e non si sposta l'orologio del
            // battello, se no il resto andrebbe perduto un pezzetto per volta.
            return ['steps' => 0, 'from' => $from, 'to' => $from, 'dist_nm' => 0.0, 'events' => 0];
        }
        $toGts = $from + $interi * $sub;

        $type   = World::type((string) $boat['type_key']);
        $patrol = Database::first(
            "SELECT * FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [$boatId]
        );
        $waypoints = Database::all(
            'SELECT * FROM boat_waypoints WHERE boat_id = ? AND reached_gts IS NULL ORDER BY seq',
            [$boatId]
        );

        $seed   = World::seed();
        $eventi = [];

        // Avvisi gia' annotati in questa patrol: senza questa memoria, ogni
        // avanzamento ripeterebbe "nafta al 25%" all'infinito. La memoria sta
        // nel giornale, non in una variabile di processo, perche' il tick e la
        // richiesta del giocatore sono processi diversi.
        // Traffico attivo nella finestra di avanzamento: le righe si leggono
        // una volta sola, le posizioni si calcolano a ogni sotto-passo. Nessuna
        // scrittura: una nave e' definita da rotta, velocita' e ora di partenza.
        $unita = Traffic::attivi($from, $toGts);
        $zoneAeree = Traffic::dati()['aria'];

        $gia = [];
        $ultimaBurrascaDb = 0;
        if ($patrol !== null) {
            $ultimaBurrascaDb = (int) (Database::first(
                "SELECT COALESCE(MAX(gts), 0) g FROM patrol_events WHERE patrol_id = ? AND kind = 'burrasca'",
                [(int) $patrol['id']]
            )['g'] ?? 0);
            $gia['__bf_ultima'] = $ultimaBurrascaDb;
            foreach (Database::all(
                'SELECT DISTINCT kind FROM patrol_events WHERE patrol_id = ? AND kind LIKE "%!_%" ESCAPE "!"',
                [(int) $patrol['id']]
            ) as $r) {
                $gia[(string) $r['kind']] = true;
            }
        }

        // Battello, equipaggio, dotazioni: lo stato materiale della missione.
        $sistemi  = Damage::systems($boatId);
        $ciurma   = Crew::aggregate($boatId);
        $scorte   = Outfitting::stores($boatId);
        $mig      = \App\Game\Carriera::effettiMiglioramenti($boatId);
        $effetti  = Damage::effects($sistemi, (float) $boat['hull_stress'], $mig['quota_max'] ?? 1.0);
        $giorniMissione = $patrol !== null ? (int) floor(($from - (int) $patrol['departed_gts']) / 86400) : 0;

        $oreTotali = 0.0;
        $progressiRiparazione = [];
        $ultimoScafo = 0;
        $riparazioniFatte = 0;

        // Stato di lavoro.
        $s = [
            'lat'      => (float) $boat['lat'],
            'lon'      => (float) $boat['lon'],
            'est_lat'  => (float) $boat['est_lat'],
            'est_lon'  => (float) $boat['est_lon'],
            'heading'  => (float) $boat['heading'],
            'speed'    => (float) $boat['speed_kn'],
            'ordered'  => (float) $boat['ordered_speed_kn'],
            'depth'    => (float) $boat['depth_m'],
            'ord_depth'=> (float) $boat['ordered_depth_m'],
            'mode'     => (string) $boat['mode'],
            'silent'   => (bool) $boat['silent'],
            'fuel'     => (float) $boat['fuel_t'],
            'battery'  => (float) $boat['battery_pct'],
            'air'      => (float) $boat['air_pct'],
            'prov'     => (float) $boat['provisions_days'],
            'last_fix' => $boat['last_fix_gts'] !== null ? (int) $boat['last_fix_gts'] : null,
            'sub_since'=> $boat['submerged_since'] !== null ? (int) $boat['submerged_since'] : null,
            'stress'   => (float) $boat['hull_stress'],
            'scafo'    => (float) $boat['hull_integrity'],
            'auto_dive_fine'  => $boat['auto_dive_fine_gts'] !== null ? (int) $boat['auto_dive_fine_gts'] : null,
            'auto_dive_quota' => $boat['auto_dive_quota'] !== null ? (float) $boat['auto_dive_quota'] : null,
            'focus'    => $boat['repair_focus'] !== null ? (string) $boat['repair_focus'] : null,
        ];

        $perduto = false;
        $sistemiCambiati = false;

        // C'e' qualcosa da fare in camera di lancio? Un tubo vuoto, una
        // ricarica in corso. Si guarda una volta per avanzamento: durante la
        // crociera non si lancia, quindi la risposta non cambia strada facendo.

        $siluriDaSistemare = (int) (Database::first(
            "SELECT COUNT(*) n FROM boat_torpedoes
             WHERE boat_id = ? AND stato IN ('lanciato', 'in_carica')",
            [$boatId]
        )['n'] ?? 0) > 0;
        $dist = 0.0; $distSurf = 0.0; $distSub = 0.0; $fuelUsed = 0.0;
        $maxDepth = (float) ($patrol['max_depth_m'] ?? 0);
        $distDaRapporto = 0.0;
        $steps = 0;

        // --- il tempo di prima, senza portarsi dietro niente -------------------
        //
        // Tre avvisi guardano il passo PRECEDENTE: la burrasca (sei sotto-passi
        // di fila sopra forza 8), la bonaccia che torna, la nebbia che cala.
        // Tenere quella memoria in una variabile locale non funzionava: la
        // variabile nasceva vuota a ogni chiamata, e chi ricaricava spesso non
        // vedeva MAI una burrasca, perche' non arrivava mai a sei passi nella
        // stessa chiamata.
        //
        // Ricostruirla dal passato non bastava: il tempo e' funzione del punto,
        // e in mezz'ora il battello si sposta. Chi avanzava in un colpo solo
        // confrontava passi calcolati in punti diversi, chi avanzava a pezzetti
        // li ricalcolava tutti nel punto di adesso, e i due giornali
        // divergevano — misurato: la burrasca c'era in un ritmo e non
        // nell'altro.
        //
        // La regola adesso e' una sola, e non ha memoria: il passato del tempo
        // si guarda SEMPRE dal punto in cui il battello sta adesso. Cosi' due
        // comandanti che si collegano con ritmi diversi leggono lo stesso
        // giornale, riga per riga, perche' ogni riga e' funzione soltanto
        // dell'ora e della posizione.
        for ($t = $from; $t < $toGts; $t += $sub) {
            $dt = min($sub, $toGts - $t);
            $steps++;
            $rng = Rng::for($seed, 'boat', $boatId, 'step', intdiv($t, $sub));

            $meteo = World::weatherCon($t, $s['lat'], $s['lon'], $clock->date($t));
            $meteoPrec = $t > $sub
                ? World::weatherCon($t - $sub, $s['lat'], $s['lon'], $clock->date($t - $sub))
                : null;
            $sun   = Astro::sun($clock->astroTs($t), $s['lat'], $s['lon']);
            $mare  = (int) $meteo['sea_state'];

            // --- quota e modo ------------------------------------------------
            $depthPrima = $s['depth'];
            $dtQuota = (int) round($dt * $effetti['quota_controllo']);   // timoni guasti = quota lenta a venire
            $s['depth'] = Movement::stepDepth($s['depth'], $s['ord_depth'], $type, max(1, $dtQuota));
            $modePrima  = $s['mode'];
            $s['mode']  = Movement::modeForDepth($s['depth']);
            $maxDepth   = max($maxDepth, $s['depth']);

            if ($s['mode'] !== $modePrima) {
                if ($s['mode'] === 'superficie') {
                    $s['sub_since'] = null;
                    $eventi[] = self::ev($t, 'emersione', 'info', $s, Narrator::emersione());
                } elseif ($modePrima === 'superficie') {
                    $s['sub_since'] = $t;
                    $eventi[] = self::ev($t, 'immersione', 'info', $s, Narrator::immersione($s['ord_depth'], false));
                }
            }

            // Passato il pericolo aereo si torna alla quota di prima.
            if ($s['auto_dive_fine'] !== null && $t >= $s['auto_dive_fine']) {
                $s['ord_depth'] = $s['auto_dive_quota'] ?? 0.0;
                $s['auto_dive_fine'] = null;
                $s['auto_dive_quota'] = null;
                $eventi[] = self::ev($t, 'emersione', 'info', $s,
                    'Passato il pericolo: il Primo Ufficiale riporta il battello a galla.');
            }

            // --- rotta --------------------------------------------------------
            if ($waypoints !== []) {
                $wp = $waypoints[0];
                $d = Geo::distanceNm($s['lat'], $s['lon'], (float) $wp['lat'], (float) $wp['lon']);
                if ($d <= 1.5) {
                    Database::run('UPDATE boat_waypoints SET reached_gts = ? WHERE id = ?', [$t, (int) $wp['id']]);
                    $eventi[] = self::ev($t, 'waypoint', 'nota', $s, Narrator::waypoint(
                        (int) $wp['seq'],
                        Grid::toQuadrat($s['lat'], $s['lon']) ?? '—'
                    ));
                    array_shift($waypoints);
                    if ($waypoints === []) {
                        $s['ordered'] = 0.0;
                        $eventi[] = self::ev($t, 'fine_rotta', 'nota', $s, Narrator::fineRotta(
                            Grid::toQuadrat($s['lat'], $s['lon']) ?? '—'
                        ));
                    }
                } else {
                    $s['heading'] = Geo::bearing($s['lat'], $s['lon'], (float) $wp['lat'], (float) $wp['lon']);
                }
            }

            // --- velocita' ----------------------------------------------------
            // Le avarie non sono un'etichetta: tolgono nodi. Un diesel fuori
            // uso dimezza la potenza in superficie, un motore elettrico fuori
            // uso dimezza quella in immersione.
            $maxKn = Movement::maxSpeed($type, $s['mode'], $mare)
                * ($s['mode'] === 'superficie' ? $effetti['vel_superficie'] : $effetti['vel_immersione']);
            $vol   = min($s['ordered'], $maxKn);

            // Limiti duri: senza nafta o senza batteria non si va da nessuna parte.
            if ($s['mode'] === 'superficie' && $s['fuel'] <= 0.01) {
                $vol = 0.0;
            }
            if ($s['mode'] !== 'superficie' && $s['battery'] <= 0.5) {
                $vol = 0.0;
            }
            $s['speed'] = $vol;

            // --- moto ---------------------------------------------------------
            $m = Movement::step(
                $s['lat'], $s['lon'], $s['heading'], $s['speed'], $dt,
                (float) $meteo['wind_dir'], (float) $meteo['wind_kn'], $s['mode']
            );
            $s['lat'] = $m['lat'];
            $s['lon'] = $m['lon'];

            [$s['est_lat'], $s['est_lon']] = Movement::stepEstimated(
                $s['est_lat'], $s['est_lon'], $s['heading'], $s['speed'], $dt, $mare, $rng,
                (float) $m['drift_dir'], (float) $m['drift_nm']
            );

            $dist += $m['dist_nm'];
            $distDaRapporto += $m['dist_nm'];
            if ($s['mode'] === 'superficie') {
                $distSurf += $m['dist_nm'];
            } else {
                $distSub += $m['dist_nm'];
            }

            // --- consumi -------------------------------------------------------
            $ore = $dt / 3600.0;
            if ($s['mode'] === 'superficie' && $effetti['diesel_ko']) {
                // Diesel entrambi fuori uso: si procede coi motori elettrici
                // anche in superficie. Niente nafta consumata, niente ricarica,
                // e la batteria cala come se si fosse immersi.
                $s['battery'] = max(0.0, $s['battery']
                    - Consumption::batteryDrainPerHour($type, $s['speed'], $s['silent'])
                        / max(0.2, $effetti['batteria'] * ($mig['batteria'] ?? 1.0)) * $ore);
                $s['air'] = min(100.0, $s['air'] + Consumption::airGainPerHour() * $effetti['aria'] * $ore);
            } elseif ($s['mode'] === 'superficie') {
                $ricarica = $s['battery'] < 99.5;
                $consumo = Consumption::fuelPerHour($type, $s['speed'], $ricarica) * $ore;
                $s['fuel'] = max(0.0, $s['fuel'] - $consumo);
                $fuelUsed += $consumo;
                if ($ricarica) {
                    $s['battery'] = min(100.0, $s['battery'] + Consumption::batteryChargePerHour($type, $s['speed']) * $ore);
                }
                $s['air'] = min(100.0, $s['air'] + Consumption::airGainPerHour() * $effetti['aria'] * $ore);
            } elseif (($mig['schnorchel'] ?? 0) > 0 && $s['mode'] === 'periscopio' && $s['speed'] <= 6.0 && $mare <= 6) {
                // Respiratore: i diesel girano a quota periscopica. Si ricarica
                // senza emergere — e si resta comunque rilevabili dal radar,
                // perche' la testa d'albero sporge dall'acqua.
                $consumo = Consumption::fuelPerHour($type, $s['speed'], true) * $ore;
                $s['fuel'] = max(0.0, $s['fuel'] - $consumo);
                $fuelUsed += $consumo;
                $s['battery'] = min(100.0, $s['battery'] + Consumption::batteryChargePerHour($type, $s['speed']) * 0.6 * $ore);
                $s['air'] = min(100.0, $s['air'] + Consumption::airGainPerHour() * 0.5 * $ore);
            } else {
                // Batterie danneggiate: stessa corrente, meno riserva.
                $s['battery'] = max(0.0, $s['battery']
                    - Consumption::batteryDrainPerHour($type, $s['speed'], $s['silent'])
                        / max(0.2, $effetti['batteria'] * ($mig['batteria'] ?? 1.0)) * $ore);

                $consumoAria = Consumption::airDrainPerHour(
                    max(1, $ciurma['uomini']), (int) $type['crew_max'], $s['silent']
                ) * $ore;

                // Le cartucce di potassa assorbono l'anidride carbonica: si
                // consumano solo quando l'aria comincia a farsi pesante.
                if ($s['air'] < 65 && ($scorte['potassa'] ?? 0) > 0) {
                    $cartucce = min((float) $scorte['potassa'], 0.6 * $ore);
                    $scorte['potassa'] -= $cartucce;
                    Outfitting::consume($boatId, 'potassa', $cartucce);
                    $consumoAria *= 0.45;
                }
                $s['air'] = max(0.0, $s['air'] - $consumoAria);
            }
            $s['prov'] = max(0.0, $s['prov'] - $ore / 24.0);

            // --- punto nave -----------------------------------------------------
            $intervalloFix = max(1, GameConfig::int('nav.fix_interval_h', 8)) * 3600;
            if (($s['last_fix'] === null || $t - $s['last_fix'] >= $intervalloFix)
                && Movement::canTakeFix($s['mode'], $mare, (float) $meteo['cloud'], $sun['alt'], (bool) $meteo['fog'])) {
                $erroreVecchio = Geo::distanceNm($s['lat'], $s['lon'], $s['est_lat'], $s['est_lon']);
                $prec = Movement::fixAccuracyNm($mare, (float) $meteo['cloud'], $rng);
                [$s['est_lat'], $s['est_lon']] = Geo::destination(
                    $s['lat'], $s['lon'], $rng->range(0, 360), $prec * $rng->range(0.3, 1.0)
                );
                $s['last_fix'] = $t;
                foreach (array_keys($gia) as $k) {
                    if (str_starts_with($k, 'senza_punto_')) {
                        unset($gia[$k]);
                    }
                }
                if ($erroreVecchio > 0.8) {
                    $eventi[] = self::ev($t, 'punto_nave', 'nota', $s, Narrator::punto(
                        $sun['alt'] > 0 ? 'sole' : 'stelle', $erroreVecchio, $prec
                    ));
                }
            }

            // --- rilevamento: chi vede per primo, vive -----------------------------
            self::rilevamento(
                $boatId, $patrol !== null ? (int) $patrol['id'] : null, $s, $type, $meteo, $sun,
                $unita, $zoneAeree, $ciurma, $sistemi, $t, $dt, $rng, $eventi, $gia, $clock, $mig, $scorte,
                $perduto, $sistemiCambiati
            );

            // Le bombe hanno rotto qualcosa: la fotografia dei sistemi presa a
            // inizio avanzamento non vale piu'. Senza questo, un battello
            // bombardato in una richiesta che recuperava mezza giornata
            // continuava a navigare a tutta forza coi motori sfondati fino alla
            // pagina successiva — e chi si collegava spesso, no. Misurato: otto
            // nodi contro quattro e quattro, nello stesso identico istante.
            if ($sistemiCambiati) {
                $sistemi = Damage::systems($boatId);
                $sistemiCambiati = false;
            }
            if ($perduto) {
                $toGts = $t;   // la simulazione finisce qui, non all'ora richiesta
                // Il battello e' finito qui. Fino all'audit del 19/09/2026 la
                // simulazione proseguiva per tutto l'avanzamento richiesto: un
                // battello affondato da un aereo alla prima ora continuava a
                // navigare, a consumare e a scrivere sul giornale per le cinque
                // ore successive, e finiva l'avanzamento a centinaia di miglia
                // dal punto in cui era morto.
                break;
            }

            // --- materiale: avarie, pressione, riparazioni ------------------------
            $oreTotali += $ore;

            // Carico delle macchine: e' il regime, non la velocita' assoluta,
            // che logora. A tutta forza i diesel si rompono molto piu' spesso.
            $riferimento = $s['mode'] === 'superficie'
                ? max(1.0, (float) $type['speed_surf_kn'])
                : max(1.0, (float) $type['speed_sub_kn']);
            $condizioni = [
                'giorni'      => $giorniMissione + (int) floor(($t - ($patrol !== null ? (int) $patrol['departed_gts'] : $t)) / 86400),
                'carico'      => min(1.2, $s['speed'] / $riferimento),
                'mare'        => $mare,
                'specialita'  => $ciurma['specialita'],
                'ricambi'     => $scorte['ricambi'] ?? 0,
            ];

            $rotti = Damage::rollFailures($sistemi, $ore, $condizioni, $rng);
            foreach ($rotti as $g) {
                Damage::applyFailure($boatId, $g['skey'], $g['grave'], $t);
                $eventi[] = self::ev($t, 'avaria', $g['grave'] ? 'allarme' : 'attenzione', $s,
                    Narrator::avaria($g['name'], $g['compartment'], $g['grave'], $g['riparabile']));
            }
            if ($rotti !== []) {
                $sistemi = Damage::systems($boatId);
                $effetti = Damage::effects($sistemi, $s['stress'], $mig['quota_max'] ?? 1.0);
            }

            // Pressione: sotto la quota di prova lo scafo lavora.
            $press = Damage::pressureStep($s['depth'], $type, $s['stress'], $ore, $rng);
            if ($press['evento'] !== null && $t - $ultimoScafo < 7200) {
                $press['evento'] = null;   // lo scafo si lamenta, ma il giornale non lo ripete ogni quarto d'ora
            }
            if ($press['evento'] !== null) {
                $ultimoScafo = $t;
                $eventi[] = self::ev($t, 'scafo', $press['evento'] === 'scafo_grave' ? 'allarme' : 'attenzione', $s,
                    Narrator::scafo($s['depth'], $press['evento'] === 'scafo_grave'));
                if ($press['evento'] === 'scafo_grave') {
                    Damage::applyFailure($boatId, 'scafo', false, $t);
                    $sistemi = Damage::systems($boatId);
                }
            }
            $s['stress'] = $press['stress'];
            $s['scafo'] = max(0.0, $s['scafo'] - $press['permanente']);

            // I compartimenti: la pressione apre le falle dove lo scafo e' gia'
            // ammaccato, e la squadra di falla lavora a fermarle. Prima di
            // questo la tabella dei compartimenti era ferma a 100/0 per sempre.
            $eventiAcqua = [];
            Compartimenti::pressione($boatId, $s['depth'], $type, $ore, $rng, $eventiAcqua);
            Compartimenti::passo($boatId, $ore, [
                'morale' => (float) ($ciurma['morale'] ?? 70.0),
                'fatica' => (float) ($ciurma['fatica'] ?? 0.0),
            ], $s['depth'], $type, $rng, $eventiAcqua);
            foreach ($eventiAcqua as $testo) {
                $eventi[] = self::ev($t, 'avaria', 'attenzione', $s, $testo);
            }

            $effetti = Damage::effects($sistemi, $s['stress'], $mig['quota_max'] ?? 1.0);

            // L'acqua imbarcata pesa: la quota di sicurezza scende e il
            // battello va piu' piano. E se e' troppa, non si torna su.
            $zavorra = Compartimenti::zavorra($boatId);
            if ($zavorra['acqua_pct'] > 0.0) {
                $effetti['quota_max']      = ($effetti['quota_max'] ?? 1.0) * $zavorra['quota_max'];
                $effetti['vel_superficie'] = ($effetti['vel_superficie'] ?? 1.0) * $zavorra['velocita'];
                $effetti['vel_immersione'] = ($effetti['vel_immersione'] ?? 1.0) * $zavorra['velocita'];
            }

            // --- e se si e' scesi troppo ------------------------------------
            //
            // Qui finiva il tre per cento dei battelli perduti, e qui finiva
            // ogni battello portato per gioco a duecentocinquanta metri per
            // vedere che succedeva: non succedeva niente. L'intervallo di
            // collasso era scritto nella scheda del tipo, mostrato al
            // comandante nella pagina del battello, e non lo leggeva nessuno.
            if ($s['depth'] > Damage::quotaCollasso($type, $boatId, $effetti['quota_max'] ?? 1.0, $s['scafo'])) {
                $eventi[] = self::ev($t, 'scafo', 'allarme', $s, Narrator::collasso($s['depth']));
                self::perduto($boatId, $s, $t, 'scafo collassato sotto la quota di sicurezza', $eventi);
                $perduto = true;
                $toGts = $t;
                break;
            }

            // Riparazioni: la squadra lavora di continuo, senza aspettare ordini.
            if ($effetti['guasti'] > 0) {
                $rip = Damage::repairStep($boatId, $sistemi, $ore, $condizioni, $s['focus'], $progressiRiparazione);
                if ($rip['riparato']) {
                    $riparazioniFatte++;
                    $nome = '';
                    foreach ($sistemi as $sy) {
                        if ((string) $sy['skey'] === $rip['skey']) { $nome = (string) $sy['name']; }
                    }
                    Outfitting::consume($boatId, 'ricambi', 1);
                    $scorte['ricambi'] = max(0, ($scorte['ricambi'] ?? 0) - 1);
                    $eventi[] = self::ev($t, 'riparazione', 'nota', $s, Narrator::riparazione($nome));
                    $sistemi = Damage::systems($boatId);
                    $effetti = Damage::effects($sistemi, $s['stress'], $mig['quota_max'] ?? 1.0);
                }
            }

            // --- contatti che si spengono ----------------------------------------
            //
            // Va fatto QUI e non a fine avanzamento: un contatto si perde
            // nell'istante in cui si perde, e scrivere quella riga all'ora in
            // cui il giocatore si e' collegato vorrebbe dire che due comandanti
            // identici leggono due giornali diversi. Misurato: la stessa
            // perdita di contatto annotata alle 05:15 per chi ricaricava e alle
            // 11:00 per chi era tornato una volta sola.
            //
            // E si guarda a ogni passo, senza bandierine alzate all'inizio:
            // i contatti nascono DENTRO l'avanzamento, quindi una bandierina
            // direbbe "non ce n'e' nessuno" proprio nel caso che conta — il
            // contatto preso e perso nella stessa mezza giornata. Provato, e
            // sbagliato: il collegamento unico non annotava la perdita, gli
            // altri tre si'.
            foreach (Contacts::scadi($boatId, $t) as $cosa) {
                $eventi[] = self::ev($t, 'contatto', 'attenzione', $s, Narrator::contattoPerso($cosa));
            }

            // --- i tubi si ricaricano --------------------------------------------
            //
            // Fino all'audit del 19/09/2026 Torpedo::ricarica() non la chiamava
            // NESSUNO. Un VIIB parte con quattordici siluri: cinque nei tubi,
            // otto in stiva, uno nel contenitore di coperta. Lanciati i primi
            // cinque, il battello restava disarmato per tutto il resto della
            // crociera, con nove siluri a bordo e nessun modo di usarli — e
            // l'inventario sulla pagina d'attacco continuava a contarli.
            if ($siluriDaSistemare) {
                $eventiSiluri = [];
                Torpedo::ricarica(
                    $boatId, $t, $mare, (float) ($ciurma['specialita']['silurista'] ?? 1.0),
                    $s['mode'] === 'superficie', $eventiSiluri, $rng
                );
                foreach ($eventiSiluri as $testo) {
                    $eventi[] = self::ev($t, 'siluri', 'nota', $s, $testo);
                }
            }

            // --- equipaggio -------------------------------------------------------
            //
            // La fatica e il morale si aggiornavano UNA VOLTA per avanzamento,
            // sulle condizioni medie del periodo. Sembrava un risparmio
            // ragionevole — sono grandezze lente — ed era invece l'ultimo posto
            // in cui il mondo dipendeva ancora dal ritmo di collegamento:
            //
            //   - il quarto di guardia. Per un intervallo corto si logora solo
            //     chi e' in coperta ADESSO; per uno lungo si spalma su tutti.
            //     Dodici ore in un colpo davano quarantacinque uomini
            //     mediamente stanchi, le stesse dodici ore a sotto-passi
            //     davano un quarto sfinito e tre riposati;
            //
            //   - l'avvicinamento all'equilibrio era lineare nelle ore, e
            //     applicarlo una volta su dodici ore non da' lo stesso
            //     risultato di applicarlo centoquarantaquattro volte su cinque
            //     minuti.
            //
            // E la resa dell'equipaggio entra nelle avarie e nelle riparazioni:
            // due comandanti identici finivano la stessa giornata con avarie
            // diverse. Misurato: l'idrofono riparato per chi si collegava una
            // volta sola, ancora rotto per chi ricaricava di continuo.
            Crew::step($boatId, $ore, $t, [
                'aria'       => $s['air'],
                'viveri'     => $s['prov'],
                'mare'       => $mare,
                'giorni'     => $condizioni['giorni'],
                'avarie'     => $effetti['guasti'],
                'superficie' => $s['mode'] === 'superficie',
                'allarme'    => (bool) $boat['battle_stations'],
            ]);
            $ciurma = Crew::aggregate($boatId);

            // --- soglie e allarmi -----------------------------------------------
            self::soglie($t, $s, $type, $boat, $eventi, $meteo, $meteoPrec, $gia, $clock, $sub);

            // --- rapporto di posizione ogni sei ore ------------------------------
            $oraGioco = $clock->date($t);
            $oraGiorno = (int) $oraGioco->format('G');
            $minuto = (int) $oraGioco->format('i');
            if ($oraGiorno % 6 === 0 && $minuto < ($sub / 60)) {
                $eventi[] = self::ev($t, 'posizione', 'info', $s, Narrator::posizione(
                    Grid::toQuadrat($s['lat'], $s['lon']) ?? '—',
                    $s['lat'], $s['lon'], $distDaRapporto, $meteo, Astro::dayPhase($sun['alt'])
                ));
                $distDaRapporto = 0.0;
            }
        }

        // --- il morale che scende ------------------------------------------------
        if ($oreTotali > 0) {
            // Il morale a terra va annotato: e' un fatto operativo, non un
            // dettaglio. Ma una volta ogni mezza giornata, non a ogni battito.
            $ultimoMorale = $patrol !== null ? (int) (Database::first(
                "SELECT COALESCE(MAX(gts), 0) g FROM patrol_events WHERE patrol_id = ? AND kind = 'morale'",
                [(int) $patrol['id']]
            )['g'] ?? 0) : 0;
            if ($ciurma['uomini'] > 0 && $ciurma['morale'] < 35 && $toGts - $ultimoMorale > 43200) {
                $eventi[] = self::ev($toGts, 'morale', 'attenzione', $s, Narrator::morale($ciurma['morale'], $ciurma['fatica']));
            }
        }

        // Contatti che non si confermano piu': persi.


        // Ordini del BdU e appuntamenti col battello cisterna: si verificano a
        // fine avanzamento, quando la posizione e' quella definitiva.
        $boatAgg = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
        if ($boatAgg !== null) {
            $boatAgg['lat'] = $s['lat'];
            $boatAgg['lon'] = $s['lon'];
            $boatAgg['mode'] = $s['mode'];
            $boatAgg['fuel_t'] = $s['fuel'];
            foreach (\App\Game\Bdu::verifica($boatAgg, $toGts) as $testo) {
                $eventi[] = self::ev($toGts, 'ordine_bdu', 'nota', $s, $testo);
            }
            foreach (\App\Game\Rifornimento::verifica($boatAgg, $toGts) as $testo) {
                $eventi[] = self::ev($toGts, 'rifornimento', 'nota', $s, $testo);
            }
        }

        // --- salvataggio ---------------------------------------------------------
        $errore = Geo::distanceNm($s['lat'], $s['lon'], $s['est_lat'], $s['est_lon']);

        Database::run(
            'UPDATE boats SET lat=?, lon=?, est_lat=?, est_lon=?, est_error_nm=?, heading=?, speed_kn=?,
                    ordered_speed_kn=?, depth_m=?, mode=?, fuel_t=?, battery_pct=?, air_pct=?, co2_pct=?,
                    provisions_days=?, last_fix_gts=?, submerged_since=?, hull_stress=?, hull_integrity=?,
                    ordered_depth_m=?, auto_dive_fine_gts=?, auto_dive_quota=?, last_sim_gts=?, version=version+1
             WHERE id = ?',
            [
                $s['lat'], $s['lon'], $s['est_lat'], $s['est_lon'], $errore, $s['heading'], $s['speed'],
                $s['ordered'], $s['depth'], $s['mode'], $s['fuel'], $s['battery'], $s['air'],
                Consumption::co2FromAir($s['air']), $s['prov'], $s['last_fix'], $s['sub_since'], $s['stress'],
                $s['scafo'], $s['ord_depth'], $s['auto_dive_fine'], $s['auto_dive_quota'], $toGts,
                $boatId,
            ]
        );

        if ($patrol !== null) {
            Database::run(
                'UPDATE patrols SET distance_nm = distance_nm + ?, surfaced_nm = surfaced_nm + ?,
                        submerged_nm = submerged_nm + ?, fuel_used_t = fuel_used_t + ?, max_depth_m = ?
                 WHERE id = ?',
                [$dist, $distSurf, $distSub, $fuelUsed, $maxDepth, (int) $patrol['id']]
            );
            foreach ($eventi as $e) {
                self::save($e, (int) $patrol['id'], $boatId);
            }
        }

        return ['steps' => $steps, 'from' => $from, 'to' => $toGts, 'dist_nm' => round($dist, 2), 'events' => count($eventi)];
    }


    /**
     * Rilevamento reciproco per un sotto-passo.
     *
     * Si scorrono le unita' in mare, si calcola dove sono ADESSO (non si legge:
     * si calcola), e per ciascuna si guarda se noi sentiamo o vediamo loro e se
     * loro vedono noi. Le stesse formule in entrambe le direzioni: cambiano
     * solo le altezze degli occhi, le sagome e gli strumenti.
     *
     * @param list<array<string,mixed>> $unita
     * @param list<array<string,mixed>> $zoneAeree
     */
    private static function rilevamento(
        int $boatId,
        ?int $patrolId,
        array &$s,
        array $type,
        array $meteo,
        array $sun,
        array $unita,
        array $zoneAeree,
        array $ciurma,
        array $sistemi,
        int $t,
        int $dt,
        Rng $rng,
        array &$eventi,
        array &$gia,
        Clock $clock,
        array $mig = [],
        array $scorte = [],
        bool &$perduto = false,
        bool &$sistemiCambiati = false,
    ): void {
        $minuti = $dt / 60.0;
        $mare   = (int) $meteo['sea_state'];
        $data   = $clock->date($t);
        $luna   = Astro::moon($clock->astroTs($t), $s['lat'], $s['lon']);
        $luce   = Astro::lightFrom($sun['alt'], $luna['alt'], $luna['illum'], (float) $meteo['cloud']);

        // Qualita' delle vedette e dell'ascolto: e' il rendimento degli uomini
        // a quelle stazioni, non un numero fisso.
        $qVedette = min(1.4, ($ciurma['specialita']['marinaio'] ?? 3.0) / 6.0 + 0.45);
        $qAscolto = min(1.4, ($ciurma['specialita']['radiotelegrafista'] ?? 1.2) / 1.8 + 0.35);

        $idrofonoGuasto = false;
        foreach ($sistemi as $sy) {
            if ((string) $sy['skey'] === 'idrofono' && (string) $sy['state'] !== 'ok') {
                $idrofonoGuasto = true;
            }
        }

        // Le sospensioni elastiche delle macchine si sentono qui: meno rumore
        // proprio vuol dire sentire piu' lontano ed essere sentiti meno.
        $rumoreProprio = Acoustics::ownNoise($s['speed'], (bool) $s['silent'], (float) $type['speed_sub_kn'])
            * ($mig['rumore_proprio'] ?? 1.0);
        if ($s['mode'] === 'superficie' && $s['speed'] > 1.0) {
            $rumoreProprio += 22.0;        // i diesel coprono tutto: in superficie l'idrofono e' quasi sordo
        }

        $strato = Acoustics::layerDepth($s['lat'], (int) $data->format('n'), $mare);
        $sottoStrato = $strato > 0 && $s['depth'] > $strato + 10;

        $sagoma = Detection::sagomaBattello($s['mode'], $s['depth'], true);

        foreach ($unita as $u) {
            // In mare adesso, non "in mare in un momento qualsiasi di questo
            // avanzamento". L'elenco si legge una volta sola per tutta la
            // chiamata, quindi in una richiesta che recupera dodici ore ci
            // finisce dentro anche chi e' partito dopo e chi e' gia' arrivato.
            // L'ora di arrivo non coincide sempre con la fine esatta della
            // rotta: un piroscafo gia' entrato in porto poteva continuare a
            // restituire una posizione, e allora veniva sentito, avvistato e
            // sorteggiato come se fosse ancora al largo.
            //
            // Chi si collegava spesso non lo incontrava (la sua finestra era di
            // cinque minuti), chi recuperava mezza giornata si'. E siccome ogni
            // unita' considerata consuma sorteggi, da li' in poi TUTTO il
            // sotto-passo cambiava: avarie diverse, aerei diversi. Misurato: lo
            // stesso attacco aereo provocava due avarie a un ritmo e nessuna
            // all'altro.
            if ($t < (int) $u['departed_gts'] || $t > (int) $u['eta_gts']) {
                continue;
            }
            $pos = Traffic::posizione(
                (string) $u['rotta_key'], (float) $u['speed_kn'], (int) $u['departed_gts'], $t,
                (float) ($u['deviazione'] ?? 0)
            );
            if ($pos === null) {
                continue;
            }

            $d = Geo::distanceNm($s['lat'], $s['lon'], $pos['lat'], $pos['lon']);
            if ($d > 75.0) {
                continue;
            }

            $convoglio = (string) $u['tipo_unita'] === 'convoglio';
            $navi = $convoglio ? max(1, (int) $u['mercantili']) : 1;
            // ATTENZIONE: qui "scorte" sono le navi di scorta del convoglio, non i
            // materiali di bordo. Portavano lo stesso nome, e l'attacco aereo piu'
            // sotto riceveva un intero al posto dell'inventario: errore fatale, ma
            // solo quando un aereo arrivava addosso davvero.
            $navidiScorta = $convoglio ? (int) $u['scorte'] : 0;
            $cls = Traffic::classe((string) ($u['class_key'] ?? 'cargo_medio'));
            $rilevamento = Geo::bearing($s['lat'], $s['lon'], $pos['lat'], $pos['lon']);

            // --- noi che sentiamo loro ------------------------------------------
            $sl = Acoustics::sourceLevel(
                (float) $cls['rumore_db'], (float) $u['speed_kn'], max(1.0, (float) $cls['speed_kn']),
                $navi + $navidiScorta, (int) $cls['eliche']
            );
            $idro = Detection::idrofono(
                $sl, $d, $mare, (float) $meteo['precip'], $rumoreProprio, $sottoStrato,
                $qAscolto + ($mig['idrofono_di'] ?? 0.0) / 15.0, $idrofonoGuasto
            );

            // --- noi che vediamo loro --------------------------------------------
            $hAlberi = $convoglio ? 30.0 : max(8.0, (float) $cls['length_m'] * 0.20);
            $vistaOk = false;
            $fumoOk = false;
            if ($sagoma > 0.0) {
                $portata = Detection::portataVisiva(
                    $s['mode'] === 'superficie' ? Detection::H_TORRETTA : Detection::H_PERISCOPIO,
                    $hAlberi, 1.0, (float) $meteo['visibility_nm'], $luce, $mare, $qVedette, (bool) $meteo['fog']
                );
                $vistaOk = $rng->chance(Detection::probabilitaVista($d, $portata, $minuti, $qVedette));

                if (!$vistaOk && (string) $cls['kind'] !== 'scorta') {
                    $portataFumo = Detection::portataFumo($portata, $luce, $navi);
                    $fumoOk = $rng->chance(Detection::probabilitaVista($d, $portataFumo, $minuti, $qVedette) * 0.6);
                }
            }

            if ($idro['udito'] || $vistaOk || $fumoOk) {
                $sensore = $vistaOk ? 'vista' : ($fumoOk ? 'fumo' : 'idrofono');
                // Quello che la vedetta riferisce e' l'APPARENZA, non la
                // verita': una nave civetta si fingeva un piroscafo qualunque,
                // e finche' non sparava era quello.
                $apparente = Traffic::classeApparente((string) ($u['class_key'] ?? 'cargo_medio'));
                $ferita = !$convoglio && (float) ($u['integrita'] ?? 100.0) < 92.0;
                $classeEst = $vistaOk
                    ? ($convoglio
                        ? 'convoglio'
                        : (string) $apparente['name'] . ($ferita ? ' (sbanda)' : ''))
                    : ($fumoOk ? 'fumo all\'orizzonte' : Acoustics::classifica($idro['snr'], $navi + $navidiScorta, (float) $u['speed_kn'], (int) $cls['eliche'], $rng));

                $res = Contacts::upsert($boatId, $patrolId, [
                    'kind'     => $convoglio ? 'convoglio' : 'nave',
                    'id'       => (int) $u['id'],
                    'sensore'  => $sensore,
                    'bearing'  => $rilevamento,
                    'distanza' => $d,
                    'est_lat'  => $s['est_lat'],
                    'est_lon'  => $s['est_lon'],
                    'classe_est' => $classeEst,
                    // La classe si scrive SOLO se l'abbiamo vista: all'idrofono
                    // resta null, e la sagoma sulla pagina d'ascolto non compare.
                    // Un convoglio non ha una classe sola, quindi nemmeno lui.
                    'classe_key_est' => $vistaOk && !$convoglio
                        ? (string) ($apparente['class_key_apparente'] ?? $u['class_key'] ?? '')
                        : null,
                    'navi'     => $convoglio ? $navi : null,
                    'snr'      => $idro['snr'],
                ], $t, $rng);

                // Il giornale segue il contatto, non il sensore: una riga
                // quando il contatto si apre (cioe' quando si capisce che e'
                // qualcosa), e una quando diventa classificabile. Il fruscio al
                // limite dell'udibile resta nella cuffia dell'idrofonista.
                $chiaveEv = 'ctt_' . ($convoglio ? 'c' : 's') . $u['id'];
                $stabilito = $vistaOk || $fumoOk || $idro['snr'] > 10.0;
                $classificabile = $vistaOk || $idro['snr'] > 20.0;

                if ($stabilito && !isset($gia[$chiaveEv])) {
                    $gia[$chiaveEv] = $t;
                    $eventi[] = self::ev($t, 'contatto', 'nota', $s, match (true) {
                        $convoglio && $vistaOk => Narrator::contattoConvoglio($navi, $navidiScorta, $rilevamento, $d),
                        $fumoOk                => Narrator::contattoFumo($rilevamento),
                        $vistaOk               => Narrator::contattoVista((string) $cls['name'], $rilevamento, $d),
                        default                => Narrator::contattoIdrofono($classeEst, $rilevamento, $idro['snr'] > 18.0 ? $d : null),
                    });
                } elseif ($classificabile && isset($gia[$chiaveEv]) && !isset($gia[$chiaveEv . '_f'])) {
                    $gia[$chiaveEv . '_f'] = $t;
                    $eventi[] = self::ev($t, 'contatto', 'nota', $s, $vistaOk
                        ? ($convoglio
                            ? Narrator::contattoConvoglio($navi, $navidiScorta, $rilevamento, $d)
                            : Narrator::contattoVista((string) $cls['name'], $rilevamento, $d))
                        : Narrator::contattoRinforzato($classeEst, $rilevamento, $d));
                }
            }

            // --- loro che vedono noi ----------------------------------------------
            if ($sagoma <= 0.0 || $d > 14.0) {
                continue;
            }
            $vedono = false;
            $chi = $convoglio ? 'una scorta del convoglio' : (string) $cls['name'];

            // Le scorte hanno occhi migliori, il radar e uomini addestrati a
            // cercare proprio noi. I mercantili guardano avanti e basta.
            $altezza = ($convoglio && $navidiScorta > 0) || (string) $cls['kind'] === 'scorta'
                ? Detection::H_PONTE_SCORTA
                : Detection::H_PONTE_MERCANTILE;
            $qualita = ($convoglio && $navidiScorta > 0) || (string) $cls['kind'] === 'scorta' ? 1.15 : 0.7;
            $occhi = ($convoglio ? max(1, $navidiScorta) : 1);

            $portataLoro = Detection::portataVisiva(
                $altezza, $s['mode'] === 'superficie' ? 5.0 : 1.0, $sagoma,
                (float) $meteo['visibility_nm'], $luce, $mare, $qualita, (bool) $meteo['fog']
            );
            $pLoro = Detection::probabilitaVista($d, $portataLoro, $minuti, $qualita);

            // Radar: non gli importa del buio.
            $conRadar = ($convoglio && $navidiScorta > 0) || (int) $cls['radar'] === 1;
            if ($conRadar) {
                $portataRadar = Detection::portataRadar($sagoma, $mare);
                $pLoro = 1.0 - (1.0 - $pLoro) * (1.0 - Detection::probabilitaVista($d, $portataRadar, $minuti, 1.0));
            }

            // Piu' occhi, piu' probabilita': ogni scorta guarda per conto suo.
            $pLoro = 1.0 - (1.0 - $pLoro) ** max(1, $occhi);

            if ($pLoro > 0 && $rng->chance($pLoro)) {
                $chiave = 'visti_' . ($convoglio ? 'c' : 's') . $u['id'];
                if (!isset($gia[$chiave])) {
                    $gia[$chiave] = true;
                    $eventi[] = self::ev($t, 'avvistati', 'allarme', $s, Narrator::avvistati($chi, $d));
                    Sectors::add($s['lat'], $s['lon'], 6.0, $t, 'avvistamento di un U-Boot');
                }
            }
        }

        // --- aerei ------------------------------------------------------------------
        if ($s['mode'] === 'superficie') {
            $heat = Sectors::heat(Sectors::key($s['lat'], $s['lon']), $t);
            $a = Detection::aereo($zoneAeree, $s['lat'], $s['lon'], $luce, $mare, $heat, $rng);
            if ($a['classe'] !== null && $rng->chance($a['probabilita'] * ($dt / 3600.0))) {
                $cls = Traffic::classe((string) $a['classe']);
                $bearing = $rng->range(0, 360);

                Contacts::upsert($boatId, $patrolId, [
                    'kind'     => 'aereo',
                    'id'       => 0,
                    'sensore'  => 'vista',
                    'bearing'  => $bearing,
                    'distanza' => $rng->range(3.0, 9.0),
                    'est_lat'  => $s['est_lat'],
                    'est_lon'  => $s['est_lon'],
                    'classe_est' => (string) $cls['name'],
                    'classe_key_est' => (string) $a['classe'],
                ], $t, $rng);

                // Rivelatore radar: se l'aereo cerca col radar e noi abbiamo
                // l'apparato giusto, il ronzio in cuffia arriva prima del rumore
                // dei motori — e mezzo minuto di anticipo e' la differenza fra
                // immergersi e prendersi quattro bombe sul ponte.
                $avvisati = ($mig['avviso_aereo'] ?? 0.0) > 0
                    && (int) $cls['radar'] === 1
                    && $rng->chance((float) $mig['avviso_aereo']);

                if ($avvisati) {
                    $eventi[] = self::ev($t, 'radar_warner', 'attenzione', $s,
                        'Il rivelatore radar canta: qualcuno ci sta illuminando. Immersione prima ancora di vederlo.');
                }

                $eventi[] = self::ev($t, 'aereo', 'allarme', $s, Narrator::aereo((string) $cls['name'], $bearing, $luce < 0.25));

                // Se non siamo stati avvisati e la quota e' ancora zero, l'aereo
                // arriva addosso prima che il boccaporto sia chiuso.
                if (!$avvisati && $s['depth'] < 8.0) {
                    self::attaccoAereo($boatId, $s, $type, $cls, $ciurma, $scorte, $mig, $t, $rng, $eventi,
                        $perduto, $sistemiCambiati);
                }
                if ($perduto) {
                    return;
                }

                // Il Primo Ufficiale non aspetta l'ordine: con un aereo addosso
                // si va sotto, e si discute dopo. L'immersione pero' e'
                // TEMPORANEA: passata la mezz'ora si torna su, perche' restare
                // sotto a oltranza vuol dire arrivare a batterie vuote — ed e'
                // un modo stupido di perdere un battello.
                if ($s['ord_depth'] < 30.0) {
                    $s['auto_dive_quota'] = $s['ord_depth'];
                    $s['auto_dive_fine'] = $t + max(5, GameConfig::int('iwo.minuti_sotto_aereo', 35)) * 60;
                    $s['ord_depth'] = 40.0;
                    $eventi[] = self::ev($t, 'immersione', 'allarme', $s, Narrator::immersione(40.0, true));
                }
                Sectors::add($s['lat'], $s['lon'], 9.0, $t, 'pattugliamento aereo allertato');
            }
        }
    }


    /**
     * Attacco aereo su un battello sorpreso in superficie.
     *
     * Quattro bombe di profondita' sganciate a volo radente, con la spoletta
     * regolata bassa: il modo piu' rapido di perdere un U-Boot dal 1943 in poi.
     * L'antiaerea puo' disturbare il puntamento, e ogni tanto abbatte
     * l'aereo — ma restare a combattere e' quasi sempre l'errore che uccide.
     *
     * @param list<string> $eventi
     */
    private static function attaccoAereo(
        int $boatId, array &$s, array $type, array $cls, array $ciurma,
        array $scorte, array $mig, int $t, Rng $rng, array &$eventi,
        bool &$perduto = false,
        bool &$sistemiCambiati = false,
    ): void {
        // Antiaerea: serve la mitragliera in ordine, munizioni e uomini svegli.
        $flak = 1.0 + (($mig['flak'] ?? 1.0) - 1.0);
        $munizioni = (float) ($scorte['munizioni_flak'] ?? 0);
        $disturbo = 0.0;

        if ($munizioni > 40) {
            $resa = min(1.4, ($ciurma['specialita']['silurista'] ?? 1.5) / 2.2 + 0.4);
            Outfitting::consume($boatId, 'munizioni_flak', min($munizioni, $rng->range(60, 180)));
            $disturbo = min(0.62, 0.16 * $flak * $resa);

            if ($rng->chance($disturbo * 0.28)) {
                $eventi[] = self::ev($t, 'flak', 'nota', $s,
                    'La mitragliera lo prende in pieno: l\'aereo vira fumando e si allontana. Per stavolta.');
                return;
            }
        }

        // Precisione dell'aereo: alta di giorno e su un battello fermo in superficie.
        $pCentro = max(0.08, 0.5 - $disturbo);
        $bombe = $rng->int(3, 4);
        $danno = 0.0;

        for ($i = 0; $i < $bombe; $i++) {
            if ($rng->chance($pCentro)) {
                $distanza = $rng->range(3.0, 22.0);
                $danno += 95.0 * (1.0 - min(1.0, $distanza / 26.0)) ** 2;
            }
        }

        if ($danno <= 0.5) {
            $eventi[] = self::ev($t, 'aereo', 'attenzione', $s,
                'Bombe in mare a poppa: colonne d\'acqua alte come una casa, ma il battello non e\' toccato.');
            return;
        }

        $s['stress'] = min(100.0, $s['stress'] + $danno * 0.3);
        $eventi[] = self::ev($t, 'aereo', 'allarme', $s, $danno > 25
            ? 'Bombe addosso: il battello sbanda, acqua in centrale, uomini scaraventati a terra.'
            : 'Bombe vicine: lo scafo incassa, qualcosa si e\' rotto in coperta.');

        // Avarie da scoppi ravvicinati.
        foreach (Damage::systems($boatId) as $sy) {
            if ((string) $sy['state'] !== 'ok') {
                continue;
            }
            if ($rng->chance(min(0.5, $danno / 90.0) * 0.2)) {
                $grave = $rng->chance(0.35);
                Damage::applyFailure($boatId, (string) $sy['skey'], $grave, $t);
                $sistemiCambiati = true;
                $eventi[] = self::ev($t, 'avaria', $grave ? 'allarme' : 'attenzione', $s,
                    Narrator::avaria((string) $sy['name'], (string) $sy['compartment'], $grave, (bool) $sy['repairable_sea']));
            }
        }

        if ($s['stress'] >= 100.0) {
            self::perduto($boatId, $s, $t, 'colpito da attacco aereo in superficie', $eventi);
            $perduto = true;
        }
    }

    /**
     * Il battello e' perduto: si chiude il fascicolo e si ferma tutto.
     *
     * Chi chiama DEVE uscire dal ciclo subito dopo. Lo stato di lavoro viene
     * azzerato nell'abbrivio perche' il salvataggio finale riscrive comunque
     * la riga del battello, e un relitto che continua a fare otto nodi sulla
     * carta ammiraglia e' una brutta cosa da vedere.
     */
    private static function perduto(int $boatId, array &$s, int $t, string $causa, array &$eventi): void
    {
        $boatRow = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
        if ($boatRow === null) {
            return;
        }
        $boatRow['depth_m'] = $s['depth'];
        $boatRow['lat'] = $s['lat'];
        $boatRow['lon'] = $s['lon'];
        $fine = \App\Game\Comandante::perdita($boatRow, $causa, $t);
        $eventi[] = self::ev($t, 'perdita', 'allarme', $s, $fine['testo']);

        $s['speed'] = 0.0;
        $s['ordered'] = 0.0;
    }

    /** Soglie di consumo e cambi di tempo: gli avvisi che il comandante vuole trovare nel giornale. */
    private static function soglie(
        int $t,
        array &$s,
        array $type,
        array $boat,
        array &$eventi,
        array $meteo,
        ?array $prec,
        array &$gia,
        Clock $clock,
        int $sub,
    ): void {
        $unaVolta = static function (string $kind, callable $fn) use (&$gia, &$eventi): void {
            if (isset($gia[$kind])) {
                return;
            }
            $gia[$kind] = true;
            $eventi[] = $fn($kind);
        };

        $fuelPct = (float) $type['fuel_t'] > 0 ? $s['fuel'] / (float) $type['fuel_t'] * 100.0 : 0.0;
        foreach ([25, 10] as $soglia) {
            if ($fuelPct <= $soglia) {
                $unaVolta("nafta_{$soglia}", fn (string $k): array => self::ev(
                    $t, $k, $soglia <= 10 ? 'allarme' : 'attenzione', $s, Narrator::nafta($fuelPct, $s['fuel'])
                ));
            }
        }

        if ($s['mode'] !== 'superficie') {
            foreach ([25, 8] as $soglia) {
                if ($s['battery'] <= $soglia) {
                    $unaVolta("batteria_{$soglia}", fn (string $k): array => self::ev(
                        $t, $k, $soglia <= 8 ? 'allarme' : 'attenzione', $s, Narrator::batteria($s['battery'])
                    ));
                }
            }
            if ($s['battery'] <= 0.5) {
                $s['ord_depth'] = 0.0;   // l'LI non aspetta l'ordine: si emerge
                $unaVolta('emersione_forzata', fn (string $k): array => self::ev(
                    $t, $k, 'allarme', $s, Narrator::emersioneForzata()
                ));
            }
            if ($s['air'] <= 25) {
                $unaVolta('aria_bassa', fn (string $k): array => self::ev(
                    $t, $k, 'attenzione', $s, Narrator::aria($s['air'])
                ));
            }
            // Con l'aria alla fine si emerge, esattamente come con le batterie
            // scariche: l'anidride carbonica ammazzava un equipaggio molto
            // prima che finisse la corrente. Fino all'audit del 19/09/2026
            // l'aria poteva scendere a zero e restarci per giorni senza che
            // succedesse niente: era l'unica delle quattro riserve senza
            // conseguenze.
            if ($s['air'] <= 2.0) {
                $s['ord_depth'] = 0.0;
                $unaVolta('aria_finita', fn (string $k): array => self::ev(
                    $t, $k, 'allarme', $s, Narrator::ariaFinita()
                ));
            }
        } else {
            // Tornati in superficie, gli avvisi di batteria e aria tornano validi
            // per la prossima immersione.
            unset($gia['batteria_25'], $gia['batteria_8'], $gia['aria_bassa'],
                $gia['emersione_forzata'], $gia['aria_finita']);
        }

        if ($s['fuel'] <= 0.01) {
            $unaVolta('in_panne', fn (string $k): array => self::ev($t, $k, 'allarme', $s, Narrator::inPanne()));
        }
        if ($s['prov'] <= 0.01) {
            $unaVolta('viveri_finiti', fn (string $k): array => self::ev($t, $k, 'attenzione', $s, Narrator::viveri(0)));
        }

        // Troppi giorni senza punto astronomico: va detto, perche' spiega
        // perche' la posizione sulla carta non e' piu' affidabile.
        if ($s['last_fix'] !== null) {
            $giorni = (int) floor(($t - $s['last_fix']) / 86400);
            if ($giorni >= 2) {
                $unaVolta("senza_punto_{$giorni}", fn (string $k): array => self::ev(
                    $t, $k, 'attenzione', $s, Narrator::niente_punto($giorni)
                ));
            }
        }

        if ($prec !== null) {
            $bfOra = (int) $meteo['beaufort'];
            $bfPrima = (int) $prec['beaufort'];
            // Una burrasca si annota quando e' chiaro che e' una burrasca —
            // mezz'ora buona sopra forza 8 — e non piu' di una volta al giorno.
            if ($bfOra >= 8 && $t - (int) ($gia['__bf_ultima'] ?? 0) > 86400) {
                // Sei sotto-passi di fila sopra forza 8, contati all'indietro
                // dal punto in cui siamo: nessun contatore da portarsi dietro,
                // e quindi nessun modo di perderlo fra una chiamata e l'altra.
                $difila = 1;
                for ($k = 1; $k < 6; $k++) {
                    $tp = $t - $k * $sub;
                    if ($tp <= 0
                        || (int) World::weatherCon($tp, $s['lat'], $s['lon'], $clock->date($tp))['beaufort'] < 8) {
                        break;
                    }
                    $difila++;
                }
                // Esattamente sei: al settimo la burrasca e' gia' annotata.
                $settimo = $t - 6 * $sub;
                $giaPrima = $difila === 6 && $settimo > 0
                    && (int) World::weatherCon($settimo, $s['lat'], $s['lon'], $clock->date($settimo))['beaufort'] >= 8;
                if ($difila === 6 && !$giaPrima) {
                    $gia['__bf_ultima'] = $t;
                    $eventi[] = self::ev($t, 'burrasca', 'attenzione', $s, Narrator::burrasca($bfOra, (int) $meteo['sea_state']));
                }
            }
            if ($bfOra <= 3 && $bfPrima >= 6) {
                $eventi[] = self::ev($t, 'bonaccia', 'info', $s, Narrator::bonaccia());
            }
            if ((bool) $meteo['fog'] && !(bool) $prec['fog']) {
                $eventi[] = self::ev($t, 'nebbia', 'attenzione', $s, Narrator::nebbia((float) $meteo['visibility_nm']));
            }
        }
    }

    /** @return array<string,mixed> */
    private static function ev(int $gts, string $kind, string $severity, array $s, string $text): array
    {
        return [
            'gts' => $gts, 'kind' => $kind, 'severity' => $severity,
            'lat' => $s['lat'], 'lon' => $s['lon'],
            'quadrat' => Grid::toQuadrat($s['lat'], $s['lon']),
            'text' => $text,
        ];
    }

    /** Scrive una riga nel giornale di guerra. */
    public static function save(array $e, int $patrolId, int $boatId, array $meta = []): void
    {
        Database::run(
            'INSERT INTO patrol_events (patrol_id, boat_id, gts, kind, severity, lat, lon, quadrat, text, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $patrolId, $boatId, $e['gts'], $e['kind'], $e['severity'],
                $e['lat'] ?? null, $e['lon'] ?? null, $e['quadrat'] ?? null,
                mb_substr($e['text'], 0, 500),
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
