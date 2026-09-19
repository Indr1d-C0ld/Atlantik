#!/usr/bin/env bash
#
# Atlantik — prova end-to-end del giro di autenticazione, attraverso Apache.
#
#   bash tests/e2e_auth.sh
#
# Cosa fa: arruola un utente di prova, legge il gettone di verifica dal log
# (il trasporto e' forzato a 'log' per la durata della prova), conferma
# l'indirizzo, accede, apre la base, esce. Alla fine cancella l'utente di prova
# e ripristina il trasporto e-mail. Non invia nessuna e-mail reale.
#
set -uo pipefail

# NOTA sul modo in cui si controllano le pagine.
#
# Si usa `grep -q PAT <<< "${PAGINA}"`, non `echo "${PAGINA}" | grep -q PAT`.
# Con pipefail attivo la seconda forma e' una trappola: grep -q esce appena
# trova, echo si prende un SIGPIPE e la pipeline restituisce 141 anche quando
# la stringa c'era. Finche' le pagine stavano nel buffer della pipe non si
# vedeva; il giorno in cui una pagina e' arrivata a duecento kilobyte la prova
# ha cominciato a fallire senza motivo apparente.

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Il file dei segreti non si indovina: lo dice l'applicazione, che lo cerca
# in ATLANTIK_CONFIG, in /etc/atlantik/ e infine nel progetto.
CONFIG_FILE="$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php"; echo App\Core\Config::sourceFile();')"
LOG="${ROOT}/storage/logs/app.log"
JAR="$(mktemp)"
# Nome con spazio (stile "Indrid Cold") e password al minimo consentito: la prova
# passa per gli stessi casi limite che useranno i giocatori.
USER_NAME="prova Indrid $(date +%s)"
USER_MAIL="prova_$(date +%s)@esempio.invalid"
USER_PASS="rotta0909"
FALLITI=0

trap 'rm -f "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

verifica() { # nome, atteso, ottenuto
  if [[ "$2" == "$3" ]]; then
    printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else
    printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"
    FALLITI=$((FALLITI + 1))
  fi
}

echo "Prova end-to-end autenticazione — ${BASE_URL}"
echo "  utente di prova: ${USER_NAME}"

# Trasporto e-mail forzato a 'log' per la durata della prova.
php -r '
$f = "'"${CONFIG_FILE}"'";
$c = require $f;
file_put_contents("/tmp/atlantik-transport.bak", $c["mail"]["transport"]);
$c["mail"]["transport"] = "log";
file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");
' || { echo "impossibile forzare il trasporto a log"; exit 1; }

# Il processo web non vede subito il file di configurazione riscritto: opcache
# lo ricontrolla ogni due secondi (opcache.revalidate_freq). Senza questa
# attesa la registrazione parte con il trasporto VECCHIO, l'e-mail non finisce
# nel diario e la prova cerca un gettone che non e' mai stato scritto. E'
# sempre stato cosi'; si notava poco perche' fra una prova e l'altra passava
# abbastanza tempo da sola.
sleep 3

ripristina() {
  php -r '
  $f = "'"${CONFIG_FILE}"'";
  $c = require $f;
  $c["mail"]["transport"] = trim((string) @file_get_contents("/tmp/atlantik-transport.bak")) ?: "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");
  @unlink("/tmp/atlantik-transport.bak");
  '
}
trap 'ripristina; rm -f "${JAR}"' EXIT

LOG_PRIMA=$(wc -l < "${LOG}" 2>/dev/null || echo 0)

# 1. Pagina di arruolamento + gettone CSRF
TOK=$(c "${BASE_URL}/arruolamento" | token_da)
[[ -n "${TOK}" ]] && verifica "gettone CSRF presente nel modulo" "si" "si" || verifica "gettone CSRF presente nel modulo" "si" "no"

# 2. CSRF mancante => rifiuto
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/arruolamento" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}")
verifica "POST senza CSRF respinto" "400" "${CODE}"

# 3. Arruolamento vero
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/arruolamento" \
  --data-urlencode "_token=${TOK}" --data-urlencode "username=${USER_NAME}" \
  --data-urlencode "email=${USER_MAIL}" --data-urlencode "password=${USER_PASS}" \
  --data-urlencode "password_confirm=${USER_PASS}")
verifica "arruolamento accettato (redirect)" "302" "${CODE}"

STATO=$(mariadb -N -B --skip-ssl -u"$(php -r '$c=require "'"${CONFIG_FILE}"'"; echo $c["db"]["user"];')" \
        -p"$(php -r '$c=require "'"${CONFIG_FILE}"'"; echo $c["db"]["pass"];')" \
        "$(php -r '$c=require "'"${CONFIG_FILE}"'"; echo $c["db"]["name"];')" \
        -e "SELECT status FROM users WHERE username='${USER_NAME}'" 2>/dev/null)
verifica "account creato in stato pending" "pending" "${STATO}"

# Il nome con spazio deve essere arrivato in tabella intatto.
verifica "nome utente con spazio accettato" "si" "$([[ "${USER_NAME}" == *" "* && -n "${STATO}" ]] && echo si || echo no)"

# 3b. Password sotto il minimo respinta
TOK=$(c "${BASE_URL}/arruolamento" | token_da)
c -o /dev/null -X POST "${BASE_URL}/arruolamento" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=prova corta $(date +%s)" --data-urlencode "email=corta_$(date +%s)@esempio.invalid" \
  --data-urlencode "password=otto8car" --data-urlencode "password_confirm=otto8car"
PAGINA=$(c "${BASE_URL}/arruolamento")
grep -q "almeno 9 caratteri" <<< "${PAGINA}" \
  && verifica "password di 8 caratteri respinta" "si" "si" \
  || verifica "password di 8 caratteri respinta" "si" "no"

# 4. Accesso negato prima della conferma
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}"
CODE=$(c -o /dev/null -w '%{http_code}' -L "${BASE_URL}/base")
verifica "base ancora irraggiungibile senza conferma" "200" "${CODE}"
PAGINA=$(c -L "${BASE_URL}/base")
grep -q "Controllo accessi" <<< "${PAGINA}" \
  && verifica "reindirizzato all'accesso" "si" "si" \
  || verifica "reindirizzato all'accesso" "si" "no"

# 5. Gettone di verifica dal log
#
# La posta non parte piu' al momento dell'iscrizione: da quando c'e' la coda
# (rilievo A6) viene accodata e la spedisce il battito, qualche messaggio per
# volta, per rispettare il tetto giornaliero del provider. Qui la coda la si
# smista a mano, altrimenti la prova aspetterebbe il cron del minuto e
# passerebbe o no secondo il momento in cui la si lancia.
php "${ROOT}/bin/console.php" mail:smista >/dev/null 2>&1

LINK=$(tail -n +"$((LOG_PRIMA + 1))" "${LOG}" | grep -o 'verifica?token=[0-9a-f]\{64\}' | head -1)
[[ -n "${LINK}" ]] && verifica "collegamento di verifica generato" "si" "si" || verifica "collegamento di verifica generato" "si" "no"

PAGINA=$(c "${BASE_URL}/${LINK}")
grep -q "Arruolamento accettato" <<< "${PAGINA}" \
  && verifica "verifica dell'indirizzo riuscita" "si" "si" \
  || verifica "verifica dell'indirizzo riuscita" "si" "no"

# 6. Gettone non riutilizzabile
PAGINA=$(c "${BASE_URL}/${LINK}")
grep -q "Arruolamento accettato" <<< "${PAGINA}" \
  && verifica "doppio clic sul collegamento non da' errore" "si" "si" \
  || verifica "doppio clic sul collegamento non da' errore" "si" "no"

# 7. Accesso e base
TOK=$(c "${BASE_URL}/accesso" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}")
verifica "accesso riuscito (redirect)" "302" "${CODE}"

# Entrati, la prima cosa che si incontra e' la creazione del comandante: senza
# di lui non c'e' flottiglia che tenga.
PAGINA=$(c -L "${BASE_URL}/base")
grep -q "Il tuo comandante" <<< "${PAGINA}" \
  && verifica "area di gioco raggiungibile (creazione del comandante)" "si" "si" \
  || verifica "area di gioco raggiungibile" "si" "no"

# 8. Uscita
PAGINA=$(c -L "${BASE_URL}/comandante")
TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/esci" --data-urlencode "_token=${TOK}"
PAGINA=$(c -L "${BASE_URL}/comandante")
grep -q "Controllo accessi" <<< "${PAGINA}" \
  && verifica "uscita effettuata" "si" "si" \
  || verifica "uscita effettuata" "si" "no"

# --- Pulizia -----------------------------------------------------------------
php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else
  printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
  exit 1
fi
