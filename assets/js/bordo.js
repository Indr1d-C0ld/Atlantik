/* Vita di bordo: suoni sintetizzati e avvisi.
 *
 * Nessun file audio: i suoni si costruiscono con l'oscillatore del browser.
 * Il diesel e' rumore filtrato che pulsa, il ping ASDIC e' un tono puro che
 * decade, l'allarme e' il campanello della centrale. Sono pochi, discreti, e
 * si spengono con un interruttore — a bordo si sta zitti.
 *
 * Gli avvisi usano le notifiche del browser, ma solo con la pagina aperta: una
 * notifica a browser chiuso richiederebbe un servizio di push, che qui non c'e'
 * (e la pagina lo dice invece di far finta).
 */
(function () {
  'use strict';

  var CHIAVE_AUDIO = 'atlantik_audio';
  var CHIAVE_AVVISI = 'atlantik_avvisi';
  var ctx = null;

  function attivo(chiave) {
    try { return localStorage.getItem(chiave) === '1'; } catch (e) { return false; }
  }
  function imposta(chiave, valore) {
    try { localStorage.setItem(chiave, valore ? '1' : '0'); } catch (e) { /* niente */ }
  }

  function audio() {
    if (ctx === null) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return null; }
      ctx = new AC();
    }
    if (ctx.state === 'suspended') { ctx.resume(); }
    return ctx;
  }

  function tono(freq, durata, tipo, volume) {
    var c = audio();
    if (!c) { return; }
    var osc = c.createOscillator();
    var g = c.createGain();
    osc.type = tipo || 'sine';
    osc.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, c.currentTime);
    g.gain.exponentialRampToValueAtTime(volume || 0.08, c.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + durata);
    osc.connect(g); g.connect(c.destination);
    osc.start(); osc.stop(c.currentTime + durata + 0.05);
  }

  var suoni = {
    // Il ping dell'ASDIC: un tono che arriva, e poi l'eco.
    ping: function () { tono(1180, 0.45, 'sine', 0.06); setTimeout(function () { tono(1180, 0.3, 'sine', 0.025); }, 420); },
    // Il campanello dell'allarme immersione.
    allarme: function () {
      var n = 0;
      var t = setInterval(function () { tono(880, 0.12, 'square', 0.05); if (++n >= 4) { clearInterval(t); } }, 180);
    },
    // Scoppio: rumore basso e breve.
    scoppio: function () {
      var c = audio(); if (!c) { return; }
      var b = c.createBuffer(1, c.sampleRate * 0.6, c.sampleRate);
      var d = b.getChannelData(0);
      for (var i = 0; i < d.length; i++) { d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / d.length, 2.5); }
      var s = c.createBufferSource(); s.buffer = b;
      var f = c.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 320;
      var g = c.createGain(); g.gain.value = 0.22;
      s.connect(f); f.connect(g); g.connect(c.destination); s.start();
    },
    contatto: function () { tono(660, 0.14, 'triangle', 0.05); setTimeout(function () { tono(990, 0.18, 'triangle', 0.05); }, 150); }
  };

  window.ATL_SUONI = {
    riproduci: function (nome) { if (attivo(CHIAVE_AUDIO) && suoni[nome]) { suoni[nome](); } }
  };

  // --- avvisi ------------------------------------------------------------------
  function avvisa(titolo, testo) {
    if (!attivo(CHIAVE_AVVISI) || !('Notification' in window) || Notification.permission !== 'granted') { return; }
    try { new Notification(titolo, { body: testo, icon: 'assets/img/icona.svg', tag: 'atlantik' }); } catch (e) { /* niente */ }
  }
  window.ATL_AVVISA = avvisa;

  // --- interruttori nella barra ---------------------------------------------------
  function aggiornaEtichette() {
    var a = document.getElementById('interruttore-audio');
    var n = document.getElementById('interruttore-avvisi');
    if (a) { a.textContent = attivo(CHIAVE_AUDIO) ? 'audio ●' : 'audio ○'; }
    if (n) { n.textContent = attivo(CHIAVE_AVVISI) ? 'avvisi ●' : 'avvisi ○'; }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var a = document.getElementById('interruttore-audio');
    if (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        imposta(CHIAVE_AUDIO, !attivo(CHIAVE_AUDIO));
        if (attivo(CHIAVE_AUDIO)) { suoni.contatto(); }
        aggiornaEtichette();
      });
    }
    var n = document.getElementById('interruttore-avvisi');
    if (n) {
      n.addEventListener('click', function (e) {
        e.preventDefault();
        if (!attivo(CHIAVE_AVVISI) && 'Notification' in window && Notification.permission !== 'granted') {
          Notification.requestPermission().then(function (p) {
            imposta(CHIAVE_AVVISI, p === 'granted');
            aggiornaEtichette();
          });
          return;
        }
        imposta(CHIAVE_AVVISI, !attivo(CHIAVE_AVVISI));
        aggiornaEtichette();
      });
    }
    aggiornaEtichette();

    if ('serviceWorker' in navigator) {
      var base = document.body.getAttribute('data-base') || '';
      navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () { /* niente */ });
    }
  });
})();
