<?php

declare(strict_types=1);

/**
 * Uso interno delle prove: fa avanzare il battello di un utente 'prova *' di N
 * ore di gioco, saltando l'attesa del tempo reale. Rifiuta ogni altro utente.
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Sim\BoatSim;

$username = $_SERVER['argv'][1] ?? '';
$ore      = (int) ($_SERVER['argv'][2] ?? 0);

if ($username === '' || !preg_match('/^prova[ _]/', $username)) {
    fwrite(STDERR, "Rifiuto: solo utenti 'prova_*' o 'prova *'.\n");
    exit(1);
}

$boat = Database::first(
    'SELECT b.* FROM boats b JOIN users u ON u.id = b.user_id WHERE u.username = ?',
    [$username]
);
if ($boat === null) {
    fwrite(STDERR, "Nessun battello per {$username}.\n");
    exit(1);
}

// Non si spinge il battello nel futuro — non si puo' piu', ed era sbagliato
// anche quando si poteva: restava avanti all'orologio del mondo e quindi fermo
// finche' il mondo non lo raggiungeva. Si fa il contrario: gli si arretra
// l'orologio di N ore e poi lo si porta al presente. Vive le stesse ore, ma
// dentro il tempo del mondo invece che oltre.
$adesso = \App\Sim\World::now();
$da = min((int) $boat['last_sim_gts'], $adesso - $ore * 3600);
Database::run('UPDATE boats SET last_sim_gts = ? WHERE id = ?', [$da, (int) $boat['id']]);

$r = BoatSim::advance((int) $boat['id']);
echo json_encode($r), "\n";
