#!/usr/bin/env bash
#
# Atlantik — la stanza dei bottoni: i monitor, le manopole, la carta ammiraglia.
#
#   bash tests/e2e_mondo.sh
#
# Quello che si guarda non deve cambiare niente; quello che cambia deve
# tornare indietro. Le due meta' si provano tutte e due, e in mezzo si guarda
# che la carta si disegni davvero — perche' una carta vuota e una carta rotta
# si somigliano troppo perche' se ne possa rispondere guardandola. Il
# 19/09/2026 la carta era muta per un array che in JSON diventava un oggetto,
# e dalla pagina non si capiva.
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
BASE_LOCALE="${BASE_LOCALE:-http://localhost/atlantik}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FALLITI=0

CAPO="prova mondo $(date +%s)"
GREGARIO="prova gregario $(date +%s)"
J1="$(mktemp)"; J2="$(mktemp)"; PROFILO="$(mktemp -d)"

trap 'for U in "${CAPO}" "${GREGARIO}"; do php "${ROOT}/bin/_cleanup_test_user.php" "${U}" >/dev/null 2>&1; done;
      php -r "require \"${ROOT}/bin/_bootstrap.php\"; App\\Game\\Meteo::togliTutte(); App\\Core\\Database::run(\"DELETE FROM game_config WHERE ckey LIKE '\''traffic.mix_%'\''\");" >/dev/null 2>&1;
      rm -f "${J1}" "${J2}" "${ROOT}/_prova_carta.html" "${ROOT}/_prova_zoom.html" "${ROOT}/_prova_zoom.js"; rm -rf "${PROFILO}"' EXIT

c()  { curl -s -k -b "${J1}" -c "${J1}" -H "Host: ${HOST_HDR}" "$@"; }
cg() { curl -s -k -b "${J2}" -c "${J2}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}
contiene() {
  if grep -q "$2" <<< "$3"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca: %s)\n' "$1" "$2"; FALLITI=$((FALLITI+1)); fi
}

echo "Prova end-to-end della stanza dei bottoni — ${BASE_URL}"

entra() { # jar, utente
  local J="$1" U="$2" T
  php "${ROOT}/bin/_prova_utente.php" "${U}" "rotta0909" >/dev/null 2>&1
  T=$(curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" "${BASE_URL}/accesso" | token_da)
  curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/accesso" \
    --data-urlencode "_token=${T}" --data-urlencode "login=${U}" --data-urlencode "password=rotta0909"
}
entra "${J1}" "${CAPO}"
entra "${J2}" "${GREGARIO}"
php "${ROOT}/bin/console.php" user:admin "${CAPO}" >/dev/null 2>&1

# --- Le porte sono chiuse a chi non comanda ---------------------------------
for R in mondo carta mondo/dati mondo/meteo; do
  verifica "/admin/${R} chiusa al giocatore comune" "403" \
    "$(cg -o /dev/null -w '%{http_code}' "${BASE_URL}/admin/${R}")"
done

# --- I monitor ---------------------------------------------------------------
PAGINA=$(c "${BASE_URL}/admin/mondo")
contiene "la stanza dei bottoni si apre"      "Il mondo"                  "${PAGINA}"
contiene "censimento per classe"              "Naviglio in mare, per classe" "${PAGINA}"
contiene "censimento per bandiera"            "Per bandiera"              "${PAGINA}"
contiene "composizione dei convogli"          "Composizione dei convogli" "${PAGINA}"
contiene "manopole divise per area"           "Manopole del motore"       "${PAGINA}"
contiene "e con scritto a che servono"        "Convogli in mare da tenere" "${PAGINA}"

DATI=$(c "${BASE_URL}/admin/mondo/dati?passo=10")
for CAMPO in '"ok":true' '"navi"' '"convogli"' '"battelli"' '"meteo"' '"quadro"'; do
  contiene "i dati della carta portano ${CAMPO}" "${CAMPO}" "${DATI}"
done
NAVI=$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo count($d["navi"] ?? []);' <<< "${DATI}")
IN_MARE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT COUNT(*) n FROM ships WHERE state = \"in_mare\" AND convoy_id IS NULL"); echo (int) $r["n"];')
if [[ "${NAVI}" -gt 0 && "${NAVI}" -le "${IN_MARE}" ]]; then
  verifica "la carta mostra le isolate davvero in mare" "si" "si"
else
  verifica "la carta mostra le isolate davvero in mare" "si" "no (${NAVI} su ${IN_MARE})"
fi

# --- Il meteo: si guarda, si forza, si libera --------------------------------
PRIMA=$(c "${BASE_URL}/admin/mondo/meteo?lat=50&lon=-20")
contiene "il tempo si legge in un punto qualunque" '"ok":true' "${PRIMA}"
MARE_PRIMA=$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo (int) $d["meteo"]["sea_state"];' <<< "${PRIMA}")

TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/meteo" --data-urlencode "_token=${TOK}" \
  --data-urlencode "lat=50" --data-urlencode "lon=-20" --data-urlencode "raggio_nm=300" \
  --data-urlencode "ore=6" --data-urlencode "sea_state=9" --data-urlencode "fog=1" \
  --data-urlencode "nota=prova"

DOPO=$(c "${BASE_URL}/admin/mondo/meteo?lat=50&lon=-20")
verifica "dentro il cerchio il mare e' quello imposto" "9" \
  "$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo (int) $d["meteo"]["sea_state"];' <<< "${DOPO}")"
verifica "e la pagina lo dichiara forzato" "1" \
  "$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo $d["meteo"]["forzato"] ? 1 : 0;' <<< "${DOPO}")"

FUORI=$(c "${BASE_URL}/admin/mondo/meteo?lat=30&lon=-60")
verifica "fuori dal cerchio il mondo non si accorge di niente" "0" \
  "$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo $d["meteo"]["forzato"] ? 1 : 0;' <<< "${FUORI}")"

PAGINA=$(c "${BASE_URL}/admin/mondo")
contiene "la forzatura si vede nell'elenco" "prova" "${PAGINA}"
TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/meteo/togli" --data-urlencode "_token=${TOK}" --data-urlencode "tutte=1"

LIBERO=$(c "${BASE_URL}/admin/mondo/meteo?lat=50&lon=-20")
verifica "tolta la forzatura, il tempo torna quello di prima" "${MARE_PRIMA}" \
  "$(php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo (int) $d["meteo"]["sea_state"];' <<< "${LIBERO}")"
verifica "e l'intervento resta nel registro" "admin.meteo_liberato" \
  "$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
   $r = App\Core\Database::first("SELECT action FROM audit_log WHERE target_type = \"weather\" ORDER BY id DESC LIMIT 1"); echo $r["action"] ?? "";')"

# --- La composizione del traffico --------------------------------------------
TOK=$(echo "$(c "${BASE_URL}/admin/mondo")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/mix" --data-urlencode "_token=${TOK}" \
  --data-urlencode "quale=convoglio" --data-urlencode "peso[petroliera_t2]=90" --data-urlencode "peso[liberty]=10"
verifica "la composizione forzata si salva" '{"petroliera_t2":90,"liberty":10}' \
  "$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
   $r = App\Core\Database::first("SELECT cvalue FROM game_config WHERE ckey = ?", ["traffic.mix_convoglio"]); echo $r["cvalue"] ?? "";')"

TOK=$(echo "$(c "${BASE_URL}/admin/mondo")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/mix" --data-urlencode "_token=${TOK}" \
  --data-urlencode "quale=convoglio" --data-urlencode "peso[petroliera_t2]=0" --data-urlencode "peso[liberty]=0"
verifica "svuotando le caselle si torna ai pesi storici" "0" \
  "$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
   $r = App\Core\Database::first("SELECT COUNT(*) n FROM game_config WHERE ckey = ?", ["traffic.mix_convoglio"]); echo (int) $r["n"];')"

# --- Il registro delle azioni ------------------------------------------------
#
# Audit::log scriveva diciotto azioni diverse e le due pagine che dicevano
# "registro" ne mostravano cinque: filtravano su auth.%, ed erano un diario
# degli accessi. Tutto quello che l'amministrazione faceva era scritto e
# invisibile.

# Si fa succedere qualcosa che PRIMA non sarebbe comparso da nessuna parte.
TOK=$(echo "$(c "${BASE_URL}/admin/mondo")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/meteo" --data-urlencode "_token=${TOK}" \
  --data-urlencode "sea_state=7" --data-urlencode "ore=1" --data-urlencode "nota=riga di registro"

REG=$(c "${BASE_URL}/admin/registro")
contiene "il registro si apre"                     "Registro delle azioni"  "${REG}"
contiene "e porta le azioni dell'amministrazione"  "Tempo forzato"          "${REG}"
contiene "detto in italiano, non in sigla"         "Forzatura"              "${REG}"
contiene "col dettaglio raccontato"                "riga di registro"       "${REG}"
CORPO=$(sed 's/title="[^"]*"//g' <<< "${REG}")
if grep -qE 'sea_state|"nota":' <<< "${CORPO}"; then
  verifica "il dettaglio a video non e' JSON" "si" "no"
else
  verifica "il dettaglio a video non e' JSON" "si" "si"
fi
contiene "e l'originale resta nel suggerimento"    'title="{'               "${REG}"

# Il filtro per area.
SOLO=$(c "${BASE_URL}/admin/registro?area=admin")
contiene "il filtro per area tiene le azioni di amministrazione" "Tempo forzato" "${SOLO}"
if grep -q "Accesso riuscito" <<< "${SOLO}"; then
  verifica "e lascia fuori gli accessi" "si" "no"
else
  verifica "e lascia fuori gli accessi" "si" "si"
fi

# La ricerca. Non si guarda il termine cercato: quello torna in pagina dentro
# la casella della ricerca, e un controllo che lo cercasse passerebbe sempre.
# Si guarda che venga fuori la RIGA.
CERCA=$(c "${BASE_URL}/admin/registro?cerca=riga%20di%20registro")
contiene "la ricerca trova la riga giusta" "Tempo forzato" "${CERCA}"
VUOTO=$(c "${BASE_URL}/admin/registro?cerca=zzzznessunacosa")
contiene "e su una ricerca senza esito lo dice" "Nessuna riga risponde" "${VUOTO}"

TOK=$(echo "$(c "${BASE_URL}/admin/mondo")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/mondo/meteo/togli" --data-urlencode "_token=${TOK}" --data-urlencode "tutte=1"

verifica "/admin/registro chiusa al giocatore comune" "403" \
  "$(cg -o /dev/null -w '%{http_code}' "${BASE_URL}/admin/registro")"

# --- La carta, disegnata da un browser vero ----------------------------------
BROWSER="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
if [[ -z "${BROWSER}" ]]; then
  printf '  \033[0;33m--\033[0m    carta saltata: nessun browser nel sistema\n'
else
  cat > "${ROOT}/_prova_carta.html" <<HTML
<!doctype html><meta charset="utf-8"><title>entrata</title>
<form id="f" method="post" action="/atlantik/accesso">
  <input name="login" value="${CAPO}"><input name="password" value="rotta0909"><input name="_token" id="t">
</form>
<script>
fetch('/atlantik/accesso').then(function (r) { return r.text(); }).then(function (h) {
  document.getElementById('t').value = h.match(/name="_token" value="([^"]*)"/)[1];
  document.getElementById('f').submit();
});
</script>
HTML
  timeout 60 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
    --user-data-dir="${PROFILO}" --virtual-time-budget=7000 --dump-dom \
    "${BASE_LOCALE}/_prova_carta.html" >/dev/null 2>&1

  USCITA="$(timeout 90 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
    --user-data-dir="${PROFILO}" --window-size=1500,1300 --virtual-time-budget=22000 --dump-dom \
    "${BASE_LOCALE}/admin/carta" 2>/dev/null)"
  STATO="$(grep -o 'class="stato-carta">[^<]*' <<< "${USCITA}" | head -1 | cut -d'>' -f2)"

  if grep -q 'errore' <<< "${STATO}"; then
    printf '  \033[0;31mKO\033[0m    la carta si disegna senza errori (%s)\n' "${STATO}"
    FALLITI=$((FALLITI+1))
  else
    printf '  \033[0;32mok\033[0m    la carta si disegna senza errori\n'
  fi
  if grep -qE '[0-9]+ isolate, [0-9]+ convogli, [0-9]+ battelli' <<< "${STATO}"; then
    printf '  \033[0;32mok\033[0m    e dice che cosa ha disegnato  \033[0;90m%s\033[0m\n' "${STATO}"
  else
    printf '  \033[0;31mKO\033[0m    e dice che cosa ha disegnato (ottenuto: %s)\n' "${STATO:-niente}"
    FALLITI=$((FALLITI+1))
  fi

  # Navigare la carta: rotellina, trascinamento, limiti, ritorno.
  cat > "${ROOT}/_prova_zoom.html" <<'HTML'
<!doctype html><meta charset="utf-8"><title>zoom</title>
<iframe id="q" src="/atlantik/admin/carta" style="width:1500px;height:1200px;border:0"></iframe>
<div id="esito">in corso</div>
<script src="/atlantik/_prova_zoom.js"></script>
HTML
  cat > "${ROOT}/_prova_zoom.js" <<'JS'
window.onerror = function (m) { document.getElementById('esito').textContent = 'ERRORE ' + m; };
document.getElementById('q').addEventListener('load', function () {
  var d = this.contentDocument, w = this.contentWindow, n = [];
  function z() { var e = d.querySelector('[data-zoom-valore]'); return e ? e.textContent.trim() : '?'; }
  function rotella(v, volte) {
    var t = d.getElementById('carta-admin');
    for (var i = 0; i < volte; i++) {
      t.dispatchEvent(new w.WheelEvent('wheel', { deltaY: v, clientX: 700, clientY: 500, bubbles: true, cancelable: true }));
    }
  }
  setTimeout(function () {
    n.push('inizio=' + (z() === 'tutto il teatro' ? 'teatro' : 'altro'));
    rotella(-120, 1);
    n.push('rotellaIngrandisce=' + (z() !== 'tutto il teatro' ? 'si' : 'no'));
    rotella(-120, 40);
    n.push('limiteAlto=' + z().replace('×', 'x'));
    rotella(120, 60);
    n.push('limiteBasso=' + (z() === 'tutto il teatro' ? 'teatro' : z()));
    rotella(-120, 6);
    var t = d.getElementById('carta-admin');
    t.dispatchEvent(new w.PointerEvent('pointerdown', { clientX: 700, clientY: 500, bubbles: true, pointerId: 1 }));
    t.dispatchEvent(new w.PointerEvent('pointermove', { clientX: 480, clientY: 400, bubbles: true, pointerId: 1 }));
    t.dispatchEvent(new w.PointerEvent('pointerup', { clientX: 480, clientY: 400, bubbles: true, pointerId: 1 }));
    n.push('trascina=si');
    d.querySelector('[data-tutto]').click();
    n.push('ritorno=' + (z() === 'tutto il teatro' ? 'teatro' : z()));
    n.push('vaiA=' + (d.querySelector('[data-vai]').options.length > 1 ? 'pieno' : 'vuoto'));
    document.getElementById('esito').textContent = 'ESITO ' + n.join(' ');
  }, 2600);
});
JS
  USCITA2="$(timeout 90 "${BROWSER}" --headless --disable-gpu --no-sandbox --disable-dev-shm-usage \
    --user-data-dir="${PROFILO}" --window-size=1520,1250 --virtual-time-budget=22000 --dump-dom \
    "${BASE_LOCALE}/_prova_zoom.html" 2>/dev/null)"
  rm -f "${ROOT}/_prova_zoom.html" "${ROOT}/_prova_zoom.js"
  leggi2() { grep -o "$1=[a-zA-Z0-9.]*" <<< "${USCITA2}" | head -1 | cut -d= -f2; }

  if grep -q 'ERRORE' <<< "${USCITA2}"; then
    printf '  \033[0;31mKO\033[0m    navigare la carta solleva un errore: %s\n' \
      "$(grep -o 'ERRORE [^<]*' <<< "${USCITA2}" | head -1)"
    FALLITI=$((FALLITI+1))
  fi
  verifica "la carta parte da tutto il teatro"       "teatro" "$(leggi2 inizio)"
  verifica "la rotellina ingrandisce"                "si"     "$(leggi2 rotellaIngrandisce)"
  verifica "l'ingrandimento ha un tetto"             "x24.0"  "$(leggi2 limiteAlto)"
  verifica "e un fondo, che e' tutto il teatro"      "teatro" "$(leggi2 limiteBasso)"
  verifica "si trascina senza rompersi"              "si"     "$(leggi2 trascina)"
  verifica "e si torna indietro con un pulsante"     "teatro" "$(leggi2 ritorno)"
  verifica "l'elenco «vai a» si riempie"             "pieno"  "$(leggi2 vaiA)"
fi

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
