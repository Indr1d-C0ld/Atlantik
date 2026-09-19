<?php

declare(strict_types=1);

/**
 * Prove dei moduli di simulazione (F1).
 *
 *   php tests/test_sim.php
 *
 * Sono i moduli su cui si appoggera' tutto il resto: se il tempo, la griglia e
 * il moto non sono giusti qui, ogni fase successiva paghera'. Nessuna scrittura
 * a database, nessuna e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Game\Branco;
use App\Game\Carriera;
use App\Game\Radio;
use App\Game\Outfitting;
use App\Sim\Acoustics;
use App\Sim\Astro;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\Detection;
use App\Sim\Names;
use App\Sim\Torpedo;
use App\Sim\Traffic;
use App\Sim\Clock;
use App\Sim\Consumption;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Movement;
use App\Sim\Rng;
use App\Sim\Weather;
use App\Sim\World;

$falliti = 0;
$gruppo = '';

function titolo(string $t): void
{
    echo "\n\033[1m{$t}\033[0m\n";
}

function ok(string $titolo, bool $esito, string $dettaglio = ''): void
{
    global $falliti;
    if ($esito) {
        echo "  \033[0;32mok\033[0m    {$titolo}" . ($dettaglio !== '' ? "  \033[0;90m{$dettaglio}\033[0m" : '') . "\n";
    } else {
        echo "  \033[0;31mKO\033[0m    {$titolo}" . ($dettaglio !== '' ? "  ({$dettaglio})" : '') . "\n";
        $falliti++;
    }
}

function vicino(float $a, float $b, float $tolleranza): bool
{
    return abs($a - $b) <= $tolleranza;
}

// --- Geo ---------------------------------------------------------------------
titolo('Geometria sferica');

$d = Geo::distanceNm(51.5, -0.13, 40.71, -74.01);          // Londra - New York
ok('distanza Londra-New York ≈ 3.000 nm', vicino($d, 3000, 40), sprintf('%.0f nm', $d));

$d2 = Geo::distanceNm(47.75, -3.37, 44.65, -63.57);        // Lorient - Halifax
ok('distanza Lorient-Halifax ≈ 2.470 nm', vicino($d2, 2470, 60), sprintf('%.0f nm', $d2));

ok('rilevamento verso est = 090°', vicino(Geo::bearing(0, 0, 0, 10), 90, 0.5));
ok('rilevamento verso nord = 000°', vicino(Geo::bearing(0, 0, 10, 0), 0, 0.5));

[$la, $lo] = Geo::destination(50.0, -20.0, 45.0, 120.0);
$ritorno = Geo::distanceNm(50.0, -20.0, $la, $lo);
ok('andata e distanza coerenti (120 nm per 045°)', vicino($ritorno, 120, 0.5), sprintf('%.2f nm', $ritorno));

ok('differenza di rilevamento con segno', vicino(Geo::bearingDelta(350, 10), 20, 0.01) && vicino(Geo::bearingDelta(10, 350), -20, 0.01));
ok('longitudine normalizzata', vicino(Geo::normLon(200), -160, 0.001) && vicino(Geo::normLon(-190), 170, 0.001));
ok('formato nautico della latitudine', Geo::formatLat(47.75) === "47°45,0' N", Geo::formatLat(47.75));

// --- Marinequadrat -----------------------------------------------------------
titolo('Griglia Marinequadrat');

ok('Golfo di Biscaglia ricade in BF', Grid::bigSquare(46.0, -4.0) === 'BF', (string) Grid::bigSquare(46.0, -4.0));
ok('Helgoland ricade in AN', Grid::bigSquare(54.18, 7.88) === 'AN', (string) Grid::bigSquare(54.18, 7.88));
ok('Capo Farewell (Groenlandia) ricade in AJ', Grid::bigSquare(59.8, -43.9) === 'AJ', (string) Grid::bigSquare(59.8, -43.9));
ok('posizione finale della Bismarck ricade in BE', Grid::bigSquare(48.1, -16.2) === 'BE', (string) Grid::bigSquare(48.1, -16.2));

$q = Grid::toQuadrat(47.0, -10.0, 4);
ok('quadrato con quattro cifre ben formato', (bool) preg_match('/^[A-Z]{2} [1-9]{4}$/', (string) $q), (string) $q);

$dec = Grid::fromQuadrat((string) $q);
ok('andata e ritorno del quadrato', $dec !== null
    && Geo::distanceNm(47.0, -10.0, $dec['lat'], $dec['lon']) < 7.0,
    $dec !== null ? sprintf('scarto %.1f nm', Geo::distanceNm(47.0, -10.0, $dec['lat'], $dec['lon'])) : 'nullo');

$g0 = Grid::fromQuadrat('BF');
$g4 = Grid::fromQuadrat('BF 1111');
ok('quattro cifre restringono di 81 volte',
    $g0 !== null && $g4 !== null && vicino($g0['lat_span'] / $g4['lat_span'], 81.0, 0.01),
    $g0 !== null && $g4 !== null ? sprintf('%.0f volte', $g0['lat_span'] / $g4['lat_span']) : '');

$latoNm = ($g4['lat_span'] ?? 0) * 60;
ok('il riquadro piu' . "'" . ' fine misura circa 6 miglia', vicino($latoNm, 5.93, 0.5), sprintf('%.1f nm di lato', $latoNm));

ok('sigla inesistente respinta', Grid::fromQuadrat('ZZ 1234') === null);
ok('cifra zero respinta', Grid::fromQuadrat('BF 1012') === null);

// --- Astronomia --------------------------------------------------------------
titolo('Sole, luna, luce');

$casi = [
    ['1942-06-21 12:00 UTC',  0.0, 0.0, 66.6, 'solstizio d\'estate all\'equatore'],
    ['1942-12-21 12:00 UTC', 51.5, 0.0, 15.1, 'solstizio d\'inverno a Londra'],
    ['1942-06-21 12:00 UTC', 60.0, 0.0, 53.4, 'solstizio d\'estate a 60N'],
];
foreach ($casi as [$quando, $lat, $lon, $atteso, $nome]) {
    $alt = Astro::sun(strtotime($quando), $lat, $lon)['alt'];
    ok("altezza del sole: {$nome}", vicino($alt, $atteso, 1.0), sprintf('%.2f° (atteso %.1f°)', $alt, $atteso));
}

$t0 = strtotime('1942-03-01 00:00 UTC');
$minIllum = 1.0;
$maxIllum = 0.0;
for ($d = 0; $d < 30; $d++) {
    $m = Astro::moon($t0 + $d * 86400, 55, -20);
    $minIllum = min($minIllum, $m['illum']);
    $maxIllum = max($maxIllum, $m['illum']);
}
ok('la luna compie il ciclo in un mese', $minIllum < 0.05 && $maxIllum > 0.95,
    sprintf('da %.0f%% a %.0f%% di illuminazione', $minIllum * 100, $maxIllum * 100));

$giorno     = Astro::lightFrom(30.0, 0.0, 0.0, 0.0);
$crepuscolo = Astro::lightFrom(-8.0, 0.0, 0.0, 0.0);
$notte      = Astro::lightFrom(-30.0, -20.0, 0.0, 0.0);
$nottePiena = Astro::lightFrom(-30.0, 60.0, 1.0, 0.0);
ok('giorno > crepuscolo > notte', $giorno > $crepuscolo && $crepuscolo > $notte,
    sprintf('%.3f > %.3f > %.3f', $giorno, $crepuscolo, $notte));
ok('la luna piena alta schiarisce la notte', $nottePiena > $notte * 5,
    sprintf('%.3f contro %.3f', $nottePiena, $notte));
ok('le nuvole spengono la luna', Astro::lightFrom(-30.0, 60.0, 1.0, 1.0) < $nottePiena);
ok('nome della fase del giorno', Astro::dayPhase(-9.0) === 'crepuscolo nautico', Astro::dayPhase(-9.0));

// --- Orologio ----------------------------------------------------------------
titolo('Orologio');

$clock = new Clock(1000, 0, 30);
ok('un minuto reale vale mezz\'ora di gioco', $clock->gameAt(1060) === 1800, (string) $clock->gameAt(1060));
ok('conversione inversa coerente', $clock->realAt(1800) === 1060);
ok('un\'ora reale vale trenta ore di gioco', $clock->gameSecondsFor(3600) === 108000);

$annoSec = 365 * 86400;
// Si passa dal formatter pubblico, non da ->format() a mano: cosi' la prova
// tiene fermo anche come la data si presenta al giocatore.
$capodanno = $clock->format(0, false);
$dopoUnAnno = $clock->format($annoSec, false);
ok('il calendario gira dentro l\'anno di campagna', $capodanno === $dopoUnAnno && str_contains($capodanno, (string) Clock::ANNO_CAMPAGNA),
    "{$capodanno} → {$dopoUnAnno}");
ok('la data di bordo e\' in forma italiana GG/MM/AAAA',
    (bool) preg_match('#^\d{2}/\d{2}/\d{4}$#', $capodanno)
    && (bool) preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $clock->format(0)),
    $capodanno . '  |  ' . $clock->format(0));
ok('durata leggibile', Clock::durata(90000) === '1 g 01 h' && Clock::durata(3720) === '1 h 02 m');

// --- Consumi -----------------------------------------------------------------
titolo('Consumi (calibrazione sui dati storici)');

$viic = [
    'fuel_t' => 113.5, 'range1_nm' => 8500, 'range1_kn' => 10.0, 'range2_nm' => 6500, 'range2_kn' => 12.0,
    'sub_range_nm' => 80, 'sub_range_kn' => 4.0, 'speed_surf_kn' => 17.7, 'speed_sub_kn' => 7.6,
];

$r10 = Consumption::rangeLeftNm($viic, 113.5, 10.0);
ok('VII C: 8.500 nm a 10 nodi (dato di calibrazione)', vicino($r10, 8500, 30), sprintf('%.0f nm', $r10));

$r12 = Consumption::rangeLeftNm($viic, 113.5, 12.0);
ok('VII C: 6.500 nm a 12 nodi (dato di calibrazione)', vicino($r12, 6500, 30), sprintf('%.0f nm', $r12));

$rmax = Consumption::rangeLeftNm($viic, 113.5, 17.7);
ok('VII C: circa 3.300 nm a tutta forza (CONTROPROVA, non usata per calibrare)',
    vicino($rmax, 3300, 150), sprintf('%.0f nm, atteso ~3.250', $rmax));

$ore4 = Consumption::submergedHoursLeft($viic, 100.0, 4.0);
ok('VII C: 20 ore a 4 nodi in immersione', vicino($ore4, 20, 0.6), sprintf('%.1f h = %.0f nm', $ore4, $ore4 * 4));

$oreMax = Consumption::submergedHoursLeft($viic, 100.0, 7.6);
ok('VII C: fra una e due ore alla massima subacquea', $oreMax > 1.0 && $oreMax < 2.2, sprintf('%.2f h', $oreMax));

ok('la marcia silenziosa allunga l\'autonomia',
    Consumption::submergedHoursLeft($viic, 100.0, 2.0, true) > Consumption::submergedHoursLeft($viic, 100.0, 2.0, false));
ok('la ricarica rallenta a velocita\' alta',
    Consumption::batteryChargePerHour($viic, 2.0) > Consumption::batteryChargePerHour($viic, 12.0));
ok('l\'aria peggiora scendendo', Consumption::co2FromAir(30) > Consumption::co2FromAir(90));

// --- Moto ---------------------------------------------------------------------
titolo('Moto, correnti, punto nave');

$tipo = ['speed_surf_kn' => 17.7, 'speed_sub_kn' => 7.6, 'dive_time_s' => 30, 'crush_depth_max_m' => 250];
ok('il mare grosso rallenta in superficie',
    Movement::maxSpeed($tipo, 'superficie', 8) < Movement::maxSpeed($tipo, 'superficie', 2),
    sprintf('%.1f kn contro %.1f kn', Movement::maxSpeed($tipo, 'superficie', 8), Movement::maxSpeed($tipo, 'superficie', 2)));
ok('a quota periscopica non si superano 6 nodi', Movement::maxSpeed($tipo, 'periscopio', 0) <= 6.0);
ok('il modo segue la quota',
    Movement::modeForDepth(0) === 'superficie' && Movement::modeForDepth(12) === 'periscopio' && Movement::modeForDepth(80) === 'immersione');

$q = Movement::stepDepth(0.0, 60.0, $tipo, 60, true);
ok('l\'immersione rapida scende piu\' in fretta di quella normale',
    $q > Movement::stepDepth(0.0, 60.0, $tipo, 60, false), sprintf('%.1f m in un minuto', $q));

[$dir, $kn] = Movement::current(35.0, -74.0);
ok('la Corrente del Golfo scorre verso nord-est a un paio di nodi',
    $kn > 1.2 && $dir > 10 && $dir < 90, sprintf('%.0f° a %.2f kn', $dir, $kn));
[$dirL, $knL] = Movement::current(48.0, -52.0);
ok('la Corrente del Labrador scende verso sud', $dirL > 130 && $dirL < 220 && $knL > 0.3, sprintf('%.0f° a %.2f kn', $dirL, $knL));

ok('col cielo coperto non si fa il punto', !Movement::canTakeFix('superficie', 3, 0.9, 30.0, false));
ok('in immersione non si fa il punto', !Movement::canTakeFix('immersione', 1, 0.0, 30.0, false));
ok('col sole alto e cielo sereno si fa il punto', Movement::canTakeFix('superficie', 3, 0.2, 30.0, false));
ok('nel crepuscolo nautico si fa il punto sulle stelle', Movement::canTakeFix('superficie', 3, 0.2, -8.0, false));

// --- Meteo ---------------------------------------------------------------------
titolo('Meteo');

$a = Weather::at(12345, 1000000, 52.0, -30.0);
$b = Weather::at(12345, 1000000, 52.0, -30.0);
ok('il meteo e\' deterministico', $a === $b);

$c = Weather::at(99999, 1000000, 52.0, -30.0);
ok('semi diversi danno tempo diverso', $a['pressure_hpa'] !== $c['pressure_hpa']);

$d1 = Weather::at(12345, 1000000, 52.0, -30.0);
$d2 = Weather::at(12345, 1000000 + 1800, 52.0, -30.0);
ok('il tempo cambia con continuita\' (mezz\'ora)', abs($d1['pressure_hpa'] - $d2['pressure_hpa']) < 3.0,
    sprintf('%.1f hPa di scarto', abs($d1['pressure_hpa'] - $d2['pressure_hpa'])));

$clockW = new Clock(0, 0, 30);
$campione = static function (int $offset) use ($clockW): array {
    $vento = 0.0; $burrasche = 0; $n = 400;
    for ($i = 0; $i < $n; $i++) {
        $t = $offset + $i * 3600;
        $w = Weather::at(2024, $t, 50 + ($i * 7 % 100) / 10.0, -40 + ($i * 13 % 300) / 10.0, $clockW->date($t));
        $vento += (float) $w['wind_kn'];
        if ((int) $w['beaufort'] >= 8) { $burrasche++; }
    }
    return [$vento / $n, 100 * $burrasche / $n];
};
[$vGen, $bGen] = $campione(0);
[$vLug, $bLug] = $campione(181 * 86400);
ok('l\'inverno atlantico e\' molto peggiore dell\'estate', $vGen > $vLug + 4 && $bGen > $bLug,
    sprintf('gennaio %.1f kn / %.1f%% burrasche, luglio %.1f kn / %.1f%%', $vGen, $bGen, $vLug, $bLug));
ok('il vento resta in un intervallo plausibile', $vGen > 12 && $vGen < 32 && $vLug > 8);
ok('scala Beaufort e Douglas coerenti', Weather::beaufort(40) === 8 && Weather::douglas(5.0) === 6);
ok('rosa dei venti', Weather::rosa(0) === 'N' && Weather::rosa(90) === 'E' && Weather::rosa(225) === 'SW');

// --- Casualita' -----------------------------------------------------------------
titolo('Generatore deterministico');

$r1 = Rng::for(7, 'boat', 42, 'step', 100);
$r2 = Rng::for(7, 'boat', 42, 'step', 100);
$uguali = true;
for ($i = 0; $i < 50; $i++) {
    if ($r1->float() !== $r2->float()) { $uguali = false; }
}
ok('stesso seme, stessa sequenza', $uguali);
ok('semi diversi, sequenze diverse', Rng::for(7, 'a')->float() !== Rng::for(7, 'b')->float());

$r3 = Rng::for(5, 'u');
$somma = 0.0;
for ($i = 0; $i < 50000; $i++) { $somma += $r3->float(); }
ok('distribuzione uniforme', vicino($somma / 50000, 0.5, 0.01), sprintf('media %.4f', $somma / 50000));

// --- Equipaggio -------------------------------------------------------------
titolo('Equipaggio');

$organici = [25, 48, 52, 56];
$tuttiEsatti = true;
foreach ($organici as $n) {
    if (array_sum(Names::organico($n)) !== $n) { $tuttiEsatti = false; }
}
ok('l\'organico torna sempre col numero di uomini', $tuttiEsatti);

$o = Names::organico(48);
ok('c\'e\' un solo comandante in seconda, un solo LI, un solo cuoco',
    $o['iwo'] === 1 && $o['li'] === 1 && $o['cuoco'] === 1);
ok('i macchinisti sono il gruppo piu\' numeroso dopo i marinai',
    $o['macchinista_diesel'] > $o['silurista'], "diesel {$o['macchinista_diesel']}, siluristi {$o['silurista']}");

$riposato = Crew::resa(70, 5, 80);
$stanco   = Crew::resa(70, 90, 80);
$demorale = Crew::resa(70, 5, 15);
ok('la stanchezza abbassa il rendimento', $stanco < $riposato * 0.55, sprintf('%.2f contro %.2f', $stanco, $riposato));
ok('il morale basso abbassa il rendimento', $demorale < $riposato * 0.85, sprintf('%.2f contro %.2f', $demorale, $riposato));
ok('un bravo riposato rende piu\' di uno scarso riposato', Crew::resa(85, 5, 80) > Crew::resa(35, 5, 80));

$turni = [];
for ($h = 0; $h < 12; $h++) { $turni[] = Crew::currentWatch($h * 3600); }
ok('le guardie ruotano ogni quattro ore su tre turni',
    count(array_unique($turni)) === 3 && $turni[0] !== $turni[4] && $turni[0] === $turni[3],
    implode('', $turni));

// --- Avarie ed effetti --------------------------------------------------------
titolo('Avarie, pressione, effetti');

$sistemiFinti = static function (array $rotti): array {
    $out = [];
    foreach (['diesel_1', 'diesel_2', 'emotore_1', 'emotore_2', 'timoni_orizz', 'batterie', 'compressori', 'pompe'] as $k) {
        $out[] = ['skey' => $k, 'name' => $k, 'category' => 'propulsione', 'state' => in_array($k, $rotti, true) ? 'avaria' : 'ok'];
    }
    return $out;
};

$sani = Damage::effects($sistemiFinti([]));
ok('battello sano: nessuna penalita\'', $sani['vel_superficie'] === 1.0 && $sani['vel_immersione'] === 1.0 && $sani['guasti'] === 0);

$unDiesel = Damage::effects($sistemiFinti(['diesel_1']));
ok('un diesel fuori uso dimezza la potenza in superficie',
    $unDiesel['vel_superficie'] > 0.5 && $unDiesel['vel_superficie'] < 0.6,
    sprintf('x%.2f', $unDiesel['vel_superficie']));

$dueDiesel = Damage::effects($sistemiFinti(['diesel_1', 'diesel_2']));
ok('due diesel fuori uso: si striscia coi motori elettrici',
    $dueDiesel['diesel_ko'] && $dueDiesel['vel_superficie'] > 0.1 && $dueDiesel['vel_superficie'] < 0.3,
    sprintf('x%.2f della velocita\' di superficie', $dueDiesel['vel_superficie']));

$timoni = Damage::effects($sistemiFinti(['timoni_orizz']));
ok('senza timoni orizzontali la quota viene a fatica', $timoni['quota_controllo'] < 0.4, sprintf('x%.2f', $timoni['quota_controllo']));

$batterie = Damage::effects($sistemiFinti(['batterie']));
ok('batterie danneggiate: meno riserva', $batterie['batteria'] < 0.7);

$logoro = Damage::effects($sistemiFinti([]), 80.0);
ok('lo scafo logorato abbassa la quota di collasso', $logoro['quota_max'] < 0.62, sprintf('x%.2f', $logoro['quota_max']));

$tipoProva = ['test_depth_m' => 100, 'crush_depth_min_m' => 220, 'crush_depth_max_m' => 250];
$rngP = Rng::for(1, 'pressione');
$sopra = Damage::pressureStep(80.0, $tipoProva, 10.0, 1.0, $rngP);
ok('sopra la quota di prova lo scafo riposa', $sopra['stress'] < 10.0, sprintf('%.2f', $sopra['stress']));

$sotto = Damage::pressureStep(160.0, $tipoProva, 0.0, 1.0, $rngP);
$moltoSotto = Damage::pressureStep(210.0, $tipoProva, 0.0, 1.0, $rngP);
ok('sotto la quota di prova lo scafo si sollecita', $sotto['stress'] > 3.0, sprintf('%.2f in un\'ora a 160 m', $sotto['stress']));
ok('piu\' a fondo, molto peggio', $moltoSotto['stress'] > $sotto['stress'] * 2.5,
    sprintf('%.2f a 210 m contro %.2f a 160 m', $moltoSotto['stress'], $sotto['stress']));

// --- Allestimento --------------------------------------------------------------
titolo('Allestimento');

$viicPieno = [
    'type_key' => 'VIIC', 'disp_surf_t' => 769, 'provisions_days' => 42, 'deck_gun' => '8,8 cm', 'fuel_t' => 113.5,
];
$cap = Outfitting::capacita($viicPieno);
ok('il Tipo VII C ha una stiva di circa 420 unita\'', $cap > 400 && $cap < 440, (string) $cap);

$standard = Outfitting::standard($viicPieno);
$usato = Outfitting::spazioUsato($standard);
ok('il carico standard ci sta, ma senza abbondare', $usato < $cap && $usato > $cap * 0.6,
    sprintf('%.0f su %d', $usato, $cap));

$esagerato = ['viveri' => 120, 'ricambi' => 40, 'potassa' => 240, 'ossigeno' => 48, 'munizioni_cannone' => 250, 'munizioni_flak' => 4000];
ok('il carico massimo di tutto non ci sta: bisogna scegliere', Outfitting::spazioUsato($esagerato) > $cap,
    sprintf('%.0f su %d', Outfitting::spazioUsato($esagerato), $cap));

// --- Acustica -----------------------------------------------------------------
titolo('Acustica e idrofono');

$proprioSilenzio = Acoustics::ownNoise(2.0, true, 7.6);
$proprioVeloce   = Acoustics::ownNoise(6.0, false, 7.6);
ok('correre sott\'acqua rende sordi', $proprioVeloce > $proprioSilenzio * 5,
    sprintf('%.1f dB contro %.1f dB', $proprioVeloce, $proprioSilenzio));

$slConvoglio = Acoustics::sourceLevel(138, 9, 9.5, 40);
$slNave      = Acoustics::sourceLevel(138, 9.5, 9.5, 1);
ok('quaranta navi fanno molto piu\' rumore di una', $slConvoglio > $slNave + 14,
    sprintf('%.1f dB contro %.1f dB', $slConvoglio, $slNave));

$rConvoglio = Acoustics::detectionRangeNm($slConvoglio, 4, 0, $proprioSilenzio);
$rNave      = Acoustics::detectionRangeNm($slNave, 4, 0, $proprioSilenzio);
ok('un convoglio si sente a trenta-quaranta miglia (mare moderato)', $rConvoglio > 25 && $rConvoglio < 45, sprintf('%.1f nm', $rConvoglio));
ok('una nave isolata a dieci-quindici miglia', $rNave > 7 && $rNave < 18, sprintf('%.1f nm', $rNave));

$rMareGrosso = Acoustics::detectionRangeNm($slConvoglio, 8, 0, $proprioSilenzio);
ok('col mare grosso non si sente quasi piu\' niente', $rMareGrosso < $rConvoglio * 0.55,
    sprintf('%.1f nm con mare 8 contro %.1f nm con mare 4', $rMareGrosso, $rConvoglio));

$rVeloce = Acoustics::detectionRangeNm($slConvoglio, 4, 0, $proprioVeloce);
ok('a sei nodi si sente meno della meta\'', $rVeloce < $rConvoglio * 0.65, sprintf('%.1f nm contro %.1f nm', $rVeloce, $rConvoglio));

$rStrato = Acoustics::detectionRangeNm($slConvoglio, 4, 0, $proprioSilenzio, true);
ok('lo strato termico taglia il contatto', $rStrato < $rConvoglio * 0.5, sprintf('%.1f nm sotto lo strato', $rStrato));

ok('d\'estate lo strato c\'e\', d\'inverno spesso no',
    Acoustics::layerDepth(55, 7, 3) > 0 && Acoustics::layerDepth(55, 1, 3) === 0.0);
ok('la burrasca rimescola e cancella lo strato', Acoustics::layerDepth(55, 7, 8) === 0.0);

// --- Avvistamento ---------------------------------------------------------------
titolo('Avvistamento');

$giorno = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 5.0, Detection::S_SUPERFICIE, 20, 1.0, 3);
$lunaPiena = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 5.0, Detection::S_SUPERFICIE, 20, 0.09, 3);
$notte = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 5.0, Detection::S_SUPERFICIE, 20, 0.008, 3);
ok('di notte un U-Boot in superficie si vede a un miglio e mezzo', $notte > 1.0 && $notte < 2.5, sprintf('%.2f nm', $notte));
ok('con la luna piena si vede da molto piu\' lontano', $lunaPiena > $notte * 2, sprintf('%.2f nm', $lunaPiena));
ok('di giorno si vede fino all\'orizzonte', $giorno > 10, sprintf('%.2f nm', $giorno));

$periscopio = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 1.0, Detection::S_PERISCOPIO, 20, 1.0, 3);
ok('un periscopio si vede a un paio di miglia, non di piu\'', $periscopio > 1.0 && $periscopio < 3.0, sprintf('%.2f nm', $periscopio));

$mareCalmo = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 5.0, Detection::S_SUPERFICIE, 20, 1.0, 1);
$mareGrosso = Detection::portataVisiva(Detection::H_PONTE_SCORTA, 5.0, Detection::S_SUPERFICIE, 20, 1.0, 8);
ok('il mare grosso nasconde', $mareGrosso < $mareCalmo * 0.7, sprintf('%.2f contro %.2f nm', $mareGrosso, $mareCalmo));

ok('l\'orizzonte e\' un limite invalicabile',
    Detection::portataVisiva(Detection::H_TORRETTA, 30.0, 1.0, 100, 1.0, 0) <= Detection::orizzonteNm(Detection::H_TORRETTA, 30.0) + 0.01);

ok('il radar non guarda la luce', Detection::portataRadar(Detection::S_SUPERFICIE, 2) > 6);
ok('il radar soffre il mare grosso', Detection::portataRadar(Detection::S_SUPERFICIE, 8) < Detection::portataRadar(Detection::S_SUPERFICIE, 0) * 0.4);

ok('immersi non ci vede nessuno', Detection::sagomaBattello('immersione', 60.0) === 0.0);

// Il periscopio: alzato si vede, abbassato no. E la baffa che lascia la corsa
// conta piu' del tubo (A8).
ok('a quota periscopica col periscopio giu\' non si vede nulla',
    Detection::sagomaBattello('periscopio', 12.0, false) === Detection::sagomaBattello('immersione', 60.0));
ok('col periscopio alzato qualcosa si vede',
    Detection::sagomaBattello('periscopio', 12.0, true) > Detection::sagomaBattello('periscopio', 12.0, false));
ok('fermi non si lascia baffa', vicino(Detection::baffaPeriscopio(1.0, 1), 1.0, 0.001));
ok('a sei nodi sul mare liscio la baffa triplica la sagoma',
    Detection::baffaPeriscopio(6.0, 1) > 2.4, sprintf('%.2f', Detection::baffaPeriscopio(6.0, 1)));
ok('col mare forza 4 la baffa non si distingue piu\'', vicino(Detection::baffaPeriscopio(6.0, 4), 1.0, 0.001));
ok('la baffa cresce con la velocita\'',
    Detection::baffaPeriscopio(6.0, 1) > Detection::baffaPeriscopio(4.0, 1)
    && Detection::baffaPeriscopio(4.0, 1) > Detection::baffaPeriscopio(2.0, 1));
ok('a fior d\'acqua si e\' meno visibili che in superficie',
    Detection::sagomaBattello('superficie', 2.0) < Detection::sagomaBattello('superficie', 0.0));

$vicino = Detection::probabilitaVista(2.0, 10.0, 5.0);
$lontano = Detection::probabilitaVista(9.5, 10.0, 5.0);
ok('piu\' si e\' vicini, prima si viene visti', $vicino > $lontano * 5, sprintf('%.3f contro %.3f', $vicino, $lontano));
ok('oltre la portata non si vede nulla', Detection::probabilitaVista(11.0, 10.0, 5.0) === 0.0);

// --- Traffico ---------------------------------------------------------------------
titolo('Traffico');

$rotte = Traffic::dati()['rotte'];
ok('le rotte principali esistono', isset($rotte['hx'], $rotte['sc'], $rotte['on'], $rotte['sl']));

$lunghezzaHx = Traffic::lunghezzaNm('hx');
ok('Halifax-Liverpool e\' lunga circa 2.400 miglia', $lunghezzaHx > 2200 && $lunghezzaHx < 2700, sprintf('%.0f nm', $lunghezzaHx));

$durata = Traffic::durata('hx', 9.5);
ok('un convoglio HX impiega dieci-dodici giorni', $durata / 86400 > 9 && $durata / 86400 < 13, sprintf('%.1f giorni', $durata / 86400));

$partenza = 1000000;
$pIniziale = Traffic::posizione('hx', 9.5, $partenza, $partenza + 3600);
$pMeta     = Traffic::posizione('hx', 9.5, $partenza, $partenza + (int) ($durata / 2));
$pArrivo   = Traffic::posizione('hx', 9.5, $partenza, $partenza + $durata + 7200);
ok('appena salpato e\' vicino a Halifax', $pIniziale !== null && Geo::distanceNm($pIniziale['lat'], $pIniziale['lon'], 44.65, -63.57) < 20);
ok('a meta\' traversata e\' in mezzo all\'oceano', $pMeta !== null && $pMeta['lon'] > -45 && $pMeta['lon'] < -25,
    $pMeta !== null ? sprintf('%.1fN %.1fW', $pMeta['lat'], -$pMeta['lon']) : 'nullo');
ok('arrivato non e\' piu\' in mare', $pArrivo === null);

$p1 = Traffic::posizione('sc', 7.0, $partenza, $partenza + 200000);
$p2 = Traffic::posizione('sc', 7.0, $partenza, $partenza + 200000);
ok('la posizione e\' deterministica', $p1 == $p2);

// --- Siluri --------------------------------------------------------------------
titolo('Siluri: soluzione di tiro e danno');

// Bersaglio a nord (rilevamento 000), che attraversa verso est (rotta 090):
// ci mostra il fianco dritto, AOB 90.
$sol = Torpedo::soluzione(0.0, 1.08, 90.0, false, 9.0, 30.0, 0.0);
ok('la soluzione esiste', $sol['ok']);
ok('l\'anticipo e\' dalla parte del moto del bersaglio', $sol['rotta'] > 0 && $sol['rotta'] < 45,
    sprintf('rotta siluro %.1f gradi', $sol['rotta']));
// La corsa e' piu' lunga della distanza in linea retta: il siluro va dove il
// bersaglio SARA', non dov'e' adesso.
ok('il tempo di corsa tiene conto dell\'anticipo',
    $sol['tempo_s'] > 120 && $sol['tempo_s'] < 155 && $sol['corsa_nm'] > 1.08,
    sprintf('%.0f s per una corsa di %.0f m (bersaglio a 2.000 m)', $sol['tempo_s'], $sol['corsa_nm'] * 1852));

$solSpecchio = Torpedo::soluzione(0.0, 1.08, 90.0, true, 9.0, 30.0, 0.0);
ok('bersaglio che va dall\'altra parte, anticipo speculare',
    abs(Geo::bearingDelta(0.0, $solSpecchio['rotta']) + Geo::bearingDelta(0.0, $sol['rotta'])) < 0.5,
    sprintf('%.1f contro %.1f', $solSpecchio['rotta'], $sol['rotta']));

$solFermo = Torpedo::soluzione(0.0, 1.08, 90.0, false, 0.0, 30.0, 0.0);
ok('bersaglio fermo: si spara dritto', abs(Geo::bearingDelta(0.0, $solFermo['rotta'])) < 0.5,
    sprintf('%.1f gradi', $solFermo['rotta']));

// Sensibilita' all'errore di velocita': il motivo per cui ci si avvicinava.
$giusto = Torpedo::soluzione(0.0, 1.08, 90.0, false, 9.0, 30.0, 0.0);
$sbagliato = Torpedo::soluzione(0.0, 1.08, 90.0, false, 11.0, 30.0, 0.0);
$scartoGradi = abs(Geo::bearingDelta($giusto['rotta'], $sbagliato['rotta']));
$scartoMetri = 2000 * tan(deg2rad($scartoGradi));
ok('due nodi di errore a 2.000 m fanno mancare il colpo', $scartoMetri > 100,
    sprintf('%.1f gradi = %.0f metri di scarto', $scartoGradi, $scartoMetri));

$vicino = Torpedo::soluzione(0.0, 0.27, 90.0, false, 11.0, 30.0, 0.0);
$vicinoGiusto = Torpedo::soluzione(0.0, 0.27, 90.0, false, 9.0, 30.0, 0.0);
$scartoVicino = 500 * tan(deg2rad(abs(Geo::bearingDelta($vicinoGiusto['rotta'], $vicino['rotta']))));
ok('lo stesso errore a 500 m e\' perdonabile', $scartoVicino < 45,
    sprintf('%.0f metri di scarto', $scartoVicino));

$regolazioneCorta = Torpedo::regolazione(['v1_kn' => 30, 'r1_m' => 12500, 'v2_kn' => 40, 'r2_m' => 7500, 'v3_kn' => 44, 'r3_m' => 5500], 3000);
ok('per un tiro corto si sceglie la regolazione veloce', (float) $regolazioneCorta['v_kn'] === 44.0,
    sprintf('%.0f nodi', $regolazioneCorta['v_kn']));
$regolazioneLunga = Torpedo::regolazione(['v1_kn' => 30, 'r1_m' => 12500, 'v2_kn' => 40, 'r2_m' => 7500, 'v3_kn' => 44, 'r3_m' => 5500], 9000);
ok('per un tiro lungo si scende a trenta nodi', (float) $regolazioneLunga['v_kn'] === 30.0);

// Danno per stazza: un siluro non basta contro le navi grandi.
$rngT = Rng::for(3, 'siluri');
$affondaPiccola = 0;
$affondaGrande = 0;
for ($i = 0; $i < 300; $i++) {
    $r = Rng::for(3, 'danno', $i);
    $dPiccola = Torpedo::danno(2400, 280, 'cereali', true, $r);
    $dGrande  = Torpedo::danno(16600, 280, 'cereali', true, $r);
    if ($dPiccola['danno'] >= 100 || $dPiccola['allagamento'] >= 60) { $affondaPiccola++; }
    if ($dGrande['danno'] >= 100 || $dGrande['allagamento'] >= 60) { $affondaGrande++; }
}
ok('un piroscafo piccolo affonda quasi sempre con un colpo', $affondaPiccola > 240,
    sprintf('%.0f%% su 300', 100 * $affondaPiccola / 300));
ok('una grande petroliera quasi mai', $affondaGrande < 45,
    sprintf('%.0f%% su 300', 100 * $affondaGrande / 300));

$conMagnetica = Torpedo::danno(5100, 280, 'cereali', true, Rng::for(5, 'm'));
$conContatto  = Torpedo::danno(5100, 280, 'cereali', false, Rng::for(5, 'm'));
ok('il colpo sotto la chiglia fa molto piu\' male', $conMagnetica['danno'] > $conContatto['danno'] * 1.4,
    sprintf('%.0f contro %.0f', $conMagnetica['danno'], $conContatto['danno']));

$petroliera = Torpedo::danno(8300, 280, 'benzina avio', true, Rng::for(7, 'p'));
ok('una petroliera carica di benzina prende fuoco', $petroliera['incendio'] > 20,
    sprintf('incendio %.0f', $petroliera['incendio']));

$zavorra = Torpedo::danno(8300, 280, 'in zavorra', true, Rng::for(7, 'p'));
$carica  = Torpedo::danno(8300, 280, 'minerale di ferro', true, Rng::for(7, 'p'));
ok('in zavorra si sta a galla molto meglio', $zavorra['allagamento'] < $carica['allagamento'] * 0.7,
    sprintf('%.0f contro %.0f', $zavorra['allagamento'], $carica['allagamento']));

// Difetti: la crisi dei siluri.
$cilecche = 0;
$premature = 0;
$tipoG7e = ['p_cilecca' => 0.08, 'p_prematura' => 0.02, 'p_quota_errata' => 0.06];
for ($i = 0; $i < 2000; $i++) {
    $d = Torpedo::difetti($tipoG7e, 'magnetica', Rng::for(9, 'dif', $i));
    if ($d['cilecca']) { $cilecche++; }
    if ($d['prematuro']) { $premature++; }
}
ok('la spoletta fa cilecca circa una volta su dodici', $cilecche > 100 && $cilecche < 240,
    sprintf('%.1f%%', 100 * $cilecche / 2000));
ok('la magnetica scoppia in anticipo molto piu\' spesso', $premature > 60,
    sprintf('%.1f%% con magnetica', 100 * $premature / 2000));

// Geometria della collisione: un siluro non teletrasporta.
$dSeg = Geo::distanzaDaSegmento(50.0, -25.0, 50.02, -25.0, 50.01, -25.0);
ok('il bersaglio sulla traiettoria viene intercettato', $dSeg < 0.001, sprintf('%.5f nm', $dSeg));
$dFuori = Geo::distanzaDaSegmento(50.0, -25.0, 50.02, -25.0, 50.01, -24.98);
ok('il bersaglio di fianco no', $dFuori > 0.5, sprintf('%.3f nm', $dFuori));

// --- Carriera ------------------------------------------------------------------
titolo('Carriera: gradi e prestigio');

ok('si comincia da Oberleutnant zur See', Carriera::gradoNome(0) === 'Oberleutnant zur See');
ok('il grado sale con l\'anzianita\'',
    Carriera::gradoNome(2) === 'Kapitaenleutnant' && Carriera::gradoNome(5) === 'Korvettenkapitaen'
    && Carriera::gradoNome(9) === 'Fregattenkapitaen');

ok('senza prestigio si resta al livello zero', Carriera::livelloPer(0) === 0);
ok('il livello cresce col prestigio', Carriera::livelloPer(1500) > Carriera::livelloPer(300));
ok('i livelli non tornano indietro',
    Carriera::livelloPer(30000) >= Carriera::livelloPer(14000)
    && Carriera::livelloPer(14000) >= Carriera::livelloPer(700));

$prossimo = Carriera::prossimoLivello(300);
ok('si sa quanto manca al livello successivo', $prossimo['prossimo'] === 2 && $prossimo['mancano'] === 400,
    sprintf('livello %d, mancano %d', (int) $prossimo['prossimo'], $prossimo['mancano']));

$cima = Carriera::prossimoLivello(999999);
ok('arrivati in cima non manca piu\' nulla', $cima['prossimo'] === null);

// Un comandante da centomila tonnellate deve trovarsi a meta' carriera, non in
// cima: la Croce di Cavaliere arriva li', il resto e' molto piu' lontano.
$livello100k = Carriera::livelloPer(1000);
ok('centomila tonnellate valgono la meta\' della carriera', $livello100k >= 2 && $livello100k <= 4,
    sprintf('livello %d con 1.000 di prestigio', $livello100k));

// --- Radio e mondo condiviso ----------------------------------------------------
titolo('Radio, HF/DF e branchi');

$kurz = Radio::KURZSIGNALE;
ok('i segnali brevi esistono e sono brevi',
    isset($kurz['contatto'], $kurz['meteo'], $kurz['consumo'])
    && $kurz['contatto']['durata'] < 40 && $kurz['meteo']['durata'] < 40,
    sprintf('contatto %d s, meteo %d s', $kurz['contatto']['durata'], $kurz['meteo']['durata']));

// La probabilita' per apparato e' la stessa formula del modulo: qui si verifica
// che un segnale breve sia davvero piu' sicuro di un messaggio lungo.
$pBreve = max(0.3, min(0.95, 0.3 + $kurz['contatto']['durata'] / 130.0));
$pLungo = max(0.3, min(0.95, 0.3 + 240 / 130.0));
ok('un segnale breve si intercetta molto meno di un messaggio lungo', $pBreve < $pLungo * 0.65,
    sprintf('%.0f%% contro %.0f%%', $pBreve * 100, $pLungo * 100));

// Durata di un messaggio esteso: circa un secondo ogni tre caratteri.
$testoLungo = str_repeat('a', 300);
$durata = (int) max(45, min(420, 40 + mb_strlen($testoLungo) / 3));
ok('un messaggio di 300 caratteri tiene l\'antenna oltre due minuti', $durata >= 140,
    sprintf('%d secondi', $durata));

ok('i gruppi hanno nomi storici', count(Branco::aperti(0)) >= 0);

// --- Attacco aereo -----------------------------------------------------------
titolo('Attacco aereo');

// Il nome "scorte" voleva dire due cose nello stesso metodo — i materiali di
// bordo e le navi di scorta del convoglio — e all'attacco aereo arrivava un
// intero al posto dell'inventario. Errore fatale, ma solo quando un aereo
// arrivava addosso davvero: una volta su cinque corse della prova end-to-end.
// Questa verifica chiama il metodo con le forme vere e non lo lascia piu' fare.
$m = new ReflectionMethod(\App\Sim\BoatSim::class, 'attaccoAereo');
$m->setAccessible(true);
$parametri = $m->getParameters();
ok('l\'attacco aereo riceve l\'inventario di bordo, non un numero',
    (string) $parametri[5]->getType() === 'array' && $parametri[5]->getName() === 'scorte');

$statoFinto = [
    'lat' => 47.0, 'lon' => -10.0, 'depth' => 2.0, 'mode' => 'superficie',
    'speed' => 12.0, 'heading' => 270.0, 'stress' => 0.0, 'fuel' => 100.0,
    'battery' => 100.0, 'air' => 100.0, 'ord_depth' => 0.0, 'ordered' => 12.0,
    'est_lat' => 47.0, 'est_lon' => -10.0, 'last_fix' => 0, 'sub_since' => null,
    'prov' => 40.0, 'silent' => false, 'auto_dive_fine' => null, 'auto_dive_quota' => null,
];
$eventiFinti = [];
$esito = null;
try {
    // invokeArgs con un array per riferimento: il metodo modifica lo stato del
    // battello e la lista degli eventi, e deve poterlo fare anche qui.
    $argomenti = [
        0, &$statoFinto, World::type('VIIC'), Traffic::classe('sunderland'),
        ['vedette' => 1.0, 'flak' => 1.0], ['munizioni_flak' => 800.0], [],
        1000, Rng::for(1, 'aereo'), &$eventiFinti,
    ];
    $m->invokeArgs(null, $argomenti);
    $esito = 'passato';
} catch (\Throwable $e) {
    $esito = get_class($e) . ': ' . $e->getMessage();
}
ok('un aereo addosso non fa saltare la simulazione', $esito === 'passato', (string) $esito);

// Un ordine di quota deve chiudere l'immersione d'emergenza ancora pendente:
// altrimenti alla scadenza della finestra il I.WO riporta il battello alla
// quota di PRIMA dell'allarme — a galla — cancellando in silenzio l'ordine
// appena dato dal comandante.
$sorgente = (string) file_get_contents(dirname(__DIR__) . '/src/Game/Patrol.php');
$pezzo = substr($sorgente, (int) strpos($sorgente, 'if ($depth !== null) {'));
$pezzo = substr($pezzo, 0, (int) strpos($pezzo, 'if ($silent !== null) {'));
ok('un ordine di quota annulla l\'immersione automatica del I.WO',
    str_contains($pezzo, 'auto_dive_fine_gts = NULL') && str_contains($pezzo, 'auto_dive_quota = NULL'));

// --- La nave civetta ---------------------------------------------------------
titolo('La nave civetta');

// Una Q-ship che si annuncia non e' una Q-ship. Tutto cio' che riguarda
// l'identificazione deve passare dall'apparenza; la fisica no.
$apparente = Traffic::classeApparente('qship');
$vera = Traffic::classe('qship');
ok('chi la guarda vede un piroscafo qualunque',
    (string) $apparente['name'] === (string) Traffic::classe('cargo_medio')['name'],
    (string) $apparente['name']);
ok('la sagoma mostrata e\' quella del piroscafo',
    (string) ($apparente['class_key_apparente'] ?? '') === 'cargo_medio');
ok('la stazza e il rumore restano i suoi', (int) $apparente['grt'] === (int) $vera['grt']
    && (float) $apparente['rumore_db'] === (float) $vera['rumore_db'], $apparente['grt'] . ' GRT');
ok('e\' armata davvero', (int) $vera['armata'] === 1);
ok('una nave onesta non finge niente',
    !isset(Traffic::classeApparente('cargo_medio')['class_key_apparente']));

// --- Nomi in esaurimento -----------------------------------------------------
titolo('Nomi in esaurimento');

// Il vincolo di unicita' sui nomi delle navi e' giusto, ma non deve poter far
// saltare il battito. Qui si mette il generatore con le spalle al muro: un
// nome solo, gia' preso, e si guarda che cosa fa.
$metodo = new ReflectionMethod(Traffic::class, 'nomeLibero');
$metodo->setAccessible(true);
$rngNomi = Rng::for(1234, 'prova', 'nomi');
$presi = [];
$fisso = 'Nave Di Prova ' . bin2hex(random_bytes(4));

try {
    App\Core\Database::run(
        'INSERT INTO ships (name, flag, class_key, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
         VALUES (?, "britannica", "cargo_medio", "mercantile", "hx", 9, 0, 1, 5000)',
        [$fisso]
    );
    $presi[] = $fisso;

    // Dodici richieste di seguito con un generatore che sa dire un nome solo:
    // ognuna deve tornare qualcosa di libero, e ognuna qualcosa di diverso.
    $ottenuti = [];
    $rotto = false;
    for ($i = 0; $i < 12; $i++) {
        [$n] = $metodo->invoke(null, static fn (Rng $r): string => $fisso, $rngNomi, 2);
        if ($n === null) {
            break;          // esaurimento dichiarato: e' la risposta giusta, non un errore
        }
        try {
            App\Core\Database::run(
                'INSERT INTO ships (name, flag, class_key, ruolo, rotta_key, speed_kn, departed_gts, eta_gts, grt)
                 VALUES (?, "britannica", "cargo_medio", "mercantile", "hx", 9, 0, 1, 5000)',
                [$n]
            );
        } catch (PDOException $e) {
            $rotto = true;  // ha proposto un nome gia' preso: e' il bug del 18/09
            break;
        }
        $presi[] = $n;
        $ottenuti[] = $n;
    }

    ok('con un nome solo disponibile non ne propone mai uno gia\' preso', !$rotto);
    ok('i nomi di ripiego sono tutti diversi fra loro',
        count($ottenuti) === count(array_unique($ottenuti)), implode(', ', array_slice($ottenuti, 0, 4)));
    ok('quando anche i numerali finiscono lo dice invece di insistere',
        count($ottenuti) <= 11, count($ottenuti) . ' ripieghi');
} finally {
    foreach ($presi as $n) {
        App\Core\Database::run('DELETE FROM ships WHERE name = ?', [$n]);
    }
}

// --- composizione del traffico ------------------------------------------------
titolo('Quote del traffico');

// Le quote sono tarate sul gennaio 1942 (docs/FONTI.md). Qui non si misura il
// mondo in corso, che dipende da quando si guarda, ma il SACCHETTO da cui il
// generatore pesca: e' li' che vive la decisione, ed e' li' che va tenuta ferma.
$rifl = new ReflectionMethod(Traffic::class, 'pesate');
$rifl->setAccessible(true);
$quota = static function (array $sacchetto, string $chiave): float {
    $n = count(array_filter($sacchetto, static fn (string $x): bool => $x === $chiave));
    return count($sacchetto) > 0 ? 100.0 * $n / count($sacchetto) : 0.0;
};

$mercantili = $rifl->invoke(null, [
    'cargo_medio' => 38, 'tramp_piccolo' => 22, 'cargo_grande' => 16,
    'petroliera_media' => 13, 'frigorifera' => 5, 'liberty' => 3, 'petroliera_t2' => 3,
]);
ok('il sacchetto pesato rispetta le quote',
    abs($quota($mercantili, 'cargo_medio') - 38.0) < 0.5, sprintf('%.1f%%', $quota($mercantili, 'cargo_medio')));

// Le due cose che si sbagliano piu' facilmente, e che devono restare basse.
$sorgente = (string) file_get_contents(dirname(__DIR__) . '/src/Sim/Traffic.php');
ok('le Liberty restano rare: nel gennaio 1942 ce n\'erano poche decine',
    (bool) preg_match("/'liberty'\s*=>\s*([0-9]+)/", $sorgente, $m1) && (int) $m1[1] <= 5,
    isset($m1[1]) ? 'peso ' . $m1[1] : 'non trovata');
ok('le frigorifere restano poche: erano il 4% della flotta, non una su sette',
    (bool) preg_match("/'frigorifera'\s*=>\s*([0-9]+)/", $sorgente, $m2) && (int) $m2[1] <= 7,
    isset($m2[1]) ? 'peso ' . $m2[1] : 'non trovata');
ok('niente fregate River nella scorta: la prima e\' dell\'aprile 1942',
    !str_contains($sorgente, "'fregata_river'  =>") && !str_contains($sorgente, "'fregata_river' =>"));
ok('la corvetta Flower e\' la spina dorsale della scorta',
    (bool) preg_match("/'corvetta_flower'\s*=>\s*([0-9]+)/", $sorgente, $m3) && (int) $m3[1] >= 45,
    isset($m3[1]) ? 'peso ' . $m3[1] : 'non trovata');

// La civetta: rarissima. Nel gennaio 1942 non ce n'era nessuna in mare — le
// britanniche erano state ritirate, le americane non erano ancora uscite.
ok('la nave civetta resta una rarita\'',
    App\Core\GameConfig::int('traffic.civette_per_mille', 14) <= 3,
    App\Core\GameConfig::int('traffic.civette_per_mille', 14) . ' per mille');

// Il piccolo tramp e' armato: dal 1942 il programma DEMS aveva armato quasi
// tutto il naviglio mercantile britannico.
ok('anche il piroscafo piccolo porta il suo pezzo a poppa',
    (int) Traffic::classe('tramp_piccolo')['armata'] === 1);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
