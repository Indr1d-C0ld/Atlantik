<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Meteo;
use App\Game\Mondo;
use App\Sim\Grid;
use App\Sim\Traffic;
use App\Sim\World;
use App\Support\Audit;

/**
 * La stanza dei bottoni: il mondo visto e mosso dall'amministratore.
 *
 * Due stanze comunicanti. Una e' fatta di monitor — quante navi, di che tipo,
 * sotto che bandiera, chi e' in mare, che tempo fa e dove — e non tocca
 * niente. L'altra e' fatta di manopole: le chiavi di bilanciamento, la
 * composizione del traffico, le forzature del meteo, e le poche leve che
 * agiscono sul mondo in esercizio.
 *
 * Tre regole, e valgono per tutte le leve di qui:
 *
 *   1. quello che si guarda non cambia niente, mai;
 *   2. quello che cambia finisce nel registro, con chi e con che valori;
 *   3. niente e' irreversibile senza dirlo: le forzature scadono da sole e si
 *      tolgono, le manopole tornano al valore storico.
 */
final class MondoController
{
    public function mondo(Request $request): Response
    {
        return Response::html(view('admin/mondo', [
            'title'     => 'Mondo — stanza dei bottoni',
            'attiva'    => 'mondo',
            'quadro'    => Mondo::quadro(),
            'censimento' => Mondo::censimento(),
            'incontri'  => Mondo::incontri(),
            'manopole'  => Mondo::manopole(),
            'forzature' => Meteo::attive(),
            'mix_convoglio' => $this->mixCorrente('traffic.mix_convoglio', Traffic::MIX_CONVOGLIO),
            'mix_scorta'    => $this->mixCorrente('traffic.mix_scorta', Traffic::MIX_SCORTA),
            'clock'     => World::clock(),
            'now'       => World::now(),
        ]));
    }

    /**
     * Il registro delle azioni: tutte, non solo gli accessi.
     *
     * Le due pagine che dicevano "registro" filtravano su 'auth.%': tredici
     * azioni su diciotto — ogni cosa che l'amministrazione fa — erano scritte
     * e non le mostrava nessuno.
     */
    public function registro(Request $request): Response
    {
        $area = $request->str('area');
        $cerca = mb_substr($request->str('cerca'), 0, 80);
        $pagina = max(1, $request->int('pagina', 1));
        $perPagina = 60;

        $dati = \App\Support\Registro::righe($area, $cerca, $pagina, $perPagina);

        return Response::html(view('admin/registro', [
            'title'      => 'Registro delle azioni',
            'attiva'     => 'registro',
            'righe'      => $dati['righe'],
            'totale'     => $dati['totale'],
            'pagina'     => $pagina,
            'per_pagina' => $perPagina,
            'area'       => $area,
            'cerca'      => $cerca,
            'conteggi'   => \App\Support\Registro::conteggi(),
        ]));
    }

    /** La carta ammiraglia: tutto quello che galleggia, tutto insieme. */
    public function carta(Request $request): Response
    {
        return Response::html(view('admin/carta', [
            'title'  => 'Carta ammiraglia',
            'attiva' => 'carta_admin',
            'clock'  => World::clock(),
            'now'    => World::now(),
            'quadrati' => array_values(array_map(
                static fn (string $sigla, array $q): array => ['sigla' => $sigla, 'row' => (int) $q['row'], 'col' => (int) $q['col']],
                array_keys(Grid::table()),
                array_values(Grid::table())
            )),
            // array_values: World::ports() e' indicizzato per chiave di porto, e
            // un array con chiavi di testo diventa un oggetto in JSON, non una
            // lista. Il JavaScript ci faceva forEach sopra e si fermava li',
            // portandosi dietro tutto il disegno.
            'porti' => array_values(array_map(static fn (array $p): array => [
                'nome' => (string) $p['name'],
                'lat'  => (float) $p['lat'],
                'lon'  => (float) $p['lon'],
                'base' => (string) $p['kind'] === 'base',
            ], World::ports())),
        ]));
    }

    /**
     * I dati della carta, in JSON: si aggiorna da sola senza ricaricare.
     *
     * La griglia del meteo costa: ogni punto e' un conto vero. Si chiede col
     * passo che si vuole, e il passo ha un minimo perche' nessuno possa
     * chiedere per sbaglio ventimila punti.
     */
    public function dati(Request $request): Response
    {
        $gts = $request->input('gts') !== null ? (int) $request->input('gts') : World::now();
        $passo = (float) ($request->input('passo') ?? 5.0);

        return Response::json([
            'ok'     => true,
            'gts'    => $gts,
            'ora'    => World::clock()->format($gts),
            'quadro' => Mondo::quadro(),
        ] + Mondo::scacchiera($gts) + [
            'meteo' => $request->input('meteo') === '0' ? [] : Mondo::grigliaMeteo($passo, $gts),
        ]);
    }

    /** La scheda del tempo in un punto: quella che si apre cliccando la carta. */
    public function meteoPunto(Request $request): Response
    {
        $lat = (float) $request->input('lat', 0);
        $lon = (float) $request->input('lon', 0);
        $gts = $request->input('gts') !== null ? (int) $request->input('gts') : World::now();

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return Response::json(['ok' => false, 'error' => 'Coordinate fuori dal mondo.'], 422);
        }

        return Response::json(['ok' => true, 'meteo' => Mondo::meteoIn($lat, $lon, $gts)]);
    }

    /** Mette una forzatura sul tempo. */
    public function meteoForza(Request $request): Response
    {
        $res = Meteo::forza([
            'lat'  => $request->str('lat'),
            'lon'  => $request->str('lon'),
            'raggio_nm' => $request->str('raggio_nm'),
            'ore'  => $request->str('ore'),
            'wind_kn' => $request->str('wind_kn'),
            'wind_dir' => $request->str('wind_dir'),
            'sea_state' => $request->str('sea_state'),
            'visibility_nm' => $request->str('visibility_nm'),
            'fog'  => $request->str('fog'),
            'cloud' => $request->str('cloud'),
            'nota' => $request->str('nota'),
        ], (int) Auth::id());

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Forzatura non applicata.');
            return redirect('/admin/mondo');
        }

        Audit::log('admin.meteo_forzato', (int) Auth::id(), 'weather', $res['id'] ?? null, [
            'lat' => $request->str('lat'), 'lon' => $request->str('lon'),
            'raggio_nm' => $request->str('raggio_nm'), 'ore' => $request->str('ore'),
            'mare' => $request->str('sea_state'), 'vento' => $request->str('wind_kn'),
            'nebbia' => $request->str('fog'), 'visibilita' => $request->str('visibility_nm'),
            // La nota dice PERCHE', ed e' la parte che fra sei mesi serve
            // davvero: senza, il registro racconta che cosa e non perche'.
            'nota' => $request->str('nota'),
        ], $request->ip());

        Session::flash('success', 'Forzatura applicata. Scade da sola, e si puo\' togliere prima.');
        return redirect('/admin/mondo');
    }

    /** Toglie una forzatura, o tutte. */
    public function meteoTogli(Request $request): Response
    {
        if ($request->str('tutte') === '1') {
            $n = Meteo::togliTutte();
            Audit::log('admin.meteo_liberato', (int) Auth::id(), 'weather', null, ['tolte' => $n], $request->ip());
            Session::flash('success', "Tolte {$n} forzature: il tempo torna quello che sarebbe stato.");
            return redirect('/admin/mondo');
        }

        $id = $request->int('forzatura');
        Meteo::togli($id);
        Audit::log('admin.meteo_liberato', (int) Auth::id(), 'weather', $id, [], $request->ip());
        Session::flash('success', 'Forzatura tolta.');
        return redirect('/admin/mondo');
    }

    /**
     * La composizione del traffico: quante navi di ciascun tipo, in percentuale.
     *
     * Si salvano i pesi cosi' come sono scritti; il motore li normalizza da
     * se'. Una casella vuota o a zero toglie quella classe dal mescolo. Se si
     * svuotano tutte, si torna ai pesi storici — che e' il modo piu' semplice
     * di dire "rimetti com'era".
     */
    public function mix(Request $request): Response
    {
        $quale = $request->str('quale') === 'scorta' ? 'scorta' : 'convoglio';
        $chiave = 'traffic.mix_' . $quale;
        $storici = $quale === 'scorta' ? Traffic::MIX_SCORTA : Traffic::MIX_CONVOGLIO;

        /** @var array<string,mixed> $pesi */
        $pesi = (array) $request->input('peso', []);
        $puliti = [];
        foreach ($pesi as $classe => $peso) {
            $peso = (int) $peso;
            if ($peso > 0) {
                $puliti[(string) $classe] = $peso;
            }
        }

        if ($puliti === []) {
            // Tolta la chiave, mix() ricade sui pesi storici da sola. La cache
            // di GameConfig vive quanto la richiesta, e la richiesta finisce qui.
            Database::run('DELETE FROM game_config WHERE ckey = ?', [$chiave]);
            Audit::log('admin.traffico_mix', (int) Auth::id(), 'config', null,
                ['chiave' => $chiave, 'a' => 'storico'], $request->ip());
            Session::flash('success', 'Composizione riportata ai pesi storici.');
            return redirect('/admin/mondo');
        }

        GameConfig::set($chiave, (string) json_encode($puliti), 'json',
            'Composizione ' . $quale . ' (pesi relativi). Vuota = pesi storici.');
        Audit::log('admin.traffico_mix', (int) Auth::id(), 'config', null,
            ['chiave' => $chiave, 'a' => $puliti, 'storici' => $storici], $request->ip());

        Session::flash('success', 'Composizione aggiornata: vale per le navi che partono da adesso.');
        return redirect('/admin/mondo');
    }

    /**
     * Le leve che agiscono sul mondo in esercizio.
     *
     * Sono poche di proposito. Qui dentro c'e' una partita di altre persone:
     * una leva che non si sa disfare non ci sta.
     */
    public function azione(Request $request): Response
    {
        $gts = World::now();

        switch ($request->str('azione')) {
            case 'traffico_ripopola':
                $res = Traffic::ensure($gts);
                Audit::log('admin.mondo', (int) Auth::id(), 'world', null,
                    ['azione' => 'traffico_ripopola'] + $res, $request->ip());
                Session::flash('success', sprintf(
                    'Traffico ripopolato: %d convogli e %d navi isolate in piu\'.',
                    (int) ($res['convogli'] ?? 0), (int) ($res['isolate'] ?? 0)
                ));
                break;

            case 'traffico_pota':
                $res = Traffic::pota($gts);
                Audit::log('admin.mondo', (int) Auth::id(), 'world', null,
                    ['azione' => 'traffico_pota'] + $res, $request->ip());
                Session::flash('success', 'Traffico potato: via quello arrivato e quello scaduto.');
                break;

            default:
                Session::flash('error', 'Leva sconosciuta.');
        }

        return redirect('/admin/mondo');
    }

    /**
     * I pesi da mostrare nelle caselle: quelli in vigore, storici compresi.
     *
     * @param array<string,int> $storici
     * @return list<array<string,mixed>>
     */
    private function mixCorrente(string $chiave, array $storici): array
    {
        $attuali = GameConfig::get($chiave);
        $attuali = is_array($attuali) ? $attuali : [];
        $forzato = $attuali !== [];

        $out = [];
        foreach (array_keys($storici + $attuali) as $classe) {
            $cls = Traffic::classe((string) $classe);
            $out[] = [
                'chiave'  => (string) $classe,
                'nome'    => (string) ($cls['name'] ?? $classe),
                'grt'     => (int) ($cls['grt'] ?? 0),
                'peso'    => (int) ($attuali[$classe] ?? $storici[$classe] ?? 0),
                'storico' => (int) ($storici[$classe] ?? 0),
            ];
        }

        return ['forzato' => $forzato, 'righe' => $out];
    }
}
