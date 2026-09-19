<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Grid;
use App\Sim\Traffic;
use App\Sim\World;

/**
 * La stanza dei bottoni: quello che si vede dal ponte di comando del mondo.
 *
 * Qui non si simula niente. Si guarda: quante navi ci sono e di che tipo, dove
 * stanno i convogli, chi e' in mare, che tempo fa e dove. Tutto quello che
 * scrive sta nelle manopole di GameConfig e nelle forzature del meteo, che
 * hanno un posto loro.
 *
 * E' un posto solo perche' il pannello e la carta chiedono le stesse cose: se
 * i conti li facesse ciascuno per se', prima o poi direbbero numeri diversi e
 * nessuno saprebbe quale credere.
 */
final class Mondo
{
    /** Quanto lontano guarda il censimento del traffico: tutto il teatro. */
    private const TEATRO = ['lat_max' => 72.0, 'lat_min' => 20.0, 'lon_min' => -80.0, 'lon_max' => 30.0];

    /**
     * Il quadro d'insieme: i numeri che si guardano per primi.
     *
     * @return array<string,mixed>
     */
    public static function quadro(): array
    {
        $gts = World::now();
        $uno = static fn (string $sql, array $a = []): int
            => (int) (Database::first($sql, $a)['n'] ?? 0);

        $navi = $uno("SELECT COUNT(*) n FROM ships WHERE state = 'in_mare'");
        $inConvoglio = $uno("SELECT COUNT(*) n FROM ships WHERE state = 'in_mare' AND convoy_id IS NOT NULL");

        return [
            'gts'        => $gts,
            'seme'       => World::seed(),
            'navi'       => $navi,
            'isolate'    => $navi - $inConvoglio,
            'in_convoglio' => $inConvoglio,
            'scorte'     => $uno("SELECT COUNT(*) n FROM ships WHERE state = 'in_mare' AND ruolo = 'scorta'"),
            'convogli'   => $uno("SELECT COUNT(*) n FROM convoys WHERE state = 'in_mare'"),
            'affondate'  => $uno("SELECT COUNT(*) n FROM ships WHERE state = 'affondata'"),
            'grt_in_mare' => (int) (Database::first("SELECT COALESCE(SUM(grt),0) n FROM ships WHERE state = 'in_mare'")['n'] ?? 0),
            'battelli'   => $uno("SELECT COUNT(*) n FROM boats WHERE state <> 'perduto'"),
            'in_mare'    => $uno("SELECT COUNT(*) n FROM boats WHERE state = 'mare'"),
            'in_base'    => $uno("SELECT COUNT(*) n FROM boats WHERE state = 'base'"),
            'perduti'    => $uno("SELECT COUNT(*) n FROM boats WHERE state = 'perduto'"),
            'incontri'   => $uno("SELECT COUNT(*) n FROM encounters WHERE stato <> 'concluso'"),
            'siluri'     => $uno("SELECT COUNT(*) n FROM torpedo_runs WHERE esito = 'in_corsa'"),
            'branchi'    => $uno("SELECT COUNT(*) n FROM wolfpacks WHERE stato <> 'sciolto'"),
            'ordini'     => $uno("SELECT COUNT(*) n FROM bdu_orders WHERE stato = 'aperto'"),
            'rendezvous' => $uno("SELECT COUNT(*) n FROM rendezvous WHERE stato = 'fissato' AND scadenza_gts >= ?", [$gts]),
            'posta'      => $uno("SELECT COUNT(*) n FROM mail_queue WHERE inviato_at IS NULL AND rinunciato_at IS NULL"),
        ];
    }

    /**
     * Censimento del naviglio in mare, per come lo si vuole guardare.
     *
     * @return array{classi:list<array<string,mixed>>, bandiere:list<array<string,mixed>>, ruoli:list<array<string,mixed>>, rotte:list<array<string,mixed>>}
     */
    public static function censimento(): array
    {
        $per = static fn (string $campo, int $limite = 40): array => Database::all(
            "SELECT {$campo} AS chiave, COUNT(*) n, COALESCE(SUM(grt),0) grt
               FROM ships WHERE state = 'in_mare'
              GROUP BY {$campo} ORDER BY n DESC LIMIT {$limite}"
        );

        $classi = [];
        foreach ($per('class_key') as $r) {
            $cls = Traffic::classe((string) $r['chiave']);
            $classi[] = [
                'chiave' => (string) $r['chiave'],
                'nome'   => (string) ($cls['name'] ?? $r['chiave']),
                'ruolo'  => (string) ($cls['kind'] ?? '—'),
                'n'      => (int) $r['n'],
                'grt'    => (int) $r['grt'],
            ];
        }

        return [
            'classi'   => $classi,
            'bandiere' => array_map(static fn (array $r): array => [
                'chiave' => (string) $r['chiave'], 'n' => (int) $r['n'], 'grt' => (int) $r['grt'],
            ], $per('flag')),
            'ruoli'    => array_map(static fn (array $r): array => [
                'chiave' => (string) $r['chiave'], 'n' => (int) $r['n'], 'grt' => (int) $r['grt'],
            ], $per('ruolo')),
            'rotte'    => array_map(static fn (array $r): array => [
                'chiave' => (string) $r['chiave'],
                'nome'   => Traffic::nomeRotta((string) $r['chiave']),
                'n'      => (int) $r['n'],
                'grt'    => (int) $r['grt'],
            ], $per('rotta_key', 24)),
        ];
    }

    /**
     * Tutto quello che galleggia, con la posizione di adesso.
     *
     * E' il pasto della carta ammiraglia. Le posizioni del traffico sono
     * funzione pura della rotta e del tempo (Traffic::posizione): non stanno
     * in tabella e si calcolano qui.
     *
     * @return array{navi:list<array<string,mixed>>, convogli:list<array<string,mixed>>, battelli:list<array<string,mixed>>}
     */
    public static function scacchiera(?int $gts = null): array
    {
        $gts ??= World::now();

        $convogli = [];
        foreach (Database::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo <> 'scorta') merci,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo = 'scorta') scorte
               FROM convoys c WHERE c.state = 'in_mare' AND c.departed_gts <= ? AND c.eta_gts >= ?",
            [$gts, $gts]
        ) as $c) {
            $p = Traffic::posizione((string) $c['rotta_key'], (float) $c['speed_kn'], (int) $c['departed_gts'], $gts, (float) $c['deviazione']);
            if ($p === null) {
                continue;
            }
            $convogli[] = [
                'id'      => (int) $c['id'],
                'nome'    => (string) $c['serie'] . ' ' . (string) $c['numero'],
                'lat'     => round($p['lat'], 4),
                'lon'     => round($p['lon'], 4),
                'rotta'   => round((float) $p['heading'], 1),
                'nodi'    => (float) $c['speed_kn'],
                'merci'   => (int) $c['merci'],
                'scorte'  => (int) $c['scorte'],
                'affondate' => (int) $c['affondate'],
                'quadrat' => Grid::toQuadrat($p['lat'], $p['lon']) ?? '—',
                'via'     => Traffic::nomeRotta((string) $c['rotta_key']),
            ];
        }

        $navi = [];
        foreach (Database::all(
            "SELECT id, name, flag, class_key, ruolo, rotta_key, speed_kn, departed_gts, grt, integrita, ritardataria, convoy_id
               FROM ships WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? AND convoy_id IS NULL",
            [$gts, $gts]
        ) as $s) {
            $p = Traffic::posizione((string) $s['rotta_key'], (float) $s['speed_kn'], (int) $s['departed_gts'], $gts);
            if ($p === null) {
                continue;
            }
            $cls = Traffic::classe((string) $s['class_key']);
            $navi[] = [
                'id'     => (int) $s['id'],
                'nome'   => (string) $s['name'],
                'bandiera' => (string) $s['flag'],
                'classe' => (string) ($cls['name'] ?? $s['class_key']),
                'chiave' => (string) $s['class_key'],
                'ruolo'  => (string) $s['ruolo'],
                'lat'    => round($p['lat'], 4),
                'lon'    => round($p['lon'], 4),
                'rotta'  => round((float) $p['heading'], 1),
                'nodi'   => (float) $s['speed_kn'],
                'grt'    => (int) $s['grt'],
                'integrita' => (float) $s['integrita'],
                'ferita' => (float) $s['integrita'] < 92.0,
            ];
        }

        $battelli = [];
        foreach (Database::all(
            "SELECT b.*, c.nome AS comandante, u.username
               FROM boats b LEFT JOIN commanders c ON c.id = b.commander_id
               LEFT JOIN users u ON u.id = b.user_id
              WHERE b.state <> 'perduto'"
        ) as $b) {
            $battelli[] = [
                'id'      => (int) $b['id'],
                'numero'  => (string) $b['uboat_number'],
                'tipo'    => (string) $b['type_key'],
                'comandante' => (string) ($b['comandante'] ?? '—'),
                'account' => (string) ($b['username'] ?? '—'),
                'stato'   => (string) $b['state'],
                'modo'    => (string) $b['mode'],
                'lat'     => round((float) $b['lat'], 4),
                'lon'     => round((float) $b['lon'], 4),
                'rotta'   => round((float) $b['heading'], 1),
                'nodi'    => (float) $b['speed_kn'],
                'quota'   => (float) $b['depth_m'],
                'nafta'   => (float) $b['fuel_t'],
                'batteria' => (float) $b['battery_pct'],
                'scafo'   => (float) $b['hull_integrity'],
                'flottiglia' => (string) $b['flotilla'],
                'incontro' => $b['encounter_id'] !== null,
                'quadrat' => Grid::toQuadrat((float) $b['lat'], (float) $b['lon']) ?? '—',
            ];
        }

        return ['navi' => $navi, 'convogli' => $convogli, 'battelli' => $battelli];
    }

    /**
     * Il tempo su una maglia larga, per stenderlo sulla carta.
     *
     * Il passo e' in gradi. Piu' e' fitto, piu' costa: il meteo e' una
     * funzione, e ogni punto e' un conto.
     *
     * @return list<array<string,mixed>>
     */
    public static function grigliaMeteo(float $passo = 5.0, ?int $gts = null): array
    {
        $gts ??= World::now();
        $passo = max(2.0, min(15.0, $passo));
        $out = [];

        for ($lat = self::TEATRO['lat_min']; $lat <= self::TEATRO['lat_max']; $lat += $passo) {
            for ($lon = self::TEATRO['lon_min']; $lon <= self::TEATRO['lon_max']; $lon += $passo) {
                $m = World::weather($lat, $lon, $gts);
                $out[] = [
                    'lat'  => $lat,
                    'lon'  => $lon,
                    'dir'  => round((float) $m['wind_dir'], 0),
                    'kn'   => round((float) $m['wind_kn'], 1),
                    'mare' => (int) $m['sea_state'],
                    'vis'  => round((float) $m['visibility_nm'], 1),
                    'neb'  => (bool) $m['fog'],
                    'hpa'  => round((float) $m['pressure_hpa'], 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Il tempo in un punto, con la luce: e' la scheda che si apre cliccando.
     *
     * @return array<string,mixed>
     */
    public static function meteoIn(float $lat, float $lon, ?int $gts = null): array
    {
        $gts ??= World::now();
        $m = World::weather($lat, $lon, $gts);
        $cielo = World::sky($lat, $lon, $gts, (float) $m['cloud']);

        return $m + [
            'lat'      => $lat,
            'lon'      => $lon,
            'quadrat'  => Grid::toQuadrat($lat, $lon) ?? '—',
            'luce'     => round((float) $cielo['luce'], 3),
            'giorno'   => (float) $cielo['luce'] > 0.45,
            'ora'      => World::clock()->format($gts),
            'forzato'  => Meteo::forzaturaIn($lat, $lon, $gts) !== null,
        ];
    }

    /**
     * Gli incontri aperti, che sono la cosa piu' delicata che c'e' in mare.
     *
     * @return list<array<string,mixed>>
     */
    public static function incontri(): array
    {
        return Database::all(
            "SELECT e.id, e.stato, e.allarme, e.affondate, e.grt_affondato, e.cariche_subite,
                    e.last_step_gts, b.uboat_number, c.nome AS comandante
               FROM encounters e
               LEFT JOIN boats b ON b.id = e.boat_id
               LEFT JOIN commanders c ON c.id = b.commander_id
              WHERE e.stato <> 'concluso' ORDER BY e.id DESC"
        );
    }

    /**
     * Il lavoro di un battito, detto in italiano invece che in JSON.
     *
     * Il pannello stampava la riga grezza — {"comunicati":0,"aree_assegnate":0,
     * ...} — tagliata a centoventi caratteri e larga quanto una parola sola,
     * che in una tabella vuol dire una colonna che sfonda il pannello. Ma il
     * guaio vero non era la larghezza: era che per sapere se un battito aveva
     * fatto qualcosa bisognava leggere una ventina di zeri.
     *
     * Qui si dice solo quello che e' successo, con le parole giuste, e quando
     * non e' successo niente lo si dice: e' l'esito normale, non un vuoto.
     *
     * @return array{testo:string, niente:bool, grezzo:string}
     */
    public static function lavoroBattito(?string $json): array
    {
        $grezzo = (string) $json;
        $d = json_decode($grezzo, true);
        if (!is_array($d)) {
            return ['testo' => '—', 'niente' => true, 'grezzo' => $grezzo];
        }

        // Singolare e plurale: "1 convoglio nuovo" e "3 convogli nuovi".
        $voci = [
            'navi_nuove'        => ['nave salpata', 'navi salpate'],
            'convogli_nuovi'    => ['convoglio salpato', 'convogli salpati'],
            'arrivati'          => ['arrivo in porto', 'arrivi in porto'],
            'potati_navi'       => ['nave tolta dal mare', 'navi tolte dal mare'],
            'potati_convogli'   => ['convoglio tolto', 'convogli tolti'],
            'potati_battiti'    => ['battito potato', 'battiti potati'],
            'potati_radio'      => ['trasmissione senza mittente rimossa', 'trasmissioni senza mittente rimosse'],
            'potate_entita'     => ['nave tolta da un incontro chiuso', 'navi tolte da incontri chiusi'],
            'potate_corse'      => ['corsa di siluro archiviata', 'corse di siluro archiviate'],
            'potati_contatti'   => ['contatto spento rimosso', 'contatti spenti rimossi'],
            'battelli'          => ['battello avanzato', 'battelli avanzati'],
            'incontri'          => ['incontro seguito', 'incontri seguiti'],
            'eventi'            => ['evento', 'eventi'],
            'guasti'            => ['avaria', 'avarie'],
            'agonia_navi'       => ['nave in agonia affondata', 'navi in agonia affondate'],
            'convogli_dispersi' => ['convoglio disperso', 'convogli dispersi'],
            'branchi_aperti'    => ['branco aperto', 'branchi aperti'],
            'aree_assegnate'    => ['area assegnata', 'aree assegnate'],
            'comunicati'        => ['comunicato del BdU', 'comunicati del BdU'],
            'posta_inviata'     => ['messaggio spedito', 'messaggi spediti'],
            'errori'            => ['errore', 'errori'],
        ];

        $pezzi = [];
        foreach ($voci as $chiave => [$uno, $tanti]) {
            $n = (int) ($d[$chiave] ?? 0);
            if ($n > 0) {
                $pezzi[] = $n . ' ' . ($n === 1 ? $uno : $tanti);
            }
        }

        // Le miglia percorse dai battelli sono una misura, non un conteggio.
        $miglia = (float) ($d['miglia'] ?? 0);
        if ($miglia >= 0.1) {
            $pezzi[] = number_format($miglia, 1, ',', '.') . ' miglia percorse';
        }
        $grt = (int) ($d['agonia_grt'] ?? 0);
        if ($grt > 0) {
            $pezzi[] = number_format($grt, 0, ',', '.') . ' GRT andati a fondo';
        }
        $coda = (int) ($d['posta_in_coda'] ?? 0);
        if ($coda > 0) {
            $pezzi[] = $coda . ' in coda di posta';
        }

        return [
            'testo'   => $pezzi === [] ? 'niente da fare' : implode(' · ', $pezzi),
            'niente'  => $pezzi === [],
            'grezzo'  => $grezzo,
        ];
    }

    /**
     * Le manopole, raggruppate per area invece che in un elenco unico.
     *
     * Quarantuno chiavi in fila alfabetica sono un elenco; divise per area
     * sono un pannello. Il gruppo si ricava dal prefisso della chiave, che
     * gia' c'era: traffic.*, combat.*, detect.* e cosi' via.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function manopole(): array
    {
        $titoli = [
            'world'   => 'Mondo e tempo',
            'traffic' => 'Traffico alleato',
            'combat'  => 'Combattimento',
            'detect'  => 'Sensori',
            'damage'  => 'Danno',
            'radio'   => 'Radio e HF/DF',
            'carriera' => 'Carriera',
            'branco'  => 'Branchi',
            'crew'    => 'Equipaggio',
            'heat'    => 'Calore del settore',
            'iwo'     => 'Primo ufficiale',
            'nav'     => 'Navigazione',
            'rifornimento' => 'Rifornimento',
            'auth'    => 'Accesso',
            'limits'  => 'Freni antiabuso',
            'mail'    => 'Posta',
            'app'     => 'Applicazione',
        ];

        $gruppi = [];
        foreach (GameConfig::all() as $k => $v) {
            $pre = str_contains($k, '.') ? substr($k, 0, strpos($k, '.')) : 'altro';
            $gruppi[$pre] ??= [];
            $gruppi[$pre][] = ['chiave' => $k, 'valore' => $v['value'], 'tipo' => $v['type'], 'nota' => $v['note'] ?? ''];
        }

        $out = [];
        foreach ($titoli as $pre => $titolo) {
            if (isset($gruppi[$pre])) {
                $out[$titolo] = $gruppi[$pre];
                unset($gruppi[$pre]);
            }
        }
        foreach ($gruppi as $pre => $righe) {
            $out[ucfirst($pre)] = $righe;
        }

        return $out;
    }
}
