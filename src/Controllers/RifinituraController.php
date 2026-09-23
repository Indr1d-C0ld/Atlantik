<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Comandante;
use App\Game\Trofei;
use App\Sim\Traffic;
use App\Sim\World;

/** Trofei, esportazione del giornale di guerra, amministrazione. */
final class RifinituraController
{
    public function trofei(Request $request): Response
    {
        $user = Auth::user();
        $ottenuti = Trofei::ottenuti((int) $user['id']);

        $perCategoria = [];
        foreach (Trofei::catalogo() as $t) {
            $k = (string) $t['akey'];
            $t['ottenuto'] = $ottenuti[$k] ?? null;
            if ((int) $t['nascosto'] === 1 && $t['ottenuto'] === null) {
                continue;   // i trofei nascosti si scoprono ottenendoli
            }
            $perCategoria[(string) $t['categoria']][] = $t;
        }

        return Response::html(view('game/trofei', [
            'title'    => 'Trofei',
            'gruppi'   => $perCategoria,
            'ottenuti' => count($ottenuti),
            'totale'   => count(Trofei::catalogo()),
            'clock'    => World::clock(),
        ]));
    }

    /**
     * Esporta il giornale di guerra come file di testo: e' un documento, e un
     * documento si deve poter portare via.
     */
    public function esportaKtb(Request $request, string $id): Response
    {
        $user = Auth::user();
        $patrol = Database::first(
            'SELECT * FROM patrols WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );
        if ($patrol === null) {
            Session::flash('error', 'Missione non trovata.');
            return redirect('/ktb');
        }

        $boat = Database::first('SELECT * FROM boats WHERE id = ?', [(int) $patrol['boat_id']]);
        $cmd = $patrol['commander_id'] !== null
            ? Database::first('SELECT * FROM commanders WHERE id = ?', [(int) $patrol['commander_id']])
            : null;
        $clock = World::clock();

        $r = [];
        $r[] = 'KRIEGSTAGEBUCH — GIORNALE DI GUERRA';
        $r[] = str_repeat('=', 72);
        $r[] = 'Battello:     ' . ($boat['uboat_number'] ?? '—') . '  (' . ($boat['flotilla'] ?? '') . ')';
        $r[] = 'Comandante:   ' . ($cmd['nome'] ?? '—');
        $r[] = 'Missione n.:  ' . $patrol['number'];
        $r[] = 'Partenza:     ' . $clock->formatDiario((int) $patrol['departed_gts']);
        $r[] = 'Rientro:      ' . ($patrol['returned_gts'] !== null ? $clock->formatDiario((int) $patrol['returned_gts']) : 'in corso');
        $r[] = 'Percorse:     ' . number_format((float) $patrol['distance_nm'], 0, ',', '.') . ' miglia ('
            . number_format((float) $patrol['submerged_nm'], 0, ',', '.') . ' in immersione)';
        $r[] = 'Affondate:    ' . plurale((int) $patrol['affondate'], '1 nave', '%d navi') . ' per '
            . number_format((float) $patrol['grt_affondato'], 0, ',', '.') . ' GRT';
        $r[] = str_repeat('=', 72);
        $r[] = '';

        foreach (Database::all(
            'SELECT * FROM patrol_events WHERE patrol_id = ? ORDER BY gts, id',
            [(int) $patrol['id']]
        ) as $e) {
            $r[] = sprintf('%s  %-10s %s',
                $clock->formatDiario((int) $e['gts']),
                $e['quadrat'] ?? '—',
                (string) $e['text']);
        }

        if ($patrol['rapporto'] !== null) {
            $r[] = '';
            $r[] = str_repeat('=', 72);
            $r[] = (string) $patrol['rapporto'];
        }

        $nome = sprintf('KTB-%s-patrol-%d.txt',
            str_replace(['-', ' '], '', (string) ($boat['uboat_number'] ?? 'U')), (int) $patrol['number']);

        return Response::text(implode("\n", $r) . "\n")
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nome . '"');
    }

    // --- Amministrazione ---------------------------------------------------------

    public function admin(Request $request): Response
    {
        $gts = World::now();
        $tick = Database::first('SELECT * FROM tick_runs ORDER BY id DESC LIMIT 1');
        $ticks = Database::all('SELECT * FROM tick_runs ORDER BY id DESC LIMIT 12');

        return Response::html(view('admin/pannello', [
            'title'    => 'Amministrazione',
            'utenti'   => Database::all(
                'SELECT u.*, (SELECT COUNT(*) FROM commanders c WHERE c.user_id = u.id) AS comandanti,
                        (SELECT COUNT(*) FROM boats b WHERE b.user_id = u.id) AS battelli
                 FROM users u ORDER BY u.id DESC LIMIT 100'
            ),
            'config'   => GameConfig::all(),
            'tick'     => $tick,
            'ticks'    => $ticks,
            'traffico' => Traffic::stato($gts),
            'mondo'    => World::row(),
            'clock'    => World::clock(),
            'now'      => $gts,
        ]));
    }

    public function adminConfig(Request $request): Response
    {
        $chiave = $request->str('chiave');
        $valore = $request->str('valore');
        $attuale = GameConfig::all()[$chiave] ?? null;

        if ($attuale === null) {
            Session::flash('error', 'Chiave sconosciuta: si modificano solo quelle esistenti.');
            return redirect('/admin');
        }

        GameConfig::set($chiave, $valore, (string) $attuale['type']);
        \App\Support\Audit::log('admin.config', (int) Auth::id(), 'config', null,
            ['chiave' => $chiave, 'da' => $attuale['value'], 'a' => $valore], $request->ip());

        Session::flash('success', "Impostazione aggiornata: {$chiave} = {$valore}.");
        return redirect('/admin');
    }

    public function adminUtente(Request $request): Response
    {
        $id = $request->int('utente');
        $azione = $request->str('azione');
        $u = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

        if ($u === null) {
            Session::flash('error', 'Utente non trovato.');
            return redirect('/admin');
        }
        if ((int) $u['id'] === (int) Auth::id() && in_array($azione, ['sospendi', 'bandisci', 'cancella'], true)) {
            Session::flash('error', 'Non puoi sospendere ne\' cancellare te stesso.');
            return redirect('/admin');
        }

        if ($azione === 'cancella') {
            return $this->cancellaUtente($request, $u);
        }

        $stato = match ($azione) {
            'attiva'   => 'active',
            'sospendi' => 'suspended',
            'bandisci' => 'banned',
            default    => null,
        };
        if ($stato === null) {
            Session::flash('error', 'Azione sconosciuta.');
            return redirect('/admin');
        }

        Database::run('UPDATE users SET status = ? WHERE id = ?', [$stato, $id]);
        \App\Support\Audit::log('admin.user', (int) Auth::id(), 'user', $id, ['azione' => $azione], $request->ip());

        Session::flash('success', "Utente {$u['username']}: {$azione}.");
        return redirect('/admin');
    }

    /**
     * Cancella un account per davvero, e tutto quello che ci sta attaccato.
     *
     * Non e' un provvedimento come gli altri: sospendere e revocare chiudono
     * la porta e lasciano tutto dov'e', questo porta via. Se ne vanno i
     * battelli, i comandanti — anche quelli caduti, che sparisco dall'albo
     * d'oro — le missioni, gli affondamenti, i trofei e le decorazioni. Non si
     * torna indietro.
     *
     * Per questo si chiede di scrivere il nome per esteso: un pulsante da solo
     * si preme per sbaglio, un nome copiato a mano no. E restano tre paletti:
     * non si cancella se stessi, non si cancella l'ultimo amministratore, e il
     * registro conserva la riga di chi ha cancellato che cosa.
     *
     * @param array<string,mixed> $u
     */
    private function cancellaUtente(Request $request, array $u): Response
    {
        $id = (int) $u['id'];
        $nome = (string) $u['username'];

        if (trim($request->str('conferma')) !== $nome) {
            Session::flash('error', 'Per cancellare un account bisogna scriverne il nome esatto: '
                . 'e\' l\'unica azione che non si puo\' disfare.');
            return redirect('/admin/utente/' . $id);
        }

        if ((string) $u['role'] === 'admin') {
            $altri = (int) (Database::first(
                "SELECT COUNT(*) n FROM users WHERE role = 'admin' AND id <> ?", [$id]
            )['n'] ?? 0);
            if ($altri === 0) {
                Session::flash('error', 'Questo e\' l\'ultimo amministratore: cancellarlo lascerebbe '
                    . 'il pannello senza nessuno che possa entrarci.');
                return redirect('/admin/utente/' . $id);
            }
        }

        // Che cosa se ne va: si scrive nel registro PRIMA, perche' dopo non
        // c'e' piu' niente da contare.
        $comandanti = (int) (Database::first('SELECT COUNT(*) n FROM commanders WHERE user_id = ?', [$id])['n'] ?? 0);
        $battelli   = (int) (Database::first('SELECT COUNT(*) n FROM boats WHERE user_id = ?', [$id])['n'] ?? 0);
        $missioni   = (int) (Database::first('SELECT COUNT(*) n FROM patrols WHERE user_id = ?', [$id])['n'] ?? 0);

        \App\Support\Audit::log('admin.user_cancellato', (int) Auth::id(), 'user', $id, [
            'username'   => $nome,
            'comandanti' => $comandanti,
            'battelli'   => $battelli,
            'missioni'   => $missioni,
        ], $request->ip());

        // Le chiavi esterne portano via il resto (migrazione 0014): battelli,
        // comandanti, missioni, gettoni, bacheca.
        Database::run('DELETE FROM users WHERE id = ?', [$id]);

        // I file caricati non stanno nel database: ritratto ed emblema restano
        // sul disco finche' qualcuno non li pota.
        $ritratti = \App\Game\Ritratto::potaOrfani();
        $emblemi  = \App\Game\Emblema::potaOrfani();

        Session::flash('success', sprintf(
            'Account %s cancellato: %d comandanti, %d battelli, %d missioni. '
            . 'Immagini caricate rimosse: %d ritratti, %d emblemi.',
            $nome, $comandanti, $battelli, $missioni, $ritratti, $emblemi
        ));
        return redirect('/admin/utenti');
    }
}
