#!/usr/bin/env bash
#
# Atlantik — congedo dal servizio attivo, attraverso Apache.
#
#   bash tests/e2e_congedo.sh
#
# Il mestiere aveva due uscite, e il gioco ne offriva una sola. Chi
# sopravviveva abbastanza veniva tolto dal mare e mandato a insegnare, a
# comandare una flottiglia, a lavorare al BdU: di quelli che sono diventati un
# nome, piu' di uno e' finito cosi'.
#
# In Atlantik si poteva solo morire. Comandante::congeda() era scritta dal
# primo giorno e non la chiamava nessuno: nessuna rotta, nessun pulsante,
# nessuna pagina. Lo stato 'congedato' non poteva esistere — mentre l'albo
# d'oro prometteva in testa "i comandanti che non sono tornati, e quelli che si
# sono congedati".
#
# La prova passa dalla porta principale: pagina, modulo, conferma col nome.
# Alla fine cancella tutto quello che ha creato. Non invia e-mail.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_FILE="$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php"; echo App\Core\Config::sourceFile();')"
JAR="$(mktemp)"
USER_NAME="prova congedo $(date +%s)"
USER_MAIL="prova_cong_$(date +%s)@esempio.invalid"
USER_PASS="heimkehr77"
NOME_CMD="Wilhelm Ohlendorf"
FALLITI=0

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}
contiene() {
  if grep -q "$2" <<< "$3"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca "%s")\n' "$1" "$2"; FALLITI=$((FALLITI+1)); fi
}

php "${ROOT}/bin/_prova_sfrena.php" >/dev/null 2>&1

echo "Prova end-to-end del congedo — ${BASE_URL}"
echo "  comandante di prova: ${NOME_CMD}"

php "${ROOT}/bin/console.php" world:init >/dev/null

php -r '
$f="'"${CONFIG_FILE}"'"; $c=require $f;
file_put_contents("/tmp/atlantik-transport-cong.bak", $c["mail"]["transport"]);
$c["mail"]["transport"]="log";
file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($c,true).";\n");'
ripristina() {
  php -r '
  $f="'"${CONFIG_FILE}"'"; $c=require $f;
  $c["mail"]["transport"]=trim((string)@file_get_contents("/tmp/atlantik-transport-cong.bak")) ?: "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($c,true).";\n");
  @unlink("/tmp/atlantik-transport-cong.bak");'
}
trap 'ripristina; php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${USER_NAME}"'" >/dev/null 2>&1; rm -f "${JAR}"' EXIT

TOK=$(c "${BASE_URL}/arruolamento" | token_da)
c -o /dev/null -X POST "${BASE_URL}/arruolamento" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}"
php "${ROOT}/bin/console.php" user:verify "${USER_NAME}" >/dev/null

TOK=$(c "${BASE_URL}/accesso" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}")
verifica "accesso" "302" "${CODE}"

PAGINA=$(c -L "${BASE_URL}/comandante")
TOKC=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/comandante/crea" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "nome=${NOME_CMD}" \
  --data-urlencode "nato_il=1911-09-04" --data-urlencode "nato_a=Flensburg" --data-urlencode "base=lorient"

PAGINA=$(c "${BASE_URL}/comandante")
contiene "il comandante e' in servizio" "${NOME_CMD}" "${PAGINA}"
contiene "il fascicolo offre il congedo" "Congedo dal servizio attivo" "${PAGINA}"

# --- il nome sbagliato non basta ---------------------------------------------
TOKC=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/comandante/congedo" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "conferma=qualcun altro"
PAGINA=$(c "${BASE_URL}/comandante")
contiene "col nome sbagliato non si congeda nessuno" "${NOME_CMD}" "${PAGINA}"
contiene "e lo dice" "nome esatto" "${PAGINA}"

# --- in mare non si lascia il comando ----------------------------------------
php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", ["'"${USER_NAME}"'"]);
App\Core\Database::run("UPDATE boats SET state = \"mare\" WHERE user_id = ?", [(int) $u["id"]]);'
TOKC=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/comandante/congedo" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "conferma=${NOME_CMD}"
PAGINA=$(c "${BASE_URL}/comandante")
contiene "in mare il comando non si lascia" "${NOME_CMD}" "${PAGINA}"
contiene "e lo dice" "banchina" "${PAGINA}"

php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", ["'"${USER_NAME}"'"]);
App\Core\Database::run("UPDATE boats SET state = \"base\" WHERE user_id = ?", [(int) $u["id"]]);'

# --- il congedo vero ----------------------------------------------------------
PAGINA=$(c "${BASE_URL}/comandante")
TOKC=$(echo "${PAGINA}" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/comandante/congedo" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "conferma=${NOME_CMD}")
verifica "il congedo si accetta" "302" "${CODE}"

STATO=$(php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", ["'"${USER_NAME}"'"]);
$c = App\Core\Database::first("SELECT stato FROM commanders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [(int) $u["id"]]);
echo $c["stato"] ?? "nessuno";')
verifica "il fascicolo si chiude come congedato" "congedato" "${STATO}"

PAGINA=$(c "${BASE_URL}/comandante")
contiene "si riparte dalla creazione" "Il tuo comandante" "${PAGINA}"

ALBO=$(c "${BASE_URL}/albo")
contiene "e il congedato resta nell'albo d'oro" "${NOME_CMD}" "${ALBO}"

EREDITA=$(php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", ["'"${USER_NAME}"'"]);
echo App\Game\Comandante::eredita((int) $u["id"]) >= 0 ? "si" : "no";')
verifica "il successore eredita da chi si e' congedato" "si" "${EREDITA}"

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'; exit 0
fi
printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
exit 1
