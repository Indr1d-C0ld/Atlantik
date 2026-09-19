#!/usr/bin/env bash
#
# Atlantik — password dimenticata.
#
#   bash tests/e2e_recupero.sh
#
# Fino all'audit del 19/09/2026 questa strada non esisteva: chi perdeva la
# password restava fuori per sempre, e in un gioco con permadeath e carriere
# lunghe mesi vuol dire perdere tutto. Lo schema la prevedeva — user_tokens ha
# il genere 'reset_password' dalla prima migrazione — e nessuno l'aveva mai
# percorsa.
#
# Quattro cose da tenere ferme:
#
#   1. si chiede il collegamento e arriva;
#   2. la risposta e' IDENTICA che l'indirizzo risulti iscritto o no, se no
#      chiunque puo' scoprire chi gioca provando indirizzi;
#   3. la password nuova entra, la vecchia no;
#   4. le sessioni gia' aperte cadono. E' la meta' che quasi sempre si
#      dimentica, ed e' quella che conta: si rifa' la password proprio perche'
#      qualcun altro e' entrato.
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_FILE="$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php"; echo App\Core\Config::sourceFile();')"
FALLITI=0

UTENTE="prova recupero $(date +%s)"
VECCHIA="rotta0909"
NUOVA="controcorrente7"
J_SESSIONE="$(mktemp)"   # la sessione aperta PRIMA del cambio
J_OSPITE="$(mktemp)"     # chi chiede e usa il collegamento
J_PROVA="$(mktemp)"

ripristina() {
  php -r '
  $f = "'"${CONFIG_FILE}"'"; $c = require $f;
  $t = @file_get_contents("/tmp/atlantik-transport-recupero.bak");
  if ($t !== false && $t !== "") { $c["mail"]["transport"] = $t; }
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");' 2>/dev/null
  rm -f /tmp/atlantik-transport-recupero.bak
}
trap 'ripristina; php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${UTENTE}"'" >/dev/null 2>&1; rm -f "${J_SESSIONE}" "${J_OSPITE}" "${J_PROVA}"' EXIT

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}
contiene() {
  if grep -q "$2" <<< "$3"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca: %s)\n' "$1" "$2"; FALLITI=$((FALLITI+1)); fi
}
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
cs() { curl -s -k -b "${J_SESSIONE}" -c "${J_SESSIONE}" -H "Host: ${HOST_HDR}" "$@"; }
co() { curl -s -k -b "${J_OSPITE}"   -c "${J_OSPITE}"   -H "Host: ${HOST_HDR}" "$@"; }

# Il freno all'accesso e' cumulativo fra le prove: venti tentativi per IP ogni
# quarto d'ora, e tutte le prove arrivano da 127.0.0.1. Frenate, le verifiche
# negative passerebbero per il motivo sbagliato.
php "${ROOT}/bin/_prova_sfrena.php" >/dev/null 2>&1

echo "Prova end-to-end del recupero password — ${BASE_URL}"

# La posta va nel diario, non sulla rete.
php -r '
$f = "'"${CONFIG_FILE}"'"; $c = require $f;
file_put_contents("/tmp/atlantik-transport-recupero.bak", $c["mail"]["transport"]);
$c["mail"]["transport"] = "log";
file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");'
sleep 3   # opcache: il processo web non vede subito la configurazione riscritta

php "${ROOT}/bin/_prova_utente.php" "${UTENTE}" "${VECCHIA}" >/dev/null 2>&1
INDIRIZZO="$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT email FROM users WHERE username = ?", [$argv[1]]); echo $u["email"] ?? "";' "${UTENTE}")"

# --- la porta si vede ---------------------------------------------------------
PAGINA=$(co "${BASE_URL}/accesso")
contiene "la pagina di accesso offre il recupero" "Password dimenticata" "${PAGINA}"

# --- una sessione aperta con la password vecchia -------------------------------
TOK=$(cs "${BASE_URL}/accesso" | token_da)
cs -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${UTENTE}" --data-urlencode "password=${VECCHIA}"
verifica "si entra con la password di adesso" "200" \
  "$(cs -o /dev/null -w '%{http_code}' "${BASE_URL}/comandante")"

# --- si chiede il collegamento -------------------------------------------------
TOK=$(co "${BASE_URL}/recupero-richiesta" | token_da)
RISPOSTA=$(co -L -X POST "${BASE_URL}/recupero-richiesta" --data-urlencode "_token=${TOK}" \
  --data-urlencode "email=${INDIRIZZO}")
contiene "la richiesta viene presa" "collegamento" "${RISPOSTA}"

php "${ROOT}/bin/console.php" mail:smista >/dev/null 2>&1
GETTONE="$(grep -oE 'recupero\?token=[a-f0-9]{64}' "${ROOT}/storage/logs/app.log" | tail -1 | cut -d= -f2)"
if [[ ${#GETTONE} -eq 64 ]]; then
  verifica "il collegamento arriva, con un gettone lungo" "64" "${#GETTONE}"
else
  verifica "il collegamento arriva, con un gettone lungo" "64" "${#GETTONE}"
fi

# --- un indirizzo che non esiste riceve la STESSA risposta ---------------------
TOK=$(co "${BASE_URL}/recupero-richiesta" | token_da)
RISPOSTA2=$(co -L -X POST "${BASE_URL}/recupero-richiesta" --data-urlencode "_token=${TOK}" \
  --data-urlencode "email=nessunoquaggiu@esempio.invalid")
ESTRATTO()  { grep -oE 'class="avviso[^"]*">[^<]*' <<< "$1" | head -1; }
if [[ "$(ESTRATTO "${RISPOSTA}")" == "$(ESTRATTO "${RISPOSTA2}")" ]]; then
  verifica "un indirizzo che non risulta riceve la stessa risposta" "si" "si"
else
  verifica "un indirizzo che non risulta riceve la stessa risposta" "si" "no"
fi
NUOVI=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT COUNT(*) n FROM mail_queue WHERE destinatario = ?", ["nessunoquaggiu@esempio.invalid"]);
echo (int) $r["n"];')
verifica "e non gli parte nessuna posta" "0" "${NUOVI}"

# --- la password nuova ---------------------------------------------------------
MODULO=$(co "${BASE_URL}/recupero?token=${GETTONE}")
contiene "il collegamento apre il modulo" "Password nuova" "${MODULO}"
TOK=$(echo "${MODULO}" | token_da)
co -o /dev/null -X POST "${BASE_URL}/recupero" --data-urlencode "_token=${TOK}" \
  --data-urlencode "token=${GETTONE}" --data-urlencode "password=${NUOVA}" \
  --data-urlencode "password_confirm=${NUOVA}"

entra() { # jar, password -> codice della pagina protetta
  local J="$1" PW="$2" T
  rm -f "$J"
  T=$(curl -s -k -c "$J" -H "Host: ${HOST_HDR}" "${BASE_URL}/accesso" | token_da)
  curl -s -k -b "$J" -c "$J" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/accesso" \
    --data-urlencode "_token=${T}" --data-urlencode "login=${UTENTE}" --data-urlencode "password=${PW}"
  curl -s -k -b "$J" -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' "${BASE_URL}/comandante"
}
verifica "con la password nuova si entra"  "200" "$(entra "${J_PROVA}" "${NUOVA}")"
verifica "con la vecchia non si entra piu'" "302" "$(entra "${J_PROVA}" "${VECCHIA}")"

# --- e la sessione che era gia' aperta -----------------------------------------
verifica "la sessione aperta prima del cambio e' caduta" "302" \
  "$(cs -o /dev/null -w '%{http_code}' "${BASE_URL}/comandante")"

# --- il gettone non si riusa ---------------------------------------------------
RIUSO=$(co "${BASE_URL}/recupero?token=${GETTONE}")
contiene "il gettone non si usa due volte" "gi&agrave; stato usato\|già stato usato" "${RIUSO}"

# --- e resta scritto nel registro ----------------------------------------------
AZIONE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT action FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1", ["auth.password_rifatta"]);
echo $r["action"] ?? "";')
verifica "il cambio resta nel registro" "auth.password_rifatta" "${AZIONE}"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
