# Atlantik — registro delle fonti

Ogni dato storico usato nei seed porta un campo `fonte` e, quando serve, un `confidence`.
Dove la letteratura da' valori discordi si registra **l'intervallo**, non un numero medio
inventato. Questo file tiene il conto di cosa e' stato verificato, da dove, e cosa resta
da controllare.

## Metodo

1. Nessun valore entra nei seed senza una riga qui.
2. I valori incerti sono marcati `confidence: low|medium|high` e riportati con intervallo.
3. Le discrepanze fra fonti vanno annotate, non appianate in silenzio.
4. Quando il gioco si discosta dalla storia per scelta di design (vedi DESIGN.md §0.1),
   lo scarto va dichiarato qui e mostrato in interfaccia.

## Verificato e usato in F1 (17/09/2026)

### Curva del consumo di nafta — calibrata, non inventata
Il consumo orario e' modellato come `a + b·v³` (consumo fisso di bordo piu' potenza
proporzionale al cubo della velocita'). Le due costanti si ricavano risolvendo il sistema
sulle **due autonomie documentate del Tipo VII C**: 8.500 nm a 10 nodi e 6.500 nm a 12 nodi,
con 113,5 t di nafta. Ne escono a ≈ 0,029 t/h e b ≈ 1,04·10⁻⁴.

**Controprova indipendente**: la stessa curva, estrapolata a 17,7 nodi (velocita' massima in
superficie), predice **3.304 nm** di autonomia. Le fonti riportano circa 3.250 nm per il
VII C a tutta forza. Lo scarto e' sotto il 2%: la calibrazione regge su un dato che non e'
stato usato per costruirla. `confidence: alta` per il VII C e il VII B (due punti documentati),
`media` per gli altri tipi (un solo punto, proporzione fissa/variabile ereditata dal VII C).

### Curva di scarica della batteria
Modellata come `consumo di bordo + c·v⁴`. La quarta potenza — e non il cubo — perche' le
batterie al piombo perdono capacita' con l'intensita' di scarica (effetto Peukert).
Calibrata sull'autonomia subacquea documentata (VII C: 80 nm a 4 nodi = 20 ore), predice
**1,7 ore** alla massima velocita' subacquea di 7,6 nodi, contro l'ora e mezza abbondante
riportata dalle fonti. `confidence: media`.

### Geometria della griglia Marinequadrat — verificata
Grande quadrato di **486 miglia nautiche / circa 900 km di lato**, suddivisione ricorsiva in
matrici 3×3 numerate 1-9 per righe, **quattro cifre** che portano alla precisione di **circa
6 miglia** (486 → 162 → 54 → 18 → 6). Le sigle dei grandi quadrati sono di due lettere e
l'Atlantico e' coperto per righe, da ovest a est e da nord a sud, a partire dalla Groenlandia.
Fonti: voci enciclopediche su *German Naval Grid System* / *Marinequadrat*.

### Marinequadrat — QUESTIONE APERTA, la piu' importante
**La tabella completa delle sigle non e' documentata in nessuna fonte accessibile.** Quello
che si e' potuto ancorare:

| Sigla | Ancoraggio | Stato |
|---|---|---|
| AJ | "major grid AJ is located south of Greenland" | contenimento verificato |
| AN | Mare del Nord, comprende Helgoland; esempio storico "AN 1879" | contenimento verificato |
| BE | posizione finale della Bismarck, "BE 6192" (circa 48N 16W) | contenimento verificato |
| BF | Golfo di Biscaglia, corridoio delle flottiglie atlantiche | contenimento verificato |

Le altre 108 sigle in `db/seed/marinequadrat.php` sono **ricostruite**: occupano la posizione
giusta nel reticolo ma la sigla non e' storica, ed e' marcata `confidence: ricostruita`.
Va inoltre verificato il livello di dettaglio: le quattro cifre di "BE 6192" non cadono,
con il reticolo attuale, esattamente dove cadeva la Bismarck — segno che l'allineamento
delle bande va corretto di uno-due gradi. **Serve una riproduzione della carta quadrettata
della Kriegsmarine.** Correggerla e' una modifica di dati, non di codice.

## Verificato e tarato in F2 (17/09/2026)

### Composizione dell'equipaggio
Ruolino modellato sui Tipo VII: quattro ufficiali (I.WO, II.WO, Leitender Ingenieur,
Obersteuermann), una quindicina di sottufficiali, il resto marinai e macchinisti. Tre guardie
da quattro ore; il LI, il radiotelegrafista, il nostromo e il cuoco stanno **fuori dai quarti**
e lavorano a giornata, come da organizzazione di bordo reale. Le tre guardie di coperta sono
guidate rispettivamente dal I.WO, dal II.WO e dall'Obersteuermann. `confidence: media`.

### Frequenza delle avarie
Le probabilita' di guasto per ora di esercizio sono scelte perche' una patrol lunga (quattro
settimane, circa 700 ore) produca **quattro-cinque avarie**: e' l'ordine di grandezza che
emerge dai giornali di guerra, pieni di piccoli guasti e ogni tanto di uno serio. Sono
moltiplicate per l'usura della missione (+3% al giorno dopo la prima settimana), il regime
delle macchine (a tutta forza i diesel si guastano quasi tre volte tanto), il mare grosso e la
qualita' dell'equipaggio di quella specialita'. `confidence: bassa` sui valori assoluti — e'
bilanciamento, non un dato storico, ed e' dichiarato come tale (`damage.rate_scale` e'
regolabile a caldo).

### Cosa non si ripara a mare
Periscopi e scafo resistente sono marcati `repairable_sea = 0`: un periscopio piegato o
allagato e uno scafo deformato richiedono il cantiere. E' la regola che trasforma un'avaria
in una decisione — proseguire mezzi ciechi o rientrare.

### Danno da pressione
Sotto la quota di prova lo scafo accumula sollecitazione con andamento quadratico rispetto
all'eccesso di quota (3·x + 9·x², con x = 0 alla quota di prova e 1 all'inizio dell'intervallo
di collasso). La sollecitazione accumulata **abbassa in modo permanente la quota di collasso**
e il cantiere ne recupera solo una parte. Modello di gioco, non formula d'ingegneria:
riproduce il fatto documentato che i battelli "invecchiavano" scendendo.

### Propulsione di emergenza
Con entrambi i diesel fuori uso il battello non resta fermo: procede in superficie coi motori
elettrici a velocita' ridottissima, consumando batteria. Era una soluzione disperata ma reale
per rientrare.

## Verificato e tarato in F3 (18/09/2026)

### Portate idrofoniche — il numero che decide il gioco
Modello classico del sonar passivo (SNR = SL − TL − NL + DI − rumore proprio), tarato perche'
riproduca gli ordini di grandezza dei giornali di guerra:

| Bersaglio | mare 1 | mare 4 | mare 6 | mare 8 | sotto lo strato |
|---|---|---|---|---|---|
| Convoglio di 40 navi a 9 nodi | 56 nm | **33 nm** | 21 nm | 12 nm | 11 nm |
| Mercantile isolato a 9,5 nodi | 25 nm | 11 nm | 6 nm | 3 nm | 2 nm |
| Cacciatorpediniere a 20 nodi | 50 nm | 29 nm | 18 nm | 10 nm | 9 nm |

Il valore di riferimento storico e' il convoglio udibile a **trenta-cinquanta miglia** con mare
moderato. Conseguenza voluta del modello: alla velocita' di 6 nodi il rumore proprio sale a 19 dB
e la portata scende a 9 miglia — **chi corre sott'acqua e' sordo**, che e' esattamente la ragione
per cui si stava in ascolto a due nodi.

### Portate visive
Formula dell'orizzonte geometrico D = 2,08·(√h₁+√h₂) in miglia nautiche con altezze in metri,
moltiplicata per un fattore di luce e uno di sagoma, e **mai superiore all'orizzonte**.
Ne escono: U-Boot in superficie visto da una scorta a **1,7 nm di notte senza luna**, 4,1 nm con
luna piena, 11,9 nm di giorno; periscopio a 2 nm di giorno. Sono i valori riportati dalle fonti
sulla scoperta notturna in superficie. Radar centimetrico alleato: torretta a 8-9 nm con mare
calmo, 2 nm con mare 8 (il clutter nasconde).

### Traffico e convogli
Serie, velocita' e consistenza storiche: HX 9,5 nodi (veloce, da Halifax), SC 7 nodi (lento, da
Sydney), ON/ONS in uscita, OG/HG per Gibilterra, SL da Freetown, TM petroliere da Trinidad. Le
serie si estraggono con **peso inverso all'intervallo di partenza** (gli HX salpavano ogni sei
giorni, i TM ogni sedici), altrimenti il mare avrebbe una composizione falsa. Rotte spezzate in
punti di passaggio al largo: Halifax-Liverpool risulta di 2.437 nm, cioe' 10,7 giorni a 9,5 nodi —
i convogli reali impiegavano 11-13 giorni. `confidence: media`.

### Scelta architetturale: il traffico non si salva, si calcola
Come per il meteo, la posizione di ogni nave e' una **funzione pura** di rotta, velocita' e ora di
partenza. A database finiscono solo gli avvenimenti (partenza, arrivo, affondamento). Costo
misurato: 1,7 microsecondi per posizione, cioe' circa 56 ms di calcolo per un avanzamento di 24
ore di gioco con 113 unita' in mare.

## Verificato e tarato in F4 (18/09/2026)

### Siluri — prestazioni e difetti
Sei tipi storici con le loro regolazioni reali: **G7a (T I)** a vapore con tre andature
(30 nodi/12.500 m, 40/7.500, 44/5.500) e scia visibile; **G7e (T II)** elettrico senza scia,
30 nodi per 5.000 m; **T III** con gittata portata a 7.500 m; **FAT** e **LUT** a corsa
programmata; **G7es T5 Zaunkoenig** acustico (24,5 nodi, 5.700 m, efficace fra 10 e 18 nodi di
velocita' del bersaglio). Testata 280 kg.

I tassi di difetto traducono in numeri la **crisi dei siluri**: cilecca della spoletta,
scoppio prematuro (molto piu' frequente con la magnetica), corsa piu' profonda del regolato —
quest'ultima fa passare il siluro sotto la chiglia senza toccarla, che e' esattamente il
rapporto che i comandanti scrivevano nel 1940. `confidence: media` sui valori assoluti,
regolabili con `combat.qualita_siluri`.

### Soluzione di tiro — sensibilita' misurata
Il calcolatore risolve il triangolo d'intercetto in forma chiusa. La prova statistica (60 lanci
per riga, mercantile di 5.100 GRT a 9 nodi, G7e a 30 nodi) da':

| Condizione | Colpito |
|---|---|
| Soluzione perfetta, 1.000 m | 88% |
| Soluzione perfetta, 2.500 m | 72% |
| Errore di 2 nodi sulla velocita', 1.000 m | 85% |
| **Errore di 2 nodi sulla velocita', 2.500 m** | **0%** |
| Errore di 10 gradi sull'AOB, 1.500 m | 90% |
| Stima tipica del I.WO, 1.500 m | 80% |

L'ultima riga della tabella e' la dottrina: **due nodi di errore a 2.500 metri sono un colpo
mancato, a 1.000 metri no**. E' il motivo per cui si insegnava ad avvicinarsi invece di sparare
da lontano, ed emerge dalla geometria, non da una regola scritta a mano.

### Danno e affondamento
Un colpo sotto la chiglia (spoletta magnetica) affonda il 97% dei piroscafi da 2.400 GRT, il 64%
di quelli da 5.100, il 39% di quelli da 8.200 e il 3% delle petroliere T2 da 16.600 — che ne
chiedono tre o quattro. Tempi di affondamento fra i dieci minuti e l'ora. Le petroliere cariche
di benzina bruciano; le navi in zavorra e quelle cariche di legname restano a galla molto piu' a
lungo; il carico di munizioni puo' far sparire la nave in pochi secondi.

### ASDIC: l'angolo cieco che salvava i battelli
Due limiti modellati, entrambi documentati: il **cono cieco** sotto la nave (negli ultimi 200 m
dell'accosto il fascio passa sopra il bersaglio, e le cariche si lanciano alla cieca su una
posizione prevista) e soprattutto l'**angolo**: l'ASDIC lavorava quasi orizzontale e non sapeva
guardare in basso. Oltre i 32 gradi sotto l'orizzontale il contatto si perde — a 120 metri di
quota basta che la scorta arrivi a 190 metri perche' il battello esca dal fascio. E' il motivo
per cui scendere in profondita' funzionava, e per cui gli alleati inventarono le armi a lancio in
avanti (Hedgehog, Squid), che colpiscono senza perdere il contatto.

### L'immersione per allarme aereo e' temporanea
Quando un aereo compare, il Primo Ufficiale immerge senza aspettare ordini — ma dopo mezz'ora
riporta il battello a galla. Senza questo, un battello immerso per allarme restava sotto fino a
esaurire le batterie: un modo stupido di perdere un battello, e storicamente falso (si tornava su
appena il cielo era libero, perche' sott'acqua non si va da nessuna parte).

### Cariche di profondita'
Raggio letale 7 m, raggio di danno 18 m (Mk VII con Torpex), velocita' di discesa 3,5 m/s.
La quota di scoppio e' una stima dell'avversario, con un errore sistematico **verso il basso**
(gli alleati sottovalutavano la profondita' raggiungibile dagli U-Boot) piu' un errore casuale
che cresce con la quota. Il punto di lancio e' una previsione di dove saremo, non una fotografia
di dove siamo: manovrare dopo che la scorta si e' impegnata nell'accosto e' la contromisura.

## Verificato e tarato in F5 (18/09/2026)

### Decorazioni e criteri
Nove decorazioni con i criteri di conferimento: Croce di Ferro di seconda e prima classe,
**U-Boots-Kriegsabzeichen** (due missioni di guerra, o una sola se coronata da successo — questo
criterio e' documentato, `confidence: alta`), Croce Tedesca in oro, **Ritterkreuz** e i suoi tre
gradi successivi. Le soglie di tonnellaggio sono **indicative e dichiarate come tali**: la Croce di
Cavaliere non si otteneva per tabella ma per proposta del BdU, e i criteri si inasprirono nel corso
della guerra. Qui si usa la soglia dei primi anni (circa centomila tonnellate), `confidence: bassa`.
I Brillanti restano quasi irraggiungibili, come nella realta': fra i sommergibilisti li ricevettero
due soli comandanti.

### Gradi
Nella realta' i comandanti di U-Boot erano quasi tutti Oberleutnant o Kapitaenleutnant; i gradi
superiori comandavano flottiglie, non battelli. Qui si sale piu' in alto perche' la carriera del
giocatore e' una sola e deve durare — **scarto dichiarato**, non errore.

### Le tre valute
**Ansehen** (prestigio, il merito: sblocca), **Zuteilungspunkte** (punti di assegnazione, la
priorita' in cantiere: permette di avere davvero il pezzo sbloccato) e **Reichsmark** (paga
personale, per licenze e comfort). La separazione fra merito e logistica e' storicamente onesta:
un comandante decorato non riceveva un battello nuovo se il cantiere non ne aveva.

### Catena di sopravvivenza
Probabilita' di riuscire ad abbandonare il battello secondo la quota al momento del colpo: 72% in
superficie, 45% entro 25 m, 18% entro 60 m, 5% entro 110 m, **zero oltre**. Chi esce deve poi
sopravvivere in acqua — nel Nord Atlantico d'inverno chi non viene raccolto entro mezz'ora non
viene raccolto piu' — e qualcuno deve volerlo raccogliere (le scorte a volte lo facevano, a volte
no). L'esito tipico e' quello storico: **nessun superstite**.

### Artefatto noto del calendario ciclico
Il mondo e' ancorato a un anno fisso (decisione di progetto §0.1) e la data mostrata gira dentro
quell'anno. Una missione che attraversa il capodanno appare percio' con il rientro "prima" della
partenza. La durata resta corretta; e' un difetto cosmetico del periodo fisso, accettato e
documentato.

## Verificato e tarato in F6 (18/09/2026)

### HF/DF — il prezzo di ogni trasmissione
Ricevere e' quasi gratis (onde lunghissime, si prendono anche immersi); **trasmettere** richiede
l'antenna fuori e paga il conto. Ogni unita' di scorta con apparato radiogoniometrico entro 160
miglia tenta il rilevamento, con una probabilita' che dipende dalla **durata dell'emissione**:

| Antenna | Probabilita' per apparato |
|---|---|
| 22 s (segnale breve) | 47% |
| 34 s (rapporto meteo) | 56% |
| 60 s | 76% |
| oltre 130 s | 95% |

Un solo rilevamento da' una direzione (errore 60-140 nm); due danno un punto (20-60 nm); tre o
piu' lo stringono a 8-25 nm e fanno dirottare un gruppo di caccia. E' questo il motivo storico dei
**Kurzsignale**: non rendevano invisibili, rendevano difficili. Il punto radiogoniometrico alza il
calore del settore, che in F3 governa la copertura aerea e la qualita' delle scorte: la catena
"trasmetto → mi trovano → il cielo si popola" e' chiusa e visibile in gioco.

### Rudeltaktik
Il BdU apre finestre operative di 72 ore su uno sbarramento (nomi storici dei gruppi: Raubgraf,
Drossel, Westmark, Veilchen...). Chi tiene il contatto e lo **segnala** guadagna prestigio
(`branco.premio_contatto`, 180 punti) anche senza affondare nulla — altrimenti nessuno farebbe mai
il Fuehlungshalter, perche' pedinare non porta tonnellaggio. Gli altri membri ricevono il contatto
di seconda mano (sensore `radiogoniometro`, precisione inferiore al proprio). Il convoglio e' lo
stesso per tutti e si logora davvero. Il branco resta **facoltativo**: chi caccia da solo non ha
obblighi.

### Rifornimento dal Tipo XIV
Richiesta via radio (quindi si paga subito in radiogoniometria), appuntamento a 180-420 miglia con
finestra di tre giorni, trasferimento solo in superficie con mare fino a forza 5. La probabilita'
che l'appuntamento sia **compromesso** cresce col calore del settore: e' il modo in cui il gioco
rappresenta Ultra, che nel 1943 permise agli alleati di sterminare quasi tutte le vacche da latte.

## F7 (18/09/2026) — rifinitura e strumenti di verifica

### Il cruscotto di bilanciamento
`php bin/console.php balance:report` rimisura i modelli e li confronta con le bande storiche
attese, segnalando chi esce dai limiti. E' il modo per accorgersi che una modifica ha spostato
qualcosa che non doveva spostarsi. Bande verificate: autonomia del Tipo VII C (8.300-8.700 nm a 10
nodi, 3.100-3.500 a tutta forza, 19-21 ore a 4 nodi immersi), portate idrofoniche, avvistamento
notturno e diurno, percentuali di affondamento con un colpo per classe di stazza, vento medio
stagionale, probabilita' di intercettazione radiogoniometrica.

### Trofei: distinti dalle decorazioni, di proposito
Le decorazioni sono storiche e le conferisce il BdU secondo criteri d'epoca; i trofei premiano il
modo di giocare (l'agguato silenzioso, la disciplina radio, il mestiere del navigatore). Tenerli
separati evita di inventare medaglie mai esistite per premiare comportamenti da videogioco.

### Applicazione installabile: cosa fa e cosa non fa
Il service worker mette in cache **solo il guscio** (fogli di stile, script, icone). Non tenta di
far girare il gioco senza rete: un mondo persistente senza server non e' un gioco, e' una bugia —
senza collegamento si apre una pagina che lo dice. Le notifiche usano l'API del browser e
funzionano **solo con la pagina aperta**: la notifica a browser chiuso richiederebbe un servizio di
push con chiavi VAPID, che non e' implementato, e l'interfaccia non finge il contrario.

### Audio
Nessun file sonoro: i suoni (ping ASDIC, campanello d'allarme, scoppio, contatto) sono sintetizzati
in tempo reale dal browser. Sono pochi, discreti, spenti per difetto e con un interruttore nella
barra — a bordo si sta zitti.

## Aree da documentare

| Area | Stato | Note |
|---|---|---|
| Specifiche Type II / VII / IX / XIV / XXI | da compilare | dislocamento, velocita', autonomia, quote, tempi di immersione, equipaggio |
| Siluri G7a / G7e / T III / FAT / LUT / T5 | da compilare | velocita', gittate, spolette, tassi di avaria documentati |
| Artiglieria 8,8 / 10,5 / flak 2 cm e 3,7 cm | da compilare | dotazioni munizioni, limiti di mare |
| Sensori tedeschi (GHG, KDB, Metox, Naxos, FuMO) | da compilare | bande, portate, date di introduzione |
| ASW alleato (ASDIC, radar ASV/271, Hedgehog, Squid, HF/DF, Leigh Light) | da compilare | portate, dottrine, limiti |
| Cariche di profondita' Mk VII | da compilare | velocita' di discesa, raggi di danno |
| Serie e composizione convogli (HX, SC, ON/ONS, OG/HG, SL, TM) | da compilare | velocita', numero navi, scorta tipica, rotte |
| Classi di scorta (Flower, V&W, Town, River, sloop) | inserite (22 classi in `db/seed/ship_classes.php`) | GRT, velocita', sensori; `rumore_db` e' taratura, non misura |
| Flottiglie e basi (Brest, Lorient, St. Nazaire, La Pallice, Bordeaux, Kiel, Bergen) | da compilare | assegnazioni, bunker |
| Gradi, decorazioni e criteri di conferimento | da compilare | soglie storiche indicative |
| Ruoli e organizzazione dell'equipaggio | modellata (vedi sopra) | da verificare su ruolini originali |
| Tempi reali di riparazione a bordo | approssimati | 1,5-9 ore secondo il sistema e la gravita' |
| Vita di bordo (viveri, acqua, CO2, temperatura) | da compilare | consumi e tempi |
| Griglia Marinequadrat | geometria verificata, sigle da verificare | vedi sopra |
| Correnti atlantiche (Golfo, Labrador, Canarie, equatoriale) | approssimate | nuclei gaussiani su assi idealizzati; velocita' di picco 2 nodi alla Corrente del Golfo |
| Climatologia del vento e del mare nord-atlantico | tarata sui valori attesi | gennaio: media 21,6 kn, burrasche 14%; luglio: media 13,4 kn, burrasche 0,7%; nebbia sui Banchi di Terranova 13-17% in estate |

## Tavolo di carteggio — aspetto della carta (settembre 2026)

La tavolozza e la composizione del tavolo di carteggio (mare graduato, terre in
ocra, cornice di pergamena, cartiglio tedesco, rosa dei venti, scala grafica a
blocchi) sono prese da una ricostruzione moderna della carta di navigazione
della Kriegsmarine per l'Atlantico. **Solo l'aspetto**: la geometria di quella
immagine e' stata misurata e scartata — meridiani non equidistanti fino a 138
miglia nautiche di scarto, Islanda disegnata 10° troppo a est (255 nm). La prova
e' in `docs/AUDIT.md`, sezione 16.

Quello che si vede sulla carta del gioco e' calcolato:

- proiezione di Mercatore vera, con inversa, ricalcolata a ogni disegno;
- graduazione della cornice con passo scelto sui pixel disponibili, non fisso;
- scala grafica in miglia nautiche e chilometri, esatta a qualunque
  ingrandimento (il rapporto "1 : N" del cartiglio e' invece dichiarato
  *indicativo*: dipende dai punti per pollice dello schermo, che il browser non
  espone in modo attendibile);
- reticolo Marinequadrat con le sigle dei grandi quadrati, dalla stessa tabella
  che usa il motore (`db/seed/marinequadrat.php`).

**Deviazione dichiarata — piattaforma continentale.** L'alone d'acqua chiara
lungo le coste e' *decorativo*: nel motore non esiste un modello di profondita'
del fondale, e quindi nemmeno un'isobata da disegnare. Che la piattaforma segua
la costa e' vero in generale; la sua larghezza sulla carta non lo e'. Nessuna
meccanica di gioco legge quel disegno.

**Deviazione dichiarata — linee di costa.** Restano le 183 posizioni tracciate a
mano a risoluzione 1-2 gradi: bastano per orientarsi al largo, non per la
navigazione costiera. Sostituirle con un rilievo reale (Natural Earth 1:50m,
pubblico dominio) e' una modifica di dati, non di codice.

## Baffa del periscopio (settembre 2026)

Che il periscopio alzato fosse un rischio e non un dettaglio e' documentato:
nei manuali di caccia antisommergibile alleati la "feather" — la scia bianca
lasciata dal tubo in corsa — e' descritta come il modo piu' comune di
individuare un battello in avvicinamento con mare calmo, e le vedette erano
addestrate a cercarla. Da qui le due regole del modello:

- l'effetto cresce con la velocita': e' un fenomeno d'onda, e la resistenza
  d'onda va col quadrato della velocita';
- l'effetto sparisce col mare formato: sopra forza 4 le creste e le pecore
  bianche coprono qualunque scia.

**Deviazione dichiarata.** Il *valore* del fattore (fino a circa ×2,7 sulla
sagoma, a 6 nodi con mare 1) non viene da una fonte numerica: non e' un dato
che qualcuno abbia misurato e pubblicato. E' una taratura scelta perche'
produce l'effetto di gioco giusto — a velocita' d'attacco su mare liscio il
periscopio alzato a lungo si paga, a bassa velocita' o su mare mosso quasi no —
e resta modificabile in un punto solo (`Detection::baffaPeriscopio`).

La sagoma di base del periscopio alzato (`S_PERISCOPIO`) e le portate visive
restano quelle gia' tarate in F3.

## Coda della posta (settembre 2026)

Nessuna fonte storica: e' esercizio. Il tetto di 300 invii al giorno e' il
limite del piano gratuito Brevo, dichiarato dal fornitore; la soglia
prudenziale di 280 e la scala delle attese (1, 5, 15, 60, 180, 360 minuti) sono
scelte operative, non misure, e si cambiano da `game_config` senza toccare il
codice.

## Linee di costa — Natural Earth (18 settembre 2026)

**Fonte.** `ne_50m_land.geojson` dal repository ufficiale di Natural Earth
(nvkelso/natural-earth-vector). Natural Earth e' di **pubblico dominio**: non e'
dovuta nessuna attribuzione. La diamo lo stesso, perche' e' giusto cosi' e
perche' un dato senza provenienza, fra un anno, e' un dato di cui non ci si fida.

La copia lavorata sta in `db/seed/ne_50m_land.geojson.gz` (518 KB): cosi' la
carta si rigenera senza rete e senza sperare che un indirizzo sia ancora al suo
posto. Non e' raggiungibile dal web (verificato: 403).

**Lavorazione** — `php bin/genera_coste.php`, tre passaggi:

1. **ritaglio** al teatro atlantico (108°O..44°E, 62°S..83°N) con
   Sutherland-Hodgman. I lati nati dal taglio vengono marcati: sulla carta si
   riempiono ma non si disegnano, perche' non sono coste. Senza questo, lungo
   il 108° meridiano comparirebbe una spiaggia dritta;
2. **semplificazione** Douglas-Peucker a **0,03°** (~3,3 km). E' sotto il pixel
   a qualunque ingrandimento utile della carta (al massimo zoom un pixel vale
   0,025°, e la carta non si spinge oltre);
3. **scarto** degli anelli sotto **0,004 gradi quadrati**, che sulla carta
   sarebbero un punto. Le Azzorre, Madera, le Canarie e le Faer Oer restano.

Risultato: 494 anelli, 13.086 vertici, 180 KB (65 KB compressi in transito).

**Verifica di posizione** — le stesse misure con cui e' stata bocciata la carta
illustrata (`docs/AUDIT.md`, punto 16), adesso applicate al dato nuovo:

| Terra | Sulla carta | Reale | Scarto |
|---|---|---|---|
| Islanda | 63,41..66,52 N / 24,48..13,56 O | 63,39..66,53 / 24,54..13,50 | 0,06° (4 nm) |
| Gran Bretagna | 50,02..58,65 N / 6,13 O..1,75 E | 49,96..58,64 / 6,22 O..1,76 E | 0,09° (5 nm) |
| Terranova | — | — | 0,13° (8 nm) |
| Cuba | — | — | 0,07° (4 nm) |

Lo scarto e' la tolleranza di semplificazione, non un errore: la carta
illustrata metteva l'Islanda 10° (255 miglia) fuori posto.

`tests/test_coste.php` rimisura tutto a ogni esecuzione, e verifica anche che
le nove basi di flottiglia cadano dove c'e' la terra — cioe' che il dato della
carta e quello del motore parlino dello stesso pianeta. Bordeaux sta cinquanta
miglia su per la Gironda e Trondheim in fondo a un fiordo: la prova lo sa, e
chiede terra entro trenta miglia e mare raggiungibile entro centoventi.

**Cosa NON viene da Natural Earth.** I nomi sulla carta
(`assets/js/etichette.js`) sono scritti a mano, posizionati dove stanno senza
coprire niente. Natural Earth porta le geometrie, non la tipografia.

## La carta illustrata come fondale (18 settembre 2026)

La ricostruzione della carta di navigazione della Kriegsmarine — quella misurata
e bocciata come supporto geodetico in `docs/AUDIT.md`, punto 16 — sta ora dietro
alle **pagine in cui non si misura niente**: ingresso, arruolamento, accesso,
conferma dell'indirizzo, sala della flottiglia, cantiere, albo d'oro,
statistiche, trofei, bacheca, pagina offline.

**Dove non compare, e perche'.** Sulle nove postazioni di plancia (Zentrale,
tavolo di carteggio, ascolto, attacco, battello, equipaggio, radio, BdU, KTB) e
nel pannello di amministrazione il fondale non c'e'. La' la carta e' uno
strumento, e un'immagine che sbaglia l'Islanda di 255 miglia non deve stare
nella stessa schermata di una posizione calcolata: il rischio non e' che
inganni il motore — non puo' — ma che inganni l'occhio. L'elenco delle
postazioni sta in `App\Core\View::sfondo()`, ed e' una regola sola da leggere.
`tests/e2e_navigazione.sh` verifica entrambe le meta': che sulla plancia non ci
sia e sulle pagine di terra ci sia.

**Lavorazione dell'immagine.** L'originale (1536×1024, 3,2 MB) viene ridotto a
1600 px e 820 px, desaturato al 52%, sfocato (1,6 px e 0,9 px) e smorzato di
sei punti di luminosita', poi salvato in WebP: **59 KB e 24 KB**, cioe' 61 e 25
KB in transito. La sfocatura e' deliberata e fatta **nel file**, non a schermo:
un fondale non deve competere col testo, e sfocare a ogni ridisegno si paga a
ogni ridisegno mentre qui si paga una volta sola. Rende anche illeggibile il
cartiglio dell'immagine, che e' esattamente cio' che si vuole da un fondale.

Sopra all'immagine sta un velo scuro con vignettatura — piu' fitto al centro,
dove sta il testo — il cui colore segue l'illuminazione di bordo: di notte il
fondale resta un'ombra rossastra. I pannelli hanno fondo semitrasparente e
`backdrop-filter`, cosi' si staccano invece di galleggiare.

**Non e' nell'esportazione del giornale di guerra.** Il KTB si esporta come
testo semplice, e un testo semplice non ha copertine. Era nell'elenco delle
possibilita' del punto 16: si dichiara qui che non si fa, invece di lasciarlo
sospeso.

## Tratto della carta — due stili, una geometria (18 settembre 2026)

Il tavolo di carteggio si disegna in due modi, a scelta del giocatore
(`users.preferenze`, chiave `carta.stile`):

- **carta da tavolo** (di serie): terra riempita in ocra, alone della
  piattaforma continentale, nomi di terre e mari, mare in azzurri graduati;
- **minuta a inchiostro**: solo il filo di costa su carta invecchiata, nomi dei
  soli continenti. E' l'aspetto delle minute tracciate in fretta a bordo, ed e'
  anche il piu' leggero da disegnare.

**La geometria e' la stessa nei due casi.** Cambia il tratto, non il posto delle
cose: entrambi usano il rilievo Natural Earth. Non esiste — e non e' previsto —
un modo di rimettere in servizio le vecchie coste tracciate a mano: sono
sbagliate di miglia, e una carta nautica che si puo' scegliere sbagliata non e'
un'opzione, e' una trappola. Restano nella storia del repository per chi le
volesse guardare.

**Provenienza in chiaro.** Sotto il comando del tratto la pagina scrive quale
rilievo sta usando (`Coste: Natural Earth 1:50m — 494 anelli, 13.086 vertici`).
La firma la mette il generatore nel file stesso: serve a sapere cosa si sta
guardando senza doverlo dedurre dalla forma dell'Islanda, e a scoprire in un
secondo se un browser sta servendo una copia vecchia di cache.

## Figure di segnaposto (18 settembre 2026)

Quattordici figure — sagome di navi e di aerei, una di U-Boot, due onorificenze,
un'insegna di grado, un'icona — ritagliate da **due tavole di riferimento
generate**, non da documenti. Stanno nel gioco per dare una faccia alle cose, e
non per insegnare a riconoscere niente.

**La regola su dove si usano** *(rivista il 18 settembre 2026, vedi sotto)*.
Le sagome vanno nel **resoconto** — il rapporto di missione, il naviglio
affondato. La stessa figura accanto a un affondamento gia' avvenuto e' soltanto
un ricordo illustrato.

**Ogni figura esce con la sua dichiarazione**: il testo alternativo e il
suggerimento dicono sempre *"Ricostruzione, non riferimento documentale"* piu' la
nota specifica. La figura sta **accanto** al nome scritto, mai al suo posto: chi
non vede le immagini legge le stesse identiche informazioni.

**Il meccanismo**, perche' la dichiarazione non resti una buona intenzione:

| | |
|---|---|
| `db/seed/segnaposto_ritagli.php` | piano scritto a mano: cos'e' ogni figura, dove sta nella tavola, a che entita' di gioco si lega |
| `bin/taglia_segnaposto.php` | ritaglia, toglie la pergamena, riduce, salva in WebP, genera il registro |
| `db/seed/segnaposto.php` | registro generato: quello che il gioco legge |
| `tests/test_segnaposto.php` | nessuna figura sul disco senza una riga nel registro e viceversa; ogni riga dichiara soggetto e fonte; la fonte e' sempre `ricostruzione` |

Il ritaglio non usa un test sul colore ma un **riempimento dai bordi**: le
tavole sono JPEG, e attorno a ogni tratto scuro la compressione lascia un alone
che col colore di fondo non c'entra piu' niente. Un secondo passaggio, acceso
una figura alla volta, toglie le sacche di pergamena **chiuse dentro** la
sagoma: nel disegno del Tipo VII C, fra il ponte e la murata, non c'e' vernice
chiara — c'e' il foglio che si vede attraverso, e su una plancia d'acciaio
diventa un cuneo color crema. Acceso solo dove serve, perche' le navi dipinte
di chiaro hanno grigi vicini alla pergamena e scavarle le riempie di buchi.

**Cosa NON e' stato ritagliato, e perche'** — sta scritto per esteso nel piano:
classi che nel gioco non esistono (Tribal, Captain, Southampton, Bogue), aerei
fuori teatro (Hurricane, Corsair, Avenger), l'emblema di torretta di U-96
(finche' non ci sara' un modo di scegliere il proprio emblema, attaccare quello
di U-96 al battello di chiunque sarebbe una bugia gratuita), l'insegna da
Oberstabsgefreiter (grado che nell'equipaggio del gioco non esiste, e nemmeno di
foggia navale).

**Una discrepanza dichiarata invece che corretta in silenzio**: la tavola mostra
il Fregio di fronte dei sommergibili in tre gradi (bronzo, argento, oro); quello
storico era in bronzo e in argento. La nota della figura lo dice.

**Undici figure su quattordici** sono legate a un'entita' di gioco; le altre tre
sono l'insegna dell'ammiraglio in testa agli ordini del BdU, l'icona del
periscopio sul suo comando e il riquadro di griglia accanto al quadrato. Nessuna
figura resta senza un posto: un ritaglio senza destinazione fa peso e basta, e
col tempo qualcuno lo usa dove capita.

## Emblemi di torretta (18 settembre 2026)

Ogni battello puo' portare un emblema: uno dal repertorio, oppure uno caricato
dal comandante. **Lo stesso emblema puo' stare su piu' torrette**: molti segni
erano di flottiglia e non di battello. All'inizio qui c'era un vincolo di
unicita', ed era storicamente sbagliato; se n'e' andato con la migrazione 0026.
Il perche', per esteso, sta piu' avanti in «Gli emblemi si portavano in tanti».

**I disegni sono originali**, fatti per questo gioco: sagome piene e bordi netti,
come le mascherine che si dipingevano davvero sulla torretta. Non sono copie di
emblemi storici, e non lo pretendono. Dove il soggetto e' documentato la scheda
lo dice (`confidence: alta`): il ferro di cavallo di U-99 di Kretschmer, il toro
infuriato di U-47 di Prien poi passato alla 7. U-Flottille, il pesce sega di
U-96, i tre pesciolini di U-333 di Cremer. Gli altri cinque sono soggetti
plausibili per l'epoca, dichiarati `ricostruita`.

**L'unicita' se n'e' andata** (migrazione 0026): c'erano un indice `UNIQUE` su
`boats.emblema_key` e uno su `boats.emblema_hash`, e sono stati tolti tutti e
due. Al loro posto il repertorio mostra **chi altro porta** quel segno. Quello
che resta unico, e nel database, e' il numero dell'U-Boot — e per i comandanti
in servizio il nome e il ritratto preso dalla galleria.

**Il caricamento.** Il file che arriva non viene **mai** servito com'e':

- il tipo si decide guardando i byte (`getimagesize`), non l'estensione ne'
  quello che dichiara il browser — se li sceglie chi carica;
- si accettano PNG, JPEG e WebP. **Gli SVG no**: un SVG e' un documento che puo'
  contenere codice, e servirlo dallo stesso dominio del gioco vuol dire eseguirlo
  nella sessione di chi lo guarda;
- l'immagine viene riaperta, ritagliata quadrata, ridisegnata a 256 pixel e
  riscritta in WebP. Quello che finisce sul disco e' un file costruito da noi:
  niente metadati, niente code, niente di quello che poteva esserci dentro;
- il nome del file e' lo `sha256` del risultato, e quello stesso hash e' la
  chiave di unicita': la stessa identica immagine non entra due volte;
- il file si sposta al suo posto **dopo** che il vincolo ha detto di si'.

I file rimasti orfani — un account cancellato si porta via il battello per
vincolo di chiave esterna, ma non il file — vengono raccolti dalla potatura del
battito (`Emblema::potaOrfani()`), con cinque minuti di grazia per non toccare
un caricamento in corso.

**Quello che NON c'e', e va detto**: nessuna moderazione automatica delle
immagini caricate. Un emblema caricato lo vedono anche gli altri comandanti, in
flottiglia e nell'albo d'oro. Per ora l'unico rimedio e' a posteriori e manuale:
`php bin/console.php emblema:elenco` per vedere cosa gira e
`php bin/console.php emblema:rimuovi <U-xx>` per ripulire una torretta. Va bene
per un gioco fra poche persone che si conoscono; non basterebbe se si aprisse.

## Profili documentali — ONI 208 (18 settembre 2026)

Cinque sagome di naviglio mercantile non sono piu' ricostruzioni: vengono dai
profili dell'**ONI 208 — Merchant Ship Recognition Manual**, Division of Naval
Intelligence, US Navy. Opera del governo degli Stati Uniti, **pubblico dominio**
(l'esemplare digitalizzato su Internet Archive porta il Public Domain Mark 1.0).
Sono disegni fatti per essere confrontati con una nave vera a distanza: la
ragione per cui esistono e' esattamente l'accuratezza.

| Classe del gioco | Profilo | Pagina |
|---|---|---|
| `cargo_medio` | piroscafo a tre isole *Clan Macdougall* | 29 |
| `petroliera_media` | petroliera a motrice poppiera *Trontolite* | 47 |
| `petroliera_t2` | petroliera di squadra *Cadillac / Saranac* | 47 |
| `trasporto_truppe` | transatlantico P&O *Strathaird / Strathnaver* | 43 |
| `transatlantico` | *Empress of Britain* | 43 |

Ogni profilo e' di una **nave precisa, col suo nome**: la nota della figura dice
sempre quale nave e' e quanto era grossa davvero, perche' la stazza della classe
del gioco non coincide. Il profilo sta per il tipo di nave, non per il
tonnellaggio.

Le tre pagine lavorate sono nel progetto (`db/seed/oni/`, rese a 150 punti per
pollice, 1,5 MB l'una): il ritaglio si rifa' senza riscaricare i 52 MB del
manuale.

**Il registro ora distingue due fonti** — `ricostruzione` e `documentale` — e la
prova pretende che chi si dichiara documentale **citi il documento**: il giorno
in cui bastasse la parola, "documentale" non varrebbe niente. La riga che
accompagna la figura cambia di conseguenza: *"Profilo da ONI 208…"* invece di
*"Ricostruzione, non riferimento documentale"*.

### Quello che non si e' potuto fare, e perche'

- **Scorte britanniche** (corvetta Flower, fregata River, cacciatorpediniere
  V&W e Town, sloop Black Swan): **FM 30-50 — Recognition Pictorial Manual of
  Naval Vessels** e' stato scaricato e aperto (270 pagine). La sezione
  britannica arriva agli incrociatori — KENT, DEVONSHIRE, NORFOLK, HAWKINS,
  LEANDER, SOUTHAMPTON, DIDO, ARETHUSA, FIJI — e poi passa al Giappone. **Le
  scorte non ci sono**: il manuale copre le unita' maggiori. Restano
  ricostruzioni.
- **Tipi di U-Boot**: **ONI 204 — German Naval Vessels** (37 pagine, aperto)
  copre solo unita' di superficie. **ONI 220-M — Axis Submarine Manual** e'
  quasi tutto testo e fotografie, e classifica gli U-Boot per tonnellaggio
  (250, 517, 740, 1.060 t) invece che per tipo. Nessuno dei due da' profili per
  i nove tipi del gioco.
- **Liberty ship**: questa edizione dell'ONI 208 e' del 1942 e la Liberty non
  c'e' ancora. Resta la ricostruzione.
- **Medaglie e insegne**: scelta deliberata di non cercarne di documentali. Le
  immagini d'epoca della Croce di Cavaliere portano la svastica al centro, e
  questo gioco non ha nessuna iconografia nazista — la croce che usa e' una
  croce di ferro liscia. In piu' le fotografie di medaglie sono quasi sempre
  coperte dal diritto d'autore del fotografo: di pubblico dominio e' l'oggetto,
  non lo scatto.

## Sagome costruite sulle misure — le scorte (18 settembre 2026)

Nessun manuale di riconoscimento alleato in pubblico dominio porta i profili
delle scorte: FM 30-50 si ferma agli incrociatori, ONI 204 e' naviglio di
superficie tedesco, ONI 220-M classifica gli U-Boot per tonnellaggio. Le misure
pero' sono pubblicate. Da quelle si costruisce.

Sette sagome — corvetta Flower, fregata River, cacciatorpediniere V&W e Town,
sloop Black Swan, peschereccio armato e peschereccio d'altura — sono generate da
`bin/disegna_navi.php` a partire da `db/seed/navi_dimensioni.php`. **Il dato
e' il file delle misure; il disegno e' una conseguenza**: si cambiano i numeri e
la sagoma cambia.

**Tutte condividono la scala.** Un'unita' del disegno e' un metro e l'altezza del
riquadro e' la stessa per tutte: messe a una stessa altezza in pixel, le
proporzioni fra classe e classe sono quelle vere. Un four-piper e' lungo il
doppio di una corvetta perche' lo era davvero.

| Classe | Fuori tutto | Baglio | Dislocamento | Segno distintivo |
|---|---|---|---|---|
| Corvetta Flower | 62,5 m | 10,1 m | 925 t | castello corto, un fumaiolo, piccola |
| Peschereccio armato | 50,0 m | 8,4 m | 530 t | plancia e fumaiolo a poppavia del mezzo |
| Sloop Black Swan | 91,3 m | 11,4 m | 1.250 t | castello lungo, impianti binati |
| Fregata River | 91,8 m | 11,1 m | 1.370 t | castello lungo, un fumaiolo |
| Ct. V&W | 95,1 m | 9,0 m | 1.100 t | **due** fumaioli di altezza diversa |
| Ct. Town | 95,8 m | 9,6 m | 1.190 t | **quattro** fumaioli, ponte continuo |

### La distinzione che conta

Il file delle misure separa riga per riga due cose:

- **documentato** — lunghezza fuori tutto, baglio, dislocamento, numero di
  fumaioli e di alberi, numero e posizione dei pezzi principali. Sono dati
  pubblicati e la fonte e' citata in ogni scheda;
- **ricostruito** — le *proporzioni* delle sovrastrutture: quanto e' lungo il
  castello, quanto e' alto il fumaiolo, dove comincia la plancia. Ricavate dalla
  descrizione del profilo, non da un piano di costruzione. Sono la parte che
  rende la sagoma riconoscibile, ed e' la parte che non si puo' dichiarare
  esatta.

Nel registro queste figure hanno `fonte: misurata`, che e' una terza cosa
rispetto a `ricostruzione` e a `documentale`, e la riga sotto la figura lo dice:
*"Sagoma in scala costruita su misure documentate: … Le proporzioni delle
sovrastrutture sono ricostruite."*

### Copertura, e cosa resta scoperto

Diciassette classi di nave su ventidue hanno una figura. Restano senza:

- **`qship`** — e **deliberatamente** senza. Una nave civetta era un mercantile
  camuffato: darle una sagoma propria significherebbe rendere riconoscibile
  proprio quello che non doveva esserlo. Il gioco mostra il nome, come vedeva il
  comandante.
- **`frigorifera`** — nessun profilo distinguibile trovato: una frigorifera da
  6.000 tonnellate somiglia a un piroscafo da carico, e usarne uno sarebbe dire
  una cosa che non si sa.
- **`swordfish`, `hudson`, `wellington_leigh`** — nessuna fonte in pubblico
  dominio trovata per le sagome di questi tre aerei.

Una classe senza figura mostra il nome scritto, che e' sempre bastato.

## Il negativo dei profili documentali (18 settembre 2026)

I profili dei manuali di riconoscimento sono inchiostro nero su carta chiara.
Ritagliati e messi su una plancia d'acciaio diventano una macchia nera
invisibile, oppure restano attaccati al loro rettangolo di carta — che e'
esattamente quello che e' successo al primo tentativo.

Il ritaglio fa percio' il **negativo**: quanto piu' un pixel era scuro, tanto
piu' resta opaco, e il colore diventa un grigio chiaro; quello che era carta
sparisce da solo, sfumature comprese. Il risultato e' una sagoma luminosa su
fondo trasparente, che e' come si stampano le tavole di silhouette su fondo
scuro. Le sagome disegnate dalle misure usano lo stesso inchiostro chiaro, cosi'
le tre provenienze stanno insieme senza stonare.

## Le sagome sulla pagina d'ascolto (18 settembre 2026)

La regola d'origine diceva: mai nell'identificazione. Nasceva dal fatto che una
tavola di sagome *e'* un manuale di riconoscimento, e con figure inventate si
insegna a riconoscere navi che non esistono.

Quel motivo e' mezzo caduto. Quattordici classi su ventidue non hanno piu'
figure inventate — sette profili ONI 208 e sette sagome costruite sulle misure —
e per quelle la sagoma accanto alla classificazione non insegna niente di falso.
La regola e' stata percio' sostituita con una piu' stretta, che non e' sulla
provenienza della figura ma su **cosa sa il comandante**:

> La sagoma compare solo per un contatto **visto** e con la classificazione
> ormai ferma: sensore `vista` e certezza **almeno il 60%**.

All'idrofono non si vede niente: si sente un battito d'elica, e il Funkmaat dice
«mercantile isolato», non «piroscafo a tre isole». Una sagoma accanto a un
contatto acustico regalerebbe al giocatore un'informazione che il comandante non
aveva — ed e' esattamente il tipo di regalo che questo progetto non fa.

**Il dato segue la regola, non solo la pagina.** La migrazione 0018 aggiunge
`contacts.classe_key_est`, che viene scritta **solo** quando il contatto e'
riconosciuto a vista; all'idrofono e col fumo resta NULL, e un convoglio non ne
ha una perche' non e' una classe sola. Cosi' la regola non si puo' aggirare
cambiando una vista: l'informazione non c'e' proprio.
`tests/e2e_navigazione.sh` verifica l'invariante sul database — nessun contatto
non visivo con una classe riconosciuta — invece che sulla resa della pagina.

Vedere la sagoma vuol dire aver riconosciuto, e riconoscere costa tempo,
avvicinamento e rischio.

## Le sagome nella stazione d'attacco (18 settembre 2026)

Stessa regola della pagina d'ascolto, portata al suo caso limite. Nella stazione
d'attacco il bersaglio e' a qualche migliaio di metri — ma se il periscopio e'
dentro, non lo si sta guardando comunque.

> La sagoma compare se il battello puo' **vedere**: in superficie, oppure a quota
> periscopica **col periscopio fuori**. Sotto, o col periscopio abbassato, non
> compare.

E' il legame che mancava alla corsa del periscopio (audit A8). Alzarlo aveva
gia' un prezzo — la sagoma del battello quasi triplica per una vedetta, e la
baffa sul mare liscio si vede da lontano — ma non aveva un guadagno visibile.
Adesso ce l'ha: **si alza il periscopio e si vede che cosa si ha davanti**. La
decisione piu' piccola dell'attacco in immersione diventa anche la piu'
leggibile.

`tests/e2e_navigazione.sh` alza e abbassa il periscopio e verifica che la pagina
cambi di conseguenza.

## Le sagome nel giornale di guerra (18 settembre 2026)

Il giornale e' il resoconto per definizione, ed era il primo posto nominato
nella regola: una sagoma accanto a un affondamento gia' avvenuto e' un ricordo
illustrato, non un aiuto a riconoscere.

Per arrivarci pero' e' servito **dare all'affondamento una voce sua**. Prima la
riga finiva nel mucchio della cronaca dell'incontro: tutte marcate
`combattimento`, tutte con l'ora di fine del blocco di passi invece che con
l'ora vera. Nel giornale un affondamento era indistinguibile da un colpo
mancato, e non c'era modo di risalire a quale nave fosse.

Adesso `Encounter::registraAffondamento()` scrive la sua riga — genere
`affondamento`, ora esatta in cui la nave e' andata giu' — e restituisce il
testo alla cronaca in diretta, che pero' non lo riscrive nel giornale. La
sagoma si aggancia all'affondamento **per l'ora esatta**, senza dover indovinare
niente dal testo della riga.

`tests/e2e_navigazione.sh` verifica l'invariante: nessuna riga di genere
`affondamento` senza la sua nave allo stesso istante. Se un giorno le due
scritture si disallineassero, la riga resterebbe muta — e la prova lo direbbe
prima che se ne accorga un giocatore.

**Il miglioramento e' del giornale, non solo delle figure.** Anche senza
sagome, adesso un affondamento e' una voce distinta con la sua ora: e' come
doveva essere fin dall'inizio.

## Unicita' dei nomi, e lo spazio che ci voleva (18 settembre 2026)

**Nessun nome in due**: ne' fra le navi in mare, ne' fra i comandanti in
flottiglia, ne' fra i battelli (quello era gia' vincolato). I vincoli stanno nel
database — `UNIQUE` su `ships.name`, `commanders.nome`, `boats.uboat_number` —
non nelle buone intenzioni del generatore: reggono anche la corsa fra due
creazioni simultanee, che un controllo "leggi prima, scrivi dopo" non regge.

Per poterli imporre e' servito prima **costruire lo spazio dei nomi**. Ce n'erano
152 distinti per duemila scafi in mare: la collisione non era un caso sfortunato,
era la norma (99,2% di estrazioni gia' viste su ventimila prove). Adesso i nomi
si compongono come si componevano davvero:

- **statunitensi**: nome piu' cognome di personaggi storici, come le Liberty
  (40 × 50 = 2.000 combinazioni);
- **britanniche di stato**: `Empire`, `Ocean` piu' un vocabolario largo;
- **britanniche private**: `Clan` piu' un clan scozzese, `City of` piu' una
  citta', `Baron` piu' un titolo, oppure ceppo di luogo piu' desinenza
  (`-pool`, `-dale`, `-hall`, `-moor`: 51 × 12 = 612);
- **canadesi**: `Fort` piu' un luogo, oppure un luogo piu' `Park`;
- **norvegesi**: ceppo piu' desinenza (`-aas`, `-heim`, `-vik`, `-nes`: 32 × 12).

Risultato misurato: **3.459 nomi di mercantile** distinti e **309 di scorta**.
Le liste delle scorte sono cresciute in proporzione — oltre cento nomi di fiore
per le corvette Flower, che ne furono varate piu' di duecento.

Dove due nomi si incontrassero lo stesso, il generatore ritira qualche volta e
in ultima istanza distingue col numero di scafo. Il vincolo del database resta
l'ultima parola.

**Conseguenza da dichiarare.** La migrazione `0019` ha cancellato quasi
millesettecento navi con nome duplicato, e alcune stavano dentro convogli gia'
partiti: quei convogli sono rimasti con tre o quattro scafi. La migrazione
`0022` li chiude — sotto gli otto mercantili non e' un convoglio, e' una
processione — e il generatore ne mette in mare di nuovi, completi. Un convoglio
sfoltito da un U-Boot resta invece un convoglio e non viene toccato.

## La nave civetta (18 settembre 2026)

La classe `qship` esisteva dal principio e non entrava mai nel traffico. Se ci
fosse entrata si sarebbe annunciata da sola: la vedetta avrebbe riferito «nave
civetta», che e' l'unica cosa che una nave civetta non dice mai.

Adesso una classe puo' dichiarare di **fingersi** un'altra (`ship_classes.finge`).
Tutto cio' che riguarda l'identificazione passa da `Traffic::classeApparente()`:
il nome riferito dalla vedetta, la classe registrata nel contatto, la sagoma
mostrata. **La fisica no** — stazza, rumore e armamento restano quelli della
nave: e' l'apparenza che mente.

Ne compare una ogni mille mercantili isolati circa (`traffic.civette_per_mille`,
14 su mille), e solo fra le isolate: la trappola funziona su chi si avvicina in
superficie a un bersaglio che sembra solo e indifeso.

**La maschera cade in un modo solo: quando la nave spara.** E' scritto in
`Encounter::cannone()`, ed e' esattamente dove la trappola scattava davvero —
U-Boot in superficie, a poche centinaia di metri, equipaggio allo scoperto in
coperta. Il danno cresce con la vicinanza: a tremila metri si fa in tempo a
immergersi, a cinquecento no. Provato: sei colpi a 445 metri, i pannelli cadono,
lo scafo va al 12% di sollecitazione e lo scafo resistente resta in avaria fino
al cantiere. Da quel momento `encounter_entities.smascherata` resta a uno: chi
ha visto i pannelli cadere non se lo dimentica.

## Il danno che resta addosso alla nave (18 settembre 2026 — O1)

Fino a oggi una nave colpita e non affondata tornava intera nel traffico appena
si rompeva il contatto: il danno viveva dentro `encounter_entities` e moriva con
l'incontro. Due siluri a segno, e la mattina dopo quella nave navigava come
nuova.

### Quello che succedeva davvero

Una nave silurata e rimasta a galla faceva tre cose, e le faceva quasi sempre.

**Rallentava.** Con una stiva allagata e l'assetto storto non si tengono i nodi
di prima. I rapporti d'inchiesta dell'Ammiragliato sulle navi silurate e
rientrate riportano velocita' residue di poche unita', spesso meno della meta'.

**Restava indietro.** Il convoglio non aspettava: era istruzione scritta, non
crudelta'. La nave rallentata diventava una *straggler*, e le straggler erano la
preda preferita degli U-Boot — fuori dalla formazione non c'era piu' nessuno a
proteggerle. Nelle statistiche alleate le navi perdute fuori formazione pesano
in modo sproporzionato rispetto a quante fossero.

**Poteva affondare piu' tardi.** Ore, a volte giorni. Il comandante che l'aveva
colpita era lontano e spesso non lo sapeva: glielo diceva il BdU, che
confrontava le rivendicazioni con le intercettazioni sul traffico nemico e
accreditava dopo. E' il motivo per cui nei Kriegstagebuecher le conferme
arrivano separate dall'attacco, a volte molto separate.

### Come e' stato modellato

Le colonne del danno (`integrita`, `allagamento`, `incendio`, `ritardataria`,
`danno_*`, `affonda_gts`, `convoglio_perduto`) stanno su `ships`, e il danno
esce dall'incontro alla chiusura — per tutte le vie di chiusura, compreso il
disimpegno ordinato dal comandante.

La **velocita' residua** e' una funzione dell'allagamento (che pesa di piu'),
dell'integrita' e dell'incendio (che pesa poco: si corre lo stesso, si brucia
correndo), con un pavimento a tre nodi. Nessuna nave resta ferma in mezzo
all'Atlantico.

Rallentando, **l'ora di partenza viene spostata** in modo che la posizione nel
momento del danno resti la stessa. E' obbligatorio, non elegante: in questo
progetto la posizione di una nave non e' una riga ma un calcolo su rotta,
velocita' e ora di partenza, e cambiare la velocita' senza toccare il resto la
farebbe saltare indietro di miglia. La prova `rallentando non salta di
posizione` misura lo scarto, che resta sotto il centinaio di metri (è
arrotondamento al secondo, non modello).

La **probabilita' di affondare dopo** e' una curva sull'allagamento: nulla sotto
un quarto di stiva allagata, quasi certa vicino alla soglia di non ritorno. Il
carico conta, con gli stessi effetti gia' modellati nel danno del siluro: in
zavorra e col legname si galleggia, col minerale si va giu' prima. La decisione
si prende **una volta sola**, al momento del danno, e si scrive: non dipende da
quante volte qualcuno guarda, e il mondo resta deterministico.

La **conferma del BdU** e' un radiogramma al battello che l'ha colpita, e la
stazza va alla missione durante la quale e' stato inflitto il danno. Se quella
missione e' gia' chiusa, il conto si fa direttamente sul comandante: altrimenti
quel tonnellaggio non gli arriverebbe mai.

### La conseguenza che non era prevista

Le ritardatarie tolgono navi ai convogli, e gli affondamenti pure. Senza una
regola che chiuda i convogli esauriti, dopo qualche mese l'Atlantico sarebbe
pieno di convogli da cinque navi. Da qui la **dispersione**: un convoglio sceso
sotto il minimo si scioglie — i mercantili proseguono da soli, la scorta rientra
— e il generatore ne fa partire uno nuovo. Anche questo e' storico: *«convoy is
to scatter»* e' l'ordine che il 4 luglio 1942 mando' al fondo il PQ17. La soglia
e' bassa apposta: un convoglio malmenato da un branco deve poter arrivare in
porto malmenato, non sciogliersi al terzo affondamento.

Prove: `tests/test_danni.php`, 27 controlli.

## Quello che si vede dalla stazione d'attacco (18 settembre 2026 — O2)

L'audit aveva lasciato aperta una incoerenza: la pagina dei contatti applicava
la regola concordata — classe solo per i contatti **visti** e classificati oltre
il 60% — e la pagina d'attacco, accanto, mostrava la verita' nuda. Nome, classe,
stazza e distanza al metro di tutte le unita' del convoglio, comprese quelle a
sette chilometri, anche col periscopio abbassato.

Il quadro tattico adesso e' quello che e' sempre stato: **il tavolo di
plottaggio della Zentrale**. Ci finisce solo cio' che qualcuno ha visto o
sentito, con l'errore di chi l'ha visto o sentito.

### Le tre soglie, e da dove vengono

**Si vede** solo con un occhio fuori: in superficie dalla torretta, a quota
periscopica col periscopio alzato. Il calcolo della portata e' lo stesso gia'
usato dal ciclo di crociera (`Detection::portataVisiva`), con l'altezza
dell'occhio che cambia fra torretta e periscopio.

**Si riconosce** molto piu' vicino di quanto si individui. Una sagoma
all'orizzonte e' una sagoma; per dire «petroliera classe T2» servono i dettagli
— la posizione del ponte, il numero di alberi, il taglio della poppa — e quelli
si risolvono a distanza molto minore, e di notte quasi mai. E' la stessa
distinzione su cui sono costruiti i manuali di riconoscimento (ONI 208-J), che
insegnano a distinguere la sagoma prima e l'identita' poi. Modellata come una
quota della portata di avvistamento, modulata dalla luce; la soglia di
riconoscimento e' **la stessa 60% della pagina d'ascolto**, cosi' le due pagine
dicono finalmente la stessa cosa.

**Si legge il nome** solo da vicino e con la luce: poche centinaia di metri di
notte, meno di un miglio di giorno. Nei Kriegstagebuecher il nome compare quasi
sempre *dopo*, aggiunto in un secondo momento: durante l'attacco si scrive
«Dampfer ca. 6000 BRT», e il nome lo mette il BdU quando conferma. Quando il
nome non si legge, il plottaggio da' all'unita' **un numero** — ed e' cosi' che
la pagina la chiama.

### Gli errori, e perche' sono proporzionali

Rilevamento, distanza, angolo sulla prua e velocita' sono **stime**, ciascuna
col suo errore, e l'errore dipende da come si e' ottenuta la misura.

- Il **rilevamento** e' la cosa che si sa meglio, con l'occhio e anche con
  l'orecchio: pochi gradi.
- La **distanza** sbaglia in percentuale e mai oltre il fattore che il mestiere
  consente. Un errore assoluto poteva far scrivere «sessanta metri» per una nave
  che ne stava a duemila, e nessun idrofonista ha mai sbagliato cosi'.
- La **velocita'** si stima dai giri d'elica, ed e' una delle cose che
  l'idrofonista fa meglio. Anche qui l'errore e' proporzionale: uno assoluto
  poteva dare «ferma» per una nave che faceva otto nodi, e una nave ferma e' un
  problema di tiro completamente diverso.
- L'**angolo sulla prua** si stima *col segno*, e il segno e' quale fianco ci
  mostra. A orecchio non lo si sa: dall'idrofono arriva un rilevamento, non un
  profilo, e l'angolo si ricava solo seguendo come quel rilevamento cambia nel
  tempo. Tenere il lato vero regalava al comandante meta' del problema di tiro,
  quindi a orecchio il fianco si sbaglia spesso — misurato, circa un terzo delle
  volte.
- La **stazza** si stima a occhio, e a occhio si e' sempre generosi: le
  rivendicazioni degli equipaggi superavano regolarmente il vero. La stazza
  mostrata ha quindi un errore con una piccola tendenza all'eccesso. Quella che
  finisce in archivio quando la nave affonda e' invece la vera, perche' quella
  la accredita il BdU.

Le stime **non si ri-tirano a ogni aggiornamento di pagina**: il seme dipende dal
minuto di gioco. Altrimenti sarebbero bastate dieci ricariche e una media per
avere il valore vero, e tutto questo non sarebbe servito a niente.

### Il tavolo disegna le stime, non la verita'

La posizione segnata sul quadro tattico e' quella **stimata**: il rilevamento
riferito, alla distanza riferita. La rotta disegnata discende dal rilevamento e
dall'angolo sulla prua riferiti, cosi' il disegno e la tabella raccontano la
stessa storia, errore compreso. Un contatto che non si e' visto e' un
**cerchietto**: non ha prua ne' stazza, e non finge di averle.

Quello che l'idrofono sente ma non riesce a separare non finisce sul tavolo: il
Funkmaat lo riferisce come numero — «molte eliche, rumore di massa» — ed e'
esattamente quello che si aveva da sotto.

### La maschera della civetta regge anche qui

La nave civetta continua a mostrare la classe apparente, e la maschera cade
quando spara, non quando la si guarda meglio. Verificato in
`tests/test_vista.php`.

Prove: `tests/test_vista.php`, 20 controlli, piu' quattro in
`tests/e2e_navigazione.sh` che alzano e abbassano il periscopio sulla pagina
vera.

## I ritratti dei comandanti (18 settembre 2026)

### Perche' non da uboat.net

uboat.net e' la raccolta piu' completa che esista sull'arma subacquea tedesca, ed
e' il posto a cui si pensa per primo. Non si e' preso niente da li', per un
motivo che non lascia margini: il loro `robots.txt` dice

```
User-agent: ClaudeBot
Disallow: /
```

Hanno chiesto espressamente che gli agenti automatici stiano fuori dal sito, e la
richiesta si rispetta — non perche' qualcuno controlli, ma perche' e' la loro.
Si aggiunge che le immagini di quel sito arrivano da archivi e collezioni private
con diritti diversi l'uno dall'altro: ripubblicarle qui sarebbe una
ripubblicazione a tutti gli effetti, e senza poter dire sotto quale licenza.

### Da dove vengono, allora

> **Superato il 18 settembre 2026, sera.** Questa e' stata la prima strada, e
> non e' piu' quella in uso: il repertorio oggi e' la raccolta del proprietario
> (vedi «secondo tempo» e «terzo tempo» piu' sotto), e `bin/scarica_ritratti.php`
> e' stato tolto di proposito. Resta scritta perche' il ragionamento dei tre
> criteri vale ancora — e' il modo giusto di scegliere volti da Commons — e
> perche' lo strumento si recupera dalla storia del progetto, commit `05354c4`,
> se un giorno servisse.

Da **Wikimedia Commons**, dove ogni file porta con se' la licenza dichiarata e la
stringa di attribuzione richiesta. L'elenco delle persone non e' scritto a mano:
viene da una interrogazione di **Wikidata** (persone della Kriegsmarine con un
ritratto su Commons), filtrata da `bin/scarica_ritratti.php` con tre criteri
dichiarati nel codice:

1. **Comandava sommergibili.** La Kriegsmarine aveva anche le corazzate: senza
   questo filtro entrerebbe Lindemann, che comandava la Bismarck.
2. **L'immagine e' un suo ritratto.** Wikidata collega alla persona la sua
   immagine principale, che non sempre e' una faccia: per Schnee e' "U-123 e
   U-201 che escono da Lorient", per Kusch una lapide, per von Friedeburg la
   firma della resa. Il criterio e' severo — il cognome dev'essere nel nome del
   file e il nome del file non deve parlare d'altro — e perde qualche ritratto
   buono col nome storto. Meglio cosi': trenta volti giusti valgono piu' di
   sessanta di cui meta' sono banchine.
3. **E' una fotografia del tempo.** Parecchi comandanti sono sopravvissuti e
   hanno fatto carriera nella Bundesmarine: il loro ritratto su Commons e'
   spesso un ammiraglio del 1966 o un signore anziano del 2014. In una plancia
   del 1942 quel volto non ci sta, e le fotografie posteriori al 1946 restano
   fuori.

Il risultato sono **33 ritratti**: Kretschmer, Prien, Schepke, Topp, Lüth,
Lehmann-Willenbrock, Endrass, Bleichrodt, Herbert Schultze, Bigalk, Kuppisch,
Liebe, von Tiesenhausen, Mützelburg, Kuhnke e altri. Diciassette sono di pubblico
dominio, dodici vengono dalla donazione del Bundesarchiv (CC BY-SA 3.0 de), due
sono CC BY-SA 3.0 e due CC0.

### Come si portano

Ogni ritratto mostra **sotto di se'** la sua dichiarazione: chi e' il ritratto,
l'anno dello scatto e la stringa di attribuzione richiesta dalla licenza — per
esempio *«Bundesarchiv, Bild 183-L22207 / Tölle (Tröller) / CC-BY-SA 3.0»*. Non
e' un abbellimento: e' una condizione delle licenze CC, ed e' la stessa regola
che questo progetto applica gia' alle sagome delle navi.

### Il nome del comandante ritratto

Chi sceglie un ritratto storico puo' anche prendersi il nome dell'uomo che c'e'
sopra. Il gioco non fa finta di niente: sul fascicolo compare che quel nome e'
portato **in omaggio**, e che il fascicolo e' di un altro uomo. Se il nome e'
gia' in servizio da qualcun altro, il ritratto resta e il nome no — il vincolo
di unicita' sui nomi non si scavalca da questa porta piu' che dalle altre.

### Un volto per fascicolo

Come per i nomi, come per gli emblemi, come per i numeri di battello: due
comandanti non possono avere la stessa faccia. Il vincolo sta nel database —
`UNIQUE` su `commanders.ritratto_key` e su `commanders.ritratto_hash` — e non in
un controllo a mano, perche' fra il controllo e la scrittura passa il tempo di
un'altra richiesta. Vale anche per le fotografie caricate: l'impronta e' lo
sha256 dell'immagine **dopo** che e' stata riscritta da noi, quindi due
caricamenti dello stesso file collidono anche se arrivano con nomi diversi.

Le fotografie caricate seguono la stessa strada degli emblemi: tipo deciso dai
byte e non dall'estensione, immagine riaperta e riscritta in WebP da GD (niente
metadati, niente code, niente di quello che poteva esserci dentro), nome uguale
all'impronta, niente SVG.

## Gli emblemi di torretta, il seguito (18 settembre 2026)

Il repertorio passa da nove a **trentanove**. Gli emblemi storici non esistono
come file liberi da nessuna parte — Commons non ne ha una raccolta, e uboat.net
e' fuori discussione per il motivo di sopra — quindi si disegna, come si e'
fatto per le sagome delle navi. Tutti i disegni stanno in
`bin/disegna_emblemi.php`, in chiaro.

Lo stile e' quello delle mascherine vere: campo tondo, sagoma piena, bordo netto,
due o tre colori. Doveva leggersi dalla banchina e sopravvivere alla vernice, non
stare bene su uno schermo.

I **soggetti** sono documentati dove si puo', e la scheda lo dichiara:

- il **pupazzo di neve** e' il gioco di parole di Adalbert Schnee su U-201, e i
  giochi di parole sul nome del comandante erano il motivo piu' comune di tutti;
- il **diavolo rosso** e' il "Roter Teufel" di Erich Topp su U-552;
- l'**orso bianco** viene dai gruppi operativi artici;
- il **toro**, il **ferro di cavallo** e i **tre pesciolini** c'erano gia' e
  restano attribuiti a U-47, U-99 e U-333.

Gli altri sono dichiarati `ricostruita`: plausibili per l'epoca, non attribuiti a
un battello preciso. Sono animali, carte da gioco, portafortuna e oggetti — che
e' esattamente il territorio in cui stavano gli emblemi veri. **Nessuna insegna
di partito**: non ce n'e' una, e non e' una svista.

## Fruibilita' su tablet e telefono (18 settembre 2026)

Regola di casa, scritta anche nel foglio di stile: le regole per gli schermi
stretti stanno tutte dentro una media query, e **niente di come si vede su un
monitor e' stato toccato**. Verificato rendendo la stessa pagina a 1400, 834 e
375 pixel.

I problemi veri su un telefono sono quattro, e sono questi.

**Le tabelle.** Otto colonne di dati in trecento pixel non si leggono: si
accartocciano. Invece di spremerle si lascia che tengano la loro larghezza e si
fa scorrere il pannello che le contiene, che e' come si legge un tabulato. Solo
le tabelle larghe: il conto delle colonne lo fa `:has(th:nth-child(6))`, non un
attributo da mettere a mano in venti viste.

**Le due colonne.** Quadro tattico e strumenti affiancati vogliono almeno
cinquanta caratteri per parte; sotto le 56rem si impilano. Lo stile era in
linea nella vista e ora e' una classe, perche' una regola in linea non si puo'
scavalcare da un foglio di stile.

**La navigazione.** Quattordici voci a capo diventano cinque righe di bottoni e
mangiano mezzo schermo: su schermo stretto diventano una striscia che scorre di
lato.

**Le dita.** Bersagli da toccare di almeno 2,6rem, e campi di testo a 16px —
sotto quella misura iOS ingrandisce la pagina da solo al primo tocco, e chi
stava leggendo perde il segno.

Una cosa trovata per strada: sul tablet il quadro tattico sfondava il bordo
destro, perche' la tela si dimensiona in JavaScript sul contenitore e in quel
caso il conto veniva piu' largo della colonna. Adesso c'e' una rete in CSS
(`#plotta { max-width: 100% }`): quando il conto e' giusto non fa niente, quando
e' sbagliato la tela si stringe invece di spingere fuori tutta la pagina.

## Il repertorio dei ritratti, secondo tempo (18 settembre 2026, sera)

Il proprietario del gioco ha messo insieme **a mano** una raccolta di 509
ritratti di comandanti di U-Boot realmente esistiti e ha chiesto di usare
quella. Il repertorio passa da 33 voci a **516**.

### Quello che si sa, e quello che non si sa

Resta in piedi la ragione per cui non si e' scaricato niente in automatico da
uboat.net: il loro `robots.txt` dice `User-agent: ClaudeBot / Disallow: /`, e
quella richiesta vale ancora. Una persona che naviga e salva un'immagine non e'
un agente automatico, e quella e' una scelta sua; il programma, da li', continua
a non passare.

Di quei 509 file **non si conosce la provenienza singola**, e il gioco non la
inventa. Ogni voce importata cosi' e' marcata `fonte: 'raccolta'`, e la
dichiarazione che compare sotto il ritratto dice testualmente *«raccolta
storica, provenienza del singolo file non verificata»*. Le 8 voci scaricate da
Commons che la raccolta non copriva restano, con la loro licenza verificata e
la loro attribuzione: la differenza si vede, nel registro e sullo schermo.

Dire "non lo so" e' una informazione. Attribuire una licenza che nessuno ha
controllato sarebbe una bugia, e questo progetto non ne racconta.

### I nomi vengono dai nomi dei file

Non c'era un elenco di nomi: c'erano 509 nomi di file. `bin/importa_ritratti.php`
li smonta e li rimette insieme, e la scheda di ogni voce porta
`nome_ricavato => true` perche' chi guarda sappia da dove viene quel nome.

Le regole, tutte nel codice:

- **Marcatori fuori.** `dauter_helmut_pow` e' Helmut Dauter, non "Helmut Dauter
  Pow"; `hoffmann_eberhard_u451` e' Eberhard Hoffmann, e `u451` e' il suo
  battello; `benker_hans_nov-1942` porta una data. Prigionia, numeri di
  battello, date e le cifre che distinguono due scatti della stessa persona non
  sono parti del nome.
- **Cognome prima, nomi di battesimo dopo.** `eckermann_hans_christian_franz`
  diventa Hans Christian Franz Eckermann.
- **Le particelle stanno col cognome, e vanno al posto giusto.**
  `harpe_richard_von` → Richard von Harpe. `ahlefeld_von_hunold` → Hunold von
  Ahlefeld, perche' li' la particella chiude il cognome. Ma
  `heusinger_von_waldegg_burkhard` → Burkhard Heusinger von Waldegg, perche' li'
  sta in mezzo. La differenza e' se dopo la particella resta ancora qualcosa da
  usare come nome.
- **Le dieresi si ricostruiscono, ma con prudenza.** `lueth` → Lüth,
  `pueckler` → Pückler, `schroeter` → Schröter. Il digramma si converte **solo
  se non e' preceduto da vocale**: in `bauer` la "ue" e' la coda di "bau" piu'
  "er", e senza quella regola verrebbe fuori "Baüer". Stessa cosa per `blauert`
  e per `quaet-faslem`, che restano com'erano.

Funziona quasi sempre. Dove sbaglia, sbaglia in modo visibile e correggibile, e
la scheda dichiara che quel nome e' ricavato.

## Il repertorio dei ritratti, terzo tempo (18 settembre 2026, sera)

Le otto voci di Commons che la raccolta non copriva, e che il paragrafo di
sopra dice rimaste, non ci sono piu'. Sono state tolte la sera stessa, nel
commit `516538f`, insieme a `bin/scarica_ritratti.php`, e il repertorio e'
diventato **la sola raccolta: 508 ritratti**, una provenienza sola, una
dichiarazione sola sotto ogni volto.

Il motivo e' scritto nel messaggio di quel commit, ed e' pratico: lo strumento
teneva viva una strada che, se qualcuno la ripercorreva, riscriveva il seme dei
ritratti con le sole voci di Commons e cancellava la raccolta. Uno strumento che
distrugge il repertorio al primo uso distratto e' peggio di nessuno strumento.

Di 509 fotografie ne sono entrate 508: `capt14.jpg` resta fuori perche' il
nome del file non e' un nome di persona, e l'importatore lo dice invece di
inventarne uno. Gli originali — la raccolta intera, 509 file — stanno nel backup
privato, in `sorgenti/ritratti-comandanti/`, dal 22 settembre.

Questo paragrafo e' stato aggiunto il 23/09/2026: fino a quel giorno la
documentazione continuava a descrivere lo strumento in cinque punti, come se ci
fosse ancora. L'audit dell'8 revisione l'aveva scambiato per uno strumento mai
scritto; la nona ha guardato la storia e ha trovato il commit che lo toglieva.

## L'unicita' vale fra i vivi (18 settembre 2026, sera)

Fino a qui un nome, un volto, un emblema e un numero di U-Boot erano unici **per
sempre**: il primo che li prendeva se li teneva anche da morto. Per un mondo
persistente che va avanti per anni non regge. Nel 1942 i comandanti morivano
quasi tutti — tre equipaggi su quattro non tornarono — e un repertorio che si
consuma a ogni perdita, in pochi mesi, non avrebbe piu' avuto niente dentro.

La regola giusta e' quella che il gioco gia' raccontava, applicata per bene:

> In flottiglia non ci sono due cose uguali **fra quelle in servizio**.

Quando un comandante cade, viene dato per disperso, finisce prigioniero o viene
congedato, il suo nome e il suo volto tornano disponibili. Quando un battello si
perde, il suo numero e il suo emblema tornano disponibili. Il fascicolo del
caduto **non cambia**: nell'albo d'oro restano il suo volto e il suo nome. E'
solo la prenotazione che decade.

### Come, senza una riga di codice applicativo

Una colonna generata che vale il dato quando la riga e' viva e `NULL` quando non
lo e', piu' un indice `UNIQUE` su quella colonna. `UNIQUE` ignora i `NULL`,
quindi i morti non danno fastidio a nessuno e la liberazione avviene
nell'istante esatto in cui cambia lo stato — non a un battito successivo, non
quando qualcuno si ricorda di passare a ripulire.

```sql
ALTER TABLE commanders
  ADD COLUMN vivo_nome VARCHAR(64) AS (IF(stato = 'attivo', nome, NULL)) VIRTUAL,
  ADD UNIQUE INDEX uq_vivo_nome (vivo_nome)
```

Una cosa trovata per strada: **una colonna generata che legge un `CHAR` non si
puo' indicizzare**. Il riempimento a lunghezza fissa rende l'espressione non
deterministica agli occhi del motore, che rifiuta l'indice. Le due impronte
`ritratto_hash` ed `emblema_hash` erano `CHAR(64)` e sono diventate `VARCHAR(64)`:
sono stringhe esadecimali di lunghezza fissa, quindi per noi non cambia niente.

Prove: `tests/test_profilo.php`, sezioni *Chi cade restituisce il nome e il
volto* e *Chi affonda restituisce numero ed emblema*.

## Una trappola nelle prove, non nel gioco (18 settembre 2026, sera)

Vale la pena scriverlo perche' e' costato mezz'ora e puo' ricapitare.

Con `set -o pipefail`, la forma `echo "${PAGINA}" | grep -q PAT` **mente**:
`grep -q` esce appena trova, `echo` si prende un SIGPIPE e la pipeline
restituisce 141 anche quando la stringa c'era. Finche' le pagine stavano nel
buffer della pipe — sessantaquattro kilobyte — `echo` faceva in tempo a finire e
non si notava. Il giorno in cui la pagina di creazione del comandante e'
arrivata a duecento kilobyte, per via dei cinquecento ritratti, la prova ha
cominciato a fallire senza motivo apparente: la pagina era giusta, il grep
trovava, e il risultato era rosso.

Tutte le occorrenze sono diventate `grep -q PAT <<< "${PAGINA}"`, che non ha
pipeline e quindi non ha il problema.

Nella stessa tornata, altre due fragilita' delle prove:

- Il processo web non vede subito il file di configurazione riscritto: opcache
  lo ricontrolla ogni due secondi. Le prove che forzano il trasporto della posta
  a `log` adesso aspettano.
- La posta non parte piu' al momento dell'iscrizione — da quando c'e' la coda la
  spedisce il battito — quindi la prova dell'autenticazione smista la coda a
  mano invece di sperare che il cron passi al momento giusto.

## L'emblema non era esclusivo (18 settembre 2026, sera tardi)

Correzione a una regola che avevo messo e che era **storicamente sbagliata**.

Avevo reso l'emblema di torretta unico: un segno, un battello. Non funzionava
cosi'. Gli emblemi erano di due specie, e solo una era personale.

**Di flottiglia.** Il toro che sbuffa nasce con U-47 di Prien dopo Scapa Flow e
diventa poi il segno di **tutta la 7. U-Flottille**: lo portavano decine di
battelli insieme, ed era il punto — si riconosceva la flottiglia dalla banchina.
Il pesce sega ridente di U-96 diventa allo stesso modo quello della 9.

**Personale del comandante.** E quello se lo portava dietro cambiando battello:
il diavolo rosso di Erich Topp passa da U-57 a U-552.

In tutti e due i casi lo stesso emblema sta su piu' torrette contemporaneamente,
e nel secondo caso ci sta pure su torrette diverse in momenti diversi. Il
vincolo se n'e' andato (migrazione 0026). Al suo posto il repertorio dice
**chi altro lo porta** — che e' una informazione, non un divieto, ed e' anche il
modo in cui un giocatore capisce a quale flottiglia si sta accodando.

### Che cosa resta unico, e perche' quello si'

Tre cose, e solo fra chi e' in servizio:

| Cosa | Perche' |
|---|---|
| Il **nome** del comandante | In flottiglia ci si chiama per cognome: due Vogel sarebbero una confusione |
| Il **ritratto** preso dalla galleria | E' il volto di una persona realmente esistita: due comandanti non possono essere lo stesso uomo |
| Il **numero** dell'U-Boot | Due battelli non hanno lo stesso scafo, e il numero e' lo scafo |

Non e' piu' unica la **fotografia caricata da casa**: se due giocatori si
portano lo stesso file, sono affari loro, e non c'e' nessuna ragione storica ne'
di gioco per impedirlo.

## Le fotografie portate da casa (18 settembre 2026, sera tardi)

Due cose, tutte e due facoltative nel senso giusto del termine.

**La misura.** Una fotografia caricata viene ritagliata quadrata e riportata a
320 pixel di lato, che e' la misura della galleria storica. Non e' pignoleria:
in una griglia di cinquecento volti tutti della stessa taglia, uno di taglia
diversa si vede subito e rompe la pagina. I file originali della raccolta erano
in media 224x308 e sono stati normalizzati a quel quadrato.

**L'aria d'epoca.** Chi vuole puo' chiedere che la sua fotografia venga
invecchiata. Non e' un filtro "vintage" a caso: rifa' le cose per cui una
fotografia del 1942 si riconosce a colpo d'occhio, **in quest'ordine**, perche'
l'ordine conta.

> **Correzione del 18 settembre 2026, sera tardi — il seppia era sbagliato.**
> La prima versione virava forte sul caldo, e a occhio sembrava giusto. Misurato
> sui 508 volti veri della galleria, lo scarto fra canale rosso e canale blu ha
> **mediana zero**: 288 fotografie sono neutre, 107 hanno una dominante calda e
> **113 una fredda**. Le dominanti sono rumore di scansione che va in tutte e
> due le direzioni, non una caratteristica del mezzo — una stampa alla gelatina
> d'argento e' grigia, e il seppia e' un luogo comune da cartolina. Il filtro
> produceva uno scarto di +24 su 255, quindici volte la media delle vere.
> Adesso ne produce +3, che sta dentro la dispersione delle vere.
>
> Misurando si e' scoperta anche una cosa che a occhio non si vedeva: **in GD
> l'argomento di `IMG_FILTER_CONTRAST` e' rovesciato** — i valori positivi
> abbassano il contrasto, i negativi lo alzano. Passavo dei negativi credendo di
> ammorbidire, e lo stavo indurendo.

1. **Via il colore.** Monocromatica, prima di tutto il resto.
2. **Contrasto piu' morbido e un filo piu' chiara.** Le stampe alla gelatina
   d'argento non hanno i neri profondi di un sensore digitale.
3. **La dominante calda** della carta invecchiata. Si vira *dopo* aver tolto il
   colore, mai prima.
4. **Un filo di sfocatura.** Le ottiche del tempo non erano incise come quelle
   di oggi.
5. **Grana e vignettatura**, per ultime. Se si aggiungesse la grana prima della
   virata, verrebbe virata anche lei e sembrerebbe sporco colorato invece che
   argento.

Due passaggi non sono fissati a mano ma **misurati**, e si portano verso i
numeri della galleria qualunque cosa arrivi in ingresso: una fotografia gia'
piatta non viene appiattita ancora, una gia' chiara non viene schiarita.

Il **contrasto** si cerca per bisezione: sei passate su una copia, finche' la
deviazione standard non si avvicina allo 0,215 misurato sulle vere.

E c'e' **l'esposizione**. Misurata su un
campione dei volti veri, la galleria sta intorno a **0,51** di luminanza media —
sono stampe chiare, quasi slavate. Una fotografia moderna ben esposta sta molto
piu' in basso (quella di prova stava a 0,31), e messa nella griglia accanto alle
altre si nota perche' e' *scura*, non perche' e' moderna. Il filtro la sposta
verso quel bersaglio: l'ottanta per cento della differenza, con un tetto, per
non bruciare le luci di una fotografia gia' chiara.

Verificato mettendo una fotografia vera della galleria accanto alla stessa
passata dal filtro: la differenza si vede appena, che e' il segno che i
parametri sono nel posto giusto.

## Il numero dell'U-Boot (18 settembre 2026, sera tardi)

Domanda giusta, e la risposta e' si': il numero **si assegna da solo**, ed e'
storicamente coerente per tipo di battello. La Kriegsmarine non numerava a caso:
assegnava per commessa, e a ogni tipo corrispondono blocchi precisi.

| Tipo | Blocchi |
|---|---|
| II D | U-137–152 |
| VII B | U-45–55, U-73–76, U-83–87, U-99–102 |
| VII C | U-69–72, U-77–82, U-88–98, U-201–300, U-351–458 |
| VII C/41 | U-292–300, U-925–1058 |
| IX B | U-64–65, U-103–111, U-122–124 |
| IX C/40 | U-167–170, U-183–194, U-525–550 |
| IX D2 | U-177–182, U-195–200, U-847–852 |
| XXI | U-2501–2600 |

Sono i blocchi veri. U-99 e U-100 cadono fra i VII B — ed erano infatti i
battelli di Kretschmer e di Schepke. I XXI partono da U-2501, che e' dove sono
partiti davvero.

L'estrazione e' deterministica (seme del mondo piu' utente piu' tipo), e il
numero estratto si prende solo se nessun battello ancora a galla ce l'ha: quando
un U-Boot si perde, il suo numero torna al mare.

**Una correzione trovata rispondendo.** Fra i Tipo II D c'era anche il blocco
U-56–U-63. Quei battelli erano **II C**, non II D. Tolto.


## Una fotografia caricata resta di chi l'ha caricata (18 settembre 2026, sera tardi)

Il repertorio dei ritratti e' un **elenco scritto** (`db/seed/ritratti.php`), non
una lettura della cartella. Le fotografie portate da casa finiscono in
`assets/img/ritratti/caricati/` e in `commanders.ritratto_file`, e non entrano
in quell'elenco: quindi non compaiono fra le scelte di nessun altro, e nessuno
puo' prendersi il volto di un altro giocatore.

E' il tipo di garanzia che si rompe in silenzio il giorno in cui qualcuno
sostituisce l'elenco con un `glob()` della cartella, quindi adesso c'e' una
prova che lo verifica dal di fuori: si posa un file finto fra i caricati e si
controlla che non compaia ne' nel repertorio ne' nel catalogo.

Il nome di ogni file caricato e' l'impronta sha256 del suo contenuto, quindi non
si indovina. Perche' l'impronta serva a qualcosa, pero', la cartella non deve
essere **elencabile**: l'elenco e' gia' spento nella configurazione del sito, e
ora c'e' anche un `.htaccess` in ciascuna delle due cartelle dei caricati che lo
vieta per conto suo, nega i file che non siano immagini e spegne l'esecuzione.
E' la rete per il giorno in cui la configurazione del sito cambia senza che
nessuno ci pensi.

## Le quote del traffico, verificate (18 settembre 2026, notte)

Domanda diretta: le quote di incontro sono realistiche? Le ho misurate contro
quello che c'era davvero in Atlantico nel **gennaio 1942**, che e' la data in cui
il gioco e' ambientato. Cinque cose erano giuste, cinque no.

### Quello che tornava

**Convoglio contro navigazione isolata — 80/20.** Nel 1942 il grosso del traffico
era convogliato, ma le navi troppo veloci (oltre 15 nodi) e quelle troppo lente
viaggiavano sole, e soprattutto **la costa orientale americana non aveva ancora
un sistema di convogli**: fu introdotto solo a meta' maggio del 1942, ed e'
esattamente il motivo per cui l'operazione Paukenschlag fu un massacro. Le rotte
isolate del gioco danno infatti il peso piu' alto ai Caraibi e alla costa
statunitense.

**La percentuale di petroliere — 14%.** Le petroliere erano circa il 12-15% degli
scafi mercantili, ed erano il bersaglio che contava di piu'. Giusto.

**Le bandiere.** I pesi configurati (42% britannica, 18% statunitense, 12%
norvegese, 7% greca, 6% olandese, 6% neutrale, 5% panamense, 4% canadese) sono
coerenti con le flotte reali: la britannica largamente la prima, la norvegese
enorme rispetto al paese (Nortraship, circa mille navi), la greca e l'olandese
significative.

**Il buco dell'Atlantico centrale.** Le zone di copertura aerea si fermano a
Terranova da una parte e all'Islanda e agli Approaches dall'altra, e in mezzo non
c'e' niente: e' il *Black Pit*, il tratto fuori dalla portata degli aerei di base
che resto' aperto fino a meta' 1943. Modellato per omissione, ed e' giusto cosi'.

**La scorta media di 5-6 unita' per convoglio.** Bassa, ma il 1942 fu l'anno
della crisi delle scorte.

### Quello che non tornava, e che ho corretto

**Le Liberty al 14%.** La prima, la Patrick Henry, e' del 27 settembre 1941: nel
gennaio 1942 ce n'erano poche decine in mare in tutto il mondo. Diventano la nave
piu' comune dell'Atlantico solo dal 1943. Tenerle al livello di una classe
qualunque spostava in avanti di un anno l'aspetto dell'intero traffico. Scese al
3%, e tolte dalle rotte isolate.

**Le frigorifere al 13%.** Erano navi speciali e costose, intorno al 4% della
flotta mercantile. Scese al 5%.

**Le fregate classe River al 22% della scorta.** La prima, la Rother, entra in
servizio nell'**aprile 1942**, e la classe diventa comune nel 1943. Un quinto
delle scorte era fatto di navi che non esistevano ancora. Tolte del tutto; al
loro posto la corvetta Flower sale al 52%, che e' il ruolo che aveva davvero —
la spina dorsale, non una fra tante. Rientrano gli sloop Black Swan, che c'erano.

**La nave civetta a 14 per mille.** In tutta la guerra le Q-ship furono un
fallimento: la Royal Navy ne converti' una decina fra il 1939 e il 1940 e le
ritiro' senza risultati; la US Navy ci riprovo' nel 1942 e la prima, la USS Atik,
usci' a marzo e fu affondata subito da U-123. Nel gennaio 1942 in Atlantico non
ce n'era **nessuna**. Scesa a 2 per mille: resta un incontro possibile e
memorabile, ma da una volta nella carriera, non da una al mese. E' una
sovra-rappresentazione dichiarata, tenuta al minimo.

**Gli Swordfish sopra Terranova**, attribuiti a una portaerei di scorta. L'unica
portaerei di scorta dell'Atlantico, la Audacity, era stata affondata il **21
dicembre 1941**: a gennaio non ce n'erano. Tolti.

**Il piroscafo piccolo disarmato.** Dal 1942 il programma DEMS aveva messo un
pezzo a poppa alla grande maggioranza del naviglio mercantile britannico, compresi
i tramp piccoli — ed e' precisamente il motivo per cui l'attacco col cannone in
superficie smise di essere un affare per gli U-Boot. Adesso e' armato.

### Il difetto che non si vedeva: le bandiere si squilibravano da sole

I pesi erano giusti, ma il traffico **non li rispettava**. Misurato sul mondo in
esercizio: greca 1,4% invece di 7, olandese 2,1% invece di 6, statunitense 36%
invece di 18.

La causa e' il vincolo di unicita' sui nomi, introdotto in una tornata
precedente. Quando un nome estratto risultava gia' preso, il generatore
ritentava — e a ogni ritentativo ri-estraeva **anche la bandiera**. I repertori
nazionali pero' hanno taglie molto diverse: quello britannico e quello americano
sono combinatori e producono centinaia di nomi, quello greco ne aveva 44 e
quello olandese 35. Con seicento navi in mare i repertori piccoli si esaurivano
subito, ogni ritentativo li scartava, e il traffico scivolava verso i due
grandi.

Due correzioni. Il ritentativo adesso **resta sulla stessa bandiera** e ri-estrae
solo il nome. E i repertori piccoli sono diventati combinatori come gli altri,
secondo gli schemi veri: i greci col nome del santo o della famiglia armatrice e
il suffisso dell'armatore, gli olandesi col ceppo piu' la desinenza di compagnia
(*-dam* e *-dijk* per la Holland-Amerika, *-kerk* per il Rotterdamsche Lloyd).
Da 44 e 35 nomi a oltre duecento ciascuno.

Misurato dopo: britannica 44% (42 atteso), statunitense 15,4% (18), norvegese
13,8% (12), greca 7,5% (7), olandese 6,3% (6), neutrale 5,2% (6), panamense 4,4%
(5), canadese 3,5% (4). Entro due punti su tutte.

### Quello che resta deliberatamente assente

**Portaerei di scorta.** Nel gennaio 1942 non ce n'erano in Atlantico. La prima,
la Audacity, era durata tre mesi ed era gia' affondata; le successive (Avenger,
Biter, Dasher) arrivano da meta' 1942 e i gruppi di supporto con portaerei sono
del 1943. Un U-Boot del gennaio 1942 non ne incontrava.

**Liberator a lunghissimo raggio in quantita'.** Ce n'erano pochissimi — il 120
Squadron ne aveva ricevuti alcuni nel settembre 1941 — e sono rimasti nelle zone
in quantita' minima, non tolti del tutto.

Prove: `tests/test_sim.php`, sezione *Quote del traffico*, che tiene ferme le
quote nel sacchetto da cui il generatore pesca — perche' e' li' che vive la
decisione.

## L'invecchiamento si vede subito (18 settembre 2026, notte)

La casella «rendila d'epoca» adesso mostra l'effetto **mentre la si accende**, e
mentre si sposta l'inquadratura. Prima bisognava caricare per scoprire com'era
venuta.

Per farlo, la ricetta esiste in due posti: in PHP (`App\Game\Ritratto::invecchia`)
per chi carica senza JavaScript, e in JavaScript (`assets/js/invecchia.js`) per
l'anteprima. E' una duplicazione, ed e' il genere di duplicazione che si slega in
silenzio — qualcuno ritara i numeri da una parte e l'anteprima comincia a
raccontare una cosa diversa da quella che il server produce. Percio':

- i due file si rimandano l'un l'altro, in testa, con l'avvertimento;
- **c'e' una prova** che controlla che i numeri siano ancora gli stessi in tutte
  e due — luminanza 0,51, deviazione 0,215, vignettatura 0,22 con esponente 2,2,
  spinta all'85%, grana da −6 a +6, virata +2/+1/−1, bisezione fino a 48;
- quello che parte **e' gia' quello che si vede**: se l'ha invecchiata il
  browser, il modulo lo dice al server (`gia_invecchiata`) e il server non la
  rifa'. Applicarla due volte si vedrebbe.

### Le formule di GD, misurate e non supposte

Perche' l'anteprima somigliasse davvero al risultato, ho misurato come si
comportano i filtri di GD invece di fidarmi della documentazione:

| Filtro | Comportamento misurato |
|---|---|
| `IMG_FILTER_GRAYSCALE` | `0,299 R + 0,587 G + 0,114 B` |
| `IMG_FILTER_CONTRAST` | `((v/255 − 0,5) × f + 0,5) × 255` con `f = ((100 − arg)/100)²` |
| `IMG_FILTER_GAUSSIAN_BLUR` | nucleo 3×3 `[1 2 1 / 2 4 2 / 1 2 1]` diviso 16 |

La seconda riga e' anche la spiegazione formale di una cosa trovata prima: e'
`f` a essere al quadrato di `(100 − arg)`, quindi **un argomento positivo
abbassa il contrasto** e uno negativo lo alza. Il contrario di quello che
sembra.

### Verificato, non sperato

Stessa fotografia, stesso ritaglio, passata dalle due strade:

| | luminanza | deviazione standard | scarto rosso-blu |
|---|---|---|---|
| PHP (il server) | 0,4786 | 0,19657 | +3,00 |
| JavaScript (l'anteprima) | 0,4797 | 0,19660 | +3,00 |

Scarto quadratico medio fra le due immagini: **2,1%**, che e' la grana — l'unico
passaggio che per sua natura non puo' coincidere, perche' e' rumore casuale.

## Un riquadro nero, e la prova che mancava (18 settembre 2026, notte fonda)

Estraendo il filtro d'epoca in un file suo — `assets/js/invecchia.js`, perche'
servisse anche all'anteprima — dentro il ritaglio ci era finita per sbaglio
**anche `limita()`**, che serviva a chi chiamava. Risultato: scegliendo una
fotografia il riquadro si riempiva di nero e si fermava li'. Il `fillRect` nero
c'era gia' stato, poi `limita()` non esisteva piu' e il `drawImage` non veniva
mai eseguito.

La cosa istruttiva e' **perche' non me ne ero accorto**. Avevo controllato la
sintassi di tutti e due i file, e avevo verificato il filtro nuovo con una
pagina di prova che lo chiamava direttamente. Tutto verde. Ma avevo provato *il
pezzo che avevo cambiato*, non *la strada che percorre chi usa il gioco*: quella
pagina di prova non apriva mai il widget vero, e il widget vero era rotto.

Da qui `tests/e2e_browser.sh`, che e' l'unica prova che **esegue** il
JavaScript. Apre una pagina con il widget vero, sceglie un file come lo
sceglierebbe una persona, e controlla tre cose: che non voli un errore, che il
riquadro non resti nero, e che accendendo la casella d'epoca l'immagine cambi
davvero. Se nel sistema non c'e' un browser si salta dicendolo — meglio saltata
che finta.

Verificata al contrario, che e' l'unico modo di sapere se una prova serve:
rimesso il difetto, la prova diventa rossa su due righe; tolto, torna verde.
