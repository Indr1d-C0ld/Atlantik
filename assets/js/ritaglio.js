/* Ritaglio della fotografia prima di caricarla.
 *
 * Il server sa fare un ritaglio quadrato centrato, alzato del quindici per
 * cento perche' in un ritratto la testa sta in alto. Indovina bene quasi
 * sempre, ma e' pur sempre un indovinare: su una fotografia di gruppo, o su una
 * inquadrata storta, taglia la persona sbagliata.
 *
 * Qui la si fa scegliere. Si apre la fotografia nel browser, la si mostra in un
 * riquadro quadrato, e la si sposta e ingrandisce finche' la faccia non e' dove
 * deve stare. Al momento di inviare, il riquadro diventa l'immagine caricata:
 * quello che parte e' gia' ritagliato.
 *
 * SENZA JAVASCRIPT non cambia niente: parte il file com'e' e il server fa il
 * suo ritaglio centrato, come ha sempre fatto.
 */
(function () {
  'use strict';

  var campo = document.querySelector('input[type=file][data-ritaglio]');
  if (!campo || typeof HTMLCanvasElement === 'undefined' || !window.DataTransfer) { return; }

  var LATO = 320;                       // la misura della galleria storica
  var modulo = campo.form;
  var immagine = null;
  var scala = 1, minScala = 1, offX = 0, offY = 0;

  var scatola = document.createElement('div');
  scatola.className = 'campo';
  scatola.hidden = true;
  scatola.innerHTML =
    '<label>Inquadratura</label>' +
    '<div class="ritaglio">' +
      '<canvas width="' + LATO + '" height="' + LATO + '"></canvas>' +
    '</div>' +
    '<div class="azioni" style="margin-top:.6rem">' +
      '<label style="text-transform:none;letter-spacing:0;color:var(--testo-2);font-size:.82rem">' +
        'ingrandisci <input type="range" min="100" max="300" value="100" data-zoom style="vertical-align:middle">' +
      '</label>' +
      '<button type="button" class="bottone--fantasma" data-centra>Ricentra</button>' +
    '</div>' +
    '<p class="aiuto">Trascina per spostare, la barra per ingrandire. Parte quello che vedi nel riquadro.</p>';
  campo.parentNode.insertBefore(scatola, campo.nextSibling);

  var tela = scatola.querySelector('canvas');
  var ctx = tela.getContext('2d');
  var zoom = scatola.querySelector('[data-zoom]');

  // La casella "rendila d'epoca", se c'e' nello stesso modulo.
  var casella = modulo.querySelector('input[name=invecchia]');

  /** Il riquadro non deve mai mostrare bordi vuoti: l'immagine lo copre tutto. */
  function limita() {
    var l = immagine.width * scala, h = immagine.height * scala;
    offX = Math.min(0, Math.max(LATO - l, offX));
    offY = Math.min(0, Math.max(LATO - h, offY));
  }

  function disegna() {
    if (!immagine) { return; }
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, LATO, LATO);
    limita();
    ctx.drawImage(immagine, offX, offY, immagine.width * scala, immagine.height * scala);
    if (casella && casella.checked && window.AtlantikInvecchia) {
      window.AtlantikInvecchia(ctx, LATO, LATO);
    }
  }

  function centra(alto) {
    // Il riquadro copre il lato corto; si parte alzati, come fa il server.
    minScala = Math.max(LATO / immagine.width, LATO / immagine.height);
    scala = minScala;
    zoom.value = 100;
    offX = (LATO - immagine.width * scala) / 2;
    offY = alto ? Math.min(0, (LATO - immagine.height * scala) * 0.18) : (LATO - immagine.height * scala) / 2;
    disegna();
  }

  campo.addEventListener('change', function () {
    var f = campo.files && campo.files[0];
    if (!f) { scatola.hidden = true; return; }
    var lettore = new FileReader();
    lettore.onload = function () {
      var im = new Image();
      im.onload = function () {
        immagine = im;
        scatola.hidden = false;
        centra(true);
      };
      im.src = lettore.result;
    };
    lettore.readAsDataURL(f);
  });

  zoom.addEventListener('input', function () {
    if (!immagine) { return; }
    var prima = scala;
    scala = minScala * (parseInt(zoom.value, 10) / 100);
    // Si ingrandisce attorno al centro del riquadro, non attorno all'angolo.
    offX = LATO / 2 - (LATO / 2 - offX) * (scala / prima);
    offY = LATO / 2 - (LATO / 2 - offY) * (scala / prima);
    disegna();
  });

  scatola.querySelector('[data-centra]').addEventListener('click', function () { centra(true); });

  // La casella si vede subito: e' tutto il punto.
  if (casella) { casella.addEventListener('change', disegna); }

  var trascino = false, px = 0, py = 0;
  function giu(e) { trascino = true; px = (e.touches ? e.touches[0] : e).clientX; py = (e.touches ? e.touches[0] : e).clientY; }
  function muovi(e) {
    if (!trascino || !immagine) { return; }
    var p = e.touches ? e.touches[0] : e;
    var r = tela.getBoundingClientRect();
    var k = LATO / r.width;                       // la tela e' disegnata piu' piccola del suo bitmap
    offX += (p.clientX - px) * k;
    offY += (p.clientY - py) * k;
    px = p.clientX; py = p.clientY;
    disegna();
    if (e.cancelable) { e.preventDefault(); }
  }
  function su() { trascino = false; }

  tela.addEventListener('mousedown', giu);
  window.addEventListener('mousemove', muovi);
  window.addEventListener('mouseup', su);
  tela.addEventListener('touchstart', giu, { passive: true });
  tela.addEventListener('touchmove', muovi, { passive: false });
  tela.addEventListener('touchend', su);

  // All'invio, il riquadro prende il posto del file scelto.
  modulo.addEventListener('submit', function (e) {
    if (!immagine || campo.dataset.pronto === '1') { return; }
    e.preventDefault();
    tela.toBlob(function (blob) {
      if (blob) {
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'ritratto.webp', { type: blob.type }));
        campo.files = dt.files;

        // Quello che parte e' gia' esattamente quello che si vede nel riquadro,
        // invecchiatura compresa. Si dice al server di non rifarla, altrimenti
        // la applicherebbe due volte — e due volte si vede.
        if (casella && casella.checked) {
          var segno = document.createElement('input');
          segno.type = 'hidden';
          segno.name = 'gia_invecchiata';
          segno.value = '1';
          modulo.appendChild(segno);
        }
      }
      campo.dataset.pronto = '1';
      modulo.submit();
    }, 'image/webp', 0.92);
  });
})();
