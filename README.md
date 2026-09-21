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

## Che cos'è, in concreto

Ti viene assegnato un battello e un equipaggio di quarantotto uomini con nome e
cognome. Esci da Lorient, attraversi il Golfo di Biscaglia — dove il pericolo
non sono i convogli ma gli aerei — e vai a cercare qualcosa in mezzo
all'Atlantico. Non c'è una mappa che ti dice dove sono i nemici: c'è un
idrofono, quattro vedette in torretta, e un Obersteuermann che ti dà un punto
nave con un errore che cresce di ora in ora finché il cielo non si apre
abbastanza da prendere le stelle.

Quando senti qualcosa, il tempo cambia marcia: la crociera corre veloce, la
caccia va al secondo. Ti avvicini in superficie di notte, ti immergi quando ti
vedono, calcoli la soluzione di tiro con i dati che hai — angolo sulla prua,
velocità stimata, distanza — e lanci sapendo che se hai sbagliato di due nodi a
duemila metri il siluro passa dietro.

Poi arrivano le scorte, e la partita cambia di nuovo: silenzio, quota, strato
termico, e la pazienza di non muoversi mentre le cariche scendono.

Se non torni, non torni. Il comandante muore, il suo fascicolo si chiude e
finisce nell'albo d'oro; l'account resta e ne arruola un altro, che eredita una
quota del prestigio del predecessore e ricomincia.

---

## L'ambientazione

**Un eterno 1942.** Il calendario gira dentro l'anno di massima densità
operativa e non invecchia mai: restano vere le stagioni, le fasi lunari, le
durate del giorno — e la guerra non finisce. È una decisione di progetto, non
una scorciatoia, e ha una conseguenza dichiarata: l'arsenale del 1939-45 c'è
tutto, ma si sblocca **per merito**, non per data. Ogni apparato porta scritto
l'anno in cui comparve davvero, e il gioco lo dice in chiaro.

La difficoltà non scala col calendario: scala con **lo spazio e con la
reazione del nemico**.

- Il **Mid-Atlantic Gap** a sud della Groenlandia è dove i Liberator non
  arrivano: è la caccia grossa.
- Il **Golfo di Biscaglia** è il corridoio del transito, e si paga in aerei.
- Ogni settore ha un **calore** (0-100) che sale con gli affondamenti, con le
  trasmissioni radio intercettate e con gli avvistamenti, e scende da solo col
  tempo. Calore alto significa più scorte, meglio addestrate, copertura aerea
  più estesa e convogli deviati. È quello che faceva l'Ammiragliato per
  davvero, e produce la stessa curva di difficoltà senza barare col calendario.

La posizione si dice in **Marinequadrat**, la griglia della Kriegsmarine: BF
1911, AK 4732. È quella che si trasmette al BdU, ed è quella con cui gli altri
comandanti ti dicono dove hanno visto qualcosa.

**Nove basi** con le loro flottiglie storiche — Lorient (2. und 10.), Saint-
Nazaire (6. und 7.), Brest (1. und 9.), La Pallice, Bordeaux, Kiel,
Wilhelmshaven, Bergen, Trondheim — e ventitré fra porti e ancoraggi nel
teatro.

---

## Il tempo, che è la cosa più insolita

Due motori, due velocità.

**In crociera** il mondo corre a **1:30**: un minuto reale vale mezz'ora di
gioco. Una traversata dell'Atlantico dura giorni di gioco e ore vere. Il mondo
va avanti anche quando non guardi — un battito da cron ogni minuto muove tutti
i battelli in mare, fa salpare e arrivare i convogli, cambia il tempo, manda
gli aerei in pattuglia.

**In contatto** il tempo rallenta fino a **1:1**: durante un attacco il secondo
di gioco è un secondo vero, e le decisioni si prendono adesso.

L'avanzamento è **pigro e deterministico**: il battello sta fermo nel database
finché qualcuno non apre una pagina, e a quel punto la simulazione recupera il
tempo passato a sotto-passi di cinque minuti. Il caso è seminato sul battello e
sull'istante, mai sull'orologio di chi si collega — il che significa che

> due comandanti identici, uno che ricarica ogni trenta secondi e uno che torna
> una volta al giorno, dopo dodici ore di gioco si trovano nello stesso punto,
> con la stessa nafta e **lo stesso identico giornale di bordo**.

Non è un'aspirazione: è una prova automatica che gira a ogni esecuzione della
suite, e ci sono volute tre tornate di audit per renderla vera.

---

## Il battello

**Otto tipi giocabili**, dal piccolo II D costiero al IX D2 oceanico, passando
per il VII B e il VII C che sono la spina dorsale, fino al XXI — che in
immersione va più forte che in superficie. Ogni scheda riporta dislocamento,
velocità, autonomia alle andature di riferimento, quota di prova, tubi e
siluri, ed è confrontata una per una con le fonti.

**Diciannove sistemi** che si guastano davvero: diesel, motori elettrici,
timoni orizzontali, casse di zavorra, pompe, compressori, periscopi, idrofono,
radio, cannone, flak, batterie, scafo. Si rompono per usura — e l'usura cresce
col regime, col mare grosso e con la stanchezza di chi sta alle macchine — e
per i colpi incassati. La squadra ripara a mare quello che si può riparare a
mare: un periscopio piegato, no.

**Otto compartimenti** che imbarcano acqua. L'acqua pesa: abbassa la quota di
sicurezza e rallenta. Una paratia si può sigillare, e se dentro c'è ancora
qualcuno, quel qualcuno resta dentro. La centrale non si sigilla.

**La quota di collasso è vera.** Ogni scafo ha il suo punto di cedimento, fisso
e sconosciuto al comandante, dentro l'intervallo dichiarato dal cantiere — per
un VII B, fra 220 e 250 metri. La pressione oltre la quota di prova lascia due
segni: uno elastico che si riassorbe, e uno permanente che non torna indietro e
abbassa quell'intervallo per sempre. Scendere è una scelta con un prezzo.

**Sei tipi di siluro**: il G7a a vapore con le sue tre regolazioni (44 nodi per
5.500 metri, 40 per 7.500, 30 per 12.500) e la scia che ti tradisce, i G7e
elettrici silenziosi e più corti, il FAT e il LUT che corrono a serpentina
dentro un convoglio, e il T5 acustico che insegue l'elica. Con i loro difetti
storici: cilecca, scoppio prematuro, quota sbagliata — la crisi dei siluri è
nel modello, non nelle note.

I tubi **si ricaricano**: venti minuti buoni per tubo, quattro uomini che
manovrano una tonnellata e mezza d'acciaio in un corridoio largo un metro. E i
siluri del contenitore stagno di coperta si tirano dentro solo in superficie,
col mare non oltre forza 3, in un'ora, col battello che nel frattempo non può
immergersi in fretta.

**Dieci apparati** da comprare col prestigio, ognuno con la sua data storica in
scheda: Metox e Naxos (rivelatori radar), l'idrofono a schiera Balkon, le
batterie maggiorate, le sospensioni elastiche, lo scafo rinforzato, la
mitragliera quadrinata, i siluri collaudati, il lanciatore multiplo per le
cartucce Bold, lo Schnorchel.

---

## L'equipaggio

Quarantotto uomini, ciascuno con nome, grado, ruolo e anzianità. Non sono una
statistica: sono un ruolino.

Gli ufficiali sono quelli storici — il **I.WO** all'attacco silurico, il
**II.WO** all'artiglieria, il **LI** che comanda l'immersione e l'assetto ed è
quello che ti salva, l'**Obersteuermann** che tiene il punto. Poi il Funkmaat
alla radio e all'idrofono, i macchinisti ai diesel e agli elettrici, i
siluristi, le vedette, e lo Smutje — il cuoco, che conta sul serio per il
morale.

Ognuno ha **competenza** nella sua specialità, **fatica** e **morale**. I tre
si muovono per conto loro secondo la vita di bordo: i quarti di guardia che
ruotano, il mare grosso che non fa dormire, l'aria che si fa pesante,
l'allarme, i giorni di missione che si accumulano, le avarie, i viveri che
finiscono. E la resa dell'equipaggio entra dappertutto: nelle avarie, nella
velocità delle riparazioni, nella qualità dell'ascolto, nell'errore al lancio.

Fra una missione e l'altra si mandano gli uomini ai corsi. Si feriscono, si
muore, e i migliori vengono trasferiti a formare nuovi equipaggi — che è una
delle ragioni per cui la qualità media crollò nel 1943.

---

## Il rilevamento, che è il cuore vero

Tutto il gioco è un problema di informazione asimmetrica: **chi vede per primo,
vive**. Il modello è simmetrico: le stesse formule con cui tu trovi loro li
governano quando cercano te.

**L'idrofono** non dà la distanza, dà un rilevamento. Un convoglio grosso si
sente a trenta-quaranta miglia con mare moderato; una nave isolata a dieci o
quindici; col mare grosso quasi niente. Una stima di distanza si azzarda solo
quando il rumore è forte — e resta grossolana. La distanza vera si ricava
pedinando, che è il mestiere del Fuehlungshalter e costa ore.

**Lo strato termico** taglia il contatto in due: sotto, l'ASDIC fatica a
trovarti e tu fatichi a sentire. D'inverno lo strato quasi non c'è, e una
burrasca lo rimescola.

**La vista** dipende dalla sagoma, dalla luce e dal mare. Un U-Boot in
superficie, di notte e senza luna, lo si vede a un miglio e mezzo; con la luna
piena, a cinque — e vale in tutte e due le direzioni, perché è la stessa
formula. Il **fumo all'orizzonte** è un canale a sé — si vede prima degli alberi
— ed è il primo indizio di un convoglio. Presentare la prua riduce moltissimo
la propria sagoma; correre lascia una baffa che a sei nodi sul mare liscio
triplica quello che si vede di te.

**Il radar alleato** non guarda la luce: di notte non ti serve a niente essere
scuro. Il Metox canta quando qualcuno ti illumina, e mezzo minuto di anticipo è
la differenza fra immergersi e prendersi quattro bombe sul ponte.

**Gli aerei** pattugliano per zona e reagiscono al calore del settore. In
Biscaglia sono il motivo per cui si transita di notte e in immersione.

I contatti sono **soggettivi**: quello che hai in mano non è la verità, è
quello che il tuo equipaggio crede. Classe stimata, rotta stimata, velocità
stimata, con una certezza che cresce tenendo il contatto — e una nave civetta
resta un innocuo piroscafo finché non calano i pannelli.

---

## Il combattimento

L'incontro tattico materializza il convoglio in formazione vera — colonne a
mille iarde, navi a seicento, che è il motivo per cui ci si infila dentro — e
scorre a passi di dieci secondi.

**Il lancio** è un problema di trigonometria con dati incerti: rilevamento,
angolo sulla prua, velocità e distanza stimate, più l'errore del periscopio o
la stima del I.WO se non sei tu a guardare. Si sceglie il tubo, la spoletta
(a contatto o magnetica), la quota di corsa e l'apertura del ventaglio.
L'errore si propaga: a duemila metri, due nodi sbagliati sono un siluro perso.

**Le scorte** cercano con l'ASDIC — che ha un cono, un angolo cieco sotto di
sé, e perde il contatto nell'ultimo tratto dell'accosto, che è esattamente il
momento in cui lanciano le cariche — e con l'idrofono, che sente il tuo rumore
proprio: lì la marcia silenziosa paga. Le cariche scendono con una quota
stimata. Il Bold, la cartuccia che produce una nube di bolle, compra secondi.

Il **cannone di coperta** affonda un piroscafo isolato senza spendere un
siluro, ma ti tiene in superficie a poche centinaia di metri — ed è la
situazione in cui la nave civetta faceva cadere i pannelli.

Una nave colpita e non affondata **non guarisce**: esce dall'incontro, rallenta,
perde il convoglio e diventa una ritardataria — la preda preferita — e magari
affonda ore dopo, e il BdU te la accredita comunque.

---

## Il mondo che gira da solo

Seicento-settecento navi in mare in ogni momento — seicentotrenta adesso, in
quattordici convogli — su **undici rotte storiche**
e **otto serie di convogli** — HX e SC verso est, ON e ONS verso ovest, OG e HG
per Gibilterra, SL da Freetown, TM le petroliere — più il naviglio isolato, le
rotte dei Caraibi, del Capo e dell'Islanda.

**Ventidue classi di naviglio**: mercantili, petroliere, trasporti truppe,
ausiliarie, sei classi di scorta (dalle corvette Flower ai cacciatorpediniere
Town e V&W, alle fregate River) e cinque tipi di aereo. Ognuna con velocità,
stazza, rumore, eliche, e — dove serve — ASDIC, radar, HF/DF.

Il **meteo** è un campo continuo deterministico: vento, stato del mare,
visibilità, nebbia, nuvolosità, pressione. È funzione pura del seme, del punto
e dell'istante — non è una tabella, e non cambia se ricarichi. Sole e luna sono
calcolati per davvero: l'altezza del sole decide la luce, la fase della luna
decide se la notte è nera o se ti vedono a cinque miglia.

---

## Multigiocatore

Il mondo è **uno solo e condiviso**. I convogli che affondi non ci sono più per
gli altri; il calore che lasci in un settore lo trovano loro.

**Il BdU** assegna aree operative, emette comunicati di situazione, forma i
gruppi e — quando segnali un convoglio — ti chiede di **pedinarlo**: restare
attaccato per ore senza attaccare, e continuare a trasmettere, perché gli altri
possano arrivare. È il mestiere più ingrato dell'Atlantico e il gioco lo paga
più di un'area operativa, perché altrimenti non lo farebbe nessuno.

**I branchi** sono asincroni: si entra, si segnala, i compagni ricevono il
punto e convergono. Chi segnala prende il premio del Fuehlungshalter.

**La radio ha un prezzo.** Ogni trasmissione è un'emissione che l'**HF/DF**
alleato può agganciare: la probabilità cresce con i secondi di antenna, e con
due o più rilevamenti esce un punto — il tuo — con un errore che si stringe.
Da lì in poi il settore si scalda. I Kurzsignale esistono apposta per stare in
aria il meno possibile.

**Il rifornimento in mare** dal Tipo XIV — la "mucca" — con appuntamento in un
quadrato e in una finestra, e il rischio che l'appuntamento sia compromesso.

E una regola che è costata un audit per scoprirla: **una nave, un
affondamento**. Due battelli sullo stesso convoglio aprono due incontri
distinti — è la tattica del branco — ma lo stesso piroscafo va a fondo una
volta sola e il merito è di chi ce l'ha mandato.

---

## La carriera

**Tredici livelli di anzianità**, dai gradi storici: si comincia Oberleutnant
zur See e si arriva, con trentamila punti di prestigio, a Fregattenkapitän.

Tre valute distinte: il **prestigio** che misura la carriera, i **punti di
assegnazione** che comprano apparati e corsi, i **Reichsmark**.

**Nove decorazioni** con i criteri veri e la motivazione generata in tedesco
burocratico: Croce di Ferro di seconda e prima classe, Distintivo di guerra dei
sommergibili, Fregio di fronte, Croce Tedesca in oro, e la catena della Croce
di Cavaliere fino a fronde di quercia, spade e brillanti. Più **ventitré
trofei** che premiano il mestiere invece del tonnellaggio: la notte perfetta,
il cacciatore cacciato, l'orecchio fino, la disciplina del silenzio, sotto le
cariche, il Leitender Ingenieur.

**Permadeath, con catena di sopravvivenza.** Quando lo scafo cede, la
probabilità di uscirne dipende dalla quota — sotto i cento metri il portello
non si apre nemmeno — e chi esce finisce prigioniero o disperso. Il fascicolo
si chiude e resta nell'albo d'oro, pubblico e permanente. L'account ne arruola
un altro, che eredita una quota del prestigio.

E c'è la **seconda uscita**, quella che i giochi dimenticano: il **congedo**. Si
lascia il comando in banchina, da vivi, e si chiude la carriera con onore — che
è come finirono parecchi di quelli diventati un nome.

---

## Che cosa lo rende diverso

- **Il mondo non dipende da quanto spesso ricarichi la pagina.** È la proprietà
  più difficile da ottenere in un gioco persistente a tempo compresso, ed è
  verificata da una prova che confronta quattro ritmi di collegamento diversi e
  pretende lo stesso giornale di bordo, riga per riga.
- **Niente è comprabile con soldi veri, e non c'è nessun vantaggio a giocare di
  più** se non l'esperienza: il tempo del mondo scorre uguale per tutti.
- **Onestà storica dichiarata.** Ogni dato porta il suo grado di confidenza
  (alta, media, bassa) e la sua fonte; ogni ricostruzione è etichettata come
  tale. Le sagome del naviglio dicono in pagina se sono documentate o
  ricostruite.
- **Zero dipendenze.** Niente Composer, niente npm, niente CDN, nessun build
  step. La politica dei contenuti non ammette JavaScript in linea.
- **Si gioca col dito.** La carta nautica si trascina e si ingrandisce con la
  pinza; l'applicazione si installa sul telefono.

---

## Stato

**Il gioco è completo: da F0 a F7.** Fondamenta, mondo e navigazione, battello
ed equipaggio, contatti, combattimento, carriera, multigiocatore, rifinitura.
Quello che resta è bilanciamento sul campo e beta.

Sotto c'è un mondo in esercizio dal 17 settembre 2026: seicentotrenta navi in
mare in questo momento, in quattordici convogli, per tre milioni e mezzo di
tonnellate di stazza. Il battito del minuto ha girato 3.561 volte e ha fallito
otto volte — ogni fallimento con la sua riga nel diario e la sua causa nota,
che è il motivo per cui si contano.

### Le sette revisioni tecniche

Il gioco è stato sottoposto a sette audit successivi, ognuno con un metodo
diverso dal precedente, perché un metodo ripetuto smette di trovare. Il
registro completo — con le misure, i numeri e le prove che lo dimostrano — sta
in `docs/AUDIT.md` nel deployment; qui il sunto.

| # | Metodo | Il rilievo che conta |
|---|--------|----------------------|
| 1 | Infrastruttura ed esercizio | Nessun versionamento e nessuna copia di sicurezza |
| 2 | Il gioco in esercizio | **L'incontro tattico non avanzava mai**: in tutta la storia di quel mondo non era affondato niente |
| 3 | Famiglie di guasto già viste | Il quadro dei compartimenti era inerte: quattro colonne che nessuno scriveva |
| 4 | *Chi chiama che cosa* | **`Torpedo::ricarica()` non la chiamava nessuno**: lanciati i cinque siluri dei tubi, il battello restava disarmato per tutta la crociera |
| 5 | *Gira dove serve?* | Due battelli sullo stesso convoglio affondavano la stessa nave e **la pagavano tutti e due** |
| 6 | Scala, durata, browser vero | Ogni incontro chiuso teneva in vita le venticinque navi del convoglio **e impediva di potarle** |
| 7 | Caricamenti, accessibilità, fonti | Gli `.htaccess` che proteggevano i caricamenti **non venivano letti da nessuno** |

Il filo è uno solo, ed è il motivo per cui vale la pena raccontarlo: quasi
nessuno di questi difetti si vede leggendo il codice. Quel codice era giusto.
Non girava, o girava nel posto sbagliato.

### I numeri

| | |
|---|---|
| Righe di codice | ~36.000, 283 file |
| Rotte HTTP | 91 |
| Tabelle / migrazioni | 45 / 38 |
| Prove automatiche | **32 file, 906 controlli, tutti verdi** |
| Costo del battito | 40 ms per battello in mare; a cinquanta battelli, il 3,5% del minuto |
| Crescita del database | ~146 MB l'anno con cinquanta giocatori attivi, in equilibrio |

La suite copre la simulazione (griglia, astronomia, consumi calibrati sui dati
storici, acustica, avvistamento, siluri), l'equità fra ritmi di collegamento
diversi, i casi limite, la matrice dei permessi con sei identità, gli ordini
malformati, i caricamenti ostili, la potatura, e il JavaScript di bordo guidato
da un browser vero — compresa la carta su uno schermo da telefono.

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

Trentadue file — ventuno che girano in PHP e undici che interrogano il server
attraverso Apache — per **906 controlli**. Si aspettano un'installazione
funzionante e un database raggiungibile. Le end-to-end creano e cancellano da
sé i propri account di prova (`prova *`), e la suite intera lascia gli account
veri esattamente com'erano: è verificato confrontando l'impronta del battello e
del comandante prima e dopo.

```bash
php tests/test_sim.php               # griglia, astronomia, consumi, orologio
php tests/test_incontro.php          # incontro tattico e accredito degli affondamenti
php tests/test_danni.php             # danno persistente, ritardatarie, convogli dispersi
php tests/test_compartimenti.php     # falle, pressione, squadra di falla, paratie
php tests/test_vista.php             # quello che si vede davvero dalla stazione d'attacco
php tests/test_profilo.php           # fascicoli, ritratti, emblemi, unicità
php tests/test_date.php              # forma italiana delle date e fuso orario
php tests/test_coerenza.php          # promesse a vuoto: manopole morte, pannelli inerti
php tests/test_viste.php             # annidamento dei form, tele con alternativa, titolo di pagina
php tests/test_coste.php             # coste, porti, navigabilità
php tests/test_posta.php             # coda di posta e ritentativi
php tests/test_concorrenza.php       # lucchetti e avanzamento serializzato
php tests/test_segnaposto.php        # sagome e registro delle fonti
php tests/test_username.php          # validatore dei nomi utente
php tests/test_equita.php            # lo stesso mondo comunque ci si colleghi
php tests/test_limiti.php            # casi limite: riserve finite, quota di collasso
php tests/test_siluri.php            # ricarica dei tubi, contenitori di coperta, siluri guasti
php tests/test_pedinamento.php       # ordine di pedinamento del BdU, dalla radio all'incasso
php tests/test_due_battelli.php      # due giocatori sullo stesso convoglio: una nave, un affondamento
php tests/test_apparati.php          # gli apparati del cantiere fanno quello che promettono
php tests/test_potatura.php          # il mondo non cresce per sempre: incontri chiusi, contatti, naviglio

bash tests/e2e_auth.sh               # registrazione, conferma, accesso
bash tests/e2e_recupero.sh           # password dimenticata: collegamento, cambio, sessioni chiuse
bash tests/e2e_navigazione.sh        # dalla base al mare e ritorno
bash tests/e2e_congedo.sh            # congedo dal servizio attivo, dal modulo all'albo d'oro
bash tests/e2e_admin.sh              # pannello di amministrazione
bash tests/e2e_mensa.sh              # mensa ufficiali: flottiglia e moderazione
bash tests/e2e_mondo.sh              # stanza dei bottoni, registro, carta navigabile
bash tests/e2e_permessi.sh           # sei identità contro tutte le rotte: chi non deve entrare, non entra
bash tests/e2e_ordini_storti.sh      # ordini assurdi a ogni postazione: niente 500, niente stati impossibili
bash tests/e2e_caricamenti.sh        # quello che i giocatori portano da casa: polyglot, SVG, bombe, esecuzione
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
  mondo, meccaniche, formule, scelte e perché. Questo README ne è il sunto;
  lì ci sono i conti.
- [docs/FONTI.md](docs/FONTI.md) — il registro delle fonti storiche: da dove
  viene ogni numero, e quanto è affidabile.

Il registro degli audit (`docs/AUDIT.md`) resta nell'installazione e non in
questo repository: parla di quella macchina, dei suoi percorsi e dei suoi
provvedimenti, e non del gioco.

## Stack

PHP 8.4 senza framework · MariaDB 11 · Apache · JavaScript vanilla + Canvas
(nessun build step) · simulazione autoritativa lato server, con tick da cron e
avanzamento pigro deterministico.

Un solo front controller, PSR-4 senza autoloader generato, migrazioni
idempotenti, CSRF imposto nel router e non lasciato al singolo controller,
password in Argon2id, gettoni conservati solo come impronta. Il mondo è una
funzione pura del seme e dell'istante: meteo, astronomia e caso sono
riproducibili, e le forzature dell'amministratore stanno in uno strato sopra il
modello, non dentro.

Nessuna dipendenza: niente Composer, niente npm, niente CDN. La politica dei
contenuti non ammette JavaScript in linea.

## Licenza

[GNU General Public License v3.0](LICENSE).

I dati storici (tipi di U-Boot, classi di naviglio, rotte dei convogli, meteo,
sagome ONI 208) vengono da fonti pubbliche documentate in
[docs/FONTI.md](docs/FONTI.md); le sagome ONI sono di pubblico dominio
(US Navy). Gli emblemi sono disegni originali e seguono la licenza del progetto.
