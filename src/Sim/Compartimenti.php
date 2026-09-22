<?php

declare(strict_types=1);

namespace App\Sim;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * I compartimenti: dove l'acqua entra, e che cosa si fa per fermarla.
 *
 * Fino all'audit del 19/09/2026 questa tabella esisteva, si vedeva in pagina e
 * non la scriveva nessuno: otto compartimenti eternamente integri e asciutti.
 * Il danno c'era — sui diciannove apparati e sulla sollecitazione dello scafo —
 * ma il posto dove l'acqua entra davvero era finto.
 *
 * Adesso e' collegato, e segue quello che succedeva davvero:
 *
 *   - una carica vicina spacca qualcosa in UN compartimento, non in tutto il
 *     battello: si sceglie quello vicino allo scoppio, e i sistemi che ci
 *     stanno dentro sono gli stessi che gia' si guastano;
 *   - sotto la quota di prova le giunzioni piangono: una falla nasce dove lo
 *     scafo e' gia' indebolito, e cresce con la pressione — a duecento metri
 *     entra molta piu' acqua che a cinquanta;
 *   - la squadra di falla lavora: senza di lei l'acqua sale e basta;
 *   - quando non c'e' piu' niente da fare si SIGILLA. La paratia si chiude,
 *     l'acqua smette di allagare il resto del battello, e chi era dentro resta
 *     dentro. E' la decisione piu' dura che un comandante prendeva, e il gioco
 *     non deve fingere che sia gratis.
 *
 * L'acqua imbarcata pesa: un battello appesantito scende piu' in fretta di
 * quanto vorrebbe, risale a fatica e non regge le quote che reggeva prima.
 */
final class Compartimenti
{
    /** Tonnellate d'acqua che un compartimento pieno al 100% imbarca. */
    private const TONNELLATE_PIENO = 11.0;

    /** Sopra questa percentuale di allagamento totale il battello non torna su. */
    private const AFFOGAMENTO = 62.0;

    /**
     * Lo stato dei compartimenti di un battello.
     *
     * @return list<array<string,mixed>>
     */
    public static function di(int $boatId): array
    {
        return Database::all(
            'SELECT * FROM boat_compartments WHERE boat_id = ? ORDER BY seq',
            [$boatId]
        );
    }

    /**
     * Quanto pesa l'acqua a bordo, e che cosa costa.
     *
     * @param list<array<string,mixed>>|null $comp
     * @return array{acqua_pct:float, acqua_t:float, quota_max:float, velocita:float, sigillati:int, allagati:int, critico:bool}
     */
    public static function zavorra(int $boatId, ?array $comp = null): array
    {
        $comp ??= self::di($boatId);
        if ($comp === []) {
            return ['acqua_pct' => 0.0, 'acqua_t' => 0.0, 'quota_max' => 1.0,
                    'velocita' => 1.0, 'sigillati' => 0, 'allagati' => 0, 'critico' => false];
        }

        $somma = 0.0;
        $sigillati = 0;
        $allagati = 0;
        foreach ($comp as $c) {
            $a = (float) $c['flooding'];
            $somma += $a;
            if ((int) $c['sealed'] === 1) {
                $sigillati++;
            }
            if ($a > 0.5) {
                $allagati++;
            }
        }
        $media = $somma / count($comp);

        return [
            'acqua_pct' => round($media, 2),
            'acqua_t'   => round($somma / 100.0 * self::TONNELLATE_PIENO, 1),
            // Con l'acqua a bordo l'assetto non tiene: la quota di sicurezza
            // scende in fretta, la velocita' molto meno.
            'quota_max' => round(max(0.35, 1.0 - $media / 55.0), 3),
            'velocita'  => round(max(0.55, 1.0 - $media / 140.0), 3),
            'sigillati' => $sigillati,
            'allagati'  => $allagati,
            'critico'   => $media >= self::AFFOGAMENTO,
        ];
    }

    /**
     * Uno scoppio vicino: rompe qualcosa in un compartimento solo.
     *
     * Il compartimento lo sceglie il caso, ma non a caso: la centrale e i
     * locali macchine sono piu' grandi e stanno in mezzo, e le prendono piu'
     * spesso; le camere siluri sono agli estremi.
     *
     * @param list<string> $eventi
     */
    public static function colpisci(int $boatId, float $danno, Rng $rng, array &$eventi): void
    {
        if ($danno < 6.0) {
            return;
        }
        $comp = self::di($boatId);
        if ($comp === []) {
            return;
        }

        $pesi = [
            'zentrale' => 22, 'diesel' => 18, 'elettrico' => 16, 'quadrato' => 12,
            'prua' => 12, 'poppa' => 10, 'sottuff' => 5, 'cucina' => 5,
        ];
        $urna = [];
        foreach ($comp as $c) {
            $k = (string) $c['ckey'];
            for ($i = 0; $i < ($pesi[$k] ?? 8); $i++) {
                $urna[] = $c;
            }
        }
        $scelto = $urna[$rng->int(0, count($urna) - 1)];

        // L'integrita' se ne va in proporzione allo scoppio; l'acqua entra solo
        // se la lamiera ha davvero ceduto.
        $perdita = min(70.0, $danno * $rng->range(0.35, 0.75));
        $integrita = max(0.0, (float) $scelto['integrity'] - $perdita);
        $falla = 0.0;
        if ($integrita < 72.0 && (int) $scelto['sealed'] === 0) {
            $falla = min(45.0, (72.0 - $integrita) * $rng->range(0.25, 0.6));
        }

        Database::run(
            'UPDATE boat_compartments SET integrity = ?, flooding = LEAST(100, flooding + ?) WHERE id = ?',
            [round($integrita, 2), round($falla, 2), (int) $scelto['id']]
        );

        if ($falla >= 12.0) {
            $eventi[] = sprintf(
                'Falla in %s: l\'acqua entra a getto e la squadra di falla ci corre.',
                mb_strtolower((string) $scelto['name'])
            );
        } elseif ($falla > 0.0) {
            $eventi[] = sprintf(
                '%s imbarca acqua da un passascafo: poca, per ora.',
                (string) $scelto['name']
            );
        } elseif ($perdita > 25.0) {
            $eventi[] = sprintf('Lamiere deformate in %s, ma la paratia tiene.', mb_strtolower((string) $scelto['name']));
        }
    }

    /**
     * La pressione sulle giunzioni gia' indebolite.
     *
     * Sotto la quota di prova una lamiera gia' ammaccata comincia a piangere.
     * Non nasce una falla dal nulla in un compartimento intatto: nasce dove lo
     * scoppio di prima ha gia' lavorato.
     *
     * @param array<string,mixed> $type
     * @param list<string> $eventi
     */
    public static function pressione(int $boatId, float $quota, array $type, float $ore, Rng $rng, array &$eventi): void
    {
        $prova = (float) ($type['test_depth_m'] ?? 100.0);
        if ($quota <= $prova * 0.7 || $ore <= 0.0) {
            return;
        }

        $scala = (float) GameConfig::get('damage.pressure_scale', 1.0);
        $eccesso = max(0.0, ($quota - $prova * 0.7) / max(1.0, $prova));

        foreach (self::di($boatId) as $c) {
            if ((int) $c['sealed'] === 1) {
                continue;
            }
            $integrita = (float) $c['integrity'];
            $acqua = (float) $c['flooding'];

            // Una falla che c'e' gia' peggiora con la quota.
            if ($acqua > 0.0) {
                $cresce = $scala * $ore * $eccesso * $rng->range(0.8, 2.4);
                if ($cresce > 0.01) {
                    Database::run(
                        'UPDATE boat_compartments SET flooding = LEAST(100, flooding + ?) WHERE id = ?',
                        [round($cresce, 2), (int) $c['id']]
                    );
                }
                continue;
            }

            // Una lamiera ammaccata puo' cedere adesso.
            if ($integrita < 88.0 && $rng->chance(min(0.3, $scala * $ore * $eccesso * (88.0 - $integrita) / 220.0))) {
                Database::run(
                    'UPDATE boat_compartments SET flooding = LEAST(100, flooding + ?) WHERE id = ?',
                    [round($rng->range(2.0, 7.0), 2), (int) $c['id']]
                );
                $eventi[] = sprintf(
                    'Una giunzione cede in %s: con questa quota la lamiera ammaccata non tiene.',
                    mb_strtolower((string) $c['name'])
                );
            }
        }
    }

    /**
     * La squadra di falla, ora per ora.
     *
     * Si lavora prima dove c'e' piu' acqua. Un compartimento sigillato non lo
     * si prosciuga: dentro non c'e' nessuno che possa farlo.
     *
     * @param array{morale:float,fatica:float} $ciurma
     * @param list<string> $eventi
     */
    public static function passo(int $boatId, float $ore, array $ciurma, float $quota, array $type, Rng $rng, array &$eventi): void
    {
        if ($ore <= 0.0) {
            return;
        }
        $comp = self::di($boatId);
        $aperti = array_values(array_filter(
            $comp,
            static fn (array $c): bool => (int) $c['sealed'] === 0 && (float) $c['flooding'] > 0.0
        ));
        if ($aperti === []) {
            return;
        }

        usort($aperti, static fn (array $a, array $b): int => (float) $b['flooding'] <=> (float) $a['flooding']);

        // In superficie con le pompe si svuota molto meglio che in immersione.
        $prova = (float) ($type['test_depth_m'] ?? 100.0);
        $resa = $quota <= 1.0 ? 2.2 : max(0.35, 1.3 - $quota / ($prova * 2.0));
        $resa *= 0.65 + 0.35 * (max(0.0, min(100.0, (float) ($ciurma['morale'] ?? 70.0))) / 100.0);
        $resa *= 1.0 - 0.3 * (max(0.0, min(100.0, (float) ($ciurma['fatica'] ?? 0.0))) / 100.0);

        $squadra = $aperti[0];
        $tolta = min((float) $squadra['flooding'], $ore * $resa * $rng->range(1.4, 2.6));
        if ($tolta <= 0.01) {
            return;
        }

        $rimasta = max(0.0, (float) $squadra['flooding'] - $tolta);
        Database::run('UPDATE boat_compartments SET flooding = ? WHERE id = ?', [round($rimasta, 2), (int) $squadra['id']]);

        if ($rimasta <= 0.0) {
            $eventi[] = sprintf('Falla tamponata in %s: l\'acqua non sale piu\'.', mb_strtolower((string) $squadra['name']));
        }
    }

    /**
     * Sigilla o riapre una paratia.
     *
     * Sigillare ferma l'acqua e salva il battello; costa gli uomini che stanno
     * dentro e i sistemi di quel compartimento, che restano irraggiungibili.
     * Riaprire si puo', ma l'acqua ricomincia a lavorare.
     *
     * @return array{ok:bool, error?:string, evento?:string}
     */
    public static function sigilla(int $boatId, string $ckey, bool $chiudi): array
    {
        $c = Database::first(
            'SELECT * FROM boat_compartments WHERE boat_id = ? AND ckey = ?',
            [$boatId, $ckey]
        );
        if ($c === null) {
            return ['ok' => false, 'error' => 'Compartimento sconosciuto.'];
        }
        if ((string) $c['ckey'] === 'zentrale' && $chiudi) {
            return ['ok' => false, 'error' => 'La centrale non si sigilla: e\' da li\' che si comanda il battello.'];
        }
        if ((int) $c['sealed'] === ($chiudi ? 1 : 0)) {
            return ['ok' => false, 'error' => $chiudi ? 'Gia\' sigillato.' : 'Gia\' aperto.'];
        }

        Database::run('UPDATE boat_compartments SET sealed = ? WHERE id = ?', [$chiudi ? 1 : 0, (int) $c['id']]);

        if (!$chiudi) {
            return ['ok' => true, 'evento' => sprintf('Paratia di %s riaperta.', mb_strtolower((string) $c['name']))];
        }

        // Chi era dentro. Gli uomini si contano per compartimento: e' la parte
        // che rende la decisione quello che e'.
        $dentro = Database::all(
            "SELECT id, name FROM crew_members WHERE boat_id = ? AND station = ? AND health <> 'morto'",
            [$boatId, $ckey]
        );
        $persi = 0;
        if ((float) $c['flooding'] >= 35.0) {
            foreach ($dentro as $u) {
                Database::run("UPDATE crew_members SET health = 'morto' WHERE id = ?", [(int) $u['id']]);
                $persi++;
            }
        }

        return [
            'ok' => true,
            'evento' => $persi > 0
                ? sprintf('Paratia di %s sigillata. Dentro restano %d uomini, e non si riaprira\'.',
                    mb_strtolower((string) $c['name']), $persi)
                : sprintf('Paratia di %s sigillata: l\'acqua resta di la\'.', mb_strtolower((string) $c['name'])),
        ];
    }

    /**
     * Rimette tutti i compartimenti a nuovo, in un colpo.
     *
     * Non e' piu' il cantiere: dal 21/09/2026 il lavoro di bacino lo fa
     * App\Game\Cantiere ora per ora, compartimento per compartimento, mentre
     * il battello sta in porto. Questo metodo resta come azzeramento secco —
     * lo usano le prove per ripartire da una situazione pulita.
     */
    public static function revisiona(int $boatId): void
    {
        Database::run(
            'UPDATE boat_compartments SET integrity = 100, flooding = 0, fire = 0, sealed = 0 WHERE boat_id = ?',
            [$boatId]
        );
    }
}
