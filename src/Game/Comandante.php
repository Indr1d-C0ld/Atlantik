<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\World;

/**
 * Il comandante: l'alter ego del giocatore, e la sua mortalita'.
 *
 * Il battello perso e' perso. Prima pero' c'e' una catena di sopravvivenza
 * vera, con esiti pesati da condizioni reali: la quota al momento del colpo
 * (sopra i cinquanta metri qualche possibilita', a duecento nessuna),
 * l'allagamento, la temperatura dell'acqua (nel Nord Atlantico si muore di
 * ipotermia in venti minuti) e la presenza di qualcuno disposto a raccogliere.
 *
 * Il fascicolo del caduto non si cancella: resta nell'albo d'oro, con il suo
 * tonnellaggio e la sua data. Il giocatore ricomincia con un uomo nuovo, che
 * eredita una quota della reputazione di flottiglia — non i gradi, non le
 * medaglie.
 */
final class Comandante
{
    /** @return array<string,mixed>|null */
    public static function corrente(int $userId): ?array
    {
        return Database::first(
            "SELECT * FROM commanders WHERE user_id = ? AND stato = 'attivo' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> tutti i comandanti di un account, vivi e morti */
    public static function fascicoli(int $userId): array
    {
        return Database::all('SELECT * FROM commanders WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /** Eredita' di flottiglia: una quota del prestigio del predecessore. */
    public static function eredita(int $userId): int
    {
        $prec = Database::first(
            "SELECT prestigio_tot FROM commanders WHERE user_id = ? AND stato <> 'attivo' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if ($prec === null) {
            return 0;
        }
        $pct = max(0, min(80, GameConfig::int('carriera.eredita_pct', 25)));
        return (int) round((int) $prec['prestigio_tot'] * $pct / 100);
    }

    /**
     * Crea il comandante.
     *
     * @return array{ok:bool, error?:string, commander_id?:int}
     */
    public static function crea(int $userId, array $dati): array
    {
        if (self::corrente($userId) !== null) {
            return ['ok' => false, 'error' => 'Hai gia\' un comandante in servizio.'];
        }

        $nome = trim((string) ($dati['nome'] ?? ''));
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 64) {
            return ['ok' => false, 'error' => 'Il nome del comandante deve avere da 3 a 64 caratteri.'];
        }
        if (!preg_match('/^[\p{L}\p{N} .\'\-]+$/u', $nome)) {
            return ['ok' => false, 'error' => 'Il nome contiene caratteri non ammessi.'];
        }

        $base = World::port((string) ($dati['base'] ?? 'lorient'));
        if ($base === null || (string) $base['kind'] !== 'base') {
            return ['ok' => false, 'error' => 'Base non valida.'];
        }

        // Si scrive all'italiana, "22/05/1913", e si conserva come data vera.
        // Chi incolla una forma ISO non viene respinto: la conversione l'accetta.
        $natoIl = data_it_a_iso((string) ($dati['nato_il'] ?? ''));

        $eredita = self::eredita($userId);

        // Un nome di comandante non si porta in due: in flottiglia ci si chiama
        // per cognome, e due Vogel sarebbero una confusione. Non si controlla
        // prima e si scrive dopo — due creazioni simultanee passerebbero tutte e
        // due il controllo. Si scrive, e chi arriva secondo lo scopre dal
        // vincolo, che e' l'unico posto dove non si puo' barare.
        try {
            Database::run(
            'INSERT INTO commanders (user_id, nome, nato_il, nato_a, ritratto, flottiglia, base_key,
                                     grado, prestigio, prestigio_tot, punti, reichsmark, entrato_gts)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [
                $userId, $nome, $natoIl, mb_substr(trim((string) ($dati['nato_a'] ?? '')), 0, 64) ?: null,
                in_array((string) ($dati['ritratto'] ?? 'r1'), ['r1', 'r2', 'r3', 'r4'], true)
                    ? (string) ($dati['ritratto'] ?? 'r1')
                    : 'r1',
                (string) ($base['flotillas'] ?? 'U-Flottille'), (string) $base['port_key'],
                $eredita, $eredita, $eredita > 0 ? 40 : 20, 1200, World::now(),
            ]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'C\'e\' gia\' un comandante con questo nome in servizio. '
                    . 'In flottiglia ci si chiama per cognome: scegline un altro.'];
            }
            throw $e;
        }
        $id = Database::lastInsertId();

        // Il battello segue il comandante.
        Database::run(
            "UPDATE boats SET commander_id = ?, home_port_key = ?, flotilla = ? WHERE user_id = ? AND state <> 'perduto'",
            [$id, (string) $base['port_key'], (string) ($base['flotillas'] ?? 'U-Flottille'), $userId]
        );

        // Il ritratto. Va dopo la creazione e non dentro, per due motivi: una
        // fotografia si carica solo su un comandante che esiste gia', e la
        // scelta dal repertorio puo' fallire per conto suo — quel volto
        // potrebbe essere stato preso un istante prima. Un ritratto mancato
        // non deve far fallire l'arruolamento.
        $avviso = null;

        // Una fotografia propria ha la precedenza: se l'ha allegata, e' quella
        // che vuole.
        $file = $dati['file'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = \App\Game\Ritratto::carica($id, $file, !empty($dati['invecchia']));
            if (!$res['ok']) {
                $avviso = $res['error'] ?? null;
            }
            return ['ok' => true, 'commander_id' => $id, 'avviso' => $avviso];
        }

        $chiave = trim((string) ($dati['ritratto_key'] ?? ''));
        if ($chiave !== '') {
            $res = \App\Game\Ritratto::scegli($id, $chiave, !empty($dati['nome_storico']));
            if (!$res['ok']) {
                $avviso = $res['error'] ?? null;
            } elseif (($res['error'] ?? null) !== null) {
                $avviso = $res['error'];
            }
        }

        return ['ok' => true, 'commander_id' => $id, 'avviso' => $avviso];
    }

    /**
     * Il battello e' perduto: catena di sopravvivenza ed esito.
     *
     * @return array{esito:string,superstiti:int,testo:string}
     */
    public static function perdita(array $boat, string $causa, int $gts, ?array $meteo = null): array
    {
        $rng = Rng::for(World::seed(), 'perdita', (int) $boat['id'], $gts);
        $quota = (float) $boat['depth_m'];
        $lat = (float) $boat['lat'];
        $lon = (float) $boat['lon'];
        $meteo ??= World::weather($lat, $lon, $gts);

        $uomini = (int) (Database::first(
            "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health <> 'morto'",
            [(int) $boat['id']]
        )['n'] ?? 45);

        // Probabilita' di riuscire ad abbandonare il battello: crolla con la
        // quota. Sotto i cento metri il portello non si apre nemmeno, e a
        // duecento lo scafo arriva giu' schiacciato.
        $pAbbandono = match (true) {
            $quota <= 2.0   => 0.72,
            $quota <= 25.0  => 0.45,
            $quota <= 60.0  => 0.18,
            $quota <= 110.0 => 0.05,
            default         => 0.0,
        };

        // L'acqua fredda uccide in fretta: nel Nord Atlantico d'inverno chi non
        // viene raccolto entro mezz'ora non viene raccolto piu'.
        $acqua = (float) $meteo['sea_c'];
        $pSopravvivenzaInAcqua = max(0.05, min(0.85, ($acqua - 2.0) / 22.0));
        if ((int) $meteo['sea_state'] >= 7) {
            $pSopravvivenzaInAcqua *= 0.45;
        }

        // Qualcuno che raccoglie: le scorte a volte lo facevano, a volte no —
        // e dopo il 1942 sempre piu' raramente.
        $pRaccolti = str_contains($causa, 'cariche') || str_contains($causa, 'scorta') ? 0.55 : 0.2;

        $superstiti = 0;
        if ($rng->chance($pAbbandono)) {
            $usciti = (int) round($uomini * $rng->range(0.15, 0.6));
            $superstiti = (int) round($usciti * $pSopravvivenzaInAcqua);
        }

        $raccolti = $superstiti > 0 && $rng->chance($pRaccolti);

        $esito = match (true) {
            $superstiti === 0 => 'disperso',
            $raccolti         => 'prigioniero',
            default           => 'disperso',
        };

        $quadrat = Grid::toQuadrat($lat, $lon);
        $clock = World::clock();

        $testo = match ($esito) {
            'prigioniero' => sprintf(
                '%s in quadrato %s. %d uomini riescono a uscire e vengono raccolti dal nemico: '
                . 'per loro la guerra finisce in un campo di prigionia. Gli altri %d restano nel battello.',
                ucfirst($causa), $quadrat ?? '—', $superstiti, $uomini - $superstiti
            ),
            default => sprintf(
                '%s in quadrato %s. Nessun superstite: %d uomini scomparsi con il battello. '
                . 'Il BdU non ricevette piu' . "'" . ' risposta alle chiamate.',
                ucfirst($causa), $quadrat ?? '—', $uomini
            ),
        };

        // Il battello, il ruolino, la missione.
        Database::run("UPDATE boats SET state = 'perduto', speed_kn = 0, ordered_speed_kn = 0, encounter_id = NULL WHERE id = ?", [(int) $boat['id']]);
        Database::run("UPDATE crew_members SET health = 'morto' WHERE boat_id = ? AND health <> 'morto'", [(int) $boat['id']]);

        $patrol = Database::first(
            "SELECT * FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [(int) $boat['id']]
        );
        if ($patrol !== null) {
            Database::run(
                "UPDATE patrols SET state = 'perduta', returned_gts = ?, note = ? WHERE id = ?",
                [$gts, mb_substr($testo, 0, 255), (int) $patrol['id']]
            );
            \App\Sim\BoatSim::save([
                'gts' => $gts, 'kind' => 'perdita', 'severity' => 'allarme',
                'lat' => $lat, 'lon' => $lon, 'quadrat' => $quadrat, 'text' => $testo,
            ], (int) $patrol['id'], (int) $boat['id']);
        }

        // Il fascicolo del comandante si chiude.
        $cmd = $boat['commander_id'] !== null
            ? Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $boat['commander_id']])
            : self::corrente((int) $boat['user_id']);

        if ($cmd !== null) {
            if ($patrol !== null) {
                Carriera::chiudiPatrol($cmd, array_merge($patrol, ['returned_gts' => $gts]), $boat, false);
            }
            Database::run(
                'UPDATE commanders SET stato = ?, uscito_gts = ?, sorte = ?, ultimo_quadrat = ? WHERE id = ?',
                [$esito, $gts, mb_substr($testo, 0, 255), $quadrat, (int) $cmd['id']]
            );
        }

        return ['esito' => $esito, 'superstiti' => $superstiti, 'testo' => $testo];
    }

    /**
     * L'albo d'oro: i comandanti che non sono tornati, e quelli che si sono
     * congedati. Consultabile da chiunque, per sempre.
     *
     * @return list<array<string,mixed>>
     */
    public static function albo(int $limite = 50): array
    {
        return Database::all(
            "SELECT c.*, u.username FROM commanders c JOIN users u ON u.id = c.user_id
             WHERE c.stato <> 'attivo' ORDER BY c.grt_affondato DESC, c.uscito_gts ASC LIMIT " . max(1, min(200, $limite))
        );
    }

    /** Classifica dei comandanti in servizio. */
    public static function classifica(int $limite = 25): array
    {
        return Database::all(
            "SELECT c.*, u.username FROM commanders c JOIN users u ON u.id = c.user_id
             WHERE c.stato = 'attivo' ORDER BY c.grt_affondato DESC LIMIT " . max(1, min(200, $limite))
        );
    }

    /** Congedo volontario: si chiude la carriera da vivi, e resta nell'albo. */
    public static function congeda(array $cmd, int $gts): void
    {
        Database::run(
            "UPDATE commanders SET stato = 'congedato', uscito_gts = ?, sorte = ? WHERE id = ?",
            [$gts, 'Congedato dal servizio attivo su sua richiesta.', (int) $cmd['id']]
        );
    }
}
