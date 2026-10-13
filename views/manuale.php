<?php
/** @var bool $dentro  il lettore e' un comandante in servizio */
$capitoli = [
    'crociera'   => 'I. La crociera',
    'trovare'    => 'II. Trovare il nemico',
    'attacco'    => 'III. L\'attacco',
    'disimpegno' => 'IV. Il disimpegno',
    'aerei'      => 'V. Gli aerei',
    'battello'   => 'VI. Il battello e gli uomini',
    'comando'    => 'VII. Il BdU, la radio, il branco',
    'carriera'   => 'VIII. La carriera, e come finisce',
    'speciali'   => 'IX. Missioni speciali, e i naufraghi',
    'assenza'    => 'X. Quando il comandante non c\'è',
];
?>
<div class="pannello manuale">
  <span class="targhetta">Befehlshaber der U-Boote — Ufficio addestramento</span>
  <h1>Manuale del comandante</h1>
  <p class="sommario">
    Quello che serve sapere prima di mollare gli ormeggi, scritto come lo scriverebbe la scuola:
    poche regole, e il perché di ciascuna. Non sostituisce l'Agru-Front — l'attacco s'impara
    attaccando — ma evita di imparare le cose più care al prezzo più caro.
    <?php if ($dentro): ?>
      L'addestramento tattico si chiede dalla <a href="<?= e(url('/base#agru')) ?>">base di flottiglia</a>.
    <?php else: ?>
      Per provarlo: <a href="<?= e(url('/arruolamento')) ?>">arruolamento</a>.
    <?php endif; ?>
  </p>
  <nav class="indice-manuale" aria-label="Capitoli">
    <?php foreach ($capitoli as $id => $nome): ?>
      <a href="#<?= e($id) ?>"><?= e($nome) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<div class="pannello manuale" id="crociera">
  <h2>I. La crociera</h2>
  <p><b>Il tempo.</b> In crociera un minuto vero è mezz'ora di mare: una traversata fino al quadrato assegnato
  è una sera, una patrol di sei settimane un paio di giorni. Il mondo corre anche quando si è a terra.
  In combattimento il tempo rallenta: un quarto in avvicinamento, il tempo vero quando si spara o si viene cacciati.</p>
  <p><b>Dove si è.</b> La posizione in plancia è la <i>stima</i> dell'Obersteuermann, non la verità: corre
  dietro alla corrente e al vento che non vede, e l'errore cresce ogni ora. Il punto astronomico la corregge,
  quando il cielo è sgombro; una costa in vista la corregge di colpo. Chi pianifica un appuntamento al
  miglio con tre giorni di stima sbaglia l'appuntamento.</p>
  <p><b>La rotta.</b> Si traccia sulla carta, punto per punto; i punti si trascinano. Se un tratto taglia la
  terraferma, l'Obersteuermann aggiunge i punti per doppiarla. Dalle basi si esce per il canale, e per il
  canale si rientra.</p>
  <p><b>Le casse.</b> La nafta decide quanto lontano si va e a che velocità: a dieci nodi un VII C fa
  ottomilacinquecento miglia, a tutta forza poco più di tremila. In immersione si va a batteria: su un VII C a due
  nodi dura quasi cinque giorni, a quattro meno di uno, a sette poco più di due ore. L'aria si consuma sotto, e si rinnova solo in superficie. I viveri si
  scelgono in cantiere — fino al doppio della dotazione, se la stiva li tiene — e le prime due settimane
  c'è il fresco. Finiti i viveri il BdU ordina il rientro, e dopo pochi giorni gli uomini si ammalano.</p>
</div>

<div class="pannello manuale" id="trovare">
  <h2>II. Trovare il nemico</h2>
  <p><b>L'idrofono sente prima.</b> Un convoglio si sente a decine di miglia, col mare calmo e il battello
  lento. L'idrofono dà il <i>rilevamento</i>, non la distanza: la distanza la si indovina dal rumore, e la si
  sbaglia. A sei nodi si sente poco; a quota periscopica, fermi, si sente meglio di tutto.</p>
  <p><b>La vedetta vede, ed è vista.</b> Di notte un U-Boot in superficie lo si scorge a meno di due miglia,
  con la luna piena a quattro, di giorno a dodici. Il battello basso sull'acqua vede alberi e fumo molto prima
  di essere visto: è per questo che si attacca di notte e in superficie, con la luna dalla parte giusta.</p>
  <p><b>Il fumo all'orizzonte</b> è spesso la prima cosa: non dice cosa sia né quante navi, ma dice dove guardare.</p>
  <p><b>Il quadrato.</b> La carta è divisa nei quadrati della Kriegsmarine: due lettere per il grande
  quadrato, quattro cifre fino al quadratino di sei miglia (<code>BE 5287</code>). Il BdU parla in quadrati.</p>
</div>

<div class="pannello manuale" id="attacco">
  <h2>III. L'attacco</h2>
  <p><b>Quando.</b> Un contatto diventa un attacco dalla stazione d'attacco, entro dieci miglia dal bersaglio.
  Da lì comincia una finestra di tempo reale — una ventina di minuti — dentro la quale si combatte manovra
  per manovra. Un contatto che nessuno insegue, prima o poi, si perde.</p>
  <p><b>I cinque passi</b>, gli stessi della stazione d'attacco:</p>
  <ol>
    <li><b>Avvicinamento.</b> Davanti al convoglio, non dietro: un convoglio a nove nodi non lo si
    raggiunge in immersione. Si corre in superficie fuori dalla vista, ci si mette sulla sua rotta, e lo si aspetta.</li>
    <li><b>Osservazione.</b> Periscopio fuori il minimo indispensabile: la baffa si vede, e la sagoma per una
    vedetta triplica. Il I.WO lo abbassa da solo dopo cinque minuti.</li>
    <li><b>Soluzione.</b> Il calcolatore di lancio (Vorhaltrechner) vuole tre cose: distanza, angolo sulla
    prua del bersaglio e sua velocità. Sono stime, e l'errore si propaga: un nodo sbagliato a duemila metri è
    una nave mancata. Più si è vicini, meno conta l'errore.</li>
    <li><b>Lancio.</b> Un tubo per nave, o un ventaglio per non dipendere dalla stima. Il siluro a vapore è
    veloce ma lascia la scia; quello elettrico non la lascia ed è più lento. La spoletta magnetica esplode sotto
    la chiglia e spezza la nave — quando funziona: nel 1942 i siluri hanno ancora i loro difetti, corse troppo
    profonde, scoppi prematuri, spolette che non si accendono.</li>
    <li><b>Evasione.</b> Dopo il primo scoppio il convoglio sa. Si scende, subito.</li>
  </ol>
</div>

<div class="pannello manuale" id="disimpegno">
  <h2>IV. Il disimpegno</h2>
  <p><b>Come cerca l'ASDIC.</b> Lavora quasi orizzontale, fino a due chilometri e poco più, peggio col mare
  grosso. Ha due ciechi: gli ultimi duecento metri, quando la scorta ti passa sopra e il fascio ti scavalca
  — ed è lì che lancia le cariche, alla cieca — e il basso: oltre una trentina di gradi sotto l'orizzonte
  non vede. Un battello profondo esce dal fascio prima che la scorta gli arrivi sopra.</p>
  <p><b>Le cariche</b> si regolano su una quota stimata, e nel 1942 la si stima per difetto. Scendere sotto
  i cento metri salvava i battelli. Scendere oltre la quota di collasso li perdeva: il fondo dell'Atlantico
  è pieno di chi ha fatto quella scelta.</p>
  <p><b>Il silenzio</b> paga: macchine al minimo, marcia silenziosa, e la scorta perde il contatto più in fretta.
  Lo strato termico, dove c'è, devia il fascio. Il Bold, la cartuccia che fa bolle, offre all'ASDIC un falso bersaglio.</p>
  <p><b>Quando è finita.</b> Quando nessuna scorta ha più un contatto e le eliche si allontanano. Le scorte
  non mollano subito: possono restare sopra il punto per ore.</p>
</div>

<div class="pannello manuale" id="aerei">
  <h2>V. Gli aerei</h2>
  <p>Dove il nemico ha basi vicine il cielo è pieno, e dove ci si è fatti notare — affondamenti, avvistamenti,
  trasmissioni radio — si riempie di più. In superficie un aereo che arriva addosso non lascia il tempo di
  chiudere il boccaporto: le bombe arrivano prima dell'immersione. Il rivelatore radar (Metox, poi Naxos)
  canta quando qualcuno ci illumina, e quel mezzo minuto è la differenza. Di notte volano in meno, ma hanno il
  radar e il faro Leigh.</p>
</div>

<div class="pannello manuale" id="battello">
  <h2>VI. Il battello e gli uomini</h2>
  <p><b>Le avarie</b> vengono dall'uso, dal mare e dalle cariche. Una parte si ripara a bordo, se ci sono
  ricambi e uomini capaci; i periscopi e lo scafo resistente solo in cantiere. Il danno da pressione abbassa per
  sempre la quota di collasso. In porto il cantiere lavora mentre si aspetta: chi riparte prima che abbia finito
  riparte con quello che c'è.</p>
  <p><b>Gli uomini</b> hanno competenza, stanchezza e morale, e la resa dell'equipaggio entra dappertutto:
  nell'ascolto, nelle riparazioni, nell'errore al lancio. Le guardie ruotano ogni quattro ore; il mare grosso
  non fa dormire; l'aria cattiva, l'allarme e i giorni di missione pesano. Fra una missione e l'altra si mandano
  ai corsi.</p>
</div>

<div class="pannello manuale" id="comando">
  <h2>VII. Il BdU, la radio, il branco</h2>
  <p><b>Il BdU</b> assegna le aree operative, dove passa il traffico e dove il mare non è ancora troppo caldo, e
  manda un comunicato al giorno. Se segnali un convoglio, può ordinarti di pedinarlo.</p>
  <p><b>La radio</b> serve e costa. Ogni trasmissione può essere presa dai radiogoniometri alleati — un segnale
  breve di una ventina di secondi una volta su due, un messaggio di due minuti quasi sempre — e il punto rilevato riscalda il settore: aerei e scorte
  arrivano dove si è trasmesso.</p>
  <p><b>La guerra delle informazioni.</b> A Londra l'Ammiragliato mette insieme i punti radiogoniometrici, gli
  avvistamenti e gli affondamenti, e fa girare i convogli al largo: un convoglio che passava nel tuo settore, se
  ti sei fatto sentire, ti girerà intorno a cento miglia di distanza. Nel 1942 i britannici non leggono i cifrari
  degli U-Boot: sanno solo quello che tu dici per radio, e quello che vedono. Dall'altra parte il B-Dienst legge
  i loro: ogni tanto il BdU sa che un convoglio è stato deviato e dove si trova, e lo dice a tutti i battelli in
  mare — nel giornale, e negli avvisi per posta.</p>
  <p><b>Il branco</b> è facoltativo. Il BdU forma gruppi per qualche giorno; chi trova un convoglio e lo
  segnala lo passa agli altri, e prende il premio di chi tiene il contatto — pedinare non affonda niente, ma
  senza chi pedina il branco non si raduna. Il rifornimento in mare dal Tipo XIV si chiede al BdU, e
  l'appuntamento può essere compromesso.</p>
</div>

<div class="pannello manuale" id="carriera">
  <h2>VIII. La carriera, e come finisce</h2>
  <p>Tre conti: il <b>prestigio</b>, che si guadagna e sblocca i gradi, i siluri nuovi e gli apparati; i
  <b>punti di assegnazione</b>, che il cantiere spende per avere quello che il grado permette; i
  <b>Reichsmark</b>, la paga. Al rientro il BdU legge il rapporto e decide promozioni e decorazioni.</p>
  <p><b>Chi affonda, affonda davvero.</b> Il battello perduto chiude il fascicolo, ma non sempre il comandante:
  chi va giù in superficie ha buone probabilità di essere raccolto, a cento metri quasi nessuna. Disperso,
  prigioniero o salvato, il nome resta nell'albo d'oro, e il comandante che viene dopo eredita un quarto del
  prestigio — mai i gradi, mai le decorazioni.</p>
</div>

<div class="pannello manuale" id="speciali">
  <h2>IX. Missioni speciali, e i naufraghi</h2>
  <p><b>Le missioni speciali.</b> In porto il BdU offre ogni tanto un lavoro diverso dalla caccia: <i>posare mine</i>
  davanti a un porto nemico — si parte con meno siluri, si posano in immersione sul punto, e il campo lavora per
  settimane sul traffico che passa, accreditato a te —; fare da <i>battello meteorologico</i> — sei rapporti dal
  quadrato assegnato, uno ogni otto ore, e ognuno è un rischio radiogoniometrico —; <i>sbarcare agenti</i>
  dell'Abwehr su una costa, di notte, in superficie, a macchine ferme. Una missione accettata e non compiuta al
  rientro costa prestigio.</p>
  <p><b>I naufraghi.</b> Dopo un affondamento in mare restano le scialuppe. Entro qualche ora, in superficie e
  vicino, puoi dare la rotta per la terra con acqua e gallette — costa viveri, e fermarsi sul punto di un
  affondamento è il modo più sicuro di farsi trovare —, o prendere a bordo il comandante della nave, che forse
  parla. Dopo il Laconia, nel settembre 1942, Dönitz proibì i soccorsi; qui il mondo è un eterno 1942, e la scelta
  resta a te. Giorni dopo si viene a sapere quanti si sono salvati, e il fascicolo lo ricorda.</p>
</div>

<div class="pannello manuale" id="assenza">
  <h2>X. Quando il comandante non c'è</h2>
  <p>Il mare non aspetta. Mentre sei a terra il battello naviga, consuma, incontra; il I.WO tiene la guardia,
  si immerge e riemerge, ripara quello che si può. <b>Attacca solo se gliel'hai lasciato scritto</b>: la
  <i>dottrina d'ingaggio</i>, dalla base o dalla centrale, è prudente (non attacca), navi isolate (solo le
  navi sole, senza scorta) o convogli. Attacca come un ufficiale giovane: si avvicina — in superficie di notte,
  a quota periscopica di giorno —, lancia un ventaglio di tre con la sua stima, che sbaglia più della tua, e poi
  scende in silenzio. Una salva sola: il secondo attacco è una scelta da comandante. Quando torni e apri la
  stazione d'attacco, il comando torna a te. Se invece una finestra d'attacco scade in un incontro che avevi
  cominciato tu, il I.WO disimpegna — giù a ottanta metri, due nodi, silenzio.</p>
  <p>Per non perdere i convogli che passano mentre sei altrove, dal tuo <?= $dentro
      ? '<a href="' . e(url('/comandante/profilo#avvisi')) . '">fascicolo</a>'
      : 'fascicolo' ?> puoi chiedere gli <b>avvisi per posta</b>: un messaggio quando c'è un contatto, un
  pericolo o un ordine del BdU. Non arrivano quando sei già alla console, e non più di uno ogni mezz'ora per
  la stessa cosa.</p>
  <p><b>I colleghi.</b> In mare non ci sei solo tu: accanto ai comandanti in carne e ossa la flottiglia ha i
  suoi U-Boot mossi dal motore. Partono, pattugliano l'area del BdU, entrano nei branchi, segnalano i convogli
  — e la loro segnalazione arriva a te —, attaccano e a volte non tornano, con le stesse regole di tutti. Il
  gioco li dichiara: nel fascicolo, nell'albo, nelle statistiche.</p>
</div>
