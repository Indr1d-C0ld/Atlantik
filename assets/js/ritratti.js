/* Selettore del ritratto: ricerca su tutti, quaranta per pagina.
 *
 * Il server disegna solo la prima pagina e manda giu' l'elenco intero in forma
 * compatta. Qui si prende in mano la griglia: si cerca su tutti e cinquecento,
 * si sfoglia, e nel documento restano sempre quaranta immagini invece di
 * cinquecento.
 *
 * La scelta NON sta piu' in un radio — un radio sparirebbe cambiando pagina —
 * ma in un campo nascosto, e la scheda selezionata si riconosce dal bordo.
 *
 * Se JavaScript non c'e', resta la prima pagina disegnata dal server, coi suoi
 * radio veri: quaranta volti fra cui scegliere e nessuna funzione persa se non
 * la ricerca.
 */
(function () {
  'use strict';

  var radice = document.getElementById('scelta-ritratti');
  if (!radice) { return; }

  var tutti;
  try { tutti = JSON.parse(radice.getAttribute('data-elenco') || '[]'); } catch (e) { return; }
  if (!tutti.length) { return; }

  var per = parseInt(radice.getAttribute('data-per'), 10) || 40;
  var scelto = radice.getAttribute('data-scelto') || '';
  var griglia = radice.querySelector('[data-griglia]');
  var conteggio = radice.querySelector('[data-conteggio]');
  var pagina = 0;
  var filtrati = tutti;

  // Il testo su cui si cerca si prepara una volta sola: con cinquecento schede
  // rifarlo a ogni tasto si sentirebbe.
  tutti.forEach(function (r) {
    r._c = (r.n || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  });

  // --- la barra di comando ---------------------------------------------------
  var barra = document.createElement('div');
  barra.className = 'campo';
  barra.style.maxWidth = '24rem';
  barra.innerHTML =
    '<label for="filtro-ritratti">Cerca un nome</label>' +
    '<input type="search" id="filtro-ritratti" placeholder="cognome o nome" autocomplete="off">';
  radice.insertBefore(barra, conteggio);
  var campo = barra.querySelector('input');

  var nascosto = document.createElement('input');
  nascosto.type = 'hidden';
  nascosto.name = 'ritratto_key';
  nascosto.value = scelto;
  radice.appendChild(nascosto);

  var piede = document.createElement('div');
  piede.className = 'azioni';
  piede.style.marginTop = '.8rem';
  piede.innerHTML =
    '<button type="button" class="bottone--fantasma" data-prec>← precedenti</button>' +
    '<span data-pagina style="color:var(--testo-3);font-size:.8rem"></span>' +
    '<button type="button" class="bottone--fantasma" data-succ>successivi →</button>';
  radice.appendChild(piede);
  var bPrec = piede.querySelector('[data-prec]');
  var bSucc = piede.querySelector('[data-succ]');
  var etPagina = piede.querySelector('[data-pagina]');

  // --- disegno ---------------------------------------------------------------

  // L'indirizzo delle immagini e' relativo alla radice dell'applicazione: la si
  // ricava da un'immagine gia' disegnata dal server, invece di indovinarla.
  var base = '';
  var campione = griglia.querySelector('img[src]');
  if (campione) {
    var srcCampione = campione.getAttribute('src');
    var taglio = srcCampione.indexOf('img/ritratti/');
    if (taglio > 0) { base = srcCampione.slice(0, taglio); }
  }

  function scheda(r) {
    var l = document.createElement('label');
    l.className = 'scelta-ritratto' + (r.p ? ' preso' : '') + (r.k === nascosto.value ? ' scelto' : '');
    l.title = r.n + (r.p ? ' — lo porta ' + r.p + ', in servizio' : '');

    var img = document.createElement('img');
    img.src = base + r.u;
    img.alt = r.n;
    img.width = 320; img.height = 320;
    img.loading = 'lazy'; img.decoding = 'async';
    l.appendChild(img);

    var b = document.createElement('b');
    b.textContent = r.n;
    l.appendChild(b);

    if (r.a) { var s = document.createElement('small'); s.textContent = r.a; l.appendChild(s); }

    if (r.p) {
      var occ = document.createElement('small');
      occ.style.color = 'var(--ambra)';
      occ.textContent = 'in servizio: ' + r.p;
      l.appendChild(occ);
    } else {
      l.addEventListener('click', function () {
        nascosto.value = r.k;
        griglia.querySelectorAll('.scelta-ritratto.scelto').forEach(function (x) {
          x.classList.remove('scelto');
        });
        l.classList.add('scelto');
        vuoto.classList.remove('scelto');
      });
    }
    return l;
  }

  // La casella "senza ritratto" resta sempre in cima e non entra nel conto.
  var vuoto = document.createElement('label');
  vuoto.className = 'scelta-ritratto' + (nascosto.value === '' ? ' scelto' : '');
  vuoto.innerHTML = '<div class="profilo-ritratto--vuoto" style="aspect-ratio:1">nessuna</div><b>Senza ritratto</b>';
  vuoto.addEventListener('click', function () {
    nascosto.value = '';
    griglia.querySelectorAll('.scelta-ritratto.scelto').forEach(function (x) { x.classList.remove('scelto'); });
    vuoto.classList.add('scelto');
  });

  function disegna() {
    var da = pagina * per;
    var pezzo = filtrati.slice(da, da + per);

    griglia.textContent = '';
    if (pagina === 0) { griglia.appendChild(vuoto); }
    pezzo.forEach(function (r) { griglia.appendChild(scheda(r)); });

    var pagine = Math.max(1, Math.ceil(filtrati.length / per));
    etPagina.textContent = 'pagina ' + (pagina + 1) + ' di ' + pagine;
    bPrec.disabled = pagina === 0;
    bSucc.disabled = pagina >= pagine - 1;
    piede.style.display = pagine > 1 ? '' : 'none';

    var liberi = filtrati.filter(function (r) { return !r.p; }).length;
    conteggio.textContent = campo.value.trim() === ''
      ? filtrati.length + ' ritratti, ' + liberi + ' liberi'
      : filtrati.length + ' su ' + tutti.length + ' corrispondono';
  }

  function cerca() {
    var q = campo.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    filtrati = q === '' ? tutti : tutti.filter(function (r) { return r._c.indexOf(q) !== -1; });
    pagina = 0;
    disegna();
  }

  campo.addEventListener('input', cerca);
  bPrec.addEventListener('click', function () { if (pagina > 0) { pagina--; disegna(); window.scrollTo({ top: radice.offsetTop - 20, behavior: 'smooth' }); } });
  bSucc.addEventListener('click', function () {
    if ((pagina + 1) * per < filtrati.length) { pagina++; disegna(); window.scrollTo({ top: radice.offsetTop - 20, behavior: 'smooth' }); }
  });

  disegna();
})();
