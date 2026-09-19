<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\World;

/**
 * L'emblema di torretta.
 *
 * Due strade: sceglierne uno dal repertorio, oppure caricare il proprio.
 *
 * **Lo stesso emblema puo' stare su piu' torrette**, ed e' storia: molti segni
 * erano di FLOTTIGLIA e non di battello. Il toro infuriato di Prien, da U-47,
 * divento' quello di tutta la 7. U-Flottille e lo portavano decine di battelli
 * insieme; il pesce sega di U-96 fece lo stesso con la 9. Un emblema personale
 * seguiva invece il comandante da un battello all'altro — il diavolo rosso di
 * Topp passa da U-57 a U-552 — quindi lo stesso segno sta su torrette diverse
 * anche in momenti diversi.
 *
 * All'inizio qui c'era un vincolo di unicita'. Se n'e' andato con la
 * migrazione 0026, perche' era storicamente sbagliato. Al suo posto il
 * repertorio dice **chi altro lo porta**: una informazione, non un divieto, e
 * anche il modo in cui si capisce a quale flottiglia ci si sta accodando.
 * Quello che resta unico e' il NUMERO del battello, e quello si', nel
 * database.
 */
final class Emblema
{
    // Due prefissi diversi per la stessa cartella, e non e' una svista:
    // asset() vuole il percorso RELATIVO a assets/, il filesystem lo vuole
    // intero. Tenerli separati evita l'errore in cui ci si casca sempre —
    // /assets/assets/img/... e l'immagine rotta.
    private const URL = 'img/emblemi';
    private const URL_CARICATI = 'img/emblemi/caricati';
    private const DISCO_CARICATI = 'assets/img/emblemi/caricati';

    /** Formati accettati in caricamento. Niente SVG: un SVG puo' contenere codice. */
    private const FORMATI = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_WEBP => 'webp',
    ];

    private const LATO = 256;               // il disegno viene rifatto a questa misura
    private const PESO_MAX = 3145728;       // 3 MB in ingresso

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $repertorio = null;

    /** @return array<string,array<string,mixed>> */
    public static function repertorio(): array
    {
        if (self::$repertorio === null) {
            $f = dirname(__DIR__, 2) . '/db/seed/emblemi.php';
            /** @var array<string,array<string,mixed>> $r */
            $r = is_file($f) ? require $f : [];
            self::$repertorio = $r;
        }
        return self::$repertorio;
    }

    /**
     * Il repertorio, con accanto chi altro lo porta.
     *
     * UN EMBLEMA NON E' ESCLUSIVO, e sarebbe sbagliato renderlo tale: molti
     * segni di torretta erano di FLOTTIGLIA, non di battello. Il toro che
     * sbuffa nasce con U-47 di Prien dopo Scapa Flow e diventa poi il segno di
     * tutta la 7. U-Flottille — lo portavano decine di battelli insieme. Il
     * pesce sega ridente di U-96 diventa quello della 9. E un comandante che
     * cambiava battello si portava dietro il proprio: il diavolo rosso di Topp
     * passo' da U-57 a U-552.
     *
     * Qui quindi non si blocca niente: si dice soltanto chi altro lo porta, che
     * e' una informazione e non un divieto.
     *
     * @return list<array<string,mixed>>
     */
    public static function catalogo(?int $boatId = null): array
    {
        $compagni = [];
        foreach (Database::all(
            "SELECT emblema_key, uboat_number FROM boats
              WHERE emblema_key IS NOT NULL AND state <> 'perduto'
              ORDER BY uboat_number"
        ) as $r) {
            $compagni[(string) $r['emblema_key']][] = (string) $r['uboat_number'];
        }

        $mio = null;
        $mioNumero = '';
        if ($boatId !== null) {
            $b = Database::first('SELECT emblema_key, uboat_number FROM boats WHERE id = ?', [$boatId]);
            $mio = $b === null ? null : $b['emblema_key'];
            $mioNumero = (string) ($b['uboat_number'] ?? '');
        }

        $out = [];
        foreach (self::repertorio() as $chiave => $v) {
            $altri = array_values(array_diff($compagni[$chiave] ?? [], [$mioNumero]));
            $out[] = $v + [
                'chiave'     => $chiave,
                'url'        => self::URL . '/' . $v['file'],
                'lo_portano' => $altri,
                'e_mio'      => $mio !== null && (string) $mio === $chiave,
            ];
        }
        return $out;
    }

    /**
     * L'emblema di un battello, se ne ha uno.
     *
     * Il motto viene con l'emblema: la lente che lo mostra in grande lo scrive
     * sotto al nome. Quello caricato da casa non ne ha uno, e resta vuoto.
     *
     * @return array{url:string,nome:string,motto:string,origine:string}|null
     */
    public static function di(array $boat): ?array
    {
        if ($boat['emblema_key'] !== null) {
            $v = self::repertorio()[(string) $boat['emblema_key']] ?? null;
            if ($v !== null) {
                return [
                    'url'     => self::URL . '/' . $v['file'],
                    'nome'    => (string) $v['nome'],
                    'motto'   => (string) ($v['motto'] ?? ''),
                    'origine' => 'repertorio',
                ];
            }
        }
        if ($boat['emblema_file'] !== null) {
            return [
                'url'     => self::URL_CARICATI . '/' . (string) $boat['emblema_file'],
                'nome'    => 'Emblema del comandante',
                'motto'   => 'Portato da casa',
                'origine' => 'caricato',
            ];
        }
        return null;
    }

    /** @return array{ok:bool, error?:string} */
    public static function scegli(int $boatId, string $chiave): array
    {
        if (!isset(self::repertorio()[$chiave])) {
            return ['ok' => false, 'error' => 'Questo emblema non esiste.'];
        }

        $vecchio = Database::first('SELECT emblema_file FROM boats WHERE id = ?', [$boatId]);

        try {
            Database::run(
                'UPDATE boats SET emblema_key = ?, emblema_file = NULL, emblema_hash = NULL,
                        emblema_gts = ?, version = version + 1
                 WHERE id = ?',
                [$chiave, World::now(), $boatId]
            );
        } catch (\PDOException $e) {
            // Niente piu' vincolo di unicita' sull'emblema: se arriva un errore
            // di integrita' e' un guasto vero, non una collisione prevista.
            throw $e;
        }

        self::rimuoviFile($vecchio['emblema_file'] ?? null);
        return ['ok' => true];
    }

    /**
     * Ripulisce la torretta: il battello resta senza segno.
     *
     * @return array{ok:bool}
     */
    public static function togli(int $boatId): array
    {
        $vecchio = Database::first('SELECT emblema_file FROM boats WHERE id = ?', [$boatId]);
        Database::run(
            'UPDATE boats SET emblema_key = NULL, emblema_file = NULL, emblema_hash = NULL,
                    emblema_gts = NULL, version = version + 1 WHERE id = ?',
            [$boatId]
        );
        self::rimuoviFile($vecchio['emblema_file'] ?? null);
        return ['ok' => true];
    }

    /**
     * Carica un emblema dal comandante.
     *
     * Il file che arriva NON viene mai servito com'e': viene riaperto, ridotto e
     * riscritto in WebP. Cosi' quello che finisce sul disco e' un'immagine
     * costruita da noi — niente metadati, niente code, niente di quello che
     * poteva esserci dentro a un file arrivato da fuori. Gli SVG non si
     * accettano proprio: un SVG e' un documento che puo' contenere codice.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool, error?:string}
     */
    public static function carica(int $boatId, array $file): array
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
            return ['ok' => false, 'error' => 'Il file supera i 3 MB.'];
        }

        // Il tipo si decide guardando i byte, non l'estensione ne' quello che
        // dichiara il browser: tutti e due se li sceglie chi carica.
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::FORMATI[$info[2]])) {
            return ['ok' => false, 'error' => 'Servono un PNG, un JPEG o un WebP. '
                . 'Gli SVG non si accettano: possono contenere codice.'];
        }
        [$larghezza, $altezza] = $info;
        if ($larghezza < 32 || $altezza < 32) {
            return ['ok' => false, 'error' => 'Immagine troppo piccola: almeno 32 pixel per lato.'];
        }
        if ($larghezza > 4000 || $altezza > 4000) {
            return ['ok' => false, 'error' => 'Immagine troppo grande: non oltre 4000 pixel per lato.'];
        }

        $sorgente = match (self::FORMATI[$info[2]]) {
            'png'  => @imagecreatefrompng($tmp),
            'jpeg' => @imagecreatefromjpeg($tmp),
            'webp' => @imagecreatefromwebp($tmp),
        };
        if (!$sorgente instanceof \GdImage) {
            return ['ok' => false, 'error' => 'Non riesco ad aprire l\'immagine.'];
        }

        // Quadrata, centrata sul lato corto: un emblema di torretta e' tondo.
        $lato = min($larghezza, $altezza);
        $dx = (int) (($larghezza - $lato) / 2);
        $dy = (int) (($altezza - $lato) / 2);

        $out = imagecreatetruecolor(self::LATO, self::LATO);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $sorgente, 0, 0, $dx, $dy, self::LATO, self::LATO, $lato, $lato);
        imagedestroy($sorgente);

        $temporaneo = tempnam(sys_get_temp_dir(), 'embl');
        imagewebp($out, $temporaneo, 86);
        imagedestroy($out);

        $hash = hash_file('sha256', $temporaneo);
        $nome = $hash . '.webp';
        $dir  = dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $vecchio = Database::first('SELECT emblema_file FROM boats WHERE id = ?', [$boatId]);

        try {
            Database::run(
                'UPDATE boats SET emblema_key = NULL, emblema_file = ?, emblema_hash = ?,
                        emblema_gts = ?, version = version + 1
                 WHERE id = ?',
                [$nome, $hash, World::now(), $boatId]
            );
        } catch (\PDOException $e) {
            @unlink($temporaneo);
            throw $e;
        }

        // Si sposta il file solo dopo che il vincolo ha detto di si'.
        @rename($temporaneo, $dir . '/' . $nome);
        @chmod($dir . '/' . $nome, 0644);
        self::rimuoviFile($vecchio['emblema_file'] ?? null);

        return ['ok' => true];
    }

    /**
     * Toglie dal disco gli emblemi caricati che non appartengono piu' a nessuno.
     *
     * Serve perche' un battello puo' sparire senza passare da qui — un account
     * cancellato si porta dietro il suo battello per vincolo di chiave esterna,
     * e il file resta orfano sul disco. Gira nel battito, insieme alle altre
     * potature.
     *
     * @return int quanti file rimossi
     */
    public static function potaOrfani(): int
    {
        $dir = dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI;
        if (!is_dir($dir)) {
            return 0;
        }
        $vivi = array_flip(array_column(
            Database::all('SELECT emblema_file FROM boats WHERE emblema_file IS NOT NULL'),
            'emblema_file'
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

    /** Cancella un file caricato, se non lo usa piu' nessuno. */
    private static function rimuoviFile(?string $nome): void
    {
        if ($nome === null || $nome === '' || !preg_match('/^[0-9a-f]{64}\.webp$/', $nome)) {
            return;
        }
        $ancora = Database::first('SELECT id FROM boats WHERE emblema_file = ?', [$nome]);
        if ($ancora !== null) {
            return;
        }
        @unlink(dirname(__DIR__, 2) . '/' . self::DISCO_CARICATI . '/' . $nome);
    }
}
