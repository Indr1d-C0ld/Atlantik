<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Da evento a riga di giornale di guerra.
 *
 * Il Kriegstagebuch non e' un registro di gioco: e' un documento asciutto,
 * scritto in terza persona, con l'ora, la posizione e il fatto. Qui si scrive
 * in italiano ma con quel taglio — niente enfasi, niente aggettivi di troppo.
 */
final class Narrator
{
    public static function partenza(string $porto, string $flottiglia): string
    {
        return "Mollati gli ormeggi da {$porto}. {$flottiglia}. Uscita per missione di guerra.";
    }

    public static function posizione(string $quadrat, float $lat, float $lon, float $miglia, array $meteo, string $fase): string
    {
        return sprintf(
            'Quadrato %s, %s %s. Percorse %.0f miglia nelle ultime sei ore. Vento %s forza %d, mare %s, visibilita\' %.0f miglia. %s.',
            $quadrat,
            Geo::formatLat($lat),
            Geo::formatLon($lon),
            $miglia,
            Weather::rosa((float) $meteo['wind_dir']),
            (int) $meteo['beaufort'],
            Weather::nomeMare((int) $meteo['sea_state']),
            (float) $meteo['visibility_nm'],
            ucfirst($fase)
        );
    }

    public static function punto(string $tipo, float $erroreVecchio, float $precisione): string
    {
        $astro = $tipo === 'sole' ? 'con il sole' : 'sulle stelle del crepuscolo';
        return sprintf(
            'Punto nave %s. La stima era in errore di %.1f miglia; posizione ora nota a %.1f miglia.',
            $astro,
            $erroreVecchio,
            $precisione
        );
    }

    public static function niente_punto(int $giorni): string
    {
        return "Cielo coperto da {$giorni} giorni: nessun punto astronomico. Si naviga di stima.";
    }

    public static function immersione(float $quota, bool $rapida): string
    {
        return $rapida
            ? sprintf('Allarme! Immersione rapida, quota %.0f metri.', $quota)
            : sprintf('Immersione a quota %.0f metri.', $quota);
    }

    public static function emersione(): string
    {
        return 'Emersi. Aria fresca nel battello, avviati i diesel, in carica le batterie.';
    }

    public static function quota(float $quota): string
    {
        return sprintf('Assunta quota %.0f metri.', $quota);
    }

    public static function rotta(float $rotta, float $velocita): string
    {
        return sprintf('Accostato per %03.0f gradi, velocita\' %.0f nodi.', $rotta, $velocita);
    }

    public static function waypoint(int $n, string $quadrat): string
    {
        return "Raggiunto il punto di rotta {$n}, quadrato {$quadrat}.";
    }

    public static function fineRotta(string $quadrat): string
    {
        return "Esaurita la rotta pianificata in quadrato {$quadrat}. Il battello resta in zona in attesa di ordini.";
    }

    public static function burrasca(int $beaufort, int $mare): string
    {
        return sprintf(
            'Il tempo peggiora: vento forza %d, mare %s. Uomini di guardia assicurati con le cinghie, boccaporto chiuso fra un cambio e l\'altro.',
            $beaufort,
            Weather::nomeMare($mare)
        );
    }

    public static function bonaccia(): string
    {
        return 'Il mare cala. Condizioni buone per il cannone di coperta e per il carico dei siluri esterni.';
    }

    public static function nebbia(float $vis): string
    {
        return sprintf('Banco di nebbia: visibilita\' ridotta a %.1f miglia. Raddoppiate le vedette, idrofono in ascolto continuo.', $vis);
    }

    public static function nafta(float $percentuale, float $tonnellate): string
    {
        return match (true) {
            $percentuale <= 10 => sprintf('Nafta al %.0f%% (%.1f t). Autonomia appena sufficiente al rientro: rotta di ritorno non piu\' rimandabile.', $percentuale, $tonnellate),
            $percentuale <= 25 => sprintf('Nafta al %.0f%% (%.1f t). Il Leitender Ingenieur raccomanda velocita\' economica.', $percentuale, $tonnellate),
            default            => sprintf('Nafta al %.0f%% (%.1f t).', $percentuale, $tonnellate),
        };
    }

    public static function batteria(float $percentuale): string
    {
        return match (true) {
            $percentuale <= 8  => sprintf('Batterie al %.0f%%. Bisogna emergere: sott\'acqua non resta quasi nulla.', $percentuale),
            $percentuale <= 25 => sprintf('Batterie al %.0f%%. Ridotta la velocita\' per risparmiare.', $percentuale),
            default            => sprintf('Batterie al %.0f%%.', $percentuale),
        };
    }

    public static function aria(float $percentuale): string
    {
        return sprintf(
            'Aria %s (%.0f%%). Anidride carbonica al %.2f%%.',
            Consumption::statoAria($percentuale),
            $percentuale,
            Consumption::co2FromAir($percentuale)
        );
    }

    public static function emersioneForzata(): string
    {
        return 'Batterie esaurite: emersione obbligata. Siamo allo scoperto, e non per scelta.';
    }

    public static function inPanne(): string
    {
        return 'Nafta esaurita. Il battello e\' fermo in mezzo all\'oceano: si attende il rifornimento o il rimorchio.';
    }

    public static function viveri(float $giorni): string
    {
        return $giorni <= 0
            ? 'Viveri esauriti. Razioni di emergenza: gallette e conserve contate.'
            : sprintf('Viveri per altri %.0f giorni.', $giorni);
    }

    // --- Materiale ed equipaggio ---------------------------------------------

    public static function avaria(string $sistema, string $compartimento, bool $grave, bool $riparabile): string
    {
        $dove = [
            'prua' => 'in camera siluri di prua', 'sottuff' => 'negli alloggi sottufficiali',
            'quadrato' => 'nel quadrato ufficiali', 'zentrale' => 'in centrale',
            'cucina' => 'in cucina', 'diesel' => 'nel locale diesel',
            'elettrico' => 'nel locale motori elettrici', 'poppa' => 'in camera siluri di poppa',
            'ausiliari' => 'nel locale ausiliari', 'batterie' => 'nel locale batterie',
        ][$compartimento] ?? 'a bordo';

        if ($grave && !$riparabile) {
            return "Avaria grave {$dove}: {$sistema} fuori uso. Non si ripara a mare.";
        }
        if ($grave) {
            return "Avaria grave {$dove}: {$sistema} fuori uso. Squadra di riparazione al lavoro.";
        }
        if (!$riparabile) {
            return "Avaria {$dove}: {$sistema} in avaria. Serve il cantiere.";
        }
        return "Avaria {$dove}: {$sistema}. Il Leitender Ingenieur manda gli uomini.";
    }

    public static function riparazione(string $sistema): string
    {
        return "Riparazione conclusa: {$sistema} di nuovo in servizio.";
    }

    public static function scafo(float $quota, bool $grave): string
    {
        if ($grave) {
            return sprintf(
                "A %.0f metri lo scafo cede di schianto in piu' punti: schizzi d'acqua dai passascafi, "
                . "luci che ballano. Non si puo' restare qui.",
                $quota
            );
        }
        return sprintf(
            "A %.0f metri lo scafo comincia a lamentarsi: colpi secchi nei rivetti, un filo d'acqua "
            . "dai premistoppa. Nessuno parla.",
            $quota
        );
    }

    public static function morale(float $morale, float $fatica): string
    {
        return sprintf(
            "L'umore a bordo e' %s e gli uomini sono %s. Ai turni si sbaglia, e si sbaglia sempre piu' spesso.",
            Crew::statoMorale($morale),
            Crew::statoFaticaPlurale($fatica)
        );
    }

    public static function ferito(string $nome, string $causa): string
    {
        return "{$nome} ferito: {$causa}. Medicato in quadrato, fuori servizio.";
    }

    // --- Contatti ---------------------------------------------------------------

    public static function contattoIdrofono(string $classificazione, float $rilevamento, ?float $distanza): string
    {
        $r = sprintf('%03.0f', $rilevamento);
        $d = $distanza !== null ? sprintf(', distanza stimata %.0f miglia', $distanza) : '';
        return "Idrofono: {$classificazione}. Rilevamento {$r}{$d}.";
    }

    public static function contattoVista(string $cosa, float $rilevamento, float $distanza): string
    {
        return sprintf('Vedetta: %s in vista, rilevamento %03.0f, distanza stimata %.1f miglia.', $cosa, $rilevamento, $distanza);
    }

    public static function contattoFumo(float $rilevamento): string
    {
        return sprintf('Fumo all\'orizzonte, rilevamento %03.0f. Non si vedono ancora gli scafi.', $rilevamento);
    }

    public static function contattoConvoglio(int $navi, int $scorte, float $rilevamento, float $distanza): string
    {
        return sprintf(
            'Convoglio: si contano una ventina di scafi o piu\' (stima %d bastimenti, %d unita\' di scorta), '
            . 'rilevamento %03.0f, distanza stimata %.0f miglia.',
            $navi, $scorte, $rilevamento, $distanza
        );
    }

    public static function contattoRinforzato(string $classificazione, float $rilevamento, float $distanza): string
    {
        return sprintf(
            'Il rumore si rinforza: %s. Rilevamento %03.0f, si avvicina — distanza stimata %.0f miglia.',
            $classificazione, $rilevamento, $distanza
        );
    }

    public static function contattoPerso(string $cosa): string
    {
        return "Perso il contatto con {$cosa}.";
    }

    public static function aereo(string $tipo, float $rilevamento, bool $notte): string
    {
        return $notte
            ? "Allarme aereo! Motori in avvicinamento nel buio, rilevamento " . sprintf('%03.0f', $rilevamento)
              . ": e' un {$tipo} col radar. Immersione immediata."
            : "Allarme aereo! {$tipo} in avvicinamento, rilevamento " . sprintf('%03.0f', $rilevamento) . ". Immersione immediata.";
    }

    public static function avvistati(string $daChi, float $distanza): string
    {
        return sprintf(
            'Siamo stati avvistati: %s ha accostato verso di noi, distanza %.1f miglia. Non c\'e\' piu\' sorpresa.',
            $daChi, $distanza
        );
    }

    // --- Prima uscita --------------------------------------------------------

    /**
     * L'ordine di missione consegnato prima di mollare gli ormeggi.
     *
     * Non e' un tutorial: e' quello che un comandante del 1942 riceveva
     * davvero. Ma dice dove andare e perche', che e' esattamente cio' che
     * mancava a chi si arruola oggi (audit A9).
     */
    public static function ordineMissione(string $quadrat, string $zona, float $distanzaNm, float $giorni): string
    {
        return sprintf(
            "Ordine di missione del BdU: portarsi in quadrato %s, %s, e operare contro il traffico. "
            . "Sono %.0f miglia di trasferimento: a velocita' economica, circa %.0f giorni di mare. "
            . "Segnalare i contatti, non ingaggiare le scorte.",
            $quadrat,
            $zona,
            $distanzaNm,
            $giorni
        );
    }

    /** Le due righe con cui il Primo Ufficiale spiega il passo successivo. */
    public static function primoConsiglio(string $quadrat): string
    {
        return sprintf(
            "Il I.WO, sottovoce: «Herr Kaleun, il tavolo di carteggio e' pronto. "
            . "Segni la rotta per %s con un paio di punti e la trasmetta alla centrale: "
            . "l'Obersteuermann pensa al resto. In superficie si fa strada e si caricano le batterie; "
            . "sott'acqua si va piano e si vede poco. L'idrofono sente lontano, ma solo se stiamo zitti.»",
            $quadrat
        );
    }

    public static function rientro(string $porto, float $miglia, int $durataSec): string
    {
        return sprintf(
            'Entrati a %s. Missione conclusa: %.0f miglia percorse in %s di mare.',
            $porto,
            $miglia,
            Clock::durata($durataSec)
        );
    }
}
