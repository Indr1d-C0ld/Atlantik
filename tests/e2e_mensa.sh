#!/usr/bin/env bash
#
# Atlantik — la mensa ufficiali: chi la legge, chi ci scrive, chi puo' togliere.
#
#   bash tests/e2e_mensa.sh
#
# Tre regole, e nascono tutte dallo stesso fatto: la mensa e' un posto fisico.
# La 11. U-Flottille mangia a Bergen e la 2./10. a Lorient, e quello che si
# dice a Bergen a Lorient non si sente. Il comando invece parla a tutti.
#
#   1. si legge la bacheca della PROPRIA flottiglia, piu' i comunicati del
#      comando;
#   2. si scrive solo da terra;
#   3. si toglie quello che si e' scritto; l'amministratore toglie qualunque
#      cosa, e resta nel registro.
#
# La prova comincia da un fatto che per mesi non si vedeva: la base scelta
# alla creazione del comandante veniva ignorata quando si assegnava il
# battello, e tutti finivano a Lorient. Con tutti nella stessa flottiglia, il
# filtro non filtrerebbe niente e questa prova passerebbe per sbaglio.
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FALLITI=0
PASSWORD="rotta0909"

UNO="prova mensa uno $(date +%s)"
DUE="prova mensa due $(date +%s)"
CAPO="prova mensa capo $(date +%s)"
J1="$(mktemp)"; J2="$(mktemp)"; J3="$(mktemp)"

trap 'for U in "${UNO}" "${DUE}" "${CAPO}"; do php "${ROOT}/bin/_cleanup_test_user.php" "${U}" >/dev/null 2>&1; done; rm -f "${J1}" "${J2}" "${J3}"' EXIT

c1() { curl -s -k -b "${J1}" -c "${J1}" -H "Host: ${HOST_HDR}" "$@"; }
c2() { curl -s -k -b "${J2}" -c "${J2}" -H "Host: ${HOST_HDR}" "$@"; }
c3() { curl -s -k -b "${J3}" -c "${J3}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}
contiene() {
  if grep -q "$2" <<< "$3"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca: %s)\n' "$1" "$2"; FALLITI=$((FALLITI+1)); fi
}
non_contiene() {
  if grep -q "$2" <<< "$3"; then printf '  \033[0;31mKO\033[0m    %s (si vede: %s)\n' "$1" "$2"; FALLITI=$((FALLITI+1))
  else printf '  \033[0;32mok\033[0m    %s\n' "$1"; fi
}
sql1() { php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first($argv[1], array_slice($argv, 2)); echo $r === null ? "" : (string) reset($r);' "$@"; }

echo "Prova end-to-end della mensa ufficiali — ${BASE_URL}"

# --- Tre comandanti, tre basi diverse ----------------------------------------
apri() { # jar, utente, base, nome del comandante
  local J="$1" U="$2" B="$3" N="$4" T
  php "${ROOT}/bin/_prova_utente.php" "${U}" "${PASSWORD}" >/dev/null 2>&1 || return 1
  T=$(curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" "${BASE_URL}/accesso" | token_da)
  curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/accesso" \
    --data-urlencode "_token=${T}" --data-urlencode "login=${U}" --data-urlencode "password=${PASSWORD}"
  T=$(curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -L "${BASE_URL}/comandante" | token_da)
  curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -o /dev/null -X POST "${BASE_URL}/comandante/crea" \
    -F "_token=${T}" -F "nome=${N}" -F "nato_il=22/05/1913" -F "nato_a=Kiel" -F "base=${B}"
  # Il battello si assegna alla prima visita in base.
  curl -s -k -b "${J}" -c "${J}" -H "Host: ${HOST_HDR}" -o /dev/null "${BASE_URL}/base"
}

apri "${J1}" "${UNO}"  lorient "Otto Lorient $(date +%s | tail -c 4)"
apri "${J2}" "${DUE}"  bergen  "Hans Bergen $(date +%s | tail -c 4)"
apri "${J3}" "${CAPO}" brest   "Karl Brest $(date +%s | tail -c 4)"
php "${ROOT}/bin/console.php" user:admin "${CAPO}" >/dev/null 2>&1

FLOT1=$(sql1 'SELECT b.flotilla FROM boats b JOIN users u ON u.id = b.user_id WHERE u.username = ?' "${UNO}")
FLOT2=$(sql1 'SELECT b.flotilla FROM boats b JOIN users u ON u.id = b.user_id WHERE u.username = ?' "${DUE}")
PORTO2=$(sql1 'SELECT b.home_port_key FROM boats b JOIN users u ON u.id = b.user_id WHERE u.username = ?' "${DUE}")

verifica "il battello nasce alla base scelta dal comandante" "bergen" "${PORTO2}"
verifica "e con la flottiglia di quella base" "11. U-Flottille" "${FLOT2}"
if [[ "${FLOT1}" != "${FLOT2}" ]]; then
  verifica "i due comandanti sono in flottiglie diverse" "si" "si"
else
  verifica "i due comandanti sono in flottiglie diverse" "si" "no"
fi

# --- Si scrive, e si legge la propria mensa ----------------------------------
TOK=$(c1 "${BASE_URL}/bacheca" | token_da)
c1 -o /dev/null -X POST "${BASE_URL}/bacheca" --data-urlencode "_token=${TOK}" \
  --data-urlencode "testo=PAROLA-DI-LORIENT"
TOK=$(c2 "${BASE_URL}/bacheca" | token_da)
c2 -o /dev/null -X POST "${BASE_URL}/bacheca" --data-urlencode "_token=${TOK}" \
  --data-urlencode "testo=PAROLA-DI-BERGEN"
TOK=$(c3 "${BASE_URL}/admin/comunicazioni" | token_da)
c3 -o /dev/null -X POST "${BASE_URL}/admin/comunicazioni" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=bacheca" --data-urlencode "testo=PAROLA-DEL-COMANDO"

MENSA1=$(c1 "${BASE_URL}/bacheca")
MENSA2=$(c2 "${BASE_URL}/bacheca")

contiene     "la mensa porta il nome della flottiglia" "Bacheca della ${FLOT1}" "${MENSA1}"
contiene     "ciascuno legge quello che ha scritto"    "PAROLA-DI-LORIENT"      "${MENSA1}"
non_contiene "e non quello dell'altra flottiglia"      "PAROLA-DI-BERGEN"       "${MENSA1}"
contiene     "l'altro legge il proprio"                "PAROLA-DI-BERGEN"       "${MENSA2}"
non_contiene "e non quello della prima"                "PAROLA-DI-LORIENT"      "${MENSA2}"
contiene     "il comunicato del comando arriva qui"    "PAROLA-DEL-COMANDO"     "${MENSA1}"
contiene     "e arriva anche di la'"                   "PAROLA-DEL-COMANDO"     "${MENSA2}"

# --- Si scrive solo da terra -------------------------------------------------
TOK=$(c1 "${BASE_URL}/base" | token_da)
c1 -o /dev/null -X POST "${BASE_URL}/uscita" --data-urlencode "_token=${TOK}"
TOK=$(c1 "${BASE_URL}/bacheca" | token_da)
c1 -o /dev/null -X POST "${BASE_URL}/bacheca" --data-urlencode "_token=${TOK}" \
  --data-urlencode "testo=PAROLA-DAL-MARE"
DAL_MARE=$(sql1 'SELECT COUNT(*) FROM bacheca WHERE testo = ?' 'PAROLA-DAL-MARE')
verifica "dal mare alla bacheca non si scrive" "0" "${DAL_MARE}"

# --- Togliere un messaggio ---------------------------------------------------
ID1=$(sql1 'SELECT id FROM bacheca WHERE testo = ?' 'PAROLA-DI-LORIENT')
ID2=$(sql1 'SELECT id FROM bacheca WHERE testo = ?' 'PAROLA-DI-BERGEN')

# Un altro giocatore non tocca il messaggio di nessuno.
TOK=$(c2 "${BASE_URL}/bacheca" | token_da)
c2 -o /dev/null -X POST "${BASE_URL}/bacheca/rimuovi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "messaggio=${ID1}"
verifica "un altro giocatore non toglie il messaggio altrui" "1" \
  "$(sql1 'SELECT COUNT(*) FROM bacheca WHERE id = ?' "${ID1}")"

# Chi l'ha scritto se lo riprende.
TOK=$(c1 "${BASE_URL}/bacheca" | token_da)
c1 -o /dev/null -X POST "${BASE_URL}/bacheca/rimuovi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "messaggio=${ID1}"
verifica "chi l'ha scritto lo toglie" "0" \
  "$(sql1 'SELECT COUNT(*) FROM bacheca WHERE id = ?' "${ID1}")"

# L'amministratore modera, e resta scritto.
TOK=$(c3 "${BASE_URL}/admin/comunicazioni" | token_da)
c3 -o /dev/null -X POST "${BASE_URL}/bacheca/rimuovi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "messaggio=${ID2}" --data-urlencode "dove=admin"
verifica "l'amministratore toglie anche quello degli altri" "0" \
  "$(sql1 'SELECT COUNT(*) FROM bacheca WHERE id = ?' "${ID2}")"
verifica "e la moderazione resta nel registro" "admin.bacheca_rimossa" \
  "$(sql1 'SELECT action FROM audit_log WHERE target_type = ? ORDER BY id DESC LIMIT 1' 'bacheca')"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
