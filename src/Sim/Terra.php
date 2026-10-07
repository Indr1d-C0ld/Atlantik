<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * La terraferma, per la simulazione.
 *
 * Fino al 30/09/2026 le coste esistevano soltanto nel disegno della carta
 * (assets/js/coste.js): la simulazione non sapeva dove fosse la terra, e un
 * battello che tirava dritto da Lorient a Kiel attraversava la Bretagna, la
 * Manica e lo Jutland come se fossero mare. Adesso la simulazione legge le
 * stesse coste della carta — un solo dato, cosi' quello che si vede e quello
 * che succede non possono divergere — e ci aggiunge due cose:
 *
 *   - i canali d'uscita delle basi (db/seed/canali.php): a quella scala sette
 *     basi su nove stanno sulla terraferma, in estuari e fiordi che la carta
 *     non risolve. Intorno a ogni canale, per CORRIDOIO_NM miglia per parte,
 *     si naviga anche dove la carta dice terra;
 *   - la ricerca di una rotta che eviti la terra (rotta()), per adeguare la
 *     rotta tracciata dal comandante e per portare il battello fuori dal porto
 *     e dentro.
 *
 * I punti sono quelli di una carta 1:50m semplificata a ~3 km: basta per non
 * attraversare una penisola, non per passare fra due scogli.
 */
final class Terra
{
    /** Larghezza del corridoio navigabile intorno ai canali, per parte. */
    public const CORRIDOIO_NM = 2.0;
    /** Passo con cui si campiona un segmento per vedere se tocca terra. */
    private const PASSO_NM = 0.4;
    /** Altezza delle fasce dell'indice, in gradi di latitudine. */
    private const FASCIA = 0.25;
    /** Lato della maglia su cui si cerca la rotta, in gradi di latitudine. */
    private const MAGLIA = 1.0 / 12.0;

    /**
     * Passaggi stretti che non sono il canale di una base, e che la maglia
     * della ricerca, da sola, chiuderebbe. Gibilterra: sette miglia e mezzo
     * nel punto piu' stretto, e gli U-Boot la passavano davvero, di notte e in
     * immersione, sfruttando la corrente entrante.
     */
    private const PASSAGGIO_NM = 4.0;     // mezza larghezza: lo stretto e' largo da otto a dodici miglia
    private const PASSAGGI = [
        'gibilterra' => [[35.93, -6.10], [35.95, -5.75], [35.97, -5.45], [36.02, -5.25], [36.10, -4.95]],
    ];

    /** @var array<int,list<array{0:float,1:float,2:float,3:float}>>|null  fascia => lati [y1,x1,y2,x2] */
    private static ?array $fasce = null;
    /** @var array<string,list<array{0:float,1:float}>>|null */
    private static ?array $canali = null;
    /** @var array<string,array{0:float,1:float,2:float,3:float}> */
    private static array $riquadri = [];

    /**
     * @param list<array{0:float,1:float}> $punti
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function riquadro(array $punti): array
    {
        $lat = array_column($punti, 0);
        $lon = array_column($punti, 1);
        $mLon = 0.1 / max(0.2, cos(deg2rad(max(array_map('abs', $lat)))));
        return [min($lat) - 0.1, max($lat) + 0.1, min($lon) - $mLon, max($lon) + $mLon];
    }

    // --- dati ---------------------------------------------------------------

    private static function carica(): void
    {
        if (self::$fasce !== null) {
            return;
        }
        $radice = $GLOBALS['__project_root'] ?? dirname(__DIR__, 2);
        $js = (string) @file_get_contents($radice . '/assets/js/coste.js');
        preg_match_all('/\{b:\[[^\]]*\],p:\[([^\]]*)\]/', $js, $m);
        $fasce = [];
        foreach ($m[1] as $grezzo) {
            $p = array_map('floatval', explode(',', $grezzo));
            $k = count($p);
            for ($i = 0, $j = $k - 2; $i < $k; $j = $i, $i += 2) {
                $y1 = $p[$j]; $x1 = $p[$j + 1]; $y2 = $p[$i]; $x2 = $p[$i + 1];
                if ($y1 === $y2) {
                    continue;                      // un lato orizzontale non taglia un raggio orizzontale
                }
                $da = (int) floor(min($y1, $y2) / self::FASCIA);
                $a  = (int) floor(max($y1, $y2) / self::FASCIA);
                for ($f = $da; $f <= $a; $f++) {
                    $fasce[$f][] = [$y1, $x1, $y2, $x2];
                }
            }
        }
        self::$fasce = $fasce;
        $file = $radice . '/db/seed/canali.php';
        self::$canali = is_file($file) ? require $file : [];
    }

    /** @return array<string,list<array{0:float,1:float}>> */
    public static function canali(): array
    {
        self::carica();
        return self::$canali ?? [];
    }

    /** L'uscita di una base: l'ultimo punto del suo canale, o null. */
    public static function uscita(string $portKey): ?array
    {
        $c = self::canali()[$portKey] ?? null;
        return $c !== null ? $c[count($c) - 1] : null;
    }

    // --- dove si puo' stare -----------------------------------------------------

    /** Il punto cade dentro un anello di costa (pari-dispari, raggio verso est). */
    private static function dentroCosta(float $la, float $lo): bool
    {
        self::carica();
        $dentro = false;
        foreach (self::$fasce[(int) floor($la / self::FASCIA)] ?? [] as [$y1, $x1, $y2, $x2]) {
            if (($y1 > $la) !== ($y2 > $la) && $lo < ($x2 - $x1) * ($la - $y1) / ($y2 - $y1) + $x1) {
                $dentro = !$dentro;
            }
        }
        return $dentro;
    }

    /**
     * Distanza dal canale piu' vicino, in miglia, con il canale e l'indice del
     * vertice piu' vicino. Approssimazione piana: su pochi chilometri basta.
     *
     * @return array{0:float,1:?string,2:int}
     */
    public static function distanzaCanale(float $la, float $lo): array
    {
        return self::distanzaPolilinee(self::canali(), $la, $lo);
    }

    /**
     * @param array<string,list<array{0:float,1:float}>> $linee
     * @return array{0:float,1:?string,2:int}
     */
    private static function distanzaPolilinee(array $linee, float $la, float $lo): array
    {
        $meglio = [INF, null, 0];
        $kx = cos(deg2rad($la)) * 60.0;
        foreach ($linee as $chiave => $punti) {
            // Riquadro della linea allargato di un decimo di grado (sei miglia,
            // piu' di qualunque corridoio): quasi tutti i punti del mare sono
            // lontani da ogni canale, e il conto si salta.
            $r = self::$riquadri[$chiave] ??= self::riquadro($punti);
            if ($la < $r[0] || $la > $r[1] || $lo < $r[2] || $lo > $r[3]) {
                continue;
            }
            $n = count($punti);
            for ($i = 0; $i < $n - 1; $i++) {
                [$ay, $ax] = $punti[$i];
                [$by, $bx] = $punti[$i + 1];
                $dx = ($bx - $ax) * $kx; $dy = ($by - $ay) * 60.0;
                $px = ($lo - $ax) * $kx;  $py = ($la - $ay) * 60.0;
                $l2 = $dx * $dx + $dy * $dy;
                $t = $l2 > 0 ? max(0.0, min(1.0, ($px * $dx + $py * $dy) / $l2)) : 0.0;
                $d = hypot($px - $t * $dx, $py - $t * $dy);
                if ($d < $meglio[0]) {
                    $meglio = [$d, $chiave, $t < 0.5 ? $i : $i + 1];
                }
            }
        }
        return $meglio;
    }

    /** Dentro il corridoio di un canale o di un passaggio. */
    private static function nelCorridoio(float $la, float $lo): bool
    {
        return self::distanzaCanale($la, $lo)[0] <= self::CORRIDOIO_NM
            || self::distanzaPolilinee(self::PASSAGGI, $la, $lo)[0] <= self::PASSAGGIO_NM;
    }

    /** Terraferma: dentro la costa e fuori dai canali e dai passaggi. */
    public static function aTerra(float $la, float $lo): bool
    {
        return self::dentroCosta($la, $lo) && !self::nelCorridoio($la, $lo);
    }

    /** Il segmento tocca terra da qualche parte (estremi compresi). */
    public static function attraversa(float $la1, float $lo1, float $la2, float $lo2): bool
    {
        $d = Geo::distanceNm($la1, $lo1, $la2, $lo2);
        $n = max(1, (int) ceil($d / self::PASSO_NM));
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n;
            if (self::aTerra($la1 + ($la2 - $la1) * $t, $lo1 + ($lo2 - $lo1) * $t)) {
                return true;
            }
        }
        return false;
    }

    // --- la rotta ------------------------------------------------------------

    /**
     * Una rotta dal primo punto al secondo che non tocchi terra.
     *
     * Restituisce i punti DOPO la partenza, arrivo compreso. Se l'arrivo sta a
     * terra lo si sposta nel mare piu' vicino e lo si dice ('spostato'). Se la
     * partenza o l'arrivo stanno in un canale, la rotta lo percorre fino
     * all'uscita (o dall'uscita), come si faceva: da un porto si esce per il
     * canale, non per la via piu' corta sulla carta.
     *
     * @return array{punti:list<array{0:float,1:float}>, spostato:bool, trovata:bool}
     */
    public static function rotta(float $la1, float $lo1, float $la2, float $lo2): array
    {
        $spostato = false;
        if (self::aTerra($la2, $lo2)) {
            $mare = self::mareVicino($la2, $lo2);
            if ($mare === null) {
                return ['punti' => [], 'spostato' => false, 'trovata' => false];
            }
            [$la2, $lo2] = $mare;
            $spostato = true;
        }
        // Una partenza sulla terraferma: succede alla posizione STIMATA, che dopo
        // ore di deriva puo' finire dentro la costa mentre il battello vero sta
        // in mare. Si comincia dal mare piu' vicino e si pianifica da li'.
        if (self::aTerra($la1, $lo1)) {
            $mare = self::mareVicino($la1, $lo1);
            if ($mare === null) {
                return ['punti' => [], 'spostato' => $spostato, 'trovata' => false];
            }
            $resto = self::rotta($mare[0], $mare[1], $la2, $lo2);
            array_unshift($resto['punti'], $mare);
            $resto['spostato'] = $resto['spostato'] || $spostato;
            return $resto;
        }
        if (!self::attraversa($la1, $lo1, $la2, $lo2)) {
            return ['punti' => [[$la2, $lo2]], 'spostato' => $spostato, 'trovata' => true];
        }

        // Fuori dal canale di partenza, dentro quello d'arrivo.
        $origine = [$la1, $lo1];
        $prima = [];
        $dopo = [];
        [$dp, $cp, $ip] = self::agganciaCanale($la1, $lo1);
        [$da, $ca, $ia] = self::agganciaCanale($la2, $lo2);
        if ($cp !== null && $dp <= self::CORRIDOIO_NM) {
            $punti = self::$canali[$cp];
            if ($cp === $ca && $da <= self::CORRIDOIO_NM) {
                // Partenza e arrivo nello stesso canale: lo si percorre e basta.
                $passo = $ia >= $ip ? 1 : -1;
                $out = [];
                for ($i = $ip; $i !== $ia + $passo; $i += $passo) {
                    $out[] = $punti[$i];
                }
                $out[] = [$la2, $lo2];
                return ['punti' => self::semplifica([$la1, $lo1], $out), 'spostato' => $spostato, 'trovata' => true];
            }
            for ($i = $ip; $i < count($punti); $i++) {
                $prima[] = $punti[$i];
            }
            [$la1, $lo1] = $punti[count($punti) - 1];
        }
        if ($ca !== null && $da <= self::CORRIDOIO_NM) {
            $punti = self::$canali[$ca];
            for ($i = count($punti) - 1; $i >= $ia; $i--) {
                $dopo[] = $punti[$i];
            }
            $dopo[] = [$la2, $lo2];
            [$la2, $lo2] = $punti[count($punti) - 1];
        }

        $mezzo = self::attraversa($la1, $lo1, $la2, $lo2)
            ? self::cerca($la1, $lo1, $la2, $lo2)
            : [[$la2, $lo2]];
        if ($mezzo === null) {
            return ['punti' => [], 'spostato' => $spostato, 'trovata' => false];
        }
        // $dopo comincia dall'uscita del canale d'arrivo, che e' gia' l'ultimo
        // punto di $mezzo: la si salta.
        // Si semplifica dalla posizione vera di partenza, non dal vertice del
        // canale piu' vicino: partendo da quello, il primo tratto reale (dal
        // largo di Brest, dalla rada) poteva tagliare un capo che dal vertice
        // non si vedeva. Trovato il 07/10/2026 da test_terra, una volta su
        // qualche decina, secondo dove il battello si era fermato.
        $tutti = array_merge($prima, $mezzo, array_slice($dopo, 1));
        return ['punti' => self::semplifica($origine, $tutti), 'spostato' => $spostato, 'trovata' => true];
    }

    /**
     * Il canale da cui un punto deve passare, se c'e'.
     *
     * Dentro il corridoio, il canale e' quello. Fuori, conta lo stesso se il
     * punto sta in acque interne — una rada, un estuario, un fiordo — da cui il
     * mare aperto non si vede: la rada di Brest, per esempio, e' acqua sulla
     * carta, ma la maglia della ricerca non ci trova celle abbastanza larghe, e
     * la rotta saltava fuori dal Goulet passando sopra la penisola di Crozon.
     * Allora si aggancia il canale nel vertice piu' vicino che si vede, entro
     * quindici miglia, e lo si percorre.
     *
     * Restituisce [distanza, canale, indice] come distanzaCanale(): distanza
     * zero vuol dire «agganciato», INF «nessun canale».
     *
     * @return array{0:float,1:?string,2:int}
     */
    private static function agganciaCanale(float $la, float $lo): array
    {
        $vicino = self::distanzaCanale($la, $lo);
        if ($vicino[0] <= self::CORRIDOIO_NM) {
            return $vicino;
        }
        // I vertici si scorrono tutti (sono poche decine): il riquadro che
        // velocizza distanzaCanale() taglia a sei miglia, e qui ne servono
        // quindici — la rada di Brest ne dista nove.
        $meglio = null;
        foreach (self::canali() as $chiave => $punti) {
            foreach ($punti as $i => [$y, $x]) {
                $d = Geo::distanceNm($la, $lo, $y, $x);
                if ($d <= 15.0 && ($meglio === null || $d < $meglio[0])) {
                    $meglio = [$d, $chiave];
                }
            }
        }
        if ($meglio === null) {
            return [INF, null, 0];
        }
        $punti = self::$canali[$meglio[1]];
        [$ul, $uo] = $punti[count($punti) - 1];
        if (!self::attraversa($la, $lo, $ul, $uo)) {
            return [INF, null, 0];          // l'uscita si vede: non e' acqua interna
        }
        $visto = null;
        foreach ($punti as $i => [$y, $x]) {
            $d = Geo::distanceNm($la, $lo, $y, $x);
            if ($d <= 15.0 && ($visto === null || $d < $visto[0]) && !self::attraversa($la, $lo, $y, $x)) {
                $visto = [$d, $i];
            }
        }
        return $visto === null ? [INF, null, 0] : [0.0, $meglio[1], $visto[1]];
    }

    /**
     * Il mare piu' vicino a un punto di terra, cercato a spirale sulla maglia
     * fino a una sessantina di miglia.
     *
     * @return array{0:float,1:float}|null
     */
    public static function mareVicino(float $la, float $lo): ?array
    {
        $dlat = self::MAGLIA / 2;
        $dlon = $dlat / max(0.2, cos(deg2rad($la)));
        for ($r = 1; $r <= 24; $r++) {
            $meglio = null;
            for ($i = -$r; $i <= $r; $i++) {
                foreach ([[$i, -$r], [$i, $r], [-$r, $i], [$r, $i]] as [$a, $b]) {
                    $y = $la + $a * $dlat; $x = $lo + $b * $dlon;
                    if (!self::aTerra($y, $x)) {
                        $d = Geo::distanceNm($la, $lo, $y, $x);
                        if ($meglio === null || $d < $meglio[0]) {
                            $meglio = [$d, $y, $x];
                        }
                    }
                }
            }
            if ($meglio !== null) {
                return [$meglio[1], $meglio[2]];
            }
        }
        return null;
    }

    /**
     * A* sulla maglia, poi i punti che si vedono l'uno dall'altro si saltano.
     *
     * La maglia copre il riquadro fra partenza e arrivo con un margine; se non
     * basta (per girare la Scozia, per esempio) si allarga una volta.
     *
     * @return list<array{0:float,1:float}>|null
     */
    private static function cerca(float $la1, float $lo1, float $la2, float $lo2): ?array
    {
        // Due secondi in tutto, non per tentativo: la rotta che non esiste
        // fallisce una volta sola.
        $scadenza = microtime(true) + 2.0;
        // Fra il Mediterraneo e l'oceano si passa per Gibilterra, e il
        // riquadro deve contenerla: se no il primo tentativo, da Brest a
        // Barcellona, esplora tutto il golfo di Biscaglia prima di arrendersi.
        $includi = self::mediterraneo($la1, $lo1) !== self::mediterraneo($la2, $lo2)
            ? self::PASSAGGI['gibilterra'] : [];
        foreach ([[4.0, 6.0], [12.0, 20.0]] as [$mLat, $mLon]) {
            $percorso = self::astar($la1, $lo1, $la2, $lo2, $mLat, $mLon, $scadenza, $includi);
            if ($percorso !== null) {
                return $percorso;
            }
        }
        return null;
    }

    /**
     * Il punto sta nel Mediterraneo (stretto compreso). A grandi linee, ma sul
     * mare basta: a ovest di Tarifa e' oceano, e sopra i 43 gradi a ovest di
     * Greenwich c'e' solo il golfo di Biscaglia.
     */
    private static function mediterraneo(float $la, float $lo): bool
    {
        return $lo > -5.6 && $la < 46.0 && ($la < 43.0 || $lo > 0.0);
    }

    /**
     * @param list<array{0:float,1:float}> $includi  punti che il riquadro deve contenere
     * @return list<array{0:float,1:float}>|null
     */
    private static function astar(float $la1, float $lo1, float $la2, float $lo2, float $mLat, float $mLon, float $scadenza, array $includi = []): ?array
    {
        $lats = [$la1, $la2, ...array_column($includi, 0)];
        $lons = [$lo1, $lo2, ...array_column($includi, 1)];
        $lat0 = max(-60.0, min($lats) - $mLat);
        $lat1 = min(80.0, max($lats) + $mLat);
        $lon0 = max(-108.0, min($lons) - $mLon);
        $lon1 = min(44.0, max($lons) + $mLon);
        $dlat = self::MAGLIA;
        $dlon = self::MAGLIA / max(0.25, cos(deg2rad(($la1 + $la2) / 2)));
        $righe = (int) ceil(($lat1 - $lat0) / $dlat);
        $colonne = (int) ceil(($lon1 - $lon0) / $dlon);

        // Una cella e' acqua se lo sono il centro e i quattro angoli: cosi' il
        // tratto dritto fra due celle vicine non taglia una lingua di terra
        // piu' stretta della cella (le isole Frisone, prima di questa regola,
        // si attraversavano). Le righe si calcolano alla prima richiesta.
        $centri = [];    // riga => '1'/'2'/'0' ai centri delle celle
        $angoli = [];    // bordo => '1'/'2'/'0' agli angoli (colonne + 1 punti)
        $eAcqua = static function (int $r, int $c) use (&$centri, &$angoli, $lat0, $lon0, $dlat, $dlon, $righe, $colonne): bool {
            if ($r < 0 || $c < 0 || $r >= $righe || $c >= $colonne) {
                return false;
            }
            $centri[$r] ??= self::riga($lat0 + ($r + 0.5) * $dlat, $lon0, $dlon, $colonne);
            if ($centri[$r][$c] === '2') {
                // Dentro un canale o un passaggio basta il centro: il corridoio
                // e' acqua per definizione, e gli angoli di una cella larga
                // possono uscirne. Per le rotte lunghe da nord la cella e'
                // larga quasi quanto lo stretto di Gibilterra, e un angolo
                // finiva su Tarifa o sul Marocco: da Capo Farewell a Barcellona
                // non c'era rotta (07/10/2026).
                return true;
            }
            if ($centri[$r][$c] !== '1') {
                return false;
            }
            foreach ([$r, $r + 1] as $b) {
                $angoli[$b] ??= self::riga($lat0 + $b * $dlat, $lon0 - $dlon / 2, $dlon, $colonne + 1);
                if ($angoli[$b][$c] === '0' || $angoli[$b][$c + 1] === '0') {
                    return false;
                }
            }
            return true;
        };
        // Il punto di mezzo di un passo cade sempre sulla maglia o fra le sue
        // righe e colonne: in diagonale e' l'angolo comune (gia' controllato,
        // salvo nei corridoi), in orizzontale sta sulla riga dei centri e fra
        // due colonne, in verticale fra due righe e sulla colonna dei centri.
        // Anche queste righe si calcolano una volta sola: chiederlo punto per
        // punto alla costa triplicava il tempo della ricerca.
        $mezziO = [];    // riga => '1'/'2'/'0' fra le colonne, sulla riga dei centri
        $mezziV = [];    // bordo => '1'/'2'/'0' fra le righe, sulle colonne dei centri
        $mezzoInAcqua = static function (int $r, int $c, int $dr, int $dc) use (&$mezziO, &$mezziV, &$angoli, $lat0, $lon0, $dlat, $dlon, $colonne): bool {
            if ($dr === 0) {
                $mezziO[$r] ??= self::riga($lat0 + ($r + 0.5) * $dlat, $lon0 - $dlon / 2, $dlon, $colonne + 1);
                return $mezziO[$r][$c + ($dc > 0 ? 1 : 0)] !== '0';
            }
            $b = $r + ($dr > 0 ? 1 : 0);
            if ($dc === 0) {
                $mezziV[$b] ??= self::riga($lat0 + $b * $dlat, $lon0, $dlon, $colonne);
                return $mezziV[$b][$c] !== '0';
            }
            $angoli[$b] ??= self::riga($lat0 + $b * $dlat, $lon0 - $dlon / 2, $dlon, $colonne + 1);
            return $angoli[$b][$c + ($dc > 0 ? 1 : 0)] !== '0';
        };
        $cella = static fn (float $la, float $lo): array => [
            (int) floor(($la - $lat0) / $dlat), (int) floor(($lo - $lon0) / $dlon),
        ];
        $centro = static fn (int $r, int $c): array => [$lat0 + ($r + 0.5) * $dlat, $lon0 + ($c + 0.5) * $dlon];

        $chiave = static fn (int $r, int $c): int => $r * $colonne + $c;

        // Partenza e arrivo sulla maglia: le celle d'acqua CHE SI VEDONO dal
        // punto. Prendendo solo la piu' vicina in assoluto, da una rada o da un
        // fiordo si saltava a una cella oltre la penisola, e il primo tratto
        // passava sopra la terra (il golfo del Morbihan, lo Sognefjord, il
        // Frohavet: test_terra, 07/10/2026). E non basta la piu' vicina che si
        // vede: all'imbocco dello Sognefjord quella sta in una sacca chiusa fra
        // gli scogli, e la ricerca non ne usciva. Allora si prendono tutte
        // quelle che si vedono fino a due anelli oltre la prima, e la ricerca
        // parte da tutte insieme (e arriva alla prima che raggiunge).
        $aggancia = static function (float $la, float $lo) use ($cella, $eAcqua, $centro, $chiave): array {
            [$r, $c] = $cella($la, $lo);
            $viste = [];
            $fino = 12;
            for ($raggio = 0; $raggio <= $fino; $raggio++) {
                for ($i = -$raggio; $i <= $raggio; $i++) {
                    for ($j = -$raggio; $j <= $raggio; $j++) {
                        if (max(abs($i), abs($j)) !== $raggio || !$eAcqua($r + $i, $c + $j)) {
                            continue;
                        }
                        [$y, $x] = $centro($r + $i, $c + $j);
                        if (!self::attraversa($la, $lo, $y, $x)) {
                            $viste[$chiave($r + $i, $c + $j)] = [$r + $i, $c + $j, Geo::distanceNm($la, $lo, $y, $x)];
                        }
                    }
                }
                if ($viste !== [] && $fino === 12) {
                    $fino = min(12, $raggio + 2);
                }
            }
            return $viste;
        };
        $partenze = $aggancia($la1, $lo1);
        $mete = $aggancia($la2, $lo2);
        if ($partenze === [] || $mete === []) {
            return null;
        }

        $kx = cos(deg2rad(($la1 + $la2) / 2));
        // Stima pesata (A* «avido» di un 40%): esplora molte meno celle, e il
        // giro in piu' che puo' costare lo toglie la semplificazione dopo.
        $h = static fn (int $r, int $c): float => 1.4 * 60.0 * hypot(
            $lat0 + ($r + 0.5) * $dlat - $la2,
            ($lon0 + ($c + 0.5) * $dlon - $lo2) * $kx,
        );
        $passi = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]] as [$dr, $dc]) {
            $passi[] = [$dr, $dc, 60.0 * hypot($dr * $dlat, $dc * $dlon * $kx)];
        }

        // L'arrivo e' un nodo in piu' (chiave -1), raggiungibile da ogni cella
        // che lo vede: cosi' si arriva per la via piu' corta fino al punto, non
        // fino alla cella.
        $ARRIVO = -1;
        $coda = new \SplPriorityQueue();
        $coda->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $costo = [];
        $da = [];
        foreach ($partenze as $k => [$r, $c, $d]) {
            $costo[$k] = $d;
            $coda->insert([$r, $c], -($d + $h($r, $c)));
        }
        $chiuso = [];
        $espansi = 0;
        while (!$coda->isEmpty()) {
            [$r, $c] = $coda->extract();
            $k = $r === $ARRIVO ? $ARRIVO : $chiave($r, $c);
            if (isset($chiuso[$k])) {
                continue;
            }
            $chiuso[$k] = true;
            if ($k === $ARRIVO) {
                break;
            }
            // Un tetto alle celle e uno al tempo: una rotta che non esiste
            // (dal Mediterraneo alla Groenlandia) non deve tenere ferma una
            // pagina per dieci secondi.
            if (++$espansi > 150000 || ($espansi % 2000 === 0 && microtime(true) > $scadenza)) {
                return null;
            }
            if (isset($mete[$k])) {
                $nuovo = $costo[$k] + $mete[$k][2];
                if (!isset($costo[$ARRIVO]) || $nuovo < $costo[$ARRIVO]) {
                    $costo[$ARRIVO] = $nuovo;
                    $da[$ARRIVO] = $k;
                    $coda->insert([$ARRIVO, $ARRIVO], -$nuovo);
                }
            }
            foreach ($passi as [$dr, $dc, $w]) {
                $nr = $r + $dr; $nc = $c + $dc;
                if (!$eAcqua($nr, $nc)) {
                    continue;
                }
                // In diagonale solo se non si taglia un angolo di terra.
                if ($dr !== 0 && $dc !== 0 && (!$eAcqua($r + $dr, $c) || !$eAcqua($r, $c + $dc))) {
                    continue;
                }
                // E non si passa sopra un isolotto piu' piccolo della cella:
                // centri e angoli in acqua, ma un'isola fra i due centri (le
                // Ebridi Esterne, 07/10/2026). Si guarda il punto di mezzo.
                if (!$mezzoInAcqua($r, $c, $dr, $dc)) {
                    continue;
                }
                $nk = $chiave($nr, $nc);
                $nuovo = $costo[$k] + $w;
                if (!isset($costo[$nk]) || $nuovo < $costo[$nk]) {
                    $costo[$nk] = $nuovo;
                    $da[$nk] = $k;
                    $coda->insert([$nr, $nc], -($nuovo + $h($nr, $nc)));
                }
            }
        }
        if (!isset($chiuso[$ARRIVO])) {
            return null;
        }

        // Dalla cella d'arrivo indietro fino a quella di partenza, COMPRESA: e'
        // l'unica che si sa vedere dal punto di partenza, e la semplificazione
        // prende il primo punto senza controllarlo (davanti a Brest il primo
        // tratto tagliava capo Sizun, 07/10/2026).
        $celle = [];
        for ($k = $da[$ARRIVO]; ; $k = $da[$k]) {
            $celle[] = $centro(intdiv($k, $colonne), $k % $colonne);
            if (!isset($da[$k])) {
                break;
            }
        }
        $celle = array_reverse($celle);
        $celle[] = [$la2, $lo2];
        return $celle;
    }

    /**
     * Una riga della maglia: '1' dove il centro della cella e' acqua, '2' dove
     * e' acqua perche' sta nel corridoio di un canale o di un passaggio. La si
     * riempie per intervalli — gli incroci della latitudine con i lati di
     * costa, ordinati, delimitano la terra a coppie — e poi si riaprono le
     * celle dei canali.
     */
    private static function riga(float $la, float $lon0, float $dlon, int $colonne): string
    {
        self::carica();
        $x = [];
        foreach (self::$fasce[(int) floor($la / self::FASCIA)] ?? [] as [$y1, $x1, $y2, $x2]) {
            if (($y1 > $la) !== ($y2 > $la)) {
                $x[] = ($x2 - $x1) * ($la - $y1) / ($y2 - $y1) + $x1;
            }
        }
        sort($x);
        $riga = str_repeat('1', $colonne);
        for ($i = 0; $i + 1 < count($x); $i += 2) {
            $c0 = max(0, (int) ceil(($x[$i] - $lon0) / $dlon - 0.5));
            $c1 = min($colonne - 1, (int) floor(($x[$i + 1] - $lon0) / $dlon - 0.5));
            for ($c = $c0; $c <= $c1; $c++) {
                $riga[$c] = '0';
            }
        }
        // Canali e passaggi: ogni cella con il centro nel corridoio diventa
        // '2', che sia acqua o terra per la carta. Per non chiedere a ogni
        // cella, si guardano solo le colonne dentro il riquadro di ciascuna
        // linea.
        foreach ([self::$canali ?? [], self::PASSAGGI] as $linee) {
            foreach ($linee as $chiave => $punti) {
                $r = self::$riquadri[$chiave] ??= self::riquadro($punti);
                if ($la < $r[0] || $la > $r[1]) {
                    continue;
                }
                $c0 = max(0, (int) floor(($r[2] - $lon0) / $dlon));
                $c1 = min($colonne - 1, (int) ceil(($r[3] - $lon0) / $dlon));
                for ($c = $c0; $c <= $c1; $c++) {
                    if ($riga[$c] !== '2' && self::nelCorridoio($la, $lon0 + ($c + 0.5) * $dlon)) {
                        $riga[$c] = '2';
                    }
                }
            }
        }
        return $riga;
    }

    /**
     * Toglie i punti che non servono: da ciascun punto si salta al piu' lontano
     * che si raggiunge senza toccare terra.
     *
     * @param array{0:float,1:float} $da
     * @param list<array{0:float,1:float}> $punti
     * @return list<array{0:float,1:float}>
     */
    private static function semplifica(array $da, array $punti): array
    {
        // Il punto piu' lontano che si vede si cerca a salti che raddoppiano,
        // poi per dicotomia fra l'ultimo visto e il primo nascosto. Non e' per
        // forza il piu' lontano in assoluto (la visibilita' lungo una rotta non
        // e' monotona), ma ci va vicino con pochi controlli: scendere di un
        // punto alla volta dal fondo costava due secondi su una rotta che gira
        // la Scozia, perche' ogni tentativo fallito ripercorre centinaia di
        // miglia prima di trovare la terra.
        $vede = static fn (array $a, array $b): bool => !self::attraversa($a[0], $a[1], $b[0], $b[1]);
        $out = [];
        $corrente = $da;
        $n = count($punti);
        $i = 0;
        while ($i < $n) {
            $buono = $i;                    // il primo si prende comunque: e' la maglia a garantirlo
            $passo = 1;
            $cattivo = null;
            while ($buono + $passo < $n) {
                if ($vede($corrente, $punti[$buono + $passo])) {
                    $buono += $passo;
                    $passo *= 2;
                } else {
                    $cattivo = $buono + $passo;
                    break;
                }
            }
            if ($cattivo !== null) {
                while ($cattivo - $buono > 1) {
                    $m = intdiv($buono + $cattivo, 2);
                    if ($vede($corrente, $punti[$m])) {
                        $buono = $m;
                    } else {
                        $cattivo = $m;
                    }
                }
            }
            $out[] = $punti[$buono];
            $corrente = $punti[$buono];
            $i = $buono + 1;
        }
        return $out;
    }
}
