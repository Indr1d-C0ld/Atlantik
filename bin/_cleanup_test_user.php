<?php

declare(strict_types=1);

/** Uso interno delle prove: rimuove un utente di prova e i suoi freni. */

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;

$username = $_SERVER['argv'][1] ?? '';
if ($username === '' || !preg_match('/^prova[ _]/', $username)) {
    fwrite(STDERR, "Rifiuto: questo comando cancella solo utenti 'prova_*' o 'prova *'.\n");
    exit(1);
}

$u = Database::first('SELECT id FROM users WHERE username = ?', [$username]);
if ($u !== null) {
    Database::run('DELETE FROM user_tokens WHERE user_id = ?', [(int) $u['id']]);
    Database::run('DELETE FROM audit_log WHERE actor_user_id = ?', [(int) $u['id']]);
    Database::run('DELETE FROM users WHERE id = ?', [(int) $u['id']]);
}
Database::run("DELETE FROM rate_limits WHERE rkey LIKE 'reg:%' OR rkey LIKE 'login:%' OR rkey LIKE 'azioni:%'");

// La posta delle prove: le righe restano in coda e falserebbero il conto degli
// invii verso il tetto giornaliero del provider.
Database::run("DELETE FROM mail_queue WHERE destinatario LIKE '%@esempio.invalid'");
// Anche l'avviso all'amministratore: e' diretto a una casella vera, e lasciato
// li' conterebbe verso il tetto giornaliero del provider (audit A6).
Database::run("DELETE FROM mail_queue WHERE genere = 'avviso_admin' AND oggetto LIKE '%: prova%'");

// Da quando ci sono le chiavi esterne (migrazione 0014) gli orfani non si
// formano piu' da soli. Questa passata resta come rete: ripulisce cio' che era
// gia' in tabella prima dei vincoli.
foreach ([
    'sinkings', 'patrols', 'bdu_orders', 'radio_messages', 'hfdf_fixes',
    'rendezvous', 'wolfpack_members', 'encounters',
] as $tabella) {
    Database::run("DELETE FROM {$tabella} WHERE boat_id IS NOT NULL AND boat_id NOT IN (SELECT id FROM boats)");
}
Database::run('DELETE FROM commanders WHERE user_id NOT IN (SELECT id FROM users)');

// Gli emblemi caricati dalle prove restano sul disco quando il battello sparisce.
App\Game\Emblema::potaOrfani();
echo "pulito: {$username}\n";
