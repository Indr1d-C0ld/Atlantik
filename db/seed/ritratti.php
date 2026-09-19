<?php

declare(strict_types=1);

/**
 * Ritratti storici dei comandanti di U-Boot.
 *
 * GENERATO — non si modifica a mano.
 *
 * Due provenienze, e la differenza conta:
 *
 *   'commons'  scaricato da Wikimedia Commons con bin/scarica_ritratti.php.
 *              Porta con se' licenza e stringa di attribuzione verificate,
 *              e il gioco le mostra sotto il ritratto perche' e' una
 *              condizione delle licenze CC.
 *
 *   'raccolta' importato da una raccolta messa insieme a mano, con
 *              bin/importa_ritratti.php. La provenienza del singolo file
 *              NON e' verificata, e il gioco lo dice invece di attribuire
 *              una licenza che nessuno ha controllato.
 *
 * 'nome_ricavato' => true vuol dire che il nome viene dal nome del file,
 * smontato da un lettore automatico: e' giusto quasi sempre e sbagliato
 * qualche volta.
 */

/*
 * NOTA DELLA VERSIONE PUBBLICA
 * ============================
 *
 * Qui il repertorio e' VUOTO, e non per una dimenticanza.
 *
 * L'installazione da cui nasce questo progetto ha 508 fotografie di comandanti
 * di U-Boot realmente esistiti, raccolte a mano una per una. La provenienza del
 * singolo file non e' verificata: il gioco lo dichiara apertamente sotto ogni
 * ritratto, invece di attribuire una licenza che nessuno ha controllato.
 * Metterle in un repository GPL-3 vorrebbe dire asserire proprio quella licenza
 * — e non si puo'.
 *
 * Il gioco funziona benissimo senza: alla creazione del comandante la galleria
 * non compare, e si carica la propria fotografia.
 *
 * Per costruirsi un repertorio:
 *
 *   1. si mettono le immagini in una cartella (JPEG, PNG o WebP);
 *   2. php bin/importa_ritratti.php --prova /percorso/cartella     (legge e non scrive)
 *   3. php bin/importa_ritratti.php /percorso/cartella             (importa e riscrive questo file)
 *
 * L'importatore ricava il nome dal nome del file, ridimensiona al lato della
 * galleria e registra la provenienza. Se le immagini vengono da Wikimedia
 * Commons, bin/scarica_ritratti.php conserva licenza e attribuzione, e il gioco
 * le mostra: quella e' la strada pulita.
 */

return [];
