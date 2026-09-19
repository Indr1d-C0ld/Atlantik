<?php

declare(strict_types=1);

/**
 * Prova del validatore dei nomi utente e della lunghezza minima della password.
 *
 *   php tests/test_username.php
 *
 * Non tocca il database (se non per leggere game_config) e non manda e-mail.
 */

require __DIR__ . '/../bin/_bootstrap.php';

use App\Auth\Auth;

$falliti = 0;

function prova(string $titolo, bool $esito): void
{
    global $falliti;
    if ($esito) {
        echo "  \033[0;32mok\033[0m    {$titolo}\n";
    } else {
        echo "  \033[0;31mKO\033[0m    {$titolo}\n";
        $falliti++;
    }
}

echo "Nomi utente ammessi\n";
$ammessi = [
    'Indrid Cold',            // il caso richiesto: nome e cognome
    'U 96',
    'Otto von Bismarck',
    'Kapitan D\'Angelo',
    'Gunther Prien',
    'silent_runner',
    'Nicolo Machiavelli',
    'Jose Maria Aznar',
    'a1b',                    // il minimo: 3 caratteri
    str_repeat('x', 32),      // il massimo: 32 caratteri
];
foreach ($ammessi as $u) {
    prova('"' . $u . '"', Auth::validateUsername($u) === null);
}

echo "\nNomi utente respinti\n";
$respinti = [
    ''                    => 'vuoto',
    'ab'                  => 'troppo corto',
    'Indrid Cold <script>' => 'caratteri non consentiti',
    ' Indrid'             => 'inizia con spazio (dopo la ripulitura resta valido? no: "Indrid" lo e\')',
    '_pippo'              => 'inizia con underscore',
    'pippo-'              => 'finisce con trattino',
    'pippo@esempio.it'    => 'chiocciola non consentita',
    str_repeat('x', 33)   => 'troppo lungo',
];
foreach ($respinti as $u => $perche) {
    // " Indrid" dopo la normalizzazione diventa "Indrid", che e' valido:
    // e' il comportamento voluto, quindi qui si prova il caso senza nome.
    if ($u === ' Indrid') {
        prova('spazi ai bordi ripuliti, non respinti', Auth::validateUsername(' Indrid ') === null
            && Auth::normalizeUsername(' Indrid ') === 'Indrid');
        continue;
    }
    prova($perche, Auth::validateUsername((string) $u) !== null);
}

echo "\nNormalizzazione della spaziatura\n";
// L'a capo non viene respinto ma appiattito a spazio: e' spaziatura come le altre,
// e un campo di testo HTML non puo' contenerlo comunque.
prova('a capo appiattito a spazio', Auth::normalizeUsername("riga\nspezzata") === 'riga spezzata');
prova('spazi multipli ridotti a uno', Auth::normalizeUsername('Indrid   Cold') === 'Indrid Cold');
prova('tabulazione trattata come spazio', Auth::normalizeUsername("Indrid\tCold") === 'Indrid Cold');
prova('spazio unificatore (U+00A0) normalizzato', Auth::normalizeUsername("Indrid\u{00A0}Cold") === 'Indrid Cold');
prova('bordi ripuliti', Auth::normalizeUsername('  Indrid Cold  ') === 'Indrid Cold');

echo "\nLunghezza minima della password\n";
$min = Auth::minPasswordLength();
prova("minimo attuale = 9 (letto: {$min})", $min === 9);

echo "\n";
if ($falliti === 0) {
    echo "\033[0;32mTutte le verifiche superate.\033[0m\n";
    exit(0);
}
printf("\033[0;31m%d verifiche fallite.\033[0m\n", $falliti);
exit(1);
