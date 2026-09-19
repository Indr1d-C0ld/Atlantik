<?php

declare(strict_types=1);

/**
 * Trofei: riconoscimenti del gioco, non della Kriegsmarine.
 *
 * Sono distinti dalle decorazioni storiche di proposito: quelle le conferisce
 * il BdU secondo criteri d'epoca, questi premiano il modo in cui si gioca —
 * la pazienza dell'agguato, la prudenza con la radio, il mestiere del
 * navigatore. Molti si ottengono facendo le cose come si facevano davvero.
 */

return [
    // --- Caccia ---------------------------------------------------------------
    ['akey' => 'primo_sangue', 'nome' => 'Primo sangue', 'categoria' => 'caccia', 'ordine' => 10,
     'descrizione' => 'Affondare la prima nave.',
     'nota' => 'Il tonnellaggio si conta da qui.'],
    ['akey' => 'dieci_navi', 'nome' => 'Dieci scafi', 'categoria' => 'caccia', 'ordine' => 20,
     'descrizione' => 'Affondare dieci navi con lo stesso comandante.', 'nota' => null],
    ['akey' => 'centomila', 'nome' => 'Centomila tonnellate', 'categoria' => 'caccia', 'ordine' => 30,
     'descrizione' => 'Raggiungere le 100.000 tonnellate di stazza affondata.',
     'nota' => 'La soglia con cui si proponeva la Croce di Cavaliere nei primi anni.'],
    ['akey' => 'petroliera', 'nome' => 'Il carico che brucia', 'categoria' => 'caccia', 'ordine' => 40,
     'descrizione' => 'Affondare una petroliera carica.',
     'nota' => 'Si vede il bagliore fino all\'orizzonte, e si capisce cosa si e\' fatto.'],
    ['akey' => 'scorta', 'nome' => 'Il cacciatore cacciato', 'categoria' => 'caccia', 'ordine' => 50,
     'descrizione' => 'Affondare un\'unita\' di scorta.',
     'nota' => 'Raro, pericoloso, e considerato un fatto d\'armi a se\'.'],
    ['akey' => 'notte_perfetta', 'nome' => 'Notte perfetta', 'categoria' => 'caccia', 'ordine' => 60,
     'descrizione' => 'Affondare tre navi in un solo incontro.',
     'nota' => 'L\'attacco notturno in superficie dentro il convoglio: la dottrina di Doenitz, applicata.'],
    ['akey' => 'cannoniere', 'nome' => 'Economia di guerra', 'categoria' => 'caccia', 'ordine' => 70,
     'descrizione' => 'Affondare una nave col solo cannone di coperta.',
     'nota' => 'Un siluro costa quanto cento colpi da 8,8.'],

    // --- Navigazione -----------------------------------------------------------
    ['akey' => 'traversata', 'nome' => 'Traversata', 'categoria' => 'navigazione', 'ordine' => 100,
     'descrizione' => 'Percorrere 5.000 miglia in una sola missione.', 'nota' => null],
    ['akey' => 'navigatore', 'nome' => 'Mestiere di Obersteuermann', 'categoria' => 'navigazione', 'ordine' => 110,
     'descrizione' => 'Rientrare alla base con un errore di stima inferiore a due miglia.',
     'nota' => 'Sestante, cronometro e tavole: la posizione non si regala.'],
    ['akey' => 'profondo', 'nome' => 'Sotto la quota di prova', 'categoria' => 'navigazione', 'ordine' => 120,
     'descrizione' => 'Scendere oltre la quota di prova del battello e tornare a galla.',
     'nota' => 'Lo scafo si lamenta, e da quel momento e\' un altro scafo.'],
    ['akey' => 'rifornito', 'nome' => 'Appuntamento in mezzo al nulla', 'categoria' => 'navigazione', 'ordine' => 130,
     'descrizione' => 'Completare un rifornimento in mare dal Tipo XIV.', 'nota' => null],

    // --- Sopravvivenza ----------------------------------------------------------
    ['akey' => 'cariche', 'nome' => 'Sotto le cariche', 'categoria' => 'sopravvivenza', 'ordine' => 200,
     'descrizione' => 'Subire cinquanta cariche di profondita\' in un solo incontro e sopravvivere.',
     'nota' => 'Il rullo di lanci e\' progettato per essere il momento peggiore della vita a bordo.'],
    ['akey' => 'sganciato', 'nome' => 'Sganciati', 'categoria' => 'sopravvivenza', 'ordine' => 210,
     'descrizione' => 'Rompere il contatto dopo essere stati scoperti da una scorta.',
     'nota' => 'Strato termico, marcia silenziosa, e la pazienza di non muoversi.'],
    ['akey' => 'aereo_scampato', 'nome' => 'Trenta secondi', 'categoria' => 'sopravvivenza', 'ordine' => 220,
     'descrizione' => 'Sfuggire a un attacco aereo grazie al rivelatore radar.',
     'nota' => 'Il ronzio in cuffia arriva prima del rumore dei motori.'],
    ['akey' => 'ritorno', 'nome' => 'Il battello e\' tornato', 'categoria' => 'sopravvivenza', 'ordine' => 230,
     'descrizione' => 'Rientrare alla base con almeno un\'avaria grave a bordo.', 'nota' => null],

    // --- Comando ----------------------------------------------------------------
    ['akey' => 'ritterkreuz', 'nome' => 'Al collo', 'categoria' => 'comando', 'ordine' => 300,
     'descrizione' => 'Ottenere la Croce di Cavaliere.', 'nota' => null],
    ['akey' => 'fuehlungshalter', 'nome' => 'Fuehlungshalter', 'categoria' => 'comando', 'ordine' => 310,
     'descrizione' => 'Segnalare cinque contatti utili al proprio gruppo.',
     'nota' => 'Pedinare non affonda nulla, e senza qualcuno che lo faccia il branco non esiste.'],
    ['akey' => 'dieci_patrol', 'nome' => 'Dieci volte fuori', 'categoria' => 'comando', 'ordine' => 320,
     'descrizione' => 'Concludere dieci missioni con lo stesso comandante.',
     'nota' => 'Statisticamente, a questo punto si era gia\' morti.'],
    ['akey' => 'erede', 'nome' => 'Chi viene dopo', 'categoria' => 'comando', 'ordine' => 330,
     'descrizione' => 'Prendere servizio come comandante dopo averne perso uno.', 'nascosto' => 1,
     'nota' => 'Il fascicolo precedente resta nell\'albo d\'oro. Si riparte, ma non da zero.'],

    // --- Mestiere ----------------------------------------------------------------
    ['akey' => 'silenzio', 'nome' => 'Disciplina del silenzio', 'categoria' => 'mestiere', 'ordine' => 400,
     'descrizione' => 'Concludere una missione con almeno un affondamento e nessuna trasmissione radio.',
     'nota' => 'Il modo piu\' sicuro di non farsi triangolare e\' non parlare.'],
    ['akey' => 'idrofonista', 'nome' => 'Orecchio fino', 'categoria' => 'mestiere', 'ordine' => 410,
     'descrizione' => 'Rilevare un convoglio all\'idrofono a piu\' di trenta miglia.',
     'nota' => 'Due nodi, marcia silenziosa, e si sente mezzo oceano.'],
    ['akey' => 'riparatore', 'nome' => 'Il Leitender Ingenieur', 'categoria' => 'mestiere', 'ordine' => 420,
     'descrizione' => 'Riparare dieci avarie in mare nel corso della carriera.', 'nota' => null],
    ['akey' => 'equipaggio', 'nome' => 'Uomini contenti', 'categoria' => 'mestiere', 'ordine' => 430,
     'descrizione' => 'Rientrare da una missione di almeno tre settimane con il morale sopra 70.',
     'nota' => 'Cibo, aria, riparazioni riuscite e un comandante che decide.'],
];
