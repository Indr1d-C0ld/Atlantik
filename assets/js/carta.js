/* Tavolo di carteggio — carta nautica su tela, proiezione di Mercatore.
 *
 * Mostra la posizione STIMATA (quella che il comandante crede di avere), il
 * cerchio d'incertezza, la rotta pianificata e la griglia Marinequadrat.
 * La posizione vera non compare: non la conosce nemmeno l'Obersteuermann.
 *
 * L'aspetto e' quello della carta di navigazione della Kriegsmarine: mare
 * graduato in azzurri, terre in ocra, cornice di pergamena con la graduazione,
 * cartiglio tedesco, rosa dei venti e scala grafica. La geometria pero' non
 * viene da nessuna immagine: e' calcolata qui, punto per punto. E' la
 * differenza fra una carta e il disegno di una carta.
 */
(function () {
  'use strict';

  var tela = document.getElementById('carta');
  if (!tela) { return; }

  var dati = JSON.parse(tela.getAttribute('data-carta'));
  var ctx = tela.getContext('2d');

  /**
   * Stile del disegno delle terre.
   *   piena       terra riempita, alone della piattaforma, nomi: una carta da
   *               tavolo, quella di serie;
   *   essenziale  solo il filo di costa a inchiostro, come una minuta tracciata
   *               in fretta. Meno cose in mezzo ai contatti, e meno da disegnare
   *               su una macchina lenta.
   * In tutti e due i casi la geometria e' la stessa: cambia il tratto, non il
   * posto delle cose.
   */
  var stileCarta = dati.stile === 'essenziale' ? 'essenziale' : 'piena';

  var CORNICE = 24;            // fascia di pergamena graduata, in pixel logici
  var W = 0, H = 0, dpr = 1;   // dimensioni logiche e densita' dello schermo

  // --- proiezione ----------------------------------------------------------
  // scala = pixel per grado di longitudine. Il valore iniziale viene fissato
  // in dimensiona(), in modo da inquadrare circa 70 gradi di longitudine:
  // l'ampiezza con cui si lavorava davvero al tavolo di carteggio.
  var vista = { lat: dati.lat, lon: dati.lon, scala: 2.6 };
  var rotta = (dati.rotta || []).map(function (w) { return { lat: w.lat, lon: w.lon }; });
  var modificata = false;

  function merc(lat) { return Math.log(Math.tan(Math.PI / 4 + (Math.max(-80, Math.min(80, lat)) * Math.PI / 180) / 2)) * 180 / Math.PI; }
  function mercInv(y) { return (2 * Math.atan(Math.exp(y * Math.PI / 180)) - Math.PI / 2) * 180 / Math.PI; }

  function xy(lat, lon) {
    return {
      x: W / 2 + (lon - vista.lon) * vista.scala,
      y: H / 2 - (merc(lat) - merc(vista.lat)) * vista.scala
    };
  }
  function latlon(x, y) {
    return {
      lon: vista.lon + (x - W / 2) / vista.scala,
      lat: mercInv(merc(vista.lat) - (y - H / 2) / vista.scala)
    };
  }

  function dentroCarta(x, y) {
    return x > CORNICE && x < W - CORNICE && y > CORNICE && y < H - CORNICE;
  }

  /**
   * Quanto si puo' allargare: mai oltre 150 gradi di longitudine in vista.
   * Le coste sono ritagliate al teatro (108°O..44°E) e piu' in la' ci sarebbe
   * il taglio, non la terra. Meglio non arrivarci.
   */
  function scalaMinima() {
    return Math.max(1.6, (W - 2 * CORNICE) / 150);
  }

  /** Il teatro e' l'Atlantico: oltre non si carteggia. */
  function limita() {
    vista.lon = Math.max(-100, Math.min(40, vista.lon));
    vista.lat = Math.max(-10, Math.min(74, vista.lat));
  }

  function stile(nome, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(nome).trim() || fallback || '#888';
  }
  function verde() { return stile('--verde-fosf', '#9fe8a0'); }

  var SANS = '"Roboto Condensed", "Liberation Sans Narrow", "Arial Narrow", system-ui, sans-serif';
  var MONO = 'ui-monospace, "DejaVu Sans Mono", "Liberation Mono", monospace';

  /** Estremi geografici della porzione visibile, con un margine. */
  function riquadro() {
    var a = latlon(-40, H + 40), b = latlon(W + 40, -40);
    return { lonW: a.lon, lonE: b.lon, latS: a.lat, latN: b.lat };
  }

  /**
   * Passo della graduazione: il piu' fitto che lasci respirare le etichette.
   * Si sceglie sui pixel, non sui gradi, cosi' la cornice resta leggibile a
   * qualunque ingrandimento e su qualunque schermo.
   */
  function passo() {
    var candidati = [0.25, 0.5, 1, 2, 5, 10, 20], i;
    for (i = 0; i < candidati.length; i++) {
      if (candidati[i] * vista.scala >= 54) { return candidati[i]; }
    }
    return 20;
  }

  // --- il mare --------------------------------------------------------------

  function mare() {
    // Nello stile essenziale il mare e' carta invecchiata, come sulle minute
    // tracciate a bordo: si vede meglio quello che ci si disegna sopra.
    if (stileCarta === 'essenziale') {
      ctx.fillStyle = stile('--carta-fondo', '#e8dfc8');
      ctx.fillRect(0, 0, W, H);
      return;
    }
    var g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, stile('--carta-mare-largo', '#3b82b1'));
    g.addColorStop(0.55, stile('--carta-mare-fondo', '#2c73a3'));
    g.addColorStop(1, stile('--carta-mare-largo', '#3b82b1'));
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);
  }

  /**
   * Gli anelli di terra che toccano la porzione visibile.
   *
   * Sono quasi cinquecento: disegnarli tutti a ogni trascinamento sarebbe
   * lavoro buttato. Il riquadro che ognuno porta con se' serve a questo.
   */
  function terreInVista() {
    var r = riquadro(), fuori = [];
    (window.ATL_TERRE || []).forEach(function (t) {
      var b = t.b;   // [latS, latN, lonO, lonE]
      if (b[1] < r.latS || b[0] > r.latN || b[3] < r.lonW || b[2] > r.lonE) { return; }
      fuori.push(t);
    });
    return fuori;
  }

  /** Il contorno di un anello di terra, come percorso chiuso da riempire. */
  function tracciato(t) {
    var p = t.p, i, q;
    ctx.beginPath();
    for (i = 0; i < p.length; i += 2) {
      q = xy(p[i], p[i + 1]);
      if (i === 0) { ctx.moveTo(q.x, q.y); } else { ctx.lineTo(q.x, q.y); }
    }
    ctx.closePath();
  }

  /**
   * La linea di costa vera.
   *
   * Dove la terra e' stata tagliata al bordo del teatro, il taglio non e' una
   * costa: si riempie ma non si disegna. Altrimenti la carta mostrerebbe una
   * spiaggia dritta lungo il 108° meridiano.
   */
  function lineaCosta(t) {
    var p = t.p, n = p.length / 2, i, k, q;
    var tratte = t.t || [[0, n]];
    for (k = 0; k < tratte.length; k++) {
      var da = tratte[k][0], quanti = tratte[k][1];
      if (quanti < 2) { continue; }
      ctx.beginPath();
      for (i = 0; i <= quanti; i++) {
        var j = ((da + i) % n) * 2;
        q = xy(p[j], p[j + 1]);
        if (i === 0) { ctx.moveTo(q.x, q.y); } else { ctx.lineTo(q.x, q.y); }
      }
      ctx.stroke();
    }
  }

  /**
   * Piattaforma continentale: un alone d'acqua chiara lungo la costa. Non e'
   * batimetria misurata — nel motore non c'e' un modello di profondita' — ma
   * il fatto che la piattaforma segua la costa e' vero, e la carta lo mostra.
   */
  function piattaforma(terre) {
    var largo = Math.max(4, Math.min(48, 1.7 * vista.scala));
    ctx.save();
    ctx.lineJoin = 'round'; ctx.lineCap = 'round';
    ctx.globalAlpha = 0.55;
    ctx.strokeStyle = stile('--carta-mare-piatt', '#79aecb');
    ctx.lineWidth = largo;
    terre.forEach(function (t) { tracciato(t); ctx.stroke(); });
    ctx.globalAlpha = 0.45;
    ctx.lineWidth = largo * 0.45;
    terre.forEach(function (t) { tracciato(t); ctx.stroke(); });
    ctx.restore();
  }

  function terre(elenco) {
    var terra = stile('--carta-terra', '#9b9a68'),
        alta  = stile('--carta-terra-alta', '#a8a06a'),
        orlo  = stile('--carta-terra-orlo', '#6f6c46');

    if (stileCarta === 'essenziale') {
      ctx.strokeStyle = orlo; ctx.lineWidth = 1.2; ctx.lineJoin = 'round';
      elenco.forEach(lineaCosta);
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = stile('--carta-testo-terra', 'rgba(60,52,30,.62)');
      (window.ATL_ETICHETTE || []).forEach(function (et) {
        if (!et.grande) { return; }        // solo i continenti: il resto e' rumore
        var q = xy(et.lat, et.lon);
        if (!dentroCarta(q.x, q.y)) { return; }
        ctx.font = Math.max(11, Math.min(18, vista.scala * 1.0)) + 'px ' + SANS;
        ctx.fillText(et.nome.split('').join(' '), q.x, q.y);
      });
      ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
      return;
    }

    // Il riempimento, tutto in un percorso solo: e' molto piu' svelto che
    // riempire cinquecento anelli uno per uno, e il risultato e' identico.
    ctx.fillStyle = terra;
    ctx.beginPath();
    elenco.forEach(function (t) {
      var p = t.p, i, q;
      for (i = 0; i < p.length; i += 2) {
        q = xy(p[i], p[i + 1]);
        if (i === 0) { ctx.moveTo(q.x, q.y); } else { ctx.lineTo(q.x, q.y); }
      }
      ctx.closePath();
    });
    ctx.fill();

    // Una velatura piu' chiara verso l'interno da' il rilievo delle carte vere.
    // Da lontano non si distinguerebbe: si risparmia il lavoro.
    if (vista.scala > 2.5) {
      ctx.save(); ctx.clip();
      ctx.globalAlpha = 0.34; ctx.strokeStyle = alta;
      // Una fascia stretta lungo la costa, non una mano di bianco su tutto:
      // sulle carte vere il rilievo si schiarisce verso l'interno, ma la
      // terraferma resta terraferma.
      ctx.lineWidth = Math.max(5, Math.min(26, 1.1 * vista.scala));
      elenco.forEach(function (t) { tracciato(t); ctx.stroke(); });
      ctx.restore();
      ctx.globalAlpha = 1;
    }

    // La linea di costa, che non e' il contorno del riempimento: dove la terra
    // e' tagliata al bordo del teatro non c'e' nessuna spiaggia da disegnare.
    ctx.strokeStyle = orlo; ctx.lineWidth = 1; ctx.lineJoin = 'round';
    elenco.forEach(lineaCosta);

    // Nomi delle terre, spaziati come sulle carte stampate.
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    var inchTerra = stile('--carta-testo-terra', 'rgba(60,52,30,.62)');
    var inchMare  = stile('--carta-testo-mare', 'rgba(15,35,55,.55)');
    (window.ATL_ETICHETTE || []).forEach(function (et) {
      var q = xy(et.lat, et.lon);
      if (!dentroCarta(q.x, q.y)) { return; }
      var dim = et.grande
        ? Math.max(12, Math.min(22, vista.scala * 1.1))
        : Math.max(9, Math.min(14, vista.scala * 0.8));
      // I nomi di mare sono in corsivo e nell'inchiostro dell'acqua: e' la
      // convenzione di ogni carta nautica, e si legge senza pensarci.
      ctx.font = (et.grande ? '700 ' : 'italic ') + dim + 'px ' + SANS;
      ctx.fillStyle = et.mare ? inchMare : inchTerra;
      ctx.fillText(et.grande ? et.nome.split('').join(' ') : et.nome, q.x, q.y);
    });
    ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
  }

  // --- reticolo -------------------------------------------------------------

  function graticola() {
    var r = riquadro(), p = passo(), v;
    ctx.strokeStyle = stile('--carta-griglia', 'rgba(20,16,8,.2)');
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (v = Math.ceil(r.lonW / p) * p; v <= r.lonE; v += p) {
      var q = xy(0, v); ctx.moveTo(Math.round(q.x) + 0.5, 0); ctx.lineTo(Math.round(q.x) + 0.5, H);
    }
    for (v = Math.ceil(r.latS / p) * p; v <= r.latN; v += p) {
      var s = xy(v, 0); ctx.moveTo(0, Math.round(s.y) + 0.5); ctx.lineTo(W, Math.round(s.y) + 0.5);
    }
    ctx.stroke();
  }

  /**
   * Marinequadrat: bande di 8 gradi di latitudine da 73N, colonne di 12 gradi
   * da 72W. Le sigle dei grandi quadrati sono stampate sulla carta come lo
   * erano su quelle di bordo — e' con quelle che si parla col BdU.
   */
  function quadrati() {
    var r = riquadro();
    ctx.save();
    ctx.strokeStyle = stile('--carta-quadrat', 'rgba(120,20,20,.45)');
    ctx.lineWidth = 1.2;
    ctx.setLineDash([7, 5]);
    ctx.beginPath();
    var lo, la;
    for (lo = dati.lon_west + Math.floor((r.lonW - dati.lon_west) / 12) * 12; lo <= r.lonE + 12; lo += 12) {
      var q = xy(0, lo); ctx.moveTo(q.x, 0); ctx.lineTo(q.x, H);
    }
    for (la = dati.lat_top; la >= -60; la -= 8) {
      if (la < r.latS - 8 || la > r.latN + 8) { continue; }
      var s = xy(la, 0); ctx.moveTo(0, s.y); ctx.lineTo(W, s.y);
    }
    ctx.stroke();
    ctx.setLineDash([]);

    // Suddivisione interna in 3x3, ma solo quando c'e' spazio per vederla.
    if (vista.scala > 3.2) {
      ctx.globalAlpha = 0.45; ctx.lineWidth = 0.8;
      ctx.beginPath();
      for (lo = dati.lon_west + Math.floor((r.lonW - dati.lon_west) / 12) * 12; lo <= r.lonE + 12; lo += 4) {
        var q2 = xy(0, lo); ctx.moveTo(q2.x, 0); ctx.lineTo(q2.x, H);
      }
      for (la = dati.lat_top; la >= -60; la -= 8 / 3) {
        if (la < r.latS - 3 || la > r.latN + 3) { continue; }
        var s2 = xy(la, 0); ctx.moveTo(0, s2.y); ctx.lineTo(W, s2.y);
      }
      ctx.stroke();
      ctx.globalAlpha = 1;
    }

    // Sigle dei grandi quadrati. Stanno al centro del quadrato, ma se il
    // quadrato sborda dallo schermo la sigla si sposta per restare leggibile:
    // il comandante deve sapere sempre in che quadrato si trova.
    var dim = Math.max(9, Math.min(20, vista.scala * 1.5));
    ctx.font = '700 ' + dim + 'px ' + SANS;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = stile('--carta-quadrat', 'rgba(120,20,20,.45)');
    ctx.globalAlpha = 0.55;
    var m = CORNICE + 14;
    (dati.quadrati || []).forEach(function (qd) {
      var n = dati.lat_top - qd.row * 8, s = n - 8,
          o = dati.lon_west + qd.col * 12, e = o + 12;
      if (e < r.lonW || o > r.lonE || n < r.latS || s > r.latN) { return; }
      var a1 = xy(n, o), a2 = xy(s, e);
      var vx = Math.min(a2.x, W - m) - Math.max(a1.x, m);
      var vy = Math.min(a2.y, H - m) - Math.max(a1.y, m);
      // Meno di un terzo di quadrato in vista: la sigla confonderebbe soltanto.
      if (vx < (a2.x - a1.x) * 0.34 || vy < (a2.y - a1.y) * 0.34) { return; }
      var cx = Math.min(Math.max((a1.x + a2.x) / 2, Math.max(a1.x, m) + dim), Math.min(a2.x, W - m) - dim);
      var cy = Math.min(Math.max((a1.y + a2.y) / 2, Math.max(a1.y, m) + dim), Math.min(a2.y, H - m) - dim);
      if (!isFinite(cx) || !isFinite(cy)) { return; }
      ctx.fillText(qd.sigla, cx, cy);
    });
    ctx.restore();
    ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
  }

  // --- cornice, cartiglio, strumenti ----------------------------------------

  function etichettaGrado(v, assi) {
    if (assi === 'EW') {
      while (v > 180) { v -= 360; }
      while (v < -180) { v += 360; }
    }
    var segno = v < 0 ? assi[1] : assi[0];
    var a = Math.abs(v);
    return (a % 1 === 0 ? a : a.toFixed(1)) + '°' + (a === 0 ? '' : segno);
  }

  function cornice() {
    var perg = stile('--carta-pergamena', '#dec7a6'),
        perg2 = stile('--carta-pergamena-2', '#c9ae89'),
        inch = stile('--carta-inchiostro', '#16120b');
    var r = riquadro(), p = passo(), v;

    // Fascia di pergamena sui quattro lati.
    var g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, perg); g.addColorStop(0.5, perg2); g.addColorStop(1, perg);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, CORNICE);
    ctx.fillRect(0, H - CORNICE, W, CORNICE);
    ctx.fillRect(0, 0, CORNICE, H);
    ctx.fillRect(W - CORNICE, 0, CORNICE, H);

    ctx.strokeStyle = inch; ctx.lineWidth = 1;
    ctx.strokeRect(0.5, 0.5, W - 1, H - 1);
    ctx.strokeRect(CORNICE + 0.5, CORNICE + 0.5, W - 2 * CORNICE - 1, H - 2 * CORNICE - 1);

    // Graduazione: tacca lunga sul passo, corta a meta'.
    ctx.fillStyle = inch; ctx.strokeStyle = inch;
    ctx.font = '9px ' + MONO;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.beginPath();
    for (v = Math.ceil(r.lonW / (p / 2)) * (p / 2); v <= r.lonE; v += p / 2) {
      var q = xy(0, v);
      if (q.x < CORNICE || q.x > W - CORNICE) { continue; }
      var lunga = Math.abs(v / p - Math.round(v / p)) < 1e-6;
      var h = lunga ? 7 : 4;
      ctx.moveTo(q.x, CORNICE); ctx.lineTo(q.x, CORNICE - h);
      ctx.moveTo(q.x, H - CORNICE); ctx.lineTo(q.x, H - CORNICE + h);
      if (lunga) {
        ctx.fillText(etichettaGrado(v, 'EW'), q.x, CORNICE / 2 - 1);
        ctx.fillText(etichettaGrado(v, 'EW'), q.x, H - CORNICE / 2 + 1);
      }
    }
    for (v = Math.ceil(r.latS / (p / 2)) * (p / 2); v <= r.latN; v += p / 2) {
      var s = xy(v, 0);
      if (s.y < CORNICE || s.y > H - CORNICE) { continue; }
      var lunga2 = Math.abs(v / p - Math.round(v / p)) < 1e-6;
      var h2 = lunga2 ? 7 : 4;
      ctx.moveTo(CORNICE, s.y); ctx.lineTo(CORNICE - h2, s.y);
      ctx.moveTo(W - CORNICE, s.y); ctx.lineTo(W - CORNICE + h2, s.y);
      if (lunga2) {
        ctx.save();
        ctx.translate(CORNICE / 2, s.y); ctx.rotate(-Math.PI / 2);
        ctx.fillText(etichettaGrado(v, 'NS'), 0, 0);
        ctx.restore();
        ctx.save();
        ctx.translate(W - CORNICE / 2, s.y); ctx.rotate(Math.PI / 2);
        ctx.fillText(etichettaGrado(v, 'NS'), 0, 0);
        ctx.restore();
      }
    }
    ctx.stroke();
    ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
  }

  /** Riquadro di pergamena con orlo d'inchiostro: la base di ogni cartiglio. */
  function riquadroCarta(x, y, w, h) {
    ctx.fillStyle = stile('--carta-pergamena', '#dec7a6');
    ctx.globalAlpha = 0.94;
    ctx.fillRect(x, y, w, h);
    ctx.globalAlpha = 1;
    ctx.strokeStyle = stile('--carta-inchiostro', '#16120b');
    ctx.lineWidth = 1;
    ctx.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
    ctx.strokeRect(x + 2.5, y + 2.5, w - 5, h - 5);
  }

  function cartiglio() {
    var x = CORNICE + 8, y = CORNICE + 8, w = 176, h = 62;
    if (W < 460 || H < 320) { return; }
    var inch = stile('--carta-inchiostro', '#16120b');
    riquadroCarta(x, y, w, h);

    ctx.fillStyle = inch;
    ctx.textAlign = 'center';
    ctx.font = '700 11px ' + SANS;
    ctx.fillText('K R I E G S M A R I N E', x + w / 2, y + 17);
    ctx.font = '10px ' + SANS;
    ctx.fillText('U-Boot-Navigationskarte', x + w / 2, y + 30);
    ctx.fillText('Atlantischer Ozean', x + w / 2, y + 41);

    // Rapporto di scala: indicativo, perche' dipende dallo schermo. Quello
    // che si misura davvero e' la scala grafica qui sotto.
    var mPerPx = 111320 * Math.cos(vista.lat * Math.PI / 180) / vista.scala;
    var n = Math.round(mPerPx / 0.00026 / 100000) * 100000;
    ctx.font = '8px ' + MONO;
    ctx.fillText('Maßstab ca. 1 : ' + n.toLocaleString('de-DE'), x + w / 2, y + 53);
    ctx.textAlign = 'start';
  }

  function rosa() {
    if (W < 520 || H < 360) { return; }
    var cx = W - CORNICE - 46, cy = H - CORNICE - 46, r = 28;
    var inch = stile('--carta-inchiostro', '#16120b');
    riquadroCarta(cx - 38, cy - 38, 76, 76);

    ctx.save();
    ctx.translate(cx, cy);
    ctx.strokeStyle = inch; ctx.fillStyle = inch; ctx.lineWidth = 0.8;
    ctx.beginPath(); ctx.arc(0, 0, r * 0.62, 0, 6.3); ctx.stroke();

    for (var i = 0; i < 8; i++) {
      var a = i * Math.PI / 4;
      var lungo = i % 2 === 0;
      var lun = lungo ? r : r * 0.66;
      ctx.beginPath();
      ctx.moveTo(Math.sin(a) * lun, -Math.cos(a) * lun);
      ctx.lineTo(Math.sin(a + 0.28) * lun * 0.26, -Math.cos(a + 0.28) * lun * 0.26);
      ctx.lineTo(Math.sin(a - 0.28) * lun * 0.26, -Math.cos(a - 0.28) * lun * 0.26);
      ctx.closePath();
      ctx.globalAlpha = i % 2 === 0 ? 0.85 : 0.45;
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    ctx.font = '700 9px ' + SANS;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText('N', 0, -r - 5);
    ctx.font = '8px ' + SANS;
    ctx.fillText('S', 0, r + 5); ctx.fillText('O', r + 5, 0); ctx.fillText('W', -r - 5, 0);
    ctx.restore();
    ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
  }

  /** Scala grafica a blocchi alternati: l'unica misura sempre esatta. */
  function scalaGrafica() {
    var nmPerGrado = 60 * Math.cos(vista.lat * Math.PI / 180);
    var pxPerNm = vista.scala / nmPerGrado;
    var passi = [10, 20, 50, 100, 200, 500, 1000], scelta = 100, i;
    for (i = 0; i < passi.length; i++) {
      if (passi[i] * pxPerNm >= 44) { scelta = passi[i]; break; }
      scelta = passi[i];
    }
    var lun = scelta * pxPerNm, blocchi = 4;
    if (lun * blocchi > W - 2 * CORNICE - 120) { blocchi = 2; }

    var x0 = CORNICE + 14, y0 = H - CORNICE - 26, alt = 7;
    var inch = stile('--carta-inchiostro', '#16120b');
    riquadroCarta(x0 - 10, y0 - 15, lun * blocchi + 48, 38);

    ctx.strokeStyle = inch; ctx.lineWidth = 1;
    for (i = 0; i < blocchi; i++) {
      ctx.fillStyle = i % 2 === 0 ? inch : stile('--carta-pergamena', '#dec7a6');
      ctx.fillRect(x0 + i * lun, y0, lun, alt);
      ctx.strokeRect(x0 + i * lun + 0.5, y0 + 0.5, lun, alt);
    }
    ctx.fillStyle = inch;
    ctx.font = '8px ' + MONO; ctx.textAlign = 'center';
    ctx.fillText('0', x0, y0 - 4);
    ctx.fillText((scelta * blocchi).toLocaleString('it-IT') + ' nm', x0 + lun * blocchi, y0 - 4);
    ctx.fillText(Math.round(scelta * blocchi * 1.852).toLocaleString('it-IT') + ' km', x0 + lun * blocchi / 2, y0 + alt + 10);
    ctx.textAlign = 'start';
  }

  function legenda() {
    if (W < 620 || H < 420) { return; }
    var w = 128, h = 76, x = W - CORNICE - w - 8, y = CORNICE + 8;
    var inch = stile('--carta-inchiostro', '#16120b');
    riquadroCarta(x, y, w, h);

    ctx.fillStyle = inch; ctx.font = '700 9px ' + SANS;
    ctx.fillText('SEGNI CONVENZIONALI', x + 8, y + 15);
    ctx.font = '9px ' + SANS;

    var voci = [
      ['battello', 'il nostro battello'],
      ['convoglio', 'convoglio'],
      ['nave', 'nave isolata'],
      ['aereo', 'aereo']
    ];
    voci.forEach(function (v, i) {
      var cy = y + 29 + i * 12, cx = x + 14;
      ctx.save();
      if (v[0] === 'battello') {
        ctx.fillStyle = inch;
        ctx.beginPath(); ctx.moveTo(cx, cy - 5); ctx.lineTo(cx + 2.5, cy + 3);
        ctx.lineTo(cx, cy + 1.5); ctx.lineTo(cx - 2.5, cy + 3); ctx.closePath(); ctx.fill();
      } else {
        ctx.strokeStyle = v[0] === 'aereo' ? stile('--rosso', '#d4564b') : verde();
        ctx.lineWidth = 1.4; ctx.beginPath();
        if (v[0] === 'convoglio') { ctx.rect(cx - 5, cy - 3, 10, 6); }
        else if (v[0] === 'aereo') {
          ctx.moveTo(cx - 5, cy); ctx.lineTo(cx + 5, cy);
          ctx.moveTo(cx, cy - 4); ctx.lineTo(cx, cy + 4);
        } else { ctx.arc(cx, cy, 4, 0, 6.3); }
        ctx.stroke();
      }
      ctx.restore();
      ctx.fillStyle = inch;
      ctx.fillText(v[1], x + 26, cy + 3);
    });
  }

  // --- disegno --------------------------------------------------------------
  function disegna() {
    var inch = stile('--carta-inchiostro', '#16120b'),
        acc  = stile('--accento', '#c39a52');

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);

    // Tutto cio' che e' carta sta dentro il neatline: nomi, coste e simboli
    // non sbavano sulla cornice, esattamente come su un foglio stampato.
    ctx.save();
    ctx.beginPath();
    ctx.rect(CORNICE, CORNICE, W - 2 * CORNICE, H - 2 * CORNICE);
    ctx.clip();

    mare();
    var elenco = terreInVista();
    if (stileCarta !== 'essenziale') {
      piattaforma(elenco);
    }
    terre(elenco);
    graticola();
    quadrati();

    // Isole e riferimenti minori. Se il segno cade fuori dal neatline si tace:
    // meglio niente che un nome mozzato contro la cornice.
    ctx.fillStyle = stile('--carta-terra-orlo', '#6f6c46');
    ctx.font = '10px ' + SANS;
    (window.ATL_ISOLE || []).forEach(function (is) {
      var p = xy(is.lat, is.lon);
      if (!dentroCarta(p.x, p.y)) { return; }
      ctx.beginPath(); ctx.arc(p.x, p.y, 2.5, 0, 6.3); ctx.fill();
      ctx.fillText(is.nome, p.x + 5, p.y + 3);
    });

    // Porti e basi.
    (dati.porti || []).forEach(function (po) {
      var p = xy(po.lat, po.lon);
      if (!dentroCarta(p.x, p.y)) { return; }
      ctx.fillStyle = po.base ? acc : inch;
      ctx.beginPath(); ctx.arc(p.x, p.y, po.base ? 4 : 2.5, 0, 6.3); ctx.fill();
      if (po.base) {
        ctx.strokeStyle = inch; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.arc(p.x, p.y, 6.5, 0, 6.3); ctx.stroke();
      }
      if (vista.scala > 1.6) {
        ctx.fillStyle = inch; ctx.font = (po.base ? '700 ' : '') + '10px ' + SANS;
        ctx.fillText(po.nome, p.x + 8, p.y - 4);
      }
    });

    // Rotta pianificata.
    if (rotta.length) {
      var start = xy(dati.lat, dati.lon);
      ctx.strokeStyle = acc; ctx.lineWidth = 1.8; ctx.setLineDash([5, 3]);
      ctx.beginPath(); ctx.moveTo(start.x, start.y);
      rotta.forEach(function (wp) { var p = xy(wp.lat, wp.lon); ctx.lineTo(p.x, p.y); });
      ctx.stroke(); ctx.setLineDash([]);
      rotta.forEach(function (wp, i) {
        var p = xy(wp.lat, wp.lon);
        ctx.fillStyle = acc;
        ctx.beginPath(); ctx.arc(p.x, p.y, 5, 0, 6.3); ctx.fill();
        ctx.strokeStyle = inch; ctx.lineWidth = 1; ctx.stroke();
        ctx.fillStyle = '#16120b'; ctx.font = '700 9px ' + MONO;
        ctx.fillText(String(i + 1), p.x - 2.5, p.y + 3);
      });
    }

    // Contatti: dove il comandante CREDE che sia il bersaglio. Un contatto
    // idrofonico ha una direzione buona e una distanza inventata, quindi si
    // disegna come un settore lungo il rilevamento, non come un punto.
    (dati.contatti || []).forEach(function (k) {
      var p = xy(k.lat, k.lon);
      var b0c = xy(dati.lat, dati.lon);
      var certo = Math.max(0.15, Math.min(1, k.certezza));

      if (k.distanza === null) {
        ctx.strokeStyle = verde(); ctx.globalAlpha = 0.5; ctx.setLineDash([5, 4]); ctx.lineWidth = 1.2;
        ctx.beginPath(); ctx.moveTo(b0c.x, b0c.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        ctx.setLineDash([]); ctx.globalAlpha = 1;
      }

      ctx.strokeStyle = k.tipo === 'aereo' ? stile('--rosso', '#d4564b') : verde();
      ctx.lineWidth = 1.8;
      ctx.globalAlpha = 0.45 + 0.55 * certo;
      ctx.beginPath();
      if (k.tipo === 'convoglio') {
        ctx.rect(p.x - 6, p.y - 4, 12, 8);
      } else if (k.tipo === 'aereo') {
        ctx.moveTo(p.x - 6, p.y); ctx.lineTo(p.x + 6, p.y);
        ctx.moveTo(p.x, p.y - 5); ctx.lineTo(p.x, p.y + 5);
      } else {
        ctx.arc(p.x, p.y, 5, 0, 6.3);
      }
      ctx.stroke();
      ctx.globalAlpha = 1;

      // Il nome si scrive solo se il segno e' dentro il neatline: altrimenti
      // resterebbe mezza parola appoggiata alla cornice.
      if (vista.scala > 1.2 && dentroCarta(p.x, p.y)) {
        ctx.fillStyle = verde(); ctx.font = '9px ' + MONO;
        ctx.fillText(k.classe.slice(0, 22), p.x + 8, p.y + 3);
      }
    });

    // Cerchio d'incertezza e battello.
    var b0 = xy(dati.lat, dati.lon);
    var nmPerGrado = 60 * Math.cos(dati.lat * Math.PI / 180);
    var raggio = (dati.errore_nm / nmPerGrado) * vista.scala;
    if (raggio > 2) {
      ctx.strokeStyle = inch; ctx.globalAlpha = 0.55; ctx.setLineDash([3, 3]); ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(b0.x, b0.y, raggio, 0, 6.3); ctx.stroke();
      ctx.setLineDash([]); ctx.globalAlpha = 1;
    }

    // Sagoma del battello, orientata sulla rotta.
    ctx.save();
    ctx.translate(b0.x, b0.y);
    ctx.rotate(dati.heading * Math.PI / 180);
    ctx.fillStyle = inch;
    ctx.beginPath();
    ctx.moveTo(0, -10); ctx.lineTo(4, 4.5); ctx.lineTo(0, 2.5); ctx.lineTo(-4, 4.5);
    ctx.closePath(); ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,.65)'; ctx.lineWidth = 0.8; ctx.stroke();
    ctx.restore();

    ctx.restore();

    cornice();
    cartiglio();
    legenda();
    rosa();
    scalaGrafica();
  }

  // --- interazione ----------------------------------------------------------
  var trascino = null;

  function puntoTela(e) {
    var r = tela.getBoundingClientRect();
    return { x: (e.clientX - r.left) * (W / r.width), y: (e.clientY - r.top) * (H / r.height) };
  }

  tela.addEventListener('mousedown', function (e) {
    var p = puntoTela(e);
    trascino = { x: p.x, y: p.y, lat: vista.lat, lon: vista.lon, mosso: false };
  });
  window.addEventListener('mouseup', function (e) {
    if (trascino && !trascino.mosso && e.target === tela) {
      var p = puntoTela(e);
      if (dentroCarta(p.x, p.y)) {
        var g = latlon(p.x, p.y);
        rotta.push({ lat: Math.round(g.lat * 1000) / 1000, lon: Math.round(g.lon * 1000) / 1000 });
        modificata = true;
        aggiornaElenco();
        disegna();
      }
    }
    trascino = null;
  });
  tela.addEventListener('mousemove', function (e) {
    if (!trascino) { return; }
    var p = puntoTela(e);
    var dx = p.x - trascino.x, dy = p.y - trascino.y;
    if (Math.abs(dx) + Math.abs(dy) > 4) { trascino.mosso = true; }
    vista.lon = trascino.lon - dx / vista.scala;
    vista.lat = mercInv(merc(trascino.lat) + dy / vista.scala);
    limita();
    disegna();
  });
  tela.addEventListener('wheel', function (e) {
    e.preventDefault();
    // Si ingrandisce attorno al punto sotto il cursore, non attorno al centro
    // della carta: al tavolo si mette il dito su un punto e ci si avvicina a
    // quello. Si prende la posizione geografica sotto il cursore, si cambia
    // scala, e si sposta la vista quel tanto che serve a rimettercela sotto.
    var p = puntoTela(e);
    var prima = latlon(p.x, p.y);
    vista.scala = Math.max(scalaMinima(), Math.min(40, vista.scala * (e.deltaY < 0 ? 1.15 : 0.87)));
    var dopo = latlon(p.x, p.y);
    vista.lon += prima.lon - dopo.lon;
    vista.lat = mercInv(merc(vista.lat) + (merc(prima.lat) - merc(dopo.lat)));
    limita();
    disegna();
  }, { passive: false });

  // --- elenco dei punti di rotta --------------------------------------------
  var elenco = document.getElementById('elenco-rotta');
  var campo = document.getElementById('campo-waypoints');
  var bottoneSalva = document.getElementById('salva-rotta');

  function aggiornaElenco() {
    if (!elenco) { return; }
    if (!rotta.length) {
      elenco.innerHTML = '<li class="vuoto">Nessun punto di rotta. Fai clic sulla carta per tracciarla.</li>';
    } else {
      elenco.innerHTML = rotta.map(function (wp, i) {
        return '<li><b>' + (i + 1) + '</b> <span>' + gradi(wp.lat, 'NS') + '  ' + gradi(wp.lon, 'EW') +
               '</span><button type="button" data-i="' + i + '" class="togli">×</button></li>';
      }).join('');
    }
    if (campo) { campo.value = JSON.stringify(rotta); }
    if (bottoneSalva) { bottoneSalva.disabled = !modificata; }
  }

  function gradi(v, assi) {
    var segno = v < 0 ? assi[1] : assi[0];
    var a = Math.abs(v), g = Math.floor(a), m = (a - g) * 60;
    return g + '°' + m.toFixed(1).replace('.', ',') + "' " + segno;
  }

  if (elenco) {
    elenco.addEventListener('click', function (e) {
      var b = e.target.closest('.togli');
      if (!b) { return; }
      rotta.splice(parseInt(b.getAttribute('data-i'), 10), 1);
      modificata = true;
      aggiornaElenco(); disegna();
    });
  }

  var pulisci = document.getElementById('pulisci-rotta');
  if (pulisci) {
    pulisci.addEventListener('click', function () {
      rotta = []; modificata = true; aggiornaElenco(); disegna();
    });
  }

  // --- provenienza del rilievo ----------------------------------------------
  // Scritta in chiaro sotto la carta: chi la guarda deve poter sapere QUALE
  // rilievo sta vedendo, senza indovinarlo dalla forma dell'Islanda. Serve
  // anche a scoprire in un secondo se il browser sta servendo un file vecchio.
  (function () {
    var dove = document.getElementById('fonte-coste');
    if (!dove) { return; }
    var f = window.ATL_COSTE_FONTE;
    dove.textContent = f
      ? 'Coste: ' + f.nome + ' — ' + f.anelli + ' anelli, ' + f.vertici.toLocaleString('it-IT') + ' vertici.'
      : 'Coste: tracciato semplificato (nessuna firma nel file).';
  })();

  // --- avvio ----------------------------------------------------------------
  var scalaFissata = false;

  function dimensiona() {
    var r = tela.parentElement.getBoundingClientRect();
    dpr = Math.min(3, window.devicePixelRatio || 1);
    W = Math.max(320, Math.floor(r.width) - 2);
    H = Math.max(320, Math.min(760, Math.floor(window.innerHeight * 0.70)));
    tela.width = Math.round(W * dpr);
    tela.height = Math.round(H * dpr);
    tela.style.height = H + 'px';
    if (!scalaFissata) {
      vista.scala = Math.max(scalaMinima(), Math.min(12, (W - 2 * CORNICE) / 70));
      scalaFissata = true;
    }
    limita();
    disegna();
  }
  window.addEventListener('resize', dimensiona);
  dimensiona();
  aggiornaElenco();
})();
