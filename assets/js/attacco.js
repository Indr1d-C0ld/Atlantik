/* Quadro tattico e aggiornamento della stazione d'attacco.
 *
 * Qui il tempo scorre quasi come nella realta': il polling e' fitto (tre
 * secondi) perche' in tre secondi una scorta percorre venti metri e un siluro
 * cinquanta.
 *
 * Dal 29/09/2026 la pagina e' divisa in passi, e questo script tiene legati
 * il quadro, la tavola dei bersagli e il calcolatore:
 *   - scegliere un bersaglio (dalla tavola, o con un clic sul suo segno nel
 *     quadro) ricarica il calcolatore con le stime di quel bersaglio. Prima il
 *     calcolatore restava fermo sui valori del primo bersaglio dell'elenco,
 *     qualunque si scegliesse: si lanciava su una nave con la soluzione di
 *     un'altra;
 *   - le stime della tavola si aggiornano a ogni giro, e con loro il
 *     calcolatore, finche' il comandante non ci mette le mani;
 *   - ogni segno del quadro porta il suo numero, lo stesso della tavola, e
 *     non sparisce quando il quadro si affolla.
 */
(function () {
  'use strict';

  var tela = document.getElementById('plotta');
  if (!tela) { return; }
  var dati = JSON.parse(tela.getAttribute('data-plotta'));
  var ctx = tela.getContext('2d');
  var scalaNm = 5;           // raggio del quadro, in miglia
  var posizioni = [];        // dove si e' disegnato ogni segno, per il clic

  function stile(n) {
    return getComputedStyle(document.documentElement).getPropertyValue(n).trim() || '#888';
  }

  function xy(cx, cy, r, latB, lonB, rottaB, lat, lon) {
    // Posizione relativa in miglia, ruotata in modo che la prua sia in alto.
    var dy = (lat - latB) * 60;
    var dx = (lon - lonB) * 60 * Math.cos(latB * Math.PI / 180);
    var a = -rottaB * Math.PI / 180;
    var rx = dx * Math.cos(a) - dy * Math.sin(a);
    var ry = dx * Math.sin(a) + dy * Math.cos(a);
    return { x: cx + rx / scalaNm * r, y: cy - ry / scalaNm * r };
  }

  // --- il bersaglio scelto -------------------------------------------------

  function radioScelta() { return document.querySelector('input[name="bersaglio"]:checked'); }
  function sceltoId() { var r = radioScelta(); return r ? parseInt(r.value, 10) : null; }

  var campiCalc = { aob: 'c-aob', metri: 'c-dist', vel: 'c-vel' };
  var aMano = false;          // il comandante ha corretto i valori: non si toccano piu'
  Object.keys(campiCalc).forEach(function (k) {
    var el = document.getElementById(campiCalc[k]);
    if (el) { el.addEventListener('input', function () { aMano = true; }); }
  });

  /** Porta nel calcolatore le stime del bersaglio scelto. */
  function riempiCalcolatore(forza) {
    var r = radioScelta();
    if (!r || (aMano && !forza)) { return; }
    var aob = document.getElementById('c-aob'), dist = document.getElementById('c-dist'), vel = document.getElementById('c-vel');
    if (aob) { aob.value = r.getAttribute('data-aob'); }
    if (dist) { dist.value = r.getAttribute('data-metri'); }
    if (vel) { vel.value = r.getAttribute('data-vel'); }
  }

  function scriviScelta() {
    var r = radioScelta();
    var nome = r ? r.getAttribute('data-breve') : null;
    var per = document.getElementById('soluzione-per');
    if (per) { per.textContent = nome ? 'Valori per ' + nome + '.' : 'Nessun bersaglio scelto.'; }
    var rie = document.getElementById('riepilogo-lancio');
    if (rie) { rie.textContent = nome ? 'Su ' + nome + ', con i valori del passo 3.' : ''; }
    var can = document.getElementById('g-bersaglio');
    if (can && r) { can.value = r.value; }
    document.querySelectorAll('#tavola-bersagli tr[data-unita]').forEach(function (tr) {
      tr.classList.toggle('scelta', r !== null && tr.getAttribute('data-unita') === r.value);
    });
  }

  function scegli(id) {
    var r = document.querySelector('input[name="bersaglio"][value="' + id + '"]');
    if (!r) { return false; }
    r.checked = true;
    aMano = false;
    riempiCalcolatore(true);
    scriviScelta();
    disegna();
    return true;
  }

  document.querySelectorAll('input[name="bersaglio"]').forEach(function (r) {
    r.addEventListener('change', function () { scegli(r.value); });
  });
  // Un clic su tutta la riga, non solo sul pallino: il pallino e' piccolo.
  document.querySelectorAll('#tavola-bersagli tr[data-unita]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('input, a, button')) { return; }
      scegli(tr.getAttribute('data-unita'));
    });
  });

  // --- il quadro --------------------------------------------------------------

  function disegna() {
    var w = tela.width, h = tela.height;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 18;
    var fondo = stile('--acciaio-0'), linea = stile('--acciaio-4'),
        verde = stile('--verde-fosf'), rosso = stile('--rosso'),
        ambra = stile('--ambra'), testo = stile('--testo-3'), chiaro = stile('--testo');

    ctx.fillStyle = fondo; ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = linea; ctx.lineWidth = 1;
    ctx.font = '10px ui-monospace, monospace'; ctx.fillStyle = testo; ctx.textAlign = 'center';
    [1, 2, 4].forEach(function (nm) {
      if (nm > scalaNm) { return; }
      var rr = r * nm / scalaNm;
      ctx.beginPath(); ctx.arc(cx, cy, rr, 0, 6.3); ctx.stroke();
      ctx.fillText(nm + ' nm', cx, cy - rr - 4);
    });
    for (var g = 0; g < 360; g += 45) {
      var a = (g - 90) * Math.PI / 180;
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
      ctx.globalAlpha = 0.3; ctx.stroke(); ctx.globalAlpha = 1;
    }

    var b = dati.battello;
    var scelto = sceltoId();
    posizioni = [];

    // Le unita'. Il numero accanto al segno e' quello della tavola: resta
    // sempre, anche con il quadro affollato. Prima si toglievano TUTTE le
    // etichette oltre le quattordici unita', e un convoglio intero diventava
    // una nuvola di triangoli senza nome.
    var visibili = [];
    (dati.unita || []).forEach(function (u) {
      if (u.stato === 'affondata') { return; }
      var p = xy(cx, cy, r, b.lat, b.lon, b.rotta, u.lat, u.lon);
      if (p.x < -20 || p.x > w + 20 || p.y < -20 || p.y > h + 20) { return; }
      visibili.push({ u: u, p: p });
    });

    visibili.forEach(function (v) {
      var u = v.u, p = v.p;
      var colore = u.ruolo === 'scorta'
        ? (u.contatto > 0.4 ? rosso : (u.contatto > 0.1 ? ambra : testo))
        : (u.ruolo === 'ignoto' ? testo : verde);

      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.strokeStyle = colore; ctx.fillStyle = colore; ctx.lineWidth = 1.6;

      if (u.ruolo === 'ignoto') {
        // Un contatto che non si e' visto non ha prua ne' stazza: sul tavolo
        // e' un cerchietto sul rilevamento, e non finge di essere altro.
        ctx.beginPath(); ctx.arc(0, 0, 4, 0, 6.3); ctx.stroke();
      } else {
        ctx.rotate((u.rotta - b.rotta) * Math.PI / 180);
        ctx.beginPath();
        if (u.ruolo === 'scorta') {
          ctx.moveTo(0, -7); ctx.lineTo(4, 5); ctx.lineTo(-4, 5); ctx.closePath(); ctx.stroke();
        } else {
          var lung = Math.max(5, Math.min(11, 4 + (u.grt || 3000) / 1400));
          ctx.moveTo(0, -lung); ctx.lineTo(3, lung * 0.7); ctx.lineTo(-3, lung * 0.7); ctx.closePath();
          if (u.stato === 'danneggiata' || u.stato === 'affonda') { ctx.stroke(); } else { ctx.fill(); }
        }
      }
      ctx.restore();

      if (u.id === scelto) {
        ctx.strokeStyle = chiaro; ctx.lineWidth = 1.2; ctx.setLineDash([3, 3]);
        ctx.beginPath(); ctx.arc(p.x, p.y, 13, 0, 6.3); ctx.stroke(); ctx.setLineDash([]);
      }

      // Il numero sempre; il nome breve solo per il bersaglio scelto, o quando
      // c'e' posto. Venti frasi sovrapposte non sono informazione.
      ctx.fillStyle = u.id === scelto ? chiaro : colore; ctx.textAlign = 'left';
      ctx.font = (u.id === scelto ? '700 ' : '') + '10px ui-monospace, monospace';
      var etichetta = String(u.numero || '');
      if (u.id === scelto || (scalaNm <= 5 && visibili.length <= 12)) {
        var breve = (u.breve || '').replace(/[«»]/g, '');
        if (breve && breve !== etichetta) { etichetta += ' ' + breve.slice(0, 16); }
      }
      ctx.fillText(etichetta, p.x + 9, p.y + 3);
      posizioni.push({ id: u.id, x: p.x, y: p.y });
    });

    // I siluri in corsa.
    (dati.siluri || []).forEach(function (s) {
      var p = xy(cx, cy, r, b.lat, b.lon, b.rotta, s.lat, s.lon);
      ctx.strokeStyle = ambra; ctx.lineWidth = 2;
      var a = (s.rotta - b.rotta - 90) * Math.PI / 180;
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      ctx.lineTo(p.x + Math.cos(a) * 9, p.y + Math.sin(a) * 9);
      ctx.stroke();
    });

    // Noi, al centro, prua in alto.
    ctx.fillStyle = chiaro;
    ctx.beginPath(); ctx.moveTo(cx, cy - 8); ctx.lineTo(cx + 4, cy + 5); ctx.lineTo(cx, cy + 3);
    ctx.lineTo(cx - 4, cy + 5); ctx.closePath(); ctx.fill();
  }

  function dimensiona() {
    var rect = tela.parentElement.getBoundingClientRect();
    tela.width = Math.max(280, Math.floor(rect.width) - 2);
    tela.height = Math.max(280, Math.min(520, Math.floor(window.innerHeight * 0.5)));
    disegna();
  }

  tela.addEventListener('wheel', function (e) {
    e.preventDefault();
    scalaNm = Math.max(0.5, Math.min(12, scalaNm * (e.deltaY < 0 ? 0.8 : 1.25)));
    disegna();
  }, { passive: false });

  // Un clic sul segno sceglie il bersaglio: il piu' vicino entro una ventina
  // di pixel, altrimenti niente.
  tela.addEventListener('click', function (e) {
    var rect = tela.getBoundingClientRect();
    var x = (e.clientX - rect.left) * (tela.width / rect.width);
    var y = (e.clientY - rect.top) * (tela.height / rect.height);
    var meglio = null, dMin = 22;
    posizioni.forEach(function (p) {
      var d = Math.hypot(p.x - x, p.y - y);
      if (d < dMin) { dMin = d; meglio = p; }
    });
    if (meglio && scegli(meglio.id)) {
      var tr = document.querySelector('#tavola-bersagli tr[data-unita="' + meglio.id + '"]');
      if (tr) { tr.classList.add('lampo'); setTimeout(function () { tr.classList.remove('lampo'); }, 900); }
    }
  });

  // --- aggiornamento ------------------------------------------------------------

  function scrivi(nome, valore) {
    document.querySelectorAll('[data-campo="' + nome + '"]').forEach(function (el) {
      if (el.textContent !== valore) { el.textContent = valore; }
    });
  }
  // Il punto delle migliaia a mano: toLocaleString('it-IT') non raggruppa i
  // numeri di quattro cifre («1019»), e il server scrive «1.019».
  function migliaia(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function tre(g) { return ('00' + (Math.round(g) % 360)).slice(-3); }

  /** Le stime nuove nella tavola e nei dati del pallino di ogni riga. */
  function aggiornaTavola(unita) {
    var scelto = sceltoId(), cambiato = false;
    (unita || []).forEach(function (u) {
      var tr = document.querySelector('#tavola-bersagli tr[data-unita="' + u.id + '"]');
      if (!tr) { return; }
      var c = function (k, v) { var td = tr.querySelector('[data-c="' + k + '"]'); if (td && td.textContent !== v) { td.textContent = v; } };
      c('ril', tre(u.rilevamento) + '°');
      c('metri', '~' + migliaia(u.metri) + ' m');
      c('aob', u.aob + '° ' + u.aob_lato);
      c('vel', u.velocita.toFixed(1).replace('.', ',') + ' kn');
      var r = tr.querySelector('input[name="bersaglio"]');
      if (r) {
        var vel = u.velocita.toFixed(1);
        if (r.getAttribute('data-metri') !== String(u.metri) || r.getAttribute('data-aob') !== String(u.aob)
            || r.getAttribute('data-vel') !== vel) {
          r.setAttribute('data-aob', String(u.aob));
          r.setAttribute('data-metri', String(u.metri));
          r.setAttribute('data-vel', vel);
          if (u.id === scelto) { cambiato = true; }
        }
      }
    });
    if (cambiato) { riempiCalcolatore(false); }
  }

  // La finestra di tempo reale scorre al secondo; il giro la riallinea.
  var finestra = document.getElementById('finestra-attacco');
  var restano = finestra ? parseInt(finestra.getAttribute('data-finestra'), 10) : 0;
  var totale = finestra ? Math.max(1, parseInt(finestra.getAttribute('data-finestra-tot'), 10)) : 1;
  function mostraFinestra() {
    if (!finestra) { return; }
    // "m" da sola, in una pagina dove ogni numero e' in metri, si legge
    // come metri. Sotto il minuto restano solo i secondi.
    var m = Math.floor(restano / 60), sec = restano % 60;
    scrivi('finestra', m > 0 ? m + ' min ' + ('0' + sec).slice(-2) + ' s' : sec + ' s');
    var barra = finestra.querySelector('[data-campo="finestra-barra"]');
    if (barra) { barra.style.width = Math.max(0, Math.min(100, 100 * restano / totale)).toFixed(1) + '%'; }
    finestra.classList.toggle('finestra-attacco--poca', restano < 300);
    var mis = finestra.querySelector('.misuratore');
    if (mis) {
      mis.classList.toggle('allarme', restano < 300);
      mis.classList.toggle('attenzione', restano >= 300 && restano < 600);
    }
  }
  setInterval(function () { if (restano > 0) { restano--; mostraFinestra(); } }, 1000);

  function giro() {
    fetch(document.body.getAttribute('data-incontro-url') || 'api/incontro',
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s || !s.ok) { return; }
        if (!s.incontro) { window.location.reload(); return; }

        dati.battello = { lat: s.battello.lat, lon: s.battello.lon, rotta: s.battello.rotta };
        dati.unita = s.unita;
        dati.siluri = s.siluri;
        aggiornaTavola(s.unita);
        disegna();

        scrivi('ora', s.incontro.ora);
        scrivi('quota', Math.round(s.battello.quota) + ' m');
        scrivi('velocita', s.battello.velocita.toFixed(1).replace('.', ','));
        scrivi('rotta', tre(s.battello.rotta));
        scrivi('batteria', String(Math.round(s.battello.batteria)));
        scrivi('aria', String(Math.round(s.battello.aria)));
        scrivi('stress', String(Math.round(s.battello.stress)));

        restano = s.incontro.finestra_s;
        mostraFinestra();

        var c = document.getElementById('cronaca-viva');
        if (c && s.cronaca && s.cronaca.length) {
          c.innerHTML = s.cronaca.map(function (e) {
            return '<li><span class="ora">' + e.ora + '</span><span class="testo">' +
              e.testo.replace(/[<>&]/g, '') + '</span></li>';
          }).join('');
        }
      })
      .catch(function () { /* si riprova al giro dopo */ });
  }

  window.addEventListener('resize', dimensiona);
  dimensiona();
  scriviScelta();
  setInterval(giro, 3000);
})();
