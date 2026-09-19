/**
 * Atlantik — la carta ammiraglia.
 *
 * Tutto il teatro in una tela sola: traffico alleato, convogli con la loro
 * scorta, battelli dei giocatori, e a richiesta il vento e lo stato del mare
 * stesi su una maglia. E' una vista che in gioco NON esiste: dal ponte si vede
 * quello che le vedette vedono, di qui si vede tutto. Per questo sta dietro al
 * pannello di amministrazione e da nessun'altra parte.
 *
 * La proiezione e' la stessa della carta di bordo — equirettangolare sul
 * teatro, cosi' i quadrati Marinequadrat restano rettangoli e un rilevamento
 * letto qui e uno letto li' si somigliano.
 *
 * Niente JavaScript in linea: la politica dei contenuti non lo permette.
 */
(function () {
  'use strict';

  var tela = document.getElementById('carta-admin');
  if (!tela || !tela.getContext) { return; }

  var ctx = tela.getContext('2d');
  var cfg = JSON.parse(tela.getAttribute('data-carta'));
  var W = tela.width, H = tela.height;

  // Il teatro disegnato per intero: piu' largo della griglia dei quadrati,
  // perche' le rotte americane partono da fuori. E' anche la vista di
  // partenza, quella a cui si torna col tasto "tutto il teatro".
  var LAT_N = 72, LAT_S = 20, LON_W = -80, LON_E = 20;

  // La vista: quanto si ingrandisce e dove si e' spostati. La proiezione resta
  // equirettangolare come sulla carta di bordo — si scala e si trasla, non si
  // cambia proiezione: cosi' i quadrati Marinequadrat restano rettangoli a
  // qualunque ingrandimento, e un rilevamento letto qui somiglia a uno letto
  // in plancia.
  var ZOOM_MIN = 1, ZOOM_MAX = 24;
  var vista = { z: 1, cx: (LON_W + LON_E) / 2, cy: (LAT_N + LAT_S) / 2 };

  var mondo = { navi: [], convogli: [], battelli: [], meteo: [], quadro: null, ora: '' };
  var scelta = null;

  // Gradi coperti dalla tela all'ingrandimento corrente.
  function ampiezzaLon() { return (LON_E - LON_W) / vista.z; }
  function ampiezzaLat() { return (LAT_N - LAT_S) / vista.z; }

  function x(lon) { return (lon - (vista.cx - ampiezzaLon() / 2)) / ampiezzaLon() * W; }
  function y(lat) { return ((vista.cy + ampiezzaLat() / 2) - lat) / ampiezzaLat() * H; }
  function lonDa(px) { return (vista.cx - ampiezzaLon() / 2) + px / W * ampiezzaLon(); }
  function latDa(py) { return (vista.cy + ampiezzaLat() / 2) - py / H * ampiezzaLat(); }

  /**
   * Il centro non esce dal teatro, e non si scavalca il bordo.
   *
   * Senza questo si finisce col trascinare la carta fino a guardare il nulla,
   * e poi non si sa piu' come tornare indietro.
   */
  function limitaVista() {
    vista.z = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, vista.z));
    var mezzoLon = ampiezzaLon() / 2, mezzoLat = ampiezzaLat() / 2;
    if (ampiezzaLon() >= LON_E - LON_W) {
      vista.cx = (LON_W + LON_E) / 2;
    } else {
      vista.cx = Math.max(LON_W + mezzoLon, Math.min(LON_E - mezzoLon, vista.cx));
    }
    if (ampiezzaLat() >= LAT_N - LAT_S) {
      vista.cy = (LAT_N + LAT_S) / 2;
    } else {
      vista.cy = Math.max(LAT_S + mezzoLat, Math.min(LAT_N - mezzoLat, vista.cy));
    }
  }

  /** Ingrandisce tenendo fermo il punto sotto il cursore. */
  function ingrandisci(fattore, pxAncora, pyAncora) {
    var lonPrima = lonDa(pxAncora), latPrima = latDa(pyAncora);
    vista.z = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, vista.z * fattore));
    limitaVista();
    // Si rimette sotto il cursore lo stesso punto di mare di prima.
    vista.cx += lonPrima - lonDa(pxAncora);
    vista.cy += latPrima - latDa(pyAncora);
    limitaVista();
    disegna();
    aggiornaZoom();
  }

  function aggiornaZoom() {
    var e = document.querySelector('[data-zoom-valore]');
    if (e) { e.textContent = vista.z < 1.05 ? 'tutto il teatro' : ('×' + vista.z.toFixed(1)); }
  }

  function mostra(nome) {
    var c = document.querySelector('[data-mostra="' + nome + '"]');
    return c ? c.checked : true;
  }

  // --- disegno ---------------------------------------------------------------

  function fondo() {
    var g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, '#0b1420');
    g.addColorStop(1, '#0a1018');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);
  }

  function coste() {
    if (!window.ATL_TERRE || !mostra('coste')) { return; }
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(150,170,190,.45)';
    ctx.fillStyle = 'rgba(28,38,48,.75)';
    // Il formato e' quello della carta di bordo: ogni terra ha una scatola
    // (b: latS, latN, lonO, lonE) per scartarla in fretta, e i punti in p,
    // coppie LAT,LON — in quest'ordine, che e' l'ordine in cui si leggono
    // le coordinate e non quello in cui si disegnano.
    window.ATL_TERRE.forEach(function (terra) {
      var b = terra.b;
      if (b[1] < LAT_S || b[0] > LAT_N || b[3] < LON_W || b[2] > LON_E) { return; }
      var p = terra.p;
      ctx.beginPath();
      for (var i = 0; i < p.length; i += 2) {
        var px = x(p[i + 1]), py = y(p[i]);
        if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
      }
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
    });
  }

  function griglia() {
    if (!mostra('griglia')) { return; }
    ctx.strokeStyle = 'rgba(190,160,90,.16)';
    ctx.lineWidth = 1;
    ctx.font = '11px monospace';
    ctx.fillStyle = 'rgba(190,160,90,.42)';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';

    cfg.quadrati.forEach(function (q) {
      var lat2 = cfg.lat_top - q.row * cfg.band_deg;
      var lat1 = lat2 - cfg.band_deg;
      var lon1 = cfg.lon_west + q.col * cfg.col_deg;
      var lon2 = lon1 + cfg.col_deg;
      var px = x(lon1), py = y(lat2);
      ctx.strokeRect(px, py, x(lon2) - px, y(lat1) - py);
      ctx.fillText(q.sigla, px + 4, py + 3);
    });
  }

  function porti() {
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    cfg.porti.forEach(function (p) {
      var px = x(p.lon), py = y(p.lat);
      ctx.fillStyle = p.base ? '#d8a33c' : 'rgba(180,190,200,.6)';
      ctx.beginPath();
      ctx.arc(px, py, p.base ? 4 : 2.5, 0, Math.PI * 2);
      ctx.fill();
      if (p.base) {
        ctx.font = '11px sans-serif';
        ctx.fillStyle = 'rgba(216,163,60,.85)';
        ctx.fillText(p.nome, px + 7, py);
      }
    });
  }

  function meteo() {
    if (!mostra('meteo') || !mondo.meteo.length) { return; }
    mondo.meteo.forEach(function (m) {
      var px = x(m.lon), py = y(m.lat);
      if (px < 0 || px > W || py < 0 || py > H) { return; }

      // Lo stato del mare come alone: piu' e' grosso, piu' si vede.
      if (m.mare > 0) {
        ctx.fillStyle = 'rgba(90,150,200,' + Math.min(0.30, m.mare * 0.035) + ')';
        ctx.beginPath();
        ctx.arc(px, py, 4 + m.mare * 2.6, 0, Math.PI * 2);
        ctx.fill();
      }
      // Il vento come freccia: punta dove va, lunga quanto soffia.
      var lung = Math.min(26, 4 + m.kn * 0.55);
      var a = (m.dir + 180) * Math.PI / 180;
      var dx = Math.sin(a) * lung, dy = -Math.cos(a) * lung;
      ctx.strokeStyle = m.neb ? 'rgba(220,220,225,.75)' : 'rgba(150,200,230,.6)';
      ctx.lineWidth = m.kn > 33 ? 2 : 1;
      ctx.beginPath();
      ctx.moveTo(px, py);
      ctx.lineTo(px + dx, py + dy);
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(px, py, 1.6, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(150,200,230,.7)';
      ctx.fill();
    });
  }

  function freccia(px, py, rotta, lung, colore) {
    var a = rotta * Math.PI / 180;
    ctx.strokeStyle = colore;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(px, py);
    ctx.lineTo(px + Math.sin(a) * lung, py - Math.cos(a) * lung);
    ctx.stroke();
  }

  function navi() {
    if (!mostra('navi')) { return; }
    mondo.navi.forEach(function (n) {
      var scorta = n.ruolo === 'scorta';
      if (scorta && !mostra('scorte')) { return; }
      var px = x(n.lon), py = y(n.lat);
      ctx.fillStyle = scorta ? '#8fd0a0'
        : (n.chiave.indexOf('petroliera') === 0 ? '#e0b060'
          : (n.chiave === 'qship' ? '#d06a60' : '#9fb4c8'));
      ctx.beginPath();
      ctx.arc(px, py, n.grt > 9000 ? 3.4 : 2.4, 0, Math.PI * 2);
      ctx.fill();
      if (n.ferita) {
        ctx.strokeStyle = 'rgba(200,80,70,.9)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(px, py, 5.5, 0, Math.PI * 2);
        ctx.stroke();
      }
      freccia(px, py, n.rotta, 7, 'rgba(159,180,200,.45)');
    });
  }

  function convogli() {
    if (!mostra('convogli')) { return; }
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    mondo.convogli.forEach(function (c) {
      var px = x(c.lon), py = y(c.lat);
      var lato = Math.max(7, Math.min(16, 5 + c.merci * 0.35));
      ctx.strokeStyle = '#e4c36a';
      ctx.lineWidth = 1.5;
      ctx.strokeRect(px - lato / 2, py - lato / 2, lato, lato);
      ctx.fillStyle = 'rgba(228,195,106,.16)';
      ctx.fillRect(px - lato / 2, py - lato / 2, lato, lato);
      ctx.fillStyle = 'rgba(228,195,106,.95)';
      ctx.fillText(c.nome + ' (' + c.merci + '+' + c.scorte + ')', px, py - lato / 2 - 2);
      freccia(px, py, c.rotta, 12, 'rgba(228,195,106,.7)');
    });
  }

  function battelli() {
    if (!mostra('battelli')) { return; }
    ctx.font = 'bold 11px monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    mondo.battelli.forEach(function (b) {
      var px = x(b.lon), py = y(b.lat);
      var inMare = b.stato === 'mare';
      ctx.fillStyle = b.incontro ? '#e2554a' : (inMare ? '#7ee08a' : '#7b8794');
      ctx.beginPath();
      ctx.moveTo(px, py - 6);
      ctx.lineTo(px + 5, py + 5);
      ctx.lineTo(px - 5, py + 5);
      ctx.closePath();
      ctx.fill();
      if (b.incontro) {
        ctx.strokeStyle = 'rgba(226,85,74,.8)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.arc(px, py, 11, 0, Math.PI * 2);
        ctx.stroke();
      }
      ctx.fillStyle = b.incontro ? '#f0a49c' : '#cfe8d4';
      ctx.fillText(b.numero, px, py + 7);
      if (inMare) { freccia(px, py, b.rotta, 14, 'rgba(126,224,138,.7)'); }
    });
  }

  function evidenzia() {
    if (!scelta) { return; }
    ctx.strokeStyle = '#ffd479';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(x(scelta.lon), y(scelta.lat), 16, 0, Math.PI * 2);
    ctx.stroke();
  }

  function disegna() {
    limitaVista();
    fondo();
    coste();
    griglia();
    meteo();
    porti();
    navi();
    convogli();
    battelli();
    evidenzia();
  }

  // --- scheda ----------------------------------------------------------------

  function riga(et, v) {
    return '<div class="riga"><span class="etichetta">' + et + '</span><span class="valore piccolo">' + v + '</span></div>';
  }

  function scheda(html) {
    var d = document.getElementById('scheda-carta');
    d.innerHTML = html;
    d.hidden = false;
  }

  function schedaMeteo(lat, lon) {
    var u = cfg.url_meteo + '?lat=' + lat.toFixed(4) + '&lon=' + lon.toFixed(4);
    fetch(u, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { scheda('<b>Il tempo non si legge qui.</b>'); return; }
        var m = d.meteo;
        scheda('<h3>Il tempo in ' + m.quadrat + '</h3>'
          + riga('posizione', m.lat.toFixed(3) + ' / ' + m.lon.toFixed(3))
          + riga('ora di bordo', m.ora)
          + riga('vento', m.wind_kn.toFixed(1) + ' kn da ' + Math.round(m.wind_dir) + '° (forza ' + m.beaufort + ')')
          + riga('mare', 'stato ' + m.sea_state + ', onda ' + m.wave_m.toFixed(1) + ' m')
          + riga('visibilità', m.visibility_nm.toFixed(1) + ' nm' + (m.fog ? ' — nebbia' : ''))
          + riga('cielo', Math.round(m.cloud * 100) + '% coperto, ' + (m.giorno ? 'giorno' : 'notte') + ' (luce ' + m.luce + ')')
          + riga('pressione', Math.round(m.pressure_hpa) + ' hPa')
          + riga('acqua / aria', m.sea_c.toFixed(1) + '° / ' + m.air_c.toFixed(1) + '°')
          + (m.forzato ? '<p class="aiuto" style="color:var(--ambra)">Qui il tempo è <b>forzato</b> dall\'amministrazione.</p>' : ''));
      })
      .catch(function () { scheda('<b>Il tempo non si legge: richiesta fallita.</b>'); });
  }

  function vicino(px, py) {
    var best = null, bestD = 18;
    function prova(o, tipo) {
      var d = Math.hypot(x(o.lon) - px, y(o.lat) - py);
      if (d < bestD) { bestD = d; best = { o: o, tipo: tipo }; }
    }
    if (mostra('battelli')) { mondo.battelli.forEach(function (b) { prova(b, 'battello'); }); }
    if (mostra('convogli')) { mondo.convogli.forEach(function (c) { prova(c, 'convoglio'); }); }
    if (mostra('navi')) { mondo.navi.forEach(function (n) { prova(n, 'nave'); }); }
    return best;
  }

  // --- navigare: rotellina, trascinamento, tastiera --------------------------

  function tela2canvas(e) {
    var r = tela.getBoundingClientRect();
    return [(e.clientX - r.left) * (W / r.width), (e.clientY - r.top) * (H / r.height)];
  }

  tela.addEventListener('wheel', function (e) {
    e.preventDefault();
    var p = tela2canvas(e);
    ingrandisci(e.deltaY < 0 ? 1.22 : 1 / 1.22, p[0], p[1]);
  }, { passive: false });

  var trascino = null;
  tela.addEventListener('pointerdown', function (e) {
    trascino = { x: e.clientX, y: e.clientY, cx: vista.cx, cy: vista.cy, mosso: false };
    tela.setPointerCapture(e.pointerId);
  });
  tela.addEventListener('pointermove', function (e) {
    if (trascino === null) { return; }
    var r = tela.getBoundingClientRect();
    var dx = (e.clientX - trascino.x) * (W / r.width);
    var dy = (e.clientY - trascino.y) * (H / r.height);
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) { trascino.mosso = true; }
    vista.cx = trascino.cx - dx / W * ampiezzaLon();
    vista.cy = trascino.cy + dy / H * ampiezzaLat();
    disegna();
  });
  function finisciTrascino(e) {
    if (trascino !== null) {
      try { tela.releasePointerCapture(e.pointerId); } catch (x) { /* gia' rilasciato */ }
    }
    var mosso = trascino !== null && trascino.mosso;
    trascino = null;
    return mosso;
  }
  tela.addEventListener('pointerup', finisciTrascino);
  tela.addEventListener('pointercancel', finisciTrascino);

  document.addEventListener('keydown', function (e) {
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) { return; }
    var passo = 0.18;
    if (e.key === '+' || e.key === '=') { ingrandisci(1.25, W / 2, H / 2); }
    else if (e.key === '-' || e.key === '_') { ingrandisci(1 / 1.25, W / 2, H / 2); }
    else if (e.key === 'ArrowLeft')  { vista.cx -= ampiezzaLon() * passo; disegna(); }
    else if (e.key === 'ArrowRight') { vista.cx += ampiezzaLon() * passo; disegna(); }
    else if (e.key === 'ArrowUp')    { vista.cy += ampiezzaLat() * passo; disegna(); }
    else if (e.key === 'ArrowDown')  { vista.cy -= ampiezzaLat() * passo; disegna(); }
    else { return; }
    e.preventDefault();
  });

  function tuttoIlTeatro() {
    vista.z = 1;
    vista.cx = (LON_W + LON_E) / 2;
    vista.cy = (LAT_N + LAT_S) / 2;
    disegna();
    aggiornaZoom();
  }

  /** Inquadra un punto: serve a "vai a" e al doppio clic. */
  function inquadra(lat, lon, zoom) {
    vista.cx = lon;
    vista.cy = lat;
    if (zoom) { vista.z = zoom; }
    disegna();
    aggiornaZoom();
  }

  tela.addEventListener('dblclick', function (e) {
    var p = tela2canvas(e);
    inquadra(latDa(p[1]), lonDa(p[0]), Math.min(ZOOM_MAX, vista.z * 2.2));
  });

  tela.addEventListener('click', function (e) {
    // Un trascinamento finisce con un clic: se la carta si e' mossa, quel clic
    // non e' una scelta.
    if (trascino !== null || e.detail > 1) { return; }
    var p = tela2canvas(e);
    var px = p[0], py = p[1];
    var t = vicino(px, py);

    if (t === null) {
      scelta = { lat: latDa(py), lon: lonDa(px) };
      disegna();
      schedaMeteo(scelta.lat, scelta.lon);
      return;
    }

    scelta = { lat: t.o.lat, lon: t.o.lon };
    disegna();

    if (t.tipo === 'battello') {
      var b = t.o;
      scheda('<h3>' + b.numero + ' — ' + b.comandante + '</h3>'
        + riga('account', b.account)
        + riga('tipo', b.tipo + ' · ' + b.flottiglia)
        + riga('stato', b.stato + (b.incontro ? ' — IN COMBATTIMENTO' : '') + ', ' + b.modo)
        + riga('posizione', b.quadrat + '  (' + b.lat.toFixed(3) + ' / ' + b.lon.toFixed(3) + ')')
        + riga('rotta e velocità', Math.round(b.rotta) + '° a ' + b.nodi.toFixed(1) + ' kn')
        + riga('quota', b.quota.toFixed(0) + ' m')
        + riga('nafta', b.nafta.toFixed(1) + ' t')
        + riga('batteria', b.batteria.toFixed(0) + '%')
        + riga('scafo', b.scafo.toFixed(0) + '%'));
    } else if (t.tipo === 'convoglio') {
      var c = t.o;
      scheda('<h3>Convoglio ' + c.nome + '</h3>'
        + riga('rotta', c.via)
        + riga('consistenza', c.merci + ' mercantili, ' + c.scorte + ' di scorta')
        + riga('già affondate', String(c.affondate))
        + riga('posizione', c.quadrat + '  (' + c.lat.toFixed(3) + ' / ' + c.lon.toFixed(3) + ')')
        + riga('rotta e velocità', Math.round(c.rotta) + '° a ' + c.nodi.toFixed(1) + ' kn'));
    } else {
      var n = t.o;
      scheda('<h3>' + n.nome + '</h3>'
        + riga('classe', n.classe)
        + riga('bandiera', n.bandiera)
        + riga('ruolo', n.ruolo)
        + riga('stazza', n.grt.toLocaleString('it-IT') + ' GRT')
        + riga('integrità', n.integrita.toFixed(0) + '%' + (n.ferita ? ' — ritardataria' : ''))
        + riga('posizione', n.lat.toFixed(3) + ' / ' + n.lon.toFixed(3))
        + riga('rotta e velocità', Math.round(n.rotta) + '° a ' + n.nodi.toFixed(1) + ' kn'));
    }
  });

  // --- dati ------------------------------------------------------------------

  function stato(t) {
    var s = document.querySelector('[data-stato]');
    if (s) { s.textContent = t; }
  }

  // Una carta che non si disegna deve DIRLO. Muta, sembra vuota: e una carta
  // vuota e una carta rotta si somigliano troppo perche' se ne possa
  // rispondere guardandola.
  window.addEventListener('error', function (e) {
    stato('errore: ' + (e && e.message ? e.message : 'sconosciuto'));
  });

  function carica() {
    stato('carico…');
    var u = cfg.url_dati + '?passo=5' + (mostra('meteo') ? '' : '&meteo=0');
    fetch(u, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { stato('la carta non risponde'); return; }
        mondo.navi = d.navi || [];
        mondo.convogli = d.convogli || [];
        mondo.battelli = d.battelli || [];
        mondo.meteo = d.meteo || [];
        mondo.quadro = d.quadro || null;
        stato(d.ora + ' — ' + mondo.navi.length + ' isolate, ' + mondo.convogli.length
          + ' convogli, ' + mondo.battelli.length + ' battelli');
        riempiVaiA();
        disegna();
      })
      .catch(function (e) { stato('la carta non risponde: ' + (e && e.message ? e.message : '?')); });
  }

  function riempiVaiA() {
    var s = document.querySelector('[data-vai]');
    if (!s) { return; }
    var html = '<option value="">vai a…</option>';
    mondo.battelli.forEach(function (b) {
      html += '<option value="' + b.lat + ',' + b.lon + '">' + b.numero + ' — ' + b.comandante + '</option>';
    });
    mondo.convogli.forEach(function (c) {
      html += '<option value="' + c.lat + ',' + c.lon + '">convoglio ' + c.nome + '</option>';
    });
    s.innerHTML = html;
  }

  document.querySelectorAll('[data-mostra]').forEach(function (c) {
    c.addEventListener('change', function () {
      // Il meteo costa: se lo si accende e non c'e', lo si va a prendere.
      if (c.getAttribute('data-mostra') === 'meteo' && c.checked && !mondo.meteo.length) {
        carica();
      } else {
        disegna();
      }
    });
  });
  var b = document.querySelector('[data-aggiorna]');
  if (b) { b.addEventListener('click', carica); }

  var bTutto = document.querySelector('[data-tutto]');
  if (bTutto) { bTutto.addEventListener('click', tuttoIlTeatro); }
  var bPiu = document.querySelector('[data-piu]');
  if (bPiu) { bPiu.addEventListener('click', function () { ingrandisci(1.3, W / 2, H / 2); }); }
  var bMeno = document.querySelector('[data-meno]');
  if (bMeno) { bMeno.addEventListener('click', function () { ingrandisci(1 / 1.3, W / 2, H / 2); }); }

  // "Vai a": si inquadra un battello o un convoglio senza cercarlo a occhio.
  var scegli = document.querySelector('[data-vai]');
  if (scegli) {
    scegli.addEventListener('change', function () {
      var v = scegli.value;
      if (v === '') { return; }
      var pezzi = v.split(',');
      inquadra(parseFloat(pezzi[0]), parseFloat(pezzi[1]), Math.max(vista.z, 6));
    });
  }

  aggiornaZoom();
  disegna();
  carica();
})();
