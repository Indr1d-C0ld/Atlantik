<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;

/**
 * I contatti: quello che l'equipaggio crede di sapere.
 *
 * Un contatto non e' un'unita' nemica: e' una RIGA NEL RAPPORTO di un uomo che
 * ha sentito qualcosa all'idrofono o visto qualcosa all'orizzonte. Ha un
 * rilevamento, una distanza stimata con errore, una classificazione incerta, e
 * puo' essere perso. La posizione vera del bersaglio non entra mai qui.
 */
final class Contacts
{
    /**
     * Dopo quanto tempo senza conferme un contatto si considera perso.
     * Tre ore: un contatto lontano e intermittente non si "perde" perche' per
     * mezz'ora il rumore e' sceso sotto la soglia — lo si perde quando smette
     * davvero di sentirsi.
     */
    public const SCADENZA_S = 10800;

    /**
     * Quanto si puo' arrivare a essere sicuri, secondo il sensore.
     * All'idrofono non si e' mai sicuri: si sente un rumore, non si vede una nave.
     */
    private static function certezzaMassima(string $sensore): float
    {
        return match ($sensore) {
            'vista'    => 0.97,
            'radar'    => 0.90,
            'idrofono' => 0.72,
            'fumo'     => 0.45,
            default    => 0.55,
        };
    }

    /**
     * Registra o aggiorna un contatto.
     *
     * @param array<string,mixed> $dati
     */
    public static function upsert(int $boatId, ?int $patrolId, array $dati, int $gts, Rng $rng): array
    {
        $kind    = (string) $dati['kind'];
        $shipId  = $kind === 'nave' ? (int) $dati['id'] : null;
        $convoyId = $kind === 'convoglio' ? (int) $dati['id'] : null;

        $esistente = Database::first(
            'SELECT * FROM contacts WHERE boat_id = ? AND ' .
            ($shipId !== null ? 'ship_id = ?' : 'convoy_id = ?') . ' ORDER BY id DESC LIMIT 1',
            [$boatId, $shipId ?? $convoyId]
        );

        // All'idrofono la distanza non si misura: si sente un rilevamento. Una
        // stima si azzarda solo quando il rumore e' forte (quindi vicino), e
        // resta grossolana. La distanza vera si ricava pedinando: e' il mestiere
        // del Fuehlungshalter, e arriva con le ore di ascolto.
        $sensore = (string) $dati['sensore'];
        $conDistanza = $sensore !== 'idrofono' || (float) ($dati['snr'] ?? 0) > 18.0;

        $errore = Detection::erroreDistanza((float) $dati['distanza'], $sensore, $rng);
        $distStimata = $conDistanza
            ? max(0.2, (float) $dati['distanza'] + $errore * ($rng->chance(0.5) ? 1 : -1))
            : null;
        $rilevamento = Geo::normBearing((float) $dati['bearing'] + $rng->gauss() * self::erroreRilevamento((string) $dati['sensore']));

        // Posizione stimata del contatto, a partire dalla NOSTRA posizione
        // stimata: l'errore di navigazione si somma a quello del sensore.
        // Senza distanza, il contatto si segna sulla carta a una distanza di
        // comodo lungo il rilevamento: e' cosi' che si tracciava, sapendo che
        // il punto e' sbagliato ma la direzione no.
        $distCarta = $distStimata ?? min(40.0, max(8.0, (float) $dati['distanza']));
        [$latEst, $lonEst] = Geo::destination((float) $dati['est_lat'], (float) $dati['est_lon'], $rilevamento, $distCarta);

        if ($esistente !== null && !((bool) $esistente['perso']) && $gts - (int) $esistente['last_gts'] < self::SCADENZA_S) {
            // Contatto gia' in mano: si affina. Piu' a lungo lo si tiene, meglio
            // si stimano rotta e velocita' — e' il lavoro del Fuehlungshalter.
            $dt = max(1, $gts - (int) $esistente['last_gts']);
            $rottaStimata = Geo::bearing((float) $esistente['lat_est'], (float) $esistente['lon_est'], $latEst, $lonEst);
            $distPercorsa = Geo::distanceNm((float) $esistente['lat_est'], (float) $esistente['lon_est'], $latEst, $lonEst);
            $velStimata = $distPercorsa / ($dt / 3600.0);

            // La certezza cresce quanto piu' il contatto e' netto: un fruscio
            // ascoltato per ore resta un fruscio.
            $qualita = max(0.12, min(1.0, ((float) ($dati['snr'] ?? 12.0) - 5.0) / 20.0));
            if (in_array((string) $dati['sensore'], ['vista', 'radar'], true)) {
                $qualita = 1.0;
            }
            $certezza = min(
                self::certezzaMassima((string) $dati['sensore']),
                (float) $esistente['certezza'] + 0.06 * $qualita
            );

            Database::run(
                'UPDATE contacts SET last_gts = ?, bearing = ?, range_nm = ?, range_err_nm = ?,
                        course_est = ?, speed_est = ?, certezza = ?, lat_est = ?, lon_est = ?,
                        sensore = ?, classe_est = COALESCE(?, classe_est),
                        classe_key_est = COALESCE(?, classe_key_est),
                        navi_stimate = COALESCE(?, navi_stimate), perso = 0
                 WHERE id = ?',
                [
                    $gts, round($rilevamento, 1), $distStimata !== null ? round($distStimata, 2) : null, $errore,
                    $velStimata > 0.5 && $velStimata < 30 ? round($rottaStimata, 1) : $esistente['course_est'],
                    $velStimata > 0.5 && $velStimata < 30 ? round($velStimata, 1) : $esistente['speed_est'],
                    round($certezza, 3), round($latEst, 5), round($lonEst, 5),
                    (string) $dati['sensore'], $dati['classe_est'] ?? null,
                    $dati['classe_key_est'] ?? null, $dati['navi'] ?? null,
                    (int) $esistente['id'],
                ]
            );
            return ['id' => (int) $esistente['id'], 'nuovo' => false];
        }

        Database::run(
            'INSERT INTO contacts (boat_id, patrol_id, target_kind, ship_id, convoy_id, sensore, first_gts, last_gts,
                                   bearing, range_nm, range_err_nm, classe_est, classe_key_est,
                                   certezza, navi_stimate, lat_est, lon_est)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $boatId, $patrolId, $kind === 'convoglio' ? 'convoglio' : ($kind === 'aereo' ? 'aereo' : 'nave'),
                $shipId, $convoyId, (string) $dati['sensore'], $gts, $gts,
                round($rilevamento, 1), $distStimata !== null ? round($distStimata, 2) : null, $errore,
                $dati['classe_est'] ?? null, $dati['classe_key_est'] ?? null,
                min(0.35, self::certezzaMassima((string) $dati['sensore'])), $dati['navi'] ?? null,
                round($latEst, 5), round($lonEst, 5),
            ]
        );
        return ['id' => Database::lastInsertId(), 'nuovo' => true];
    }

    /**
     * Marca come persi i contatti che non si confermano da troppo tempo.
     *
     * Torna la descrizione di quelli persi adesso, perche' un contatto che si
     * spegne va scritto nel giornale: e' un fatto, e spesso e' IL fatto — un
     * convoglio agganciato e poi perduto e' la differenza fra una crociera e
     * niente. Prima si spegnevano in silenzio, e il comandante se ne accorgeva
     * solo guardando una tabella vuota.
     *
     * @return list<string>
     */
    public static function scadi(int $boatId, int $gts): array
    {
        $soglia = $gts - self::SCADENZA_S;
        $persi = Database::all(
            'SELECT target_kind, classe_est FROM contacts
             WHERE boat_id = ? AND perso = 0 AND last_gts < ?',
            [$boatId, $soglia]
        );
        if ($persi === []) {
            return [];
        }

        Database::run(
            'UPDATE contacts SET perso = 1 WHERE boat_id = ? AND perso = 0 AND last_gts < ?',
            [$boatId, $soglia]
        );

        $fuori = [];
        foreach ($persi as $p) {
            $classe = trim((string) ($p['classe_est'] ?? ''));
            $fuori[] = $classe !== '' && !str_starts_with($classe, 'segnalazione')
                ? $classe
                : match ((string) $p['target_kind']) {
                    'convoglio' => 'il convoglio',
                    'aereo'     => 'l\'aereo',
                    default     => 'la nave',
                };
        }

        return $fuori;
    }

    /** @return list<array<string,mixed>> */
    public static function attivi(int $boatId, int $limite = 30): array
    {
        return Database::all(
            'SELECT * FROM contacts WHERE boat_id = ? AND perso = 0 ORDER BY last_gts DESC LIMIT ' . max(1, min(100, $limite)),
            [$boatId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recenti(int $boatId, int $limite = 40): array
    {
        return Database::all(
            'SELECT * FROM contacts WHERE boat_id = ? ORDER BY last_gts DESC LIMIT ' . max(1, min(100, $limite)),
            [$boatId]
        );
    }

    private static function erroreRilevamento(string $sensore): float
    {
        return match ($sensore) {
            'vista'    => 1.5,
            'radar'    => 1.0,
            'idrofono' => 4.5,     // l'idrofono da' il rilevamento a occhio e croce
            'fumo'     => 3.0,
            default    => 5.0,
        };
    }
}
