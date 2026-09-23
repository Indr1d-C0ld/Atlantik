<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\World;

/**
 * Il volto del comandante.
 *
 * Due strade, e sono diverse sul serio.
 *
 *   REPERTORIO. Cinquecentotto fotografie di comandanti di U-Boot veri, dalla
 *   raccolta messa insieme a mano dal proprietario del gioco. La provenienza
 *   del singolo file non e' verificata, e sotto ogni ritratto il gioco lo dice.
 *   (Fino al 18/09/2026 erano trentatre' volti da Wikimedia Commons con la loro
 *   licenza, scaricati da bin/scarica_ritratti.php, poi tolto: vedi
 *   docs/FONTI.md per il come, il perche' non da uboat.net, e il perche' della
 *   rimozione.)
 *   Chi ne sceglie una puo' anche prendersi il nome del comandante che c'e'
 *   sopra: e' un omaggio, non un travestimento, e il gioco lo dice apertamente
 *   sulla pagina del profilo.
 *
 *   CARICATA. Una fotografia portata da casa. Non viene mai servita com'e':
 *   viene riaperta, ritagliata quadrata e riscritta in WebP, esattamente come
 *   gli emblemi di torretta. Quello che finisce sul disco e' un'immagine
 *   costruita qui.
 *
 * In nessuno dei due casi due comandanti possono avere lo stesso volto. Il
 * vincolo sta nel database — UNIQUE su ritratto_key e su ritratto_hash — e non
 * in un controllo a mano che si puo' dimenticare: fra il controllo e la
 * scrittura passa il tempo di un'altra richiesta, e in quel tempo il volto
 * potrebbe essere stato preso.
 */
final class Ritratto
{
    private const URL = 'img/ritratti';
    private const URL_CARICATI = 'img/ritratti/caricati';
    private const DISCO_CARICATI = 'assets/img/ritratti/caricati';

    private const FORMATI = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_WEBP => 'webp',
    ];
    /**
     * Il lato delle fotografie caricate.
     *
     * E' lo stesso della galleria storica, e non e' un caso: una fotografia
     * portata da casa deve stare nella griglia accanto alle altre senza
     * saltare all'occhio per misura. I file originali della raccolta erano in
     * media 224x308 e sono stati normalizzati a questo quadrato.
     */
    private const LATO = 320;
    private const PESO_MAX = 5242880;          // 5 MB in ingresso

    /** Pixel massimi dell'immagine in ingresso: e' l'area che GD deve tenere in memoria. */
    private const PIXEL_MAX = 16000000;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $repertorio = null;

    /** @return array<string,array<string,mixed>> */
    public static function repertorio(): array
    {
        if (self::$repertorio !== null) {
            return self::$repertorio;
        }
        $f = dirname(__DIR__, 2) . '/db/seed/ritratti.php';
        return self::$repertorio = is_file($f) ? (array) require $f : [];
    }

    /**
     * Il repertorio con, accanto a ogni volto, chi l'ha gia' preso.
     *
     * @return list<array<string,mixed>>
     */
    public static function catalogo(?int $commanderId = null): array
    {
        // Solo chi e' IN SERVIZIO tiene impegnato un volto. Un comandante caduto,
        // disperso, prigioniero o congedato conserva il suo ritratto nel
        // fascicolo e nell'albo d'oro, ma la prenotazione decade: nel 1942 i
        // comandanti morivano quasi tutti, e un repertorio che si consuma per
        // sempre a ogni perdita in pochi mesi non avrebbe piu' niente dentro.
        $presi = [];
        foreach (Database::all(
            "SELECT id, nome, ritratto_key FROM commanders
              WHERE ritratto_key IS NOT NULL AND stato = 'attivo'"
        ) as $r) {
            $presi[(string) $r['ritratto_key']] = ['id' => (int) $r['id'], 'nome' => (string) $r['nome']];
        }

        $out = [];
        foreach (self::repertorio() as $k => $v) {
            $chi = $presi[$k] ?? null;
            $out[] = $v + [
                'key'     => $k,
                'url'     => self::URL . '/' . basename((string) $v['file']),
                'preso'   => $chi !== null,
                'preso_da'=> $chi['nome'] ?? null,
                'e_mio'   => $chi !== null && $commanderId !== null && $chi['id'] === $commanderId,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp(
            (string) ($a['cognome'] ?? $a['nome']), (string) ($b['cognome'] ?? $b['nome'])
        ) ?: strcmp((string) $a['nome'], (string) $b['nome']));
        return $out;
    }

    /** Quanti volti per pagina: abbastanza da scegliere, pochi da caricare. */
    public const PER_PAGINA = 40;

    /**
     * Il catalogo ridotto all'osso, per il selettore.
     *
     * Cinquecento ritratti in una pagina sola sono cinquecento tag <img> nel
     * documento, e si sentono. Qui esce solo quello che serve a disegnare una
     * scheda — chiave, nome, indirizzo dell'immagine, se e' libero — cosi' che
     * la pagina possa mandarne giu' l'elenco intero in JSON (poche decine di
     * kilobyte) e disegnarne quaranta per volta.
     *
     * @return list<array<string,mixed>>
     */
    public static function elenco(?int $commanderId = null): array
    {
        $out = [];
        foreach (self::catalogo($commanderId) as $r) {
            $out[] = [
                'k' => $r['key'],
                'n' => $r['nome'],
                'u' => $r['url'],
                'a' => ($r['nato'] ?? null) !== null || ($r['morto'] ?? null) !== null
                    ? ((string) ($r['nato'] ?? '?')) . '–' . ((string) ($r['morto'] ?? '?'))
                    : '',
                'p' => $r['preso'] && !$r['e_mio'] ? (string) $r['preso_da'] : null,
                'm' => (bool) $r['e_mio'],
            ];
        }
        return $out;
    }

    /**
     * Il ritratto di un comandante, pronto da mostrare.
     *
     * @return array{url:string,nome:string,origine:string,licenza?:string,attribuzione?:string,fonte?:string}|null
     */
    public static function di(array $cmd): ?array
    {
        if (($cmd['ritratto_key'] ?? null) !== null) {
            $v = self::repertorio()[(string) $cmd['ritratto_key']] ?? null;
            if ($v !== null) {
                return [
                    'url'           => self::URL . '/' . basename((string) $v['file']),
                    'nome'          => (string) $v['nome'],
                    'origine'       => 'repertorio',
                    'anno'          => $v['anno_foto'] ?? null,
                    'licenza'       => (string) ($v['licenza'] ?? ''),
                    'attribuzione'  => (string) ($v['attribuzione'] ?? ''),
                    'fonte'         => (string) ($v['fonte'] ?? 'raccolta'),
                    'nome_ricavato' => (bool) ($v['nome_ricavato'] ?? false),
                ];
            }
        }
        if (($cmd['ritratto_file'] ?? null) !== null) {
            return [
                'url'     => self::URL_CARICATI . '/' . (string) $cmd['ritratto_file'],
                'nome'    => 'Fotografia del comandante',
                'origine' => 'caricato',
            ];
        }
        return null;
    }

    /** La dichiarazione da mostrare sotto un ritratto storico. */
    /**
     * Quello che si scrive sotto un ritratto.
     *
     * Dove la licenza si conosce, si cita: e' una condizione delle licenze CC e
     * una regola di casa. Dove NON si conosce — la raccolta messa insieme a
     * mano — si dice che non si conosce, invece di inventare un'attribuzione.
     * "Non lo so" e' una informazione; una licenza a caso sarebbe una bugia.
     */
    public static function dichiarazione(array $r): string
    {
        if (($r['origine'] ?? '') !== 'repertorio') {
            return 'Fotografia caricata dal giocatore.';
        }
        $pezzi = ['Fotografia storica di ' . (string) $r['nome']];
        if (($r['anno'] ?? null) !== null) {
            $pezzi[] = (string) $r['anno'];
        }
        if (($r['attribuzione'] ?? '') !== '') {
            $pezzi[] = (string) $r['attribuzione'];
        } elseif (($r['licenza'] ?? '') !== '') {
            $pezzi[] = (string) $r['licenza'];
        } else {
            $pezzi[] = 'raccolta storica, provenienza del singolo file non verificata';
        }
        if (($r['nome_ricavato'] ?? false) === true) {
            $pezzi[] = 'nome ricavato dal nome del file';
        }
        return implode(' — ', $pezzi) . '.';
    }

    /**
     * Prende un volto dal repertorio.
     *
     * @return array{ok:bool, error?:string, nome_storico?:string}
     */
    public static function scegli(int $commanderId, string $chiave, bool $prendiIlNome = false): array
    {
        $v = self::repertorio()[$chiave] ?? null;
        if ($v === null) {
            return ['ok' => false, 'error' => 'Questo ritratto non esiste.'];
        }

        $vecchio = Database::first('SELECT ritratto_file FROM commanders WHERE id = ?', [$commanderId]);

        try {
            Database::run(
                'UPDATE commanders SET ritratto_key = ?, ritratto_file = NULL, ritratto_hash = NULL,
                        profilo_gts = ? WHERE id = ?',
                [$chiave, World::now(), $commanderId]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Quel volto e\' gia\' su un altro fascicolo, '
                    . 'e quel comandante e\' in servizio. Scegline un altro: tornera\' libero '
                    . 'quando lui non ci sara\' piu\'.'];
            }
            throw $e;
        }

        self::rimuoviFile($vecchio['ritratto_file'] ?? null);

        if ($prendiIlNome) {
            $res = self::prendiNomeStorico($commanderId, (string) $v['nome']);
            if (!$res['ok']) {
                return ['ok' => true, 'error' => $res['error'] ?? null, 'nome_storico' => null];
            }
            return ['ok' => true, 'nome_storico' => (string) $v['nome']];
        }

        Database::run('UPDATE commanders SET nome_storico = 0 WHERE id = ?', [$commanderId]);
        return ['ok' => true];
    }

    /**
     * Prende anche il nome del comandante ritratto.
     *
     * Vale lo stesso vincolo di sempre: un nome, un comandante. Se quel nome e'
     * gia' in servizio — magari perche' un altro giocatore ha scelto lo stesso
     * omaggio prima di cambiare ritratto — il volto resta e il nome no.
     *
     * @return array{ok:bool, error?:string}
     */
    private static function prendiNomeStorico(int $commanderId, string $nome): array
    {
        try {
            Database::run(
                'UPDATE commanders SET nome = ?, nome_storico = 1 WHERE id = ?',
                [mb_substr($nome, 0, 64), $commanderId]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Il ritratto e\' tuo, ma quel nome e\' portato da un '
                    . 'comandante in servizio: resta quello che avevi.'];
            }
            throw $e;
        }
        return ['ok' => true];
    }

    /** Toglie il ritratto e libera il volto per gli altri. */
    public static function togli(int $commanderId): array
    {
        $vecchio = Database::first('SELECT ritratto_file FROM commanders WHERE id = ?', [$commanderId]);
        Database::run(
            'UPDATE commanders SET ritratto_key = NULL, ritratto_file = NULL, ritratto_hash = NULL,
                    nome_storico = 0, profilo_gts = ? WHERE id = ?',
            [World::now(), $commanderId]
        );
        self::rimuoviFile($vecchio['ritratto_file'] ?? null);
        return ['ok' => true];
    }

    /**
     * Carica una fotografia portata da casa.
     *
     * Il file che arriva NON viene mai servito com'e': viene riaperto, ritagliato
     * e riscritto in WebP. Cosi' quello che finisce sul disco e' un'immagine
     * costruita qui — niente metadati, niente code, niente di quello che poteva
     * esserci dentro a un file arrivato da fuori. Gli SVG non si accettano
     * proprio: un SVG e' un documento che puo' contenere codice.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool, error?:string}
     */
    public static function carica(int $commanderId, array $file, bool $invecchia = false): array
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errore === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Nessun file scelto.'];
        }
        if ($errore !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Caricamento non riuscito (codice ' . $errore . ').'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'File non valido.'];
        }
        if ((int) ($file['size'] ?? 0) > self::PESO_MAX) {
            return ['ok' => false, 'error' => 'Il file supera i 5 MB.'];
        }

        // Il tipo si decide guardando i byte, non l'estensione ne' quello che
        // dichiara il browser: tutti e due se li sceglie chi carica.
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::FORMATI[$info[2]])) {
            return ['ok' => false, 'error' => 'Servono un PNG, un JPEG o un WebP. '
                . 'Gli SVG non si accettano: possono contenere codice.'];
        }
        [$larghezza, $altezza] = $info;
        if ($larghezza < 64 || $altezza < 64) {
            return ['ok' => false, 'error' => 'Immagine troppo piccola: almeno 64 pixel per lato.'];
        }
        if ($larghezza > 6000 || $altezza > 6000) {
            return ['ok' => false, 'error' => 'Immagine troppo grande: non oltre 6000 pixel per lato.'];
        }
        // Il lato non basta: quello che GD deve tenere in memoria e' l'AREA,
        // quattro byte per pixel, e la conta si fa prima di aprire l'immagine
        // perche' dopo sarebbe tardi. Un PNG di centodiciassette kilobyte da
        // seimila per seimila pixel sono centoquarantaquattro megabyte di
        // bitmap: misurato il 19/09/2026 su questa macchina, un solo
        // caricamento portava un processo Apache da 80 a 235 megabyte, per un
        // secondo, e qualche decina di richieste insieme avrebbero messo in
        // ginocchio tutto quello che gira sul server, non solo il gioco.
        //
        // Sedici milioni di pixel accettano comodamente la fotografia di un
        // telefono (4032 x 3024 fanno dodici milioni e due) e tengono GD sotto
        // i sessantaquattro megabyte. Di piu' non servirebbe comunque: quello
        // che si salva e' un quadrato di poche centinaia di pixel.
        if ($larghezza * $altezza > self::PIXEL_MAX) {
            return ['ok' => false, 'error' => sprintf(
                'Immagine troppo grande: %s milioni di pixel, e il limite e\' %d. '
                . 'Ridimensionala prima di caricarla.',
                number_format($larghezza * $altezza / 1000000, 1, ',', '.'),
                (int) (self::PIXEL_MAX / 1000000)
            )];
        }

        $sorgente = match (self::FORMATI[$info[2]]) {
            'png'  => @imagecreatefrompng($tmp),
            'jpeg' => @imagecreatefromjpeg($tmp),
            'webp' => @imagecreatefromwebp($tmp),
        };
        if (!$sorgente instanceof \GdImage) {
            return ['ok' => false, 'error' => 'Non riesco ad aprire l\'immagine.'];
        }

        // Quadrata, centrata sul lato corto ma alzata: in un ritratto la testa
        // sta in alto, e tagliare dal centro geometrico decapita.
        $lato = min($larghezza, $altezza);
        $dx = (int) (($larghezza - $lato) / 2);
        $dy = (int) max(0, ($altezza - $lato) * 0.18);

        $out = imagecreatetruecolor(self::LATO, self::LATO);
        imagecopyresampled($out, $sorgente, 0, 0, $dx, $dy, self::LATO, self::LATO, $lato, $lato);
        imagedestroy($sorgente);

        if ($invecchia) {
            self::invecchia($out);
        }

        $temporaneo = tempnam(sys_get_temp_dir(), 'ritr');
        imagewebp($out, $temporaneo, 88);
        imagedestroy($out);

        $hash = hash_file('sha256', $temporaneo);
        $nome = $hash . '.webp';
        $dir  = dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $vecchio = Database::first('SELECT ritratto_file FROM commanders WHERE id = ?', [$commanderId]);

        try {
            Database::run(
                'UPDATE commanders SET ritratto_key = NULL, ritratto_file = ?, ritratto_hash = ?,
                        nome_storico = 0, profilo_gts = ? WHERE id = ?',
                [$nome, $hash, World::now(), $commanderId]
            );
        } catch (\PDOException $e) {
            @unlink($temporaneo);
            throw $e;
        }

        // Si sposta il file solo dopo che il vincolo ha detto di si'.
        @rename($temporaneo, $dir . '/' . $nome);
        @chmod($dir . '/' . $nome, 0644);
        self::rimuoviFile($vecchio['ritratto_file'] ?? null);

        return ['ok' => true];
    }

    /**
     * Fa sembrare vecchia una fotografia nuova.
     *
     * Serve a una cosa sola: una foto scattata col telefono, messa accanto a
     * cinquecento scatti del 1942, stona. Non per la posa — per la resa. Le
     * fotografie di allora sono monocromatiche con una dominante calda, hanno
     * poco contrasto nelle ombre, una grana visibile, gli angoli piu' scuri del
     * centro e un'ottica che non era mai perfettamente incisa.
     *
     * Qui si rifanno quelle cinque cose, in quest'ordine — l'ordine conta:
     * prima si toglie il colore, poi si schiaccia il contrasto, poi si vira, e
     * solo alla fine si aggiunge la grana, che altrimenti verrebbe virata
     * anche lei e sembrerebbe sporco colorato invece che argento.
     *
     * E' FACOLTATIVO. Chi vuole la sua fotografia com'e', la tiene com'e'.
     */
    /**
     * I numeri della galleria storica, misurati sui 508 volti veri.
     *
     * Non sono scelti a occhio: sono il risultato di una misura, e sono il
     * bersaglio verso cui il filtro porta una fotografia moderna.
     *
     *   LUMINANZA 0,51 — sono stampe chiare, quasi slavate.
     *   DEVIAZIONE STANDARD 0,215 — hanno poco contrasto; una fotografia
     *     digitale ben esposta arriva intorno a 0,26.
     *   SCARTO ROSSO-BLU: mediana ZERO. Centosette hanno una dominante calda e
     *     centotredici una fredda, cioe' le dominanti sono rumore di scansione
     *     che va in tutte e due le direzioni. Il seppia della cartolina d'epoca
     *     e' un luogo comune: una stampa alla gelatina d'argento e' grigia.
     */
    private const LUMINANZA_GALLERIA = 0.51;
    private const CONTRASTO_GALLERIA = 0.215;

    /** @return array{0:float,1:float} luminanza media e deviazione standard */
    private static function misura(\GdImage $im): array
    {
        $l = imagesx($im);
        $h = imagesy($im);
        $passo = max(1, (int) floor(min($l, $h) / 56));
        $v = [];
        for ($y = 0; $y < $h; $y += $passo) {
            for ($x = 0; $x < $l; $x += $passo) {
                // Dopo IMG_FILTER_GRAYSCALE i tre canali sono uguali: ne basta uno.
                $v[] = ((imagecolorat($im, $x, $y) >> 16) & 0xFF) / 255.0;
            }
        }
        $n = count($v);
        if ($n === 0) {
            return [0.5, 0.2];
        }
        $media = array_sum($v) / $n;
        $somma = 0.0;
        foreach ($v as $x) {
            $somma += ($x - $media) ** 2;
        }
        return [$media, sqrt($somma / $n)];
    }

    /**
     * Fa sembrare vecchia una fotografia nuova.
     *
     * Serve a una cosa sola: una foto scattata col telefono, messa accanto a
     * cinquecento scatti del 1942, stona. Non per la posa — per la resa.
     *
     * Le cose si fanno in quest'ordine, e l'ordine conta: prima si toglie il
     * colore, poi si lavora sul tono, poi si sfoca, poi si sporca, e solo alla
     * fine si sistema l'esposizione e si mette il soffio di calore. La grana va
     * per ultima perche' se venisse prima del tono la moltiplicherebbe il
     * contrasto, e se venisse prima della virata sembrerebbe sporco colorato
     * invece che argento.
     *
     * DUE PASSAGGI SONO MISURATI, non fissati a mano: il contrasto e
     * l'esposizione si portano verso i numeri della galleria vera, qualunque
     * cosa arrivi in ingresso. Una fotografia gia' piatta non viene appiattita
     * ancora, una gia' chiara non viene schiarita.
     *
     * E' FACOLTATIVO. Chi vuole la sua fotografia com'e', la tiene com'e'.
     */
    public static function invecchia(\GdImage $im): void
    {
        $l = imagesx($im);
        $h = imagesy($im);

        // 1. Via il colore, prima di tutto il resto.
        imagefilter($im, IMG_FILTER_GRAYSCALE);

        // 2. Il contrasto sceso a quello della galleria.
        //
        //    ATTENZIONE AL SEGNO: in GD l'argomento di IMG_FILTER_CONTRAST e'
        //    ROVESCIATO — i valori POSITIVI abbassano il contrasto, i negativi
        //    lo alzano. Non e' scritto da nessuna parte in modo evidente, e
        //    passando dei negativi si ottiene l'esatto contrario di quello che
        //    si voleva: me ne sono accorto solo misurando il risultato.
        //
        //    Il valore giusto non e' una costante: dipende da quanto e'
        //    contrastata la fotografia che arriva. Si cerca per bisezione, su
        //    una copia, finche' la deviazione standard non si avvicina al
        //    bersaglio. Sei passate su un'immagine di trecento pixel costano
        //    niente e valgono molto piu' di un numero indovinato.
        [, $dev] = self::misura($im);
        if ($dev > self::CONTRASTO_GALLERIA) {
            $basso = 0;
            $alto = 48;
            for ($i = 0; $i < 6; $i++) {
                $mezzo = (int) round(($basso + $alto) / 2);
                $copia = imagecreatetruecolor($l, $h);
                imagecopy($copia, $im, 0, 0, 0, 0, $l, $h);
                imagefilter($copia, IMG_FILTER_CONTRAST, $mezzo);
                [, $d2] = self::misura($copia);
                imagedestroy($copia);
                if ($d2 > self::CONTRASTO_GALLERIA) {
                    $basso = $mezzo;
                } else {
                    $alto = $mezzo;
                }
            }
            imagefilter($im, IMG_FILTER_CONTRAST, (int) round(($basso + $alto) / 2));
        }

        // 3. Le ottiche del tempo non erano incise come quelle di oggi.
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);

        // 4. Vignettatura e grana, nello stesso giro di pixel: sono l'una il
        //    contrario dell'altra — la vignetta toglie luce ai bordi, la grana
        //    ne aggiunge e toglie a caso ovunque — e farle insieme costa meta'.
        $cx = $l / 2;
        $cy = $h / 2;
        $raggio = sqrt($cx * $cx + $cy * $cy);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $l; $x++) {
                $c = imagecolorat($im, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;

                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $raggio;
                $vignetta = 1.0 - 0.22 * ($d ** 2.2);
                $grana = random_int(-6, 6);

                $r = max(0, min(255, (int) round($r * $vignetta) + $grana));
                $g = max(0, min(255, (int) round($g * $vignetta) + $grana));
                $b = max(0, min(255, (int) round($b * $vignetta) + $grana));
                imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        // 5. L'esposizione, misurata DOPO la vignettatura perche' e' lei a
        //    decidere quanto scura viene fuori davvero.
        [$media] = self::misura($im);
        $spinta = (int) round((self::LUMINANZA_GALLERIA - $media) * 255 * 0.85);
        imagefilter($im, IMG_FILTER_BRIGHTNESS, max(-45, min(75, $spinta)));

        // 6. Un soffio di calore, per ultimo. Dentro la dispersione delle
        //    fotografie vere — la mediana e' zero — e solo per non far sembrare
        //    l'immagine uscita da uno scanner di oggi.
        imagefilter($im, IMG_FILTER_COLORIZE, 2, 1, -1);
    }

    /**
     * Toglie dal disco i ritratti caricati che non appartengono piu' a nessuno.
     * Gira nel battito, insieme alle altre potature.
     */
    public static function potaOrfani(): int
    {
        $dir = dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI;
        if (!is_dir($dir)) {
            return 0;
        }
        $vivi = array_flip(array_column(
            Database::all('SELECT ritratto_file FROM commanders WHERE ritratto_file IS NOT NULL'),
            'ritratto_file'
        ));
        $tolti = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if (!preg_match('/^[0-9a-f]{64}\.webp$/', $f) || isset($vivi[$f])) {
                continue;
            }
            // Un file appena scritto potrebbe essere di un caricamento in corso.
            if (time() - (int) @filemtime($dir . '/' . $f) < 300) {
                continue;
            }
            if (@unlink($dir . '/' . $f)) {
                $tolti++;
            }
        }
        return $tolti;
    }

    private static function rimuoviFile(?string $nome): void
    {
        if ($nome === null || !preg_match('/^[0-9a-f]{64}\.webp$/', $nome)) {
            return;
        }
        $ancora = Database::first('SELECT id FROM commanders WHERE ritratto_file = ?', [$nome]);
        if ($ancora !== null) {
            return;
        }
        @unlink(dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI . '/' . $nome);
    }
}
