/* Quadro tattico e aggiornamento della stazione d'attacco.
 *
 * Qui il tempo scorre quasi come nella realta': il polling e' fitto (tre
 * secondi) perche' in tre secondi una scorta percorre venti metri e un siluro
 * cinquanta.
 */
(function () {
  'use strict';

  var tela = document.getElementById('plotta');
  if (!tela) { return; }
  var dati = JSON.parse(tela.getAttribute('data-plotta'));
  var ctx = tela.getContext('2d');
  var scalaNm = 5;           // raggio del quadro, in miglia

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

  function disegna() {
    var w = tela.width, h = tela.height;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 18;
    var fondo = stile('--acciaio-0'), linea = stile('--acciaio-4'),
        verde = stile('--verde-fosf'), rosso = stile('--rosso'),
        ambra = stile('--ambra'), testo = stile('--testo-3');

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

    // Le unita'.
    (dati.unita || []).forEach(function (u) {
      if (u.stato === 'affondata') { return; }
      var p = xy(cx, cy, r, b.lat, b.lon, b.rotta, u.lat, u.lon);
      if (p.x < -20 || p.x > w + 20 || p.y < -20 || p.y > h + 20) { return; }

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

      // L'etichetta e' quella breve, e sparisce quando il quadro si affolla:
      // venti frasi sovrapposte non sono informazione, sono rumore.
      if (scalaNm <= 5 && (dati.unita || []).length <= 14) {
        ctx.fillStyle = colore; ctx.textAlign = 'left'; ctx.font = '9px ui-monospace, monospace';
        ctx.fillText((u.breve || u.nome || '').slice(0, 16), p.x + 8, p.y + 3);
      }
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
    ctx.fillStyle = stile('--testo');
    ctx.beginPath(); ctx.moveTo(cx, cy - 8); ctx.lineTo(cx + 4, cy + 5); ctx.lineTo(cx, cy + 3);
    ctx.lineTo(cx - 4, cy + 5); ctx.closePath(); ctx.fill();
  }

  function dimensiona() {
    var rect = tela.parentElement.getBoundingClientRect();
    tela.width = Math.max(300, Math.floor(rect.width) - 2);
    tela.height = Math.max(300, Math.min(560, Math.floor(window.innerHeight * 0.55)));
    disegna();
  }

  tela.addEventListener('wheel', function (e) {
    e.preventDefault();
    scalaNm = Math.max(0.5, Math.min(12, scalaNm * (e.deltaY < 0 ? 0.8 : 1.25)));
    disegna();
  }, { passive: false });

  function scrivi(nome, valore) {
    document.querySelectorAll('[data-campo="' + nome + '"]').forEach(function (el) {
      if (el.textContent !== valore) { el.textContent = valore; }
    });
  }

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
        disegna();

        scrivi('ora', s.incontro.ora);
        scrivi('quota', Math.round(s.battello.quota) + ' m');
        scrivi('velocita', s.battello.velocita.toFixed(1).replace('.', ','));
        scrivi('rotta', ('00' + Math.round(s.battello.rotta)).slice(-3));
        scrivi('batteria', Math.round(s.battello.batteria));
        scrivi('aria', Math.round(s.battello.aria));
        scrivi('stress', Math.round(s.battello.stress));

        // "m" da sola, in una pagina dove ogni numero e' in metri, si legge
        // come metri. Sotto il minuto restano solo i secondi.
        var m = Math.floor(s.incontro.finestra_s / 60), sec = s.incontro.finestra_s % 60;
        scrivi('finestra', m > 0
          ? m + ' min ' + ('0' + sec).slice(-2) + ' s'
          : sec + ' s');

        var c = document.getElementById('cronaca-viva');
        if (c && s.cronaca) {
          c.innerHTML = s.cronaca.map(function (e) {
            return '<li><span class="ora">' + e.ora + '</span><span class="testo">' +
              e.testo.replace(/[<>]/g, '') + '</span></li>';
          }).join('');
        }
      })
      .catch(function () { /* si riprova al giro dopo */ });
  }

  window.addEventListener('resize', dimensiona);
  dimensiona();
  setInterval(giro, 3000);
})();
