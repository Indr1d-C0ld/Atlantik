#!/usr/bin/env bash
#
# Atlantik — la matrice dei permessi, provata invece che letta.
#
#   bash tests/e2e_permessi.sh
#
# Le revisioni precedenti hanno controllato che ogni rotta /admin avesse il suo
# middleware e che ogni POST avesse il freno. E' un controllo sul CODICE: dice
# che la dichiarazione c'e', non che funzioni. Questa prova chiede la stessa
# cosa al server, con sei identita' diverse, e guarda che cosa risponde.
#
#   ospite    — nessuna sessione
#   pendente  — iscritto, indirizzo non ancora confermato
#   attivo    — un giocatore qualunque
#   sospeso   — provvedimento temporaneo
#   bandito   — provvedimento definitivo
#   comando   — amministratore
#
# Il contratto, scritto in Router::runMiddleware:
#
#   (nessuno)  pagina pubblica: chiunque, anche l'ospite
#   guest      solo chi NON ha una sessione valida (sospeso e bandito non ce
#              l'hanno: per loro Auth::user() e' nullo, quindi passano)
#   auth       serve un account in qualunque stato, anche non confermato
#   active     serve un account confermato e attivo
#   admin      403 per tutti gli altri, compreso il giocatore attivo
#
# Si provano solo le rotte GET: una POST cambierebbe lo stato del mondo, e qui
# interessa chi entra, non che cosa fa. Alla fine si porta via tutto.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FALLITI=0
PASS="pattuglia77"

declare -A JAR
for chi in ospite pendente attivo sospeso bandito comando; do
  JAR[$chi]="$(mktemp)"
done

UT_PEND="prova perm pend $(date +%s)"
UT_ATT="prova perm att $(date +%s)"
UT_SOSP="prova perm sosp $(date +%s)"
UT_BAND="prova perm band $(date +%s)"
UT_ADMIN="prova perm adm $(date +%s)"

pulisci() {
  for n in "${UT_PEND}" "${UT_ATT}" "${UT_SOSP}" "${UT_BAND}" "${UT_ADMIN}"; do
    php "${ROOT}/bin/_cleanup_test_user.php" "${n}" >/dev/null 2>&1
  done
  for chi in "${!JAR[@]}"; do rm -f "${JAR[$chi]}"; done
}
trap pulisci EXIT

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}

php "${ROOT}/bin/_prova_sfrena.php" >/dev/null 2>&1
echo "Prova end-to-end della matrice dei permessi — ${BASE_URL}"

# --- le sei identita' ---------------------------------------------------------
crea() { # nome, stato
  php "${ROOT}/bin/_prova_utente.php" "$1" "${PASS}" >/dev/null 2>&1
  php -r '
  require "'"${ROOT}"'/bin/_bootstrap.php";
  App\Core\Database::run("UPDATE users SET status = ? WHERE username = ?", [$argv[2], $argv[1]]);' "$1" "$2"
}
entra() { # jar, nome
  local TOK
  TOK=$(curl -s -k -b "$1" -c "$1" -H "Host: ${HOST_HDR}" "${BASE_URL}/accesso" \
        | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -k -b "$1" -c "$1" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/accesso" \
    --data-urlencode "_token=${TOK}" --data-urlencode "login=$2" --data-urlencode "password=${PASS}"
}

crea "${UT_PEND}"  "pending"
crea "${UT_ATT}"   "active"
crea "${UT_SOSP}"  "active"     # si entra da attivi, poi si sospende
crea "${UT_BAND}"  "active"
crea "${UT_ADMIN}" "active"
php "${ROOT}/bin/console.php" user:admin "${UT_ADMIN}" >/dev/null 2>&1

entra "${JAR[pendente]}" "${UT_PEND}"
entra "${JAR[attivo]}"   "${UT_ATT}"
entra "${JAR[sospeso]}"  "${UT_SOSP}"
entra "${JAR[bandito]}"  "${UT_BAND}"
entra "${JAR[comando]}"  "${UT_ADMIN}"

# I provvedimenti arrivano DOPO l'accesso: e' il caso che conta, perche' la
# sessione e' gia' aperta e il provvedimento deve chiuderla da solo.
php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
App\Core\Database::run("UPDATE users SET status = \"suspended\" WHERE username = ?", [$argv[1]]);' "${UT_SOSP}"
php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
App\Core\Database::run("UPDATE users SET status = \"banned\" WHERE username = ?", [$argv[1]]);' "${UT_BAND}"

# --- la matrice ---------------------------------------------------------------
ROTTE=$(php -r '
$s = file_get_contents("'"${ROOT}"'/src/routes.php");
preg_match_all("/\\\$router->get\(\s*\x27([^\x27]+)\x27\s*,\s*(?:\[[^\]]+\]|function[^,]*)\s*(?:,\s*\[([^\]]*)\])?\s*\)/", $s, $m, PREG_SET_ORDER);
foreach ($m as $r) {
    $mw = trim($r[2] ?? "");
    $mw = $mw === "" ? "-" : implode(",", array_map(fn($x) => trim($x, " \x27\""), explode(",", $mw)));
    $mw = implode(",", array_filter(explode(",", $mw), fn($x) => $x !== "throttle")) ?: "-";
    echo $r[1], " ", $mw, "\n";
}')

# Gli identificativi veri per le rotte con segnaposto. Riempirli a caso non
# prova niente: una pagina che non trova l'oggetto rimanda indietro, e quel
# rimando si confonderebbe con il rifiuto per mancanza di permessi.
ID_CMD=$(php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$c = App\Game\Comandante::crea((int) $u["id"], [
  "nome" => "Permessi Prova " . substr((string) time(), -5), "nato_il" => "1912-04-04",
  "nato_a" => "Kiel", "ritratto" => "r1", "base" => "lorient"]);
$cid = (int) ($c["commander_id"] ?? 0);
$b = App\Game\Fleet::ensureBoat((int) $u["id"]);
App\Core\Database::run(
  "INSERT INTO patrols (boat_id, user_id, commander_id, number, departed_gts, state, base_key)
   VALUES (?, ?, ?, 1, ?, \"conclusa\", \"lorient\")",
  [(int) $b["id"], (int) $u["id"], $cid, App\Sim\World::now() - 3600]);
echo $cid, " ", App\Core\Database::lastInsertId(), " ", (int) $u["id"];' "${UT_ATT}")
read -r ID_CMD ID_PATROL ID_UTENTE <<< "${ID_CMD}"

# --- che cosa si pretende, e in che direzione --------------------------------
#
# La domanda che conta non e' "il giocatore legittimo vede la pagina?" — quello
# dipende da dove si trova il suo battello, e un rimando alla base e' una
# risposta giusta. La domanda che conta e' l'altra: CHI NON DEVE ENTRARE,
# entra? Un 200 dove ci vuole un rifiuto e' un buco; un 302 dove ci si
# aspettava una pagina e' quasi sempre il gioco che fa il suo mestiere.
#
# Quindi: per ogni rotta si elencano le identita' che devono essere respinte,
# e si pretende il rifiuto. Le altre si guardano solo per essere sicuri che la
# porta non sia murata per tutti.
respinti() { # middleware -> identita' che NON devono entrare
  case "$1" in
    -)      echo "" ;;
    guest)  echo "attivo comando" ;;
    auth)   echo "ospite sospeso bandito" ;;
    active) echo "ospite sospeso bandito pendente" ;;
    admin)  echo "ospite sospeso bandito pendente attivo" ;;
  esac
}
codice_rifiuto() { # middleware
  case "$1" in
    admin) echo "403" ;;
    *)     echo "302" ;;
  esac
}

PROVATE=0
ENTRA_QUALCUNO=0
while read -r ROTTA MW; do
  [[ -z "${ROTTA}" ]] && continue
  URL="${ROTTA}"
  case "${ROTTA}" in
    /rapporto/*|/ktb/*)      URL="${ROTTA//\{id\}/${ID_PATROL}}" ;;
    /admin/utente/*)         URL="${ROTTA//\{id\}/${ID_UTENTE}}" ;;
    *\{id\}*)                URL="${ROTTA//\{id\}/${ID_CMD}}" ;;
  esac
  case "${URL}" in
    */verifica|*/recupero) continue ;;   # vogliono un gettone: rispondono a modo loro
  esac

  RIF=$(codice_rifiuto "${MW}")
  for chi in $(respinti "${MW}"); do
    GOT=$(curl -s -k -b "${JAR[$chi]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}${URL}")
    PROVATE=$((PROVATE+1))
    if [[ "${GOT}" != "${RIF}" ]]; then
      printf '  \033[0;31mKO\033[0m    %-30s %-7s %-9s doveva prendere %s, ha preso %s\n' \
        "${URL}" "${MW}" "${chi}" "${RIF}" "${GOT}"
      FALLITI=$((FALLITI+1))
    fi
  done

  # L'ospite sulle rotte pubbliche: quelle devono aprirsi davvero.
  if [[ "${MW}" == "-" ]]; then
    GOT=$(curl -s -k -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}${URL}")
    PROVATE=$((PROVATE+1))
    if [[ "${GOT}" != "200" ]]; then
      printf '  \033[0;31mKO\033[0m    %-30s pubblica  ospite    doveva aprirsi, ha dato %s\n' "${URL}" "${GOT}"
      FALLITI=$((FALLITI+1))
    fi
  fi
done <<< "${ROTTE}"

printf '  \033[0;32mok\033[0m    %d rifiuti verificati sulle rotte GET\n' "${PROVATE}"

# --- e che la porta non sia murata per tutti ---------------------------------
#
# Il rovescio della medaglia: se per un errore tutte le rotte rispondessero 403
# a chiunque, le verifiche di sopra passerebbero in blocco. Qui si guarda che
# chi ha diritto entri davvero, su una pagina per ciascun livello.
CODE=$(curl -s -k -b "${JAR[attivo]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/comandante")
verifica "il giocatore attivo apre il suo fascicolo" "200" "${CODE}"
CODE=$(curl -s -k -b "${JAR[comando]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/admin")
verifica "l'amministratore apre il pannello" "200" "${CODE}"
CODE=$(curl -s -k -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/albo")
verifica "l'ospite apre l'albo d'oro" "200" "${CODE}"
CODE=$(curl -s -k -b "${JAR[attivo]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/rapporto/${ID_PATROL}")
verifica "e il rapporto di una sua missione" "200" "${CODE}"

# --- un account non confermato non ottiene una sessione ----------------------
#
# L'identita' "pendente" di questa prova non e' mai entrata, e non per errore:
# chi non ha confermato l'indirizzo non passa nemmeno dall'accesso. Va detto,
# se no la riga "pendente respinto" di sopra proverebbe soltanto che un ospite
# e' un ospite.
CODE=$(curl -s -k -b "${JAR[pendente]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/arruolamento")
verifica "chi non ha confermato l'indirizzo non ha una sessione aperta" "200" "${CODE}"

# --- e le due cose che la matrice non vede ------------------------------------
#
# Un provvedimento deve chiudere la sessione gia' aperta, non solo impedire il
# prossimo accesso.
CODE=$(curl -s -k -b "${JAR[sospeso]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/base")
verifica "il sospeso viene buttato fuori dalla sessione che aveva aperto" "302" "${CODE}"
CODE=$(curl -s -k -b "${JAR[bandito]}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/base")
verifica "e il bandito pure" "302" "${CODE}"

# E non deve poter rientrare.
J="$(mktemp)"
TOK=$(curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" "${BASE_URL}/accesso" | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/accesso" \
  --data-urlencode "_token=${TOK}" --data-urlencode "login=${UT_BAND}" --data-urlencode "password=${PASS}"
CODE=$(curl -s -k -b "${J}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/base")
verifica "e non rientra nemmeno rifacendo l'accesso" "302" "${CODE}"
rm -f "${J}"

# --- la roba degli altri ------------------------------------------------------
#
# Il middleware 'active' dice soltanto "questo e' un giocatore": non sa di chi
# sia la missione che sta chiedendo. La proprieta' la controllano i controller,
# uno per uno, ed e' esattamente il posto dove un controllo si dimentica. Qui
# c'e' un secondo giocatore vero che prova ad aprire la roba del primo.
echo
echo "  — e la roba degli altri —"

SECONDO="prova perm due $(date +%s)"
php "${ROOT}/bin/_prova_utente.php" "${SECONDO}" "${PASS}" >/dev/null 2>&1
J2="$(mktemp)"
entra "${J2}" "${SECONDO}"
trap 'pulisci; php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${SECONDO}"'" >/dev/null 2>&1; rm -f "${J2}"' EXIT

altrui() { # descrizione, url, codice che NON deve essere 200
  local GOT
  GOT=$(curl -s -k -b "${J2}" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}$2")
  if [[ "${GOT}" == "200" ]]; then
    printf '  \033[0;31mKO\033[0m    %s (ha aperto la pagina: %s)\n' "$1" "$2"
    FALLITI=$((FALLITI+1))
  else
    printf '  \033[0;32mok\033[0m    %s  \033[0;90m%s\033[0m\n' "$1" "${GOT}"
  fi
}

altrui "la missione di un altro non si apre"            "/rapporto/${ID_PATROL}"
altrui "e nemmeno il suo giornale di bordo"             "/ktb/${ID_PATROL}/esporta"
altrui "il pannello di amministrazione resta chiuso"    "/admin"
altrui "e la scheda di un altro account pure"           "/admin/utente/${ID_UTENTE}"

# Il fascicolo pubblico invece SI: e' fatto per essere letto da tutti, ed e' la
# meta' del gioco. Quello che non deve mostrare e' l'indirizzo e-mail.
FASC=$(curl -s -k -b "${J2}" -H "Host: ${HOST_HDR}" "${BASE_URL}/profilo/${ID_CMD}")
if grep -q "Permessi Prova" <<< "${FASC}"; then
  printf '  \033[0;32mok\033[0m    il fascicolo pubblico di un altro si legge\n'
else
  printf '  \033[0;31mKO\033[0m    il fascicolo pubblico di un altro non si apre\n'; FALLITI=$((FALLITI+1))
fi
if grep -qE "esempio\.invalid|@" <<< "$(grep -o '[a-zA-Z0-9._%-]*@[a-zA-Z0-9._%-]*' <<< "${FASC}" | head -1)"; then
  printf '  \033[0;31mKO\033[0m    e mostra un indirizzo e-mail\n'; FALLITI=$((FALLITI+1))
else
  printf '  \033[0;32mok\033[0m    e non mostra nessun indirizzo e-mail\n'
fi

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'; exit 0
fi
printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
exit 1
