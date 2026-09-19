<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * Siluri: soluzione di tiro, corsa, difetti, danno.
 *
 * Il siluro non insegue il bersaglio (tranne il T5): corre dritto lungo la
 * direzione impostata. Se la soluzione era sbagliata, il siluro passa dietro o
 * davanti — e l'errore non e' del motore, e' del comandante. Per questo la
 * parte importante non e' la corsa, e' il calcolo: rilevamento, angolo sulla
 * prua del bersaglio, distanza e velocita'. Sbagliare di due nodi la velocita'
 * a duemila metri vuol dire mancare.
 */
final class Torpedo
{
    /** Tempo di ricarica di un tubo, in secondi di gioco (interno, con mare calmo). */
    public const RICARICA_S = 900;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $tipi = null;

    /** @return array<string,array<string,mixed>> */
    public static function tipi(): array
    {
        if (self::$tipi !== null) {
            return self::$tipi;
        }
        $out = [];
        foreach (Database::all('SELECT * FROM torpedo_types ORDER BY unlock_rank, tkey') as $r) {
            $out[(string) $r['tkey']] = $r;
        }
        return self::$tipi = $out;
    }

    /** @return array<string,mixed> */
    public static function tipo(string $tkey): array
    {
        $t = self::tipi()[$tkey] ?? null;
        if ($t === null) {
            throw new \RuntimeException("Tipo di siluro sconosciuto: {$tkey}");
        }
        return $t;
    }

    /**
     * Regolazione di velocita' utilizzabile per una data distanza: si sceglie
     * la piu' veloce che ci arriva ancora.
     *
     * @return array{v_kn:float,r_m:int}
     */
    public static function regolazione(array $tipo, float $distanzaM): array
    {
        $opzioni = [];
        foreach ([['v1_kn', 'r1_m'], ['v2_kn', 'r2_m'], ['v3_kn', 'r3_m']] as [$v, $r]) {
            if ($tipo[$v] !== null && $tipo[$r] !== null) {
                $opzioni[] = ['v_kn' => (float) $tipo[$v], 'r_m' => (int) $tipo[$r]];
            }
        }
        usort($opzioni, static fn (array $a, array $b): int => $b['v_kn'] <=> $a['v_kn']);
        foreach ($opzioni as $o) {
            if ($o['r_m'] >= $distanzaM) {
                return $o;
            }
        }
        return $opzioni[count($opzioni) - 1] ?? ['v_kn' => 30.0, 'r_m' => 5000];
    }

    /**
     * Soluzione di tiro.
     *
     * Risolve il triangolo d'intercetto: dove sara' il bersaglio quando il
     * siluro sara' arrivato li'. I dati in ingresso sono le STIME del
     * comandante, non la verita': se la stima e' sbagliata, la soluzione lo e'.
     *
     * @param float $rilevamento  rilevamento vero del bersaglio, in gradi
     * @param float $distanzaNm   distanza stimata
     * @param float $aob          angolo sulla prua del bersaglio (0 = ci viene addosso, 90 = di traverso)
     * @param bool  $aobSinistra  il bersaglio mostra il fianco sinistro
     * @param float $velocitaKn   velocita' stimata del bersaglio
     * @param float $siluroKn     velocita' del siluro
     *
     * @return array{ok:bool, rotta:float, corsa_nm:float, tempo_s:float, giro:float, errore?:string}
     */
    public static function soluzione(
        float $rilevamento,
        float $distanzaNm,
        float $aob,
        bool $aobSinistra,
        float $velocitaKn,
        float $siluroKn,
        float $rottaPropria,
    ): array {
        // Rotta vera del bersaglio ricavata da rilevamento e angolo sulla prua:
        // e' il modo in cui la si otteneva davvero, guardando nel periscopio.
        $rottaBersaglio = Geo::normBearing(
            $rilevamento + 180.0 + ($aobSinistra ? $aob : -$aob)
        );

        // Piano locale in miglia: a queste distanze la curvatura non conta.
        $rx = $distanzaNm * sin(deg2rad($rilevamento));
        $ry = $distanzaNm * cos(deg2rad($rilevamento));
        $vx = $velocitaKn * sin(deg2rad($rottaBersaglio));
        $vy = $velocitaKn * cos(deg2rad($rottaBersaglio));

        // |R + V·t| = vs·t  ->  (|V|² - vs²)t² + 2(R·V)t + |R|² = 0
        $a = $vx * $vx + $vy * $vy - $siluroKn * $siluroKn;
        $b = 2 * ($rx * $vx + $ry * $vy);
        $c = $rx * $rx + $ry * $ry;

        $t = null;
        if (abs($a) < 1e-9) {
            if (abs($b) > 1e-9) {
                $t = -$c / $b;
            }
        } else {
            $disc = $b * $b - 4 * $a * $c;
            if ($disc >= 0) {
                $sq = sqrt($disc);
                foreach ([(-$b - $sq) / (2 * $a), (-$b + $sq) / (2 * $a)] as $cand) {
                    if ($cand > 0 && ($t === null || $cand < $t)) {
                        $t = $cand;
                    }
                }
            }
        }

        if ($t === null || $t <= 0) {
            return ['ok' => false, 'rotta' => 0.0, 'corsa_nm' => 0.0, 'tempo_s' => 0.0, 'giro' => 0.0,
                    'errore' => 'Nessuna soluzione: il bersaglio e\' troppo veloce o si allontana.'];
        }

        $ix = $rx + $vx * $t;
        $iy = $ry + $vy * $t;
        $rotta = Geo::normBearing(rad2deg(atan2($ix, $iy)));

        return [
            'ok'       => true,
            'rotta'    => round($rotta, 1),
            'corsa_nm' => round($siluroKn * $t, 3),
            'tempo_s'  => round($t * 3600.0, 1),
            'giro'     => round(Geo::bearingDelta($rottaPropria, $rotta), 1),
        ];
    }

    /**
     * La stima del Primo Ufficiale, quando il comandante non e' al periscopio.
     * E' bravo, ma non e' il comandante: sbaglia un po' su tutto.
     *
     * @return array{aob:float,distanza:float,velocita:float}
     */
    public static function stimaIwo(float $aobVero, float $distanzaVera, float $velocitaVera, float $resa, Rng $rng): array
    {
        $q = max(0.25, min(1.3, $resa));
        return [
            'aob'       => max(0.0, min(180.0, $aobVero + $rng->gauss() * 12.0 / $q)),
            'distanza'  => max(0.1, $distanzaVera * (1.0 + $rng->gauss() * 0.16 / $q)),
            'velocita'  => max(0.5, $velocitaVera + $rng->gauss() * 1.9 / $q),
        ];
    }

    /**
     * Difetti del siluro, estratti al lancio.
     *
     * @return array{cilecca:bool,prematuro:bool,quota_extra:float,nota:?string}
     */
    public static function difetti(array $tipo, string $spoletta, Rng $rng): array
    {
        $scala = (float) GameConfig::get('combat.qualita_siluri', 1.0);

        $cilecca = $rng->chance((float) $tipo['p_cilecca'] * $scala);
        // La spoletta magnetica era piu' sensibile e piu' capricciosa: scoppiava
        // in anticipo sul campo magnetico della nave — o non scoppiava affatto.
        $prematuro = $spoletta === 'magnetica'
            ? $rng->chance((float) $tipo['p_prematura'] * $scala * 2.6)
            : $rng->chance((float) $tipo['p_prematura'] * $scala);

        $quotaExtra = $rng->chance((float) $tipo['p_quota_errata'] * $scala)
            ? round($rng->range(1.5, 3.5), 1)
            : 0.0;

        $nota = null;
        if ($prematuro) {
            $nota = 'scoppio prematuro';
        } elseif ($cilecca) {
            $nota = 'spoletta difettosa';
        } elseif ($quotaExtra > 0) {
            $nota = 'corsa troppo profonda';
        }

        return ['cilecca' => $cilecca, 'prematuro' => $prematuro, 'quota_extra' => $quotaExtra, 'nota' => $nota];
    }

    /**
     * Danno di una testata su una nave.
     *
     * Un mercantile medio (5.000 GRT circa) con un siluro sotto la chiglia
     * spesso si spezza; una petroliera grande ne assorbe tre o quattro. Il
     * danno scala percio' come radice inversa della stazza.
     *
     * @return array{danno:float,allagamento:float,incendio:float}
     */
    public static function danno(int $grt, int $warheadKg, string $carico, bool $sottoChiglia, Rng $rng): array
    {
        // Taratura: un siluro sotto la chiglia spezza spesso un piroscafo
        // piccolo, mette in ginocchio un cargo medio (che poi affonda per
        // allagamento nella maggior parte dei casi) e non basta contro una
        // grande petroliera. E' quello che raccontano i rapporti di missione.
        $base = 52.0 * ($warheadKg / 280.0) * (5200.0 / max(400.0, $grt)) ** 0.4;

        // Il colpo sotto la chiglia spezza la schiena: e' il motivo per cui si
        // insisteva con la spoletta magnetica nonostante i guai che dava.
        if ($sottoChiglia) {
            $base *= 1.55;
        }
        $base *= $rng->range(0.78, 1.3);

        $allagamento = $base * $rng->range(0.6, 1.0);
        $incendio = 0.0;

        $c = mb_strtolower($carico);
        if (str_contains($c, 'benzina') || str_contains($c, 'greggio') || str_contains($c, 'nafta')) {
            $incendio = $rng->range(35.0, 95.0);       // le petroliere cariche bruciano per ore
        } elseif (str_contains($c, 'munizioni')) {
            if ($rng->chance(0.22)) {
                $base = 999.0;                          // esplosione: sparisce in pochi secondi
                $incendio = 100.0;
            }
        } elseif (str_contains($c, 'zavorra')) {
            $allagamento *= 0.55;                       // in zavorra si sta a galla molto meglio
        } elseif (str_contains($c, 'legname')) {
            $allagamento *= 0.45;                       // il legname galleggia, e la nave con lui
        }

        return [
            'danno'       => round($base, 2),
            'allagamento' => round($allagamento, 2),
            'incendio'    => round($incendio, 2),
        ];
    }

    /** Quanto ci mette una nave colpita ad andare a fondo, in secondi di gioco. */
    public static function tempoAffondamento(float $allagamento, int $grt, Rng $rng): int
    {
        $minuti = (900.0 / max(8.0, $allagamento)) * (1.0 + $grt / 25000.0) * $rng->range(0.6, 1.7);
        return (int) round(max(1.0, min(300.0, $minuti)) * 60);
    }

    // --- Arsenale di bordo ---------------------------------------------------

    /**
     * Carico standard di siluri per grado del comandante.
     *
     * Un comandante nuovo riceve quello che c'e' in magazzino: elettrici T II e
     * qualche G7a a vapore. Con l'anzianita' arrivano i T III, i FAT e, in
     * fondo alla carriera, lo Zaunkoenig.
     *
     * @return array<string,int>
     */
    public static function caricoStandard(array $type, int $grado = 0): array
    {
        $totale = (int) $type['torpedoes'];
        $disponibili = array_values(array_filter(
            self::tipi(),
            static fn (array $t): bool => (int) $t['unlock_rank'] <= $grado
        ));
        if ($disponibili === []) {
            return ['G7e_T2' => $totale];
        }

        $elettrico = null;
        $vapore = null;
        foreach ($disponibili as $t) {
            if ((string) $t['propulsione'] === 'elettrico' && (string) $t['guida'] === 'dritto') {
                $elettrico = (string) $t['tkey'];   // l'ultimo sbloccato e' il migliore
            }
            if ((string) $t['propulsione'] === 'vapore') {
                $vapore = (string) $t['tkey'];
            }
        }
        $elettrico ??= (string) $disponibili[0]['tkey'];

        $nVapore = $vapore !== null ? (int) round($totale * 0.35) : 0;
        return array_filter([
            $elettrico => $totale - $nVapore,
            $vapore    => $nVapore,
        ]);
    }

    /** Carica i tubi e le riserve di un battello. */
    public static function imbarca(int $boatId, array $type, array $carico): int
    {
        Database::run('DELETE FROM boat_torpedoes WHERE boat_id = ?', [$boatId]);

        $prua = (int) $type['tubes_bow'];
        $poppa = (int) $type['tubes_stern'];
        $totale = (int) $type['torpedoes'];

        $lista = [];
        foreach ($carico as $tkey => $quanti) {
            for ($i = 0; $i < (int) $quanti; $i++) {
                $lista[] = (string) $tkey;
            }
        }
        $lista = array_slice($lista, 0, $totale);

        $n = 0;
        $i = 0;
        for ($t = 1; $t <= $prua && $i < count($lista); $t++, $i++) {
            Database::run(
                'INSERT INTO boat_torpedoes (boat_id, tkey, posizione, tubo, stato) VALUES (?, ?, "tubo_prua", ?, "pronto")',
                [$boatId, $lista[$i], $t]
            );
            $n++;
        }
        for ($t = 1; $t <= $poppa && $i < count($lista); $t++, $i++) {
            Database::run(
                'INSERT INTO boat_torpedoes (boat_id, tkey, posizione, tubo, stato) VALUES (?, ?, "tubo_poppa", ?, "pronto")',
                [$boatId, $lista[$i], $prua + $t]
            );
            $n++;
        }
        // Il resto in stiva: tre dei quattordici di un Tipo VII stanno in
        // contenitori stagni esterni, e si imbarcano solo con mare calmo.
        $esterni = max(0, count($lista) - $i - 8);
        for (; $i < count($lista); $i++) {
            $esterno = $esterni-- > 0;
            Database::run(
                'INSERT INTO boat_torpedoes (boat_id, tkey, posizione, stato) VALUES (?, ?, ?, "pronto")',
                [$boatId, $lista[$i], $esterno ? 'riserva_esterna' : 'riserva_interna']
            );
            $n++;
        }
        return $n;
    }

    /** @return list<array<string,mixed>> i tubi pronti al lancio */
    public static function tubiPronti(int $boatId): array
    {
        return Database::all(
            "SELECT * FROM boat_torpedoes WHERE boat_id = ? AND stato = 'pronto'
             AND posizione IN ('tubo_prua','tubo_poppa') ORDER BY tubo",
            [$boatId]
        );
    }

    /** @return array{tubi:int,riserve:int,per_tipo:array<string,int>} */
    public static function inventario(int $boatId): array
    {
        $tubi = 0;
        $riserve = 0;
        $perTipo = [];
        foreach (Database::all(
            "SELECT tkey, posizione, stato FROM boat_torpedoes WHERE boat_id = ? AND stato <> 'lanciato'",
            [$boatId]
        ) as $r) {
            if (str_starts_with((string) $r['posizione'], 'tubo')) {
                $tubi++;
            } else {
                $riserve++;
            }
            $perTipo[(string) $r['tkey']] = ($perTipo[(string) $r['tkey']] ?? 0) + 1;
        }
        return ['tubi' => $tubi, 'riserve' => $riserve, 'per_tipo' => $perTipo];
    }

    /**
     * Ricarica un tubo vuoto pescando dalle riserve. Il lavoro e' pesante e
     * lungo: quattro uomini che manovrano un tubo d'acciaio da una tonnellata e
     * mezza in un corridoio largo un metro, col battello che rolla.
     */
    public static function ricarica(int $boatId, int $gts, int $statoMare, float $resaSiluristi): int
    {
        $ricaricati = 0;
        $vuoti = Database::all(
            "SELECT * FROM boat_torpedoes WHERE boat_id = ? AND stato = 'in_carica' AND ricarica_fine_gts <= ?",
            [$boatId, $gts]
        );
        foreach ($vuoti as $v) {
            Database::run("UPDATE boat_torpedoes SET stato = 'pronto', ricarica_fine_gts = NULL WHERE id = ?", [(int) $v['id']]);
            $ricaricati++;
        }

        // Tubi liberi da riempire, se ci sono riserve interne.
        $liberi = (int) (Database::first(
            "SELECT COUNT(*) n FROM boat_torpedoes WHERE boat_id = ? AND stato = 'pronto'
             AND posizione IN ('tubo_prua','tubo_poppa')",
            [$boatId]
        )['n'] ?? 0);

        $riserva = Database::first(
            "SELECT * FROM boat_torpedoes WHERE boat_id = ? AND stato = 'pronto' AND posizione = 'riserva_interna' LIMIT 1",
            [$boatId]
        );
        $tuboVuoto = Database::first(
            "SELECT tubo FROM boat_torpedoes WHERE boat_id = ? AND stato = 'lanciato' AND tubo IS NOT NULL
             ORDER BY tubo LIMIT 1",
            [$boatId]
        );

        if ($riserva !== null && $tuboVuoto !== null) {
            $durata = (int) round(self::RICARICA_S * (1.0 + 0.12 * max(0, $statoMare - 3)) / max(0.4, $resaSiluristi));
            Database::run(
                "UPDATE boat_torpedoes SET posizione = ?, tubo = ?, stato = 'in_carica', ricarica_fine_gts = ?
                 WHERE id = ?",
                [
                    (int) $tuboVuoto['tubo'] > 4 ? 'tubo_poppa' : 'tubo_prua',
                    (int) $tuboVuoto['tubo'], $gts + $durata, (int) $riserva['id'],
                ]
            );
            Database::run(
                "DELETE FROM boat_torpedoes WHERE boat_id = ? AND stato = 'lanciato' AND tubo = ? LIMIT 1",
                [$boatId, (int) $tuboVuoto['tubo']]
            );
        }

        return $ricaricati;
    }
}
