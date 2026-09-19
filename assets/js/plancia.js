/* Plancia: aggiornamento periodico dello stato e comandi rapidi.
 *
 * In crociera il mondo cambia lentamente: un giro ogni 30 secondi basta e
 * avanza. Le pagine non si ricaricano da sole — si aggiornano i quadranti.
 */
(function () {
  'use strict';

  var radice = document.querySelector('[data-plancia]');
  if (!radice) { return; }

  var url = radice.getAttribute('data-stato-url');
  var ultimaFirma = null;
  var intervallo = parseInt(radice.getAttribute('data-intervallo') || '30000', 10);

  function scrivi(nome, valore) {
    document.querySelectorAll('[data-campo="' + nome + '"]').forEach(function (el) {
      if (el.textContent !== valore) {
        el.textContent = valore;
        el.classList.remove('lampo');
        void el.offsetWidth;
        el.classList.add('lampo');
      }
    });
  }

  function barra(nome, pct) {
    document.querySelectorAll('[data-barra="' + nome + '"]').forEach(function (el) {
      el.style.width = Math.max(0, Math.min(100, pct)) + '%';
    });
  }

  /**
   * Un numero come lo scrive il server: virgola per i decimali, punto per le
   * migliaia. Le due meta' della stessa casella devono dire la stessa cosa — se
   * no il valore cambia forma da solo qualche secondo dopo il caricamento, e
   * chi guarda non capisce perche'. L'autonomia si leggeva "8.696 nm" appena
   * caricata la pagina e "8696 nm" al primo aggiornamento.
   */
  function numero(v, dec) {
    var t = Number(v).toFixed(dec === undefined ? 0 : dec);
    var pezzi = t.split('.');
    pezzi[0] = pezzi[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return pezzi.join(',');
  }

  function aggiorna(s) {
    var b = s.battello, m = s.meteo, c = s.cielo;

    scrivi('ora', s.ora);
    scrivi('quadrat', b.quadrat || '—');
    scrivi('posizione', b.lat_txt + '   ' + b.lon_txt);
    scrivi('errore', '± ' + numero(b.errore_nm, 1) + ' nm');
    scrivi('rotta', ('00' + numero(b.rotta)).slice(-3) + '°');
    scrivi('velocita', numero(b.velocita, 1) + ' kn');
    scrivi('vel_ord', numero(b.vel_ord, 1) + ' kn');
    scrivi('quota', numero(b.quota, 1) + ' m');
    scrivi('quota_ord', numero(b.quota_ord) + ' m');
    scrivi('modo', b.modo);
    scrivi('nafta', numero(b.nafta_t, 1) + ' t');
    scrivi('nafta_pct', numero(b.nafta_pct) + '%');
    scrivi('autonomia', numero(b.autonomia_nm) + ' nm');
    scrivi('batteria', numero(b.batteria) + '%');
    scrivi('ore_immersione', numero(b.ore_immersione, 1) + ' h');
    scrivi('aria', numero(b.aria) + '%');
    scrivi('co2', numero(b.co2, 2) + '%');
    scrivi('viveri', numero(b.viveri, 1) + ' g');

    barra('nafta', b.nafta_pct);
    barra('batteria', b.batteria);
    barra('aria', b.aria);

    scrivi('meteo', m.descrizione);
    scrivi('vento', numero(m.wind_kn) + ' kn');   // il modello manda 7.4, il server scrive 19
    scrivi('mare', 'forza ' + m.sea_state);
    scrivi('visibilita', numero(m.visibility_nm, 1) + ' nm');
    scrivi('pressione', numero(m.pressure_hpa) + ' hPa');
    scrivi('fase', c.fase);
    scrivi('luce', numero(c.luce * 100) + '%');
    scrivi('luna', c.moon_phase);

    if (s.materiale) {
      scrivi('avarie', String(s.materiale.avarie));
      scrivi('stress', numero(s.materiale.stress) + '%');
    }

    // Avvisi e suoni: solo sulle righe di giornale mai viste prima, e solo se
    // il comandante li ha accesi. Niente sorprese sonore a chi non le vuole.
    if (s.ktb && s.ktb.length) {
      var primo = s.ktb[0];
      var firma = primo.ora + '|' + primo.text.slice(0, 40);
      if (ultimaFirma !== null && firma !== ultimaFirma) {
        if (/aereo|allarme|cariche/i.test(primo.kind + primo.text)) {
          if (window.ATL_SUONI) { window.ATL_SUONI.riproduci('allarme'); }
          if (window.ATL_AVVISA) { window.ATL_AVVISA('Atlantik — allarme', primo.text); }
        } else if (/contatto|avvistati/i.test(primo.kind)) {
          if (window.ATL_SUONI) { window.ATL_SUONI.riproduci('contatto'); }
          if (window.ATL_AVVISA) { window.ATL_AVVISA('Atlantik — contatto', primo.text); }
        }
      }
      ultimaFirma = firma;
    }

    var ktb = document.getElementById('ktb-vivo');
    if (ktb && s.ktb) {
      ktb.innerHTML = s.ktb.map(function (e) {
        return '<li class="sev-' + e.sev + '"><span class="ora">' + e.ora + '</span>' +
               '<span class="testo">' + e.text.replace(/[<>]/g, '') + '</span></li>';
      }).join('');
    }
  }

  function giro() {
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) { if (s && s.ok) { aggiorna(s); } })
      .catch(function () { /* il mare non aspetta: si riprova al giro dopo */ });
  }

  setInterval(giro, intervallo);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { giro(); }
  });

  // Comandi rapidi di quota: riempiono il campo e inviano il modulo.
  document.querySelectorAll('[data-quota]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.getElementById('modulo-ordini');
      var campo = document.getElementById('campo-quota');
      if (f && campo) { campo.value = b.getAttribute('data-quota'); f.submit(); }
    });
  });
  document.querySelectorAll('[data-velocita]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.getElementById('modulo-ordini');
      var campo = document.getElementById('campo-velocita');
      if (f && campo) { campo.value = b.getAttribute('data-velocita'); f.submit(); }
    });
  });
})();
