/* Rosa dei rilevamenti dell'idrofono.
 *
 * Non e' un radar: non ci sono punti sullo schermo, ci sono DIREZIONI. Ogni
 * raggio e' un rilevamento; la sua lunghezza dice quanto e' netto il contatto,
 * non quanto e' lontano — perche' la distanza, all'idrofono, non si misura.
 */
(function () {
  'use strict';

  var tela = document.getElementById('rosa-idrofono');
  if (!tela) { return; }
  var dati = JSON.parse(tela.getAttribute('data-rosa'));
  var ctx = tela.getContext('2d');

  function stile(n) {
    return getComputedStyle(document.documentElement).getPropertyValue(n).trim() || '#888';
  }

  function disegna() {
    var w = tela.width, h = tela.height;
    var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 26;
    var fondo = stile('--acciaio-0'), linea = stile('--acciaio-4'),
        acc = stile('--accento'), verde = stile('--verde-fosf'), testo = stile('--testo-3');

    ctx.fillStyle = fondo;
    ctx.fillRect(0, 0, w, h);

    // Cerchi e raggi della rosa.
    ctx.strokeStyle = linea; ctx.lineWidth = 1;
    [0.33, 0.66, 1].forEach(function (f) {
      ctx.beginPath(); ctx.arc(cx, cy, r * f, 0, 6.3); ctx.stroke();
    });
    ctx.font = '10px ui-monospace, monospace'; ctx.fillStyle = testo; ctx.textAlign = 'center';
    for (var g = 0; g < 360; g += 30) {
      var a = (g - 90) * Math.PI / 180;
      ctx.beginPath();
      ctx.moveTo(cx + Math.cos(a) * r * 0.9, cy + Math.sin(a) * r * 0.9);
      ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
      ctx.stroke();
      ctx.fillText(('00' + g).slice(-3), cx + Math.cos(a) * (r + 13), cy + Math.sin(a) * (r + 13) + 3);
    }

    // La prua del battello.
    var pa = (dati.prua - 90) * Math.PI / 180;
    ctx.strokeStyle = testo; ctx.setLineDash([3, 3]);
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(pa) * r, cy + Math.sin(pa) * r); ctx.stroke();
    ctx.setLineDash([]);

    // I contatti.
    (dati.contatti || []).forEach(function (c) {
      var a = (c.ril - 90) * Math.PI / 180;
      var lung = r * (0.35 + 0.65 * Math.min(1, c.certezza));
      ctx.strokeStyle = c.perso ? linea : (c.sensore === 'idrofono' ? verde : acc);
      ctx.globalAlpha = c.perso ? 0.45 : 1;
      ctx.lineWidth = c.perso ? 1.5 : 2.5;
      ctx.beginPath();
      ctx.moveTo(cx + Math.cos(a) * r * 0.12, cy + Math.sin(a) * r * 0.12);
      ctx.lineTo(cx + Math.cos(a) * lung, cy + Math.sin(a) * lung);
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(cx + Math.cos(a) * lung, cy + Math.sin(a) * lung, c.perso ? 2 : 3.5, 0, 6.3);
      ctx.fillStyle = ctx.strokeStyle; ctx.fill();
      ctx.globalAlpha = 1;
    });

    // Al centro, il battello.
    ctx.fillStyle = stile('--testo');
    ctx.beginPath(); ctx.arc(cx, cy, 3, 0, 6.3); ctx.fill();
  }

  function dimensiona() {
    var rect = tela.parentElement.getBoundingClientRect();
    var lato = Math.max(260, Math.min(Math.floor(rect.width) - 2, 420));
    tela.width = lato; tela.height = lato;
    disegna();
  }
  window.addEventListener('resize', dimensiona);
  dimensiona();
})();
