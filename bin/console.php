<?php

declare(strict_types=1);

/**
 * Atlantik — console amministrativa.
 *
 *   php bin/console.php migrate
 *   php bin/console.php user:list
 *   php bin/console.php user:verify <username|email>     conferma a mano un account
 *   php bin/console.php user:admin  <username>           promuove ad amministratore
 *   php bin/console.php user:unadmin <username>          riporta a giocatore comune
 *   php bin/console.php user:passwd <username>           reimposta la password
 *   php bin/console.php user:rename <vecchio> <nuovo>    cambia il nome d'accesso
 *   php bin/console.php mail:test   <indirizzo>          prova il trasporto SMTP
 *   php bin/console.php emblema:elenco                   emblemi in servizio
 *   php bin/console.php emblema:rimuovi <U-xx>           toglie un emblema
 *   php bin/console.php mail:coda                        stato della coda di spedizione
 *   php bin/console.php mail:smista [quanti]             tenta i messaggi in attesa
 *   php bin/console.php world:init [seme]                 crea il mondo
 *   php bin/console.php world:seed                        carica tipi di U-Boot e porti
 *   php bin/console.php world:stats                       stato del mondo
 *   php bin/console.php sim:tick                           avanza tutti i battelli in mare
 *   php bin/console.php sim:boat <id|numero>              scheda di un battello
 *   php bin/console.php crew:list <id|numero>             ruolino dell'equipaggio
 *   php bin/console.php traffic:status                    naviglio in mare
 *   php bin/console.php balance:report                    controllo di bilanciamento sui modelli
 *   php bin/console.php bdu:status                        ordini, branchi, traffico radio
 *   php bin/console.php career:status                     comandanti, gradi, decorazioni, albo d'oro
 *   php bin/console.php combat:status                     incontri in corso e albo degli affondamenti
 *   php bin/console.php traffic:ensure                    popola il mare (lo fa anche il tick)
 *   php bin/console.php config:list
 *   php bin/console.php config:set  <chiave> <valore> [tipo]
 *   php bin/console.php status
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

use App\Auth\Auth;
use App\Cli\Migrator;
use App\Cli\Seeder;
use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Mailer;
use App\Game\Fleet;
use App\Game\Patrol;
use App\Sim\BoatSim;
use App\Sim\Clock;
use App\Sim\Consumption;
use App\Sim\Crew;
use App\Sim\Damage;
use App\Sim\Geo;
use App\Sim\Grid;
use App\Sim\Sectors;
use App\Sim\Torpedo;
use App\Sim\Traffic;
use App\Sim\World;

$argv = $_SERVER['argv'];
$cmd  = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): void { fwrite(STDERR, $s . "\n"); }

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden) {
        shell_exec('stty -echo 2>/dev/null');
        $v = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        return $v;
    }
    return trim((string) fgets(STDIN));
}

/** @return array<string,mixed>|null */
function findUser(string $needle): ?array
{
    return Database::first(
        'SELECT * FROM users WHERE username = ? OR email = ? OR id = ?',
        [$needle, mb_strtolower($needle), ctype_digit($needle) ? (int) $needle : 0]
    );
}

try {
    switch ($cmd) {
        case 'migrate':
            out('Migrazioni:');
            foreach ((new Migrator($projectRoot . '/db/migrations'))->migrate() as $line) {
                out($line);
            }
            out('Fatto.');
            break;

        case 'status':
            $cfgFile = Config::sourceFile();
            out('Atlantik — stato');
            out('  config      : ' . $cfgFile);
            out('  database    : ' . (Database::isReachable() ? 'raggiungibile' : 'NON raggiungibile'));
            $ver = Database::all('SELECT version FROM schema_migrations ORDER BY version');
            out('  migrazioni  : ' . count($ver) . ' applicate' . (count($ver) ? ' (ultima: ' . end($ver)['version'] . ')' : ''));
            out('  utenti      : ' . (int) (Database::first('SELECT COUNT(*) n FROM users')['n'] ?? 0)
                . ' (attivi: ' . (int) (Database::first("SELECT COUNT(*) n FROM users WHERE status='active'")['n'] ?? 0) . ')');
            out('  mail        : trasporto ' . (string) Config::get('mail.transport', 'log')
                . ' via ' . (string) Config::get('mail.smtp_host', '—'));
            out('  avviso admin: ' . (string) Config::get('notify.admin_email', '—'));
            out('  tempo mondo : 1 minuto reale = ' . GameConfig::int('world.time_ratio', 30) . ' minuti di gioco');
            break;

        case 'user:list':
            $rows = Database::all('SELECT id, username, email, status, role, created_at, last_login_at FROM users ORDER BY id');
            if ($rows === []) {
                out('Nessun utente registrato.');
                break;
            }
            printf("%-4s %-20s %-30s %-10s %-10s %s\n", 'ID', 'UTENTE', 'E-MAIL', 'STATO', 'RUOLO', 'ISCRITTO');
            foreach ($rows as $r) {
                printf("%-4d %-20s %-30s %-10s %-10s %s\n",
                    $r['id'], $r['username'], $r['email'], $r['status'], $r['role'], substr((string) $r['created_at'], 0, 16));
            }
            break;

        case 'user:verify':
            $u = findUser($args[0] ?? '');
            if ($u === null) { err('Utente non trovato.'); exit(1); }
            Database::run("UPDATE users SET status='active', email_verified_at=COALESCE(email_verified_at, NOW()) WHERE id=?", [$u['id']]);
            Database::run("UPDATE user_tokens SET used_at=NOW() WHERE user_id=? AND kind='verify_email' AND used_at IS NULL", [$u['id']]);
            out("Account '{$u['username']}' confermato e attivato.");
            break;

        case 'user:admin':
            $u = findUser($args[0] ?? '');
            if ($u === null) { err('Utente non trovato.'); exit(1); }
            Database::run("UPDATE users SET role='admin', status='active', email_verified_at=COALESCE(email_verified_at, NOW()) WHERE id=?", [$u['id']]);
            out("Utente '{$u['username']}' promosso ad amministratore.");
            break;

        case 'user:unadmin':
            // Serve per le prove, ma non solo: un amministratore che si dimette
            // non deve dover aprire il database a mano.
            $u = findUser($args[0] ?? '');
            if ($u === null) { err('Utente non trovato.'); exit(1); }
            Database::run("UPDATE users SET role='player' WHERE id=?", [$u['id']]);
            out("Utente '{$u['username']}' riportato a giocatore comune.");
            break;

        case 'user:rename':
            // Il nome utente e' solo un'etichetta: la password, la sessione e
            // tutto il resto stanno attaccati all'id, non al nome. Rinominare
            // non fa perdere niente — ma passa dalla stessa validazione della
            // registrazione, perche' un nome che non si potrebbe scegliere non
            // deve potersi nemmeno ottenere per questa strada.
            $vecchio = $args[0] ?? '';
            $nuovo   = $args[1] ?? '';
            if ($vecchio === '' || $nuovo === '') {
                err('Uso: php bin/console.php user:rename <nome attuale> <nome nuovo>');
                exit(1);
            }
            $u = Database::first('SELECT id, username FROM users WHERE username = ?',
                [\App\Auth\Auth::normalizeUsername($vecchio)]);
            if ($u === null) { err("Nessun utente «{$vecchio}»."); exit(1); }

            $nuovo = \App\Auth\Auth::normalizeUsername($nuovo);
            if ($e = \App\Auth\Auth::validateUsername($nuovo)) { err($e); exit(1); }
            $preso = Database::first('SELECT id FROM users WHERE username = ? AND id <> ?', [$nuovo, (int) $u['id']]);
            if ($preso !== null) { err("«{$nuovo}» e' gia' in uso."); exit(1); }

            Database::run('UPDATE users SET username = ? WHERE id = ?', [$nuovo, (int) $u['id']]);
            \App\Support\Audit::log('user.rename', (int) $u['id'], 'user', (int) $u['id'],
                ['da' => (string) $u['username'], 'a' => $nuovo]);
            out("Rinominato: «{$u['username']}» → «{$nuovo}». Password e sessioni non toccate.");
            exit(0);

        case 'user:passwd':
            $u = findUser($args[0] ?? '');
            if ($u === null) { err('Utente non trovato.'); exit(1); }
            $min = Auth::minPasswordLength();
            $p1 = prompt("Nuova password (min {$min}): ", true);
            $p2 = prompt('Conferma: ', true);
            if (mb_strlen($p1) < $min || $p1 !== $p2) { err('Password non valida o non coincidente.'); exit(1); }
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($p1), $u['id']]);
            out('Password aggiornata.');
            break;

        case 'mail:test':
            $to = $args[0] ?? '';
            if ($to === '') { err('Uso: php bin/console.php mail:test <indirizzo>'); exit(1); }
            out('Trasporto: ' . (string) Config::get('mail.transport', 'log'));
            $t0 = microtime(true);
            $res = Mailer::send($to, 'Atlantik — prova di trasmissione', implode("\n", [
                'BEFEHLSHABER DER U-BOOTE',
                '',
                'Prova di trasmissione dal sistema Atlantik.',
                'Se leggi questo messaggio, il canale e\' aperto.',
                '',
                'Inviato il ' . date('d/m/Y H:i:s'),
            ]));
            $ms = (int) round((microtime(true) - $t0) * 1000);
            out($res['ok'] ? "Inviata in {$ms} ms." : 'FALLITA: ' . ($res['error'] ?? '?'));
            exit($res['ok'] ? 0 : 1);

        case 'emblema:elenco':
            out('Emblemi in servizio');
            $r = Database::all(
                "SELECT uboat_number, emblema_key, emblema_file, emblema_gts FROM boats
                  WHERE emblema_key IS NOT NULL OR emblema_file IS NOT NULL ORDER BY uboat_number"
            );
            if ($r === []) {
                out('  nessuno.');
            }
            foreach ($r as $x) {
                out(sprintf('  %-8s %s', $x['uboat_number'],
                    $x['emblema_key'] !== null ? 'repertorio: ' . $x['emblema_key'] : 'caricato: ' . $x['emblema_file']));
            }
            out('');
            out(sprintf('  file caricati orfani rimossi: %d', \App\Game\Emblema::potaOrfani()));
            exit(0);

        case 'emblema:rimuovi':
            // Un emblema caricato lo vedono anche gli altri comandanti, in
            // flottiglia e nell'albo. Serve un modo di toglierlo senza passare
            // dal database a mano.
            $u = $args[0] ?? '';
            if ($u === '') { err('Uso: php bin/console.php emblema:rimuovi <U-xx>'); exit(1); }
            $b = Database::first('SELECT id, uboat_number FROM boats WHERE uboat_number = ?', [$u]);
            if ($b === null) { err("Nessun battello {$u}."); exit(1); }
            \App\Game\Emblema::togli((int) $b['id']);
            out("Torretta di {$b['uboat_number']} ripulita.");
            exit(0);

        case 'mail:coda':
            $st = \App\Core\Posta::stato();
            out('Coda della posta');
            out(sprintf('  in attesa        : %d', $st['in_coda']));
            out(sprintf('  inviate in 24 ore: %d su un tetto di %d', $st['inviate_24h'], $st['tetto']));
            out(sprintf('  rinunciate       : %d', $st['rinunciate']));
            $righe = Database::all(
                "SELECT id, destinatario, oggetto, genere, tentativi, prossimo_at, ultimo_errore
                   FROM mail_queue WHERE inviato_at IS NULL AND rinunciato_at IS NULL
                   ORDER BY priorita, id LIMIT 15"
            );
            if ($righe !== []) {
                out('');
                out('  In attesa:');
                foreach ($righe as $r) {
                    out(sprintf('    #%-5d %-30s %-12s tentativi=%d  dalle %s',
                        $r['id'], mb_substr((string) $r['destinatario'], 0, 30), $r['genere'],
                        $r['tentativi'], $r['prossimo_at']));
                    if ($r['ultimo_errore'] !== null) {
                        out('           ultimo errore: ' . $r['ultimo_errore']);
                    }
                }
            }
            $rin = Database::all(
                "SELECT id, destinatario, genere, ultimo_errore FROM mail_queue
                  WHERE rinunciato_at IS NOT NULL ORDER BY id DESC LIMIT 5"
            );
            if ($rin !== []) {
                out('');
                out('  Rinunciate (le ultime):');
                foreach ($rin as $r) {
                    out(sprintf('    #%-5d %-30s %-12s %s', $r['id'],
                        mb_substr((string) $r['destinatario'], 0, 30), $r['genere'], (string) $r['ultimo_errore']));
                }
            }
            exit(0);

        case 'mail:smista':
            $n = isset($args[0]) ? (int) $args[0] : null;
            $r = \App\Core\Posta::smista($n);
            out(sprintf('Tentati %d: %d inviati, %d rinunciati.', $r['tentati'], $r['inviati'], $r['rinunciati']));
            exit(0);


        case 'world:init':
            $seme = isset($args[0]) ? (int) $args[0] : null;
            $w = World::init($seme);
            out('Mondo pronto.');
            out('  seme        : ' . $w['seed']);
            out('  rapporto    : 1:' . $w['time_ratio']);
            out('  inizio reale: ' . date('d/m/Y H:i', (int) $w['epoch_real_ts']));
            out('  ora di gioco: ' . World::clock()->format(World::now()));
            break;

        case 'world:seed':
            foreach ((new Seeder($projectRoot))->all() as $line) {
                out('  ' . $line);
            }
            break;

        case 'world:stats':
            $w = World::row();
            $clock = World::clock();
            $now = World::now();
            out('Mondo');
            out('  seme            : ' . $w['seed']);
            out('  data di bordo   : ' . $clock->format($now) . '  (campagna ' . Clock::ANNO_CAMPAGNA . ')');
            out('  tempo trascorso : ' . Clock::durata($now) . ' di gioco dall\'inizio');
            out('  rapporto        : 1:' . $w['time_ratio'] . '  (1 minuto reale = ' . $w['time_ratio'] . ' minuti di gioco; 1 ora reale = ' . $w['time_ratio'] . ' ore di gioco)');
            out('  tipi di U-Boot  : ' . count(World::types()));
            out('  porti           : ' . count(World::ports()) . ' (di cui basi: ' . count(World::bases()) . ')');
            $b = Database::first("SELECT COUNT(*) n, SUM(state='mare') mare FROM boats");
            out('  battelli        : ' . (int) ($b['n'] ?? 0) . ' (in mare: ' . (int) ($b['mare'] ?? 0) . ')');
            $p = Database::first("SELECT COUNT(*) n, SUM(state='in_corso') corso FROM patrols");
            out('  patrol          : ' . (int) ($p['n'] ?? 0) . ' (in corso: ' . (int) ($p['corso'] ?? 0) . ')');
            $e = Database::first('SELECT COUNT(*) n FROM patrol_events');
            out('  righe di KTB    : ' . (int) ($e['n'] ?? 0));
            break;

        case 'sim:tick':
            $t0 = microtime(true);
            // Anche i battelli in base: in porto non si naviga ma il cantiere
            // ripara, e quel lavoro va avanti con lo stesso battito.
            $boats = Database::all("SELECT id, uboat_number, state FROM boats WHERE state IN ('mare', 'base')");
            if ($boats === []) {
                out('Nessun battello in mare ne\' in base.');
                break;
            }
            foreach ($boats as $b) {
                $rotti = (int) (Database::first(
                    "SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'",
                    [(int) $b['id']]
                )['n'] ?? 0);
                $r = BoatSim::advance((int) $b['id']);
                if ((string) $b['state'] === 'base') {
                    $ora = (int) (Database::first(
                        "SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ? AND state <> 'ok'",
                        [(int) $b['id']]
                    )['n'] ?? 0);
                    out(sprintf('  %-8s in cantiere: %d avarie, %d riparate', $b['uboat_number'], $rotti, $rotti - $ora));
                    continue;
                }
                out(sprintf('  %-8s %3d passi, %7.1f nm, %d eventi', $b['uboat_number'], $r['steps'], $r['dist_nm'], $r['events']));
            }
            out(sprintf('Fatto in %d ms.', (int) round((microtime(true) - $t0) * 1000)));
            break;

        case 'sim:boat':
            $chiave = $args[0] ?? '';
            $boat = Database::first('SELECT * FROM boats WHERE id = ? OR uboat_number = ?', [ctype_digit($chiave) ? (int) $chiave : 0, $chiave]);
            if ($boat === null) { err('Battello non trovato.'); exit(1); }
            $type = World::type((string) $boat['type_key']);
            $clock = World::clock();
            $meteo = World::weather((float) $boat['lat'], (float) $boat['lon']);
            $cielo = World::sky((float) $boat['lat'], (float) $boat['lon'], null, (float) $meteo['cloud']);
            out($boat['uboat_number'] . ' — ' . $type['name'] . ' (' . $boat['flotilla'] . ')');
            out('  stato       : ' . $boat['state'] . ', modo ' . $boat['mode']);
            out('  posizione   : ' . Geo::formatLat((float) $boat['lat']) . '  ' . Geo::formatLon((float) $boat['lon'])
                . '   quadrato ' . (Grid::toQuadrat((float) $boat['lat'], (float) $boat['lon']) ?? '—'));
            out('  stimata     : ' . Geo::formatLat((float) $boat['est_lat']) . '  ' . Geo::formatLon((float) $boat['est_lon'])
                . '   errore ' . number_format((float) $boat['est_error_nm'], 1) . ' nm');
            out('  rotta/vel   : ' . sprintf('%03.0f°  %.1f kn (ordinati %.1f)', (float) $boat['heading'], (float) $boat['speed_kn'], (float) $boat['ordered_speed_kn']));
            out('  quota       : ' . sprintf('%.0f m (ordinata %.0f m)', (float) $boat['depth_m'], (float) $boat['ordered_depth_m']));
            out('  nafta       : ' . sprintf('%.1f / %.1f t  (%.0f%%, autonomia %.0f nm a 10 kn)',
                (float) $boat['fuel_t'], (float) $type['fuel_t'], 100 * (float) $boat['fuel_t'] / max(0.1, (float) $type['fuel_t']),
                Consumption::rangeLeftNm($type, (float) $boat['fuel_t'], 10)));
            out('  batteria    : ' . sprintf('%.0f%%  (%.1f h a 4 kn immersi)', (float) $boat['battery_pct'],
                Consumption::submergedHoursLeft($type, (float) $boat['battery_pct'], 4)));
            out('  aria        : ' . sprintf('%.0f%% (%s), CO2 %.2f%%', (float) $boat['air_pct'], Consumption::statoAria((float) $boat['air_pct']), (float) $boat['co2_pct']));
            out('  viveri      : ' . sprintf('%.1f giorni', (float) $boat['provisions_days']));
            out('  ora di bordo: ' . $clock->format((int) $boat['last_sim_gts']));
            out('  meteo       : ' . $meteo['descrizione'] . sprintf('  (%.0f hPa, vento %.0f kn, mare %d, vis %.1f nm)',
                (float) $meteo['pressure_hpa'], (float) $meteo['wind_kn'], (int) $meteo['sea_state'], (float) $meteo['visibility_nm']));
            out('  cielo       : ' . $cielo['fase'] . sprintf(', sole %.1f°, %s (%.0f%% illuminata, %.1f°), luce %.3f',
                $cielo['sun_alt'], $cielo['moon_phase'], 100 * $cielo['moon_illum'], $cielo['moon_alt'], $cielo['luce']));
            $sistemi = Damage::systems((int) $boat['id']);
            $effetti = Damage::effects($sistemi, (float) $boat['hull_stress']);
            $ciurma  = Crew::aggregate((int) $boat['id']);
            out('  equipaggio  : ' . $ciurma['uomini'] . ' uomini, competenza ' . number_format($ciurma['competenza'], 0)
                . ', morale ' . number_format($ciurma['morale'], 0) . ' (' . Crew::statoMorale($ciurma['morale']) . ')'
                . ', gli uomini sono ' . Crew::statoFaticaPlurale($ciurma['fatica']));
            out('  scafo       : sollecitazione ' . number_format((float) $boat['hull_stress'], 0) . '%'
                . ', collasso stimato ' . number_format((float) $type['crush_depth_min_m'] * $effetti['quota_max'], 0) . ' m');
            if ($effetti['guasti'] > 0) {
                out('  avarie      : ' . implode(', ', $effetti['elenco']));
                out('                velocita\' superficie x' . number_format($effetti['vel_superficie'], 2)
                    . ', immersione x' . number_format($effetti['vel_immersione'], 2)
                    . ', controllo quota x' . number_format($effetti['quota_controllo'], 2));
            } else {
                out('  avarie      : nessuna');
            }

            $inv = Torpedo::inventario((int) $boat['id']);
            $perTipo = [];
            foreach ($inv['per_tipo'] as $k => $n) { $perTipo[] = $k . ' x' . $n; }
            out('  siluri      : ' . $inv['tubi'] . ' nei tubi, ' . $inv['riserve'] . ' in riserva'
                . ($perTipo ? '  (' . implode(', ', $perTipo) . ')' : ''));

            $aff = Database::first(
                'SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings WHERE boat_id = ?', [(int) $boat['id']]
            );
            out('  affondato   : ' . (int) ($aff['n'] ?? 0) . ' navi, '
                . number_format((float) ($aff['grt'] ?? 0), 0, ',', '.') . ' GRT');

            $patrol = Patrol::corrente((int) $boat['id']);
            if ($patrol !== null) {
                out('  patrol n.' . $patrol['number'] . ': ' . number_format((float) $patrol['distance_nm'], 0) . ' nm percorse, '
                    . Clock::durata(World::now() - (int) $patrol['departed_gts']) . ' di mare');
                out('');
                out('  Ultime righe del giornale di guerra:');
                foreach (array_reverse(Patrol::ktb((int) $patrol['id'], 12)) as $e) {
                    out(sprintf('   %s  %-12s %s', $clock->format((int) $e['gts']), '[' . $e['kind'] . ']', $e['text']));
                }
            }
            break;

        case 'crew:list':
            $chiave = $args[0] ?? '';
            $boat = Database::first('SELECT * FROM boats WHERE id = ? OR uboat_number = ?', [ctype_digit($chiave) ? (int) $chiave : 0, $chiave]);
            if ($boat === null) { err('Battello non trovato.'); exit(1); }
            $ciurma = Crew::aggregate((int) $boat['id']);
            out($boat['uboat_number'] . ' — ' . $ciurma['uomini'] . ' uomini, guardia in servizio: '
                . Crew::currentWatch(World::now()) . 'a');
            printf("%-22s %-24s %-34s %-6s %-5s %-5s %s\n", 'NOME', 'GRADO', 'INCARICO', 'TURNO', 'COMP', 'FAT', 'MORALE');
            foreach (Crew::roster((int) $boat['id']) as $m) {
                printf("%-22s %-24s %-34s %-6s %-5.0f %-5.0f %.0f\n",
                    $m['name'], $m['rank_name'], $m['role_name'],
                    (int) $m['watch_no'] === 0 ? 'giorn.' : $m['watch_no'] . 'a',
                    (float) $m['competence'], (float) $m['fatigue'], (float) $m['morale']);
            }
            break;

        case 'traffic:status':
            $gts = World::now();
            $st = Traffic::stato($gts);
            out('Traffico alleato alle ' . World::clock()->format($gts));
            out('  convogli in mare : ' . $st['convogli']);
            out('  navi isolate     : ' . $st['isolate']);
            out('  navi totali      : ' . $st['navi'] . '  (' . number_format($st['grt_mare'], 0, ',', '.') . ' GRT)');
            out('  affondate finora : ' . $st['affondate'] . '  (' . number_format($st['grt_affondato'], 0, ',', '.') . ' GRT)');
            out('');
            out('  Convogli:');
            foreach (Database::all("SELECT * FROM convoys WHERE state = 'in_mare' AND departed_gts <= ? AND eta_gts >= ? ORDER BY serie, numero", [$gts, $gts]) as $cv) {
                $p = Traffic::posizione((string) $cv['rotta_key'], (float) $cv['speed_kn'], (int) $cv['departed_gts'], $gts, (float) $cv['deviazione']);
                $navi = (int) (Database::first("SELECT COUNT(*) n FROM ships WHERE convoy_id = ? AND state = 'in_mare'", [$cv['id']])['n'] ?? 0);
                out(sprintf('    %-7s %-36s %4.1f kn  %-10s %2d navi',
                    $cv['serie'] . $cv['numero'], Traffic::nomeRotta((string) $cv['rotta_key']), (float) $cv['speed_kn'],
                    $p !== null ? (Grid::toQuadrat($p['lat'], $p['lon'], 2) ?? '—') : 'in porto', $navi));
            }
            $caldi = Sectors::caldi($gts, 8);
            if ($caldi !== []) {
                out('');
                out('  Settori caldi:');
                foreach ($caldi as $sc) {
                    out(sprintf('    %-8s %5.1f  %s', $sc['quadrat'], $sc['heat'], $sc['stato']));
                }
            }
            break;

        case 'traffic:ensure':
            $r = Traffic::ensure(World::now());
            out(sprintf('Creati %d convogli e %d navi isolate; %d convogli arrivati.', $r['convogli'], $r['navi'], $r['arrivati']));
            break;

        case 'combat:status':
            $gts = World::now();
            $enc = Database::all("SELECT e.*, b.uboat_number FROM encounters e JOIN boats b ON b.id = e.boat_id WHERE e.stato <> 'concluso'");
            out('Incontri in corso: ' . count($enc));
            foreach ($enc as $x) {
                $ent = (int) (Database::first('SELECT COUNT(*) n FROM encounter_entities WHERE encounter_id = ?', [$x['id']])['n'] ?? 0);
                out(sprintf('  %-8s %-14s %2d unita\', %d affondate, %d cariche subite, finestra %s',
                    $x['uboat_number'], $x['stato'], $ent, (int) $x['affondate'], (int) $x['cariche_subite'],
                    max(0, (int) $x['finestra_fine'] - time()) . ' s'));
            }
            out('');
            $tot = Database::first('SELECT COUNT(*) n, COALESCE(SUM(grt),0) grt FROM sinkings');
            out('Albo degli affondamenti: ' . (int) ($tot['n'] ?? 0) . ' navi, '
                . number_format((float) ($tot['grt'] ?? 0), 0, ',', '.') . ' GRT');
            foreach (Database::all('SELECT s.*, b.uboat_number FROM sinkings s JOIN boats b ON b.id = s.boat_id ORDER BY s.gts DESC LIMIT 15') as $sk) {
                out(sprintf('  %s  %-8s %-26s %-14s %8s GRT  %s  %s',
                    World::clock()->format((int) $sk['gts']), $sk['uboat_number'], mb_substr((string) $sk['nome'], 0, 26),
                    $sk['bandiera'], number_format((int) $sk['grt'], 0, ',', '.'), $sk['quadrat'] ?? '—', $sk['arma']));
            }
            break;

        case 'career:status':
            $att = Database::all("SELECT c.*, u.username FROM commanders c JOIN users u ON u.id = c.user_id
                                  WHERE c.stato = 'active' OR c.stato = 'attivo' ORDER BY c.grt_affondato DESC");
            out('Comandanti in servizio: ' . count($att));
            foreach ($att as $c) {
                $dec = (int) (Database::first('SELECT COUNT(*) n FROM awards WHERE commander_id = ?', [$c['id']])['n'] ?? 0);
                out(sprintf('  %-22s %-22s liv %2d  %2d missioni  %2d navi  %9s GRT  %3d punti  %d decorazioni',
                    $c['nome'], \App\Game\Carriera::gradoNome((int) $c['grado']), (int) $c['grado'],
                    (int) $c['patrols'], (int) $c['affondate'], number_format((float) $c['grt_affondato'], 0, ',', '.'),
                    (int) $c['punti'], $dec));
            }
            $albo = \App\Game\Comandante::albo(20);
            out('');
            out('Albo d\'oro: ' . count($albo) . ' fascicoli chiusi');
            foreach ($albo as $c) {
                out(sprintf('  %-22s %-18s %2d missioni  %9s GRT  %-12s %s',
                    $c['nome'], \App\Game\Carriera::gradoNome((int) $c['grado']), (int) $c['patrols'],
                    number_format((float) $c['grt_affondato'], 0, ',', '.'), $c['stato'], $c['ultimo_quadrat'] ?? '—'));
            }
            break;

        case 'bdu:status':
            $gts = World::now();
            out('BdU alle ' . World::clock()->format($gts));
            $ord = Database::all("SELECT tipo, stato, COUNT(*) n FROM bdu_orders GROUP BY tipo, stato ORDER BY tipo");
            out('  Ordini:');
            foreach ($ord as $o) {
                out(sprintf('    %-14s %-12s %d', $o['tipo'], $o['stato'], (int) $o['n']));
            }
            out('');
            out('  Gruppi operativi:');
            foreach (Database::all("SELECT w.*, (SELECT COUNT(*) FROM wolfpack_members m WHERE m.wolfpack_id = w.id AND m.uscito_gts IS NULL) membri
                                    FROM wolfpacks w WHERE w.stato <> 'sciolto'") as $w) {
                out(sprintf('    %-16s %-8s %d battelli, chiude fra %d ore',
                    $w['nome'], $w['quadrat'], (int) $w['membri'], max(0, (int) round(((int) $w['chiude_gts'] - time()) / 3600))));
            }
            out('');
            $radio = Database::first('SELECT COUNT(*) n, SUM(intercettato) i FROM radio_messages WHERE boat_id IS NOT NULL');
            out('  Traffico radio: ' . (int) ($radio['n'] ?? 0) . ' trasmissioni dai battelli, '
                . (int) ($radio['i'] ?? 0) . ' intercettate');
            foreach (Database::all('SELECT f.*, b.uboat_number FROM hfdf_fixes f JOIN boats b ON b.id = f.boat_id
                                    ORDER BY f.gts DESC LIMIT 8') as $f) {
                out(sprintf('    %s  %-8s %d rilevamenti, errore %5.1f nm, %-9s %s',
                    World::clock()->format((int) $f['gts']), $f['uboat_number'], (int) $f['rilevamenti'],
                    (float) $f['errore_nm'], $f['quadrat'] ?? '—', $f['reazione']));
            }
            break;

        case 'balance:report':
            // Controllo di bilanciamento: si rimisurano i modelli e si confronta
            // con le bande storiche attese. Non e' una prova automatica, e' un
            // cruscotto: serve a vedere se una modifica ha spostato qualcosa
            // che non doveva spostarsi.
            $banda = static function (string $nome, float $valore, float $min, float $max, string $unita = ''): void {
                $ok = $valore >= $min && $valore <= $max;
                out(sprintf('  %-46s %8.1f %-4s  atteso %5.1f-%-5.1f  %s',
                    $nome, $valore, $unita, $min, $max, $ok ? 'ok' : '*** FUORI BANDA ***'));
            };

            out('BILANCIAMENTO — confronto coi valori storici attesi');
            out('');
            out(' Consumi e autonomia (Tipo VII C)');
            $viic = World::type('VIIC');
            $banda('autonomia a 10 nodi', Consumption::rangeLeftNm($viic, 113.5, 10), 8300, 8700, 'nm');
            $banda('autonomia a tutta forza', Consumption::rangeLeftNm($viic, 113.5, 17.7), 3100, 3500, 'nm');
            $banda('ore in immersione a 4 nodi', Consumption::submergedHoursLeft($viic, 100, 4), 19, 21, 'h');

            out('');
            out(' Idrofono (mare 4, battello in ascolto a 2 nodi silenzioso)');
            $pr = \App\Sim\Acoustics::ownNoise(2.0, true, 7.6);
            $banda('convoglio di 40 navi',
                \App\Sim\Acoustics::detectionRangeNm(\App\Sim\Acoustics::sourceLevel(138, 9, 9.5, 40), 4, 0, $pr), 28, 40, 'nm');
            $banda('mercantile isolato',
                \App\Sim\Acoustics::detectionRangeNm(\App\Sim\Acoustics::sourceLevel(138, 9.5, 9.5, 1), 4, 0, $pr), 8, 15, 'nm');

            out('');
            out(' Avvistamento di un U-Boot in superficie (da ponte di scorta, mare 3)');
            $banda('notte senza luna',
                \App\Sim\Detection::portataVisiva(12.0, 5.0, 1.0, 20, 0.008, 3), 1.0, 2.5, 'nm');
            $banda('con luna piena',
                \App\Sim\Detection::portataVisiva(12.0, 5.0, 1.0, 20, 0.09, 3), 3.0, 5.5, 'nm');
            $banda('di giorno',
                \App\Sim\Detection::portataVisiva(12.0, 5.0, 1.0, 20, 1.0, 3), 10.0, 13.0, 'nm');

            out('');
            out(' Siluri: affondamento con un colpo sotto la chiglia (500 prove per classe)');
            foreach ([2400 => [92, 100], 5100 => [55, 75], 8200 => [28, 50], 16600 => [0, 12]] as $grt => [$min, $max]) {
                $aff = 0;
                for ($i = 0; $i < 500; $i++) {
                    $d = \App\Sim\Torpedo::danno($grt, 280, 'cereali', true, \App\Sim\Rng::for(11, 'bal', $grt, $i));
                    if ($d['danno'] >= 100 || $d['allagamento'] >= 60) { $aff++; }
                }
                $banda(number_format($grt, 0, ',', '.') . ' GRT', 100 * $aff / 500, (float) $min, (float) $max, '%');
            }

            out('');
            out(' Meteo del Nord Atlantico (400 campioni per stagione, 50-60N)');
            $clockB = World::clock();
            foreach ([['gennaio', 0, 18.0, 26.0], ['luglio', 181 * 86400, 10.0, 18.0]] as [$mese, $off, $min, $max]) {
                $somma = 0.0;
                for ($i = 0; $i < 400; $i++) {
                    $t = $off + $i * 3600;
                    $w = \App\Sim\Weather::at(2024, $t, 50 + ($i * 7 % 100) / 10.0, -40 + ($i * 13 % 300) / 10.0, $clockB->date($t));
                    $somma += (float) $w['wind_kn'];
                }
                $banda('vento medio, ' . $mese, $somma / 400, $min, $max, 'kn');
            }

            out('');
            out(' Radiogoniometria: probabilita\' per apparato');
            $banda('segnale breve (22 s)', 100 * max(0.3, min(0.95, 0.3 + 22 / 130.0)), 40, 55, '%');
            out('');
            out(' Stato del mondo');
            $st = \App\Game\Statistiche::campagna(World::now());
            out(sprintf('  naviglio alleato in mare: %s navi, %s GRT, %d convogli',
                number_format((float) $st['naviglio_in_mare'], 0, ',', '.'),
                number_format((float) $st['grt_in_mare'], 0, ',', '.'), $st['convogli']));
            out(sprintf('  affondamenti: %d navi per %s GRT; battelli perduti: %d; scambio: %s',
                $st['navi_affondate'], number_format((float) $st['grt_affondato'], 0, ',', '.'),
                $st['battelli_persi'], $st['scambio'] !== null ? number_format((float) $st['scambio'], 0, ',', '.') . ' GRT' : '—'));
            break;

        case 'config:list':
            foreach (GameConfig::all() as $k => $v) {
                printf("%-32s %-8s %s\n", $k, $v['type'], $v['value']);
            }
            break;

        case 'config:set':
            [$k, $v, $t] = [$args[0] ?? '', $args[1] ?? '', $args[2] ?? 'string'];
            if ($k === '') { err('Uso: php bin/console.php config:set <chiave> <valore> [tipo]'); exit(1); }
            GameConfig::set($k, $v, $t);
            out("{$k} = {$v} ({$t})");
            break;

        default:
            out(trim((string) file_get_contents(__FILE__, false, null, 0, 1400)));
            break;
    }
} catch (\Throwable $e) {
    err('Errore: ' . $e->getMessage());
    exit(1);
}
