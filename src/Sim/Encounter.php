<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\Lock;
use App\Core\GameConfig;
use App\Game\Outfitting;

/**
 * L'incontro tattico: quando il mondo rallenta e si combatte.
 *
 * Fuori dall'incontro il traffico e' una funzione matematica: le navi non
 * esistono come righe, si calcolano. Quando pero' si arriva a distanza di
 * lancio, quelle navi devono manovrare, incassare siluri, affondare e cacciare:
 * allora e il convoglio viene MATERIALIZZATO in unita' vere, con posizione,
 * rotta e integrita', e il tempo scende a passi di dieci secondi.
 *
 * Il ciclo e' quello storico: avvicinamento (di notte, in superficie, davanti
 * al convoglio), attacco (pochi minuti), evasione (ore). La parte lunga e'
 * l'ultima, ed e' quella che uccide.
 */
final class Encounter
{
    /** Distanza oltre la quale l'incontro si considera finito, in miglia. */
    public const DISTANZA_FINE_NM = 14.0;

    /** Raggio di distruzione e di danno di una carica di profondita', in metri. */
    public const DC_LETALE_M = 7.0;
    public const DC_DANNO_M  = 18.0;

    public static function passoS(): int
    {
        return max(5, GameConfig::int('combat.passo_s', 10));
    }

    /** @return array<string,mixed>|null */
    public static function corrente(int $boatId): ?array
    {
        return Database::first(
            "SELECT * FROM encounters WHERE boat_id = ? AND stato <> 'concluso' ORDER BY id DESC LIMIT 1",
            [$boatId]
        );
    }

    /**
     * Pota la zavorra degli incontri chiusi.
     *
     * Un incontro materializza la formazione del convoglio: venticinque righe
     * di naviglio, piu' una riga per ogni siluro lanciato. Finito l'incontro
     * non le legge piu' nessuno — tutte le letture sono sull'incontro in corso
     * — e nessuno le toglieva.
     *
     * Non e' solo peso morto. Traffic::pota() si rifiuta di togliere una nave
     * arrivata in porto se un'entita' la nomina ancora, quindi ogni convoglio
     * mai attaccato da qualcuno teneva in vita le sue venticinque navi per
     * sempre: due tabelle che crescono, e la prima che impedisce alla seconda
     * di essere potata. Misurato il 19/09/2026 su un caso costruito — una nave
     * arrivata quaranta giorni prima, trattenuta da un incontro concluso:
     * tolta l'entita', la stessa potatura se la portava via subito.
     *
     * La RIGA dell'incontro resta, ed e' giusto: e' piccola, e tre trofei
     * guardano i suoi totali (navi affondate in un solo incontro, cariche
     * subite, essersi sganciati). Quello che se ne va e' la massa.
     *
     * @return array{entita:int,siluri:int}
     */
    public static function pota(int $gts, int $grazia = 7 * 86400): array
    {
        $limite = $gts - max(86400, $grazia);

        $siluri = Database::run(
            "DELETE tr FROM torpedo_runs tr JOIN encounters e ON e.id = tr.encounter_id
              WHERE e.stato = 'concluso' AND COALESCE(e.ended_gts, e.last_step_gts) < ?
              LIMIT 5000",
            [$limite]
        )->rowCount();

        $entita = Database::run(
            "DELETE ee FROM encounter_entities ee JOIN encounters e ON e.id = ee.encounter_id
              WHERE e.stato = 'concluso' AND COALESCE(e.ended_gts, e.last_step_gts) < ?
              LIMIT 5000",
            [$limite]
        )->rowCount();

        return ['entita' => $entita, 'siluri' => $siluri];
    }

    /** @return list<array<string,mixed>> */
    public static function entita(int $encounterId, bool $soloVive = true): array
    {
        return Database::all(
            'SELECT * FROM encounter_entities WHERE encounter_id = ?'
            . ($soloVive ? " AND stato NOT IN ('affondata','fuggita')" : '')
            . ' ORDER BY FIELD(ruolo,"scorta","bersaglio","mercantile"), id',
            [$encounterId]
        );
    }

    /**
     * Apre un incontro a partire da un contatto in mano.
     *
     * @return array{ok:bool, error?:string, encounter_id?:int}
     */
    public static function apri(array $boat, array $contatto, int $gts): array
    {
        if (self::corrente((int) $boat['id']) !== null) {
            return ['ok' => false, 'error' => 'C\'e\' gia\' un incontro in corso.'];
        }

        $rng = Rng::for(World::seed(), 'incontro', (int) $boat['id'], $gts);
        $convoyId = $contatto['convoy_id'] !== null ? (int) $contatto['convoy_id'] : null;
        $shipId   = $contatto['ship_id'] !== null ? (int) $contatto['ship_id'] : null;

        // Posizione vera del bersaglio adesso.
        if ($convoyId !== null) {
            $cv = Database::first('SELECT * FROM convoys WHERE id = ?', [$convoyId]);
            if ($cv === null) {
                return ['ok' => false, 'error' => 'Convoglio non piu\' in mare.'];
            }
            $pos = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
        } elseif ($shipId !== null) {
            $sh = Database::first('SELECT * FROM ships WHERE id = ?', [$shipId]);
            if ($sh === null || (string) $sh['state'] !== 'in_mare') {
                return ['ok' => false, 'error' => 'La nave non e\' piu\' in mare.'];
            }
            $pos = Traffic::posizione((string) $sh['rotta_key'], (float) $sh['speed_kn'], (int) $sh['departed_gts'], $gts);
        } else {
            return ['ok' => false, 'error' => 'Contatto non ingaggiabile.'];
        }

        if ($pos === null) {
            return ['ok' => false, 'error' => 'Il bersaglio e\' fuori portata.'];
        }

        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], $pos['lat'], $pos['lon']);
        $raggio = (float) GameConfig::get('combat.raggio_nm', 10.0);
        if ($d > $raggio) {
            return ['ok' => false, 'error' => sprintf(
                'Il bersaglio e\' a %.1f miglia: bisogna portarsi entro %.0f miglia per dare battaglia.', $d, $raggio
            )];
        }

        $patrol = Database::first(
            "SELECT id FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [(int) $boat['id']]
        );

        $finestra = time() + max(5, GameConfig::int('combat.finestra_min', 25)) * 60;

        Database::run(
            'INSERT INTO encounters (boat_id, patrol_id, convoy_id, ship_id, stato, started_gts, last_step_gts,
                                     last_step_real, finestra_fine, ratio)
             VALUES (?, ?, ?, ?, "avvicinamento", ?, ?, ?, ?, ?)',
            [
                (int) $boat['id'], $patrol !== null ? (int) $patrol['id'] : null, $convoyId, $shipId,
                $gts, $gts, time(), $finestra, max(1, GameConfig::int('combat.ratio_avvicin', 4)),
            ]
        );
        $encId = Database::lastInsertId();

        // --- materializzazione ---------------------------------------------
        if ($convoyId !== null) {
            Formazione::materializza($encId, $convoyId, $pos, $rng);
        } else {
            $sh = Database::first('SELECT * FROM ships WHERE id = ?', [$shipId]);
            $cls = Traffic::classe((string) $sh['class_key']);
            Database::run(
                'INSERT INTO encounter_entities (encounter_id, ship_id, class_key, name, ruolo, lat, lon, heading,
                                                 speed_kn, grt, integrita, allagamento, incendio)
                 VALUES (?, ?, ?, ?, "bersaglio", ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $encId, $shipId, (string) $sh['class_key'], (string) $sh['name'],
                    $pos['lat'], $pos['lon'], $pos['heading'], (float) $sh['speed_kn'], (int) $sh['grt'],
                    (float) ($sh['integrita'] ?? 100.0), (float) ($sh['allagamento'] ?? 0.0),
                    (float) ($sh['incendio'] ?? 0.0),
                ]
            );
        }

        Database::run('UPDATE boats SET encounter_id = ?, battle_stations = 1 WHERE id = ?', [$encId, (int) $boat['id']]);

        return ['ok' => true, 'encounter_id' => $encId];
    }



    // =====================================================================
    //  Il passo tattico
    // =====================================================================

    /**
     * Fa avanzare l'incontro.
     *
     * L'incontro ha un orologio suo: mentre il mondo corre a 1:30, qui il tempo
     * scende a 1:4 in avvicinamento e a 1:1 quando si spara o si viene
     * bombardati. Il battello resta percio' indietro rispetto all'ora del
     * mondo, e la recupera quando l'incontro si chiude — e' il prezzo di poter
     * combattere manovra per manovra.
     *
     * Un battello alla volta, e con lo stesso lucchetto della crociera: il
     * battito del minuto muove le scorte mentre il comandante ordina una
     * manovra, e i due non devono sovrapporsi — ne uscirebbero cariche di
     * profondita' contate due volte.
     *
     * @return array{passi:int,eventi:list<string>,stato:string,chiuso:bool}
     */
    public static function step(int $encId, ?int $fino = null): array
    {
        $chi = Database::first('SELECT boat_id FROM encounters WHERE id = ?', [$encId]);
        if ($chi === null) {
            return ['passi' => 0, 'eventi' => [], 'stato' => 'concluso', 'chiuso' => true];
        }
        return Lock::con(
            'boat:' . (int) $chi['boat_id'],
            static fn (): array => self::passoBloccato($encId, $fino),
            ['passi' => 0, 'eventi' => [], 'stato' => 'in_corso', 'chiuso' => false],
            3
        );
    }

    /** @return array{passi:int,eventi:list<string>,stato:string,chiuso:bool} */
    private static function passoBloccato(int $encId, ?int $fino = null): array
    {
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        if ($enc === null || (string) $enc['stato'] === 'concluso') {
            return ['passi' => 0, 'eventi' => [], 'stato' => 'concluso', 'chiuso' => true];
        }

        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $enc['boat_id']]);
        if ($boat === null) {
            return ['passi' => 0, 'eventi' => [], 'stato' => 'concluso', 'chiuso' => true];
        }

        $passo = self::passoS();
        $ratio = max(1, (int) $enc['ratio']);
        $oraReale = time();

        // L'orologio dell'incontro deve partire da qualche parte.
        //
        // Se non e' mai partito — un incontro aperto prima che apri() lo
        // segnasse — si mette in moto adesso e si torna indietro senza fare
        // passi. Prima non era cosi': l'ora di partenza restava zero, la
        // scorciatoia qui sotto la sostituiva con l'ora corrente, il conto
        // dava sempre zero passi e si usciva PRIMA di scrivere qualsiasi cosa.
        // Risultato: l'incontro non avanzava mai piu', i siluri restavano in
        // corsa per sempre e le scorte non cercavano nessuno.
        if ((int) $enc['last_step_real'] === 0) {
            Database::run('UPDATE encounters SET last_step_real = ? WHERE id = ?', [$oraReale, $encId]);
            return ['passi' => 0, 'eventi' => [], 'stato' => (string) $enc['stato'], 'chiuso' => false];
        }
        $ultimaReale = (int) $enc['last_step_real'];

        // Quanto tempo di gioco e' passato secondo l'orologio dell'incontro.
        $gameDaFare = $fino !== null
            ? max(0, $fino - (int) $enc['last_step_gts'])
            : (int) round(max(0, $oraReale - $ultimaReale) * $ratio);

        $maxPassi = max(10, GameConfig::int('combat.max_passi', 900));
        $vorrebbe = (int) floor($gameDaFare / $passo);
        $passi = min($maxPassi, $vorrebbe);
        if ($passi <= 0) {
            return ['passi' => 0, 'eventi' => [], 'stato' => (string) $enc['stato'], 'chiuso' => false];
        }

        // Il resto non si butta.
        //
        // Il passo tattico dura dieci secondi: se fra una chiamata e l'altra ne
        // sono passati quindici, se ne consumano dieci e cinque restano in
        // cassa per la volta dopo. Segnare comunque "l'ultimo passo e' adesso"
        // li buttava via, e con le pagine che si aggiornano da sole l'attacco
        // finiva per scorrere a due terzi della velocita' giusta.
        //
        // L'eccezione e' quando si arriva al tetto dei passi: li' il tempo in
        // eccesso va perso apposta, perche' nessuno vuole che il battello
        // rimasto solo per mezz'ora si faccia mezz'ora di caccia tutta insieme
        // — a quello ci pensa il Primo Ufficiale, disimpegnando.
        $realeConsumato = $vorrebbe > $maxPassi
            ? max(0, $oraReale - $ultimaReale)
            : (int) floor($passi * $passo / max(1, $ratio));
        $nuovaOraReale = $fino !== null ? $oraReale : min($oraReale, $ultimaReale + $realeConsumato);

        $type   = World::type((string) $boat['type_key']);
        $ciurma = Crew::aggregate((int) $boat['id']);
        $sistemi = Damage::systems((int) $boat['id']);
        $migBoat = \App\Game\Carriera::effettiMiglioramenti((int) $boat['id']);
        $effetti = Damage::effects($sistemi, (float) $boat['hull_stress'], $migBoat['quota_max'] ?? 1.0);

        $t = (int) $enc['last_step_gts'];
        $eventi = [];
        $entita = self::entita($encId);
        $siluri = Database::all("SELECT * FROM torpedo_runs WHERE encounter_id = ? AND esito = 'in_corsa'", [$encId]);

        $meteo = World::weather((float) $boat['lat'], (float) $boat['lon'], $t);
        $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], $t, (float) $meteo['cloud']);
        $mare  = (int) $meteo['sea_state'];
        $data  = World::clock()->date($t);
        $strato = Acoustics::layerDepth((float) $boat['lat'], (int) $data->format('n'), $mare);

        $b = [
            'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
            'heading' => (float) $boat['heading'],
            'ord_heading' => $boat['ordered_heading'] !== null ? (float) $boat['ordered_heading'] : (float) $boat['heading'],
            'speed' => (float) $boat['speed_kn'], 'ordered' => (float) $boat['ordered_speed_kn'],
            'depth' => (float) $boat['depth_m'], 'ord_depth' => (float) $boat['ordered_depth_m'],
            'mode' => (string) $boat['mode'], 'silent' => (bool) $boat['silent'],
            'periscopio' => (bool) ($boat['periscopio_alzato'] ?? 0),
            'periscopio_gts' => $boat['periscopio_gts'] !== null ? (int) $boat['periscopio_gts'] : null,
            'battery' => (float) $boat['battery_pct'], 'fuel' => (float) $boat['fuel_t'],
            'air' => (float) $boat['air_pct'], 'stress' => (float) $boat['hull_stress'],
        ];

        /** @var array<string,true> righe gia' scritte nel giornale da chi le ha generate */
        $giaNelGiornale = [];

        $allarme = (bool) $enc['allarme'];
        $cariche = (int) $enc['cariche_subite'];
        $affondate = (int) $enc['affondate'];
        $grtAffondato = (int) $enc['grt_affondato'];
        $perduto = false;

        for ($i = 0; $i < $passi; $i++) {
            $t += $passo;
            $rng = Rng::for(World::seed(), 'tattico', $encId, intdiv($t, $passo));
            $ore = $passo / 3600.0;

            // --- il nostro battello -------------------------------------------
            $b['depth'] = Movement::stepDepth($b['depth'], $b['ord_depth'], $type, (int) round($passo * $effetti['quota_controllo']));
            $b['mode'] = Movement::modeForDepth($b['depth']);
            $maxKn = Movement::maxSpeed($type, $b['mode'], $mare)
                * ($b['mode'] === 'superficie' ? $effetti['vel_superficie'] : $effetti['vel_immersione']);
            $b['speed'] = min($b['ordered'], $maxKn);

            // Accostata: il battello non gira su se stesso, ci mette il suo tempo.
            $delta = Geo::bearingDelta($b['heading'], $b['ord_heading']);
            $rateo = ($b['mode'] === 'superficie' ? 3.2 : 1.9) * ($b['speed'] / max(2.0, $maxKn) + 0.25);
            $b['heading'] = Geo::normBearing($b['heading'] + max(-$rateo * $passo, min($rateo * $passo, $delta)));

            if ($b['speed'] > 0.01) {
                [$b['lat'], $b['lon']] = Geo::destination($b['lat'], $b['lon'], $b['heading'], $b['speed'] * $ore);
            }

            if ($b['mode'] === 'superficie') {
                $b['fuel'] = max(0.0, $b['fuel'] - Consumption::fuelPerHour($type, $b['speed']) * $ore);
                $b['air'] = min(100.0, $b['air'] + Consumption::airGainPerHour() * $ore);
            } else {
                // Le batterie maggiorate valgono anche qui — anzi, soprattutto
                // qui. Fino all'audit del 19/09/2026 l'apparato piu' caro del
                // cantiere (centodieci punti di assegnazione) funzionava
                // soltanto durante la crociera tranquilla e si spegneva
                // nell'unico momento in cui la riserva di corrente decide se si
                // torna a casa: sotto le cariche, in immersione, a fare i nodi
                // che servono per non farsi prendere.
                $b['battery'] = max(0.0, $b['battery']
                    - Consumption::batteryDrainPerHour($type, $b['speed'], $b['silent'])
                        / max(0.2, $effetti['batteria'] * ($migBoat['batteria'] ?? 1.0)) * $ore);
                $b['air'] = max(0.0, $b['air'] - Consumption::airDrainPerHour(max(1, $ciurma['uomini']), (int) $type['crew_max'], $b['silent']) * $ore);
            }

            $press = Damage::pressureStep($b['depth'], $type, $b['stress'], $ore, $rng);
            $b['stress'] = $press['stress'];
            if ($press['permanente'] > 0.0) {
                Database::run(
                    'UPDATE boats SET hull_integrity = GREATEST(0, hull_integrity - ?) WHERE id = ?',
                    [$press['permanente'], (int) $boat['id']]
                );
            }

            // Scendere sotto il collasso per sfuggire alle cariche e' una
            // scelta, non una scorciatoia: il fondo dell'Atlantico e' pieno di
            // battelli che l'hanno fatta.
            $integrita = (float) (Database::first('SELECT hull_integrity FROM boats WHERE id = ?', [(int) $boat['id']])['hull_integrity'] ?? 100.0);
            if ($b['depth'] > Damage::quotaCollasso($type, (int) $boat['id'], $effetti['quota_max'] ?? 1.0, $integrita)) {
                $eventi[] = Narrator::collasso($b['depth']);
                $boatRow = array_merge($boat, ['depth_m' => $b['depth'], 'lat' => $b['lat'], 'lon' => $b['lon']]);
                $fine = \App\Game\Comandante::perdita($boatRow, 'scafo collassato sotto la quota di sicurezza', $t);
                $eventi[] = $fine['testo'];
                $b['speed'] = 0.0;
                $perduto = true;
                break;
            }

            // --- camera di lancio ------------------------------------------------
            //
            // Ricaricare un tubo sono venti minuti buoni, e durante l'attacco
            // sono i venti minuti piu' lunghi che ci siano: quattro uomini che
            // manovrano una tonnellata e mezza d'acciaio mentre le eliche si
            // avvicinano. Senza questo, il secondo attacco allo stesso convoglio
            // non esisteva.
            $eventiSiluri = [];
            Torpedo::ricarica(
                (int) $boat['id'], $t, $mare,
                min(1.4, ($ciurma['specialita']['silurista'] ?? 1.5) / 2.0 + 0.4),
                $b['mode'] === 'superficie', $eventiSiluri, $rng
            );
            foreach ($eventiSiluri as $testo) {
                $eventi[] = $testo;
            }

            // --- il naviglio ----------------------------------------------------
            foreach ($entita as &$e) {
                self::muoviEntita($e, $passo, $t, $rng, $allarme);
            }
            unset($e);

            // --- i siluri in corsa -----------------------------------------------
            foreach ($siluri as &$sil) {
                if ((string) $sil['esito'] !== 'in_corsa') {
                    continue;
                }
                $esito = self::muoviSiluro($sil, $entita, $passo, $t, $rng, $b);
                if ($esito !== null) {
                    $eventi[] = $esito['testo'];
                    if (($esito['colpito'] ?? false) && !$allarme) {
                        $allarme = true;
                    }
                    if (isset($esito['affondata'])) {
                        $affondate++;
                        $grtAffondato += (int) $esito['grt'];
                    }
                }
            }
            unset($sil);

            // --- navi che affondano ------------------------------------------------
            foreach ($entita as &$e) {
                if ((string) $e['stato'] === 'affonda' && $e['affonda_gts'] !== null && $t >= (int) $e['affonda_gts']) {
                    $e['stato'] = 'affondata';
                    Database::run("UPDATE encounter_entities SET stato = 'affondata' WHERE id = ?", [(int) $e['id']]);
                    $testoAffondamento = self::registraAffondamento($enc, $boat, $e, $t, self::armaCheHaAffondato($e));
                    if ($testoAffondamento === null) {
                        // Ci era arrivato prima un altro battello. La si vede
                        // andare giu' lo stesso — ed e' giusto che il giornale
                        // lo dica — ma nel registro ci va una volta sola.
                        $eventi[] = sprintf(
                            '%s va a fondo, ma l\'aveva gia\' colpita un altro battello: '
                            . 'il BdU accredita a chi ce l\'ha mandata per primo.',
                            (string) $e['name']
                        );
                    } else {
                        $affondate++;
                        $grtAffondato += (int) $e['grt'];
                        // Va nella cronaca in diretta, ma NON di nuovo nel giornale:
                        // la sua riga l'ha gia' scritta registraAffondamento, con
                        // l'ora esatta e il genere giusto.
                        $eventi[] = $testoAffondamento;
                        $giaNelGiornale[$testoAffondamento] = true;
                    }
                }
            }
            unset($e);

            // --- le scorte -----------------------------------------------------------
            if ($allarme || $rng->chance(0.02)) {
                $esitiScorte = Scorte::ai($entita, $b, $type, $encId, $t, $passo, $mare, $strato,
                    (float) $cielo['luce'], $effetti, $rng, $allarme, $migBoat);
                foreach ($esitiScorte['eventi'] as $ev) {
                    $eventi[] = $ev;
                }
                if ($esitiScorte['cariche'] > 0) {
                    $cariche += $esitiScorte['cariche'];
                }
                if ($esitiScorte['danno'] > 0.0) {
                    Scorte::applicaDanno((int) $boat['id'], $b, $type, $esitiScorte['danno'], $t, $rng, $eventi);
                    if ($b['stress'] >= 100.0) {
                        // Lo scafo ha ceduto sotto le cariche: applicaDanno ha
                        // gia' chiuso il fascicolo. Quello che mancava era
                        // fermarsi — il relitto proseguiva l'incontro fino
                        // all'ultimo passo, manovrando e sparando.
                        $b['speed'] = 0.0;
                        $perduto = true;
                        break;
                    }
                }
                $allarme = $allarme || $esitiScorte['scoperti'];
            }
        }

        // --- salvataggio ----------------------------------------------------------
        foreach ($entita as $e) {
            Database::run(
                'UPDATE encounter_entities SET lat = ?, lon = ?, heading = ?, speed_kn = ?, integrita = ?,
                        allagamento = ?, incendio = ?, stato = ?, affonda_gts = ?, contatto = ?, manovra = ?,
                        dc_residue = ?, ultimo_attacco_gts = ?
                 WHERE id = ?',
                [
                    $e['lat'], $e['lon'], $e['heading'], $e['speed_kn'], $e['integrita'], $e['allagamento'],
                    $e['incendio'], $e['stato'], $e['affonda_gts'], $e['contatto'], $e['manovra'],
                    $e['dc_residue'], $e['ultimo_attacco_gts'], (int) $e['id'],
                ]
            );
        }
        foreach ($siluri as $sil) {
            Database::run(
                'UPDATE torpedo_runs SET lat = ?, lon = ?, percorso_m = ?, esito = ?, esito_gts = ?, nota = ?,
                        entity_id = ? WHERE id = ?',
                [
                    $sil['lat'], $sil['lon'], $sil['percorso_m'], $sil['esito'], $sil['esito_gts'], $sil['nota'],
                    // Su chi e' andato a finire. La colonna c'era e non la
                    // scriveva nessuno, cosi' il conto dei siluri spesi per
                    // affondare una nave — che si fa proprio contando le corse
                    // finite addosso a quella nave — trovava sempre zero, e nel
                    // registro degli affondamenti finiva zero siluri per
                    // qualunque nave, comprese quelle affondate a siluri.
                    isset($sil['entity_id']) ? (int) $sil['entity_id'] : null,
                    (int) $sil['id'],
                ],
            );
        }

        // Il periscopio non resta fuori per distrazione. Se il comandante se ne
        // dimentica lo abbassa il I.WO dopo cinque minuti, e se si cambia quota
        // scende comunque: fuori dall'acqua a quaranta metri non ci arriva.
        $periscopio = $b['periscopio'];
        if ($periscopio && ($b['mode'] !== 'periscopio'
            || ($b['periscopio_gts'] !== null && $t - $b['periscopio_gts'] > 300))) {
            $periscopio = false;
            $b['periscopio'] = false;
            $eventi[] = $b['mode'] !== 'periscopio'
                ? 'Periscopio rientrato: si cambia quota.'
                : 'Il I.WO fa abbassare il periscopio: e\' fuori da cinque minuti.';
        }

        Database::run(
            'UPDATE boats SET lat = ?, lon = ?, heading = ?, speed_kn = ?, depth_m = ?, mode = ?,
                    battery_pct = ?, fuel_t = ?, air_pct = ?, co2_pct = ?, hull_stress = ?, last_sim_gts = ?,
                    periscopio_alzato = ?, periscopio_gts = ?,
                    est_lat = est_lat + (? - lat), est_lon = est_lon + (? - lon), version = version + 1
             WHERE id = ?',
            [
                $b['lat'], $b['lon'], $b['heading'], $b['speed'], $b['depth'], $b['mode'],
                $b['battery'], $b['fuel'], $b['air'], Consumption::co2FromAir($b['air']), $b['stress'], $t,
                $periscopio ? 1 : 0, $periscopio ? $b['periscopio_gts'] : null,
                $b['lat'], $b['lon'], (int) $boat['id'],
            ]
        );

        // Se il battello e' perduto non c'e' nessun incontro da portare avanti:
        // si chiude qui, o resterebbe aperto per sempre con dentro un relitto.
        if ($perduto) {
            self::chiudi($encId, 'Battello perduto in combattimento.', $t, $affondate, $grtAffondato);
            if ($enc['patrol_id'] !== null) {
                foreach ($eventi as $testo) {
                    if (isset($giaNelGiornale[$testo])) {
                        continue;
                    }
                    BoatSim::save([
                        'gts' => $t, 'kind' => 'combattimento', 'severity' => 'allarme',
                        'lat' => $b['lat'], 'lon' => $b['lon'],
                        'quadrat' => Grid::toQuadrat($b['lat'], $b['lon']),
                        'text' => $testo,
                    ], (int) $enc['patrol_id'], (int) $boat['id']);
                }
            }

            return ['passi' => $passi, 'eventi' => $eventi, 'stato' => 'chiuso', 'chiuso' => true];
        }

        // Fine dell'incontro: tutti affondati, tutti lontani, o finestra scaduta.
        $vive = array_values(array_filter($entita, static fn (array $e): bool => (string) $e['stato'] !== 'affondata'));
        $piuVicina = null;
        foreach ($vive as $e) {
            $d = Geo::distanceNm($b['lat'], $b['lon'], (float) $e['lat'], (float) $e['lon']);
            if ($piuVicina === null || $d < $piuVicina) {
                $piuVicina = $d;
            }
        }

        $stato = (string) $enc['stato'];
        if ($allarme && $stato !== 'evasione') {
            $stato = 'evasione';
        } elseif (!$allarme && $piuVicina !== null && $piuVicina < 4.0) {
            $stato = 'attacco';
        }

        // Ci hanno perso: nessuna scorta ha piu' un contatto e nessun siluro
        // e' in acqua. Si aspetta un po', poi si smette di trattenere il fiato.
        $contattoMax = 0.0;
        foreach ($vive as $e) {
            $contattoMax = max($contattoMax, (float) $e['contatto']);
        }
        $siluriInAcqua = count(array_filter($siluri, static fn (array $x): bool => (string) $x['esito'] === 'in_corsa'));
        $sganciati = $allarme && $contattoMax < 0.08 && $siluriInAcqua === 0
            && $piuVicina !== null && $piuVicina > 4.0;

        $chiuso = false;
        if ($vive === [] || $piuVicina === null || $piuVicina > self::DISTANZA_FINE_NM || $sganciati) {
            // Chi stava affondando, affonda lo stesso.
            //
            // Una nave con l'integrita' a zero e' condannata: ha solo un'ora
            // da aspettare. Se il contatto si rompeva prima, pero', l'incontro
            // si chiudeva e quella nave veniva semplicemente buttata via —
            // tornava in mare intera e il comandante non aveva affondato
            // niente. Non e' quello che succedeva: una nave spezzata in due
            // andava giu' anche se il sommergibile era gia' lontano, e il BdU
            // l'accreditava. Chi e' solo danneggiata invece se la cava: quella
            // non si accredita, e non si e' mai accreditata senza vederla.
            foreach ($entita as &$condannata) {
                if ((string) $condannata['stato'] !== 'affonda') {
                    continue;
                }
                $quando = $condannata['affonda_gts'] !== null ? max($t, (int) $condannata['affonda_gts']) : $t;
                $condannata['stato'] = 'affondata';
                Database::run("UPDATE encounter_entities SET stato = 'affondata' WHERE id = ?", [(int) $condannata['id']]);
                $accreditata = self::registraAffondamento(
                    $enc, $boat, $condannata, $quando, self::armaCheHaAffondato($condannata)
                );
                if ($accreditata === null) {
                    $eventi[] = sprintf(
                        '%s e\' andata giu\', ma il merito e\' di un altro battello: '
                        . 'ci era arrivato prima.',
                        (string) $condannata['name']
                    );
                    continue;
                }
                $affondate++;
                $grtAffondato += (int) $condannata['grt'];
                $eventi[] = sprintf(
                    '%s non ce l\'ha fatta: e\' andata giu\' dopo che avevamo rotto il contatto.',
                    (string) $condannata['name']
                );
            }
            unset($condannata);

            // Il danno di chi resta a galla esce dall'incontro e resta nel
            // mondo: rallenta, perde il convoglio, e magari affonda domani.
            $strascico = Danni::registra($enc, $boat, $entita, $t);
            if ($strascico['ritardatarie'] > 0) {
                $eventi[] = $strascico['ritardatarie'] === 1
                    ? 'Una delle colpite non tiene il passo: il convoglio la sta lasciando indietro.'
                    : sprintf('%d delle colpite non tengono il passo: il convoglio le sta lasciando indietro.',
                        $strascico['ritardatarie']);
            }

            $esito = $affondate > 0
                ? sprintf('%d affondate per %s GRT.', $affondate, number_format($grtAffondato, 0, ',', '.'))
                : ($strascico['danneggiate'] > 0
                    ? sprintf('Nessun affondamento: %d danneggiate, e il contatto si e\' rotto.', $strascico['danneggiate'])
                    : 'Nessun risultato: il contatto si e\' allontanato.');
            self::chiudi($encId, $esito, $t, $affondate, $grtAffondato);
            $eventi[] = match (true) {
                $vive === [] => 'Il mare e\' vuoto: non resta nulla a galla.',
                $sganciati   => 'Le eliche si allontanano. Ci hanno perso: si puo\' respirare.',
                default      => 'Contatto rotto: il convoglio si e\' allontanato.',
            };
            $chiuso = true;
        } else {
            Database::run(
                'UPDATE encounters SET last_step_gts = ?, last_step_real = ?, stato = ?, allarme = ?,
                        cariche_subite = ?, affondate = ?, grt_affondato = ?, ratio = ?
                 WHERE id = ?',
                [
                    $t, $nuovaOraReale, $stato, $allarme ? 1 : 0, $cariche, $affondate, $grtAffondato,
                    $stato === 'avvicinamento'
                        ? max(1, GameConfig::int('combat.ratio_avvicin', 4))
                        : max(1, GameConfig::int('combat.ratio_attacco', 1)),
                    $encId,
                ]
            );
        }

        // Le righe di cronaca finiscono nel giornale di guerra.
        if ($eventi !== [] && $enc['patrol_id'] !== null) {
            foreach ($eventi as $testo) {
                if (isset($giaNelGiornale[$testo])) {
                    continue;
                }
                BoatSim::save([
                    'gts' => $t, 'kind' => 'combattimento', 'severity' => 'nota',
                    'lat' => $b['lat'], 'lon' => $b['lon'],
                    'quadrat' => Grid::toQuadrat($b['lat'], $b['lon']),
                    'text' => $testo,
                ], (int) $enc['patrol_id'], (int) $boat['id']);
            }
        }

        return ['passi' => $passi, 'eventi' => $eventi, 'stato' => $stato, 'chiuso' => $chiuso];
    }


    // --- Movimento del naviglio -------------------------------------------------

    /** Muove una nave del convoglio (o la nave isolata) per un passo. */
    private static function muoviEntita(array &$e, int $passo, int $t, Rng $rng, bool $allarme): void
    {
        if (in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
            $e['speed_kn'] = 0.0;
            return;
        }

        // Le scorte hanno una logica propria (aiScorte): qui si muovono e basta.
        if ((string) $e['ruolo'] !== 'scorta') {
            // Zigzag: lo schema dell'Ammiragliato faceva perdere tempo ma
            // rovinava la soluzione di tiro. Sotto attacco diventa piu' largo.
            // Zigzag: si applica la DERIVATA della sinusoide, altrimenti la
            // rotta non oscilla, deriva — e dopo mezz'ora il convoglio sta
            // navigando da un'altra parte.
            $periodo = $allarme ? 420 : 900;
            $ampiezza = $allarme ? 35.0 : 18.0;
            $omega = 2 * M_PI / $periodo;
            $e['heading'] = Geo::normBearing(
                (float) $e['heading'] + $ampiezza * $omega * cos($omega * $t) * $passo
            );

            // Una nave che affonda rallenta fino a fermarsi.
            if ((string) $e['stato'] === 'affonda') {
                $e['speed_kn'] = max(0.0, (float) $e['speed_kn'] - 0.8 * ($passo / 60.0));
            } elseif ((float) $e['allagamento'] > 30) {
                $e['speed_kn'] = max(2.0, (float) $e['speed_kn'] * 0.998);
            }
        }

        $dist = (float) $e['speed_kn'] * ($passo / 3600.0);
        if ($dist > 0.00001) {
            [$lat, $lon] = Geo::destination((float) $e['lat'], (float) $e['lon'], (float) $e['heading'], $dist);
            $e['lat'] = $lat;
            $e['lon'] = $lon;
        }

        // Allagamento progressivo: oltre una certa soglia non si tiene piu'.
        if ((float) $e['allagamento'] > 45 && (string) $e['stato'] === 'danneggiata') {
            $e['allagamento'] = min(100.0, (float) $e['allagamento'] + 0.25 * ($passo / 60.0));
            if ((float) $e['allagamento'] >= 85) {
                $e['stato'] = 'affonda';
                $e['affonda_gts'] = $t + Torpedo::tempoAffondamento((float) $e['allagamento'], (int) $e['grt'], $rng);
            }
        }
    }

    /**
     * Muove un siluro e verifica se incontra qualcosa.
     *
     * @return array{testo:string,colpito?:bool,affondata?:bool,grt?:int}|null
     */
    private static function muoviSiluro(array &$sil, array &$entita, int $passo, int $t, Rng $rng, array $b): ?array
    {
        $tipo = Torpedo::tipo((string) $sil['tkey']);
        $avanza = (float) $sil['speed_kn'] * ($passo / 3600.0);      // miglia
        $avanzaM = $avanza * 1852.0;

        // Il T5 insegue il rumore: cerca la sorgente piu' forte davanti a se'.
        if ((string) $tipo['guida'] === 'acustico' && (int) $sil['percorso_m'] > 400) {
            $migliore = null;
            $migliorePunteggio = 0.0;
            foreach ($entita as $e) {
                if (in_array((string) $e['stato'], ['affondata', 'fuggita'], true) || (float) $e['speed_kn'] < 6.0) {
                    continue;
                }
                $d = Geo::distanceNm((float) $sil['lat'], (float) $sil['lon'], (float) $e['lat'], (float) $e['lon']);
                $ril = Geo::bearing((float) $sil['lat'], (float) $sil['lon'], (float) $e['lat'], (float) $e['lon']);
                if ($d > 0.9 || abs(Geo::bearingDelta((float) $sil['heading'], $ril)) > 50) {
                    continue;
                }
                // Fra dieci e diciotto nodi la testa acustica sente meglio.
                $v = (float) $e['speed_kn'];
                $punteggio = ($v >= 10 && $v <= 18 ? 1.0 : 0.45) / max(0.05, $d);
                if ($punteggio > $migliorePunteggio) {
                    $migliorePunteggio = $punteggio;
                    $migliore = ['ril' => $ril];
                }
            }
            if ($migliore !== null) {
                $delta = Geo::bearingDelta((float) $sil['heading'], $migliore['ril']);
                $sil['heading'] = Geo::normBearing((float) $sil['heading'] + max(-4.0, min(4.0, $delta)));
            }
        }

        [$lat, $lon] = Geo::destination((float) $sil['lat'], (float) $sil['lon'], (float) $sil['heading'], $avanza);
        $latPrima = (float) $sil['lat'];
        $lonPrima = (float) $sil['lon'];
        $sil['lat'] = $lat;
        $sil['lon'] = $lon;
        $sil['percorso_m'] = (int) $sil['percorso_m'] + (int) round($avanzaM);

        // Scoppio prematuro: la spoletta magnetica si eccita sul nulla.
        if ((string) ($sil['nota'] ?? '') === 'scoppio prematuro'
            && (int) $sil['percorso_m'] > (int) ((int) $sil['corsa_max_m'] * 0.18)) {
            $sil['esito'] = 'prematuro';
            $sil['esito_gts'] = $t;
            return ['testo' => sprintf('Tubo %d: scoppio prematuro a %d metri. La colonna d\'acqua avverte il convoglio.',
                (int) $sil['tubo'], (int) $sil['percorso_m'])];
        }

        // Incontro con una nave: si guarda il segmento percorso, non solo il punto.
        foreach ($entita as &$e) {
            if (in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
                continue;
            }
            // Si misura la distanza del bersaglio dalla TRAIETTORIA percorsa
            // in questo passo, non dai suoi estremi: un siluro non teletrasporta.
            $raggioNm = ((float) Traffic::classe((string) $e['class_key'])['length_m'] * 0.45) / 1852.0;
            $dMin = Geo::distanzaDaSegmento($latPrima, $lonPrima, $lat, $lon, (float) $e['lat'], (float) $e['lon']);
            if ($dMin > $raggioNm) {
                continue;
            }

            $sil['esito_gts'] = $t;

            if ((string) ($sil['nota'] ?? '') === 'spoletta difettosa') {
                $sil['esito'] = 'cilecca';
                return ['testo' => sprintf('Tubo %d: il siluro colpisce %s e non esplode. Un tonfo, e nient\'altro.',
                    (int) $sil['tubo'], (string) $e['name'])];
            }
            if ((string) ($sil['nota'] ?? '') === 'corsa troppo profonda' && (string) $sil['spoletta'] === 'contatto') {
                $sil['esito'] = 'mancato';
                return ['testo' => sprintf('Tubo %d: il siluro passa sotto la chiglia di %s senza toccarla. Correva troppo profondo.',
                    (int) $sil['tubo'], (string) $e['name'])];
            }

            $sottoChiglia = (string) $sil['spoletta'] === 'magnetica';
            $danno = Torpedo::danno((int) $e['grt'], (int) $tipo['warhead_kg'], (string) ($e['carico'] ?? ''), $sottoChiglia, $rng);

            $e['integrita'] = max(0.0, (float) $e['integrita'] - $danno['danno']);
            $e['allagamento'] = min(100.0, (float) $e['allagamento'] + $danno['allagamento']);
            $e['incendio'] = min(100.0, (float) $e['incendio'] + $danno['incendio']);
            $sil['esito'] = 'colpito';
            $sil['entity_id'] = (int) $e['id'];
            // Chi l'ha colpita: serve al registro per dire di che cosa e' morta.
            $e['colpita_siluro'] = 1;
            Database::run('UPDATE encounter_entities SET colpita_siluro = 1 WHERE id = ?', [(int) $e['id']]);

            $testo = sprintf('Colpita %s (%s GRT) sul %s.', (string) $e['name'],
                number_format((int) $e['grt'], 0, ',', '.'),
                $rng->chance(0.5) ? 'lato dritto' : 'lato sinistro');

            if ((float) $e['integrita'] <= 0.0) {
                $e['stato'] = 'affonda';
                $e['affonda_gts'] = $t + ($danno['danno'] > 200 ? 30 : Torpedo::tempoAffondamento((float) $e['allagamento'], (int) $e['grt'], $rng));
                $testo .= $danno['danno'] > 200
                    ? ' Il carico salta in aria: la nave sparisce in una colonna di fuoco.'
                    : ' Si spezza e comincia ad andare giu\'.';
            } else {
                $e['stato'] = 'danneggiata';
                $testo .= (float) $e['incendio'] > 40
                    ? ' Va a fuoco: si vede il bagliore fino all\'orizzonte.'
                    : sprintf(' Sbanda e rallenta, allagamento al %.0f%%.', (float) $e['allagamento']);
            }

            return ['testo' => $testo, 'colpito' => true];
        }
        unset($e);

        if ((int) $sil['percorso_m'] >= (int) $sil['corsa_max_m']) {
            $sil['esito'] = 'esaurito';
            $sil['esito_gts'] = $t;
            return ['testo' => sprintf('Tubo %d: siluro a fine corsa, nessun risultato.', (int) $sil['tubo'])];
        }

        return null;
    }


    // --- Le scorte ---------------------------------------------------------------



    /**
     * Registra un affondamento: contabilita', archivio, calore nel settore, e la
     * riga di giornale.
     *
     * La riga la scrive qui, con un genere suo ('affondamento') e con l'ora
     * ESATTA in cui la nave e' andata giu'. Prima finiva nel mucchio delle righe
     * di cronaca, tutte marcate 'combattimento' e tutte con l'ora di fine del
     * blocco di passi: nel giornale un affondamento era indistinguibile da un
     * colpo mancato, e non si poteva risalire a quale nave fosse.
     *
     * @return string il testo, per la cronaca in diretta
     */
    /**
     * Di che cosa e' morta: lo dicono le bandierine lasciate da chi l'ha colpita.
     *
     * Prima l'arma arrivava scritta a mano, e in tutte e due le chiamate era
     * "siluro": una nave finita a cannonate risultava affondata a siluri, il
     * trofeo del cannoniere era inottenibile e il rendimento per siluro
     * contava anche quello che il siluro non aveva fatto.
     *
     * @param array<string,mixed> $e
     */
    private static function armaCheHaAffondato(array $e): string
    {
        // Si rilegge dalla riga invece di fidarsi della copia in memoria: il
        // cannone e il siluro arrivano da azioni diverse del comandante, e la
        // copia che ha in mano chi sta affondando la nave puo' essere di prima.
        $riga = Database::first(
            'SELECT colpita_siluro, colpita_cannone FROM encounter_entities WHERE id = ?',
            [(int) $e['id']]
        ) ?? $e;

        $siluro  = (int) ($riga['colpita_siluro'] ?? 0) === 1;
        $cannone = (int) ($riga['colpita_cannone'] ?? 0) === 1;

        if ($siluro && $cannone) {
            // Il caso piu' comune di tutti: il siluro la ferma, il cannone la
            // finisce per non spendere il secondo siluro.
            return 'siluro_e_cannone';
        }
        if ($cannone) {
            return 'cannone';
        }
        // Senza bandierine si torna al siluro: e' quello che succede quando una
        // nave va giu' per un danno preso prima che l'incontro la registrasse.
        return 'siluro';
    }

    /**
     * Scrive l'affondamento nel registro, e torna la riga per il giornale.
     *
     * Torna null quando la nave era gia' stata accreditata a qualcuno: e'
     * andata a fondo lo stesso, e l'equipaggio l'ha vista andare giu', ma il
     * merito e' di chi ce l'ha mandata per primo. Chi chiama non deve contarla
     * nei totali dell'incontro.
     */
    private static function registraAffondamento(array $enc, array $boat, array $e, int $t, string $arma): ?string
    {
        // Una nave, un affondamento. Due battelli sullo stesso convoglio
        // aprono due incontri distinti, ognuno con la sua copia della
        // formazione: la stessa nave puo' andare a fondo in tutti e due.
        // Misurato il 19/09/2026 — Jonathan Cabot, 4.130 GRT, accreditati per
        // intero a due comandanti diversi. In un gioco dove il punteggio e' il
        // tonnellaggio, due giocatori d'accordo raddoppierebbero tutto
        // navigando insieme.
        if ($e['ship_id'] !== null) {
            $gia = Database::first(
                'SELECT boat_id FROM sinkings WHERE ship_id = ? LIMIT 1',
                [(int) $e['ship_id']]
            );
            if ($gia !== null) {
                return null;
            }
        }

        $sh = $e['ship_id'] !== null ? Database::first('SELECT * FROM ships WHERE id = ?', [(int) $e['ship_id']]) : null;
        $convoglio = null;
        if ($enc['convoy_id'] !== null) {
            $cv = Database::first('SELECT serie, numero FROM convoys WHERE id = ?', [(int) $enc['convoy_id']]);
            $convoglio = $cv !== null ? $cv['serie'] . $cv['numero'] : null;
        }

        $siluriUsati = (int) (Database::first(
            "SELECT COUNT(*) n FROM torpedo_runs WHERE encounter_id = ? AND entity_id = ? AND esito = 'colpito'",
            [(int) $enc['id'], (int) $e['id']]
        )['n'] ?? 0);

        // Il controllo di sopra copre il caso normale; questo copre la corsa.
        // Due incontri che si chiudono nello stesso istante passano tutti e due
        // dalla lettura e arrivano tutti e due a scrivere: l'indice unico ne
        // ferma uno, e senza questa rete l'eccezione arriverebbe fino al
        // giocatore come una pagina rotta — proprio nel momento in cui ha
        // appena affondato qualcosa.
        try {
            Database::run(
                'INSERT INTO sinkings (boat_id, patrol_id, commander_id, ship_id, nome, bandiera, class_key, grt, carico, arma,
                                       siluri_usati, convoglio, gts, lat, lon, quadrat)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $boat['id'], $enc['patrol_id'] !== null ? (int) $enc['patrol_id'] : null,
                    $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                    $e['ship_id'] !== null ? (int) $e['ship_id'] : null,
                    (string) $e['name'], (string) ($sh['flag'] ?? 'britannica'), (string) $e['class_key'],
                    (int) $e['grt'], $sh['carico'] ?? null, $arma, $siluriUsati, $convoglio, $t,
                    (float) $e['lat'], (float) $e['lon'], Grid::toQuadrat((float) $e['lat'], (float) $e['lon']),
                ]
            );
        } catch (\PDOException $ex) {
            if ($ex->getCode() === '23000') {
                return null;   // ci e' arrivato un altro un istante fa
            }
            throw $ex;
        }

        if ($e['ship_id'] !== null) {
            Database::run(
                "UPDATE ships SET state = 'affondata', sunk_gts = ?, sunk_by = ?, lat_ultima = ?, lon_ultima = ?
                 WHERE id = ?",
                [$t, (int) $boat['id'], (float) $e['lat'], (float) $e['lon'], (int) $e['ship_id']]
            );
        }
        if ($enc['convoy_id'] !== null) {
            Database::run('UPDATE convoys SET affondate = affondate + 1 WHERE id = ?', [(int) $enc['convoy_id']]);
        }
        if ($enc['patrol_id'] !== null) {
            Database::run(
                'UPDATE patrols SET affondate = affondate + 1, grt_affondato = grt_affondato + ? WHERE id = ?',
                [(int) $e['grt'], (int) $enc['patrol_id']]
            );
        }

        // Dove si affonda, il mare si scalda.
        Sectors::add((float) $e['lat'], (float) $e['lon'], 22.0, $t, 'affondamento');

        $testo = sprintf(
            "%s e' affondata: %s GRT.",
            (string) $e['name'],
            number_format((int) $e['grt'], 0, ',', '.')
        );
        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $t, 'kind' => 'affondamento', 'severity' => 'nota',
                'lat' => (float) $e['lat'], 'lon' => (float) $e['lon'],
                'quadrat' => Grid::toQuadrat((float) $e['lat'], (float) $e['lon']),
                'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }
        return $testo;
    }


    // --- Fuoco -------------------------------------------------------------------

    /**
     * Lancio di siluri.
     *
     * Le stime (angolo sulla prua, distanza, velocita') sono quelle del
     * comandante: il calcolatore risolve il triangolo su quei numeri, non sulla
     * verita'. Se la stima e' sbagliata il siluro parte perfettamente in linea
     * con una soluzione sbagliata — ed e' per questo che si insegnava a
     * cronometrare i rilevamenti invece di tirare a indovinare.
     *
     * @param array{entity_id:int,tubi:list<int>,spoletta?:string,quota?:float,ventaglio?:float,stima?:array} $ordine
     * @return array{ok:bool, error?:string, lanciati?:int, soluzione?:array, testo?:string}
     */
    public static function lancia(array $enc, array $boat, array $ordine, int $gts): array
    {
        $type = World::type((string) $boat['type_key']);

        if ((float) $boat['depth_m'] > 16.0) {
            return ['ok' => false, 'error' => 'Si lancia da quota periscopica o dalla superficie, non da quaranta metri.'];
        }

        $bersaglio = Database::first(
            'SELECT * FROM encounter_entities WHERE id = ? AND encounter_id = ?',
            [(int) $ordine['entity_id'], (int) $enc['id']]
        );
        if ($bersaglio === null || in_array((string) $bersaglio['stato'], ['affondata', 'fuggita'], true)) {
            return ['ok' => false, 'error' => 'Bersaglio non valido.'];
        }

        $tubiPronti = [];
        foreach (Torpedo::tubiPronti((int) $boat['id']) as $tp) {
            $tubiPronti[(int) $tp['tubo']] = $tp;
        }
        $richiesti = array_values(array_filter(
            array_map('intval', $ordine['tubi'] ?? []),
            static fn (int $n): bool => isset($tubiPronti[$n])
        ));
        if ($richiesti === []) {
            return ['ok' => false, 'error' => 'Nessun tubo pronto fra quelli indicati.'];
        }

        // --- dati del problema ---------------------------------------------
        $rilevamento = Geo::bearing((float) $boat['lat'], (float) $boat['lon'], (float) $bersaglio['lat'], (float) $bersaglio['lon']);
        $distanzaVera = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $bersaglio['lat'], (float) $bersaglio['lon']);

        // Angolo sulla prua del bersaglio: quanto ci mostra il fianco.
        // Angolo sulla prua: quanto il bersaglio ci mostra il fianco, e QUALE
        // fianco. Se il rilevamento inverso cade a dritta della sua rotta,
        // stiamo guardando il suo lato dritto; a sinistra, il lato sinistro.
        // Sbagliare questo segno vuol dire calcolare l'anticipo dalla parte
        // opposta, e il siluro parte perfettamente nella direzione sbagliata.
        $rilevamentoInverso = Geo::normBearing($rilevamento + 180.0);
        $deltaAob = Geo::bearingDelta((float) $bersaglio['heading'], $rilevamentoInverso);
        $aobVero = abs($deltaAob);
        $aobSinistra = $deltaAob < 0;

        $rng = Rng::for(World::seed(), 'lancio', (int) $enc['id'], $gts);
        $ciurma = Crew::aggregate((int) $boat['id']);

        $stima = $ordine['stima'] ?? null;
        if (!is_array($stima) || !isset($stima['aob'], $stima['distanza'], $stima['velocita'])) {
            // Nessuna stima del comandante: la fa il Primo Ufficiale.
            $resa = min(1.3, ($ciurma['specialita']['iwo'] ?? 0.6) / 0.6);
            $stima = Torpedo::stimaIwo($aobVero, $distanzaVera, (float) $bersaglio['speed_kn'], $resa, $rng);
            $stima['autore'] = 'I.WO';
        } else {
            $stima['autore'] = 'comandante';
        }

        $spoletta = ($ordine['spoletta'] ?? 'contatto') === 'magnetica' ? 'magnetica' : 'contatto';
        $quota = max(1.0, min(12.0, (float) ($ordine['quota'] ?? 4.0)));
        $ventaglio = max(0.0, min(6.0, (float) ($ordine['ventaglio'] ?? 0.0)));

        $lanciati = 0;
        $soluzione = null;
        $testi = [];

        foreach ($richiesti as $idx => $tubo) {
            $tp = $tubiPronti[$tubo];
            $tipo = Torpedo::tipo((string) $tp['tkey']);
            $reg = Torpedo::regolazione($tipo, (float) $stima['distanza'] * 1852.0);

            $sol = Torpedo::soluzione(
                $rilevamento, (float) $stima['distanza'], (float) $stima['aob'], $aobSinistra,
                (float) $stima['velocita'], $reg['v_kn'], (float) $boat['heading']
            );
            if (!$sol['ok']) {
                return ['ok' => false, 'error' => $sol['errore'] ?? 'Soluzione impossibile.'];
            }
            $soluzione ??= $sol;

            // Il ventaglio apre i siluri di qualche grado: si copre l'errore
            // sulla velocita' sparando piu' armi leggermente divergenti.
            $scarto = $ventaglio > 0 && count($richiesti) > 1
                ? ($idx - (count($richiesti) - 1) / 2) * $ventaglio
                : 0.0;

            // Dispersione propria dell'arma e dell'uomo che l'ha regolata.
            $resaSiluristi = min(1.4, ($ciurma['specialita']['silurista'] ?? 1.5) / 2.0 + 0.4);
            $errore = $rng->gauss() * (0.9 / max(0.4, $resaSiluristi));

            // Un lotto collaudato dimezza quasi i difetti: e' il modo di
            // spendere i punti di assegnazione che si ripaga da solo.
            $mig = \App\Game\Carriera::effettiMiglioramenti((int) $boat['id']);
            $difetti = Torpedo::difetti(
                array_merge($tipo, [
                    'p_cilecca'      => (float) $tipo['p_cilecca'] * ($mig['siluri_qualita'] ?? 1.0),
                    'p_prematura'    => (float) $tipo['p_prematura'] * ($mig['siluri_qualita'] ?? 1.0),
                    'p_quota_errata' => (float) $tipo['p_quota_errata'] * ($mig['siluri_qualita'] ?? 1.0),
                ]),
                $spoletta, $rng
            );

            Database::run(
                'INSERT INTO torpedo_runs (encounter_id, boat_id, tkey, tubo, lanciato_gts, lat, lon, heading,
                                           speed_kn, quota_m, spoletta, corsa_max_m, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $enc['id'], (int) $boat['id'], (string) $tp['tkey'], $tubo, $gts,
                    (float) $boat['lat'], (float) $boat['lon'],
                    Geo::normBearing($sol['rotta'] + $scarto + $errore),
                    $reg['v_kn'], $quota + $difetti['quota_extra'], $spoletta, $reg['r_m'], $difetti['nota'],
                ]
            );

            Database::run("UPDATE boat_torpedoes SET stato = 'lanciato' WHERE id = ?", [(int) $tp['id']]);
            $lanciati++;
        }

        if ($enc['patrol_id'] !== null) {
            Database::run('UPDATE patrols SET siluri_lanciati = siluri_lanciati + ? WHERE id = ?', [$lanciati, (int) $enc['patrol_id']]);
        }
        Database::run("UPDATE encounters SET stato = 'attacco' WHERE id = ? AND stato = 'avvicinamento'", [(int) $enc['id']]);

        $testo = sprintf(
            'Lanciati %d siluri da %s su %s: rilevamento %03.0f, distanza stimata %.0f metri, '
            . 'angolo sulla prua %.0f gradi, velocita\' stimata %.0f nodi (stima del %s). Spoletta %s, quota %.0f metri.',
            $lanciati,
            count($richiesti) > 1 ? 'tubi ' . implode(', ', $richiesti) : 'tubo ' . $richiesti[0],
            (string) $bersaglio['name'], $rilevamento, (float) $stima['distanza'] * 1852,
            (float) $stima['aob'], (float) $stima['velocita'], (string) $stima['autore'], $spoletta, $quota
        );

        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'lancio', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }

        return ['ok' => true, 'lanciati' => $lanciati, 'soluzione' => $soluzione, 'testo' => $testo];
    }

    /**
     * Cannone di coperta.
     *
     * Il modo economico di affondare una nave disarmata: un siluro costa quanto
     * cento colpi da 8,8. Richiede pero' di stare in superficie con uomini in
     * coperta, mare non impossibile, e un bersaglio che non spari a sua volta.
     *
     * @return array{ok:bool, error?:string, colpi?:int, centri?:int, testo?:string}
     */
    public static function cannone(array $enc, array $boat, int $entityId, int $colpi, int $gts): array
    {
        $type = World::type((string) $boat['type_key']);
        if (empty($type['deck_gun'])) {
            return ['ok' => false, 'error' => 'Questo battello non ha cannone di coperta.'];
        }
        if ((string) $boat['mode'] !== 'superficie') {
            return ['ok' => false, 'error' => 'Il cannone si usa in superficie.'];
        }

        $arma = Database::first("SELECT * FROM boat_systems WHERE boat_id = ? AND skey = 'cannone'", [(int) $boat['id']]);
        if ($arma !== null && (string) $arma['state'] !== 'ok') {
            return ['ok' => false, 'error' => 'Il cannone e\' fuori uso.'];
        }

        $meteo = World::weather((float) $boat['lat'], (float) $boat['lon'], $gts);
        if ((int) $meteo['sea_state'] > 4) {
            return ['ok' => false, 'error' => 'Mare troppo grosso: in coperta non si sta in piedi, figurarsi puntare.'];
        }

        $munizioni = (float) (Outfitting::stores((int) $boat['id'])['munizioni_cannone'] ?? 0);
        if ($munizioni < 1) {
            return ['ok' => false, 'error' => 'Munizioni da cannone esaurite.'];
        }

        $e = Database::first('SELECT * FROM encounter_entities WHERE id = ? AND encounter_id = ?', [$entityId, (int) $enc['id']]);
        if ($e === null || in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
            return ['ok' => false, 'error' => 'Bersaglio non valido.'];
        }

        $d = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
        if ($d > 3.2) {
            return ['ok' => false, 'error' => sprintf('Il bersaglio e\' a %.1f miglia: troppo lontano per il cannone.', $d)];
        }

        $colpi = (int) max(1, min(20, min($colpi, (int) $munizioni)));
        $rng = Rng::for(World::seed(), 'cannone', (int) $enc['id'], $gts);
        $ciurma = Crew::aggregate((int) $boat['id']);
        $resa = min(1.4, ($ciurma['specialita']['silurista'] ?? 1.5) / 2.2 + 0.45);

        // Probabilita' di centro: buona sotto il miglio, scarsa oltre i due,
        // e rovinata dal mare.
        $pCentro = max(0.05, min(0.85, (0.75 - 0.22 * $d) * $resa * (1.0 - 0.12 * max(0, (int) $meteo['sea_state'] - 1))));

        $centri = 0;
        $danno = 0.0;
        for ($i = 0; $i < $colpi; $i++) {
            if ($rng->chance($pCentro)) {
                $centri++;
                // Un colpo da 8,8 cm non spezza una nave: la buca, e le buche
                // sulla linea di galleggiamento si sommano.
                $danno += $rng->range(2.2, 5.5) * sqrt(5200.0 / max(400.0, (int) $e['grt']));
            }
        }

        Outfitting::consume((int) $boat['id'], 'munizioni_cannone', $colpi);

        $integrita = max(0.0, (float) $e['integrita'] - $danno);
        $allagamento = min(100.0, (float) $e['allagamento'] + $danno * 0.8);
        $stato = (string) $e['stato'];
        $affonda = null;

        if ($integrita <= 0.0 || $allagamento >= 85.0) {
            $stato = 'affonda';
            $affonda = $gts + Torpedo::tempoAffondamento($allagamento, (int) $e['grt'], $rng);
        } elseif ($danno > 0) {
            $stato = 'danneggiata';
        }

        Database::run(
            'UPDATE encounter_entities SET integrita = ?, allagamento = ?, stato = ?, affonda_gts = ?,
                    colpita_cannone = CASE WHEN ? > 0 THEN 1 ELSE colpita_cannone END
             WHERE id = ?',
            [$integrita, $allagamento, $stato, $affonda, $centri, $entityId]
        );

        $testo = sprintf(
            'Cannone: %d colpi su %s a %.0f metri, %d a segno. %s',
            $colpi, (string) $e['name'], $d * 1852, $centri,
            $stato === 'affonda' ? 'Si inclina e comincia ad affondare.' : ($centri > 0 ? 'Fori sulla linea di galleggiamento.' : 'Colpi corti.')
        );

        // --- e se non era un piroscafo ------------------------------------------
        //
        // La trappola della nave civetta scattava esattamente qui: il U-Boot in
        // superficie, a poche centinaia di metri, col cannone in coperta e
        // l'equipaggio allo scoperto. I pannelli cadevano e quello che sembrava
        // uno sbandato aveva due pezzi da 4 pollici gia' puntati.
        $verita = Traffic::classe((string) $e['class_key']);
        if ((string) ($verita['finge'] ?? '') !== '' && !(bool) $e['smascherata']) {
            Database::run('UPDATE encounter_entities SET smascherata = 1 WHERE id = ?', [$entityId]);

            // Piu' si e' vicini, peggio e': a tremila metri si fa in tempo a
            // immergersi, a cinquecento no.
            $vicinanza = max(0.0, 1.0 - $d / 3.2);
            $danno = 14.0 + 30.0 * $vicinanza * $rng->range(0.7, 1.3);
            $eventiFinti = [];
            $bStato = [
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'depth' => (float) $boat['depth_m'], 'mode' => (string) $boat['mode'],
                'speed' => (float) $boat['speed_kn'], 'stress' => (float) $boat['hull_stress'],
                'heading' => (float) $boat['heading'],
            ];
            Scorte::applicaDanno((int) $boat['id'], $bStato, $type, $danno, $gts, $rng, $eventiFinti);

            $testo .= sprintf(
                ' I pannelli sulle murate cadono: non era un mercantile. %s apre il fuoco da %.0f metri.',
                (string) $e['name'], $d * 1852
            );
            foreach ($eventiFinti as $ev) {
                $testo .= ' ' . (is_array($ev) ? (string) ($ev['text'] ?? '') : (string) $ev);
            }

            Database::run(
                'UPDATE boats SET hull_stress = ?, ordered_depth_m = GREATEST(ordered_depth_m, 60),
                        battle_stations = 1, version = version + 1 WHERE id = ?',
                [$bStato['stress'], (int) $boat['id']]
            );
            Database::run("UPDATE encounters SET allarme = 1 WHERE id = ?", [(int) $enc['id']]);
        }

        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'cannone', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']),
                'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }

        return ['ok' => true, 'colpi' => $colpi, 'centri' => $centri, 'testo' => $testo];
    }

    /**
     * Cartuccia Bold: una nuvola di bolle che all'ASDIC somiglia a un
     * sommergibile fermo. Non inganna a lungo, ma il tempo che guadagna e' il
     * tempo che serve per sparire.
     */
    public static function bold(array $enc, array $boat, int $gts): array
    {
        if ((string) $boat['mode'] === 'superficie') {
            return ['ok' => false, 'error' => 'Il Bold si spara in immersione.'];
        }
        $scorte = (float) (Outfitting::stores((int) $boat['id'])['bold'] ?? 0);
        if ($scorte < 1) {
            return ['ok' => false, 'error' => 'Cartucce Bold esaurite.'];
        }

        Outfitting::consume((int) $boat['id'], 'bold', 1);
        $rng = Rng::for(World::seed(), 'bold', (int) $enc['id'], $gts);

        // Le scorte che ci hanno in mano vengono ingannate in proporzione a
        // quanto era saldo il contatto: chi ci sta sopra non abbocca.
        $ingannate = 0;
        foreach (self::entita((int) $enc['id']) as $e) {
            if ((string) $e['ruolo'] !== 'scorta' || (float) $e['contatto'] <= 0.05) {
                continue;
            }
            $migB = \App\Game\Carriera::effettiMiglioramenti((int) $boat['id']);
            $p = (0.75 - 0.45 * (float) $e['contatto']) * ($migB['bold_efficacia'] ?? 1.0);
            if ($rng->chance(max(0.1, $p))) {
                Database::run(
                    "UPDATE encounter_entities SET contatto = ?, manovra = 'ricerca' WHERE id = ?",
                    [round((float) $e['contatto'] * 0.25, 3), (int) $e['id']]
                );
                $ingannate++;
            }
        }

        $testo = $ingannate > 0
            ? sprintf('Bold in mare. %d scorte si attaccano alla nuvola di bolle: adesso o mai piu\'.', $ingannate)
            : 'Bold in mare, ma non ci cascano: continuano a seguirci.';

        if ($enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'bold', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'],
                'quadrat' => null, 'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }

        return ['ok' => true, 'ingannate' => $ingannate, 'testo' => $testo];
    }

    /**
     * Passo automatico, fatto dal tick.
     *
     * Se la finestra di condotta del comandante e' scaduta, il Primo Ufficiale
     * prende il battello e fa la cosa prudente: giu', piano, in silenzio, via
     * dal contatto. Non attacca di sua iniziativa — quella e' una decisione del
     * comandante, e in sua assenza non si prende.
     */
    public static function passoAutomatico(int $encId): array
    {
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        if ($enc === null || (string) $enc['stato'] === 'concluso') {
            return ['passi' => 0, 'eventi' => [], 'stato' => 'concluso', 'chiuso' => true];
        }

        $scaduta = time() > (int) $enc['finestra_fine'];
        if ($scaduta) {
            $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $enc['boat_id']]);
            if ($boat !== null && (float) $boat['ordered_depth_m'] < 60.0) {
                $type = World::type((string) $boat['type_key']);
                $quota = min((float) $type['test_depth_m'], 80.0);
                Database::run(
                    'UPDATE boats SET ordered_depth_m = ?, ordered_speed_kn = 2, silent = 1 WHERE id = ?',
                    [$quota, (int) $boat['id']]
                );
                if ($enc['patrol_id'] !== null) {
                    BoatSim::save([
                        'gts' => (int) $enc['last_step_gts'], 'kind' => 'iwo', 'severity' => 'attenzione',
                        'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'], 'quadrat' => null,
                        'text' => 'Il comandante non e\' in plancia: il Primo Ufficiale disimpegna. '
                            . sprintf('Quota %.0f metri, due nodi, marcia silenziosa.', $quota),
                    ], (int) $enc['patrol_id'], (int) $boat['id']);
                }
            }
        }

        return self::step($encId);
    }

    /**
     * Alza o abbassa il periscopio.
     *
     * E' la decisione piu' piccola e piu' pesante dell'attacco in immersione:
     * alzato si vede il bersaglio e si prende l'angolo, ma la corsa lascia una
     * baffa che sul mare liscio si vede da lontano, e la sagoma del battello
     * per una vedetta triplica. Il I.WO lo abbassa da solo dopo cinque minuti
     * se il comandante se ne dimentica — non per gentilezza, per mestiere.
     *
     * @return array{ok:bool, error?:string, testo?:string}
     */
    public static function periscopio(array $boat, bool $alza, int $gts): array
    {
        if ($alza) {
            if ((string) $boat['mode'] !== 'periscopio') {
                return ['ok' => false, 'error' => "Il periscopio si alza a quota periscopica, non da qui."];
            }
            $st = Database::first("SELECT state FROM boat_systems WHERE boat_id = ? AND skey = 'periscopio_att'", [(int) $boat['id']]);
            if ($st !== null && (string) $st['state'] !== 'ok') {
                return ['ok' => false, 'error' => "Il periscopio d'attacco e' in avaria."];
            }
        }

        Database::run(
            'UPDATE boats SET periscopio_alzato = ?, periscopio_gts = ? WHERE id = ?',
            [$alza ? 1 : 0, $alza ? $gts : null, (int) $boat['id']]
        );

        $testo = $alza
            ? "Periscopio d'attacco fuori."
            : 'Periscopio abbassato.';

        $enc = self::corrente((int) $boat['id']);
        if ($enc !== null && $enc['patrol_id'] !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'periscopio', 'severity' => 'nota',
                'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'], 'quadrat' => null,
                'text' => $testo,
            ], (int) $enc['patrol_id'], (int) $boat['id']);
        }

        return ['ok' => true, 'testo' => $testo];
    }

    /** Chiude l'incontro e restituisce il battello alla navigazione di crociera. */
    /**
     * Chiude l'incontro.
     *
     * Il conto degli affondamenti si passa quando lo si conosce: l'ultimo passo
     * puo' averne aggiunti, e quelli non li ha ancora visti nessuno. Se non si
     * passa niente restano i valori gia' in riga, che sono quelli del passo
     * precedente.
     */
    public static function chiudi(int $encId, string $esito, int $gts, ?int $affondate = null, ?int $grtAffondato = null): void
    {
        $enc = Database::first('SELECT * FROM encounters WHERE id = ?', [$encId]);
        if ($enc === null) {
            return;
        }
        // Il danno di chi resta a galla esce dall'incontro anche per questa
        // strada: il disimpegno ordinato dal comandante passa di qui e non dal
        // passo. La scrittura e' a prova di doppione.
        $suo = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $enc['boat_id']]);
        if ($suo !== null) {
            Danni::registra(
                $enc, $suo,
                Database::all('SELECT * FROM encounter_entities WHERE encounter_id = ?', [$encId]),
                $gts
            );
        }

        Database::run(
            "UPDATE encounters SET stato = 'concluso', ended_gts = ?, esito = ?,
                    affondate = ?, grt_affondato = ? WHERE id = ?",
            [
                $gts, mb_substr($esito, 0, 255),
                $affondate ?? (int) $enc['affondate'],
                $grtAffondato ?? (int) $enc['grt_affondato'],
                $encId,
            ]
        );

        // Fine di un incontro: e' uno dei momenti in cui si guardano i trofei.
        $boatRow = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $enc['boat_id']]);
        if ($boatRow !== null) {
            $user = Database::first('SELECT * FROM users WHERE id = ?', [(int) $boatRow['user_id']]);
            $cmd = $boatRow['commander_id'] !== null
                ? Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $boatRow['commander_id']])
                : null;
            if ($user !== null) {
                foreach (\App\Game\Trofei::verifica($user, $cmd, $boatRow) as $t) {
                    if ($enc['patrol_id'] !== null) {
                        BoatSim::save([
                            'gts' => $gts, 'kind' => 'trofeo', 'severity' => 'nota',
                            'lat' => (float) $boatRow['lat'], 'lon' => (float) $boatRow['lon'], 'quadrat' => null,
                            'text' => 'Trofeo: ' . $t['nome'] . ' — ' . $t['descrizione'],
                        ], (int) $enc['patrol_id'], (int) $boatRow['id']);
                    }
                }
            }
        }
        Database::run(
            'UPDATE boats SET encounter_id = NULL, battle_stations = 0,
                    periscopio_alzato = 0, periscopio_gts = NULL, last_sim_gts = ? WHERE id = ?',
            [$gts, (int) $enc['boat_id']]
        );
    }
}
