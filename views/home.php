<div class="pannello">
  <span class="targhetta">Befehlshaber der U-Boote — Ufficio arruolamenti</span>
  <h1>Atlantico settentrionale, guerra al tonnellaggio</h1>
  <p class="sommario">
    Atlantik è una simulazione storica multigiocatore a mondo persistente. Vesti i panni di un
    comandante di U-Boot: pianifichi la patrol, scegli il carico entro i vincoli reali di spazio e peso,
    esci dal bunker e navighi per giorni. L'oceano non ti aspetta — il tempo scorre anche mentre sei a terra,
    e il tuo Primo Ufficiale comanda in tua assenza secondo la dottrina che gli hai lasciato.
  </p>
  <p class="sommario">
    Il convoglio lo trovi con l'idrofono prima che con gli occhi. Attacchi di notte, in superficie, con la luna
    sbagliata dalla parte sbagliata. Poi vieni cacciato per ore, e conta solo quanta aria e quanta batteria
    ti restano. Chi affonda, affonda davvero: il fascicolo si chiude e resta nell'albo d'oro.
  </p>

  <?php $num = static fn (int $v): string => number_format($v, 0, ',', '.'); ?>
  <div class="quadranti">
    <div class="quadrante">
      <div class="etichetta">Comandanti in ruolo</div>
      <div class="valore"><?= e(str_pad((string) ($stats['comandanti'] ?? 0), 3, '0', STR_PAD_LEFT)) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Naviglio alleato in mare</div>
      <div class="valore"><?= e($num((int) ($stats['naviglio_in_mare'] ?? 0))) ?></div>
      <div class="etichetta">navi, adesso</div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Convogli in navigazione</div>
      <div class="valore"><?= e($num((int) ($stats['convogli'] ?? 0))) ?></div>
    </div>
    <div class="quadrante">
      <div class="etichetta">Stazza in mare</div>
      <div class="valore" style="font-size:1.15rem"><?= e($num((int) ($stats['grt_in_mare'] ?? 0))) ?></div>
      <div class="etichetta">GRT</div>
    </div>
    <?php if ((int) ($stats['navi_affondate'] ?? 0) > 0): ?>
      <div class="quadrante">
        <div class="etichetta">Mandata a fondo</div>
        <div class="valore" style="font-size:1.15rem"><?= e($num((int) ($stats['grt_affondato'] ?? 0))) ?></div>
        <div class="etichetta">GRT in <?= e($num((int) ($stats['navi_affondate'] ?? 0))) ?> navi</div>
      </div>
    <?php endif; ?>
    <div class="quadrante">
      <div class="etichetta">Tempo di crociera</div>
      <div class="valore">1:30</div>
      <div class="etichetta">un minuto = mezz'ora</div>
    </div>
  </div>

  <div class="azioni">
    <a class="bottone" href="<?= e(url('/arruolamento')) ?>">Arruolati</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/accesso')) ?>">Ho già un account</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/albo')) ?>">Albo d'oro</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/statistiche')) ?>">Statistiche</a>
  </div>
</div>

<div class="pannello">
  <h2>Stato dei lavori</h2>
  <p class="sommario">
    <b>Tutte e otto le fasi sono realizzate e collaudate.</b> Quello che c'è funziona davvero, e
    dove il gioco si discosta dalla storia lo dichiara invece di nasconderlo — le fonti e gli scarti
    sono nel registro del progetto.
  </p>
  <ul class="elenco-lavori">
    <li class="fatto">F0 — Fondamenta: account, conferma dell'indirizzo, accesso, base di flottiglia</li>
    <li class="fatto">F1 — Mondo e navigazione: griglia Marinequadrat, carta, meteo, luce, consumi, diario di bordo</li>
    <li class="fatto">F2 — Battello ed equipaggio: tipi storici, compartimenti, avarie, morale</li>
    <li class="fatto">F3 — Contatti: vedette, idrofono, radar, traffico mercantile e convogli persistenti</li>
    <li class="fatto">F4 — Combattimento: siluri e soluzione di tiro, cannone, scorte, cariche di profondità</li>
    <li class="fatto">F5 — Carriera: patrol, prestigio, gradi, decorazioni, permadeath, albo d'oro</li>
    <li class="fatto">F6 — Multigiocatore: BdU, branchi, radio e HF/DF, classifiche</li>
    <li class="fatto">F7 — Rifinitura: trofei, esportazione del giornale, applicazione installabile, amministrazione</li>
  </ul>
</div>

<div class="pannello">
  <h2>Dopo le otto fasi</h2>
  <p class="sommario">
    La tabella di marcia si è chiusa, il lavoro no. Quello che è arrivato dopo, in ordine:
  </p>
  <ul class="elenco-lavori">
    <li class="fatto"><b>Il tavolo di plottaggio.</b> Alla stazione d'attacco non si vede più la verità
      nuda: ci finisce solo quello che qualcuno ha visto o sentito, con l'errore di chi l'ha visto o
      sentito. Si vede, si riconosce e si legge il nome a tre distanze diverse, e tutti i numeri sono
      stime — è per questo che il calcolatore di lancio esiste.</li>
    <li class="fatto"><b>Il danno che resta addosso.</b> Una nave colpita e non affondata rallenta,
      perde il convoglio e diventa una ritardataria — la preda preferita degli U-Boot — e può
      affondare ore dopo, con la conferma del BdU a chi l'aveva colpita. I convogli ridotti all'osso
      si disperdono, come il PQ17.</li>
    <li class="fatto"><b>La nave civetta.</b> Si finge un piroscafo qualunque finché non apre i
      portelli: nome, classe e sagoma sono quelli della maschera, la stazza e il cannone no.</li>
    <li class="fatto"><b>Fascicoli pubblici.</b> Il fascicolo di ogni comandante è leggibile dagli
      altri in servizio: ritratto ed emblema, battello, decorazioni e trofei, rendimento, e gli
      affondamenti divisi fra naviglio militare e civile e per bandiera.</li>
    <li class="fatto"><b>Cinquecentootto volti.</b> Ritratti di comandanti di U-Boot realmente
      esistiti, da scegliere alla creazione; oppure una fotografia propria, che a richiesta viene
      resa d'epoca — bianco e nero neutro, contrasto ed esposizione portati a quelli delle stampe
      vere. Un volto, un nome e un numero di U-Boot per ciascuno, finché quel comandante è in
      servizio.</li>
    <li class="fatto"><b>Trentanove emblemi di torretta</b>, disegnati per il gioco. Non sono
      esclusivi, e non devono esserlo: molti erano di flottiglia e li portavano decine di battelli
      insieme.</li>
    <li class="fatto"><b>Amministrazione.</b> Utenti e account, origine degli accessi, comunicazioni
      su due canali, classifica, e la possibilità di intervenire sui fascicoli — con ogni intervento
      nel registro di controllo.</li>
    <li class="fatto"><b>Tablet e telefono.</b> Tutta l'interfaccia è utilizzabile su schermo
      stretto, senza toccare una riga di come si vede su un monitor.</li>
  </ul>
  <p class="aiuto" style="margin-top:1rem">
    <?php // Non si scrive il numero esatto: cresce a ogni tornata di lavoro e
          // resterebbe indietro da solo, diventando una piccola bugia. ?>
    Il gioco è collaudato da <b>oltre cinquecento verifiche automatiche</b>, e i modelli storici — autonomia,
    portata dell'idrofono, avvistamento, letalità dei siluri per stazza, meteo, radiogoniometria —
    sono tenuti dentro le bande documentate da un rapporto di bilanciamento che gira su richiesta.
  </p>
</div>
