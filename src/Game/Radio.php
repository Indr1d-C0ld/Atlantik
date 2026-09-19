<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\BoatSim;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Rng;
use App\Sim\Sectors;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * La radio: l'unico modo di parlare col BdU e con gli altri battelli, e il
 * modo piu' rapido di farsi trovare.
 *
 * RICEZIONE quasi gratuita: gli ordini arrivano sulle onde lunghissime e si
 * ricevono anche restando immersi a venti metri. TRASMETTERE e' un'altra cosa:
 * bisogna emergere (o alzare l'antenna), la trasmissione dura un tempo
 * proporzionale alla lunghezza del messaggio, e in quel tempo ogni scorta con
 * l'HF/DF prende un rilevamento. Due rilevamenti fanno un punto, e su quel
 * punto arriva qualcuno.
 *
 * I **Kurzsignale** esistono esattamente per questo: messaggi cifrati brevi,
 * venti-trenta secondi di antenna invece di cinque minuti. Sono l'opzione
 * saggia, e l'interfaccia lo dice.
 */
final class Radio
{
    /** Segnali brevi standard: poche parole, pochi secondi di antenna. */
    public const KURZSIGNALE = [
        'contatto'     => ['testo' => 'Contatto con convoglio', 'durata' => 28, 'tipo' => 'contatto'],
        'perso'        => ['testo' => 'Perso il contatto',       'durata' => 22, 'tipo' => 'kurzsignal'],
        'meteo'        => ['testo' => 'Rapporto meteorologico',  'durata' => 34, 'tipo' => 'meteo'],
        'consumo'      => ['testo' => 'Situazione nafta e siluri', 'durata' => 26, 'tipo' => 'kurzsignal'],
        'attacco'      => ['testo' => 'Attacco eseguito',        'durata' => 24, 'tipo' => 'kurzsignal'],
        'rifornimento' => ['testo' => 'Richiesta di rifornimento', 'durata' => 32, 'tipo' => 'kurzsignal'],
    ];

    /**
     * Trasmette.
     *
     * @return array{ok:bool, error?:string, message_id?:int, durata?:int, fix?:array|null, testo?:string}
     */
    public static function trasmetti(array $boat, string $tipo, string $testoLibero = '', ?string $kurz = null): array
    {
        if ((string) $boat['state'] !== 'mare') {
            return ['ok' => false, 'error' => 'Si trasmette dal mare, non dal bunker.'];
        }
        if ((string) $boat['mode'] !== 'superficie') {
            return ['ok' => false, 'error' => 'Per trasmettere serve l\'antenna fuori: bisogna emergere.'];
        }

        $radio = Database::first("SELECT * FROM boat_systems WHERE boat_id = ? AND skey = 'radio'", [(int) $boat['id']]);
        if ($radio !== null && (string) $radio['state'] !== 'ok') {
            return ['ok' => false, 'error' => 'La stazione radio e\' in avaria.'];
        }

        $gts = World::now();
        $lat = (float) $boat['lat'];
        $lon = (float) $boat['lon'];
        $quadrat = Grid::toQuadrat($lat, $lon);

        if ($kurz !== null && isset(self::KURZSIGNALE[$kurz])) {
            $k = self::KURZSIGNALE[$kurz];
            $durata = (int) $k['durata'];
            $tipoMsg = (string) $k['tipo'];
            $testo = $k['testo'] . ' — quadrato ' . ($quadrat ?? '—') . '.';
            if ($kurz === 'consumo') {
                $inv = \App\Sim\Torpedo::inventario((int) $boat['id']);
                $testo .= sprintf(' Nafta %.0f t, siluri %d.', (float) $boat['fuel_t'], $inv['tubi'] + $inv['riserve']);
            }
            if ($kurz === 'meteo') {
                $m = World::weather($lat, $lon, $gts);
                $testo .= sprintf(' Vento %s forza %d, mare %d, visibilita\' %.0f nm, %.0f hPa.',
                    \App\Sim\Weather::rosa((float) $m['wind_dir']), (int) $m['beaufort'],
                    (int) $m['sea_state'], (float) $m['visibility_nm'], (float) $m['pressure_hpa']);
            }
        } else {
            $testo = mb_substr(trim($testoLibero), 0, 480);
            if ($testo === '') {
                return ['ok' => false, 'error' => 'Messaggio vuoto.'];
            }
            // Un messaggio lungo e' un messaggio pericoloso: circa un secondo
            // di antenna ogni tre caratteri, piu' cifratura e procedura.
            $durata = (int) max(45, min(420, 40 + mb_strlen($testo) / 3));
            $tipoMsg = $tipo === 'contatto' ? 'contatto' : 'rapporto';
        }

        $branco = Branco::corrente((int) $boat['id']);
        Database::run(
            'INSERT INTO radio_messages (boat_id, commander_id, destinatario, wolfpack_id, tipo, testo, quadrat,
                                         lat, lon, gts, durata_s)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $boat['id'], $boat['commander_id'] !== null ? (int) $boat['commander_id'] : null,
                $tipoMsg === 'contatto' ? 'branco' : 'bdu',
                $branco !== null ? (int) $branco['id'] : null,
                $tipoMsg, $testo, $quadrat, $lat, $lon, $gts, $durata,
            ]
        );
        $msgId = Database::lastInsertId();
        Database::run('UPDATE boats SET radio_ultima_gts = ? WHERE id = ?', [$gts, (int) $boat['id']]);

        // --- e adesso si paga il conto ---------------------------------------
        $fix = self::radiogoniometria($boat, $msgId, $durata, $gts);

        // Una segnalazione di contatto e' utile agli altri: si premia chi la fa.
        if ($tipoMsg === 'contatto' && $branco !== null) {
            Branco::segnalaContatto($branco, $boat, $lat, $lon, $gts);
        }

        $patrol = Database::first(
            "SELECT id FROM patrols WHERE boat_id = ? AND state = 'in_corso' ORDER BY id DESC LIMIT 1",
            [(int) $boat['id']]
        );
        if ($patrol !== null) {
            BoatSim::save([
                'gts' => $gts, 'kind' => 'radio', 'severity' => 'info',
                'lat' => $lat, 'lon' => $lon, 'quadrat' => $quadrat,
                'text' => sprintf('Trasmesso (%d secondi di antenna): %s', $durata, $testo)
                    . ($fix !== null ? ' — il Funkmaat non ha modo di saperlo, ma qualcuno stava ascoltando.' : ''),
            ], (int) $patrol['id'], (int) $boat['id']);
        }

        return ['ok' => true, 'message_id' => $msgId, 'durata' => $durata, 'fix' => $fix, 'testo' => $testo];
    }

    /**
     * Chi ha sentito, e con quanta precisione.
     *
     * Ogni scorta con apparato HF/DF entro il raggio da' un rilevamento; le
     * stazioni costiere alleate ne aggiungono un altro. Con un solo
     * rilevamento si ha una direzione e basta; con due o piu' si ha un punto,
     * e la precisione migliora col numero.
     *
     * @return array<string,mixed>|null
     */
    private static function radiogoniometria(array $boat, int $msgId, int $durataS, int $gts): ?array
    {
        $rng = Rng::for(World::seed(), 'hfdf', (int) $boat['id'], $gts);
        $lat = (float) $boat['lat'];
        $lon = (float) $boat['lon'];
        $raggio = (float) GameConfig::int('radio.hfdf_raggio_nm', 160);

        // Prendere un rilevamento su un'emissione di venti secondi non e'
        // scontato: l'operatore deve accorgersene, ruotare il goniometro e
        // leggere prima che il segnale finisca. E' esattamente per questo che
        // esistevano i Kurzsignale — non rendevano invisibili, rendevano
        // difficili. Sopra i due minuti di antenna, invece, non scappa nessuno.
        $pPerAscoltatore = max(0.3, min(0.95, 0.3 + $durataS / 130.0));

        $ascoltatori = 0;
        foreach (Traffic::nearby($lat, $lon, $raggio, $gts) as $u) {
            $apparati = 0;
            if ((string) $u['kind'] === 'convoglio') {
                // Un convoglio scortato porta quasi sempre almeno un apparato.
                $apparati = min(2, (int) ceil((int) $u['scorte'] / 3));
            } elseif ((int) (Traffic::classe((string) $u['classe'])['hfdf'] ?? 0) === 1) {
                $apparati = 1;
            }
            for ($i = 0; $i < $apparati; $i++) {
                if ($rng->chance($pPerAscoltatore)) {
                    $ascoltatori++;
                }
            }
        }

        // Stazioni costiere: sempre in ascolto, e piu' a lungo trasmetti piu'
        // e' probabile che ti aggancino.
        $pCostiero = (float) GameConfig::get('radio.hfdf_costiero', 0.35) * min(2.5, $durataS / 60.0);
        if ($rng->chance(min(0.9, $pCostiero))) {
            $ascoltatori++;
        }

        if ($ascoltatori < 1) {
            return null;
        }

        // Con un solo rilevamento non c'e' un punto: c'e' una direzione, e la
        // caccia parte lo stesso ma alla cieca.
        $errore = match (true) {
            $ascoltatori >= 3 => $rng->range(8.0, 25.0),
            $ascoltatori === 2 => $rng->range(20.0, 60.0),
            default => $rng->range(60.0, 140.0),
        };
        // Trasmissioni lunghe si triangolano meglio.
        $errore *= max(0.55, 1.3 - $durataS / 300.0);

        [$flat, $flon] = Geo::destination($lat, $lon, $rng->range(0, 360), $errore * $rng->range(0.2, 1.0));
        $quadrat = Grid::toQuadrat($flat, $flon);

        $reazione = match (true) {
            $ascoltatori >= 3 => 'Gruppo di caccia dirottato sul punto.',
            $ascoltatori === 2 => 'Pattugliamento aereo intensificato nella zona.',
            default => 'Rilevamento isolato: allerta generica.',
        };

        Database::run(
            'INSERT INTO hfdf_fixes (boat_id, message_id, gts, rilevamenti, errore_nm, lat, lon, quadrat, reazione)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $boat['id'], $msgId, $gts, $ascoltatori, round($errore, 2), $flat, $flon, $quadrat, $reazione]
        );
        Database::run('UPDATE radio_messages SET intercettato = 1 WHERE id = ?', [$msgId]);

        // Il settore si scalda: e' cosi' che il mare diventa pericoloso.
        $calore = (float) GameConfig::get('radio.heat_per_fix', 18.0) * min(2.0, $ascoltatori / 2.0);
        Sectors::add($flat, $flon, $calore, $gts, 'punto radiogoniometrico');

        return [
            'rilevamenti' => $ascoltatori,
            'errore_nm'   => round($errore, 1),
            'quadrat'     => $quadrat,
            'reazione'    => $reazione,
        ];
    }

    /**
     * Messaggi ricevibili da un battello: comunicati del BdU, ordini, e quello
     * che dicono i compagni di branco.
     *
     * @return list<array<string,mixed>>
     */
    public static function inArrivo(array $boat, int $limite = 30): array
    {
        $branco = Branco::corrente((int) $boat['id']);
        return Database::all(
            'SELECT r.*, b.uboat_number AS mittente
             FROM radio_messages r LEFT JOIN boats b ON b.id = r.boat_id
             WHERE (r.destinatario IN ("tutti") )
                OR (r.destinatario = "bdu" AND r.boat_id IS NULL)
                OR (r.destinatario = "branco" AND r.wolfpack_id = ?)
                OR (r.destinatario = "battello" AND r.dest_boat_id = ?)
                OR (r.boat_id = ?)
             ORDER BY r.gts DESC LIMIT ' . max(1, min(100, $limite)),
            [$branco !== null ? (int) $branco['id'] : 0, (int) $boat['id'], (int) $boat['id']]
        );
    }

    /** I punti radiogoniometrici presi sulle nostre trasmissioni. */
    public static function fixSubiti(int $boatId, int $limite = 10): array
    {
        return Database::all(
            'SELECT * FROM hfdf_fixes WHERE boat_id = ? ORDER BY gts DESC LIMIT ' . max(1, min(50, $limite)),
            [$boatId]
        );
    }
}
