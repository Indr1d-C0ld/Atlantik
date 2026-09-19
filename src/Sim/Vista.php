<?php

declare(strict_types=1);

namespace App\Sim;


/**
 * Quello che si vede davvero dalla stazione d'attacco.
 *
 * Il quadro tattico non e' la verita': e' il tavolo di plottaggio della
 * Zentrale, e su quel tavolo ci finisce soltanto cio' che qualcuno ha visto o
 * sentito, con l'errore di chi l'ha visto o sentito.
 *
 * Prima non era cosi'. L'incontro "materializzava" il convoglio e la pagina
 * mostrava tutto: nome, classe, stazza e distanza al metro di quaranta navi,
 * anche a sette chilometri, anche col periscopio abbassato. Il calcolatore di
 * lancio diventava un esercizio di copiatura — i numeri esatti erano gia' li'
 * — e la pagina dei contatti, che invece la regola ce l'aveva (classe solo per
 * i contatti VISTI e classificati oltre il 60%), diceva una cosa diversa dalla
 * pagina accanto.
 *
 * Le regole, e da dove vengono:
 *
 *   SI VEDE solo con un occhio fuori: in superficie dalla torretta, a quota
 *   periscopica col periscopio alzato. Sotto, o col periscopio abbassato, si
 *   pedina a orecchio.
 *
 *   SI RICONOSCE molto piu' vicino di quanto si individui. Una sagoma
 *   all'orizzonte e' una sagoma; per dire "petroliera classe T2" servono i
 *   dettagli — la posizione del ponte, il numero di alberi, il taglio della
 *   poppa — e quelli si risolvono a distanza molto minore, e di notte quasi
 *   mai. E' la stessa distinzione dei manuali di riconoscimento alleati
 *   (ONI 208-J), che distinguono la sagoma dall'identificazione.
 *
 *   SI LEGGE IL NOME solo da vicino e con la luce. Il nome sulla prua o sulla
 *   poppa si legge a poche centinaia di metri. Nei Kriegstagebuecher il nome
 *   compare quasi sempre DOPO, aggiunto a matita: durante l'attacco si scrive
 *   "Dampfer ca. 6000 BRT", e il nome lo mette il BdU quando conferma.
 *
 *   LA STAZZA E' UNA STIMA, e storicamente una stima generosa: le
 *   rivendicazioni degli equipaggi superavano regolarmente il vero. Qui la
 *   stazza mostrata ha il suo errore; quella che finisce in archivio quando la
 *   nave affonda e' invece la vera, perche' quella la accredita il BdU.
 *
 * Le stime non si ri-tirano a ogni aggiornamento di pagina: il seme dipende
 * dal minuto di gioco, quindi il quadro si assesta e si aggiorna quando si
 * aggiorna il plottaggio, non quando il comandante preme F5.
 */
final class Vista
{
    /** Quanto piu' vicino bisogna essere per riconoscere, non solo per vedere. */
    private const QUOTA_RICONOSCIMENTO = 0.55;

    /** Sopra questa certezza la sagoma e' riconosciuta: la stessa soglia della pagina contatti. */
    public const SOGLIA_RICONOSCIUTA = 0.60;

    /** Distanza entro cui si legge un nome scritto sulla murata, in miglia. */
    private const NOME_GIORNO_NM = 0.90;
    private const NOME_NOTTE_NM  = 0.18;   // al buio si legge solo addosso

    /**
     * Il quadro come lo vede la Zentrale.
     *
     * @param list<array<string,mixed>> $entita
     * @return list<array<string,mixed>>
     */
    public static function quadro(
        array $boat,
        array $enc,
        array $entita,
        float $qVedette = 1.0,
        float $qAscolto = 1.0,
        ?array &$sommario = null,
    ): array {
        $sommario = ['risolte' => 0, 'nascoste' => 0, 'viste' => 0, 'riconosciute' => 0, 'occhio' => false];
        $t     = (int) $enc['last_step_gts'];
        $meteo = World::weather((float) $boat['lat'], (float) $boat['lon'], $t);
        $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], $t, (float) $meteo['cloud']);
        $luce  = (float) $cielo['luce'];
        $mare  = (int) $meteo['sea_state'];
        $data  = World::clock()->date($t);

        // Lo strato termico c'e' o non c'e'. Se layerDepth torna zero vuol dire
        // che non c'e' — d'inverno la colonna d'acqua e' rimescolata e isoterma
        // — e allora non c'e' niente da attraversare. Confrontare la quota con
        // uno strato inesistente metterebbe il battello "sotto lo strato"
        // sempre, e l'idrofono non sentirebbe piu' niente: e' la stessa
        // guardia che fanno BoatSim e Scorte, e va fatta anche qui.
        $strato = Acoustics::layerDepth((float) $boat['lat'], (int) $data->format('n'), $mare);

        $modo = (string) $boat['mode'];
        $quotaBattello = (float) $boat['depth_m'];
        $occhio = match (true) {
            $modo === 'superficie' => Detection::H_TORRETTA,
            $modo === 'periscopio' && (bool) ($boat['periscopio_alzato'] ?? 0) => Detection::H_PERISCOPIO,
            default => null,
        };

        $type = World::type((string) $boat['type_key']);
        $rumoreProprio = Acoustics::ownNoise(
            (float) $boat['speed_kn'], (bool) $boat['silent'], max(1.0, (float) $type['speed_sub_kn'])
        );

        $out = [];
        $numero = 0;
        foreach ($entita as $e) {
            $numero++;
            $d   = Geo::distanceNm((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
            $ril = Geo::bearing((float) $boat['lat'], (float) $boat['lon'], (float) $e['lat'], (float) $e['lon']);
            $aobDelta = Geo::bearingDelta((float) $e['heading'], Geo::normBearing($ril + 180.0));

            // La maschera della nave civetta vale anche qui: cade quando spara,
            // non quando la si guarda meglio.
            $mascherata = !(bool) ($e['smascherata'] ?? 0);
            $classeKeyVera = (string) $e['class_key'];
            $classeKey = $mascherata
                ? (string) (Traffic::classeApparente($classeKeyVera)['class_key_apparente'] ?? $classeKeyVera)
                : $classeKeyVera;
            $cls = Traffic::classe($classeKey);

            // Le stime si ri-tirano una volta al minuto di gioco, non a ogni
            // caricamento: altrimenti basterebbe aggiornare la pagina dieci
            // volte e fare la media per avere il valore vero.
            $rng = Rng::for(World::seed(), 'vista', (int) $enc['id'], (int) $e['id'], intdiv($t, 60));

            // --- l'occhio ------------------------------------------------------
            $vista = false;
            $certezza = 0.0;
            if ($occhio !== null && (string) $e['stato'] !== 'affondata') {
                $hAlberi = max(8.0, (float) $cls['length_m'] * 0.20);
                $portata = Detection::portataVisiva(
                    $occhio, $hAlberi, 1.0, (float) $meteo['visibility_nm'], $luce, $mare,
                    $qVedette, (bool) $meteo['fog']
                );
                $vista = $portata > 0.0 && $d <= $portata;

                if ($vista) {
                    // Riconoscere costa distanza e luce. Di notte una sagoma
                    // resta una sagoma anche a mille metri.
                    $portataRic = $portata * self::QUOTA_RICONOSCIMENTO * (0.30 + 0.70 * $luce);
                    $certezza = $portataRic > 0.0
                        ? max(0.0, min(0.97, (1.0 - $d / $portataRic) ** 0.65))
                        : 0.0;
                }
            }

            // --- l'orecchio ----------------------------------------------------
            $sl = Acoustics::sourceLevel(
                (float) $cls['rumore_db'], (float) $e['speed_kn'], max(1.0, (float) $cls['speed_kn']),
                1, (int) $cls['eliche']
            );
            $idro = Detection::idrofono(
                $sl, $d, $mare, (float) $meteo['precip'], $rumoreProprio,
                $strato > 0.0 && $quotaBattello > $strato + 10.0, $qAscolto
            );

            // Una scorta che sta cercando col suo ASDIC si annuncia da sola: il
            // "ping" sullo scafo non lo si confonde con niente altro.
            $pinga = (string) $e['ruolo'] === 'scorta' && (float) ($e['contatto'] ?? 0) > 0.05;

            $osservazione = $vista ? 'vista' : (($idro['udito'] || $pinga) ? 'ascolto' : 'nulla');
            if ($osservazione === 'nulla') {
                // Sul tavolo non ci va: non la sa nessuno. Ma si conta, perche'
                // "molte eliche che non si distinguono" e' anch'essa una
                // notizia, ed e' quella che si aveva da sotto.
                $sommario['nascoste']++;
                continue;
            }

            $identificata = $vista && $certezza >= self::SOGLIA_RICONOSCIUTA;
            $nomeLeggibile = $identificata && $d <= ($luce > 0.45 ? self::NOME_GIORNO_NM : self::NOME_NOTTE_NM);

            // --- gli errori ----------------------------------------------------
            // L'errore di distanza e' PROPORZIONALE e limitato.
            //
            // Sommare un errore assoluto poteva far dire «sessanta metri» per
            // una nave che ne stava a duemila: nessun idrofonista ha mai
            // sbagliato cosi', e chi legge il tavolo se ne accorgerebbe subito.
            // Si sbaglia in percentuale, e mai oltre il fattore che il mestiere
            // consente: al telemetro del periscopio un quinto, a orecchio meta'
            // o il doppio.
            $quota = $identificata ? 0.09 : ($vista ? 0.20 : 0.42);
            $fattore = max(0.40, min(2.40, 1.0 + $rng->gauss() * $quota));
            $dStimata = max(0.03, $d * $fattore);

            $errRil = $vista ? ($identificata ? 0.6 : 1.8) : 4.5;
            $rilStimato = Geo::normBearing($ril + $rng->gauss() * $errRil);

            // L'angolo sulla prua si stima CON IL SEGNO, e il segno e' quale
            // fianco ci mostra. A orecchio non lo si sa: dall'idrofono arriva un
            // rilevamento, non un profilo, e l'angolo si ricava solo seguendo
            // come quel rilevamento cambia nel tempo. Tenere il lato vero
            // regalava al comandante meta' del problema.
            $errAob = $identificata ? 8.0 : ($vista ? 22.0 : 45.0);
            $aobFirmato = $aobDelta + $rng->gauss() * $errAob;
            if (!$vista && $rng->chance(0.32)) {
                $aobFirmato = -$aobFirmato;              // a orecchio il fianco si sbaglia spesso
            }
            $aobFirmato = max(-180.0, min(180.0, $aobFirmato));
            $aobStimato = abs($aobFirmato);

            // La velocita' si stima dai giri d'elica, ed e' una delle cose che
            // l'idrofonista fa meglio. L'errore e' proporzionale e limitato: un
            // errore assoluto poteva far scrivere "ferma" per una nave che
            // faceva otto nodi, e una nave ferma e' un problema di tiro
            // completamente diverso.
            $quotaVel = $identificata ? 0.07 : ($vista ? 0.16 : 0.28);
            $velStimata = (float) $e['speed_kn'] > 0.05
                ? round((float) $e['speed_kn'] * max(0.45, min(1.80, 1.0 + $rng->gauss() * $quotaVel)), 1)
                : 0.0;

            // La rotta segnata sul tavolo discende dal rilevamento e dall'angolo
            // sulla prua, che sono le due cose che si stimano: cosi' il disegno
            // e la tabella raccontano la stessa storia, errore compreso.
            $rottaStimata = Geo::normBearing($rilStimato + 180.0 + $aobFirmato);

            // La stazza si stima a occhio, e a occhio si e' sempre generosi.
            $grtStimato = $vista
                ? (int) round((int) $e['grt'] * (1.0 + 0.16 + $rng->gauss() * ($identificata ? 0.12 : 0.30)) / 100) * 100
                : 0;

            // --- come si chiama sul tavolo --------------------------------------
            $etichetta = match (true) {
                $nomeLeggibile => (string) $e['name'],
                $identificata  => (string) $cls['name'],
                $vista         => self::sagoma((int) $e['grt'], (string) $cls['kind']),
                default        => Acoustics::classifica((float) $idro['snr'], 1, (float) $e['speed_kn'], (int) $cls['eliche'], $rng),
            };
            // Il numero che il plottaggio le da' quando non ha un nome.
            $breve = $nomeLeggibile
                ? (string) $e['name']
                : ($identificata ? (string) $cls['name'] : sprintf('«%d»', $numero));
            if (!$nomeLeggibile) {
                $etichetta .= sprintf(' «%d»', $numero);
            }

            $out[] = [
                'id'        => (int) $e['id'],
                'nome'      => $etichetta,
                // Sul tavolo ci sta poco: il disegno usa questa, non la frase
                // intera del Funkmaat, che sovrapposta a quella delle vicine
                // non la legge nessuno.
                'breve'     => $breve,
                'nome_noto' => $nomeLeggibile,
                // Distinguere una nave da guerra da un mercantile e' facile —
                // basta la sagoma, bassa e lunga — molto piu' facile che dirne
                // la classe. Quindi il ruolo si sa appena la si vede; e una
                // scorta che sta pingando si annuncia anche senza vederla.
                'ruolo'     => ($vista || $pinga) ? (string) $e['ruolo'] : 'ignoto',
                'osservazione' => $osservazione,
                'identificata' => $identificata,
                'certezza'  => round($certezza, 2),
                'classe'    => $identificata ? (string) $cls['name'] : '',
                'classe_key'=> $identificata ? $classeKey : null,
                'grt'       => $vista ? $grtStimato : 0,
                'grt_vero'  => (int) $e['grt'],
                // Che una nave sbandi o stia andando giu' lo si sa se la si
                // vede. All'idrofono si sente un'elica, non un incendio.
                'stato'     => $vista ? (string) $e['stato'] : 'ignoto',
                'rotta'     => round($rottaStimata, 1),
                'distanza'  => round($dStimata, 3),
                'metri'     => (int) round($dStimata * 1852),
                'rilevamento' => round($rilStimato, 1),
                'aob'       => round($aobStimato, 0),
                'aob_lato'  => $aobFirmato < 0 ? 'sinistra' : 'dritta',
                'velocita'  => $velStimata,
                'integrita' => (float) $e['integrita'],
                'contatto'  => (float) $e['contatto'],
                // Sul tavolo si segna la posizione STIMATA: rilevamento e
                // distanza come li ha riferiti chi guarda o chi ascolta.
                'lat'       => Geo::destination((float) $boat['lat'], (float) $boat['lon'], $rilStimato, $dStimata)[0],
                'lon'       => Geo::destination((float) $boat['lat'], (float) $boat['lon'], $rilStimato, $dStimata)[1],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['distanza'] <=> $b['distanza']);

        $sommario['occhio'] = $occhio !== null;
        $sommario['risolte'] = count($out);
        $sommario['viste'] = count(array_filter($out, static fn (array $u): bool => $u['osservazione'] === 'vista'));
        $sommario['riconosciute'] = count(array_filter($out, static fn (array $u): bool => (bool) $u['identificata']));

        return $out;
    }

    /** Come si chiama una sagoma che si vede ma non si riconosce. */
    private static function sagoma(int $grt, string $kind): string
    {
        if ($kind === 'scorta') {
            return 'unita\' sottile';
        }
        return match (true) {
            $grt >= 9000 => 'piroscafo grosso',
            $grt >= 4000 => 'piroscafo medio',
            default      => 'piroscafo piccolo',
        };
    }
}
