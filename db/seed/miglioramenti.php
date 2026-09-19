<?php

declare(strict_types=1);

/**
 * Miglioramenti tecnici acquistabili con i punti di assegnazione.
 *
 * Ogni voce porta la data storica reale in cui l'apparato entro' in servizio:
 * il mondo di Atlantik e' un periodo fisso e lo sblocco e' per merito, ma la
 * scheda dice sempre la verita' storica (vedi docs/DESIGN.md, sezione 0.1).
 *
 * `effetto` e' la chiave che il motore legge; `valore` il moltiplicatore o
 * l'entita' dell'effetto.
 */

return [
    [
        'ukey' => 'ghg_balkon', 'nome' => 'Impianto idrofonico a schiera "Balkon"', 'categoria' => 'scoperta',
        'costo' => 120, 'unlock_rank' => 2, 'effetto' => 'idrofono_di', 'valore' => 3.5,
        'storico' => 'in servizio dal 1943',
        'fonte' => 'Balkongeraet: schiera idrofonica ventrale, sensibilita' . "'" . ' nettamente superiore al GHG.',
        'confidence' => 'media',
        'note' => 'Tre decibel e mezzo di guadagno in piu' . "'" . ': un convoglio si sente qualche miglio prima.',
    ],
    [
        'ukey' => 'metox', 'nome' => 'Rivelatore radar Metox FuMB 1', 'categoria' => 'scoperta',
        'costo' => 90, 'unlock_rank' => 1, 'effetto' => 'avviso_aereo', 'valore' => 0.55,
        'storico' => 'in servizio dall\'agosto 1942',
        'fonte' => 'Metox: riceve la banda metrica (1,5 m) dei radar ASV Mk II, dando l\'allarme prima dell\'attacco.',
        'confidence' => 'alta',
        'note' => 'Avverte solo sulla banda che copre: contro i radar centimetrici del 1943 non suona, e quel silenzio uccise molti equipaggi.',
    ],
    [
        'ukey' => 'naxos', 'nome' => 'Rivelatore radar Naxos FuMB 7', 'categoria' => 'scoperta',
        'costo' => 200, 'unlock_rank' => 6, 'effetto' => 'avviso_aereo', 'valore' => 0.80,
        'storico' => 'in servizio dall\'autunno 1943',
        'fonte' => 'Naxos: riceve la banda centimetrica (10 cm) dei radar ASV Mk III e H2S.',
        'confidence' => 'media',
        'note' => 'La risposta al silenzio del Metox.',
    ],
    [
        'ukey' => 'batterie_maggiorate', 'nome' => 'Batterie di accumulatori maggiorate', 'categoria' => 'propulsione',
        'costo' => 150, 'unlock_rank' => 3, 'effetto' => 'batteria', 'valore' => 1.22,
        'storico' => 'varianti in servizio dal 1942',
        'fonte' => 'Elementi di accumulatore a maggiore capacita' . "'" . ' montati sui battelli piu' . "'" . ' recenti.',
        'confidence' => 'bassa',
        'note' => 'Un quinto di autonomia subacquea in piu' . "'" . ': sott\'acqua sono ore di vita.',
    ],
    [
        'ukey' => 'sospensioni_elastiche', 'nome' => 'Sospensioni elastiche per le macchine', 'categoria' => 'propulsione',
        'costo' => 110, 'unlock_rank' => 4, 'effetto' => 'rumore_proprio', 'valore' => 0.78,
        'storico' => 'adottate progressivamente dal 1942',
        'fonte' => 'Montaggio elastico dei motori elettrici e delle pompe per ridurre il rumore irradiato.',
        'confidence' => 'bassa',
        'note' => 'Si sente meglio e ci si fa sentire meno: vale doppio.',
    ],
    [
        'ukey' => 'bold_multiplo', 'nome' => 'Lanciatore multiplo per cartucce Bold', 'categoria' => 'contromisure',
        'costo' => 70, 'unlock_rank' => 2, 'effetto' => 'bold_efficacia', 'valore' => 1.35,
        'storico' => 'in servizio dal 1942',
        'fonte' => 'Pillenwerfer a caricatore multiplo: piu' . "'" . ' cartucce in rapida successione.',
        'confidence' => 'bassa', 'note' => null,
    ],
    [
        'ukey' => 'scafo_rinforzato', 'nome' => 'Rinforzo dello scafo resistente', 'categoria' => 'scafo',
        'costo' => 220, 'unlock_rank' => 5, 'effetto' => 'quota_max', 'valore' => 1.15,
        'storico' => 'standard sui Tipo VII C/41 dal 1943',
        'fonte' => 'Lamiere di scafo resistente di spessore maggiore.',
        'confidence' => 'media',
        'note' => 'Quindici per cento di quota in piu' . "'" . ' prima che lo scafo ceda. Sotto le cariche, e\' tutto.',
    ],
    [
        'ukey' => 'flak_vierling', 'nome' => 'Mitragliera quadrinata 2 cm Flakvierling', 'categoria' => 'armamento',
        'costo' => 130, 'unlock_rank' => 4, 'effetto' => 'flak', 'valore' => 2.2,
        'storico' => 'sulle piattaforme "Wintergarten" dal 1943',
        'fonte' => 'Armamento antiaereo potenziato per i battelli costretti a combattere in superficie.',
        'confidence' => 'media',
        'note' => 'Restare a combattere contro un aereo resta quasi sempre l\'errore che uccide. Quasi.',
    ],
    [
        'ukey' => 'siluri_collaudati', 'nome' => 'Lotto di siluri collaudati', 'categoria' => 'armamento',
        'costo' => 80, 'unlock_rank' => 1, 'effetto' => 'siluri_qualita', 'valore' => 0.55,
        'storico' => 'dopo la revisione delle spolette del 1941-42',
        'fonte' => 'Siluri con spolette revisionate e corsa verificata al banco.',
        'confidence' => 'media',
        'note' => 'Quasi la meta' . "'" . ' dei difetti in meno. Costa, ma un siluro che non esplode e\' un siluro buttato.',
    ],
    [
        'ukey' => 'schnorchel', 'nome' => 'Schnorchel (respiratore)', 'categoria' => 'propulsione',
        'costo' => 320, 'unlock_rank' => 8, 'effetto' => 'schnorchel', 'valore' => 1.0,
        'storico' => 'in servizio dal 1944',
        'fonte' => 'Albero respiratore che permette di ricaricare le batterie a quota periscopica.',
        'confidence' => 'media',
        'note' => 'Cambia il gioco, e porta i suoi guai: testa d\'albero rilevabile dal radar, valvola che chiude e mette lo scafo in depressione, sei nodi scarsi.',
    ],
];
