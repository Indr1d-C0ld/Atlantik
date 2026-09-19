<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * Il danno che resta addosso alla nave, finito l'incontro.
 *
 * Dentro l'incontro tattico una nave colpita ha integrita', allagamento e
 * incendio, e se ne tiene conto passo per passo. Fuori no: il traffico e' una
 * funzione matematica, e una nave e' definita dalla sua rotta, dalla sua
 * velocita' e dall'ora di partenza. Quando l'incontro si chiudeva, il danno
 * spariva con lui: due siluri a segno e la mattina dopo quella nave navigava
 * come nuova.
 *
 * Non andava cosi'. Una nave silurata e rimasta a galla:
 *
 *   RALLENTA. Con una stiva allagata e l'assetto storto non si tengono piu' i
 *   nodi di prima, e a volte nemmeno la meta'.
 *
 *   RESTA INDIETRO. Il convoglio non aspetta: era regola scritta, e la nave
 *   rallentata diventava una "straggler". Erano la preda preferita degli
 *   U-Boot, perche' fuori dal convoglio non c'era piu' nessuno a proteggerle.
 *
 *   PUO' AFFONDARE PIU' TARDI. Ore, a volte giorni. Il comandante che l'aveva
 *   colpita era lontano e spesso non lo sapeva: glielo diceva il BdU, che
 *   confrontava le rivendicazioni col traffico nemico e accreditava dopo.
 *
 * Questa classe fa quelle tre cose. Il danno esce dall'incontro e resta nel
 * mondo: chi ritrova quella nave la ritrova ferita.
 */
final class Danni
{
    /** Sotto questa velocita' residua non si tiene il posto in convoglio. */
    private const SOGLIA_RITARDATARIA = 0.85;

    /** Nessuna nave, per quanto malmessa, sta ferma in mezzo all'Atlantico. */
    private const VELOCITA_MINIMA_KN = 3.0;

    /**
     * Porta fuori dall'incontro il danno delle navi rimaste a galla.
     *
     * Si chiama alla chiusura dell'incontro, dopo che le condannate sono state
     * registrate: qui restano solo quelle che se la sono cavata, per ora.
     *
     * @param list<array<string,mixed>> $entita
     * @return array{danneggiate:int,ritardatarie:int,condannate:int}
     */
    public static function registra(array $enc, array $boat, array $entita, int $gts): array
    {
        $out = ['danneggiate' => 0, 'ritardatarie' => 0, 'condannate' => 0];

        foreach ($entita as $e) {
            if ($e['ship_id'] === null) {
                continue;                                  // materializzata dal nulla
            }
            if (in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
                continue;
            }
            $integrita   = (float) $e['integrita'];
            $allagamento = (float) $e['allagamento'];
            $incendio    = (float) $e['incendio'];
            if ($integrita >= 99.99 && $allagamento <= 0.01 && $incendio <= 0.01) {
                continue;                                  // non l'ha toccata nessuno
            }

            $nave = Database::first('SELECT * FROM ships WHERE id = ?', [(int) $e['ship_id']]);
            if ($nave === null || (string) $nave['state'] !== 'in_mare') {
                continue;
            }

            // Si passa di qui due volte per lo stesso incontro — una dal passo
            // che lo chiude, una da chiudi() che copre anche il disimpegno
            // ordinato dal comandante. Il danno va scritto una volta sola: la
            // seconda ri-applicherebbe il rallentamento sopra se stesso.
            if ($nave['danno_gts'] !== null && (int) $nave['danno_gts'] >= $gts) {
                continue;
            }

            $rng = Rng::for(World::seed(), 'danno', (int) $nave['id'], $gts);

            // --- velocita' e continuita' della posizione -----------------------
            //
            // La posizione di una nave e' un calcolo su rotta, velocita' e ora
            // di partenza. Cambiare la velocita' e basta la farebbe saltare
            // indietro di colpo, come se non avesse mai percorso quello che ha
            // percorso. Si sposta invece l'ora di partenza in modo che ADESSO
            // sia esattamente dove e': la nave rallenta da qui in avanti, non
            // da quando e' salpata.
            $vecchiaVel = (float) $nave['speed_kn'];
            $fattore    = self::fattoreVelocita($integrita, $allagamento, $incendio);
            $nuovaVel   = max(self::VELOCITA_MINIMA_KN, round($vecchiaVel * $fattore, 1));

            $percorse  = max(0.0, $vecchiaVel * (($gts - (int) $nave['departed_gts']) / 3600.0));
            $lunghezza = Traffic::lunghezzaNm((string) $nave['rotta_key']);
            $restanti  = max(0.0, $lunghezza - $percorse);

            $nuovoDeparted = $gts - (int) round($percorse / $nuovaVel * 3600.0);
            $nuovaEta      = $gts + (int) round($restanti / $nuovaVel * 3600.0);

            // --- resta indietro? ----------------------------------------------
            $ritardataria = (bool) $nave['ritardataria'];
            $convoyId = $nave['convoy_id'];
            $convoglioPerduto = $nave['convoglio_perduto'];

            if ($convoyId !== null && $fattore < self::SOGLIA_RITARDATARIA) {
                $cv = Database::first('SELECT serie, numero FROM convoys WHERE id = ?', [(int) $convoyId]);
                $convoglioPerduto = $cv !== null ? $cv['serie'] . $cv['numero'] : null;
                $convoyId = null;
                $ritardataria = true;
                $out['ritardatarie']++;
            }

            // --- ce la fa o no? -----------------------------------------------
            //
            // La decisione si prende UNA volta, adesso, e si scrive: cosi' non
            // dipende da quante volte qualcuno guarda, e il mondo resta
            // deterministico come tutto il resto.
            $affondaGts = $nave['affonda_gts'] !== null ? (int) $nave['affonda_gts'] : null;
            if ($affondaGts === null) {
                $p = self::probabilitaAffondamento($allagamento, $integrita, $incendio, (string) ($nave['carico'] ?? ''));
                if ($p > 0.0 && $rng->chance($p)) {
                    $affondaGts = $gts + Torpedo::tempoAffondamento($allagamento, (int) $nave['grt'], $rng);
                    $out['condannate']++;
                }
            }

            Database::run(
                'UPDATE ships
                    SET integrita = ?, allagamento = ?, incendio = ?,
                        speed_kn = ?, departed_gts = ?, eta_gts = ?,
                        convoy_id = ?, colonna = NULL, fila = NULL,
                        ritardataria = ?, convoglio_perduto = ?,
                        danno_gts = ?,
                        danno_boat_id = ?, danno_patrol_id = ?, danno_commander_id = ?,
                        affonda_gts = ?
                  WHERE id = ?',
                [
                    round($integrita, 2), round($allagamento, 2), round($incendio, 2),
                    $nuovaVel, $nuovoDeparted, $nuovaEta,
                    $convoyId, $ritardataria ? 1 : 0, $convoglioPerduto,
                    $gts,
                    (int) $boat['id'],
                    $enc['patrol_id'] !== null ? (int) $enc['patrol_id'] : null,
                    $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                    $affondaGts,
                    (int) $nave['id'],
                ]
            );
            $out['danneggiate']++;
        }

        return $out;
    }

    /**
     * Quanto cammina ancora una nave conciata cosi'.
     *
     * L'allagamento pesa piu' dell'integrita': una stiva piena d'acqua costringe
     * a rallentare per non sfondare le paratie, anche se lo scafo regge. Il
     * fuoco pesa meno sulla velocita' — si corre lo stesso, si brucia correndo.
     */
    public static function fattoreVelocita(float $integrita, float $allagamento, float $incendio): float
    {
        $f = 1.0;
        $f -= 0.62 * min(1.0, $allagamento / 100.0);
        $f -= 0.30 * min(1.0, max(0.0, 100.0 - $integrita) / 100.0);
        $f -= 0.08 * min(1.0, $incendio / 100.0);
        return max(0.18, round($f, 3));
    }

    /**
     * Probabilita' che una nave rimasta a galla affondi lo stesso, piu' tardi.
     *
     * La curva e' sull'allagamento, che e' quello che affonda le navi. Sotto un
     * quarto di stiva allagata non succede quasi niente: si tampona, si pompa e
     * si arriva. Oltre la meta' comincia a essere una corsa contro il tempo, e
     * vicino alla soglia di non ritorno non ce n'e' quasi per nessuno.
     *
     * Il carico conta: una petroliera in zavorra e una stiva di legname
     * galleggiano da sole — e' lo stesso effetto gia' modellato nel danno del
     * siluro, qui ricompare perche' agisce anche dopo.
     */
    public static function probabilitaAffondamento(
        float $allagamento,
        float $integrita,
        float $incendio,
        string $carico,
    ): float {
        if ($allagamento < 25.0 && $incendio < 55.0) {
            return 0.0;
        }

        $x = max(0.0, min(1.0, ($allagamento - 25.0) / 60.0));
        $p = $x ** 1.45;

        // Uno scafo gia' quasi passato non tiene a lungo.
        $p += 0.35 * max(0.0, (40.0 - $integrita) / 40.0);

        // Un incendio che non si spegne arriva prima o poi alle stive.
        $p += 0.30 * max(0.0, ($incendio - 55.0) / 45.0);

        $c = mb_strtolower($carico);
        if (str_contains($c, 'zavorra')) {
            $p *= 0.45;                                   // in zavorra si sta a galla molto meglio
        } elseif (str_contains($c, 'legname')) {
            $p *= 0.35;                                   // il legname galleggia, e la nave con lui
        } elseif (str_contains($c, 'minerale') || str_contains($c, 'acciaio') || str_contains($c, 'bauxite')) {
            $p *= 1.30;                                   // il carico pesante porta giu' in fretta
        }

        return max(0.0, min(0.97, $p * (float) GameConfig::get('danni.scala_agonia', 1.0)));
    }

    /**
     * Il battito: chi doveva andare a fondo ci va, e chi l'ha colpita lo viene
     * a sapere dal BdU.
     *
     * @return array{affondate:int,grt:int}
     */
    public static function agonia(int $gts): array
    {
        $out = ['affondate' => 0, 'grt' => 0];

        $navi = Database::all(
            "SELECT * FROM ships
              WHERE state = 'in_mare' AND affonda_gts IS NOT NULL AND affonda_gts <= ?
              ORDER BY affonda_gts LIMIT 50",
            [$gts]
        );

        foreach ($navi as $n) {
            $quando = (int) $n['affonda_gts'];
            $pos = Traffic::posizione(
                (string) $n['rotta_key'], (float) $n['speed_kn'], (int) $n['departed_gts'], $quando
            );
            $lat = $pos !== null ? $pos['lat'] : (float) ($n['lat_ultima'] ?? 0.0);
            $lon = $pos !== null ? $pos['lon'] : (float) ($n['lon_ultima'] ?? 0.0);

            Database::run(
                "UPDATE ships SET state = 'affondata', sunk_gts = ?, sunk_by = ?,
                        lat_ultima = ?, lon_ultima = ?, affonda_gts = NULL
                  WHERE id = ?",
                [$quando, $n['danno_boat_id'], $lat, $lon, (int) $n['id']]
            );
            $out['affondate']++;
            $out['grt'] += (int) $n['grt'];

            // Senza un battello a cui attribuirla e' solo una nave in meno in
            // mare: puo' succedere se l'account di chi l'ha colpita e' sparito.
            if ($n['danno_boat_id'] === null) {
                continue;
            }

            self::accredita($n, $lat, $lon, $quando);
        }

        return $out;
    }

    /** Mette l'affondamento tardivo in archivio e lo comunica a chi l'ha causato. */
    private static function accredita(array $n, float $lat, float $lon, int $quando): void
    {
        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $n['danno_boat_id']]);
        if ($boat === null) {
            return;
        }

        // Una nave, un affondamento (migrazione 0038). Una ritardataria che
        // affonda ore dopo puo' essere gia' stata accreditata a un altro
        // battello che l'ha finita nel frattempo: il merito e' di chi ce l'ha
        // mandata a fondo, non di chi l'aveva colpita per primo.
        $gia = Database::first('SELECT boat_id FROM sinkings WHERE ship_id = ? LIMIT 1', [(int) $n['id']]);
        if ($gia !== null) {
            return;
        }

        $siluri = (int) (Database::first(
            "SELECT COUNT(*) c FROM torpedo_runs WHERE boat_id = ? AND esito = 'colpito'",
            [(int) $boat['id']]
        )['c'] ?? 0);

        Database::run(
            'INSERT INTO sinkings (boat_id, patrol_id, commander_id, ship_id, nome, bandiera, class_key, grt,
                                   carico, arma, siluri_usati, convoglio, gts, lat, lon, quadrat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "siluro", ?, ?, ?, ?, ?, ?)',
            [
                (int) $boat['id'], $n['danno_patrol_id'], $n['danno_commander_id'], (int) $n['id'],
                (string) $n['name'], (string) $n['flag'], (string) $n['class_key'], (int) $n['grt'],
                $n['carico'], min(4, max(1, $siluri)), $n['convoglio_perduto'], $quando,
                $lat, $lon, Grid::toQuadrat($lat, $lon),
            ]
        );

        $orario = World::clock()->format($quando);
        $testo = sprintf(
            'Conferma dal BdU: la %s, colpita in %s e rimasta indietro, e\' affondata il %s. '
            . 'Accreditate %s tonnellate di stazza lorda.',
            (string) $n['name'],
            Grid::toQuadrat($lat, $lon),
            $orario,
            number_format((int) $n['grt'], 0, ',', '.')
        );

        Database::run(
            'INSERT INTO radio_messages (destinatario, dest_boat_id, tipo, testo, quadrat, lat, lon, gts, durata_s)
             VALUES ("battello", ?, "comunicato", ?, ?, ?, ?, ?, 0)',
            [(int) $boat['id'], $testo, Grid::toQuadrat($lat, $lon), $lat, $lon, $quando]
        );

        // La missione durante la quale l'hanno colpita puo' essere gia' chiusa.
        // Se e' ancora aperta la contabilita' la fa chiudiPatrol, come sempre;
        // se e' chiusa il conto va aggiunto qui, altrimenti quella stazza non
        // arriverebbe mai al comandante.
        $patrol = $n['danno_patrol_id'] !== null
            ? Database::first('SELECT * FROM patrols WHERE id = ?', [(int) $n['danno_patrol_id']])
            : null;

        if ($patrol !== null && (string) $patrol['state'] === 'in_corso') {
            Database::run(
                'UPDATE patrols SET affondate = affondate + 1, grt_affondato = grt_affondato + ? WHERE id = ?',
                [(int) $n['grt'], (int) $patrol['id']]
            );
            self::riga($patrol, $boat, $testo, $quando, $lat, $lon);
            return;
        }

        if ($n['danno_commander_id'] === null) {
            return;
        }
        $perGrt = (float) GameConfig::get('carriera.prestigio_per_100grt', 1.0);
        $prestigio = (int) round((int) $n['grt'] / 100 * $perGrt);
        Database::run(
            'UPDATE commanders
                SET affondate = affondate + 1, grt_affondato = grt_affondato + ?,
                    prestigio = prestigio + ?, prestigio_tot = prestigio_tot + ?
              WHERE id = ?',
            [(int) $n['grt'], $prestigio, $prestigio, (int) $n['danno_commander_id']]
        );

        // Se il comandante e' in mare con una nuova missione, la notizia gli
        // arriva comunque: e' un radiogramma, non una voce di bilancio.
        $inCorso = Database::first(
            "SELECT * FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [(int) $boat['id']]
        );
        if ($inCorso !== null) {
            self::riga($inCorso, $boat, $testo, $quando, $lat, $lon);
        }
    }

    private static function riga(array $patrol, array $boat, string $testo, int $gts, float $lat, float $lon): void
    {
        BoatSim::save([
            'gts' => $gts, 'kind' => 'affondamento', 'severity' => 'nota',
            'lat' => (float) $boat['lat'], 'lon' => (float) $boat['lon'], 'quadrat' => null,
            'text' => $testo,
        ], (int) $patrol['id'], (int) $boat['id']);
    }
}
