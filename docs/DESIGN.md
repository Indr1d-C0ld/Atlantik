# ATLANTIK — Documento di progettazione

**Simulatore strategico multigiocatore persistente della Battaglia dell'Atlantico.**
Il giocatore e' sempre e solo il *Kommandant* di un U-Boot. Tutto il resto — naviglio
mercantile, convogli, scorte, aerei, BdU, meteo, oceano — e' mosso dal motore secondo
regole storicamente coerenti.

Versione documento: 1.0 — 17/09/2026
Stack: PHP 8.4 (senza framework) · MariaDB 11.8 · Apache · vanilla JS/Canvas · cron tick
Percorso d'installazione: una cartella servita dal web server · segreti in un file **fuori dal DocumentRoot**

---

## 0. Le quattro decisioni fondanti (prese con l'utente)

| # | Decisione | Conseguenza architetturale |
|---|-----------|----------------------------|
| 1 | **Tempo ibrido**: crociera a tempo compresso 24/7 + fase tattica live su contatto | Doppio motore: `world tick` (cron, passo grosso) e `tactical step` (lazy, passo fine). Stato del battello = macchina a stati. |
| 2 | **Periodo fisso "contenitore"**: l'ambientazione contiene l'intero arsenale 1939‑1945, ma lo sblocco e' **per merito individuale**, non per data storica | Nessuna escalation globale a calendario. La difficolta' scala per **zona** e per **pressione (Heat)**, non per anno. Albero tecnologico per comandante. |
| 3 | **Rudeltaktik opzionale**: branchi co-op asincroni **oppure** patrol autonome | Doppio percorso di missione con economie di ricompensa distinte. Convogli persistenti condivisi in ogni caso. |
| 4 | **Permadeath con eredita' di flottiglia** + catena di sopravvivenza | Il comandante e' un'entita' mortale e archiviabile; l'account sopravvive al comandante. Albo d'oro permanente. |

### 0.1 Nota sulla decisione 2 (e su come restare storicamente onesti)

L'utente ha scelto un mondo che non invecchia lungo il calendario di guerra. Il rischio e'
l'anacronismo: un Type XXI col Zaunkoenig che incrocia un convoglio scortato solo da
ASDIC del 1939. La soluzione progettuale e' **spostare l'asse della difficolta' dalla data
allo spazio e alla reazione del nemico**:

- **Il mondo e' ancorato a un "eterno 1942"** — l'anno di massima densita' operativa e di
  massimo equilibrio fra le due parti. E' l'anno che fa da riferimento per rotte, densita'
  di traffico, dottrine, colori, uniformi, comunicati.
- **La tecnologia e' un albero di sblocco personale**: ogni pezzo porta con se' la sua data
  storica reale (mostrata nella scheda: *"G7es T5 Zaunkoenig — in servizio dal settembre
  1943"*), ma la si ottiene per anzianita', tonnellaggio e prestigio. La verita' storica
  resta **documentata**, non simulata sul calendario.
- **La controparte scala con te, in modo diegetico**: il contrasto antisommergibile
  alleato non "sale di livello", *reagisce*. Ogni settore ha un valore di **Heat** (0‑100)
  che sale con gli affondamenti, le trasmissioni radio intercettate e gli avvistamenti; e
  scende nel tempo. Heat alto = scorte piu' numerose e meglio addestrate, gruppi di
  supporto, copertura aerea estesa, convogli deviati. E' esattamente cio' che l'Ammiragliato
  faceva davvero, e produce la stessa curva di difficolta' senza barare col calendario.
- **La geografia resta il vero gradiente**: il **Mid-Atlantic Gap** (il "buco aereo" a sud
  della Groenlandia, quadrati AK/AJ/BC) e' e resta la zona dove non arrivano i Liberator —
  la caccia grossa; il **Golfo di Biscaglia** resta il corridoio mortale del transito; le
  Approaches Occidentali restano l'inferno dei riflettori Leigh.

---

## 1. Il mondo

### 1.1 Spazio: la griglia Marinequadrat (Gradnetzmeldeverfahren)

Il gioco adotta **il vero sistema di quadrettatura della Kriegsmarine**, non una griglia
inventata. E' l'elemento identitario piu' forte dell'interfaccia: il comandante non pensa
in latitudine/longitudine, pensa in *"Quadrat AL 0278"*.

- Il globo e' diviso in **grandi quadrati** identificati da due lettere (AJ, AK, AL, AM,
  BD, BE, BF, CG... per l'Atlantico settentrionale e centrale), di **9° di longitudine ×
  6° di latitudine**.
- Ogni grande quadrato e' suddiviso **ricorsivamente in 3×3 = 9 sotto-quadrati numerati
  1‑9** (in lettura per righe: 1 2 3 / 4 5 6 / 7 8 9).
- Ogni cifra aggiunta stringe di un fattore 3. Quattro cifre — la risoluzione dei rapporti
  operativi reali — portano a circa **20′ × 13,3′**, cioe' circa **7 nm × 4,4 nm**: la
  precisione con cui si dava un contatto.

Implementazione: funzioni pure `Grid::toQuadrat(lat, lon): string` e
`Grid::fromQuadrat(string): [lat, lon, spanLat, spanLon]`, con test unitari su coordinate
storiche note. La mappa a video mostra la griglia sovrapposta alla carta nautica; la
posizione stimata dall'Obersteuermann e' sempre espressa in Quadrat, la latitudine/
longitudine esatta compare **solo dopo un punto nave riuscito** (vedi 1.5).

L'area giocabile: Atlantico dal Circolo Polare Artico (a nord di Islanda/Faer Oer) fino
al Sud Atlantico (Freetown, rotta del Capo), da Terranova/Caraibi alle coste europee e
africane. Zone modellate con densita' e carattere propri:

| Zona | Quadrati | Carattere |
|---|---|---|
| Golfo di Biscaglia | BF | Transito obbligato, caccia aerea, Leigh Light, Metox indispensabile |
| Approaches Occidentali | AM / AL est | Traffico densissimo, scorte fitte, aerei costieri |
| Mid-Atlantic Gap | AK / AJ / BC nord | Il paradiso: convogli scoperti, poca aria, branchi |
| Rotta Halifax‑UK | BB / BC / AK | HX (veloci) e SC (lenti) |
| Terranova / Canada | BA / BB ovest | Punti di raccolta convogli, nebbia, ghiaccio |
| Caraibi / Aruba | DM / EC | Petroliere isolate, poche scorte, distanza enorme |
| Freetown / Sud | ET / FE | Navi isolate, caldo, usura, autonomia |
| Artico / Norvegia | AB / AC | Convogli russi, notte polare, ghiaccio |

### 1.2 Tempo: due orologi

**Orologio di crociera (compresso).** Rapporto di default **1:30** — un minuto reale = 30
minuti di gioco; **1 ora reale = 30 ore di gioco**; una patrol storica di 4‑6 settimane
diventa **1,5‑2 settimane reali**. Il valore e' una chiave di configurazione
(`world.time_ratio`) tarabile in beta.

**Orologio tattico (live).** Quando scatta un contatto, il battello passa in
`tactical`: il rapporto scende a **1:4** in avvicinamento e **1:1** in attacco o in
evasione sotto cariche di profondita'. La finestra reale concessa al giocatore e' di
15‑30 minuti; scaduta, o se il giocatore non si presenta, **subentra l'I.WO** (vedi 4.4).

**Come avanza davvero la simulazione.** Il cron (`bin/tick.php`, ogni minuto) non "muove"
gli oggetti uno a uno: calcola per ciascuno lo stato al tempo attuale **integrando
analiticamente** dall'ultimo timestamp, a sotto-passi (5 minuti di gioco in crociera, 10
secondi in tattico). Ogni entita' ha un PRNG deterministico seminato da
`(world_seed, entity_id, tick_index)`: lo stesso intervallo ricalcolato due volte da'
lo stesso risultato. Questo rende il motore **lazy, idempotente e rigiocabile** — il
diario di bordo puo' essere ricostruito evento per evento, e una richiesta HTTP del
giocatore puo' far avanzare la sua simulazione senza aspettare il cron.

### 1.3 Luce: sole, luna, crepuscolo

Motore astronomico reale (algoritmi NOAA semplificati, nessuna dipendenza esterna):

- Altezza e azimut del sole per lat/lon/istante → giorno, **crepuscolo civile/nautico/
  astronomico**, notte. Il crepuscolo nautico e' il momento d'oro dell'attacco in
  superficie: la nave si staglia contro il cielo chiaro, l'U-Boot e' nel buio.
- **Fase lunare** (ciclo sinodico 29,53 g) + altezza della luna: la luna piena alta e'
  il nemico dell'attacco notturno in superficie; la luna nuova e' l'alleata. Il celebre
  attacco notturno di superficie di Kretschmer ha senso solo in questo modello.
- Nuvolosita' (dal meteo) attenua entrambe.

Il risultato e' un valore scalare **`light_level` 0.00‑1.00** che entra direttamente nella
formula di avvistamento visivo (3.1), e che l'interfaccia rende come colore della plancia:
**illuminazione rossa notturna** in torretta di notte, luce bianca di giorno.

### 1.4 Meteo e mare

Sistema meteo a celle che si spostano (fronti da ovest a est, come nell'Atlantico reale),
con stagionalita': l'Atlantico del nord in inverno e' un'altra cosa rispetto ad agosto.

Grandezze simulate per cella: **direzione e forza del vento (scala Beaufort 0‑12)**,
**stato del mare (Douglas 0‑9)**, **visibilita' (nebbia/foschia/pioggia, 0‑20 nm)**,
**nuvolosita'**, **pressione barometrica** (utile al giocatore: il barometro in crollo
annuncia la burrasca), **temperatura** (→ strato termico, 3.2).

Effetti, tutti storicamente fondati:
- Mare forza 6+: impossibile usare il cannone di coperta; siluri con assetto instabile;
  vedette dimezzate; velocita' in superficie ridotta; uomini di guardia legati con cinghie.
- Mare grosso: **l'ASDIC alleato peggiora molto** e le cariche di profondita' sono meno
  precise → il cattivo tempo e' rifugio e condanna insieme.
- Nebbia: azzera l'avvistamento visivo reciproco ma non l'idrofono ne' il radar → e' il
  momento in cui il radar alleato diventa decisivo.
- Mare calmo e luna: il periscopio lascia scia visibile; l'immersione a quota periscopica
  diventa rischiosa di giorno.
- Burrasca: consumo carburante +, usura macchine +, morale −, mal di mare per l'equipaggio
  nuovo, rischio di uomo in mare se si mantiene la guardia in coperta.

### 1.5 Navigazione e incertezza di posizione

Il giocatore **non conosce con certezza la propria posizione**. Il motore tiene due valori:
posizione vera e **posizione stimata** dall'Obersteuermann.

- In navigazione stimata (dead reckoning) l'errore cresce con il tempo, la corrente, il
  vento e lo scarroccio: circa **1‑3 nm ogni 12 ore**, modulato dall'abilita' del
  navigatore.
- Un **punto nave astronomico** (sestante, sole a mezzogiorno o stelle al crepuscolo)
  azzera quasi l'errore — ma richiede **superficie, orizzonte visibile e cielo sereno**.
  Con una settimana di cielo coperto in Nord Atlantico l'errore diventa serio: e' successo
  davvero, e produceva appuntamenti mancati coi convogli.
- I radiogoniometri costieri tedeschi e i segnali di posizione del BdU danno correzioni
  parziali, **al prezzo di trasmettere** (vedi 6.3 HF/DF).

Correnti reali modellate in modo grossolano ma coerente: Corrente del Golfo, Labrador,
Canarie, Nord Atlantica — spostano di qualche decimo di nodo e contribuiscono all'errore.

---

## 2. Il battello

### 2.1 I tipi (tutti a dato storico, in tabella DB `uboat_types`)

Ogni riga porta con se' le specifiche reali e un campo `fonte`. Valori indicativi che
useremo come base del bilanciamento:

| Tipo | Dislocamento (sup./imm.) | Vel. sup./imm. | Autonomia | Siluri / tubi | Quota di prova / collasso | Immersione rapida | Equipaggio |
|---|---|---|---|---|---|---|---|
| **II D** ("Einbaum") | 314 / 364 t | 12,7 / 7,4 kn | 5.650 nm @ 8 kn | 5 / 3 prua | 100 / ~150 m | ~25 s | 25 |
| **VII B** | 753 / 857 t | 17,9 / 8,0 kn | 8.700 nm @ 10 kn | 14 / 4+1 | 100 / 220‑250 m | ~30 s | 44‑48 |
| **VII C** (il cavallo da tiro) | 769 / 871 t | 17,7 / 7,6 kn | 8.500 nm @ 10 kn | 14 / 4+1 | 100 / 220‑250 m | ~30 s | 44‑52 |
| **VII C/41** | 759 / 860 t | 17,7 / 7,6 kn | 8.500 nm @ 10 kn | 14 / 4+1 | 120 / ~280 m | ~30 s | 44‑52 |
| **IX B** | 1.051 / 1.178 t | 18,2 / 7,3 kn | 12.000 nm @ 10 kn | 22 / 4+2 | 100 / ~230 m | ~35 s | 48‑56 |
| **IX C/40** | 1.144 / 1.257 t | 19,0 / 7,3 kn | 13.850 nm @ 10 kn | 22 / 4+2 | 100 / ~230 m | ~37 s | 48‑56 |
| **IX D2** | 1.616 / 1.804 t | 20,8 / 6,9 kn | 23.700 nm @ 12 kn | 24 / 4+2 | 100 / ~230 m | ~45 s | 55‑63 |
| **XXI** (Elektroboot, tardo) | 1.621 / 1.819 t | 15,6 / **17,2** kn | 15.500 nm @ 10 kn | 23 / 6 prua | 135 / ~330 m | ~20 s | 57 |
| **XIV** ("Milchkuh") | 1.688 / 1.932 t | 14,4 / 6,2 kn | 9.300 nm @ 12 kn | 0 (4 siluri di riserva da cedere) | 100 / ~240 m | ~40 s | 53‑60 |

Il Type XIV **non e' pilotabile dal giocatore**: e' l'asset di rifornimento d'altura (6.5).
Il Type XXI e' il vertice dell'albero di sblocco, raggiungibile solo a carriera molto
avanzata — e l'interfaccia ne ricorda la data reale (1944‑45).

Autonomia in immersione (comune a VII/IX): **circa 80 nm a 4 nodi**, oppure pochi minuti
alla massima; batteria da 2×62 elementi. Questa e' la risorsa che detta il ritmo della
caccia: chi corre sott'acqua muore.

### 2.2 Armamento subacqueo — i siluri

Il sistema siluri e' uno dei due pilastri simulativi (con il rilevamento). Ogni tipo ha
profilo, difetti e impiego storici:

| Siluro | Propulsione | Velocita' / gittata | Caratteristiche e vizi |
|---|---|---|---|
| **G7a (T I)** | vapore-aria | 30 kn / 12.500 m · 40 kn / 7.500 m · 44 kn / 5.500 m | Veloce e a lunga gittata, ma lascia **scia di bolle visibile** → suicidio di giorno, rischioso con luna |
| **G7e (T II)** | elettrico | 30 kn / 5.000 m | **Senza scia**. Richiede preriscaldamento della batteria: se non riscaldato, gittata e velocita' calano |
| **G7e (T III)** | elettrico | 30 kn / 7.500 m | Il siluro standard della guerra |
| **FAT / LUT** | elettrico | come T III | **Traiettoria a serpentina** dentro la formazione del convoglio: si spara nel "mucchio" e si aspetta. Vietato l'uso in solitaria vicino ad altri U-Boot |
| **G7es (T5) Zaunkoenig** | elettrico acustico | 24,5 kn / 5.700 m | **Insegue il rumore d'elica** della scorta (efficace fra 10 e 18 kn). Dopo il lancio **bisogna immergersi sotto i 60 m e allontanarsi**: puo' tornare sul lanciatore. Contromisura alleata: il *Foxer* rimorchiato |

**Spolette e la crisi dei siluri.** Pistola magnetica **Pi1** (esplosione sotto la chiglia,
devastante) e pistola a contatto. Il difetto storico — profondita' di corsa eccessiva,
magnetica che detonava in anticipo o non detonava — e' **modellato come parametro di
affidabilita' dell'arsenale**, non come punizione casuale gratuita: l'equipaggiamento
"vecchio stock" ha tassi di fallimento sensibilmente piu' alti ed e' piu' economico; le
partite collaudate costano piu' punti di assegnazione. Il fallimento viene sempre
*raccontato* nel diario ("*Fehlschuss — Torpedo lief zu tief*").

**Soluzione di tiro (TDC / Vorhaltrechner).** Il cuore dell'attacco. Il giocatore, dal
periscopio o dall'UZO in torretta, stima:
- **rilevamento** (bearing) — misurato;
- **AOB** (angolo sulla prua del bersaglio) — stimato a occhio;
- **distanza** — dal telemetro a coincidenza / altezza dell'albero sul reticolo;
- **velocita' del bersaglio** — da cronometro sui 3 rilevamenti o dal conteggio giri d'elica
  all'idrofono.
Il TDC calcola l'angolo di giro del siluro. **Gli errori di stima si propagano**: un errore
di 2 nodi sulla velocita' a 2.000 m e' un mancato colpo. Sara' possibile delegare la
stima all'I.WO (meno preciso, ma non chiede nulla al giocatore) oppure farla a mano con
gli strumenti, guadagnando precisione e prestigio. Sono previsti: salva a ventaglio
(*Faecherschuss*), lancio singolo, profondita' di corsa, spoletta, tubo di poppa.

### 2.3 Artiglieria

- **8,8 cm SK C/35** (Type VII, ~220 colpi) e **10,5 cm SK C/32** (Type IX): il modo
  economico di affondare una nave isolata e disarmata — e il modo rapido di morire se la
  nave e' una *Q-ship* o ha un cannone a poppa (le *DEMS* armate britanniche). Utilizzabile
  solo in superficie, mare ≤ 4, e richiede uomini in coperta (esposti).
- **Flak**: 2 cm C/30 singola → 2 cm gemella → **2 cm Flakvierling** (quadrinata) e 3,7 cm
  sul "Wintergarten". Restare a combattere contro un aereo e' quasi sempre l'errore che
  uccide — ma a volte e' l'unica scelta se l'immersione e' troppo lenta.

### 2.4 Sensori ed elettronica

**Nostri (tedeschi):**
- **GHG** (*Gruppenhorchgeraet*): idrofono passivo ad array, il sensore piu' importante
  del battello. Rilevamento su 360° (con zona d'ombra a poppa per il rumore proprio),
  discriminazione grossolana di numero eliche, tipo (mercantile/scorta), regime giri.
- **KDB**: base rotante, meno sensibile, montata in coperta, vulnerabile ai danni.
- **S‑Gerat / Nibelung**: sonar attivo — utilizzabile ma **rivela la propria posizione**.
- **FuMO 29/30 Seetakt** poi **FuMO 61 Hohentwiel**: radar di bordo. Poco usati davvero,
  e per buoni motivi: emettere e' farsi trovare.
- **Radar-Warner**: **Metox FuMB1** (banda metrica 1,5 m — vede l'ASV Mk II) e **Naxos
  FuMB7** (banda centimetrica 10 cm — vede l'ASV Mk III). Il Metox da solo, contro un
  aereo con radar centimetrico, **non suona**: la trappola che uccise decine di battelli.
  Il Metox inoltre **irradia** debolmente (la Kriegsmarine lo sospetto' e ne vieto' l'uso).
- **Schnorchel** (sblocco tardivo): permette di ricaricare le batterie a quota periscopica.
  Cambia il gioco — e porta i suoi guai: testa d'albero rilevabile dal radar, valvola che
  chiude e crea depressione nello scafo (dolore agli orecchi, motori che si spengono),
  velocita' limitata a 6 kn.
- **Contromisure**: **Bold / Pillenwerfer** (cartuccia chimica che genera una nuvola di
  bolle: un falso bersaglio per l'ASDIC), **Aphrodite** (pallone con strisce metalliche,
  falso eco radar), e la piu' antica di tutte — **Schleichfahrt**, la marcia silenziosa.

**Loro (alleati), modellati come capacita' delle unita' NPC:**
- **ASDIC** (sonar attivo): il "ping". Portata utile 1.000‑2.500 m, degradata da mare
  mosso, scia, strato termico; e **cieco negli ultimi ~200 m dell'accosto**, perche' il
  fascio passa sopra il bersaglio. Da qui l'attacco alla cieca con le cariche di
  profondita' e, poi, le armi a lancio in avanti.
- **Cariche di profondita'** Mk VII / Mk VII Heavy: velocita' di discesa 3‑4,3 m/s, raggio
  di distruzione ~6 m, di danno ~12‑15 m. Il rullo di lanci e' il momento peggiore della
  vita a bordo, ed e' progettato per esserlo.
- **Hedgehog** (24 bombe a contatto lanciate in avanti, niente perdita di contatto ASDIC)
  e **Squid**: sbloccati come "capacita' d'elite" delle scorte a Heat alto.
- **Radar ASV/Type 271 centimetrico**: vede la torretta a 5‑8 nm, e in mare calmo vede il
  **periscopio**. E' cio' che chiuse la notte in superficie.
- **HF/DF ("Huff-Duff")** imbarcato: rileva la *direzione* di ogni nostra trasmissione HF.
  Due navi che rilevano = un punto. Vedi 6.3: e' la meccanica che rende la radio un rischio.
- **Leigh Light**: faro da 22 milioni di candele su aereo con radar — l'agguato notturno
  in Biscaglia.
- **Support Groups e portaerei di scorta**: a Heat alto arrivano i gruppi di caccia che non
  sono legati al convoglio e possono **restare sul contatto per ore** finche' la batteria
  non finisce. E' cosi' che si moriva davvero.

### 2.5 Compartimenti, sistemi, danni

Il battello e' modellato **a compartimenti** (dalla prua): camera siluri di prua ·
alloggi sottufficiali · quadrato ufficiali/radio-idrofono · **Zentrale** (centrale) con
torretta · cucina/alloggi · locale diesel · locale motori elettrici · camera siluri di
poppa. Ogni compartimento ha integrita', allagamento, incendio, personale presente.

Sistemi indipendenti con stato 0‑100: periscopio d'attacco, periscopio di scoperta,
timoni orizzontali (prua/poppa), timone verticale, diesel 1/2, motori elettrici 1/2,
batterie (con **rischio di cloro** se allagate — e il cloro uccide), compressori, pompe di
esaurimento, casse di zavorra e regolazione, tubi lanciasiluri, radio, GHG, Metox/Naxos,
cannone, flak, scafo resistente.

**Danni.** Tre sorgenti:
1. **Combattimento** — cariche di profondita' (danno che scala con la distanza
   dell'esplosione e con la **profondita' attuale**: piu' si e' fondi, piu' lo scafo e'
   gia' sollecitato), colpi d'artiglieria, mitragliamento aereo, speronamento.
2. **Pressione** — oltre la quota di prova, ogni minuto in profondita' accumula
   deformazione permanente; i rivetti "cantano", le guarnizioni cedono. La quota di
   collasso non e' una linea netta ma **una distribuzione**: e' influenzata dai danni gia'
   subiti e dall'eta' dello scafo. Il giocatore puo' scendere a 250 m per sfuggire alle
   cariche — a un prezzo che non conosce con esattezza. Cosi' era.
3. **Avaria e manutenzione** — probabilita' per ora-macchina crescente con: giorni di
   patrol, ore di regime forzato (AK, *Aeusserste Kraft*), fatica e qualita' dell'equipaggio,
   danni pregressi non riparati, burrasca. Un diesel che si pianta in Biscaglia e' un
   evento di gioco piu' interessante di qualunque combattimento.

**Riparazioni**: l'equipaggio ripara in mare secondo competenza (il **LI** e i macchinisti
sono decisivi), con tempi realistici e con la regola che alcune cose **non si riparano a
mare**: periscopio piegato, scafo deformato, tubi di poppa schiacciati. Quella e' la fine
della patrol, e il ritorno alla base diventa esso stesso una missione.

---

## 3. Il rilevamento — il vero cuore del gioco

Tutto il gioco e' un problema di informazione asimmetrica: chi vede per primo, vive. Il
modello e' **simmetrico e bidirezionale**: le stesse formule con cui noi vediamo loro li
governano quando cercano noi.

### 3.1 Avvistamento visivo

Probabilita' di rilevamento per intervallo, funzione di:

```
P_vista = f( silhouette, distanza, light_level, meteo, mare, qualita'/numero vedette,
             fatica, settore di scansione, fumo/scia, aspetto )
```

- **Silhouette**: superficie (torretta + scafo) ≫ *Sehrohrtiefe* awash (solo torretta) >
  quota periscopica (solo tubo) > immerso (nulla). Ordini di grandezza storici: un
  mercantile di giorno con buona visibilita' e' visto dal ponte di un U-Boot a 8‑12 nm
  (il fumo anche a 15‑20 nm, prima dello scafo: il *fumo prima dell'albero*, dettaglio che
  vogliamo in gioco); un U-Boot in superficie di notte senza luna e' visto da una scorta a
  1‑2 nm; con luna piena, a 4‑6 nm.
- **Il fumo** e' un canale a se': i mercantili a carbone mal condotti fumavano, e il fumo
  all'orizzonte e' il primo indizio di convoglio. Il motore genera *contatti di fumo* come
  evento distinto, senza identificazione.
- **Aspetto (angolo)**: presentare la prua riduce moltissimo la propria silhouette — e'
  una manovra tattica reale ("*bows on*").
- **Vedette**: 4 uomini in torretta, un settore ciascuno; qualita' e fatica contano;
  con mare grosso e spruzzi la loro efficacia crolla.

### 3.2 Idrofono (GHG) e acustica

Il canale che funziona quando gli occhi non servono. Modello:

```
SNR = SL(sorgente) - TL(distanza, strato) - NL(rumore ambiente) + DI(array)
```

- **SL (livello di sorgente)**: dipende da tipo di nave, numero d'eliche, **regime giri** e
  soprattutto **cavitazione** (una nave lenta e' molto piu' silenziosa). Un convoglio di 40
  navi e' un muro di rumore udibile a **30‑50 nm** in buone condizioni: e' cosi' che li si
  trovava davvero.
- **TL**: perdita per distanza + **strato termico** (*Deckschicht*). Se ci si mette **sotto
  lo strato**, l'ASDIC nemico fatica moltissimo — ma anche il nostro idrofono perde il
  contatto. Scelta tattica vera, disponibile solo dove e quando lo strato esiste (funzione
  di stagione, latitudine, meteo).
- **NL**: mare mosso, pioggia, rumore proprio (motori, pompe, ventilatori, cucina).
- **Schleichfahrt (marcia silenziosa)**: 2 nodi, pompe ferme, uomini a riposo, niente
  cucina, ordini a bassa voce → il nostro NL crolla e il nostro SL diventa minimo. Prezzo:
  aria e batteria consumate a tempo, temperatura e CO2 che salgono, morale che scende.
- Il giocatore vede la **schermata idrofono**: un rilevamento, un'intensita', una
  classificazione probabilistica ("*Schraubengeraeusche* — mercantile, forse due"). Mai
  una verita' pulita.

### 3.3 Radar, radiogoniometria, e il rilevamento di noi

- **Radar alleato** (superficie e aereo): probabilita' di aggancio in funzione di sezione
  radar (torretta > periscopio > nulla), mare (il "clutter" del mare grosso nasconde),
  distanza, tipo di radar.
- **Il nostro Radar-Warner** avverte *solo* sulla banda che copre — e la discrepanza
  Metox/Naxos e' una vera trappola progettata.
- **HF/DF**: ogni trasmissione produce un arco di rilevamento per ogni unita' HF/DF in
  portata; due o piu' archi → un punto con errore. Se il punto e' buono, parte una caccia.

### 3.4 Il contatto: come diventa gioco

Il motore, quando una prova di rilevamento riesce, crea un **`contact`** con:
tipo di sensore, rilevamento, distanza stimata (con errore), classificazione parziale,
istante, e **certezza**. Il contatto e' *soggettivo*: esiste nella testa dell'equipaggio,
non nel mondo. Se e' rilevante, il battello passa in stato `tactical`, parte la
notifica (in pagina; opzionalmente e-mail/push per i contatti maggiori) e si apre la
finestra di condotta.

---

## 4. Equipaggio, comando, morale

### 4.1 Ruoli storici

**Ufficiali**: Kommandant (il giocatore) · **I.WO** (primo ufficiale di guardia: attacco
silurico, TDC) · **II.WO** (artiglieria e flak) · **LI** *Leitender Ingenieur* (il
signore dell'immersione e dell'assetto: e' lui che ti salva) · **Obersteuermann**
(navigatore, punto nave).
**Sottufficiali e comuni**: Bootsmann, Zentralemaat, Funkmaat (radio + idrofono),
Diesel- ed E‑Maschinisten, Torpedomixer, Smutje (il cuoco: conta davvero per il morale),
Matrosen alle vedette.

Ogni uomo ha: nome storicamente plausibile, grado, **competenza per specialita'** (0‑100),
**fatica**, **morale**, stato di salute, anzianita' e — nel tempo — un carattere. Gli uomini
si possono **addestrare fra una patrol e l'altra** (le "licenze e corsi" dell'economia),
si feriscono, muoiono, e i migliori vengono trasferiti via per formare nuovi equipaggi
(succedeva: la diluizione della qualita' fu una delle cause del crollo del 1943).

### 4.2 Turni, fatica e la vita a bordo

Tre guardie da 4 ore. Il giocatore assegna gli uomini alle stazioni e decide i turni; ogni
stazione ha un rendimento pari a competenza × (1 − fatica). Le stazioni critiche
(idrofono, timoni, TDC) rendono molto di piu' con l'uomo giusto e riposato.

Condizioni ambientali di bordo simulate perche' influenzano tutto: **CO2** (sale in
immersione: sopra soglia compaiono mal di testa, errori, poi collasso — le cartucce di
potassa e le bombole d'ossigeno sono consumabili), **temperatura e umidita'** (la muffa,
il "pane bianco di muffa" di cui tutti scrivevano), **acqua dolce razionata**, **cibo**
(fresco per i primi giorni, poi conserve: il morale scende), **una sola latrina utile**.

### 4.3 Morale

Sale con: affondamenti, licenza, cibo fresco, riparazioni riuscite, musica al grammofono,
un comandante che decide (l'indecisione e' penalizzata). Scende con: cariche di
profondita' prolungate, morti e feriti, patrol lunghe, avarie, quota estrema, fame d'aria,
ordini incoerenti, fallimenti ripetuti al lancio. Morale basso = tempi di reazione
peggiori, piu' errori, piu' avarie, e in casi estremi ordini eseguiti male.

### 4.4 L'I.WO come agente autonomo (fondamentale per il modello a tempo compresso)

Il giocatore non puo' essere sempre davanti al browser. Il **Primo Ufficiale** e' un agente
che opera secondo una **dottrina d'ingaggio** impostata dal giocatore:

- **Regole d'ingaggio**: attaccare navi isolate sopra N GRT · non attaccare scorte ·
  segnalare e pedinare i convogli senza attaccare · immergersi a ogni contatto aereo ·
  risparmiare gli ultimi due siluri · rientrare sotto X tonnellate di nafta.
- **Comportamento di default alla scadenza della finestra tattica**: prudente. Immersione,
  disimpegno, rapporto nel diario. Puo' attaccare da solo se la dottrina lo consente, con
  penalita' di precisione (e' bravo, ma non e' il comandante).
- Piu' l'I.WO e' esperto, meglio se la cava. Perderlo in combattimento e' un colpo serio.

Questo risolve elegantemente il problema del gioco asincrono: **il battello non e' mai
"in pausa", e' comandato da qualcuno** — che e' esattamente la finzione giusta.

---

## 5. Il mondo NPC: naviglio, convogli, aerei

### 5.1 Generazione del traffico

Non si generano "nemici attorno al giocatore" (l'errore classico che rompe la simulazione):
si simula **una rete di rotte mercantili persistente**, e il giocatore ci si imbatte — o non
ci si imbatte affatto, e quella settimana di mare vuoto e' *anch'essa* il gioco.

- **Rotte**: grafo di porti reali (Halifax, Sydney CB, New York, Liverpool, Glasgow,
  Bristol, Gibilterra, Freetown, Trinidad, Aruba, Città del Capo, Murmansk, Reykjavik…)
  con archi pesati sul traffico storico reale.
- **Navi isolate** (*Einzelfahrer*): generate lungo le rotte con densita' per zona e per
  ora; battono bandiera britannica, americana, norvegese, greca, panamense, ma anche
  **neutrale** (spagnola, svedese, portoghese, irlandese, turca) — e affondare un neutrale
  ha conseguenze (5.4).
- **Convogli**: entita' persistenti di prima classe con serie storica reale
  (**HX** Halifax→UK veloce ~9,5 kn · **SC** Sydney→UK lento ~7 kn · **ON/ONS** in uscita ·
  **OG/HG** Gibilterra · **SL** Freetown · **TM** petroliere), **numero progressivo**
  (HX 229, SC 122…), composizione in colonne (es. 9 colonne × 5 navi, 1.000 yd fra colonne,
  600 yd fra navi in colonna), **commodoro**, nave soccorso in coda, scorta assegnata,
  **zigzag** secondo schemi dell'Ammiragliato, e rotta con *routing* che puo' cambiare se
  l'Ammiragliato sospetta la presenza di U-Boot (Heat!).

### 5.2 Anagrafe delle navi

Generatore di **nomi realistici per bandiera e tipo**: piroscafi britannici (*Empire …*,
*Fort …*, nomi di città e di fiumi), *Liberty ship* americane (nomi di personaggi storici),
petroliere norvegesi, tramp greci. Ogni nave ha: **nome, bandiera, tipo** (cargo piccolo/
medio/grande, petroliera, trasporto truppe, frigorifera, nave da carico armata DEMS,
Q‑ship, rimorchiatore, peschereccio), **GRT reale plausibile**, carico (che influenza il
valore e il comportamento: una petroliera carica esplode, una zavorrata no), velocita',
armamento difensivo, e resistenza ai danni per compartimenti.

Le **scorte**: corvette Flower, cacciatorpediniere V&W e classe Town, fregate River,
sloop, corvette canadesi; ognuna con sensori, armi ASW, e — decisiva — una **qualita' di
equipaggio** che a Heat alto include i gruppi d'elite (Walker e il 2nd Support Group sono
un incubo meritato).

### 5.3 Intelligenza artificiale del nemico

Tre livelli, tutti server-side:
1. **Strategico** (Ammiragliato): instrada i convogli, decide le deviazioni, distribuisce
   scorte e copertura aerea in funzione del Heat e dei rapporti di contatto ricevuti.
2. **Operativo** (comandante della scorta): schema di pattugliamento attorno al convoglio,
   reazione all'allarme (**Operation Raspberry** e simili: i veri schemi di ricerca
   notturna), distacco di due navi sul contatto, richiamo quando il convoglio si allontana.
3. **Tattico** (singola unita'): accosto ASDIC con perdita di contatto finale, stima della
   quota, rullo di cariche, ricerca a spirale, pazienza (una scorta *aspetta*; il comandante
   nervoso che riparte fa rumore e muore).

Gli aerei: pattuglie costiere (Sunderland, Wellington con Leigh Light in Biscaglia),
Liberator a lungo raggio dalle basi, velivoli imbarcati su portaerei di scorta, e il
famigerato aereo che arriva *dal sole*. Frequenza per zona e per Heat, con finestre
orarie realistiche (la Biscaglia di notte e' peggio del giorno, dal 1943).

### 5.4 Bandiere, regole d'ingaggio, conseguenze

Il gioco non nasconde la natura della guerra al traffico. Le regole del *Prisenordnung* e
la loro erosione (guerra sottomarina indiscriminata) sono materiale di gioco: identificare
la bandiera **prima** di lanciare e' un atto di comando, e affondare navi neutrali o navi
ospedale produce conseguenze diplomatiche e disciplinari nel gioco (richiami del BdU,
perdita di prestigio, inchieste), non solo punti. Nessuna glorificazione: il tono e' quello
del rapporto di missione e del **diario di bordo (KTB)**, asciutto e documentario.

---

## 6. Multigiocatore: BdU, branchi, radio

### 6.1 Il BdU come regista

Il *Befehlshaber der U-Boote* e' il motore che da' senso al mondo condiviso: assegna le
aree operative (*Angriffsgebiet*), emette i comunicati, ordina la formazione dei branchi,
chiede rapporti meteo (storicamente veri: gli U-Boot erano anche stazioni meteo — e ogni
rapporto meteo e' una trasmissione che l'HF/DF puo' agganciare). Il giocatore riceve gli
ordini per radio e puo' **accettare incarichi** (pattugliare un quadrato, pedinare un
convoglio, rifornire un altro battello, salvare un equipaggio).

### 6.2 Rudeltaktik (co-op) vs patrol autonoma

Alla partenza il comandante sceglie:

- **Assegnazione a un gruppo** (*Gruppe "Drossel"*, *"Raubgraf"*, *"Wolf"*…): coordinate
  di sbarramento assegnate dal BdU, obbligo di segnalazione, ricompense di **tonnellaggio
  condiviso** e prestigio per il ruolo di *Fuehlungshalter* (chi tiene il contatto e guida
  gli altri, spesso senza attaccare — e va premiato per questo, altrimenti nessuno lo fa).
  L'attacco notturno concentrato su un convoglio persistente e' l'apice del gioco: le navi
  affondate spariscono per tutti, le scorte sono sature, e la confusione e' reale.
- **Patrol autonoma** (*Einzelunternehmen*): area a scelta, nessun obbligo, **moltiplicatore
  di prestigio individuale piu' alto** e liberta' totale — ma nessuno verra' ad aiutarti, e
  il convoglio che trovi da solo e' un convoglio che ti puo' seppellire da solo.

Il matching e' asincrono: i branchi sono **finestre operative** (es. 48‑72 ore reali) a cui
ci si iscrive dalla base; non serve che i giocatori siano online insieme (l'I.WO copre i
buchi), ma chi c'e' insieme ottiene la sinergia piena.

### 6.3 Radio — rischio e ricompensa

Ogni trasmissione e' una scelta. Il modello:

- **Ricezione**: quasi gratuita. Onde lunghissime (Goliath/Nauen) ricevibili fino a ~20 m
  di quota: si possono ricevere ordini **restando immersi**. Le onde corte richiedono
  l'antenna in superficie.
- **Trasmissione**: richiede superficie (o snorkel con antenna), dura un tempo
  proporzionale alla lunghezza del messaggio, e **genera l'evento HF/DF**. I
  **Kurzsignale** (segnali brevi cifrati: contatto, meteo, consumo) esistono proprio per
  ridurre quel tempo, e saranno l'opzione "saggia" in interfaccia.
- **Enigma**: presente come finzione diegetica (cifratura M3/M4, chiavi giornaliere,
  procedura *Offizier* per i messaggi riservati). Non simuliamo la crittoanalisi; ma
  l'ombra di **Ultra** esiste come meccanica: a Heat molto alto, gli appuntamenti col
  Type XIV e le concentrazioni di branco possono essere **anticipati** dal nemico. E'
  storicamente corretto ed e' un'ottima fonte di tensione.

### 6.4 Chat e comunicazione fra giocatori

Solo per radio, e solo entro portata e con le regole di cui sopra: niente chat globale
fuori finzione. Messaggi liberi (costosi in tempo di trasmissione) + un prontuario di
segnali brevi standard. Alla base, invece, la *Flottille* ha una bacheca (mensa ufficiali)
dove si parla liberamente.

### 6.5 Rifornimento in mare (Type XIV "Milchkuh")

Meccanica ad alto rischio e alta ricompensa: si chiede al BdU un appuntamento; si riceve un
quadrato e una finestra oraria; si naviga fin li' (consumando); il trasferimento di nafta,
siluri, viveri e — importante — **il passaggio di un medico o il trasferimento di feriti**
richiede **superficie, mare calmo e diverse ore immobili**. E' il momento piu' vulnerabile
della vita di un U-Boot. A Heat alto, la probabilita' che l'appuntamento sia stato
compromesso cresce.

---

## 7. Carriera, economia, progressione

### 7.1 Il comandante

Creazione: nome, data e luogo di nascita, ritratto (set di illustrazioni), flottiglia
d'assegnazione (che determina la base), tratti iniziali limitati. Un novellino comincia
davvero da novellino: **Oberleutnant zur See**, un **Type IID o VIIB stanco**, equipaggio
mediocre, pochi punti di assegnazione, nessuna scelta di siluri speciali.

**Gradi** (avanzamento per anzianita' + merito): Oberleutnant zur See → **Kapitaenleutnant**
→ Korvettenkapitaen → Fregattenkapitaen.

**Decorazioni**, assegnate secondo criteri storici plausibili e mai automatiche:
*U-Boots-Kriegsabzeichen* (distintivo, dopo due patrol operative) · *Eisernes Kreuz II*
e *I. Klasse* · *Deutsches Kreuz in Gold* · **Ritterkreuz** (soglia storica indicativa
~100.000 GRT o azione eccezionale) → *Eichenlaub* → *Schwerter* → *Brillanten*.
Ogni decorazione ha una **motivazione generata** che cita le azioni reali del giocatore, ed
entra nel suo fascicolo personale.

### 7.2 Esperienza e sblocchi

Due valute di progressione, distinte per non confondere merito e logistica:

1. **Ansehen (prestigio)** — il merito del comandante. Guadagnato con tonnellaggio,
   obiettivi, rapporti di contatto utili agli altri, rientri riusciti, atti di marinaresca
   (soccorso naufraghi). Sblocca: tipi di battello, tecnologie, ufficiali migliori, la
   scelta dell'area operativa, il diritto di rifiutare un ordine.
2. **Zuteilungspunkte (punti di assegnazione)** — la logistica del Reich: e' cio' che
   permette di *avere davvero* il pezzo sbloccato. Rappresenta la priorita' in cantiere.
   Spesa per: siluri speciali (FAT/LUT/T5), Radar-Warner, flak potenziata, Schnorchel,
   riparazioni accelerate, addestramento dell'equipaggio, consumabili (Bold, potassa).
3. **Reichsmark** — il denaro personale, in scala molto piu' piccola: comfort a bordo
   (grammofono, caffe' vero, viveri freschi extra), licenze e permessi per l'equipaggio,
   piccole cose che muovono il **morale**. E' il "denaro tedesco spendibile" richiesto,
   nella cornice piu' onesta possibile.

### 7.3 Il ciclo di vita di una patrol

```
BASE (flottiglia) → EQUIPAGGIAMENTO → USCITA → TRANSITO → AREA OPERATIVA
      ↑                                                          ↓
   RAPPORTO ← RIENTRO ← TRANSITO DI RITORNO ← (siluri finiti / nafta / danni)
```

**Fase di armamento** (quella richiesta esplicitamente): carico dei siluri con **vincoli
reali di spazio** — i Type VIIC portano 14 siluri di cui 11 interni e 3 in contenitori
stagni esterni (che **si possono imbarcare solo con mare calmo e ore di lavoro**), i posti
cuccetta della camera di prua sono occupati dai siluri stessi; nafta, acqua, viveri per N
giorni (che occupano spazio e determinano l'autonomia reale), munizioni per cannone e flak,
consumabili (Bold, cartucce di potassa, ossigeno, ricambi). **Peso e spazio sono un
problema di ottimizzazione vero**: piu' viveri = piu' giorni ma meno ricambi; piu' siluri =
piu' occasioni ma meno margine.

**Alla base** (il "parcheggio sicuro"): bunker di La Rochelle/Lorient/Brest/St. Nazaire/
Bordeaux, o Kiel/Wilhelmshaven/Bergen/Trondheim. Il tempo alla base scorre anch'esso: le
riparazioni richiedono giorni (di gioco), l'equipaggio va in licenza e recupera morale,
si fanno i corsi, si assiste alla cerimonia di consegna delle decorazioni, si legge il
comunicato del BdU, si guarda la bacheca della flottiglia.

### 7.4 Fine: affondamento, sopravvivenza, albo d'oro

Quando il battello e' perduto si apre la **catena di sopravvivenza**, con esiti pesati da
condizioni reali: quota al momento del colpo (sopra i 50 m qualche possibilita'; a 200 m
nessuna), allagamento, presenza di superficie nemica disposta a raccogliere (le scorte a
volte raccoglievano, a volte no), mare e temperatura dell'acqua (nel Nord Atlantico si
muore di ipotermia in 20 minuti), stato dei battellini e dei *Tauchretter*.

Esiti: **dispersi con tutto l'equipaggio** (il caso piu' frequente, storicamente) ·
**pochi superstiti prigionieri** (il comandante finisce in un campo: la sua carriera si
chiude ma resta vivo nell'albo) · **battello autoaffondato ed equipaggio salvato** ·
raro rientro con battello gravemente danneggiato.

In ogni caso: **fascicolo chiuso e archiviato per sempre** nell'**Albo d'oro** consultabile
(nome, battello, patrol, tonnellaggio, decorazioni, ultima posizione nota, sorte
dell'equipaggio, ultimo messaggio ricevuto). Il nuovo comandante eredita una quota della
reputazione di flottiglia (non i gradi, non le medaglie): si riparte davvero, ma non da
zero assoluto.

---

## 8. L'interfaccia: la Zentrale

### 8.1 Principio estetico

**Non un cruscotto moderno con badge colorati: un posto di lavoro di acciaio del 1942.**
Materiali: lamiera verniciata grigio-verde, ottone, bachelite nera, vetro di quadranti,
etichette smaltate in **Fraktur leggibile solo nei titoli** (il corpo del testo e' un
grottesco stretto, per leggibilita'), viti a croce, rivetti, ombre dure di una lampada
schermata. Due palette:

- **Giorno / in superficie**: grigio acciaio, luce fredda dall'alto.
- **Notte / immersione / allarme**: **rosso di sicurezza** (la vera illuminazione notturna
  di torretta) con quadranti fosforescenti verdi. La transizione e' automatica in base allo
  stato del battello — e comunica informazione, non e' decorazione.
- Terza modalita' **allarme** (*Alarm!*): il rosso pulsa, i comandi non essenziali si
  chiudono, resta l'essenziale. In immersione sotto attacco l'interfaccia si fa scura e
  silenziosa, i suoni si attutiscono, si sentono le eliche sopra la testa.

Audio (opzionale, attivabile): diesel, allagamento, ping ASDIC, lo scafo che canta, la
carica che scoppia, il campanello dell'allarme. Nessuna musica.

### 8.2 Le postazioni (una per schermata, come si girava davvero nel battello)

| Postazione | Contenuto |
|---|---|
| **Zentrale** (home) | Quota, assetto, rotta, velocita', regime motori, aria, batteria, nafta, CO2, allarmi, stato compartimenti, ordini rapidi (*Alarm!*, *Auf Sehrohrtiefe*, *Schleichfahrt*, *Auftauchen*) |
| **Kartentisch** (tavolo di carteggio) | Carta nautica Canvas nello stile della carta di navigazione della Kriegsmarine — mare graduato, cornice di pergamena con la graduazione, cartiglio, rosa dei venti, scala grafica — con griglia e sigle Marinequadrat, posizione **stimata** e cerchio d'incertezza, rotta pianificata a waypoint, contatti noti con ora e vettore |
| **Turm / UZO** | Vista in torretta: orizzonte, meteo, luce, vedette, binocolo, presa di rilevamento, cannone e flak |
| **Sehrohr** (periscopio) | Vista d'attacco con reticolo, telemetria sull'altezza dell'albero, cronometro, presa di AOB, corsa periscopio (alzato = rilevabile: **realizzato**, vedi `Detection::baffaPeriscopio`). Oggi vive dentro la stazione d'attacco, non in una postazione separata |
| **Vorhaltrechner (TDC)** | Soluzione di tiro, tubi, spolette, profondita', ventaglio, storico dei lanci |
| **Horchraum** (idrofono) | Rosa dei rilevamenti, intensita', classificazione, cronologia acustica |
| **Funkraum** (radio) | Messaggi BdU, contatti dei compagni di branco, invio Kurzsignale, allarme Metox/Naxos, stima del rischio HF/DF |
| **Maschinenraum** | Motori, batterie, avarie, squadre di riparazione, consumi |
| **Besatzung** (equipaggio) | Turni, stazioni, fatica, morale, feriti, scheda dei singoli uomini |
| **KTB** (diario di bordo) | Il giornale di guerra: ogni evento in stile rapporto, esportabile in PDF/testo |
| **Flottille** (solo alla base) | Armamento, cantiere, sblocchi, personale, bacheca, decorazioni, albo d'oro |

### 8.3 Tempo e attesa

In crociera la plancia mostra l'orologio di gioco che scorre, e il giocatore imposta ordini
permanenti e dottrina e **se ne va**. La pagina si aggiorna in polling lento (30‑60 s). In
tattico il polling scende a 2‑3 s e compare la barra della finestra di condotta. La lunga
attesa non e' un tempo morto: e' quella che rende l'avvistamento un evento.

---

## 9. Architettura tecnica

### 9.1 Stack e struttura del progetto

Stessa impalcatura collaudata su SubSpazio (Core riusabile, non riscritto):

```
<installazione>/
  index.php                front controller unico (PATH_INFO / FallbackResource)
  src/
    autoload.php           PSR-4 minimale
    routes.php
    Core/                  Config Database Router Request Response Session Csrf View Mailer RateLimiter
    Auth/                  registrazione, verifica e-mail Brevo, login, reset
    Controllers/           una classe per postazione + Api*
    Sim/                   IL MOTORE (vedi 9.3)
    Data/                  repository e query
    Support/               helpers, formattatori (quadrati, gradi, ore, GRT)
    Cli/                   comandi console
  views/                   template PHP per postazione
  assets/                  css/ js/ (vanilla, niente build) img/ audio/
  db/
    migrations/            0001_..., 0002_... idempotenti
    seed/                  DATI STORICI: uboat_types, torpedoes, equipment, ship_classes,
                           escort_classes, aircraft, convoy_series, ports, routes, flotillas,
                           awards, crew_names, ship_names  (ogni riga con `fonte`)
  bin/
    tick.php               cron ogni minuto
    console.php            migrate, seed, world:init, world:stats, user:*, sim:step, sim:replay
  tests/                   test di simulazione (deterministici) + e2e
  deploy/                  script sudo idempotenti + conf Apache
  docs/                    questo documento, il registro delle fonti, le note di bilanciamento
  storage/logs, storage/uploads
```

Segreti e configurazione in un file **fuori dal DocumentRoot** (`/etc/atlantik/config.php`, oppure il percorso indicato dalla variabile d'ambiente `ATLANTIK_CONFIG`),
DB **`atl_atlantik`**, mail via **Brevo SMTP** riusando il mittente verificato del forum, con
destinatario delle notifiche indicato nella configurazione.

### 9.2 Schema dati (nuclei principali)

- **Identita'**: `users` (account, stato, verifica e-mail) · `commanders` (l'alter ego, con
  stato `active|kia|pow|retired`) · `careers` (storico dei comandanti di un account) ·
  `flotillas`.
- **Battello**: `boats` (tipo, nome/codice U‑xxx, flottiglia, stato) · `boat_systems` ·
  `boat_compartments` · `boat_loadout` (siluri per tubo/stiva, munizioni, consumabili) ·
  `boat_upgrades`.
- **Equipaggio**: `crew_members` · `crew_assignments` (stazione, turno) · `crew_events`.
- **Mondo**: `world` (seed, orologio, rapporto di compressione) · `weather_cells` ·
  `sectors` (quadrato, Heat, densita', copertura aerea) · `ports` · `routes`.
- **Traffico**: `ships` (unita' NPC persistenti) · `convoys` · `convoy_ships` ·
  `escorts` · `aircraft_patrols` · `wrecks` (i relitti restano, e sono prova).
- **Missione**: `patrols` (uscita, obiettivi, esito) · `patrol_events` (il KTB, append-only)
  · `contacts` · `encounters` (istanze tattiche) · `encounter_entities` ·
  `torpedo_shots` · `sinkings` (con GRT, bandiera, posizione, ora, arma).
- **Meta**: `wolfpacks` · `wolfpack_members` · `radio_messages` · `hfdf_fixes` ·
  `orders` (BdU) · `awards` · `achievements` · `stats_daily` · `hall_of_fame`.

Regole: tutte le posizioni in **lat/lon decimali** (il quadrato e' derivato, non memorizzato
come verita'); tutti i tempi in **istante di gioco** (`game_ts`, intero: secondi dall'epoca
di campagna) **e** `real_ts`; ogni evento di simulazione porta il `tick` e il `seed` con cui
e' stato generato, per la rigiocabilita'.

### 9.3 Il motore (`src/Sim/`)

Moduli puri e testabili, senza I/O diretto:

```
Sim/
  Clock.php          conversione reale↔gioco, compressione, finestre
  Grid.php           Marinequadrat ↔ lat/lon  (test su casi storici)
  Geo.php            rotta ortodromica, rilevamento, distanza, intercetto
  Astro.php          sole, luna, crepuscoli → light_level
  Weather.php        celle, fronti, stagionalita', Beaufort/Douglas
  Rng.php            PRNG deterministico seminato (world_seed, entity, tick)
  Movement.php       integrazione rotta/velocita'/corrente/scarroccio, errore di stima
  Consumption.php    nafta, batteria, aria, CO2, viveri, usura
  Detection.php      visivo, idrofono, radar, HF/DF  (bidirezionale)
  Acoustics.php      SL/TL/NL, strato termico, cavitazione, Schleichfahrt
  Torpedo.php        corsa, spoletta, affidabilita', FAT/LUT/T5, danno
  Gunnery.php        cannone, flak, tiro delle scorte
  Damage.php         compartimenti, sistemi, pressione, allagamento, incendio, riparazioni
  Crew.php           fatica, morale, competenza, turni, perdite
  AiEscort.php       accosto ASDIC, cariche, ricerca, pazienza
  AiConvoy.php       formazione, zigzag, routing, dispersione
  AiWatchOfficer.php l'I.WO del giocatore (dottrina d'ingaggio)
  Encounter.php      macchina a stati dell'incontro tattico, passo 10 s
  WorldTick.php      orchestratore del passo strategico
  Narrator.php       da evento a riga di KTB, in stile rapporto
```

**Il passo.** `WorldTick::run()` (da cron, ogni minuto reale) e
`Sim::advanceTo(entity, now)` (lazy, da richiesta HTTP) condividono lo stesso codice:
integrazione a sotto-passi, PRNG deterministico, scrittura eventi append-only,
transazioni per entita' con lock ottimistico (`version`). Nessun lavoro pesante nel
percorso della richiesta web: il tick fa il grosso, la richiesta al massimo recupera il
ritardo del proprio battello.

### 9.4 API e front-end

API JSON sotto `/api/*` (stessa sessione, CSRF sui POST): `GET /api/state` (stato completo
del battello + delta eventi da `since`), `POST /api/order` (ordine con idempotency key),
`GET /api/contacts`, `POST /api/fire`, `GET /api/chart`, `GET /api/ktb`. Front-end vanilla:
Canvas per carta, periscopio e rosa idrofonica; SVG per i quadranti; zero dipendenze
esterne e zero build step, coerente con gli altri progetti su Balthasar. PWA con service
worker per l'uso da telefono (le notifiche di contatto su mobile sono un valore vero per un
gioco a tempo compresso).

### 9.5 Sicurezza e correttezza

Tutta la simulazione e' **server-side e autoritativa**: il client non calcola mai nulla che
conti. Ordini idempotenti con chiave, rate limiting per azione, CSRF su ogni POST, sessioni
con rotazione, password con `password_hash`, verifica e-mail obbligatoria, log applicativo.
Anti-abuso: un solo comandante attivo per account; il tempo di gioco non e' accelerabile dal
client; ogni evento e' ricostruibile dal seed (una contestazione si verifica rigiocando).

### 9.6 Statistiche

- **Personali**: tonnellaggio affondato/danneggiato per tipo e bandiera, siluri lanciati/a
  segno/falliti (con causa), giorni in mare, miglia in superficie e in immersione, ore
  sotto attacco, cariche subite, quota massima raggiunta, consumi, uomini persi.
- **Di campagna (globali)**: tonnellaggio totale della flotta giocatori, U-Boot in mare/
  perduti, scambio tonnellaggio/perdite (il vero indicatore della battaglia reale),
  classifiche per patrol e per carriera, mappa di calore degli affondamenti, storico dei
  convogli massacrati, albo d'oro.
- Aggregazione notturna in `stats_daily` per non fare scansioni pesanti a runtime.

---

## 10. Roadmap a fasi

Ogni fase si chiude con: migrazioni idempotenti, seed, comandi console, test, e **qualcosa
di giocabile o ispezionabile**. Nessuna fase e' "solo infrastruttura senza risultato".

**Stato al 18/09/2026: tutte le fasi da F0 a F7 sono realizzate e collaudate.** La tabella resta
come registro di cio' che ciascuna fase doveva consegnare; i dettagli di come e' stato fatto, con i
numeri misurati e gli scarti dichiarati, stanno in `docs/FONTI.md`.

| Fase | Contenuto | Esito verificabile |
|---|---|---|
| **F0 — Fondamenta** | Repo, config fuori DocumentRoot, DB+utente, Core portato da SubSpazio, front controller, registrazione + **verifica e-mail Brevo**, login, console CLI, cron | Ci si registra, si verifica la mail, si entra in una pagina "Flottille" spoglia |
| **F1 — Mondo e navigazione** | `Clock` `Grid` `Geo` `Astro` `Weather` `Rng` `Movement` `Consumption`, carta nautica Canvas, ordini di rotta/quota/velocita', KTB, tick da cron | Si esce da Lorient, si naviga per giorni di gioco, si vede il sole sorgere, la nafta calare, la posizione stimata derivare |
| **F2 — Battello ed equipaggio** | Tipi storici, compartimenti, sistemi, `Crew`, turni, morale, fatica, `Damage` (avarie e pressione), riparazioni | Un diesel si guasta al largo e l'equipaggio lo ripara (o no) |
| **F3 — Contatti** | `Detection` `Acoustics`, generazione traffico, rotte, navi isolate, **convogli persistenti**, aerei, Heat per settore, postazioni Turm/Sehrohr/Horchraum | Si trova un convoglio all'idrofono a 40 nm e lo si pedina |
| **F4 — Combattimento** | TDC e `Torpedo` completi, `Gunnery`, `AiEscort`, `AiConvoy`, cariche di profondita', strato termico, Bold, evasione, macchina a stati `Encounter` con finestra live e `AiWatchOfficer` | Si affonda una nave, si viene cacciati per tre ore e si sopravvive (o no) |
| **F5 — Carriera** | Ciclo patrol completo, armamento con vincoli di spazio/peso, prestigio, punti di assegnazione, RM, gradi, decorazioni, sblocchi, **permadeath e albo d'oro** | Una carriera intera, dalla prima uscita alla fine |
| **F6 — Multigiocatore** | BdU, ordini, branchi, radio + Kurzsignale, **HF/DF**, convogli condivisi, Type XIV, bacheca di flottiglia, classifiche e statistiche globali | Due giocatori attaccano lo stesso convoglio la stessa notte |
| **F7 — Rifinitura** | Achievements, esportazione KTB, audio, PWA/notifiche, pannello admin, bilanciamento sui dati raccolti, documentazione delle fonti | Beta aperta |

**Precedenza tecnica**: F1 e' la fase che decide la qualita' di tutto — se il tempo, la
griglia e il moto sono giusti e testati, il resto si appoggia; se sono approssimativi, ogni
fase successiva paghera'. Prevedo quindi test unitari veri su `Grid`, `Astro`, `Geo`,
`Clock` fin da subito.

---

## 11. Fonti e metodo storico

Il rigore storico e' un requisito, non un ornamento. Metodo:

- Ogni tabella di seed ha un campo **`fonte`** e ogni valore incerto e' marcato come tale
  (`confidence`), con nota. Quando un dato ha varianti in letteratura (es. quota di
  collasso dei VIIC) si registra **l'intervallo**, non un numero inventato.
- `docs/FONTI.md` terra' il registro: specifiche tecniche dei battelli e delle armi,
  organizzazione delle flottiglie, serie e composizione dei convogli, dottrine ASW alleate,
  vita di bordo, procedure radio. Base di partenza: la documentazione tecnica della
  Kriegsmarine sui tipi VII/IX, gli studi sulla Battaglia dell'Atlantico, i KTB pubblicati,
  i manuali ASW dell'Ammiragliato, e il materiale di uboat.net per il controllo incrociato
  delle date e delle carriere.
- Dove la storia e il gioco confliggono (es. la decisione 2), il documento **dichiara lo
  scarto** invece di nasconderlo, e l'interfaccia mostra sempre la data storica reale del
  pezzo di equipaggiamento.
- **Tono**: e' una simulazione della vita e della morte dentro un tubo d'acciaio al servizio
  di un regime criminale. Il gioco sta dalla parte del mestiere marinaro e della sorte degli
  uomini — nessuna simbologia di partito, nessuna esaltazione: rapporti, quadranti, mare,
  e l'albo dei dispersi.

---

## 12. Messa in opera su Balthasar

Vincolo tipico: l'utente che sviluppa non ha sudo senza password e la cartella del sito appartiene a `root`.
Serve **un solo script sudo collaudato** (`deploy/00-bootstrap.sh`, idempotente, con backup
e `apache2ctl configtest` prima del reload) che:

1. `chown <utente>:www-data` + `chmod 2775` sulla cartella del sito;
2. crei la cartella dei segreti fuori dal DocumentRoot (gruppo `www-data`, `0750`);
3. crei database e utente MariaDB `atl_atlantik` con password generata;
4. aggiunga al vhost il blocco `# --- Atlantik BEGIN/END ---` con `FallbackResource`
   e hardening delle directory (niente `.htaccess`: `AllowOverride None`);
5. installi la riga di cron del tick.

Da li' in avanti tutto lo sviluppo e il deploy sono eseguibili senza sudo.
