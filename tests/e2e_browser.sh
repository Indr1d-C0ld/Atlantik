#!/usr/bin/env bash
#
# Atlantik — prova del JavaScript di bordo, guidato da un browser vero.
#
#   bash tests/e2e_browser.sh
#
# E' l'unica prova che ESEGUE il JavaScript. Serve perche' il resto della suite
# non lo tocca, e il 18/09/2026 e' costato un riquadro nero: estraendo il filtro
# d'epoca in un file suo, dentro ci era finita anche una funzione che serviva a
# chi chiamava. La sintassi era buona, il file nuovo funzionava da solo, e il
# widget vero era rotto. Nessuna prova se ne era accorta perche' nessuna prova
# apriva il widget vero.
#
# Qui si apre. Si sceglie un file come lo sceglierebbe una persona, si guarda che
# il riquadro non resti nero, si accende la casella d'epoca e si guarda che
# l'immagine cambi davvero.
#
# Se nel sistema non c'e' un browser, la prova si salta dicendolo: meglio saltata
# che finta.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="${BASE_LOCALE:-http://localhost/atlantik}"
FALLITI=0

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}

BROWSER="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
if [[ -z "${BROWSER}" ]]; then
  echo "Prova del browser — saltata: nessun browser nel sistema."
  exit 0
fi

echo "Prova del browser — ${BASE}"

PAGINA="${ROOT}/_prova_browser.html"
GUIDA="${ROOT}/_prova_browser.js"
SORGENTE="${ROOT}/_prova_browser.png"
PROFILO="$(mktemp -d)"
trap 'rm -f "${PAGINA}" "${GUIDA}" "${SORGENTE}"; rm -rf "${PROFILO}"' EXIT

# Una fotografia qualunque: si prende un ritratto del repertorio, che c'e' di
# sicuro, e la si riscrive in PNG perche' il ritaglio accetta anche quello.
php -r '
$f = glob("'"${ROOT}"'/assets/img/ritratti/*.webp");
if ($f === []) { exit(1); }
$im = imagecreatefromwebp($f[0]);
imagepng($im, "'"${SORGENTE}"'");' || { echo "  nessun ritratto da usare come sorgente"; exit 0; }

cat > "${PAGINA}" <<'HTML'
<!doctype html><meta charset="utf-8"><title>attesa</title>
<form id="f" method="post" enctype="multipart/form-data" action="#">
  <input type="file" id="file" name="ritratto" data-ritaglio>
  <label><input type="checkbox" name="invecchia" value="1" id="vecchia"> d'epoca</label>
</form>
<div id="esito">in corso</div>
<script src="/atlantik/assets/js/invecchia.js"></script>
<script src="/atlantik/assets/js/ritaglio.js"></script>
<script src="/atlantik/_prova_browser.js"></script>
HTML

cat > "${GUIDA}" <<'JS'
window.onerror = function (m) { document.getElementById('esito').textContent = 'ERRORE ' + m; };
function vivi(c) {
  var d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, n = 0;
  for (var i = 0; i < d.length; i += 4 * 97) { if (d[i] > 12 || d[i+1] > 12 || d[i+2] > 12) { n++; } }
  return n;
}
function firma(c) {
  var d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, s = 0;
  for (var i = 0; i < d.length; i += 4 * 31) { s += d[i] + d[i+1] * 2 + d[i+2] * 3; }
  return s;
}
fetch('/atlantik/_prova_browser.png').then(function (r) { return r.blob(); }).then(function (b) {
  var dt = new DataTransfer();
  dt.items.add(new File([b], 'prova.png', { type: 'image/png' }));
  var inp = document.getElementById('file');
  inp.files = dt.files;
  inp.dispatchEvent(new Event('change'));
  setTimeout(function () {
    var c = document.querySelector('.ritaglio canvas');
    if (!c) { document.getElementById('esito').textContent = 'NIENTE-RIQUADRO'; return; }
    var senza = vivi(c), f1 = firma(c);
    document.getElementById('vecchia').checked = true;
    document.getElementById('vecchia').dispatchEvent(new Event('change'));
    setTimeout(function () {
      document.getElementById('esito').textContent =
        'ESITO vivi=' + senza + ' vivi2=' + vivi(c) + ' cambiata=' + (firma(c) !== f1 ? 'si' : 'no');
    }, 400);
  }, 800);
});
JS

USCITA="$(timeout 60 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
  --user-data-dir="${PROFILO}" --virtual-time-budget=9000 --dump-dom \
  "${BASE}/_prova_browser.html" 2>/dev/null)"

if grep -q 'ERRORE' <<< "${USCITA}"; then
  printf '  \033[0;31mKO\033[0m    il JavaScript solleva un errore: %s\n' \
    "$(grep -o 'ERRORE [^<]*' <<< "${USCITA}" | head -1)"
  FALLITI=$((FALLITI+1))
else
  printf '  \033[0;32mok\033[0m    il JavaScript non solleva errori\n'
fi

RIQUADRO=$(grep -c 'class="ritaglio"' <<< "${USCITA}" || true)
verifica "il riquadro di ritaglio compare dopo la scelta del file" "1" "${RIQUADRO}"

VIVI=$(grep -o 'vivi=[0-9]*' <<< "${USCITA}" | head -1 | cut -d= -f2)
if [[ -n "${VIVI}" && "${VIVI}" -gt 50 ]]; then
  printf '  \033[0;32mok\033[0m    il riquadro non resta nero  \033[0;90m%s campioni accesi\033[0m\n' "${VIVI}"
else
  printf '  \033[0;31mKO\033[0m    il riquadro resta nero (campioni accesi: %s)\n' "${VIVI:-nessuno}"
  FALLITI=$((FALLITI+1))
fi

CAMBIATA=$(grep -o 'cambiata=[a-z]*' <<< "${USCITA}" | head -1 | cut -d= -f2)
verifica "la casella d'epoca cambia subito l'immagine" "si" "${CAMBIATA:-assente}"


# --- Seconda parte: la pagina vera -------------------------------------------
#
# La prima parte monta un banco di prova sintetico, e proprio per questo non
# vede i guasti che stanno nella pagina invece che nello script. Il 18/09/2026
# ne e' passato uno sotto il naso: nella creazione del comandante un </div> di
# troppo chiudeva il pannello mentre il <form> era ancora aperto, il browser
# sganciava il form e ritratto, fotografia e casella d'epoca finivano FUORI.
# Lo script era sano, il banco sintetico verde, e nella pagina vera la casella
# non faceva niente — e il ritratto scelto in galleria non veniva nemmeno
# spedito. Qui si apre la pagina vera, dentro un iframe di pari origine, e si
# guarda che i campi stiano davvero dentro il form.

UTENTE="prova browser $(date +%s)"
PAROLA="rotta0909"
PAGINA2="${ROOT}/_prova_browser_reale.html"
GUIDA2="${ROOT}/_prova_browser_reale.js"
ENTRATA="${ROOT}/_prova_browser_entra.html"
trap 'rm -f "${PAGINA}" "${GUIDA}" "${SORGENTE}" "${PAGINA2}" "${GUIDA2}" "${ENTRATA}"; rm -rf "${PROFILO}"; php "${ROOT}/bin/_cleanup_test_user.php" "${UTENTE}" >/dev/null 2>&1' EXIT

if ! php "${ROOT}/bin/_prova_utente.php" "${UTENTE}" "${PAROLA}" >/dev/null 2>&1; then
  printf '  \033[0;33m--\033[0m    pagina vera saltata: non si riesce a creare l utente di prova\n'
else

cat > "${ENTRATA}" <<HTML
<!doctype html><meta charset="utf-8"><title>entrata</title>
<form id="f" method="post" action="/atlantik/accesso">
  <input name="login" value="${UTENTE}">
  <input name="password" value="${PAROLA}">
  <input name="_token" id="t">
</form>
<script>
fetch('/atlantik/accesso').then(function (r) { return r.text(); }).then(function (h) {
  document.getElementById('t').value = h.match(/name="_token" value="([^"]*)"/)[1];
  document.getElementById('f').submit();
});
</script>
HTML

cat > "${PAGINA2}" <<'HTML'
<!doctype html><meta charset="utf-8"><title>pagina vera</title>
<iframe id="q" src="/atlantik/comandante" style="width:1200px;height:2400px;border:0"></iframe>
<div id="esito">in corso</div>
<script src="/atlantik/_prova_browser_reale.js"></script>
HTML

cat > "${GUIDA2}" <<'JS'
window.onerror = function (m) { document.getElementById('esito').textContent = 'ERRORE ' + m; };
function firma(c) {
  var d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, s = 0, vivi = 0;
  for (var i = 0; i < d.length; i += 4 * 31) {
    s += d[i] + d[i+1] * 2 + d[i+2] * 3;
    if (d[i] > 12 || d[i+1] > 12 || d[i+2] > 12) { vivi++; }
  }
  return [s, vivi];
}
document.getElementById('q').addEventListener('load', function () {
  var d = this.contentDocument, w = this.contentWindow, n = [];
  var inp = d.querySelector('input[type=file][data-ritaglio]');
  var box = d.querySelector('input[name=invecchia]');
  if (!inp || !box) {
    document.getElementById('esito').textContent = 'ESITO campoFile=' + (inp ? 'si' : 'no') + ' casella=' + (box ? 'si' : 'no');
    return;
  }
  var f = inp.form;
  // contains(), non elements: il puntatore al form del parser associa i campi
  // anche quando il form e' stato sganciato, ed e' proprio il caso da scoprire.
  n.push('fileDentro=' + (f && f.contains(inp) ? 'si' : 'no'));
  n.push('casellaDentro=' + (f && f.contains(box) ? 'si' : 'no'));
  n.push('ritrattoDentro=' + (f && f.querySelector('[name=ritratto_key]') ? 'si' : 'no'));
  n.push('inviaDentro=' + (f && f.querySelector('button[type=submit]') ? 'si' : 'no'));
  var t = d.createElement('canvas');
  t.width = t.height = 400;
  var c = t.getContext('2d');
  var g = c.createLinearGradient(0, 0, 400, 400);
  g.addColorStop(0, '#c04020');
  g.addColorStop(1, '#2040c0');
  c.fillStyle = g; c.fillRect(0, 0, 400, 400);
  c.fillStyle = '#f0e0a0'; c.fillRect(120, 120, 160, 160);
  t.toBlob(function (b) {
    var dt = new w.DataTransfer();
    dt.items.add(new w.File([b], 'prova.png', { type: 'image/png' }));
    inp.files = dt.files;
    inp.dispatchEvent(new w.Event('change', { bubbles: true }));
    // Non si aspetta "abbastanza": si aspetta che il riquadro abbia davvero
    // disegnato. Con un tempo fisso la prova falliva ogni tanto sul primo
    // campione, preso mentre la tela era ancora nera — e la colpa sembrava
    // del prodotto.
    attendiDisegno(0);
  });
  function attendiDisegno(giri) {
    var tela = d.querySelector('.ritaglio canvas');
    if ((!tela || firma(tela)[1] === 0) && giri < 40) {
      setTimeout(function () { attendiDisegno(giri + 1); }, 100);
      return;
    }
    (function () {
      if (!tela) { document.getElementById('esito').textContent = 'ESITO ' + n.join(' ') + ' riquadro=no'; return; }
      var a = firma(tela);
      box.checked = true;
      box.dispatchEvent(new w.Event('change', { bubbles: true }));
      setTimeout(function () {
        var b2 = firma(tela);
        box.checked = false;
        box.dispatchEvent(new w.Event('change', { bubbles: true }));
        setTimeout(function () {
          var c3 = firma(tela);
          n.push('riquadro=si');
          n.push('accesi=' + a[1]);
          n.push('epocaCambia=' + (a[0] !== b2[0] ? 'si' : 'no'));
          n.push('epocaTorna=' + (a[0] === c3[0] ? 'si' : 'no'));
          n.push('firme=' + a[0] + '/' + b2[0] + '/' + c3[0]);
          document.getElementById('esito').textContent = 'ESITO ' + n.join(' ');
        }, 400);
      }, 400);
    })();
  }
});
JS

timeout 60 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
  --user-data-dir="${PROFILO}" --virtual-time-budget=6000 --dump-dom \
  "${BASE}/_prova_browser_entra.html" >/dev/null 2>&1

USCITA2="$(timeout 90 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
  --user-data-dir="${PROFILO}" --window-size=1250,1000 --virtual-time-budget=14000 --dump-dom \
  "${BASE}/_prova_browser_reale.html" 2>/dev/null)"

leggi2() { grep -o "$1=[a-z0-9]*" <<< "${USCITA2}" | head -1 | cut -d= -f2; }

printf '  \033[0;90m%s\033[0m\n' "$(grep -o 'ESITO [^<]*' <<< "${USCITA2}" | head -1)"

if grep -q 'ERRORE' <<< "${USCITA2}"; then
  printf '  \033[0;31mKO\033[0m    la pagina vera solleva un errore: %s\n' \
    "$(grep -o 'ERRORE [^<]*' <<< "${USCITA2}" | head -1)"
  FALLITI=$((FALLITI+1))
fi

verifica "creazione: il campo fotografia sta dentro il form"      "si" "$(leggi2 fileDentro)"
verifica "creazione: la casella d'epoca sta dentro il form"       "si" "$(leggi2 casellaDentro)"
verifica "creazione: il ritratto scelto sta dentro il form"       "si" "$(leggi2 ritrattoDentro)"
verifica "creazione: il pulsante d'invio sta dentro il form"      "si" "$(leggi2 inviaDentro)"
verifica "creazione: il riquadro di ritaglio compare"             "si" "$(leggi2 riquadro)"
verifica "creazione: la casella d'epoca cambia subito l'immagine" "si" "$(leggi2 epocaCambia)"
verifica "creazione: togliendola l'immagine torna com'era"        "si" "$(leggi2 epocaTorna)"

fi


# --- Terza parte: la lente sugli emblemi -------------------------------------
#
# Piccola e tutta nel browser: si mette in pagina un'immagine con
# data-emblema, ci si passa sopra col mouse e si guarda che compaia la lente
# con l'ingrandimento, il nome e il motto — e che se ne vada quando il mouse
# se ne va. Il fatto che gli emblemi in pagina abbiano davvero l'attributo lo
# controlla test_profilo.php, che legge le viste.

PAGINA3="${ROOT}/_prova_lente.html"
GUIDA3="${ROOT}/_prova_lente.js"
trap 'rm -f "${PAGINA}" "${GUIDA}" "${SORGENTE}" "${PAGINA2}" "${GUIDA2}" "${ENTRATA}" "${PAGINA3}" "${GUIDA3}"; rm -rf "${PROFILO}"; php "${ROOT}/bin/_cleanup_test_user.php" "${UTENTE}" >/dev/null 2>&1' EXIT

cat > "${PAGINA3}" <<'HTML'
<!doctype html><meta charset="utf-8"><title>lente</title>
<link rel="stylesheet" href="/atlantik/assets/css/atlantik.css">
<div style="height:40vh"></div>
<img id="e" class="emblema-tondo" width="40" height="40"
     src="/atlantik/assets/img/emblemi/ancora.svg"
     alt="Ancora" data-emblema="Ancora" data-emblema-motto="Si torna">
<div id="esito">in corso</div>
<script src="/atlantik/assets/js/emblemi.js"></script>
<script src="/atlantik/_prova_lente.js"></script>
HTML

cat > "${GUIDA3}" <<'JS'
window.onerror = function (m) { document.getElementById('esito').textContent = 'ERRORE ' + m; };
setTimeout(function () {
  var n = [], img = document.getElementById('e');
  n.push('primaNiente=' + (document.querySelector('.lente-emblema') === null ? 'si' : 'no'));
  n.push('tonda=' + (getComputedStyle(img).borderTopLeftRadius === '50%' ? 'si' : 'no'));
  img.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
  setTimeout(function () {
    var l = document.querySelector('.lente-emblema');
    n.push('lente=' + (l ? 'si' : 'no'));
    if (l) {
      n.push('viva=' + (l.classList.contains('viva') ? 'si' : 'no'));
      n.push('nome=' + (l.querySelector('b').textContent === 'Ancora' ? 'si' : 'no'));
      n.push('conMotto=' + (l.querySelector('span').textContent === 'Si torna' ? 'si' : 'no'));
      var g = l.querySelector('img').getBoundingClientRect();
      n.push('ingrandita=' + (g.width > img.getBoundingClientRect().width * 2 ? 'si' : 'no'));
      var r = l.getBoundingClientRect();
      n.push('dentro=' + (r.left >= 0 && r.top >= 0 && r.right <= window.innerWidth ? 'si' : 'no'));
    }
    document.body.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
    setTimeout(function () {
      var l2 = document.querySelector('.lente-emblema');
      n.push('spenta=' + (l2 !== null && !l2.classList.contains('viva') ? 'si' : 'no'));
      document.getElementById('esito').textContent = 'ESITO ' + n.join(' ');
    }, 250);
  }, 350);
}, 400);
JS

USCITA3="$(timeout 60 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
  --user-data-dir="${PROFILO}" --window-size=900,700 --virtual-time-budget=9000 --dump-dom \
  "${BASE}/_prova_lente.html" 2>/dev/null)"

leggi3() { grep -o "$1=[a-z]*" <<< "${USCITA3}" | head -1 | cut -d= -f2; }

if grep -q 'ERRORE' <<< "${USCITA3}"; then
  printf '  \033[0;31mKO\033[0m    la lente solleva un errore: %s\n' \
    "$(grep -o 'ERRORE [^<]*' <<< "${USCITA3}" | head -1)"
  FALLITI=$((FALLITI+1))
fi

verifica "emblema: la cornice e' tonda"                  "si" "$(leggi3 tonda)"
verifica "emblema: la lente non c'e' finche' non serve"  "si" "$(leggi3 primaNiente)"
verifica "emblema: al passaggio del mouse la lente compare" "si" "$(leggi3 lente)"
verifica "emblema: la lente e' visibile"                 "si" "$(leggi3 viva)"
verifica "emblema: la lente porta il nome"               "si" "$(leggi3 nome)"
verifica "emblema: la lente porta il motto"              "si" "$(leggi3 conMotto)"
verifica "emblema: l'immagine e' ingrandita"             "si" "$(leggi3 ingrandita)"
verifica "emblema: la lente resta dentro lo schermo"     "si" "$(leggi3 dentro)"
verifica "emblema: si spegne quando il mouse se ne va"   "si" "$(leggi3 spenta)"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
