# Atlantik

**Simulatore multigiocatore persistente della Battaglia dell'Atlantico.**
Il giocatore è il comandante di un U-Boot; il resto dell'oceano — mercantili,
convogli, scorte, aerei, meteo, BdU — è mosso dal motore secondo regole
storicamente documentate.

Non è un gioco a turni e non è un arcade. Il mondo va avanti da solo, un minuto
reale ogni mezz'ora di gioco, anche quando nessuno guarda: le navi partono e
arrivano, il tempo cambia, gli aerei pattugliano, e un battello che resta troppo
in superficie nel Golfo di Biscaglia prima o poi lo paga.

PHP 8.4 senza framework · MariaDB · JavaScript vanilla · **zero dipendenze**,
nessun build step. Interfaccia in italiano.

---

## Stato

**F0 — Fondamenta: fatta** (17/09/2026). Account con conferma dell'indirizzo via Brevo,
accesso, base di flottiglia, console CLI, migrazioni, prova end-to-end verde.

**F1 — Mondo e navigazione: fatta** (17/09/2026). Griglia Marinequadrat, orologio di
crociera 1:30, sole e luna con livello di luce, meteo atlantico come campo continuo
deterministico, correnti, moto con navigazione stimata e punto astronomico, consumi
calibrati sui dati storici, plancia con quadranti, carta nautica su tela, giornale di
guerra, tick da cron.

**F2 — Battello ed equipaggio: fatta** (17/09/2026). Compartimenti e diciannove sistemi che
si guastano davvero, avarie d'uso e da pressione, squadre di riparazione, equipaggio nominativo
con gradi, specialita', tre guardie, competenza, stanchezza e morale; cantiere e allestimento
con vincoli di stiva.

**F3 — Contatti: fatta** (18/09/2026). Traffico alleato persistente sulle rotte storiche
(convogli HX/SC/ON/ONS/OG/HG/SL/TM e navi isolate, 600-700 navi in mare), idrofono con modello
acustico completo, avvistamento visivo bidirezionale, fumo all'orizzonte, radar alleato,
pattugliamento aereo per zona, calore di settore, contatti soggettivi con rosa dei rilevamenti.

**F4 — Combattimento: fatta** (18/09/2026). Sei tipi di siluro storici con difetti e spolette,
calcolatore di lancio con propagazione dell'errore, incontro tattico a passi di dieci secondi con
il convoglio materializzato in formazione, scorte che cercano con ASDIC (cono e angolo cieco),
cariche di profondita' con quota stimata, cannone di coperta, Bold, evasione, albo degli
affondamenti.

**F5 — Carriera: fatta** (18/09/2026). Creazione del comandante, tredici livelli di anzianita' con
i gradi storici, nove decorazioni con criteri e motivazioni generate, prestigio/punti di
assegnazione/Reichsmark, dieci apparati acquistabili che agiscono davvero sul motore (Metox, Naxos,
Balkon, batterie, sospensioni elastiche, scafo rinforzato, siluri collaudati, Schnorchel), corsi
per l'equipaggio, rapporto di missione al BdU, **permadeath con catena di sopravvivenza** e albo
d'oro pubblico.

**F6 — Multigiocatore: fatta** (18/09/2026). BdU che assegna aree operative, emette comunicati di
situazione e forma i gruppi; Rudeltaktik con segnalazione di contatto condivisa e premio al
Fuehlungshalter; radio con Kurzsignale e **HF/DF** (probabilita' di intercettazione per durata
d'emissione, punto con errore per numero di rilevamenti, calore del settore); rifornimento in mare
dal Tipo XIV con rischio di appuntamento compromesso; bacheca di flottiglia; statistiche di
campagna pubbliche col rapporto di scambio.

**F7 — Rifinitura: fatta** (18/09/2026). Ventitre trofei distinti dalle decorazioni storiche,
esportazione del giornale di guerra in testo, applicazione installabile (manifesto, service worker
del solo guscio, pagina di cortesia senza rete), suoni di bordo sintetizzati e avvisi del browser
(entrambi spenti per difetto), pannello di amministrazione con diagnostica del battito, modifica a
caldo delle chiavi di bilanciamento e registro delle azioni, comando `balance:report` che confronta
i modelli con le bande storiche.

**Pannello di amministrazione avanzato** (18/09/2026). Cinque sezioni oltre alla diagnostica:
elenco utenti con ricerca e filtro di stato, scheda di un account (comandanti, battelli, missioni,
accessi, posta, nota interna), accessi e origine aggregati per indirizzo con segnalazione degli
indirizzi che accumulano fallimenti, comunicazioni su due canali (posta accodata che rispetta il
tetto giornaliero, oppure bacheca di flottiglia), classifica completa. Gli indirizzi non vengono
geolocalizzati: manderebbe il dato di un giocatore a un servizio di terzi.

**Seconda revisione tecnica** (18/09/2026, sera). Audit del gioco in esercizio: undici
rilievi trovati e chiusi, fra cui il piu' grave dell'intero progetto — **l'incontro tattico
non avanzava mai**, quindi in tutta la storia di questo mondo non era mai affondato niente.
Dettaglio, prove e osservazioni aperte nell'audit tecnico, che resta nel deployment perche'
parla di quella macchina e non del gioco.
Le verifiche automatiche salgono a **529**.

**Il danno che resta, e quello che si vede** (18/09/2026, sera). Una nave colpita e non
affondata non torna piu' intera nel traffico: rallenta, perde il convoglio e diventa una
**ritardataria** — la preda preferita degli U-Boot — e puo' affondare ore dopo, con la
conferma del BdU a chi l'aveva colpita. I convogli ridotti all'osso si **disperdono**, come
il PQ17. E la stazione d'attacco non mostra piu' la verita' nuda: il quadro tattico e' il
tavolo di plottaggio della Zentrale, dove finisce solo cio' che si e' visto o sentito, con
l'errore di chi l'ha visto o sentito. Si vede, si riconosce e si legge il nome a tre
distanze diverse, e tutti i numeri sono stime — e' per questo che il Vorhaltrechner esiste.

**Fascicoli, volti, emblemi, e il telefono** (18/09/2026, sera). Il fascicolo di ogni
comandante e' leggibile dagli altri in servizio: ritratto, emblema di torretta, battello e
tipo, decorazioni e trofei, uscite e missione piu' lunga, rendimento (GRT per siluro, per
missione, per mille miglia) e gli affondamenti divisi fra **naviglio militare e civile** e
per **bandiera**, coi neutrali segnati a parte. Alla creazione si puo' scegliere un
**ritratto storico** fra 33 fotografie di comandanti veri scaricate da Wikimedia Commons con
la loro licenza — che il gioco mostra sotto il ritratto — oppure caricarne una propria; e si
puo' prendere anche il nome del comandante ritratto, dichiarandolo come omaggio. Il
repertorio degli **emblemi di torretta** passa da nove a trentanove, tutti disegnati qui.
Un volto, un nome, un emblema e un numero di U-Boot per ciascuno, imposti dal database.
L'amministratore puo' intervenire sui fascicoli altrui, e ogni intervento resta nel registro.
Tutta l'interfaccia e' stata resa utilizzabile su **tablet e telefono** senza toccare una
riga di come si vede su un monitor.

**516 volti, e l'unicita' che vale fra i vivi** (18/09/2026, sera). Il repertorio dei
ritratti passa da 33 a **516**: una raccolta di comandanti di U-Boot realmente esistiti,
messa insieme a mano dal proprietario del gioco. Di quei file non si conosce la provenienza
singola e il gioco lo dice apertamente, invece di attribuire una licenza che nessuno ha
verificato; le voci scaricate da Commons continuano a portare la loro. I nomi sono ricavati
dai nomi dei file e la scheda lo dichiara. Con cinquecento volti serviva una casella di
ricerca, e c'e'.

Soprattutto: **l'unicita' vale fra i vivi, e solo dove ha senso**. Restano unici il nome del
comandante, il ritratto preso dalla galleria e il numero dell'U-Boot, finche' quel comandante
e' in servizio e quel battello galleggia; quando cade, tornano disponibili, e il caduto
conserva volto e nome nell'albo d'oro. L'**emblema di torretta no**: molti erano di
flottiglia — il toro di Prien divento' il segno di tutta la 7. U-Flottille — e renderlo
esclusivo sarebbe stato storicamente sbagliato. Una fotografia caricata da casa viene portata
alla misura della galleria e, **a scelta**, invecchiata: monocromatica e neutra come
sono davvero le fotografie storiche, contrasto morbido, grana e angoli scuri; l'effetto si
vede subito, accendendo e spegnendo la casella.

**Le date, e a che ora si riferiscono** (19/09/2026). Ovunque compaia una data ora si legge
all'italiana, GG/MM/AAAA — con una sola eccezione, voluta: **l'ora di bordo**. Li' i punti
alla tedesca (`07.02.1942 07:29`) restano, perche' sono quello che leggerebbe l'ufficiale che
tiene il Kriegstagebuch: valgono per l'orologio in testata di ogni pagina di bordo, per il
giornale di guerra — la pagina, le ultime righe in centrale, il file esportato — e per tutta
la stazione d'attacco, cronaca compresa — verificata forzando un incontro vero, con un
siluro in acqua, e poi rimettendo tutto com'era. Fuori di li' non escono, e c'e' una prova
che lo controlla. Ogni posto col punto ha due facce, quella disegnata dal server e quella che il
JavaScript riscrive da vivo: la prova le tiene appaiate, perche' basta che una sola resti
indietro e la data cambia forma da sola al primo aggiornamento. Cinque punti del pannello
di amministrazione stampavano la colonna del database cosi' com'era — iscrizione, conferma,
ultimo accesso, registro degli accessi, freni antiabuso — e si leggeva `2026-09-19 03:35:58`. La data di
nascita del comandante si scrive e si rilegge all'italiana e resta una data vera nel
database. In fondo a ogni pagina c'era scritto «Ora di bordo» sopra l'orologio del server:
l'ora di bordo e' quella di gioco e si legge in plancia, quella in fondo e' l'ora di Roma, e
adesso lo dice. Ogni orario reale e' l'ora di Roma — verificato che il fuso applicativo,
quello di PHP e quello del database coincidano, perche' un database in UTC sfaserebbe ogni
data di due ore senza avvisare.

**L'emblema tondo, e la lente** (19/09/2026). Sul fascicolo l'emblema di torretta compariva
dentro un quadrato col fondo bianco, mentre in testata lo stesso file era tondo: la cornice
c'era in un posto e non nell'altro. Adesso un emblema si presenta tondo dovunque compaia —
fascicolo, testata, cantiere — e il fondo agli angoli non si vede piu', qualunque cosa ci sia
nel file. Al passaggio del mouse (o col fuoco da tastiera, o con un tocco su schermo che si
tocca) si apre una **lente** che lo mostra in grande col nome e il motto.

**La mensa e' quella della tua flottiglia** (19/09/2026). La bacheca si chiamava «di
flottiglia» e mostrava a tutti i messaggi di tutti: la colonna c'era e non filtrava niente.
Adesso si legge la mensa della propria flottiglia — la 11. mangia a Bergen, la 2./10. a
Lorient, e quello che si dice la' qui non si sente — mentre i comunicati del comando
arrivano dappertutto, perche' il BdU non parla a una mensa sola. Un messaggio si puo'
togliere: chi l'ha scritto si riprende la sua frase, l'amministratore modera qualunque
cosa e la moderazione resta nel registro.

Nel farlo e' saltato fuori che **la base scelta alla creazione del comandante veniva
ignorata**: il battello lo si assegnava sempre a Lorient, cosi' il fascicolo dichiarava una
flottiglia e il battello ne portava un'altra. Con tutti nella stessa flottiglia il filtro
non avrebbe filtrato niente.

**La stanza dei bottoni** (19/09/2026). Il pannello di amministrazione guadagna due
stanze. La prima, `/admin/mondo`, e' fatta di monitor e di manopole: il censimento del
naviglio in mare per classe, bandiera, ruolo e rotta; gli incontri aperti; le quarantuno
chiavi del motore divise per area e con scritto a che servono (le descrizioni c'erano in
tabella da sempre e non le leggeva nessuno: la colonna delle note era vuota per un campo
che la query non chiedeva); la **composizione del traffico** — quanti piroscafi, quante
petroliere, quali scorte — che prima era scritta nel codice e adesso si regola, con i pesi
storici come punto di ritorno; e le **forzature del meteo**.

Il tempo, in questo gioco, e' una funzione pura del seme e dell'istante: uguale per tutti e
ricalcolabile all'indietro. Una forzatura non tocca quella funzione, le si siede sopra — in
un cerchio, per una finestra, dicendo solo i campi che si vogliono imporre. Si puo' calare
la nebbia su un convoglio senza inventarsi la pressione, e quando scade il mondo torna
quello che sarebbe stato senza che nessuno debba disfare niente.

La seconda, `/admin/carta`, e' la **carta ammiraglia**: tutto quello che galleggia, tutto
insieme. Traffico isolato, convogli con la loro consistenza e la loro scorta, battelli dei
giocatori, coste, porti, e a richiesta il vento e lo stato del mare stesi su una maglia. Un
clic apre la scheda di quello che c'e' li' sotto, o il bollettino del tempo in quel punto.
E' una vista che in gioco non esiste e non deve esistere.

**Il registro che non si vedeva, e la carta che si naviga** (19/09/2026). Audit::log
scriveva diciotto azioni diverse — manopole girate, provvedimenti sugli account, forzature
del tempo, moderazione della bacheca — e le due pagine che dicevano «registro» ne mostravano
cinque: filtravano su `auth.%`, ed erano un diario degli accessi. Tredici azioni su
diciotto, cioe' tutto quello che fa l'amministrazione, erano scritte e invisibili. Adesso
c'e' `/admin/registro`: tutte, in italiano, con il dettaglio raccontato invece che stampato
in JSON, filtrabili per area e cercabili. L'originale resta nel suggerimento del mouse.

La **carta ammiraglia** si naviga: rotellina per ingrandire dove sta il cursore (fino a
ventiquattro volte), trascinamento, doppio clic, frecce da tastiera, un elenco «vai a» per
inquadrare un battello o un convoglio, e un pulsante per tornare a tutto il teatro. La
proiezione resta equirettangolare a ogni ingrandimento: i quadrati Marinequadrat restano
rettangoli, e un rilevamento letto qui somiglia a uno letto in plancia.

**Quarta revisione tecnica: quello che era scritto e non girava** (19/09/2026). Audit
completo con un metodo nuovo — non «questo codice e' giusto» ma «questo codice gira?».
Diciassette rilievi, tutti chiusi. Il piu' grave e' anche il piu' semplice da raccontare:
`Torpedo::ricarica()` esisteva, era scritta bene, e **non la chiamava nessuno**. Un VII
parte con quattordici siluri, cinque nei tubi; lanciati quelli, il battello restava
disarmato per tutto il resto della crociera, con nove siluri a bordo e nessun modo di
usarli. Il secondo attacco allo stesso convoglio — il cuore della tattica del branco — non
poteva esistere.

Nella stessa tornata: la **quota di collasso** adesso esiste davvero (ogni scafo ha il suo
punto di cedimento, fisso e sconosciuto, dentro l'intervallo del cantiere, e si abbassa con
le deformazioni permanenti); la simulazione **si ferma** quando il battello e' perduto,
invece di far navigare il relitto; l'**aria** che finisce fa emergere come le batterie
scariche; il **recupero della password** con invalidazione di tutte le sessioni aperte; il
**congedo dal servizio attivo**, perche' il mestiere aveva due uscite e il gioco ne offriva
una sola; l'**ordine di pedinamento** del BdU, che e' la meta' mancante della Rudeltaktik;
e tre modi in cui il mondo dipendeva ancora da quanto spesso si ricarica la pagina.

Le verifiche automatiche salgono a **815**, con sei suite nuove.

**Il gioco e' completo: da F0 a F7.** Quello che resta e' bilanciamento sul campo e beta.
La pagina d'ingresso dice lo stato vero — con i numeri del mondo in corso, presi dal database a
ogni caricamento — e l'elenco di quello che e' arrivato dopo la chiusura della tabella di marcia.

## Uso

### Installazione

```bash
# 1. codice
git clone https://github.com/Indr1d-C0ld/Atlantik.git
sudo rsync -a Atlantik/ /var/www/atlantik/

# 2. server, permessi, vhost, database, configurazione
sudo OWNER_USER="$USER" \
     SITE_DIR=/var/www/atlantik \
     CONFIG_DIR=/etc/atlantik \
     PUBLIC_URL=https://esempio.tld/atlantik \
     ADMIN_EMAIL=tu@esempio.tld \
     bash /var/www/atlantik/deploy/00-bootstrap.sh

# 3. schema e mondo
php /var/www/atlantik/bin/console.php migrate
php /var/www/atlantik/bin/console.php world:seed
php /var/www/atlantik/bin/console.php world:init
```

Il file dei segreti (credenziali del database, SMTP, indirizzo
dell'amministratore) va **fuori dal DocumentRoot**: `/etc/atlantik/config.php`,
oppure il percorso indicato dalla variabile d'ambiente `ATLANTIK_CONFIG`. Il
modello è in [`config/config.example.php`](config/config.example.php).

Il mondo avanza grazie a un tick da cron, ogni minuto:

```
* * * * * /usr/bin/php /var/www/atlantik/bin/tick.php >/dev/null 2>&1
```

### Console

```bash
php bin/console.php migrate                      # applica le migrazioni
php bin/console.php world:seed                   # tipi di U-Boot, porti, rotte, classi
php bin/console.php world:init [seme]            # crea il mondo
php bin/console.php world:stats                  # stato del mondo
php bin/console.php sim:tick                     # avanza tutti i battelli
php bin/console.php user:admin <nome>            # promuove ad amministratore
php bin/console.php mail:smista                  # smista la coda di posta
```

### Le prove

Venti file, che si aspettano un'installazione funzionante e un database
raggiungibile. Le end-to-end creano e cancellano da sé i propri account di
prova (`prova *`).

```bash
php tests/test_sim.php               # griglia, astronomia, consumi, orologio
php tests/test_incontro.php          # incontro tattico e accredito degli affondamenti
php tests/test_danni.php             # danno persistente, ritardatarie, convogli dispersi
php tests/test_compartimenti.php     # falle, pressione, squadra di falla, paratie
php tests/test_vista.php             # quello che si vede davvero dalla stazione d'attacco
php tests/test_profilo.php           # fascicoli, ritratti, emblemi, unicità
php tests/test_date.php              # forma italiana delle date e fuso orario
php tests/test_coerenza.php          # promesse a vuoto: manopole morte, pannelli inerti
php tests/test_viste.php             # annidamento dei form nei modelli di pagina
php tests/test_coste.php             # coste, porti, navigabilità
php tests/test_posta.php             # coda di posta e ritentativi
php tests/test_concorrenza.php       # lucchetti e avanzamento serializzato
php tests/test_segnaposto.php        # sagome e registro delle fonti
php tests/test_username.php          # validatore dei nomi utente
php tests/test_equita.php            # lo stesso mondo comunque ci si colleghi
php tests/test_limiti.php            # casi limite: riserve finite, quota di collasso
php tests/test_siluri.php            # ricarica dei tubi, contenitori di coperta, siluri guasti
php tests/test_pedinamento.php       # ordine di pedinamento del BdU, dalla radio all'incasso

bash tests/e2e_auth.sh               # registrazione, conferma, accesso
bash tests/e2e_recupero.sh           # password dimenticata: collegamento, cambio, sessioni chiuse
bash tests/e2e_navigazione.sh        # dalla base al mare e ritorno
bash tests/e2e_congedo.sh            # congedo dal servizio attivo, dal modulo all'albo d'oro
bash tests/e2e_admin.sh              # pannello di amministrazione
bash tests/e2e_mensa.sh              # mensa ufficiali: flottiglia e moderazione
bash tests/e2e_mondo.sh              # stanza dei bottoni, registro, carta navigabile
bash tests/e2e_browser.sh            # il JavaScript di bordo, con un browser vero
```

Le variabili `BASE_URL` e `HOST_HDR` cambiano l'indirizzo che le prove
interrogano; il default è `http://localhost/atlantik`.

## Ritratti dei comandanti

Il repertorio dei ritratti storici **è vuoto in questo repository**, e non per
una dimenticanza: le fotografie di comandanti realmente esistiti che circolano
in rete hanno provenienza spesso non verificabile, e pubblicarle sotto GPL-3
vorrebbe dire asserire una licenza che nessuno ha controllato.

Il gioco funziona benissimo senza: alla creazione del comandante si carica la
propria fotografia, con ritaglio e filtro d'epoca facoltativo. Chi vuole un
repertorio se lo costruisce:

```bash
php bin/importa_ritratti.php --prova /percorso/cartella   # legge e non scrive
php bin/importa_ritratti.php /percorso/cartella           # importa
php bin/scarica_ritratti.php                              # da Wikimedia Commons, con licenza e attribuzione
```

I **trentanove emblemi di torretta**, invece, ci sono tutti: sono disegni
originali fatti per questo gioco (`bin/disegna_emblemi.php`), non copie di
emblemi storici, e il gioco dichiara in pagina quali soggetti sono documentati
e quali ricostruiti.

## Documentazione

- [docs/DESIGN.md](docs/DESIGN.md) — la progettazione per intero: modello del
  mondo, meccaniche, formule, scelte e perché.
- [docs/FONTI.md](docs/FONTI.md) — il registro delle fonti storiche: da dove
  viene ogni numero, e quanto è affidabile.

## Stack

PHP 8.4 senza framework · MariaDB 11 · Apache · JavaScript vanilla + Canvas
(nessun build step) · simulazione autoritativa lato server, con tick da cron e
avanzamento pigro deterministico.

Nessuna dipendenza: niente Composer, niente npm, niente CDN. La politica dei
contenuti non ammette JavaScript in linea.

## Licenza

[GNU General Public License v3.0](LICENSE).

I dati storici (tipi di U-Boot, classi di naviglio, rotte dei convogli, meteo,
sagome ONI 208) vengono da fonti pubbliche documentate in
[docs/FONTI.md](docs/FONTI.md); le sagome ONI sono di pubblico dominio
(US Navy). Gli emblemi sono disegni originali e seguono la licenza del progetto.
