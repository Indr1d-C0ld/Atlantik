<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * Il traffico alleato: convogli e navi isolate.
 *
 * Due scelte di fondo.
 *
 * PRIMA: non si genera naviglio "attorno al giocatore". Si tiene in mare una
 * rete di convogli e di navi isolate che percorrono rotte reali con orari
 * propri; il giocatore ci si imbatte, oppure non ci si imbatte affatto — e una
 * settimana di mare vuoto e' anch'essa il gioco.
 *
 * SECONDA: le posizioni non si salvano a ogni battito. Una nave e' definita
 * dalla sua rotta, dalla sua velocita' e dall'ora di partenza; dove si trova
 * adesso e' un calcolo, non una riga da aggiornare. A database finiscono solo
 * gli avvenimenti: la partenza, l'arrivo, l'affondamento.
 */
final class Traffic
{
    /** @var array<string,mixed>|null */
    private static ?array $dati = null;

    /** @return array<string,mixed> */
    public static function dati(): array
    {
        if (self::$dati !== null) {
            return self::$dati;
        }
        $root = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        return self::$dati = require $root . '/db/seed/rotte.php';
    }

    /** @return list<array{0:float,1:float}> */
    public static function rotta(string $key): array
    {
        $r = self::dati()['rotte'][$key] ?? null;
        return $r === null ? [] : $r['punti'];
    }

    public static function nomeRotta(string $key): string
    {
        return (string) (self::dati()['rotte'][$key]['nome'] ?? $key);
    }

    /** @var array<string,float> */
    private static array $lunghezze = [];

    /** @var array<string,list<array{lat:float,lon:float,rotta:float,tratto:float}>> */
    private static array $tratte = [];

    /**
     * Tratte di una rotta, precalcolate: distanza e rilevamento di ogni gamba.
     * Si calcolano una volta per processo — la posizione di ogni nave viene
     * chiesta migliaia di volte per avanzamento, e rifare i conti ogni volta
     * costerebbe piu' della simulazione.
     *
     * @return list<array{lat:float,lon:float,rotta:float,tratto:float}>
     */
    private static function tratte(string $key): array
    {
        if (isset(self::$tratte[$key])) {
            return self::$tratte[$key];
        }
        $punti = self::rotta($key);
        $out = [];
        $tot = 0.0;
        for ($i = 1; $i < count($punti); $i++) {
            $a = $punti[$i - 1];
            $b = $punti[$i];
            $d = Geo::distanceNm($a[0], $a[1], $b[0], $b[1]);
            $out[] = [
                'lat' => $a[0], 'lon' => $a[1],
                'rotta' => Geo::bearing($a[0], $a[1], $b[0], $b[1]),
                'tratto' => $d,
            ];
            $tot += $d;
        }
        self::$lunghezze[$key] = $tot;
        return self::$tratte[$key] = $out;
    }

    /** Lunghezza di una rotta in miglia. */
    public static function lunghezzaNm(string $key): float
    {
        if (!isset(self::$lunghezze[$key])) {
            self::tratte($key);
        }
        return self::$lunghezze[$key] ?? 0.0;
    }

    /**
     * Dove si trova, adesso, chi e' partito a quell'ora su quella rotta.
     *
     * @return array{lat:float,lon:float,heading:float,progresso:float}|null
     *         null se non e' ancora partito o e' gia' arrivato
     */
    public static function posizione(string $rottaKey, float $speedKn, int $departedGts, int $gts, float $deviazioneDeg = 0.0): ?array
    {
        if (count(self::tratte($rottaKey)) < 1 || $gts < $departedGts) {
            return null;
        }

        $percorse = $speedKn * (($gts - $departedGts) / 3600.0);
        if ($percorse < 0) {
            return null;
        }

        $acc = 0.0;
        foreach (self::tratte($rottaKey) as $tratta) {
            if ($percorse <= $acc + $tratta['tratto']) {
                [$lat, $lon] = Geo::destination($tratta['lat'], $tratta['lon'], $tratta['rotta'], $percorse - $acc);

                // Deviazione dalla rotta base: l'Ammiragliato spostava i
                // convogli quando sospettava la presenza di sommergibili.
                if (abs($deviazioneDeg) > 0.01) {
                    $scarto = min(90.0, abs($deviazioneDeg)) * 1.6;   // miglia di scostamento laterale
                    [$lat, $lon] = Geo::destination($lat, $lon, Geo::normBearing($tratta['rotta'] + ($deviazioneDeg > 0 ? 90 : -90)), $scarto);
                }

                return [
                    'lat' => $lat, 'lon' => $lon, 'heading' => $tratta['rotta'],
                    'progresso' => $percorse / max(1.0, self::lunghezzaNm($rottaKey)),
                ];
            }
            $acc += $tratta['tratto'];
        }
        return null;   // arrivato
    }

    /** Durata di una traversata, in secondi di gioco. */
    public static function durata(string $rottaKey, float $speedKn): int
    {
        return (int) round(self::lunghezzaNm($rottaKey) / max(1.0, $speedKn) * 3600);
    }

    // --- Programmazione --------------------------------------------------------

    /**
     * Tiene il mare popolato: fa partire i convogli e le navi isolate che
     * servono, e archivia quelli arrivati. Idempotente, chiamabile a ogni tick.
     *
     * @return array{convogli:int,navi:int,arrivati:int}
     */
    public static function ensure(int $gts): array
    {
        $scala = (float) GameConfig::get('traffic.scala', 1.0);
        $convogliVoluti = (int) round(GameConfig::int('traffic.convogli_attivi', 10) * $scala);
        $isolateVolute  = (int) round(GameConfig::int('traffic.isolate_attive', 55) * $scala);

        // Archiviazione: chi e' arrivato non e' piu' in mare.
        $arrivati = Database::run(
            "UPDATE convoys SET state = 'arrivato' WHERE state = 'in_mare' AND eta_gts < ?",
            [$gts]
        )->rowCount();
        Database::run(
            "UPDATE ships SET state = 'arrivata' WHERE state = 'in_mare' AND eta_gts < ? AND convoy_id IS NULL",
            [$gts]
        );
        Database::run(
            "UPDATE ships SET state = 'arrivata'
             WHERE state = 'in_mare' AND convoy_id IN (SELECT id FROM convoys WHERE state = 'arrivato')",
            []
        );

        // Un convoglio ridotto all'osso non e' piu' un convoglio.
        $dispersi = self::disperdi($gts);

        // Si contano le unita' che sono in mare ADESSO, non quelle con lo stato
        // "in mare": una che salpera' domani non popola l'oceano di oggi.
        $convogli = 0;
        $inMare = (int) (Database::first(
            "SELECT COUNT(*) n FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        )['n'] ?? 0);
        while ($inMare + $convogli < $convogliVoluti) {
            if (!self::creaConvoglio($gts, $convogli)) {
                break;
            }
            $convogli++;
        }

        $navi = 0;
        $isolate = (int) (Database::first(
            "SELECT COUNT(*) n FROM ships WHERE state = 'in_mare' AND convoy_id IS NULL
             AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        )['n'] ?? 0);
        while ($isolate + $navi < $isolateVolute) {
            if (!self::creaIsolata($gts, $navi)) {
                break;
            }
            $navi++;
        }

        return ['convogli' => $convogli, 'navi' => $navi, 'arrivati' => $arrivati, 'dispersi' => $dispersi];
    }

    /**
     * Convogli dispersi.
     *
     * Un convoglio che ha perso quasi tutto non e' piu' un convoglio: non ha
     * piu' una formazione da tenere ne' abbastanza scorta per proteggerla, e
     * l'ordine era di disperdersi — ogni nave per conto suo, alla massima
     * velocita' possibile, verso la destinazione. "Convoy is to scatter" e'
     * l'ordine che il 4 luglio 1942 mando' al fondo il PQ17.
     *
     * Serve anche per un motivo meno nobile: senza, i convogli si consumano e
     * basta. Le navi affondate non tornano, le ritardatarie se ne vanno per
     * conto loro (vedi App\Sim\Danni), e dopo qualche mese l'Atlantico
     * sarebbe pieno di convogli da cinque navi. Disperdendo, il generatore ne
     * fa partire di nuovi e il traffico resta quello che deve essere.
     *
     * La soglia e' bassa apposta: un convoglio malmenato da un branco deve
     * poter arrivare in porto malmenato, non sciogliersi al terzo affondamento.
     *
     * @return int quanti convogli dispersi
     */
    private static function disperdi(int $gts): int
    {
        $minimo = max(3, GameConfig::int('traffic.convoglio_minimo', 6));

        $candidati = Database::all(
            "SELECT c.id, c.serie, c.numero, c.navi_iniziali,
                    (SELECT COUNT(*) FROM ships s
                      WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo <> 'scorta') AS merc
               FROM convoys c
              WHERE c.state = 'in_mare' AND c.departed_gts <= ?
             HAVING merc < ?",
            [$gts, $minimo]
        );

        $fatti = 0;
        foreach ($candidati as $c) {
            // Un incontro in corso su questo convoglio non si tocca: sciogliere
            // la formazione sotto i piedi di chi ci sta dentro lo farebbe
            // sparire dal quadro tattico a meta' attacco.
            $inCorso = Database::first(
                "SELECT id FROM encounters WHERE convoy_id = ? AND stato <> 'concluso' LIMIT 1",
                [(int) $c['id']]
            );
            if ($inCorso !== null) {
                continue;
            }

            // I mercantili proseguono da soli: stessa rotta, stessa ora di
            // arrivo, nessuna formazione da tenere.
            Database::run(
                "UPDATE ships SET convoy_id = NULL, colonna = NULL, fila = NULL, convoglio_perduto = ?
                  WHERE convoy_id = ? AND state = 'in_mare' AND ruolo <> 'scorta'",
                [$c['serie'] . $c['numero'], (int) $c['id']]
            );
            // La scorta ha finito il suo lavoro e torna indietro.
            Database::run(
                "UPDATE ships SET state = 'arrivata' WHERE convoy_id = ? AND ruolo = 'scorta'",
                [(int) $c['id']]
            );
            Database::run("UPDATE convoys SET state = 'arrivato' WHERE id = ?", [(int) $c['id']]);
            $fatti++;
        }

        return $fatti;
    }

    /**
     * La classe che una nave MOSTRA, che non sempre e' quella che e'.
     *
     * Una nave civetta si fingeva un piroscafo qualunque: pannelli di legno
     * sulle murate, l'aria di uno sbandato rimasto indietro dal convoglio. Chi
     * la guardava vedeva quello, e fino a che non sparava era quello.
     *
     * Tutto cio' che riguarda l'IDENTIFICAZIONE deve passare di qui: il nome
     * riferito dalla vedetta, la classe registrata nel contatto, la sagoma. La
     * stazza vera, il rumore vero e l'armamento vero restano quelli della nave
     * — e' l'apparenza che mente, non la fisica.
     */
    public static function classeApparente(string $classKey): array
    {
        $vera = self::classe($classKey);
        $finge = (string) ($vera['finge'] ?? '');
        if ($finge === '' || $finge === $classKey) {
            return $vera;
        }
        $maschera = self::classe($finge);
        // Si prende l'apparenza, si lascia il resto: la maschera cambia quello
        // che si vede, non quello che la nave e'.
        $vera['name'] = $maschera['name'];
        $vera['class_key_apparente'] = $finge;
        return $vera;
    }

    /**
     * Un nome che in mare non c'e' gia'.
     *
     * Lo spazio dei nomi e' largo (qualche migliaio di mercantili, trecento
     * scorte) ma non infinito, e con duemila scafi in mare una collisione
     * capita. Si ritira qualche volta; se proprio non si trova, si distingue
     * col numero di scafo — come si faceva davvero quando due navi della stessa
     * compagnia portavano lo stesso nome.
     *
     * Il vincolo UNIQUE sul database resta comunque l'ultima parola: questo
     * serve a non arrivarci quasi mai.
     *
     * @param callable(Rng):array{0:string,1:string}|callable(Rng):string $genera
     * @return array{0:?string,1:?string} nome libero, oppure null se non se ne trovano
     */
    private static function nomeLibero(callable $genera, Rng $rng, int $tentativi = 6): array
    {
        $ultimo = '';
        $bandiera = null;
        for ($i = 0; $i < $tentativi; $i++) {
            // Alla bandiera si resta fedeli: il generatore la riceve indietro
            // dal primo tiro e ri-estrae solo il nome. Cambiare bandiera a ogni
            // ritentativo spostava il traffico verso i repertori grandi e
            // svuotava quelli piccoli — il greco scendeva dal 7% all'1,4%.
            $out = $genera($rng, $bandiera);
            if (is_array($out)) {
                [$ultimo, $bandiera] = $out;
            } else {
                $ultimo = (string) $out;
            }
            $preso = Database::first('SELECT id FROM ships WHERE name = ?', [$ultimo]);
            if ($preso === null) {
                return [$ultimo, $bandiera];
            }
        }
        // Tutti presi: si distingue. Due navi con lo stesso nome nei registri
        // si distinguevano col numerale — Empire Star II dopo Empire Star — e
        // qui si scorre finche' non se ne trova uno libero davvero. La versione
        // precedente tirava a caso una cifra fra 2 e 9 e sperava: bastava che
        // il tiro ricadesse su un numerale gia' assegnato per far saltare
        // l'inserimento, e con lui tutto il battito.
        foreach (self::NUMERALI as $n) {
            $candidato = $ultimo . ' ' . $n;
            if (Database::first('SELECT id FROM ships WHERE name = ?', [$candidato]) === null) {
                return [$candidato, $bandiera];
            }
        }

        // Se perfino i numerali sono esauriti si rinuncia: chi chiama sa che
        // una nave in meno non e' un problema, un battito saltato si'.
        return [null, $bandiera];
    }

    /**
     * Da una tabella di pesi a un sacchetto da pescare.
     *
     * Il generatore pesca con un indice a caso da un elenco: un elenco in cui
     * ogni voce compare tante volte quanto pesa fa lo stesso lavoro di una
     * estrazione pesata, e si legge molto meglio quando si va a controllare se
     * le quote sono giuste.
     *
     * @param array<string,int> $pesi
     * @return list<string>
     */
    private static function pesate(array $pesi): array
    {
        $out = [];
        foreach ($pesi as $chiave => $peso) {
            for ($i = 0; $i < (int) $peso; $i++) {
                $out[] = $chiave;
            }
        }
        return $out;
    }

    /**
     * La composizione di un convoglio o di una scorta, come la vuole la
     * stanza dei bottoni.
     *
     * I pesi storici restano il default e stanno qui accanto, documentati:
     * quello che l'amministratore cambia e' una correzione sopra di essi, e
     * se toglie la chiave si torna alla storia. Le classi che non esistono
     * nel repertorio si scartano, cosi' una chiave scritta male non manda in
     * mare una flotta di navi inesistenti.
     *
     * @param array<string,int> $storici
     * @return list<string>
     */
    private static function mix(string $chiave, array $storici): array
    {
        $pesi = GameConfig::get($chiave);
        if (!is_array($pesi) || $pesi === []) {
            return self::pesate($storici);
        }

        $buoni = [];
        foreach ($pesi as $classe => $peso) {
            $peso = (int) $peso;
            if ($peso > 0 && self::esisteClasse((string) $classe)) {
                $buoni[(string) $classe] = $peso;
            }
        }

        return $buoni === [] ? self::pesate($storici) : self::pesate($buoni);
    }

    /** C'e' davvero, questa classe, nel repertorio delle navi? */
    private static function esisteClasse(string $key): bool
    {
        return (string) (self::classe($key)['name'] ?? '') !== $key
            || Database::first('SELECT 1 x FROM ship_classes WHERE class_key = ?', [$key]) !== null;
    }

    /** I pesi storici della composizione, che sono anche il punto di ritorno. */
    public const MIX_CONVOGLIO = [
        'cargo_medio'      => 38,
        'tramp_piccolo'    => 22,
        'cargo_grande'     => 16,
        'petroliera_media' => 13,
        'frigorifera'      => 5,
        'liberty'          => 3,
        'petroliera_t2'    => 3,
    ];
    public const MIX_SCORTA = [
        'corvetta_flower'  => 52,
        'ct_town'          => 18,
        'ct_vw'            => 13,
        'trawler_armato'   => 11,
        'sloop_black_swan' => 6,
    ];

    /** I numerali con cui i registri distinguevano due navi omonime. */
    private const NUMERALI = ['II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /**
     * Inserisce una nave tollerando la collisione di nome.
     *
     * Il vincolo di unicita' e' l'ultima parola, e fra il controllo e la scrittura
     * puo' passare un altro battito. Se succede, quella nave non nasce e basta:
     * l'oceano ne ha altre seicento, e il battito deve arrivare in fondo.
     *
     * @param list<mixed> $args
     */
    private static function inserisciNave(string $sql, array $args): bool
    {
        try {
            Database::run($sql, $args);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Potatura del naviglio esaurito.
     *
     * Una nave arrivata in porto ha finito il suo mestiere: nessun contatto la
     * riferisce piu', nessun incontro la comprende, e tenerla in tabella costa
     * soltanto. Al ritmo misurato in esercizio (2.500 arrivi al giorno) senza
     * potatura si superano le 900.000 righe in un anno.
     *
     * Non si cancella mai una nave AFFONDATA: quella e' storia, e il tonnellaggio
     * di un comandante ci si appoggia. Non si cancella nulla che sia ancora
     * riferito da un contatto, da un incontro o da un affondamento: senza chiavi
     * esterne su queste colonne (vedi A3) il controllo va fatto qui.
     *
     * @return array{navi:int,convogli:int}
     */
    public static function pota(int $gts, int $grazia = 7 * 86400): array
    {
        $limite = $gts - max(86400, $grazia);

        $navi = Database::run(
            "DELETE FROM ships
              WHERE state = 'arrivata' AND eta_gts < ?
                AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.ship_id = ships.id)
                AND NOT EXISTS (SELECT 1 FROM encounter_entities e WHERE e.ship_id = ships.id)
                AND NOT EXISTS (SELECT 1 FROM sinkings s WHERE s.ship_id = ships.id)
              LIMIT 2000",
            [$limite]
        )->rowCount();

        $convogli = Database::run(
            "DELETE FROM convoys
              WHERE state = 'arrivato' AND eta_gts < ?
                AND NOT EXISTS (SELECT 1 FROM ships s WHERE s.convoy_id = convoys.id)
                AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.convoy_id = convoys.id)
                AND NOT EXISTS (SELECT 1 FROM encounters e WHERE e.convoy_id = convoys.id)
                AND NOT EXISTS (SELECT 1 FROM wolfpacks w WHERE w.convoy_id = convoys.id)
              LIMIT 500",
            [$limite]
        )->rowCount();

        return ['navi' => $navi, 'convogli' => $convogli];
    }

    /** Fa salpare un convoglio, scaglionato indietro nel tempo. */
    private static function creaConvoglio(int $gts, int $indice): bool
    {
        $serie = self::dati()['serie'];
        $rng = Rng::for(World::seed(), 'convoglio', $gts, $indice);

        // La serie si estrae con peso inverso all'intervallo di partenza: gli HX
        // salpavano ogni sei giorni e i TM ogni sedici, quindi in mare ci sono
        // piu' HX che TM. Un'estrazione uniforme darebbe un Atlantico falso.
        $pesi = array_map(static fn (array $d): float => 1.0 / max(1.0, (float) $d['ogni_giorni']), $serie);
        $tiro = $rng->range(0, array_sum($pesi));
        $acc = 0.0;
        $def = $serie[0];
        foreach ($serie as $i => $d) {
            $acc += $pesi[$i];
            if ($tiro <= $acc) {
                $def = $d;
                break;
            }
        }

        $numero = (int) (Database::first(
            'SELECT COALESCE(MAX(numero), ?) + 1 n FROM convoys WHERE serie = ?',
            [(int) $def['numero_da'] - 1, $def['serie']]
        )['n'] ?? $def['numero_da']);

        // Partenza distribuita nella traversata: cosi' il mare non e' pieno di
        // convogli tutti appena salpati dallo stesso porto.
        $durata = self::durata((string) $def['rotta'], (float) $def['speed_kn']);
        $partito = $gts - $rng->int(0, max(1, (int) ($durata * 0.85)));

        $navi = $rng->int($def['navi'][0], $def['navi'][1]);
        $scorte = $rng->int($def['scorte'][0], $def['scorte'][1]);

        Database::run(
            'INSERT INTO convoys (serie, numero, rotta_key, speed_kn, colonne, departed_gts, eta_gts,
                                  zigzag, deviazione, navi_iniziali)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [
                $def['serie'], $numero, $def['rotta'], $def['speed_kn'], $def['colonne'],
                $partito, $partito + $durata, round($rng->range(-1.2, 1.2), 2), $navi + $scorte,
            ]
        );
        $convoyId = Database::lastInsertId();

        // Le navi del convoglio: composizione plausibile, commodoro in testa,
        // nave soccorso in coda, scorta attorno.
        // I pesi sono per SCAFO, non per stazza, e sono tarati sul gennaio 1942
        // (il conto sta in docs/FONTI.md). Le due cose che si sbagliano piu'
        // facilmente:
        //
        //   LIBERTY. La prima, la Patrick Henry, e' del 27 settembre 1941. A
        //   gennaio del 1942 ce n'erano poche decine in mare in tutto il mondo:
        //   diventano la nave piu' comune dell'Atlantico solo dal 1943. Tenerle
        //   al quattordici per cento come una classe qualunque sposta in avanti
        //   di un anno l'aspetto dell'intero traffico.
        //
        //   FRIGORIFERE. Erano navi speciali e costose, intorno al quattro per
        //   cento della flotta mercantile, non una nave su sette.
        $classi = self::mix('traffic.mix_convoglio', self::MIX_CONVOGLIO);
        if ((string) $def['serie'] === 'TM') {
            // I TM erano convogli di sole petroliere, da Trinidad al Mediterraneo.
            $classi = self::pesate(['petroliera_media' => 70, 'petroliera_t2' => 30]);
        }

        $colonne = max(1, (int) $def['colonne']);
        for ($i = 0; $i < $navi; $i++) {
            $classe = $classi[$rng->int(0, count($classi) - 1)];
            [$nome, $bandiera] = self::nomeLibero(
                static fn (Rng $r, ?string $b = null): array => ShipNames::genera($r, $classe, $b),
                $rng
            );
            if ($nome === null) {
                continue;
            }
            $cls = self::classe($classe);
            $ruolo = $i === 0 ? 'commodoro' : ($i === $navi - 1 ? 'soccorso' : 'mercantile');

            self::inserisciNave(
                'INSERT INTO ships (name, flag, class_key, convoy_id, colonna, fila, ruolo, rotta_key, speed_kn,
                                    departed_gts, eta_gts, grt, carico)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $nome, $bandiera, $classe, $convoyId,
                    $i % $colonne + 1, intdiv($i, $colonne) + 1, $ruolo,
                    $def['rotta'], $def['speed_kn'], $partito, $partito + $durata,
                    (int) round((float) $cls['grt'] * $rng->range(0.75, 1.3)),
                    ShipNames::carico($rng, (string) $cls['kind']),
                ]
            );
        }

        // La scorta del gennaio 1942: la corvetta Flower e' la spina dorsale, non
        // una fra tante. I cacciatorpediniere erano pochi e sempre richiesti
        // altrove, e i pescherecci armati riempivano i buchi.
        //
        // NIENTE FREGATE River: la prima, la Rother, entra in servizio
        // nell'aprile del 1942 e la classe diventa comune nel 1943. Prima
        // c'era un quinto di scorte che non esistevano ancora.
        $classiScorta = self::mix('traffic.mix_scorta', self::MIX_SCORTA);
        for ($i = 0; $i < $scorte; $i++) {
            $classe = $classiScorta[$rng->int(0, count($classiScorta) - 1)];
            [$nomeScorta] = self::nomeLibero(
                static fn (Rng $r, ?string $b = null): string => ShipNames::scorta($r, $classe),
                $rng
            );
            if ($nomeScorta === null) {
                continue;
            }
            self::inserisciNave(
                'INSERT INTO ships (name, flag, class_key, convoy_id, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
                 VALUES (?, ?, ?, ?, "scorta", ?, ?, ?, ?, ?)',
                [
                    $nomeScorta, 'britannica', $classe, $convoyId,
                    $def['rotta'], $def['speed_kn'], $partito, $partito + $durata,
                    (int) self::classe($classe)['grt'],
                ]
            );
        }

        return true;
    }

    /** Fa salpare una nave isolata. */
    private static function creaIsolata(int $gts, int $indice): bool
    {
        $lane = self::dati()['isolate'];
        $rng = Rng::for(World::seed(), 'isolata', $gts, $indice);

        $totale = array_sum(array_column($lane, 'peso'));
        $tiro = $rng->range(0, $totale);
        $acc = 0.0;
        $scelta = $lane[0];
        foreach ($lane as $l) {
            $acc += $l['peso'];
            if ($tiro <= $acc) {
                $scelta = $l;
                break;
            }
        }

        $classe = $scelta['classi'][$rng->int(0, count($scelta['classi']) - 1)];

        // Una nave civetta ogni tanto, e solo fra le isolate: in convoglio non
        // avrebbe senso, perche' la trappola funziona su chi si avvicina in
        // superficie a un bersaglio che sembra solo e indifeso.
        $perMille = max(0, GameConfig::int('traffic.civette_per_mille', 14));
        if ($perMille > 0 && $rng->int(1, 1000) <= $perMille) {
            $classe = 'qship';
        }

        $cls = self::classe($classe);
        $velocita = round((float) $cls['speed_kn'] * $rng->range(0.88, 1.02), 1);
        $durata = self::durata((string) $scelta['rotta'], $velocita);
        $partita = $gts - $rng->int(0, max(1, (int) ($durata * 0.9)));

        [$nome, $bandiera] = self::nomeLibero(
            static fn (Rng $r, ?string $b = null): array => ShipNames::genera($r, $classe, $b),
            $rng
        );
        if ($nome === null) {
            return false;
        }

        return self::inserisciNave(
            'INSERT INTO ships (name, flag, class_key, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico)
             VALUES (?, ?, ?, "mercantile", ?, ?, ?, ?, ?, ?)',
            [
                $nome, $bandiera, $classe, $scelta['rotta'], $velocita, $partita, $partita + $durata,
                (int) round((float) $cls['grt'] * $rng->range(0.75, 1.3)),
                ShipNames::carico($rng, (string) $cls['kind']),
            ]
        );
    }

    // --- Interrogazione ---------------------------------------------------------

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $classi = null;

    /** @return array<string,mixed> */
    public static function classe(string $key): array
    {
        if (self::$classi === null) {
            self::$classi = [];
            foreach (Database::all('SELECT * FROM ship_classes') as $r) {
                self::$classi[(string) $r['class_key']] = $r;
            }
        }
        return self::$classi[$key] ?? [
            'class_key' => $key, 'name' => $key, 'kind' => 'mercantile', 'grt' => 5000,
            'speed_kn' => 9.0, 'length_m' => 120, 'eliche' => 1, 'rumore_db' => 138,
            'armata' => 0, 'asdic' => 0, 'radar' => 0, 'hfdf' => 0, 'dc_carica' => 0,
        ];
    }

    /**
     * Unita' in mare entro un certo raggio, con la posizione calcolata adesso.
     * I convogli tornano come un'unica unita': per il rilevamento contano come
     * una sorgente sola, grossa e rumorosa.
     *
     * @return list<array<string,mixed>>
     */
    public static function nearby(float $lat, float $lon, float $raggioNm, int $gts): array
    {
        $out = [];

        // Convogli: una riga per convoglio.
        $convogli = Database::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo <> 'scorta') AS mercantili,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo = 'scorta') AS scorte
             FROM convoys c WHERE c.state = 'in_mare' AND c.departed_gts <= ? AND c.eta_gts >= ?",
            [$gts, $gts]
        );
        foreach ($convogli as $c) {
            $p = self::posizione((string) $c['rotta_key'], (float) $c['speed_kn'], (int) $c['departed_gts'], $gts, (float) $c['deviazione']);
            if ($p === null) {
                continue;
            }
            $d = Geo::distanceNm($lat, $lon, $p['lat'], $p['lon']);
            if ($d > $raggioNm) {
                continue;
            }
            $out[] = [
                'kind'       => 'convoglio',
                'id'         => (int) $c['id'],
                'nome'       => $c['serie'] . ' ' . $c['numero'],
                'lat'        => $p['lat'],
                'lon'        => $p['lon'],
                'heading'    => $p['heading'],
                'speed_kn'   => (float) $c['speed_kn'],
                'distanza'   => $d,
                'mercantili' => (int) $c['mercantili'],
                'scorte'     => (int) $c['scorte'],
                'rotta'      => (string) $c['rotta_key'],
            ];
        }

        // Navi isolate.
        $navi = Database::all(
            "SELECT * FROM ships WHERE convoy_id IS NULL AND state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        );
        foreach ($navi as $s) {
            $p = self::posizione((string) $s['rotta_key'], (float) $s['speed_kn'], (int) $s['departed_gts'], $gts);
            if ($p === null) {
                continue;
            }
            $d = Geo::distanceNm($lat, $lon, $p['lat'], $p['lon']);
            if ($d > $raggioNm) {
                continue;
            }
            $cls = self::classe((string) $s['class_key']);
            $out[] = [
                'kind'      => 'nave',
                'id'        => (int) $s['id'],
                'nome'      => (string) $s['name'],
                'bandiera'  => (string) $s['flag'],
                'classe'    => (string) $s['class_key'],
                'classe_nome' => (string) $cls['name'],
                'tipo'      => (string) $cls['kind'],
                'lat'       => $p['lat'],
                'lon'       => $p['lon'],
                'heading'   => $p['heading'],
                'speed_kn'  => (float) $s['speed_kn'],
                'distanza'  => $d,
                'grt'       => (int) $s['grt'],
                'carico'    => (string) ($s['carico'] ?? ''),
                // Una nave gia' colpita si vede che e' stata colpita: sbanda,
                // fuma dal punto sbagliato, e va piu' piano di quanto dovrebbe.
                'integrita' => (float) ($s['integrita'] ?? 100.0),
                'ritardataria' => (bool) ($s['ritardataria'] ?? false),
                'convoglio_perduto' => $s['convoglio_perduto'] ?? null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['distanza'] <=> $b['distanza']);
        return $out;
    }

    /**
     * Le unita' in mare nella finestra indicata, in forma uniforme: convogli
     * (una riga ciascuno, con la loro consistenza) e navi isolate.
     *
     * Si legge una volta per avanzamento; le posizioni si calcolano poi a
     * memoria, sotto-passo per sotto-passo, senza toccare il database.
     *
     * @return list<array<string,mixed>>
     */
    public static function attivi(int $daGts, int $aGts): array
    {
        $out = [];

        foreach (Database::all(
            "SELECT c.id, c.serie, c.numero, c.rotta_key, c.speed_kn, c.departed_gts, c.eta_gts, c.deviazione,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo <> 'scorta') AS mercantili,
                    (SELECT COUNT(*) FROM ships s WHERE s.convoy_id = c.id AND s.state = 'in_mare' AND s.ruolo = 'scorta') AS scorte
             FROM convoys c
             WHERE c.state = 'in_mare' AND c.departed_gts <= ? AND c.eta_gts >= ?",
            [$aGts, $daGts]
        ) as $c) {
            $c['tipo_unita'] = 'convoglio';
            $c['nome'] = $c['serie'] . ' ' . $c['numero'];
            $c['class_key'] = 'cargo_medio';
            $out[] = $c;
        }

        foreach (Database::all(
            "SELECT id, name, flag, class_key, rotta_key, speed_kn, departed_gts, eta_gts, grt, carico
             FROM ships
             WHERE convoy_id IS NULL AND state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ?",
            [$aGts, $daGts]
        ) as $n) {
            $n['tipo_unita'] = 'nave';
            $n['nome'] = $n['name'];
            $n['deviazione'] = 0.0;
            $n['mercantili'] = 1;
            $n['scorte'] = 0;
            $out[] = $n;
        }

        return $out;
    }

    /** Statistiche del traffico in mare. */
    public static function stato(int $gts): array
    {
        $c = Database::first(
            "SELECT COUNT(*) n FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        );
        $s = Database::first(
            "SELECT COUNT(*) n FROM ships WHERE state = 'in_mare' AND convoy_id IS NULL
             AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        );
        $t = Database::first(
            "SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM ships
             WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ?",
            [$gts, $gts]
        );
        $a = Database::first("SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM ships WHERE state = 'affondata'");
        return [
            'convogli'   => (int) ($c['n'] ?? 0),
            'isolate'    => (int) ($s['n'] ?? 0),
            'navi'       => (int) ($t['n'] ?? 0),
            'grt_mare'   => (int) ($t['grt'] ?? 0),
            'affondate'  => (int) ($a['n'] ?? 0),
            'grt_affondato' => (int) ($a['grt'] ?? 0),
        ];
    }
}
