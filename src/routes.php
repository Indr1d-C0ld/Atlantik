<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BaseController;
use App\Controllers\BattelloController;
use App\Controllers\CarrieraController;
use App\Controllers\ProfiloController;
use App\Controllers\RadioController;
use App\Controllers\MondoController;
use App\Controllers\RifinituraController;
use App\Controllers\CombattimentoController;
use App\Controllers\HomeController;
use App\Controllers\PlanciaController;
use App\Core\Router;

/** @var Router $router */

// Pubbliche
$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HomeController::class, 'health']);

// Arruolamento e verifica dell'indirizzo
$router->get('/arruolamento', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/arruolamento', [AuthController::class, 'register'], ['guest', 'throttle']);
$router->get('/verifica-inviata', [AuthController::class, 'verificationSent']);
$router->get('/verifica', [AuthController::class, 'verify']);
$router->post('/rinvia-verifica', [AuthController::class, 'resend'], ['throttle']);

// Password dimenticata: chiedere il collegamento, e poi usarlo.
$router->get('/recupero-richiesta', [AuthController::class, 'recuperoForm'], ['guest']);
$router->post('/recupero-richiesta', [AuthController::class, 'recuperoInvia'], ['guest', 'throttle']);
$router->get('/recupero', [AuthController::class, 'recuperoForm2'], ['guest']);
$router->post('/recupero', [AuthController::class, 'recuperoSalva'], ['guest', 'throttle']);

// Accesso
$router->get('/accesso', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/accesso', [AuthController::class, 'login'], ['guest', 'throttle']);
$router->post('/esci', [AuthController::class, 'logout'], ['auth']);

// Carriera
$router->get('/comandante', [CarrieraController::class, 'comandante'], ['active']);
$router->post('/comandante/crea', [CarrieraController::class, 'crea'], ['active', 'throttle']);
$router->post('/comandante/compra', [CarrieraController::class, 'compra'], ['active', 'throttle']);
$router->post('/comandante/addestra', [CarrieraController::class, 'addestra'], ['active', 'throttle']);
$router->post('/comandante/congedo', [CarrieraController::class, 'congeda'], ['active', 'throttle']);
$router->get('/rapporto/{id}', [CarrieraController::class, 'rapporto'], ['active']);
$router->get('/albo', [CarrieraController::class, 'albo']);

// --- Fascicolo pubblico del comandante ---------------------------------------
// In flottiglia ci si conosce: il fascicolo di chiunque e' leggibile da chiunque
// altro sia in servizio. Serve un account attivo, non e' una vetrina sul mondo.
$router->get('/profilo/{id}', [ProfiloController::class, 'mostra'], ['active']);
$router->get('/comandante/profilo', [ProfiloController::class, 'modifica'], ['active']);
$router->post('/comandante/ritratto', [ProfiloController::class, 'scegli'], ['active', 'throttle']);
$router->post('/comandante/ritratto/carica', [ProfiloController::class, 'carica'], ['active', 'throttle']);
$router->post('/comandante/ritratto/togli', [ProfiloController::class, 'togli'], ['active', 'throttle']);
$router->post('/comandante/nota', [ProfiloController::class, 'nota'], ['active', 'throttle']);

// Base di flottiglia
$router->get('/base', [BaseController::class, 'index'], ['active']);
$router->post('/uscita', [PlanciaController::class, 'uscita'], ['active', 'throttle']);

// Plancia
$router->get('/zentrale', [PlanciaController::class, 'zentrale'], ['active']);
$router->get('/carta', [PlanciaController::class, 'carta'], ['active']);
$router->get('/contatti', [PlanciaController::class, 'contatti'], ['active']);
$router->get('/ktb', [PlanciaController::class, 'ktb'], ['active']);

// Ordini
$router->post('/ordini', [PlanciaController::class, 'ordini'], ['active', 'throttle']);
$router->post('/rotta', [PlanciaController::class, 'rotta'], ['active', 'throttle']);
$router->post('/carta/stile', [PlanciaController::class, 'preferenzaCarta'], ['active', 'throttle']);
$router->post('/rientro', [PlanciaController::class, 'rientro'], ['active', 'throttle']);

// Battello ed equipaggio
$router->get('/battello', [BattelloController::class, 'battello'], ['active']);
$router->get('/equipaggio', [BattelloController::class, 'equipaggio'], ['active']);
$router->post('/riparazione', [BattelloController::class, 'riparazione'], ['active', 'throttle']);
$router->post('/paratia', [BattelloController::class, 'paratia'], ['active', 'throttle']);
$router->post('/turno', [BattelloController::class, 'turno'], ['active', 'throttle']);

// Bunker: cantiere e allestimento
$router->get('/cantiere', [BattelloController::class, 'cantiere'], ['active']);
$router->post('/cantiere/tipo', [BattelloController::class, 'cambiaTipo'], ['active', 'throttle']);
$router->post('/cantiere/allestimento', [BattelloController::class, 'allestimento'], ['active', 'throttle']);
$router->post('/cantiere/emblema', [BattelloController::class, 'emblemaScegli'], ['active', 'throttle']);
$router->post('/cantiere/emblema/carica', [BattelloController::class, 'emblemaCarica'], ['active', 'throttle']);

// Combattimento
$router->get('/attacco', [CombattimentoController::class, 'attacco'], ['active']);
$router->post('/attacco/ingaggia', [CombattimentoController::class, 'ingaggia'], ['active', 'throttle']);
$router->post('/attacco/manovra', [CombattimentoController::class, 'manovra'], ['active', 'throttle']);
$router->post('/attacco/lancia', [CombattimentoController::class, 'lancia'], ['active', 'throttle']);
$router->post('/attacco/cannone', [CombattimentoController::class, 'cannone'], ['active', 'throttle']);
$router->post('/attacco/bold', [CombattimentoController::class, 'bold'], ['active', 'throttle']);
$router->post('/attacco/periscopio', [CombattimentoController::class, 'periscopio'], ['active', 'throttle']);
$router->post('/attacco/disimpegna', [CombattimentoController::class, 'disimpegna'], ['active', 'throttle']);
$router->get('/api/incontro', [CombattimentoController::class, 'stato'], ['active']);

// Radio, comando e mondo condiviso
$router->get('/radio', [RadioController::class, 'radio'], ['active']);
$router->post('/radio/trasmetti', [RadioController::class, 'trasmetti'], ['active', 'throttle']);
$router->get('/bdu', [RadioController::class, 'bdu'], ['active']);
$router->post('/bdu/ordine', [RadioController::class, 'rispondiOrdine'], ['active', 'throttle']);
$router->post('/bdu/branco/entra', [RadioController::class, 'entraBranco'], ['active', 'throttle']);
$router->post('/bdu/branco/esci', [RadioController::class, 'esciBranco'], ['active', 'throttle']);
$router->post('/bdu/rifornimento', [RadioController::class, 'rifornimento'], ['active', 'throttle']);
$router->get('/bacheca', [RadioController::class, 'bacheca'], ['active']);
$router->post('/bacheca', [RadioController::class, 'bacheca'], ['active', 'throttle']);
$router->post('/bacheca/rimuovi', [RadioController::class, 'bachecaRimuovi'], ['active', 'throttle']);
$router->get('/statistiche', [RadioController::class, 'statistiche']);

// Trofei ed esportazione
$router->get('/trofei', [RifinituraController::class, 'trofei'], ['active']);
$router->get('/ktb/{id}/esporta', [RifinituraController::class, 'esportaKtb'], ['active']);

// Amministrazione
$router->get('/admin', [RifinituraController::class, 'admin'], ['admin']);
$router->post('/admin/config', [RifinituraController::class, 'adminConfig'], ['admin', 'throttle']);

// La stanza dei bottoni: il mondo guardato e mosso dall'amministratore.
$router->get('/admin/mondo', [MondoController::class, 'mondo'], ['admin']);
$router->get('/admin/carta', [MondoController::class, 'carta'], ['admin']);
$router->get('/admin/registro', [MondoController::class, 'registro'], ['admin']);
$router->get('/admin/mondo/dati', [MondoController::class, 'dati'], ['admin']);
$router->get('/admin/mondo/meteo', [MondoController::class, 'meteoPunto'], ['admin']);
$router->post('/admin/mondo/meteo', [MondoController::class, 'meteoForza'], ['admin', 'throttle']);
$router->post('/admin/mondo/meteo/togli', [MondoController::class, 'meteoTogli'], ['admin', 'throttle']);
$router->post('/admin/mondo/mix', [MondoController::class, 'mix'], ['admin', 'throttle']);
$router->post('/admin/mondo/azione', [MondoController::class, 'azione'], ['admin', 'throttle']);
$router->post('/admin/utente', [RifinituraController::class, 'adminUtente'], ['admin', 'throttle']);

// Pannello avanzato: account, accessi, comunicazioni, classifica
$router->get('/admin/utenti', [AdminController::class, 'utenti'], ['admin']);
$router->get('/admin/utente/{id}', [AdminController::class, 'utente'], ['admin']);
$router->post('/admin/utente/nota', [AdminController::class, 'nota'], ['admin', 'throttle']);
$router->get('/admin/accessi', [AdminController::class, 'accessi'], ['admin']);
$router->get('/admin/comunicazioni', [AdminController::class, 'comunicazioni'], ['admin']);
$router->post('/admin/comunicazioni', [AdminController::class, 'invia'], ['admin', 'throttle']);
$router->get('/admin/classifica', [AdminController::class, 'classifica'], ['admin']);
$router->get('/admin/comandante/{id}', [ProfiloController::class, 'adminModifica'], ['admin']);
$router->post('/admin/comandante/rinomina', [ProfiloController::class, 'adminRinomina'], ['admin', 'throttle']);

// Interfaccia dati (polling della plancia)
$router->get('/api/stato', [PlanciaController::class, 'stato'], ['active']);
