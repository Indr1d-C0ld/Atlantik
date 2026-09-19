#!/usr/bin/env bash
#
# Atlantik — prova end-to-end del pannello di amministrazione.
#
#   bash tests/e2e_admin.sh
#
# Arruola un utente di prova, lo promuove ad amministratore, verifica il
# pannello (diagnostica del tick, bilanciamento a caldo, elenco utenti), cambia
# una chiave di configurazione e controlla che finisca nel registro azioni.
# Alla fine ripulisce tutto e ripristina il valore modificato.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Il file dei segreti non si indovina: lo dice l'applicazione, che lo cerca
# in ATLANTIK_CONFIG, in /etc/atlantik/ e infine nel progetto.
CONFIG_FILE="$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php"; echo App\Core\Config::sourceFile();')"
JAR="$(mktemp)"
USER_NAME="prova admin $(date +%s)"
USER_PASS="kommandant42"
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

trasporto_log() {
  php -r '
  $f = "'"${CONFIG_FILE}"'"; $c = require $f;
  file_put_contents("/tmp/atlantik-transport-admin.bak", $c["mail"]["transport"]);
  $c["mail"]["transport"] = "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");'
}
ripristina() {
  php -r '
  $f = "'"${CONFIG_FILE}"'"; $c = require $f;
  $c["mail"]["transport"] = trim((string) @file_get_contents("/tmp/atlantik-transport-admin.bak")) ?: "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");
  @unlink("/tmp/atlantik-transport-admin.bak");'
  php "${ROOT}/bin/console.php" config:set traffic.scala 1.0 float >/dev/null 2>&1
  php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
  rm -f "${JAR}"
}
trap ripristina EXIT

echo "Prova end-to-end amministrazione — ${BASE_URL}"
trasporto_log

# Il processo web non vede subito il file di configurazione riscritto: opcache
# lo ricontrolla ogni due secondi (opcache.revalidate_freq). Senza questa
# attesa la registrazione parte con il trasporto VECCHIO, l'e-mail non finisce
# nel diario e la prova cerca un gettone che non e' mai stato scritto. E'
# sempre stato cosi'; si notava poco perche' fra una prova e l'altra passava
# abbastanza tempo da sola.
sleep 3

TOK=$(c "${BASE_URL}/arruolamento" | token_da)
c -o /dev/null -X POST "${BASE_URL}/arruolamento" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=admin_$(date +%s)@esempio.invalid" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}"

# Prima della promozione il pannello dev'essere chiuso.
php "${ROOT}/bin/console.php" user:verify "${USER_NAME}" >/dev/null
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}"
CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/admin")
verifica "pannello negato al giocatore comune" "403" "${CODE}"

# Promozione e nuovo accesso.
php "${ROOT}/bin/console.php" user:admin "${USER_NAME}" >/dev/null
PAGINA=$(c "${BASE_URL}/admin")
contiene "pannello raggiungibile dall'amministratore" "Bilanciamento a caldo" "${PAGINA}"
contiene "diagnostica del battito" "Battiti recenti" "${PAGINA}"
contiene "elenco degli utenti" "${USER_NAME}" "${PAGINA}"

# Modifica di una chiave di bilanciamento.
TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/config" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chiave=traffic.scala" --data-urlencode "valore=1.1"
VALORE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php"; echo App\Core\GameConfig::get("traffic.scala");')
verifica "chiave di bilanciamento modificata a caldo" "1.1" "${VALORE}"

AZIONE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT action FROM audit_log ORDER BY id DESC LIMIT 1"); echo $r["action"] ?? "";')
verifica "modifica registrata nel registro azioni" "admin.config" "${AZIONE}"

# Una chiave inventata dev'essere respinta.
PAGINA=$(c "${BASE_URL}/admin"); TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/config" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chiave=chiave.inventata" --data-urlencode "valore=9"
PAGINA=$(c "${BASE_URL}/admin")
contiene "chiave sconosciuta respinta" "Chiave sconosciuta" "${PAGINA}"

# --- Pannello avanzato ------------------------------------------------------
UTENTE_ID=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]); echo (int) ($r["id"] ?? 0);' "${USER_NAME}")

PAGINA=$(c "${BASE_URL}/admin/utenti")
contiene "elenco utenti avanzato" "${USER_NAME}" "${PAGINA}"
contiene "elenco utenti: conteggio per stato" "attivi" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/admin/utenti?cerca=nessunoconquestonome")
contiene "la ricerca filtra davvero" "Nessun account risponde" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/admin/utente/${UTENTE_ID}")
contiene "scheda account raggiungibile" "${USER_NAME}" "${PAGINA}"

# Nota interna.
TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/utente/nota" --data-urlencode "_token=${TOK}" \
  --data-urlencode "utente=${UTENTE_ID}" --data-urlencode "nota=annotazione di prova"
NOTA=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT note_admin FROM users WHERE id = ?", [(int) $argv[1]]); echo (string) ($r["note_admin"] ?? "");' "${UTENTE_ID}")
verifica "nota interna salvata" "annotazione di prova" "${NOTA}"

PAGINA=$(c "${BASE_URL}/admin/accessi")
contiene "pagina accessi" "Accessi e origine" "${PAGINA}"
contiene "accessi: niente geolocalizzazione, dichiarato" "geolocalizzazione" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/admin/classifica")
contiene "classifica raggiungibile" "Classifica" "${PAGINA}"
contiene "classifica: rendimento per siluro" "GRT/siluro" "${PAGINA}"

# Comunicazioni: bacheca e posta.
PAGINA=$(c "${BASE_URL}/admin/comunicazioni")
contiene "pagina comunicazioni" "Comunicazioni" "${PAGINA}"
TOK=$(echo "${PAGINA}" | token_da)
PRIMA=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
echo (int) App\Core\Database::first("SELECT COUNT(*) n FROM bacheca")["n"];')
c -o /dev/null -X POST "${BASE_URL}/admin/comunicazioni" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=bacheca" --data-urlencode "testo=prova di comunicato"
DOPO=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
echo (int) App\Core\Database::first("SELECT COUNT(*) n FROM bacheca")["n"];')
verifica "comunicato affisso in bacheca" "$((PRIMA+1))" "${DOPO}"

PAGINA=$(c "${BASE_URL}/admin/comunicazioni"); TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/comunicazioni" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=posta" --data-urlencode "destinatario=${UTENTE_ID}" \
  --data-urlencode "oggetto=Prova" --data-urlencode "testo=corpo di prova"
INCODA=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT email FROM users WHERE id = ?", [(int) $argv[1]]);
echo (int) App\Core\Database::first("SELECT COUNT(*) n FROM mail_queue WHERE destinatario = ? AND genere = ?", [$u["email"], "comunicazione"])["n"];' "${UTENTE_ID}")
verifica "comunicazione accodata, non spedita subito" "1" "${INCODA}"

# Una comunicazione senza testo non deve partire.
PAGINA=$(c "${BASE_URL}/admin/comunicazioni"); TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/comunicazioni" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=posta" --data-urlencode "destinatario=${UTENTE_ID}" \
  --data-urlencode "oggetto=Vuota" --data-urlencode "testo="
PAGINA=$(c "${BASE_URL}/admin/comunicazioni")
contiene "comunicazione vuota respinta" "vuoto" "${PAGINA}"

# --- fascicoli altrui ---------------------------------------------------------
CMD=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$c = App\Core\Database::first("SELECT id FROM commanders ORDER BY id DESC LIMIT 1"); echo (int) ($c["id"] ?? 0);')
if [[ "${CMD}" -gt 0 ]]; then
  PAGINA=$(c "${BASE_URL}/admin/comandante/${CMD}")
  contiene "l'amministratore apre il fascicolo di un altro" "Amministrazione" "${PAGINA}"
  contiene "e puo' rinominarlo" "Rinomina" "${PAGINA}"
  TOKC=$(echo "${PAGINA}" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/comandante/nota" --data-urlencode "_token=${TOKC}"     --data-urlencode "comandante=${CMD}" --data-urlencode "nota=annotazione del comando"
  NOTA=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  $r = App\Core\Database::first("SELECT nota_pubblica FROM commanders WHERE id = ?", [(int) $argv[1]]);
  echo (string) ($r["nota_pubblica"] ?? "");' "${CMD}")
  verifica "l'amministratore scrive sul fascicolo di un altro" "annotazione del comando" "${NOTA}"
  AZIONE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  $r = App\Core\Database::first("SELECT action FROM audit_log ORDER BY id DESC LIMIT 1"); echo $r["action"] ?? "";')
  verifica "e l'intervento resta nel registro" "admin.profilo_nota" "${AZIONE}"
  php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  App\Core\Database::run("UPDATE commanders SET nota_pubblica = NULL WHERE id = ?", [(int) $argv[1]]);' "${CMD}"
fi

# --- I battiti si leggono in italiano, non in JSON --------------------------
#
# La colonna del lavoro stampava la riga grezza del battito, tagliata a
# centoventi caratteri: una parola sola senza spazi, che in una tabella vuol
# dire una colonna che sfonda il pannello. E per sapere se un battito avesse
# fatto qualcosa bisognava leggere una ventina di zeri.

PANNELLO=$(c "${BASE_URL}/admin")
CORPO=$(sed 's/title="[^"]*"//g' <<< "${PANNELLO}")
# Si cerca "aree_assegnate": e' una chiave della riga grezza e non compare in
# nessuna delle diciture italiane. Cercare invece '"comunicati":' non
# funzionava: e() trasforma le virgolette in &quot; e il confronto passava
# sempre, anche col difetto rimesso.
if grep -q 'aree_assegnate' <<< "${CORPO}"; then
  verifica "il lavoro del battito non e' JSON a video" "si" "no"
else
  verifica "il lavoro del battito non e' JSON a video" "si" "si"
fi
if grep -qE 'niente da fare|nave salpata|navi salpate|battelli avanzati|convoglio salpato' <<< "${CORPO}"; then
  verifica "ed e' detto in italiano" "si" "si"
else
  verifica "ed e' detto in italiano" "si" "no"
fi
contiene "la riga grezza resta a portata, nel suggerimento" 'title="{' "${PANNELLO}"

# --- Il comunicato in mensa lo firma il comando, non l'account --------------
#
# L'amministratore non ha un comandante, e la firma in bacheca cadeva sul suo
# nome utente: un ordine del giorno del BdU che risultava scritto da "admin".

TOK=$(echo "$(c "${BASE_URL}/admin/comunicazioni")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/admin/comunicazioni" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=bacheca" --data-urlencode "testo=Prova: ordine del giorno."
MENSA=$(c "${BASE_URL}/bacheca")
grep -q 'class="comunicato"' <<< "${MENSA}" \
  && verifica "il comunicato si distingue dalle chiacchiere" "si" "si" \
  || verifica "il comunicato si distingue dalle chiacchiere" "si" "no"
grep -q '<span class="quadrat">Comando</span>' <<< "${MENSA}" \
  && verifica "ed e' firmato dal comando" "si" "si" \
  || verifica "ed e' firmato dal comando" "si" "no"
grep -q "<span class=\"quadrat\">${USER_NAME}</span>" <<< "${MENSA}" \
  && verifica "e non dal nome dell'account che l'ha scritto" "si" "no" \
  || verifica "e non dal nome dell'account che l'ha scritto" "si" "si"

php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
App\Core\Database::run("DELETE FROM bacheca WHERE testo LIKE ?", ["Prova: ordine del giorno.%"]);'

# --- Cancellare un account, che non si disfa --------------------------------
#
# Sospendere e revocare chiudono la porta; cancellare porta via. Qui si guarda
# che i paletti tengano prima di guardare che la cancellazione funzioni: un
# pulsante che cancella per sbaglio e' peggio di un pulsante che non c'e'.

VITTIMA="prova bersaglio $(date +%s)"
php "${ROOT}/bin/_prova_utente.php" "${VITTIMA}" "rotta0909" >/dev/null 2>&1
VITTIMA_ID=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]); echo (int) ($r["id"] ?? 0);' "${VITTIMA}")

esiste() { php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT COUNT(*) n FROM users WHERE id = ?", [(int) $argv[1]]);
echo (int) $r["n"] > 0 ? "si" : "no";' "$1"; }

if [[ "${VITTIMA_ID}" -gt 0 ]]; then
  PAGINA=$(c "${BASE_URL}/admin/utente/${VITTIMA_ID}")
  contiene "la scheda offre la cancellazione" "Cancella l" "${PAGINA}"
  contiene "e dice che non si disfa" "non si disfa" "${PAGINA}"
  contiene "e chiede di scrivere il nome" "Per confermare" "${PAGINA}"

  # Senza conferma: non si cancella niente.
  TOK=$(echo "${PAGINA}" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/admin/utente" --data-urlencode "_token=${TOK}" \
    --data-urlencode "utente=${VITTIMA_ID}" --data-urlencode "azione=cancella" --data-urlencode "conferma="
  verifica "senza conferma l'account resta" "si" "$(esiste "${VITTIMA_ID}")"

  # Col nome sbagliato: nemmeno.
  TOK=$(echo "$(c "${BASE_URL}/admin/utente/${VITTIMA_ID}")" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/admin/utente" --data-urlencode "_token=${TOK}" \
    --data-urlencode "utente=${VITTIMA_ID}" --data-urlencode "azione=cancella" \
    --data-urlencode "conferma=un nome qualunque"
  verifica "col nome sbagliato l'account resta" "si" "$(esiste "${VITTIMA_ID}")"

  # L'amministratore non cancella se stesso.
  TOK=$(echo "$(c "${BASE_URL}/admin/utente/${UTENTE_ID}")" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/admin/utente" --data-urlencode "_token=${TOK}" \
    --data-urlencode "utente=${UTENTE_ID}" --data-urlencode "azione=cancella" \
    --data-urlencode "conferma=${USER_NAME}"
  verifica "l'amministratore non cancella se stesso" "si" "$(esiste "${UTENTE_ID}")"

  # Col nome giusto, invece, se ne va davvero — e si porta dietro tutto.
  TOK=$(echo "$(c "${BASE_URL}/admin/utente/${VITTIMA_ID}")" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/admin/utente" --data-urlencode "_token=${TOK}" \
    --data-urlencode "utente=${VITTIMA_ID}" --data-urlencode "azione=cancella" \
    --data-urlencode "conferma=${VITTIMA}"
  verifica "col nome esatto l'account se ne va" "no" "$(esiste "${VITTIMA_ID}")"

  RESTI=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  $id = (int) $argv[1]; $n = 0;
  foreach (["commanders", "boats", "patrols", "achievements", "bacheca"] as $t) {
    $r = App\Core\Database::first("SELECT COUNT(*) n FROM {$t} WHERE user_id = ?", [$id]);
    $n += (int) $r["n"];
  }
  echo $n;' "${VITTIMA_ID}")
  verifica "non restano comandanti, battelli ne' missioni" "0" "${RESTI}"

  AZIONE=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  $r = App\Core\Database::first("SELECT action FROM audit_log WHERE target_id = ? ORDER BY id DESC LIMIT 1", [(int) $argv[1]]);
  echo $r["action"] ?? "";' "${VITTIMA_ID}")
  verifica "la cancellazione resta nel registro" "admin.user_cancellato" "${AZIONE}"
fi

php "${ROOT}/bin/_cleanup_test_user.php" "${VITTIMA}" >/dev/null 2>&1

# Il paletto sull'ultimo amministratore non si puo' esercitare qui: per farlo
# bisognerebbe togliere il ruolo all'unico amministratore vero di questo mondo.
# Si controlla allora l'invariante che quel paletto esiste per difendere.
AMMINISTRATORI=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT COUNT(*) n FROM users WHERE role = \"admin\""); echo (int) $r["n"];')
if [[ "${AMMINISTRATORI}" -ge 1 ]]; then
  verifica "resta sempre almeno un amministratore" "si" "si"
else
  verifica "resta sempre almeno un amministratore" "si" "no"
fi

# Le rotte nuove sono chiuse a chi non e' amministratore.
php "${ROOT}/bin/console.php" user:unadmin "${USER_NAME}" >/dev/null 2>&1
for R in utenti accessi comunicazioni classifica "comandante/1"; do
  CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/admin/${R}")
  verifica "rotta /admin/${R} chiusa al giocatore comune" "403" "${CODE}"
done
php "${ROOT}/bin/console.php" user:admin "${USER_NAME}" >/dev/null 2>&1


echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
