/**
 * Atlantik — la lente sugli emblemi di torretta.
 *
 * Al passaggio del mouse (o quando l'elemento prende il fuoco da tastiera)
 * l'emblema si vede in grande, col suo nome. Serve dove l'emblema e' piccolo
 * — il fascicolo, la testata del battello — e non toglie niente dove e' gia'
 * grande.
 *
 * Si attacca a qualunque immagine con data-emblema="Nome dell'emblema"; il
 * motto, se c'e', sta in data-emblema-motto. Niente JavaScript in linea: la
 * politica dei contenuti non lo permette, e va bene cosi'.
 *
 * Su schermo che si tocca non c'e' passaggio del mouse: li' un tocco accende
 * la lente e il tocco successivo (o altrove) la spegne.
 */
(function () {
  'use strict';

  var lente = null;
  var attuale = null;

  function crea() {
    if (lente !== null) { return lente; }
    lente = document.createElement('div');
    lente.className = 'lente-emblema';
    lente.setAttribute('role', 'tooltip');
    lente.innerHTML = '<img alt=""><b></b><span></span>';
    document.body.appendChild(lente);
    return lente;
  }

  function colloca(bersaglio) {
    var r = bersaglio.getBoundingClientRect();
    var l = crea();
    var lr = l.getBoundingClientRect();
    var margine = 10;

    // Sopra l'emblema se c'e' posto, altrimenti sotto.
    var y = r.top - lr.height - margine;
    if (y < margine) { y = r.bottom + margine; }

    var x = r.left + r.width / 2 - lr.width / 2;
    x = Math.max(margine, Math.min(x, window.innerWidth - lr.width - margine));

    l.style.left = Math.round(x) + 'px';
    l.style.top = Math.round(y) + 'px';
  }

  function accendi(bersaglio) {
    if (attuale === bersaglio) { return; }
    var l = crea();
    var img = l.querySelector('img');
    var nome = bersaglio.getAttribute('data-emblema') || '';
    var motto = bersaglio.getAttribute('data-emblema-motto') || '';

    img.src = bersaglio.getAttribute('src') || '';
    img.alt = nome;
    l.querySelector('b').textContent = nome;
    l.querySelector('span').textContent = motto;

    attuale = bersaglio;
    l.style.visibility = 'hidden';
    l.classList.add('viva');
    // Si misura dopo aver messo il contenuto, se no la larghezza e' quella vecchia.
    colloca(bersaglio);
    l.style.visibility = '';
  }

  function spegni() {
    if (lente === null) { return; }
    lente.classList.remove('viva');
    attuale = null;
  }

  function bersaglioDa(e) {
    var n = e.target;
    return n && n.closest ? n.closest('[data-emblema]') : null;
  }

  document.addEventListener('mouseover', function (e) {
    var b = bersaglioDa(e);
    if (b !== null) { accendi(b); } else if (attuale !== null) { spegni(); }
  });
  document.addEventListener('focusin', function (e) {
    var b = bersaglioDa(e);
    if (b !== null) { accendi(b); }
  });
  document.addEventListener('focusout', spegni);
  window.addEventListener('scroll', spegni, true);
  window.addEventListener('resize', spegni);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { spegni(); }
  });

  // Schermo che si tocca: un tocco accende, il successivo spegne.
  document.addEventListener('click', function (e) {
    var b = bersaglioDa(e);
    if (b === null) { spegni(); return; }
    if (attuale === b) { spegni(); } else { accendi(b); }
  });
})();
