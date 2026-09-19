<?php

declare(strict_types=1);

/**
 * Disegna gli emblemi di torretta.
 *
 *   php bin/disegna_emblemi.php
 *
 * I disegni sono ORIGINALI, fatti per questo gioco. Non sono riproduzioni: gli
 * emblemi storici non esistono come file liberi da nessuna parte — Commons non
 * ne ha una raccolta, e uboat.net chiede espressamente agli agenti automatici
 * di stare fuori — quindi si disegna, come si e' fatto per le sagome delle navi.
 *
 * Lo stile e' quello delle mascherine vere: campo tondo, sagoma piena, bordo
 * netto, due o tre colori. Doveva leggersi dalla banchina e sopravvivere alla
 * vernice, non stare bene su uno schermo.
 *
 * I SOGGETTI invece sono documentati dove si puo': il pupazzo di neve e' il
 * gioco di parole di Adalbert Schnee su U-201, il diavolo rosso e' quello di
 * Erich Topp su U-552, l'orso bianco viene dai gruppi artici. Dove il soggetto
 * e' solo plausibile per l'epoca, la scheda lo dichiara: 'ricostruita'.
 *
 * NIENTE INSEGNE DI PARTITO. Gli emblemi di torretta erano scherzi, portafortuna
 * e giochi di parole sul nome del comandante: animali, carte da gioco, oggetti.
 * Qui si resta in quel territorio, che e' anche quello storicamente esatto.
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

const CAMPO_BLU   = '#17415c';
const CAMPO_SCURO = '#2b2b33';
const CAMPO_VERDE = '#1d4034';
const CAMPO_ROSSO = '#5c1f20';
const BORDO       = '#0d1c2b';
const CHIARO      = '#f2efe4';
const GRIGIO      = '#cfd6dc';
const OMBRA       = '#7b868f';
const ORO         = '#d5a838';
const ROSSO       = '#b83a2e';
const NERO        = '#1a1a1f';

function svg(string $titolo, string $campo, string $corpo): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" role="img" aria-label="'
        . htmlspecialchars($titolo, ENT_QUOTES) . '"><title>' . htmlspecialchars($titolo, ENT_QUOTES) . '</title>'
        . '<circle cx="60" cy="60" r="56" fill="' . $campo . '" stroke="' . BORDO . '" stroke-width="4"/>'
        . $corpo . '</svg>';
}

/** Una sagoma piena col bordo, come una mascherina dipinta. */
function forma(string $d, string $fill, string $stroke = BORDO, float $w = 2.5): string
{
    return '<path d="' . $d . '" fill="' . $fill . '" stroke="' . $stroke . '" stroke-width="' . $w
        . '" stroke-linejoin="round" stroke-linecap="round"/>';
}

function linea(string $d, string $stroke, float $w = 3.0): string
{
    return '<path d="' . $d . '" fill="none" stroke="' . $stroke . '" stroke-width="' . $w
        . '" stroke-linecap="round" stroke-linejoin="round"/>';
}

function cerchio(float $x, float $y, float $r, string $fill, string $stroke = BORDO, float $w = 2.5): string
{
    return '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="' . $fill
        . '" stroke="' . $stroke . '" stroke-width="' . $w . '"/>';
}

// --- i disegni ---------------------------------------------------------------

$emblemi = [];

$emblemi['pupazzo_di_neve'] = [
    'nome' => 'Pupazzo di neve', 'campo' => CAMPO_BLU, 'confidence' => 'alta',
    'motto' => 'Per chi si fa un nome col proprio nome',
    'storia' => 'Adalbert Schnee lo portava su U-201: Schnee vuol dire neve, e i giochi di '
              . 'parole sul nome del comandante erano il motivo piu\' comune di tutti.',
    'disegno' => cerchio(60, 78, 22, CHIARO) . cerchio(60, 46, 15, CHIARO)
        . forma('M44 36h32l-4-9H48z', NERO) . forma('M48 27h24l-3-8H51z', NERO)
        . cerchio(55, 43, 2.4, NERO, NERO, 0) . cerchio(65, 43, 2.4, NERO, NERO, 0)
        . forma('M60 47l6 4-6 3z', ORO)
        . linea('M38 70h-14M82 70h14', OMBRA, 3.5)
        . cerchio(60, 70, 2.6, NERO, NERO, 0) . cerchio(60, 82, 2.6, NERO, NERO, 0),
];

$emblemi['diavolo_rosso'] = [
    'nome' => 'Diavolo rosso', 'campo' => CAMPO_SCURO, 'confidence' => 'alta',
    'motto' => 'Rosso, e di buon umore',
    'storia' => 'Il "Roter Teufel" di Erich Topp, su U-552: uno degli emblemi piu\' riconoscibili '
              . 'dell\'arma, e uno dei pochi che il comandante si porto\' dietro da un battello all\'altro.',
    'disegno' => forma('M60 26c-16 0-27 12-27 28 0 18 12 34 27 42 15-8 27-24 27-42 0-16-11-28-27-28z', ROSSO)
        . forma('M36 34l-8-14 16 6zM84 34l8-14-16 6z', ROSSO)
        . forma('M46 56l10 4-10 4zM74 56l-10 4 10 4z', CHIARO)
        . linea('M44 78c6 8 26 8 32 0', NERO, 3.5)
        . forma('M52 82h16l-3 6h-10z', CHIARO),
];

$emblemi['orso_bianco'] = [
    'nome' => 'Orso bianco', 'campo' => CAMPO_BLU, 'confidence' => 'alta',
    'motto' => 'Dal ghiaccio, e con calma',
    'storia' => 'L\'orso polare accompagnava i gruppi operativi artici — "Eisbaer" era il nome '
              . 'di uno di essi — e i battelli che operavano a nord del Circolo se lo dipingevano.',
    'disegno' => forma('M30 76c0-16 12-26 30-26s30 10 30 26c0 8-6 12-14 12H44c-8 0-14-4-14-12z', CHIARO)
        . cerchio(44, 44, 16, CHIARO) . cerchio(33, 32, 6, CHIARO) . cerchio(55, 32, 6, CHIARO)
        . cerchio(39, 42, 2.6, NERO, NERO, 0) . cerchio(50, 42, 2.6, NERO, NERO, 0)
        . forma('M40 52h9l-4.5 6z', NERO),
];

$emblemi['quadrifoglio'] = [
    'nome' => 'Quadrifoglio', 'campo' => CAMPO_VERDE, 'confidence' => 'ricostruita',
    'motto' => 'La fortuna non si comanda, si spera',
    'storia' => 'Portafortuna comunissimo sulle torrette: la guerra al traffico si vinceva anche '
              . 'per caso, e gli equipaggi lo sapevano meglio di chiunque.',
    'disegno' => forma('M60 58c-6-8-18-8-18 2s12 12 18 4z', '#6db36b')
        . forma('M60 58c8-6 8-18-2-18s-12 12-4 18z', '#6db36b')
        . forma('M60 58c6 8 18 8 18-2s-12-12-18-4z', '#6db36b')
        . forma('M60 58c-8 6-8 18 2 18s12-12 4-18z', '#6db36b')
        . linea('M60 60c2 12 0 22-6 30', '#3f7a42', 4),
];

$emblemi['gatto_nero'] = [
    'nome' => 'Gatto nero', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Porta sfortuna, ma agli altri',
    'storia' => 'Il gatto nero sta fra i portafortuna rovesciati: sfortuna per chi lo incrocia, '
              . 'che su una torretta e\' esattamente il messaggio che si vuole dare.',
    'disegno' => forma('M40 92c0-22 8-34 20-34s20 12 20 34z', NERO)
        . cerchio(60, 46, 17, NERO)
        . forma('M46 34l-4-14 14 7zM74 34l4-14-14 7z', NERO)
        . forma('M80 88c10-4 14-14 12-24', 'none', ORO, 4)
        . cerchio(53, 44, 3.2, ORO, ORO, 0) . cerchio(67, 44, 3.2, ORO, ORO, 0)
        . linea('M52 54h-12M68 54h12', ORO, 2),
];

$emblemi['cavalluccio_marino'] = [
    'nome' => 'Cavalluccio marino', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Piccolo, e sempre in verticale',
    'storia' => 'Il "Seepferdchen" era un soggetto di casa nell\'arma subacquea: sta dritto '
              . 'nell\'acqua come un sommergibile a quota periscopica.',
    'disegno' => forma('M62 22c10 0 16 8 14 18-2 8-8 12-8 20 0 10 6 14 6 24 0 10-8 16-16 12 '
                . '6-2 8-8 6-14-3-8-10-12-10-24 0-14 8-18 8-26 0-6-4-10-10-10z', ORO)
        . forma('M62 22c-8 0-14 4-16 10l10 2z', ORO)
        . cerchio(66, 31, 2.6, NERO, NERO, 0)
        . linea('M52 46l-8 4M50 58l-8 5M52 70l-7 5', '#a8801f', 3),
];

$emblemi['squalo'] = [
    'nome' => 'Squalo', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Sotto, e con calma',
    'storia' => 'Squali e pescecani ricorrono su molte torrette, per la ragione ovvia: '
              . 'e\' l\'animale che fa quello che fa un sommergibile.',
    'disegno' => forma('M16 64c14-14 40-20 62-16 12 2 20 8 26 16-8 8-18 12-30 13-22 2-44-4-58-13z', GRIGIO)
        . forma('M58 48l8-22 10 24z', GRIGIO)
        . forma('M104 64l12-10v22z', GRIGIO)
        . forma('M56 78l4 14 12-12z', GRIGIO)
        . linea('M22 66c10 4 20 6 30 6', OMBRA, 2.5)
        . cerchio(32, 60, 3, NERO, NERO, 0)
        . linea('M18 66l8 2 8-2 8 2', CHIARO, 2.2),
];

$emblemi['testa_di_lupo'] = [
    'nome' => 'Testa di lupo', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Non si caccia da soli',
    'storia' => 'Il branco — Rudel — era il modo di combattere dell\'arma dal 1941: il lupo '
              . 'sulla torretta diceva a quale scuola apparteneva il battello.',
    // Stessa geometria della volpe, che a quaranta pixel funziona: muso
    // triangolare, orecchie alte, macchia chiara sul davanti. Cambia il colore
    // e cambia l'animale — ed e' cosi' che funzionavano le mascherine vere.
    'disegno' => forma('M60 98L30 60c-5-7-7-16-5-26l12 14 9-24 14 16 14-16 9 24 12-14c2 10 0 19-5 26z', '#98a0a8')
        . forma('M42 70h36l-18 24z', CHIARO)
        . forma('M48 46l6 10-12-4zM72 46l-6 10 12-4z', '#6d757c')
        . cerchio(50, 64, 3.6, ORO, ORO, 0) . cerchio(70, 64, 3.6, ORO, ORO, 0)
        . cerchio(60, 82, 3.6, NERO, NERO, 0),
];

$emblemi['corona'] = [
    'nome' => 'Corona', 'campo' => CAMPO_ROSSO, 'confidence' => 'ricostruita',
    'motto' => 'Chi comanda, comanda',
    'storia' => 'Corone e stemmi araldici comparivano sulle torrette dei battelli adottati da '
              . 'una citta\' o da una regione: era un legame che si portava in mare.',
    'disegno' => forma('M28 78l-6-40 18 14 20-24 20 24 18-14-6 40z', ORO)
        . forma('M26 78h68v12H26z', ORO)
        . cerchio(22, 38, 5, ROSSO) . cerchio(98, 38, 5, ROSSO) . cerchio(60, 28, 5, ROSSO)
        . cerchio(44, 70, 4, ROSSO, BORDO, 2) . cerchio(76, 70, 4, ROSSO, BORDO, 2),
];

$emblemi['civetta'] = [
    'nome' => 'Civetta', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Vede di notte',
    'storia' => 'L\'attacco notturno in superficie era la tattica che rese temibile l\'arma nel '
              . '1940: un animale che vede al buio era la firma giusta.',
    'disegno' => forma('M34 58c0-18 12-30 26-30s26 12 26 30c0 22-12 36-26 36S34 80 34 58z', '#8a7b5c')
        . forma('M36 34l4-16 14 10zM84 34l-4-16-14 10z', '#8a7b5c')
        . cerchio(49, 54, 11, CHIARO) . cerchio(71, 54, 11, CHIARO)
        . cerchio(49, 54, 5, NERO, NERO, 0) . cerchio(71, 54, 5, NERO, NERO, 0)
        . forma('M60 60l6 8h-12z', ORO)
        . linea('M44 78c8 6 24 6 32 0', '#5e523c', 3),
];

$emblemi['volpe'] = [
    'nome' => 'Volpe', 'campo' => CAMPO_VERDE, 'confidence' => 'ricostruita',
    'motto' => 'Non la forza: l\'astuzia',
    'storia' => 'Un mezzo che vince nascondendosi ha nella volpe il suo animale araldico, e '
              . 'diversi battelli ne fecero il proprio segno.',
    'disegno' => forma('M60 94L34 64c-4-6-6-14-4-22l10 12 8-20 12 14 12-14 8 20 10-12c2 8 0 16-4 22z', '#c9732d')
        . forma('M44 76h32l-16 14z', CHIARO)
        . cerchio(50, 62, 3.2, NERO, NERO, 0) . cerchio(70, 62, 3.2, NERO, NERO, 0)
        . cerchio(60, 82, 3.4, NERO, NERO, 0),
];

$emblemi['granchio'] = [
    'nome' => 'Granchio', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Di traverso, e stringe',
    'storia' => 'Il granchio compare sui battelli del Mediterraneo e delle acque basse: '
              . 'cammina di lato e non molla, che come biglietto da visita basta e avanza.',
    'disegno' => forma('M28 62c0-16 14-26 32-26s32 10 32 26c0 12-14 20-32 20s-32-8-32-20z', ROSSO)
        . forma('M28 54L12 42c-8 8-6 20 4 24 4 2 8 0 10-4z', ROSSO)
        . forma('M92 54l16-12c8 8 6 20-4 24-4 2-8 0-10-4z', ROSSO)
        . forma('M14 40l10-8 2 12zM106 40l-10-8-2 12z', ROSSO)
        . linea('M36 82l-10 14M50 86l-4 16M70 86l4 16M84 82l10 14', '#8f2b22', 4.5)
        . cerchio(48, 54, 5, CHIARO) . cerchio(72, 54, 5, CHIARO)
        . cerchio(48, 54, 2.2, NERO, NERO, 0) . cerchio(72, 54, 2.2, NERO, NERO, 0)
        . linea('M50 70h20', '#8f2b22', 3),
];

$emblemi['leone'] = [
    'nome' => 'Leone', 'campo' => CAMPO_ROSSO, 'confidence' => 'ricostruita',
    'motto' => 'Si sente da lontano',
    'storia' => 'Leoni araldici arrivavano dagli stemmi delle citta\' che avevano adottato il '
              . 'battello, e finivano sulla torretta come una firma.',
    'disegno' => forma('M60 20c-22 0-38 16-38 38s16 38 38 38 38-16 38-38-16-38-38-38z', ORO)
        . forma('M60 32c-14 0-24 10-24 24s10 24 24 24 24-10 24-24-10-24-24-24z', '#e8c46a')
        . cerchio(51, 54, 3.4, NERO, NERO, 0) . cerchio(69, 54, 3.4, NERO, NERO, 0)
        . forma('M60 62l7 7H53z', NERO)
        . linea('M46 74c6 8 22 8 28 0', '#8a6a18', 3.5)
        . linea('M40 60l-12-3M40 66l-12 4M80 60l12-3M80 66l12 4', '#8a6a18', 2.5),
];

$emblemi['siluro_alato'] = [
    'nome' => 'Siluro alato', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Arriva prima di quanto si creda',
    'storia' => 'Il siluro con le ali e\' un soggetto da reparto: mette insieme l\'arma e la '
              . 'velocita\', e non serve spiegarlo a nessuno.',
    'disegno' => forma('M30 52h50c8 0 16 4 16 8s-8 8-16 8H30z', GRIGIO)
        . forma('M30 52c-6 0-10 4-10 8s4 8 10 8z', ROSSO)
        . forma('M56 52L40 26h12l14 26zM56 68L40 94h12l14-26z', CHIARO)
        . forma('M96 60l14-10v20z', GRIGIO)
        . linea('M44 56v8M52 55v10M64 55v10', OMBRA, 2.4)
        . cerchio(25, 60, 2.6, CHIARO, CHIARO, 0),
];

$emblemi['rosa_dei_venti'] = [
    'nome' => 'Rosa dei venti', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Sempre dove si deve',
    'storia' => 'La rosa dei venti e\' il segno del navigatore, e diversi battelli la portarono '
              . 'come emblema di bordo invece di un animale.',
    'disegno' => cerchio(60, 60, 40, 'none', ORO, 3)
        . forma('M60 16l8 36-8 12-8-12z', CHIARO) . forma('M60 104l8-36-8-12-8 12z', ORO)
        . forma('M16 60l36-8 12 8-12 8z', ORO) . forma('M104 60l-36-8-12 8 12 8z', CHIARO)
        . forma('M30 30l30 18-12 12z', GRIGIO) . forma('M90 90L60 72l12-12z', GRIGIO)
        . cerchio(60, 60, 5, ORO),
];

$emblemi['corno_da_caccia'] = [
    'nome' => 'Corno da caccia', 'campo' => CAMPO_VERDE, 'confidence' => 'ricostruita',
    'motto' => 'La muta e\' in mare',
    'storia' => 'Il corno chiama la muta, e la muta e\' il branco: l\'immagine della caccia '
              . 'collettiva era quella con cui l\'arma amava descriversi.',
    'disegno' => linea('M86 40c-16-14-40-14-54 2-12 14-10 34 4 44 12 8 28 6 36-4 6-8 6-18-1-24'
                . '-7-5-16-4-20 3-3 5-1 11 4 13', ORO, 9)
        . forma('M84 26l20 6-16 14z', ORO)
        . cerchio(56, 72, 5, ORO, BORDO, 2.5),
];

$emblemi['faro'] = [
    'nome' => 'Faro', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Si torna sempre a casa',
    'storia' => 'Il faro e\' il segno del ritorno, e nel 1942 tornare era la statistica che '
              . 'contava: tre equipaggi su quattro non lo fecero.',
    'disegno' => forma('M48 96l6-46h12l6 46z', CHIARO)
        . forma('M54 62h12v10H54zM52 80h16v10H52z', ROSSO)
        . forma('M50 50h20l-4-10H54z', GRIGIO)
        . forma('M52 40h16v-6H52z', NERO)
        . cerchio(60, 30, 7, ORO)
        . linea('M40 24l-16-8M80 24l16-8M40 36l-18 2M80 36l18 2', ORO, 3),
];

$emblemi['picche'] = [
    'nome' => 'Asso di picche', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'La carta che chiude la mano',
    'storia' => 'Le carte da gioco erano un soggetto frequente: si giocava molto, a bordo, e '
              . 'la carta scelta diceva qualcosa del comandante.',
    'disegno' => '<rect x="34" y="24" width="52" height="72" rx="6" fill="' . CHIARO
        . '" stroke="#b9b3a0" stroke-width="3"/>'
        . forma('M60 36c14 12 20 20 20 29 0 7-5 12-11 12-4 0-7-2-9-5-2 3-5 5-9 5-6 0-11-5-11-12 0-9 6-17 20-29z', NERO)
        . forma('M56 82h8l3 10H53z', NERO),
];

$emblemi['fiori'] = [
    'nome' => 'Asso di fiori', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Tre teste, una radice',
    'storia' => 'Come l\'asso di picche: la mano di carte e\' il passatempo di bordo, e '
              . 'l\'emblema ne conserva il ricordo.',
    'disegno' => '<rect x="34" y="24" width="52" height="72" rx="6" fill="' . CHIARO
        . '" stroke="#b9b3a0" stroke-width="3"/>'
        . cerchio(60, 44, 10, NERO, NERO, 0) . cerchio(49, 60, 10, NERO, NERO, 0)
        . cerchio(71, 60, 10, NERO, NERO, 0)
        . forma('M56 66h8l4 20H52z', NERO),
];

$emblemi['quadri'] = [
    'nome' => 'Asso di quadri', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Rosso, e netto',
    'storia' => 'Il terzo dei quattro semi: insieme al cuore che il repertorio aveva gia\', '
              . 'completa la mano.',
    'disegno' => '<rect x="34" y="24" width="52" height="72" rx="6" fill="' . CHIARO
        . '" stroke="#b9b3a0" stroke-width="3"/>'
        . forma('M60 32l20 28-20 28-20-28z', ROSSO),
];

$emblemi['dadi'] = [
    'nome' => 'Dadi', 'campo' => CAMPO_ROSSO, 'confidence' => 'ricostruita',
    'motto' => 'Si tira, e si vede',
    'storia' => 'Chi usciva in Atlantico nel 1942 sapeva di star tirando un dado: tre '
              . 'equipaggi su quattro non tornarono.',
    'disegno' => '<rect x="22" y="46" width="42" height="42" rx="6" fill="' . CHIARO . '" stroke="' . BORDO . '" stroke-width="3"/>'
        . '<rect x="58" y="26" width="40" height="40" rx="6" fill="' . NERO . '" stroke="' . BORDO . '" stroke-width="3"/>'
        . cerchio(33, 57, 3.6, NERO, NERO, 0) . cerchio(53, 57, 3.6, NERO, NERO, 0)
        . cerchio(43, 67, 3.6, NERO, NERO, 0)
        . cerchio(33, 77, 3.6, NERO, NERO, 0) . cerchio(53, 77, 3.6, NERO, NERO, 0)
        . cerchio(69, 37, 3.4, CHIARO, CHIARO, 0) . cerchio(87, 37, 3.4, CHIARO, CHIARO, 0)
        . cerchio(69, 55, 3.4, CHIARO, CHIARO, 0) . cerchio(87, 55, 3.4, CHIARO, CHIARO, 0),
];

$emblemi['cappello_a_cilindro'] = [
    'nome' => 'Cilindro', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Con eleganza',
    'storia' => 'Il cilindro e il bastone erano scherzi da equipaggio: si usciva a morire, e '
              . 'lo si faceva vestiti bene.',
    'disegno' => forma('M40 76h40l-4-50H44z', NERO)
        . forma('M24 76h72v10H24z', NERO)
        . forma('M42 44h36l1 10H41z', ROSSO)
        . linea('M88 36l16-14', CHIARO, 4)
        . cerchio(104, 22, 5, CHIARO),
];

$emblemi['tridente'] = [
    'nome' => 'Tridente', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Il mare risponde a chi lo conosce',
    'storia' => 'Il tridente di Nettuno e\' il segno del passaggio dell\'equatore e, piu\' in '
              . 'generale, del mestiere del mare: comparve su piu\' di una torretta.',
    'disegno' => forma('M56 40h8v58h-8z', ORO)
        . forma('M34 22h8v30h-8zM78 22h8v30h-8z', ORO)
        . forma('M30 46h60v10H30z', ORO)
        . forma('M52 14h16l-8 12z', ORO)
        . forma('M46 98h28l-4 10H50z', ORO),
];

$emblemi['drakkar'] = [
    'nome' => 'Nave drago', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Da nord, come una volta',
    'storia' => 'La nave vichinga richiama le basi norvegesi e le rotte artiche, e la sua prua '
              . 'a testa di drago era un soggetto amato dai disegnatori di bordo.',
    'disegno' => forma('M18 66h84c-4 14-18 22-42 22S22 80 18 66z', '#6b4a2a')
        . forma('M18 66c-4-10 0-18 8-22-2 8 0 14 6 16z', '#6b4a2a')
        . forma('M102 66c6-10 4-20-4-26 0 8-4 12-10 14z', '#6b4a2a')
        . forma('M56 20h8v42h-8z', '#6b4a2a')
        . forma('M60 24h28l-8 10 8 10H60z', ROSSO)
        . linea('M30 74h60', '#3f2c19', 3),
];

$emblemi['foglia_di_quercia'] = [
    'nome' => 'Foglia di quercia', 'campo' => CAMPO_VERDE, 'confidence' => 'ricostruita',
    'motto' => 'Legno duro',
    'storia' => 'La quercia e\' l\'albero dell\'iconografia tedesca e la fronda che si aggiungeva '
              . 'alle decorazioni: sulla torretta valeva come augurio di durare.',
    'disegno' => forma('M60 16c6 6 6 12 2 16 8-2 14 2 14 8 6-4 12 0 12 7 0 5-4 8-9 8 6 3 7 10 2 14'
                . '-4 3-9 2-12-1 3 7-1 13-9 15 4 5 3 11-2 14-5-3-6-9-2-14-8-2-12-8-9-15'
                . '-3 3-8 4-12 1-5-4-4-11 2-14-5 0-9-3-9-8 0-7 6-11 12-7 0-6 6-10 14-8-4-4-4-10 2-16z', '#5a8a3e')
        . linea('M60 40v56', '#33521f', 4)
        . linea('M60 54l-12-8M60 66l12-8M60 78l-12-8', '#33521f', 2.6),
];

$emblemi['mulino'] = [
    'nome' => 'Mulino a vento', 'campo' => CAMPO_BLU, 'confidence' => 'ricostruita',
    'motto' => 'Gira comunque',
    'storia' => 'I mulini richiamano le basi e le coste del Mare del Nord: qualche equipaggio '
              . 'se ne prese uno come segno della terra da cui era partito.',
    'disegno' => forma('M44 100l6-46h20l6 46z', CHIARO)
        . forma('M46 56h28l-6-14H52z', GRIGIO)
        . cerchio(60, 40, 5, NERO)
        . forma('M60 40L28 20l6-8 30 20zM60 40l32 20-6 8-30-20zM60 40L40 72l-9-5 21-31zM60 40l20-32 9 5-21 31z', ROSSO),
];

$emblemi['ombrello'] = [
    'nome' => 'Ombrello', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Per il tempo che fa qui',
    'storia' => 'Il Nord Atlantico e\' un posto dove piove sempre: l\'ombrello sulla torretta '
              . 'era lo scherzo che si capiva al primo sguardo.',
    'disegno' => forma('M14 62c0-22 20-38 46-38s46 16 46 38c-8-6-14-6-20 0-8-6-14-6-18 0-6-8-12-8-18 0-6-6-12-6-18 0-6-6-12-6-18 0z', ROSSO)
        . forma('M56 60h8v34h-8z', '#8a7b5c')
        . forma('M64 94c0 8-10 10-14 4', 'none', '#8a7b5c', 5)
        . linea('M60 24v-8', '#8a7b5c', 4),
];

$emblemi['corvo'] = [
    'nome' => 'Corvo', 'campo' => CAMPO_SCURO, 'confidence' => 'ricostruita',
    'motto' => 'Arriva prima della notizia',
    'storia' => 'Il corvo e\' l\'uccello che precede le notizie: su una torretta stava bene a '
              . 'chi faceva il Fuehlungshalter, quello che segue il convoglio e chiama gli altri.',
    'disegno' => forma('M18 78c10-26 34-42 58-38 14 2 24 10 26 22-8-2-14 0-18 6 6 6 6 14 0 20-6-8-16-10-24-4-10 8-28 6-42-6z', NERO)
        . forma('M96 62l14-6-10 14z', ORO)
        . cerchio(88, 54, 3.2, ORO, ORO, 0)
        . linea('M40 66c8 6 18 8 28 6', '#3a3a42', 2.5)
        . forma('M30 80l-12 16 22-6z', NERO),
];

$emblemi['elefante'] = [
    'nome' => 'Elefante', 'campo' => CAMPO_ROSSO, 'confidence' => 'ricostruita',
    'motto' => 'Lento a dimenticare',
    'storia' => 'Gli animali esotici comparivano sui battelli che avevano operato lontano — '
              . 'Caraibi, Africa, Oceano Indiano — e valevano come diario di viaggio.',
    'disegno' => forma('M40 30h40c8 0 14 6 14 14v18c0 10-6 16-14 16H40c-8 0-14-6-14-16V44c0-8 6-14 14-14z', GRIGIO)
        . forma('M26 40C12 36 8 50 12 62c3 9 10 12 16 10z', GRIGIO)
        . forma('M94 40c14-4 18 10 14 22-3 9-10 12-16 10z', GRIGIO)
        . forma('M52 78h16v14c0 10-4 16-8 16s-8-6-8-16z', GRIGIO)
        . forma('M44 76l-4 16 10-6zM76 76l4 16-10-6z', CHIARO)
        . cerchio(46, 52, 3.4, NERO, NERO, 0) . cerchio(74, 52, 3.4, NERO, NERO, 0),
];

$emblemi['gallo'] = [
    'nome' => 'Gallo', 'campo' => CAMPO_VERDE, 'confidence' => 'ricostruita',
    'motto' => 'Sveglia chi dorme',
    'storia' => 'Il gallo arriva dalle basi francesi — era il simbolo del paese in cui i '
              . 'battelli avevano casa dal 1940 — e fu preso piu\' volte con ironia.',
    'disegno' => forma('M34 92c-6-18 2-38 18-46 14-6 28 0 32 12 4 12-2 24-12 30z', ROSSO)
        . cerchio(70, 40, 14, ROSSO)
        . forma('M62 26c2-8 10-10 12-4 4-6 12-2 10 6-6 2-14 4-22-2z', '#d94b3a')
        . forma('M82 44l14 4-14 5z', ORO)
        . forma('M70 56l4 10-10-2z', '#d94b3a')
        . cerchio(74, 38, 3, NERO, NERO, 0)
        . forma('M34 92l-12 12 20-4z', ORO),
];

// --- scrittura ---------------------------------------------------------------

$dir = $projectRoot . '/assets/img/emblemi';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$esistenti = require $projectRoot . '/db/seed/emblemi.php';
$nuovi = 0;

foreach ($emblemi as $k => $e) {
    file_put_contents($dir . '/' . $k . '.svg', svg($e['nome'], $e['campo'], $e['disegno']));
    if (!isset($esistenti[$k])) { $nuovi++; }
    $esistenti[$k] = [
        'nome'       => $e['nome'],
        'file'       => $k . '.svg',
        'motto'      => $e['motto'],
        'storia'     => $e['storia'],
        'confidence' => $e['confidence'],
    ];
}

ksort($esistenti);
$php = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "/**\n * Repertorio degli emblemi di torretta.\n *\n"
    . " * I disegni sono ORIGINALI, fatti per questo gioco: sagome piene e bordi netti,\n"
    . " * come le mascherine che si dipingevano davvero sulla torretta — non copie di\n"
    . " * un emblema storico. Dove un emblema storico esiste ed e' documentato, la\n"
    . " * scheda lo dice: serve a far capire da dove nasce l'idea, non a spacciare il\n"
    . " * disegno per una riproduzione.\n *\n"
    . " * Generato da bin/disegna_emblemi.php, dove stanno i disegni.\n *\n"
    . " * confidence:\n"
    . " *   'alta'        il soggetto e' documentato e attribuito a un battello preciso\n"
    . " *   'ricostruita' il soggetto e' plausibile per l'epoca ma non attribuito\n *\n"
    . " * Una chiave puo' stare su piu' battelli insieme: molti segni di torretta erano\n"
    . " * di flottiglia e non di battello. Il vincolo di unicita' c'era e se n'e'\n"
    . " * andato con la migrazione 0026, perche' era storicamente sbagliato.\n */\n\n"
    . 'return ' . var_export($esistenti, true) . ";\n";
file_put_contents($projectRoot . '/db/seed/emblemi.php', $php);

printf("%d emblemi disegnati (%d nuovi), %d in repertorio.\n", count($emblemi), $nuovi, count($esistenti));
