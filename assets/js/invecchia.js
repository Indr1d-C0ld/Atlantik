/* L'invecchiamento di una fotografia, nel browser.
 *
 * E' la STESSA ricetta di App\Game\Ritratto::invecchia(), rifatta qui perche'
 * si veda subito — mentre si sposta l'inquadratura e mentre si accende e si
 * spegne la casella — invece di scoprirla dopo aver caricato.
 *
 * ATTENZIONE: sono due copie della stessa cosa e vanno cambiate insieme. I
 * numeri stanno scritti in tutte e due, e c'e' una prova che controlla che
 * siano ancora uguali: tests/test_profilo.php, "le due ricette concordano".
 *
 * Le formule di GD non sono indovinate, sono state misurate:
 *   grigio    = 0,299 R + 0,587 G + 0,114 B
 *   contrasto = ((v/255 - 0,5) * f + 0,5) * 255   con f = ((100-arg)/100)^2
 *               — ecco perche' l'argomento POSITIVO abbassa il contrasto
 *   sfocatura = nucleo 3x3 [1 2 1 / 2 4 2 / 1 2 1] diviso 16
 *
 * Quando il browser l'ha applicata, quello che parte e' gia' invecchiato e il
 * modulo lo dice al server (campo gia_invecchiata), che percio' non la rifa'.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------------------
     L'INVECCHIAMENTO, QUI DAVANTI
     ------------------------------------------------------------------------
     Questa e' la STESSA ricetta di App\Game\Ritratto::invecchia(), rifatta
     qui perche' si veda subito, mentre si sposta l'inquadratura e mentre si
     accende e si spegne la casella.

     ATTENZIONE: sono due copie della stessa cosa, e vanno cambiate insieme. I
     numeri (0,51 di luminanza, 0,215 di deviazione standard, la virata +2/+1/-1)
     stanno scritti in tutte e due, e c'e' una prova che controlla che siano
     ancora uguali — tests/test_profilo.php, "le due ricette concordano".

     Le formule di GD non sono indovinate, sono state misurate:
       grigio    = 0,299 R + 0,587 G + 0,114 B
       contrasto = ((v/255 - 0,5) * f + 0,5) * 255   con f = ((100-arg)/100)²
                   — ecco perche' l'argomento POSITIVO abbassa il contrasto
       sfocatura = nucleo 3x3 [1 2 1 / 2 4 2 / 1 2 1] diviso 16
     ------------------------------------------------------------------------ */

  var LUMINANZA_GALLERIA = 0.51;
  var CONTRASTO_GALLERIA = 0.215;

  function misura(d) {
    var somma = 0, n = 0, i;
    for (i = 0; i < d.length; i += 4 * 7) { somma += d[i] / 255; n++; }
    var media = n ? somma / n : 0.5;
    var s2 = 0;
    for (i = 0; i < d.length; i += 4 * 7) { var x = d[i] / 255 - media; s2 += x * x; }
    return [media, Math.sqrt(n ? s2 / n : 0.04)];
  }

  function contrasto(d, arg) {
    var f = Math.pow((100 - arg) / 100, 2), i, c;
    for (i = 0; i < d.length; i += 4) {
      for (c = 0; c < 3; c++) {
        d[i + c] = Math.max(0, Math.min(255, ((d[i + c] / 255 - 0.5) * f + 0.5) * 255));
      }
    }
  }

  function sfoca(d, w, h) {
    var copia = new Uint8ClampedArray(d);
    var k = [1, 2, 1, 2, 4, 2, 1, 2, 1];
    for (var y = 1; y < h - 1; y++) {
      for (var x = 1; x < w - 1; x++) {
        for (var c = 0; c < 3; c++) {
          var s = 0, j = 0;
          for (var dy = -1; dy <= 1; dy++) {
            for (var dx = -1; dx <= 1; dx++, j++) {
              s += copia[((y + dy) * w + (x + dx)) * 4 + c] * k[j];
            }
          }
          d[(y * w + x) * 4 + c] = s / 16;
        }
      }
    }
  }

  function invecchia(ctx, w, h) {
    var img = ctx.getImageData(0, 0, w, h);
    var d = img.data, i, c;

    // 1. Via il colore.
    for (i = 0; i < d.length; i += 4) {
      var g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
      d[i] = d[i + 1] = d[i + 2] = g;
    }

    // 2. Contrasto sceso a quello della galleria, cercato per bisezione.
    var dev = misura(d)[1];
    if (dev > CONTRASTO_GALLERIA) {
      var basso = 0, alto = 48;
      for (i = 0; i < 6; i++) {
        var mezzo = Math.round((basso + alto) / 2);
        var prova = new Uint8ClampedArray(d);
        contrasto(prova, mezzo);
        if (misura(prova)[1] > CONTRASTO_GALLERIA) { basso = mezzo; } else { alto = mezzo; }
      }
      contrasto(d, Math.round((basso + alto) / 2));
    }

    // 3. L'ottica del tempo non era incisa come una di oggi.
    sfoca(d, w, h);

    // 4. Vignettatura e grana, nello stesso giro.
    var cx = w / 2, cy = h / 2, raggio = Math.sqrt(cx * cx + cy * cy);
    for (var y = 0; y < h; y++) {
      for (var x = 0; x < w; x++) {
        var p = (y * w + x) * 4;
        var dd = Math.sqrt((x - cx) * (x - cx) + (y - cy) * (y - cy)) / raggio;
        var v = 1.0 - 0.22 * Math.pow(dd, 2.2);
        var grana = Math.floor(Math.random() * 13) - 6;   // -6..+6, come random_int(-6, 6)
        for (c = 0; c < 3; c++) { d[p + c] = Math.round(d[p + c] * v) + grana; }
      }
    }

    // 5. L'esposizione, misurata DOPO la vignettatura.
    var media = misura(d)[0];
    var spinta = Math.max(-45, Math.min(75, Math.round((LUMINANZA_GALLERIA - media) * 255 * 0.85)));
    for (i = 0; i < d.length; i += 4) { for (c = 0; c < 3; c++) { d[i + c] += spinta; } }

    // 6. Un soffio di calore, per ultimo.
    for (i = 0; i < d.length; i += 4) { d[i] += 2; d[i + 1] += 1; d[i + 2] -= 1; }

    ctx.putImageData(img, 0, 0);
  }

  window.AtlantikInvecchia = invecchia;
})();
