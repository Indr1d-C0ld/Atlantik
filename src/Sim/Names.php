<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Nomi tedeschi d'epoca per l'equipaggio, e ruoli di bordo.
 *
 * I nomi sono comuni nella Germania degli anni Venti e Trenta — sono gli anni
 * di nascita degli uomini che si imbarcarono. Nessun nome di persona reale e'
 * usato di proposito: le combinazioni escono dal generatore deterministico.
 */
final class Names
{
    private const NOMI = [
        'Heinrich', 'Wilhelm', 'Karl', 'Otto', 'Friedrich', 'Hans', 'Werner', 'Günther', 'Kurt', 'Helmut',
        'Erich', 'Walter', 'Rolf', 'Klaus', 'Dieter', 'Horst', 'Ernst', 'Rudolf', 'Josef', 'Paul',
        'Gerhard', 'Herbert', 'Alfred', 'Bruno', 'Fritz', 'Georg', 'Ludwig', 'Martin', 'Albert', 'Theodor',
        'Siegfried', 'Manfred', 'Reinhard', 'Ulrich', 'Wolfgang', 'Anton', 'Emil', 'Konrad', 'Max', 'Richard',
    ];

    private const COGNOMI = [
        'Bauer', 'Becker', 'Brandt', 'Braun', 'Busch', 'Dietrich', 'Eberhardt', 'Engel', 'Fischer', 'Frank',
        'Fuchs', 'Graf', 'Gross', 'Haas', 'Hartmann', 'Heinrich', 'Herzog', 'Hoffmann', 'Huber', 'Jäger',
        'Kaiser', 'Keller', 'Klein', 'Koch', 'König', 'Krause', 'Krüger', 'Lang', 'Lehmann', 'Lorenz',
        'Maier', 'Müller', 'Neumann', 'Peters', 'Richter', 'Roth', 'Sauer', 'Schäfer', 'Schmidt', 'Schneider',
        'Schröder', 'Schulz', 'Schuster', 'Schwarz', 'Seidel', 'Sommer', 'Stein', 'Vogel', 'Wagner', 'Weber',
        'Wegener', 'Werner', 'Winkler', 'Wolf', 'Zimmermann',
    ];

    /**
     * Ruoli di bordo, con grado tipico e turno di guardia.
     *
     * watch = 0 significa fuori dal giro delle guardie: il Leitender Ingenieur,
     * il radiotelegrafista, il nostromo e il cuoco lavorano a giornata, non a
     * quarti. Le tre guardie di coperta sono guidate dal I.WO, dal II.WO e
     * dall'Obersteuermann.
     *
     * @return array<string,array{name:string,rank:string,rank_key:string,station:string,watch:int,competence:array{0:int,1:int}}>
     */
    public static function ruoli(): array
    {
        return [
            'iwo' => [
                'name' => 'Primo ufficiale di guardia (I.WO)', 'rank' => 'Oberleutnant zur See', 'rank_key' => 'oblt',
                'station' => 'zentrale', 'watch' => 1, 'competence' => [55, 85],
            ],
            'iiwo' => [
                'name' => 'Secondo ufficiale di guardia (II.WO)', 'rank' => 'Leutnant zur See', 'rank_key' => 'lt',
                'station' => 'zentrale', 'watch' => 2, 'competence' => [40, 70],
            ],
            'li' => [
                'name' => 'Direttore di macchina (LI)', 'rank' => 'Oberleutnant (Ing.)', 'rank_key' => 'oblt_ing',
                'station' => 'zentrale', 'watch' => 0, 'competence' => [60, 90],
            ],
            'navigatore' => [
                'name' => 'Navigatore (Obersteuermann)', 'rank' => 'Obersteuermann', 'rank_key' => 'obstrm',
                'station' => 'zentrale', 'watch' => 3, 'competence' => [55, 85],
            ],
            'nostromo' => [
                'name' => 'Nostromo (Bootsmann)', 'rank' => 'Oberbootsmaat', 'rank_key' => 'obtsmt',
                'station' => 'prua', 'watch' => 0, 'competence' => [50, 80],
            ],
            'radiotelegrafista' => [
                'name' => 'Radiotelegrafista e idrofonista', 'rank' => 'Funkmaat', 'rank_key' => 'funkmt',
                'station' => 'quadrato', 'watch' => 0, 'competence' => [45, 80],
            ],
            'macchinista_diesel' => [
                'name' => 'Macchinista ai diesel', 'rank' => 'Maschinenmaat', 'rank_key' => 'masmt',
                'station' => 'diesel', 'watch' => 1, 'competence' => [40, 75],
            ],
            'macchinista_elettrico' => [
                'name' => 'Macchinista ai motori elettrici', 'rank' => 'Maschinenmaat', 'rank_key' => 'masmt',
                'station' => 'elettrico', 'watch' => 2, 'competence' => [40, 75],
            ],
            'silurista' => [
                'name' => 'Silurista', 'rank' => 'Mechanikersmaat', 'rank_key' => 'mechmt',
                'station' => 'prua', 'watch' => 3, 'competence' => [40, 75],
            ],
            'zentrale' => [
                'name' => 'Addetto alla centrale', 'rank' => 'Zentralemaat', 'rank_key' => 'zentmt',
                'station' => 'zentrale', 'watch' => 1, 'competence' => [40, 75],
            ],
            'cuoco' => [
                'name' => 'Cuoco (Smutje)', 'rank' => 'Matrosenobergefreiter', 'rank_key' => 'matogfr',
                'station' => 'cucina', 'watch' => 0, 'competence' => [35, 80],
            ],
            'marinaio' => [
                'name' => 'Marinaio', 'rank' => 'Matrosengefreiter', 'rank_key' => 'matgfr',
                'station' => 'prua', 'watch' => 1, 'competence' => [25, 65],
            ],
        ];
    }

    /**
     * Composizione dell'equipaggio per un battello di N uomini.
     * Le proporzioni seguono i ruolini dei Tipo VII: quattro ufficiali, una
     * quindicina di sottufficiali, il resto marinai e macchinisti.
     *
     * @return array<string,int> ruolo => quantita'
     */
    public static function organico(int $uomini): array
    {
        $base = [
            'iwo' => 1, 'iiwo' => 1, 'li' => 1, 'navigatore' => 1, 'nostromo' => 1, 'cuoco' => 1,
        ];
        $restanti = max(0, $uomini - array_sum($base));

        // Proporzioni sul resto dell'equipaggio.
        $quote = [
            'radiotelegrafista'     => 0.07,
            'macchinista_diesel'    => 0.20,
            'macchinista_elettrico' => 0.16,
            'silurista'             => 0.12,
            'zentrale'              => 0.10,
            'marinaio'              => 0.35,
        ];

        $out = $base;
        $assegnati = 0;
        foreach ($quote as $ruolo => $q) {
            $n = (int) round($restanti * $q);
            $out[$ruolo] = max(1, $n);
            $assegnati += $out[$ruolo];
        }
        // Il resto (o l'eccesso) si scarica sui marinai.
        $out['marinaio'] = max(1, $out['marinaio'] + ($restanti - $assegnati));

        return $out;
    }

    public static function nome(Rng $rng): string
    {
        return self::NOMI[$rng->int(0, count(self::NOMI) - 1)] . ' ' . self::COGNOMI[$rng->int(0, count(self::COGNOMI) - 1)];
    }
}
