<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;

/**
 * Le scorte: come cercano, come attaccano, che danno fanno.
 *
 * E' la parte lunga di ogni incontro, ed e' quella che uccide. Un cacciatorpediniere
 * non "sa" dove siamo: ha un contatto, con una sua qualita', che nasce
 * dall'ASDIC e dall'idrofono e si consuma da solo. Nell'ultimo tratto
 * dell'accosto l'ASDIC passa sopra il battello e il contatto si perde: le
 * cariche cadono su dove eravamo, non su dove siamo. Tutta l'evasione si gioca
 * in quel buco.
 *
 * Stava dentro Encounter, che aveva superato le 1.400 righe e portava da solo
 * macchina a stati, formazione, siluri, scorte e cariche (audit A7). Il
 * comportamento e' identico: sono le stesse righe, in una casa loro.
 */
final class Scorte
{
    /**
     * Comportamento delle scorte.
     *
     * Una scorta non "sa" dove siamo: ha un contatto, con una sua qualita', che
     * si guadagna e si perde. L'ASDIC e' attivo e ci trova anche in silenzio,
     * ma sotto lo strato termico fatica; l'idrofono invece sente il nostro
     * rumore, e li' la marcia silenziosa serve. Nell'ultimo tratto dell'accosto
     * l'ASDIC passa sopra di noi e il contatto si perde: il lancio delle
     * cariche e' su dove eravamo, non su dove siamo. Tutta l'evasione si gioca
     * in quel buco.
     *
     * @return array{eventi:list<string>,cariche:int,danno:float,scoperti:bool}
     */
    public static function ai(
        array &$entita,
        array $b,
        array $type,
        int $encId,
        int $t,
        int $passo,
        int $mare,
        float $strato,
        float $luce,
        array $effetti,
        Rng $rng,
        bool $allarme,
        array $mig = [],
    ): array {
        $eventi = [];
        $cariche = 0;
        $danno = 0.0;
        $scoperti = false;

        $sottoStrato = $strato > 0 && $b['depth'] > $strato + 10;

        // Centro del convoglio: le scorte non navigano per conto loro, stanno
        // in stazione attorno alle navi che devono proteggere.
        $cLat = 0.0; $cLon = 0.0; $nMerc = 0; $rottaConvoglio = null;
        foreach ($entita as $e) {
            if ((string) $e['ruolo'] === 'scorta' || in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
                continue;
            }
            $cLat += (float) $e['lat'];
            $cLon += (float) $e['lon'];
            $rottaConvoglio ??= (float) $e['heading'];
            $nMerc++;
        }
        $centro = $nMerc > 0 ? ['lat' => $cLat / $nMerc, 'lon' => $cLon / $nMerc] : null;
        // Il periscopio conta per quello che e': alzato si vede, abbassato no.
        // Prima qui c'era un 'true' fisso, e a quota periscopica il battello
        // era esposto al massimo qualunque cosa facesse il comandante (A8).
        $sagoma = Detection::sagomaBattello($b['mode'], $b['depth'], $b['periscopio']);
        if ($b['mode'] === 'periscopio' && $b['periscopio']) {
            $sagoma *= Detection::baffaPeriscopio($b['speed'], $mare);
        }
        // Le sospensioni elastiche valgono qui. Fino all'audit del 19/09/2026
        // l'apparato — centodieci punti di assegnazione, meno ventidue per
        // cento di rumore proprio — si applicava soltanto in crociera, dove
        // serve a non farsi sentire da un piroscafo di passaggio, e si spegneva
        // esattamente dove era stato comprato: sotto una scorta che ascolta.
        $rumoreNostro = Acoustics::ownNoise($b['speed'], $b['silent'], (float) $type['speed_sub_kn'])
            * (float) ($mig['rumore_proprio'] ?? 1.0);

        foreach ($entita as &$e) {
            if ((string) $e['ruolo'] !== 'scorta' || in_array((string) $e['stato'], ['affondata', 'fuggita'], true)) {
                continue;
            }
            $cls = Traffic::classe((string) $e['class_key']);
            $d = Geo::distanceNm((float) $e['lat'], (float) $e['lon'], $b['lat'], $b['lon']);
            $dMetri = $d * 1852.0;
            $contatto = (float) $e['contatto'];

            // --- ci trovano? --------------------------------------------------
            $pRileva = 0.0;

            if ($b['mode'] === 'superficie' || $sagoma > 0.0) {
                $portataVista = Detection::portataVisiva(
                    Detection::H_PONTE_SCORTA, $b['mode'] === 'superficie' ? 5.0 : 1.0, $sagoma, 20.0, $luce, $mare, 1.2
                );
                $pRileva = Detection::probabilitaVista($d, $portataVista, $passo / 60.0, 1.2);
                if ((int) $cls['radar'] === 1) {
                    $pRadar = Detection::probabilitaVista($d, Detection::portataRadar($sagoma, $mare), $passo / 60.0, 1.0);
                    $pRileva = 1.0 - (1.0 - $pRileva) * (1.0 - $pRadar);
                }
            }

            // ASDIC: portata utile fra i mille e i duemilacinquecento metri,
            // rovinata dal mare grosso, dallo strato e dalla scia del convoglio.
            if ($b['mode'] !== 'superficie' && (int) $cls['asdic'] === 1 && ($allarme || $contatto > 0.05)) {
                $portataAsdic = 2300.0 * max(0.35, 1.0 - 0.07 * max(0, $mare - 2));
                if ($sottoStrato) {
                    $portataAsdic *= 0.35;
                }
                if ($dMetri < $portataAsdic) {
                    // Due ciechi in uno. Il primo e' il cono sotto la nave:
                    // negli ultimi duecento metri dell'accosto il fascio passa
                    // sopra il bersaglio e il contatto si perde — ed e' li' che
                    // si lanciano le cariche, alla cieca.
                    // Il secondo, decisivo, e' l'angolo: l'ASDIC lavora quasi
                    // orizzontale e non sa guardare in basso. Un battello a
                    // centoventi metri, quando la scorta gli arriva sopra, esce
                    // semplicemente dal fascio. E' per questo che la profondita'
                    // salvava i battelli, e per questo gli alleati inventarono
                    // le armi a lancio in avanti.
                    $angoloSotto = rad2deg(atan($b['depth'] / max(30.0, $dMetri)));
                    $fuoriFascio = $angoloSotto > 32.0;

                    $pAsdic = ($dMetri < 200.0 || $fuoriFascio)
                        ? 0.0
                        : 0.28 * (1.0 - $dMetri / $portataAsdic) * (1.0 - min(0.6, $angoloSotto / 55.0));
                    $pRileva = max($pRileva, $pAsdic * ($passo / 30.0));
                }
            }

            // Idrofono della scorta: sente il nostro rumore, e qui il silenzio paga.
            if ($b['mode'] !== 'superficie' && $d < 6.0) {
                $snr = Acoustics::snr(
                    118.0 + $rumoreNostro, $d, $mare, 0.0, 12.0, $sottoStrato, Acoustics::DI_KDB
                );
                if ($snr > Acoustics::SOGLIA_SNR) {
                    $pRileva = max($pRileva, 0.25 * ($passo / 60.0));
                }
            }

            if ($pRileva > 0 && $rng->chance(min(0.95, $pRileva))) {
                if ($contatto < 0.25) {
                    $scoperti = true;
                    if (!$allarme) {
                        $eventi[] = sprintf('%s ha accostato verso di noi: ci hanno trovati.', (string) $e['name']);
                    }
                }
                $contatto = min(1.0, $contatto + 0.35);
                $e['manovra'] = $dMetri < 900 ? 'attacco' : 'caccia';
            } else {
                // Il contatto si sfilaccia: piu' in fretta se siamo silenziosi.
                $contatto = max(0.0, $contatto - ($b['silent'] ? 0.045 : 0.022) * ($passo / 30.0));
                if ($contatto < 0.08 && (string) $e['manovra'] !== 'stazione') {
                    $e['manovra'] = 'ricerca';
                }
            }
            $e['contatto'] = round($contatto, 3);

            // --- manovra -------------------------------------------------------
            $velMax = (float) $cls['speed_kn'];
            switch ((string) $e['manovra']) {
                case 'attacco':
                    $e['heading'] = Geo::bearing((float) $e['lat'], (float) $e['lon'], $b['lat'], $b['lon']);
                    $e['speed_kn'] = $velMax * 0.75;
                    break;
                case 'caccia':
                    $e['heading'] = Geo::bearing((float) $e['lat'], (float) $e['lon'], $b['lat'], $b['lon']);
                    $e['speed_kn'] = $velMax * 0.9;
                    break;
                case 'ricerca':
                    // Spirale di ricerca attorno all'ultimo punto. Non mollano
                    // subito: un gruppo di scorta restava sopra un contatto per
                    // ore, e i gruppi di supporto anche mezza giornata.
                    $e['heading'] = Geo::normBearing((float) $e['heading'] + 6.0 * ($passo / 30.0));
                    $e['speed_kn'] = $velMax * 0.55;
                    $ultimoContatto = $e['ultimo_attacco_gts'] !== null ? (int) $e['ultimo_attacco_gts'] : $t;
                    if ($t - $ultimoContatto > 5400) {
                        // Passate un'ora e mezza senza niente, tornano al convoglio.
                        $e['manovra'] = 'stazione';
                    }
                    break;
                default:
                    // In stazione: si tiene il posto assegnato rispetto al
                    // convoglio, si zigzaga, e si guarda fuori.
                    if ($centro !== null) {
                        $dCentro = Geo::distanceNm((float) $e['lat'], (float) $e['lon'], $centro['lat'], $centro['lon']);
                        $rilCentro = Geo::bearing((float) $e['lat'], (float) $e['lon'], $centro['lat'], $centro['lon']);
                        if ($dCentro > 3.4) {
                            $e['heading'] = $rilCentro;                                   // rientrare
                        } elseif ($dCentro < 1.8) {
                            $e['heading'] = Geo::normBearing($rilCentro + 180);            // allargarsi
                        } elseif ($rottaConvoglio !== null) {
                            $e['heading'] = Geo::normBearing($rottaConvoglio + sin($t / 300.0) * 25.0);
                        }
                        $e['speed_kn'] = min($velMax, max(6.0, $dCentro > 3.0 ? $velMax * 0.7 : $velMax * 0.45));
                    } else {
                        $e['speed_kn'] = min($velMax, (float) $e['speed_kn']);
                    }
            }

            // --- lancio delle cariche --------------------------------------------
            $ultimo = $e['ultimo_attacco_gts'] !== null ? (int) $e['ultimo_attacco_gts'] : 0;
            // Un accosto vero richiede tempo: riprendere il contatto, mettersi
            // in rotta, correre dentro, lanciare, riaccostare. Dieci minuti
            // buoni fra un rullo e l'altro, non trenta secondi.
            if ((string) $e['manovra'] === 'attacco' && $dMetri < 260.0 && (int) $e['dc_residue'] > 0
                && $t - $ultimo > 700 && $contatto > 0.45) {

                $e['ultimo_attacco_gts'] = $t;
                $lanciate = min((int) $e['dc_residue'], $rng->int(5, 8));
                $e['dc_residue'] = (int) $e['dc_residue'] - $lanciate;
                $cariche += $lanciate;

                // La quota va indovinata: l'ASDIC non la misura bene, e nei
                // primi anni gli alleati la sottovalutavano di sistema — e' il
                // motivo per cui scendere sotto i cento metri salvava i battelli.
                $quotaStimata = max(10.0, $b['depth'] * 0.85 + $rng->gauss() * (16.0 + $b['depth'] * 0.18));

                // Il punto di lancio non e' dove eravamo: e' dove il comandante
                // della scorta CALCOLA che saremo quando le cariche arriveranno
                // alla quota giusta. La bonta' della previsione dipende da
                // quanto era saldo il contatto prima di perderlo nel cono cieco.
                $affondamentoS = $quotaStimata / 3.5;                     // le cariche scendono a ~3,5 m/s
                $anticipo = $affondamentoS + $rng->range(10.0, 30.0);
                [$latMira, $lonMira] = Geo::destination(
                    $b['lat'], $b['lon'], $b['heading'], $b['speed'] * ($anticipo / 3600.0)
                );
                $erroreM = 45.0 + 190.0 * (1.0 - $contatto);
                [$latMira, $lonMira] = Geo::destination(
                    $latMira, $lonMira, $rng->range(0, 360), abs($rng->gauss()) * $erroreM / 1852.0
                );

                $eventi[] = sprintf('%s passa sopra di noi: %s.', (string) $e['name'],
                    plurale($lanciate, 'una carica in mare', '%d cariche in mare'));

                for ($k = 0; $k < $lanciate; $k++) {
                    // Lo schema di lancio copre un rettangolo di una quarantina
                    // di metri per lato: due dai lanciatori laterali, gli altri
                    // dalla rampa di poppa.
                    [$latC, $lonC] = Geo::destination($latMira, $lonMira, $rng->range(0, 360), $rng->range(0.0, 0.022));
                    $distOriz = Geo::distanceNm($latC, $lonC, $b['lat'], $b['lon']) * 1852.0;
                    $distVert = abs($quotaStimata - $b['depth']);
                    $dist3d = sqrt($distOriz ** 2 + $distVert ** 2);

                    if ($dist3d < Encounter::DC_DANNO_M) {
                        // Il danno cresce enormemente avvicinandosi: e' quello
                        // che rendeva il rullo di lanci una lotteria.
                        $danno += 110.0 * (1.0 - $dist3d / Encounter::DC_DANNO_M) ** 2;
                    }
                }
            }

            $scoperti = $scoperti || $contatto > 0.4;
        }
        unset($e);

        return ['eventi' => $eventi, 'cariche' => $cariche, 'danno' => $danno, 'scoperti' => $scoperti];
    }

    /**
     * Applica il danno delle cariche di profondita' al battello.
     *
     * @param list<string> $eventi
     */
    public static function applicaDanno(int $boatId, array &$b, array $type, float $danno, int $t, Rng $rng, array &$eventi): void
    {
        if ($danno <= 0.0) {
            return;
        }

        // Piu' si e' profondi, piu' lo scafo e' gia' sollecitato e meno regge.
        $fattoreQuota = 1.0 + max(0.0, ($b['depth'] - (float) $type['test_depth_m']) / 120.0);
        $b['stress'] = min(100.0, $b['stress'] + $danno * 0.28 * $fattoreQuota);

        if ($danno > 18) {
            $eventi[] = 'Scoppi vicinissimi: le luci si spengono, il sughero piove dal soffitto, l\'acqua entra dai passascafi.';
        } elseif ($danno > 6) {
            $eventi[] = 'Scoppi vicini: il battello sobbalza, vetri rotti in centrale.';
        } else {
            $eventi[] = 'Scoppi lontani: la pressione arriva attutita dallo scafo.';
        }

        // ...e puo' aprire una falla. Fino all'audit del 19/09/2026 il danno si
        // fermava ai sistemi e alla sollecitazione dello scafo: i compartimenti
        // restavano asciutti qualunque cosa succedesse.
        Compartimenti::colpisci($boatId, $danno, $rng, $eventi);

        // Ogni scoppio vicino puo' mettere fuori uso qualcosa.
        $sistemi = Damage::systems($boatId);
        $probabile = max(0.0, min(0.8, ($danno - 8.0) / 60.0));
        foreach ($sistemi as $sy) {
            if ((string) $sy['state'] !== 'ok') {
                continue;
            }
            if ($rng->chance($probabile * 0.16)) {
                $grave = $rng->chance(0.3 + $danno / 200.0);
                Damage::applyFailure($boatId, (string) $sy['skey'], $grave, $t);
                $eventi[] = Narrator::avaria((string) $sy['name'], (string) $sy['compartment'], $grave, (bool) $sy['repairable_sea']);
            }
        }

        // Feriti a bordo.
        if ($danno > 25 && $rng->chance(0.5)) {
            $ferito = Database::first(
                "SELECT * FROM crew_members WHERE boat_id = ? AND health = 'ok' ORDER BY RAND() LIMIT 1",
                [$boatId]
            );
            if ($ferito !== null) {
                Database::run("UPDATE crew_members SET health = 'ferito' WHERE id = ?", [(int) $ferito['id']]);
                $eventi[] = Narrator::ferito((string) $ferito['name'], 'sbattuto contro una paratia dallo scoppio');
            }
        }

        // Lo scafo puo' cedere: e' cosi' che finivano tre battelli su quattro.
        if ($b['stress'] >= 100.0) {
            $eventi[] = 'Lo scafo cede. Non c\'e\' altro da scrivere.';
            $boatRow = Database::first('SELECT * FROM boats WHERE id = ?', [$boatId]);
            if ($boatRow !== null) {
                $boatRow['depth_m'] = $b['depth'];
                $boatRow['lat'] = $b['lat'];
                $boatRow['lon'] = $b['lon'];
                $fine = \App\Game\Comandante::perdita($boatRow, 'scafo ceduto sotto le cariche di profondita\'', $t);
                $eventi[] = $fine['testo'];
            }
        }
    }
}
