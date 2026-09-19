<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Rng;
use App\Sim\World;

/**
 * La carriera del comandante: prestigio, gradi, decorazioni, e quello che con
 * il prestigio si puo' ottenere.
 *
 * Due valute distinte, per non confondere il merito con la logistica:
 *   - **Ansehen** (prestigio): il merito personale. Sblocca battelli, armi,
 *     apparati, e il diritto di scegliersi l'area operativa.
 *   - **Zuteilungspunkte** (punti di assegnazione): la priorita' in cantiere.
 *     E' cio' che permette di AVERE davvero il pezzo sbloccato.
 * A queste si aggiungono i Reichsmark, in scala molto piu' piccola: licenze,
 * caffe' vero, un grammofono nuovo. Cose che muovono il morale, non la guerra.
 */
final class Carriera
{
    /** @var list<array{livello:int,grado:string,prestigio:int}>|null */
    private static ?array $gradi = null;

    /** @return list<array{livello:int,grado:string,prestigio:int}> */
    public static function gradi(): array
    {
        if (self::$gradi !== null) {
            return self::$gradi;
        }
        $root = (string) ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2));
        return self::$gradi = require $root . '/db/seed/gradi.php';
    }

    public static function gradoNome(int $livello): string
    {
        $out = 'Oberleutnant zur See';
        foreach (self::gradi() as $g) {
            if ($g['livello'] <= $livello) {
                $out = $g['grado'];
            }
        }
        return $out;
    }

    /** Livello di anzianita' corrispondente a un totale di prestigio. */
    public static function livelloPer(int $prestigioTotale): int
    {
        $liv = 0;
        foreach (self::gradi() as $g) {
            if ($prestigioTotale >= $g['prestigio']) {
                $liv = $g['livello'];
            }
        }
        return $liv;
    }

    /** Prestigio che manca al livello successivo. @return array{prossimo:?int,mancano:int} */
    public static function prossimoLivello(int $prestigioTotale): array
    {
        foreach (self::gradi() as $g) {
            if ($g['prestigio'] > $prestigioTotale) {
                return ['prossimo' => $g['livello'], 'mancano' => $g['prestigio'] - $prestigioTotale];
            }
        }
        return ['prossimo' => null, 'mancano' => 0];
    }

    /**
     * Conto di fine missione.
     *
     * Il tonnellaggio e' la misura della guerra al traffico, ma non l'unica:
     * riportare a casa il battello e l'equipaggio vale, e affondare navi
     * neutrali costa — al BdU interessava il tonnellaggio, alla Wilhelmstrasse
     * interessavano le note diplomatiche.
     *
     * @return array{prestigio:int,punti:int,reichsmark:int,voci:list<array{voce:string,valore:int}>}
     */
    public static function conteggio(array $patrol, array $boat, bool $rientrato): array
    {
        $voci = [];
        $perGrt = (float) GameConfig::get('carriera.prestigio_per_100grt', 1.0);

        $affondamenti = Database::all(
            'SELECT s.*, c.kind FROM sinkings s LEFT JOIN ship_classes c ON c.class_key = s.class_key
             WHERE s.patrol_id = ?',
            [(int) $patrol['id']]
        );

        $grtTotale = 0;
        $neutrali = 0;
        $scorte = 0;
        foreach ($affondamenti as $a) {
            $grtTotale += (int) $a['grt'];
            if (in_array((string) $a['bandiera'], ['neutrale', 'panamense'], true) && (string) $a['kind'] !== 'scorta') {
                $neutrali++;
            }
            if ((string) $a['kind'] === 'scorta') {
                $scorte++;
            }
        }

        $prestigio = (int) round($grtTotale / 100 * $perGrt);
        if ($prestigio > 0) {
            $voci[] = ['voce' => sprintf('%s GRT affondati', number_format($grtTotale, 0, ',', '.')), 'valore' => $prestigio];
        }

        // Le scorte non pagano in tonnellaggio ma pagano in prestigio: affondare
        // un cacciatorpediniere era considerato un fatto d'armi a se'.
        if ($scorte > 0) {
            $bonus = $scorte * 140;
            $prestigio += $bonus;
            $voci[] = ['voce' => $scorte . ' unita\' di scorta affondate', 'valore' => $bonus];
        }

        // Rientro alla base: il BdU contava i battelli, non solo il tonnellaggio.
        if ($rientrato) {
            $bonus = 60 + (int) round($prestigio * 0.1);
            $prestigio += $bonus;
            $voci[] = ['voce' => 'Battello riportato alla base', 'valore' => $bonus];
        }

        // Navi neutrali: conseguenze diplomatiche e disciplinari.
        if ($neutrali > 0) {
            $malus = $neutrali * 220;
            $prestigio -= $malus;
            $voci[] = ['voce' => $neutrali . ' navi neutrali affondate — inchiesta del comando', 'valore' => -$malus];
        }

        // Perdite fra l'equipaggio.
        $morti = (int) (Database::first(
            "SELECT COUNT(*) n FROM crew_members WHERE boat_id = ? AND health = 'morto'",
            [(int) $boat['id']]
        )['n'] ?? 0);
        if ($morti > 0) {
            $malus = $morti * 45;
            $prestigio -= $malus;
            $voci[] = ['voce' => $morti . ' uomini perduti', 'valore' => -$malus];
        }

        $giorni = ((int) ($patrol['returned_gts'] ?? World::now()) - (int) $patrol['departed_gts']) / 86400;
        $punti = $rientrato
            ? (int) round(GameConfig::int('carriera.punti_per_patrol', 25) + $prestigio / 22 + $giorni * 0.8)
            : 0;
        $rm = $rientrato ? (int) round(GameConfig::int('carriera.rm_per_patrol', 900) + $giorni * 28) : 0;

        return [
            'prestigio'  => max(-3000, $prestigio),
            'punti'      => max(0, $punti),
            'reichsmark' => $rm,
            'voci'       => $voci,
        ];
    }

    /**
     * Chiude la missione sul fascicolo del comandante: accredita, promuove,
     * decora, e scrive il rapporto.
     *
     * @return array{prestigio:int,punti:int,promosso:bool,grado:string,decorazioni:list<array<string,mixed>>,rapporto:string}
     */
    public static function chiudiPatrol(array $commander, array $patrol, array $boat, bool $rientrato): array
    {
        $conto = self::conteggio($patrol, $boat, $rientrato);
        $gts = (int) ($patrol['returned_gts'] ?? World::now());

        $giorni = ($gts - (int) $patrol['departed_gts']) / 86400;
        $affondate = (int) (Database::first(
            'SELECT COUNT(*) n FROM sinkings WHERE patrol_id = ?', [(int) $patrol['id']]
        )['n'] ?? 0);
        $grt = (int) (Database::first(
            'SELECT COALESCE(SUM(grt),0) g FROM sinkings WHERE patrol_id = ?', [(int) $patrol['id']]
        )['g'] ?? 0);

        Database::run(
            'UPDATE commanders SET prestigio = prestigio + ?, prestigio_tot = GREATEST(0, prestigio_tot + ?),
                    punti = punti + ?, reichsmark = reichsmark + ?, patrols = patrols + 1,
                    affondate = affondate + ?, grt_affondato = grt_affondato + ?, giorni_mare = giorni_mare + ?
             WHERE id = ?',
            [
                $conto['prestigio'], max(0, $conto['prestigio']), $conto['punti'], $conto['reichsmark'],
                $affondate, $grt, round($giorni, 2), (int) $commander['id'],
            ]
        );

        $cmd = Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $commander['id']]);

        // Promozione.
        $nuovoLivello = self::livelloPer((int) $cmd['prestigio_tot']);
        $gradoPrima = self::gradoNome((int) $cmd['grado']);
        $avanzato = $nuovoLivello > (int) $cmd['grado'];
        if ($avanzato) {
            Database::run('UPDATE commanders SET grado = ? WHERE id = ?', [$nuovoLivello, (int) $cmd['id']]);
            $cmd['grado'] = $nuovoLivello;
        }
        // Piu' livelli condividono lo stesso grado: si e' promossi davvero solo
        // quando cambia il titolo, altrimenti e' anzianita' che matura.
        $promosso = $avanzato && self::gradoNome($nuovoLivello) !== $gradoPrima;

        $decorazioni = self::verificaDecorazioni($cmd, (int) $patrol['id'], $gts);

        $rapporto = self::rapporto($cmd, $patrol, $conto, $affondate, $grt, $giorni, $rientrato, $promosso, $avanzato, $decorazioni);
        Database::run(
            'UPDATE patrols SET prestigio = ?, punti = ?, rapporto = ?, commander_id = ? WHERE id = ?',
            [$conto['prestigio'], $conto['punti'], $rapporto, (int) $cmd['id'], (int) $patrol['id']]
        );

        return [
            'prestigio'   => $conto['prestigio'],
            'punti'       => $conto['punti'],
            'promosso'    => $promosso,
            'avanzato'    => $avanzato,
            'livello'     => (int) $cmd['grado'],
            'grado'       => self::gradoNome((int) $cmd['grado']),
            'decorazioni' => $decorazioni,
            'rapporto'    => $rapporto,
        ];
    }

    /**
     * Verifica se spettano nuove decorazioni e le conferisce.
     *
     * @return list<array<string,mixed>>
     */
    public static function verificaDecorazioni(array $cmd, ?int $patrolId, int $gts): array
    {
        $gia = array_column(
            Database::all('SELECT akey FROM awards WHERE commander_id = ?', [(int) $cmd['id']]),
            'akey'
        );
        $nuove = [];

        foreach (Database::all('SELECT * FROM award_types ORDER BY ordine') as $a) {
            $k = (string) $a['akey'];
            if (in_array($k, $gia, true)) {
                continue;
            }
            if ($a['richiede'] !== null && !in_array((string) $a['richiede'], $gia, true)) {
                continue;
            }
            if ((int) $cmd['patrols'] < (int) $a['min_patrols']
                || (int) $cmd['grt_affondato'] < (int) $a['min_grt']
                || (int) $cmd['affondate'] < (int) $a['min_navi']) {
                continue;
            }

            $motivazione = sprintf(
                'Per il comportamento tenuto in %d missioni di guerra, nel corso delle quali ha affondato '
                . '%d navi nemiche per complessive %s tonnellate di stazza lorda.',
                (int) $cmd['patrols'], (int) $cmd['affondate'],
                number_format((int) $cmd['grt_affondato'], 0, ',', '.')
            );

            Database::run(
                'INSERT INTO awards (commander_id, akey, gts, patrol_id, motivazione) VALUES (?, ?, ?, ?, ?)',
                [(int) $cmd['id'], $k, $gts, $patrolId, $motivazione]
            );
            $gia[] = $k;
            $nuove[] = ['akey' => $k, 'nome' => (string) $a['nome'], 'nome_it' => (string) $a['nome_it'], 'motivazione' => $motivazione];
        }

        return $nuove;
    }

    /** Il rapporto di missione, nella forma in cui lo si consegnava al BdU. */
    private static function rapporto(
        array $cmd, array $patrol, array $conto, int $affondate, int $grt,
        float $giorni, bool $rientrato, bool $promosso, bool $avanzato, array $decorazioni,
    ): string {
        $clock = World::clock();
        $righe = [];
        $righe[] = 'RAPPORTO DI MISSIONE';
        $righe[] = sprintf('Patrol n. %d — %s, %s', (int) $patrol['number'], self::gradoNome((int) $cmd['grado']), (string) $cmd['nome']);
        $righe[] = sprintf('Partenza: %s   Rientro: %s   Durata: %s',
            $clock->format((int) $patrol['departed_gts']),
            $patrol['returned_gts'] !== null ? $clock->format((int) $patrol['returned_gts']) : '—',
            Clock::durata((int) (($patrol['returned_gts'] ?? World::now()) - $patrol['departed_gts'])));
        $righe[] = sprintf('Percorse %s miglia, di cui %s in immersione. Nafta consumata %s tonnellate.',
            number_format((float) $patrol['distance_nm'], 0, ',', '.'),
            number_format((float) $patrol['submerged_nm'], 0, ',', '.'),
            number_format((float) $patrol['fuel_used_t'], 1, ',', '.'));
        $righe[] = '';
        $righe[] = sprintf('RISULTATO: %d navi affondate per %s tonnellate di stazza.', $affondate, number_format($grt, 0, ',', '.'));
        $righe[] = sprintf('Siluri lanciati: %d.', (int) ($patrol['siluri_lanciati'] ?? 0));
        if (!$rientrato) {
            $righe[] = 'Il battello non ha fatto ritorno.';
        }
        $righe[] = '';
        foreach ($conto['voci'] as $v) {
            $righe[] = sprintf('  %-52s %+6d', $v['voce'], $v['valore']);
        }
        $righe[] = sprintf('  %-52s %+6d', 'PRESTIGIO DELLA MISSIONE', $conto['prestigio']);
        $righe[] = sprintf('  %-52s %6d', 'Punti di assegnazione accreditati', $conto['punti']);
        $righe[] = sprintf('  %-52s %6d RM', 'Competenze', $conto['reichsmark']);

        if ($promosso) {
            $righe[] = '';
            $righe[] = 'PROMOZIONE: ' . self::gradoNome((int) $cmd['grado']) . '.';
        } elseif ($avanzato) {
            $righe[] = '';
            $righe[] = 'ANZIANITA\': maturato il livello ' . (int) $cmd['grado'] . '.';
        }
        foreach ($decorazioni as $d) {
            $righe[] = '';
            $righe[] = 'CONFERIMENTO: ' . $d['nome'] . ' (' . $d['nome_it'] . ').';
            $righe[] = $d['motivazione'];
        }

        return implode("\n", $righe);
    }

    // --- Spese ----------------------------------------------------------------

    /** @return list<array<string,mixed>> miglioramenti disponibili per il grado */
    public static function miglioramenti(int $boatId, int $grado): array
    {
        $posseduti = array_column(
            Database::all('SELECT ukey FROM boat_upgrades WHERE boat_id = ?', [$boatId]),
            'ukey'
        );
        $out = [];
        foreach (Database::all('SELECT * FROM upgrade_types ORDER BY categoria, costo') as $u) {
            $u['posseduto'] = in_array((string) $u['ukey'], $posseduti, true);
            $u['sbloccato'] = (int) $u['unlock_rank'] <= $grado;
            $out[] = $u;
        }
        return $out;
    }

    /** @return array{ok:bool, error?:string, nome?:string} */
    public static function compra(array $cmd, array $boat, string $ukey): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'I lavori si fanno in bunker, non in mare.'];
        }
        $u = Database::first('SELECT * FROM upgrade_types WHERE ukey = ?', [$ukey]);
        if ($u === null) {
            return ['ok' => false, 'error' => 'Apparato sconosciuto.'];
        }
        if ((int) $u['unlock_rank'] > (int) $cmd['grado']) {
            return ['ok' => false, 'error' => 'Non hai l\'anzianita\' per chiedere questo apparato.'];
        }
        $gia = Database::first('SELECT id FROM boat_upgrades WHERE boat_id = ? AND ukey = ?', [(int) $boat['id'], $ukey]);
        if ($gia !== null) {
            return ['ok' => false, 'error' => 'Gia\' installato a bordo.'];
        }
        if ((int) $cmd['punti'] < (int) $u['costo']) {
            return ['ok' => false, 'error' => sprintf(
                'Servono %d punti di assegnazione e ne hai %d. Il cantiere ha le sue priorita\'.',
                (int) $u['costo'], (int) $cmd['punti']
            )];
        }

        Database::run('UPDATE commanders SET punti = punti - ? WHERE id = ?', [(int) $u['costo'], (int) $cmd['id']]);
        Database::run(
            'INSERT INTO boat_upgrades (boat_id, ukey, gts) VALUES (?, ?, ?)',
            [(int) $boat['id'], $ukey, World::now()]
        );

        return ['ok' => true, 'nome' => (string) $u['nome']];
    }

    /** Effetti dei miglioramenti installati, nella forma che il motore legge. */
    public static function effettiMiglioramenti(int $boatId): array
    {
        $out = [];
        foreach (Database::all(
            'SELECT u.effetto, u.valore FROM boat_upgrades b JOIN upgrade_types u ON u.ukey = b.ukey WHERE b.boat_id = ?',
            [$boatId]
        ) as $r) {
            $e = (string) $r['effetto'];
            $v = (float) $r['valore'];
            // Fra due apparati dello stesso effetto vale il migliore.
            $out[$e] = isset($out[$e]) ? max($out[$e], $v) : $v;
        }
        return $out;
    }

    /** Addestramento dell'equipaggio fra una missione e l'altra. */
    public static function addestra(array $cmd, array $boat, string $specialita): array
    {
        if ((string) $boat['state'] !== 'base') {
            return ['ok' => false, 'error' => 'I corsi si fanno a terra.'];
        }
        $costo = 40;
        if ((int) $cmd['punti'] < $costo) {
            return ['ok' => false, 'error' => "Servono {$costo} punti di assegnazione."];
        }

        // Quanto rende il corso. Il seme tiene dentro la competenza che quella
        // specialita' ha ADESSO, se no sarebbe costante per battello: prima di
        // questa modifica il seme era soltanto (mondo + battello), e lo stesso
        // battello pescava lo stesso identico incremento per sempre. Misurato:
        // un battello a +4,55 per corso e un altro a +5,22, per tutta la
        // carriera, su decine di corsi. Restando deterministico — il mondo non
        // deve dipendere dal caso del momento — ma variando davvero.
        $attuale = (float) (Database::first(
            'SELECT COALESCE(SUM(competence), 0) c FROM crew_members WHERE boat_id = ? AND role_key = ?',
            [(int) $boat['id'], $specialita]
        )['c'] ?? 0);
        $rng = new Rng(World::seed() + (int) $boat['id'] + (int) round($attuale * 100));

        $n = Database::run(
            'UPDATE crew_members SET competence = LEAST(100, competence + ?)
             WHERE boat_id = ? AND role_key = ?',
            [round(4.5 + $rng->range(0, 3), 2), (int) $boat['id'], $specialita]
        )->rowCount();

        if ($n === 0) {
            return ['ok' => false, 'error' => 'Nessuno di quella specialita\' a bordo.'];
        }
        Database::run('UPDATE commanders SET punti = punti - ? WHERE id = ?', [$costo, (int) $cmd['id']]);

        return ['ok' => true, 'uomini' => $n];
    }
}
