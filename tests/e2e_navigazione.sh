#!/usr/bin/env bash
#
# Atlantik — prova end-to-end della navigazione (F1), attraverso Apache.
#
#   bash tests/e2e_navigazione.sh
#
# Arruola un comandante di prova, lo fa uscire da Lorient, gli traccia una rotta,
# impartisce ordini di macchina e quota, fa scorrere il tempo di gioco e verifica
# che il battello si sia mosso, abbia consumato e abbia scritto il giornale.
# Alla fine cancella tutto quello che ha creato. Non invia e-mail.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JAR="$(mktemp)"
USER_NAME="prova Kaleun $(date +%s)"
USER_MAIL="prova_nav_$(date +%s)@esempio.invalid"
USER_PASS="tauchen909"
FALLITI=0

trap 'rm -f "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}
contiene() { # descrizione, aghi, pagliaio
  if grep -q "$2" <<< "$3"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca "%s")\n' "$1" "$2"; FALLITI=$((FALLITI+1)); fi
}

echo "Prova end-to-end navigazione — ${BASE_URL}"
echo "  comandante di prova: ${USER_NAME}"

php "${ROOT}/bin/console.php" world:init >/dev/null
php "${ROOT}/bin/console.php" world:seed >/dev/null

# 1. Arruolamento (trasporto e-mail forzato a log) e attivazione da console
php -r '
$f="${ATLANTIK_CONFIG:-/etc/atlantik/config.php}"; $c=require $f;
file_put_contents("/tmp/atlantik-transport-nav.bak", $c["mail"]["transport"]);
$c["mail"]["transport"]="log";
file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($c,true).";\n");'
ripristina() {
  php -r '
  $f="${ATLANTIK_CONFIG:-/etc/atlantik/config.php}"; $c=require $f;
  $c["mail"]["transport"]=trim((string)@file_get_contents("/tmp/atlantik-transport-nav.bak")) ?: "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($c,true).";\n");
  @unlink("/tmp/atlantik-transport-nav.bak");'
}
trap 'ripristina; php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${USER_NAME}"'" >/dev/null 2>&1; rm -f "${JAR}"' EXIT

TOK=$(c "${BASE_URL}/arruolamento" | token_da)
c -o /dev/null -X POST "${BASE_URL}/arruolamento" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}"
php "${ROOT}/bin/console.php" user:verify "${USER_NAME}" >/dev/null
verifica "comandante arruolato e attivato" "0" "$?"

TOK=$(c "${BASE_URL}/accesso" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}")
verifica "accesso" "302" "${CODE}"

# 2a. Carriera (F5): senza comandante non si esce
PAGINA=$(c -L "${BASE_URL}/base")
contiene "senza comandante si finisce alla creazione" "Il tuo comandante" "${PAGINA}"
TOKC=$(echo "${PAGINA}" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/comandante/crea" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "nome=Otto Marwede" \
  --data-urlencode "nato_il=1913-02-11" --data-urlencode "nato_a=Bremen" --data-urlencode "base=lorient")
verifica "creazione del comandante" "302" "${CODE}"

PAGINA=$(c "${BASE_URL}/comandante")
contiene "fascicolo del comandante" "Otto Marwede" "${PAGINA}"
contiene "anzianita e risorse" "Punti di assegnazione" "${PAGINA}"
contiene "catalogo delle decorazioni" "Croce di Cavaliere" "${PAGINA}"
contiene "cantiere degli apparati" "Rivelatore radar Metox" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/albo")
contiene "albo d'oro consultabile" "Albo d" "${PAGINA}"

# 2. Base: battello assegnato (adesso che c'e' un comandante)
PAGINA=$(c "${BASE_URL}/base")
contiene "battello assegnato alla base" "U-" "${PAGINA}"
contiene "pulsante di uscita presente" "Mollare gli ormeggi" "${PAGINA}"

# --- Emblema di torretta (si dipinge in bunker, prima di uscire) -------------
CANT=$(c "${BASE_URL}/cantiere")
contiene "repertorio degli emblemi in cantiere" "Emblema di torretta" "${CANT}"
TOKE=$(echo "${CANT}" | token_da)

CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/cantiere/emblema" \
  --data-urlencode "_token=${TOKE}" --data-urlencode "chiave=spada")
verifica "emblema adottato" "302" "${CODE}"
CANT=$(c "${BASE_URL}/cantiere")
contiene "l'emblema compare sul battello" "spada.svg" "${CANT}"

# Nessun emblema si porta in due: lo si verifica prendendone uno gia' preso da
# un altro battello. Il vincolo sta nel database, non nel codice.
ALTRO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$r = App\Core\Database::first("SELECT emblema_key FROM boats WHERE emblema_key IS NOT NULL AND emblema_key <> \"spada\" LIMIT 1");
echo $r["emblema_key"] ?? "";')
if [[ -n "${ALTRO}" ]]; then
  TOKE=$(echo "${CANT}" | token_da)
  c -o /dev/null -X POST "${BASE_URL}/cantiere/emblema" --data-urlencode "_token=${TOKE}" --data-urlencode "chiave=${ALTRO}"
  CANT=$(c "${BASE_URL}/cantiere")
  contiene "un emblema gia' preso non si puo' rubare" "spada.svg" "${CANT}"
fi

# Caricamento: quello che arriva non viene mai servito com'e'.
PNG=$(mktemp /tmp/emblema_XXXX.png)
php -r '
$im = imagecreatetruecolor(300, 300);
imagefilledrectangle($im, 0, 0, 299, 299, imagecolorallocate($im, 30, 70, 110));
imagefilledellipse($im, 150, 150, 200, 200, imagecolorallocate($im, 220, 180, 70));
imagepng($im, $argv[1]);' "${PNG}"
TOKE=$(echo "${CANT}" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/cantiere/emblema/carica" \
  -F "_token=${TOKE}" -F "emblema=@${PNG};type=image/png")
verifica "emblema caricato" "302" "${CODE}"
CANT=$(c "${BASE_URL}/cantiere")
contiene "l'emblema caricato e' in servizio" "emblemi/caricati/" "${CANT}"
# L'indirizzo dev'essere quello giusto: /assets/img/... e non /assets/assets/...
grep -q 'assets/assets' <<< "${CANT}" \
  && verifica "nessun doppio prefisso negli indirizzi" "no" "si" \
  || verifica "nessun doppio prefisso negli indirizzi" "no" "no"
RISCRITTO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.emblema_file FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
$f = "/data/html/atlantik/assets/img/emblemi/caricati/" . $b["emblema_file"];
$i = @getimagesize($f);
echo is_file($f) && $i !== false && $i[2] === IMAGETYPE_WEBP && $i[0] === 256 ? "si" : "no";' "${USER_NAME}")
verifica "il file e' stato ridisegnato in WebP 256" "si" "${RISCRITTO}"

# Un SVG non deve entrare: puo' contenere codice.
SVG=$(mktemp /tmp/emblema_XXXX.svg)
printf '%s' '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script><circle cx="32" cy="32" r="30"/></svg>' > "${SVG}"
TOKE=$(echo "$(c "${BASE_URL}/cantiere")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/cantiere/emblema/carica" -F "_token=${TOKE}" -F "emblema=@${SVG};type=image/svg+xml"
SVGENTRATO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.emblema_file FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
echo str_ends_with((string) $b["emblema_file"], ".svg") ? "si" : "no";' "${USER_NAME}")
verifica "un SVG non entra" "no" "${SVGENTRATO}"
rm -f "${PNG}" "${SVG}"
contiene "carriera mostrata in flottiglia" "Punti di assegnazione" "${PAGINA}"
TOK=$(echo "${PAGINA}" | token_da)

# 2b. Cantiere e allestimento (F2), finche' si e' in bunker
PAGINA=$(c "${BASE_URL}/cantiere")
contiene "cantiere raggiungibile" "Allestimento per la prossima missione" "${PAGINA}"
contiene "elenco dei tipi disponibili" "Tipo VII" "${PAGINA}"
TOKC=$(echo "${PAGINA}" | token_da)

# carico impossibile: deve essere respinto
c -o /dev/null -X POST "${BASE_URL}/cantiere/allestimento" --data-urlencode "_token=${TOKC}" \
  --data-urlencode "viveri=120" --data-urlencode "ricambi=40" --data-urlencode "potassa=240" \
  --data-urlencode "ossigeno=48" --data-urlencode "munizioni_cannone=250" --data-urlencode "munizioni_flak=4000"
PAGINA=$(c "${BASE_URL}/cantiere")
contiene "carico oltre la stiva respinto" "Non ci sta" "${PAGINA}"
TOKC=$(echo "${PAGINA}" | token_da)

# carico sensato: deve passare
c -o /dev/null -X POST "${BASE_URL}/cantiere/allestimento" --data-urlencode "_token=${TOKC}" \
  --data-urlencode "viveri=42" --data-urlencode "ricambi=14" --data-urlencode "potassa=120" \
  --data-urlencode "ossigeno=12" --data-urlencode "munizioni_cannone=100" --data-urlencode "munizioni_flak=1200"
PAGINA=$(c "${BASE_URL}/cantiere")
contiene "carico imbarcato" "Carico imbarcato" "${PAGINA}"

# --- Le stazioni che esistono solo in mare, viste dal porto -----------------
#
# Centrale, carteggio e ascolto non hanno niente da mostrare all'ormeggio, e il
# controller rimanda alla flottiglia. Finche' i collegamenti restavano accesi
# lo faceva in silenzio: si cliccava e si tornava indietro senza una parola,
# che e' il modo migliore per far credere che il gioco sia rotto.

PAGINA=$(c "${BASE_URL}/base")
BARRA=$(sed -n 's/.*<nav class="nav-plancia">\(.*\)<\/nav>.*/\1/p' <<< "$(tr -d '\n' <<< "${PAGINA}")")
for STAZIONE in Zentrale Carteggio Ascolto; do
  if grep -q "spento[^>]*>${STAZIONE}<" <<< "${BARRA}"; then
    verifica "in porto ${STAZIONE} si mostra spenta" "si" "si"
  else
    verifica "in porto ${STAZIONE} si mostra spenta" "si" "no"
  fi
done

CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/zentrale")
verifica "in porto la centrale rimanda indietro" "302" "${CODE}"
PAGINA=$(c -L "${BASE_URL}/zentrale")
grep -q "si apre quando il battello" <<< "${PAGINA}" \
  && verifica "e dice perche', invece di rimandare in silenzio" "si" "si" \
  || verifica "e dice perche', invece di rimandare in silenzio" "si" "no"

PAGINA=$(c "${BASE_URL}/base"); TOK=$(echo "${PAGINA}" | token_da)

# 3. Uscita in mare
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/uscita" --data-urlencode "_token=${TOK}")
verifica "uscita in mare" "302" "${CODE}"

PAGINA=$(c "${BASE_URL}/zentrale")
contiene "plancia raggiungibile" "Zentrale" "${PAGINA}"

# E adesso che si e' in mare, le tre stazioni si accendono davvero.
for R in zentrale carta contatti; do
  verifica "in mare /${R} si apre" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/${R}")"
done
BARRA=$(sed -n 's/.*<nav class="nav-plancia">\(.*\)<\/nav>.*/\1/p' <<< "$(tr -d '\n' <<< "${PAGINA}")")
grep -q 'spento' <<< "${BARRA}" \
  && verifica "in mare nessuna stazione resta spenta" "si" "no" \
  || verifica "in mare nessuna stazione resta spenta" "si" "si"
contiene "quadrato Marinequadrat mostrato" "BF" "${PAGINA}"
TOK=$(echo "${PAGINA}" | token_da)

# Prima uscita: il BdU consegna l'ordine di missione e il I.WO spiega il
# passo successivo. Nessun comandante nuovo deve restare senza istruzioni.
KTB=$(c "${BASE_URL}/ktb")
contiene "ordine di missione alla prima uscita" "Ordine di missione del BdU" "${KTB}"
contiene "il I.WO spiega cosa fare" "tavolo di carteggio" "${KTB}"

# 4. Rotta verso il largo
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/rotta" --data-urlencode "_token=${TOK}" \
  --data-urlencode 'waypoints=[{"lat":46.5,"lon":-6.0},{"lat":48.5,"lon":-14.0},{"lat":52.0,"lon":-25.0}]')
verifica "rotta trasmessa" "302" "${CODE}"

# 5. Ordini di macchina
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/ordini" --data-urlencode "_token=${TOK}" \
  --data-urlencode "speed=10" --data-urlencode "depth=0" --data-urlencode "silent=0")
verifica "ordini impartiti" "302" "${CODE}"

# 6. Tempo: 36 ore di gioco
# L'avanzamento deve riuscire davvero: se salta (un errore fatale dentro la
# simulazione) le prove che seguono misurerebbero uno stato mai cambiato e
# direbbero soltanto "nafta al 100%", nascondendo la causa.
AVANZ=$(php "${ROOT}/bin/_test_advance.php" "${USER_NAME}" 36 2>&1)
grep -q '"steps"' <<< "${AVANZ}" \
  && verifica "avanzamento di 36 ore riuscito" "si" "si" \
  || { verifica "avanzamento di 36 ore riuscito" "si" "no"; echo "      ${AVANZ}" | head -3; }
STATO=$(c -H 'Accept: application/json' "${BASE_URL}/api/stato")
contiene "lo stato risponde in JSON" '"ok":true' "${STATO}"

MIGLIA=$(php -r '
$s = json_decode(file_get_contents("php://stdin"), true);
$p = $s["battello"] ?? [];
echo (int) round(($p["autonomia_nm"] ?? 0));' <<< "${STATO}")
[[ "${MIGLIA}" -gt 1000 ]] && verifica "autonomia calcolata" "si" "si" || verifica "autonomia calcolata" "si" "no"

NAFTA=$(php -r '$s=json_decode(file_get_contents("php://stdin"),true); echo $s["battello"]["nafta_pct"] ?? 100;' <<< "${STATO}")
php -r 'exit(((float) $argv[1] < 100.0 && (float) $argv[1] > 80.0) ? 0 : 1);' "${NAFTA}"
verifica "nafta consumata ma non finita (${NAFTA}%)" "0" "$?"

QUADRAT=$(php -r '$s=json_decode(file_get_contents("php://stdin"),true); echo $s["battello"]["quadrat"] ?? "";' <<< "${STATO}")
[[ -n "${QUADRAT}" ]] && verifica "posizione in quadrato (${QUADRAT})" "si" "si" || verifica "posizione in quadrato" "si" "no"

# 7. Immersione
#
# Batterie al pieno prima di cominciare: qui si verifica che il battello scenda
# e ci resti, non quanto dura una batteria. L'autonomia in immersione ha le sue
# prove altrove (test_sim e balance:report), e senza questo la prova dipende da
# quanto si e' navigato sott'acqua nelle sei ore precedenti — che cambia a ogni
# corsa, perche' cambiano gli aerei.
php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
App\Core\Database::run("UPDATE boats SET battery_pct = 100 WHERE id = ?", [$b["id"]]);' "${USER_NAME}"

PAGINA=$(c "${BASE_URL}/zentrale"); TOK=$(echo "${PAGINA}" | token_da)
# L'esito di questo POST non si butta via: se l'ordine non arriva, le prove
# che seguono misurano un battello che non ha mai ricevuto comandi.
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/ordini" --data-urlencode "_token=${TOK}" \
  --data-urlencode "speed=4" --data-urlencode "depth=60" --data-urlencode "silent=1")
verifica "ordine di immersione accettato" "302" "${CODE}"
ORDINATA=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.ordered_depth_m q FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
echo (int) round((float) $b["q"]);' "${USER_NAME}")
verifica "quota ordinata registrata" "60" "${ORDINATA}"
php "${ROOT}/bin/_test_advance.php" "${USER_NAME}" 6 >/dev/null
STATO=$(c -H 'Accept: application/json' "${BASE_URL}/api/stato")
MODO=$(php -r '$s=json_decode(file_get_contents("php://stdin"),true); echo $s["battello"]["modo"] ?? "";' <<< "${STATO}")
# Se non e' in immersione, la prova deve dire PERCHE': senza, si legge solo
# "atteso immersione, ottenuto superficie" e si ricomincia da capo ogni volta.
DETTAGLIO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.* FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
$e = App\Core\Database::first("SELECT text FROM patrol_events WHERE boat_id = ? AND kind IN (\"emersione_forzata\",\"emersione\",\"aereo\") ORDER BY id DESC LIMIT 1", [$b["id"]]);
printf("quota %.0f m, ordinata %.0f m, batteria %.0f%%%s", $b["depth_m"], $b["ordered_depth_m"], $b["battery_pct"],
  $e ? " — ultimo: " . mb_substr($e["text"], 0, 60) : "");' "${USER_NAME}")
verifica "battello in immersione (${DETTAGLIO})" "immersione" "${MODO}"
BATT=$(php -r '$s=json_decode(file_get_contents("php://stdin"),true); echo (int) $s["battello"]["batteria"];' <<< "${STATO}")
[[ "${BATT}" -lt 100 ]] && verifica "batteria in scarica (${BATT}%)" "si" "si" || verifica "batteria in scarica" "si" "no"

# 7b. Battello ed equipaggio (F2)
PAGINA=$(c "${BASE_URL}/battello")
contiene "scheda del battello" "Sistemi" "${PAGINA}"
contiene "compartimenti elencati" "Camera siluri di prua" "${PAGINA}"
contiene "stiva mostrata" "Cartucce di potassa" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/equipaggio")
contiene "ruolino dell'equipaggio" "Ruolino" "${PAGINA}"
contiene "guardie di bordo" "guardia" "${PAGINA}"
UOMINI=$(php -r '
$s = json_decode(file_get_contents("php://stdin"), true);
echo (int) ($s["materiale"]["avarie"] ?? -1);' <<< "$(c -H 'Accept: application/json' "${BASE_URL}/api/stato")")
[[ "${UOMINI}" -ge 0 ]] && verifica "lo stato riporta il materiale" "si" "si" || verifica "lo stato riporta il materiale" "si" "no"

ORGANICO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
echo (int) (App\Core\Database::first("SELECT COUNT(*) n FROM crew_members WHERE boat_id = ?", [$b["id"]])["n"] ?? 0);' "${USER_NAME}")
[[ "${ORGANICO}" -gt 30 ]] && verifica "equipaggio imbarcato (${ORGANICO} uomini)" "si" "si" || verifica "equipaggio imbarcato (${ORGANICO})" "si" "no"

SISTEMI=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
echo (int) (App\Core\Database::first("SELECT COUNT(*) n FROM boat_systems WHERE boat_id = ?", [$b["id"]])["n"] ?? 0);' "${USER_NAME}")
[[ "${SISTEMI}" -gt 12 ]] && verifica "sistemi installati (${SISTEMI})" "si" "si" || verifica "sistemi installati (${SISTEMI})" "si" "no"

# 7c. Ascolto e contatti (F3)
PAGINA=$(c "${BASE_URL}/contatti")
contiene "stanza dell'ascolto" "Che cosa si sentirebbe adesso" "${PAGINA}"
contiene "rosa dei rilevamenti" "rosa-idrofono" "${PAGINA}"
contiene "portata idrofonica mostrata" "Un convoglio si sente fino a" "${PAGINA}"
contiene "calore del settore" "Sorveglianza" "${PAGINA}"

TRAFFICO=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$s = App\Sim\Traffic::stato(App\Sim\World::now());
echo (int) $s["navi"];')
[[ "${TRAFFICO}" -gt 100 ]] && verifica "naviglio alleato in mare (${TRAFFICO} navi)" "si" "si" || verifica "naviglio in mare (${TRAFFICO})" "si" "no"

CONVOGLI=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$s = App\Sim\Traffic::stato(App\Sim\World::now());
echo (int) $s["convogli"];')
[[ "${CONVOGLI}" -ge 8 ]] && verifica "convogli in navigazione (${CONVOGLI})" "si" "si" || verifica "convogli in navigazione (${CONVOGLI})" "si" "no"

# La carta deve portare i contatti
PAGINA=$(c "${BASE_URL}/carta")
contiene "contatti passati alla carta" "contatti" "${PAGINA}"

# La sagoma sulla pagina d'ascolto esce solo per i contatti VISTI. L'invariante
# vera sta nel dato: un contatto all'idrofono non deve mai portarsi dietro una
# classe riconosciuta, altrimenti la regola si aggira senza accorgersene.
SPIONI=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
echo (int) App\Core\Database::first(
  "SELECT COUNT(*) n FROM contacts WHERE sensore <> \"vista\" AND classe_key_est IS NOT NULL"
)["n"];')
verifica "nessuna classe riconosciuta senza averla vista" "0" "${SPIONI}"

# 7d. Combattimento (F4): si porta il battello addosso a un convoglio e si attacca
CONTATTO=$(php "${ROOT}/bin/_test_ingaggio.php" "${USER_NAME}" contatto)
[[ "${CONTATTO}" -gt 0 ]] && verifica "contatto sul convoglio predisposto" "si" "si" || verifica "contatto predisposto" "si" "no"

PAGINA=$(c "${BASE_URL}/contatti")
contiene "contatto ingaggiabile dalla pagina d'ascolto" "ingaggia" "${PAGINA}"
TOKA=$(echo "${PAGINA}" | token_da)

CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/attacco/ingaggia" \
  --data-urlencode "_token=${TOKA}" --data-urlencode "contatto=${CONTATTO}")
verifica "ingaggio del convoglio" "302" "${CODE}"

PAGINA=$(c "${BASE_URL}/attacco")
contiene "stazione d'attacco" "Calcolatore di lancio" "${PAGINA}"

# Le sagome nella stazione d'attacco seguono il periscopio: alzato si vede,
# abbassato si pedina a orecchio. E' la stessa regola dei contatti, portata al
# suo caso limite — qui il bersaglio e' a tremila metri, ma se il periscopio e'
# dentro non lo si guarda comunque.
alzaPeriscopio() {
  php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
App\Core\Database::run("UPDATE boats SET mode=\"periscopio\", depth_m=12, ordered_depth_m=12, periscopio_alzato=?, periscopio_gts=NULL WHERE id=?",
  [(int) $argv[2], $b["id"]]);' "${USER_NAME}" "$1"
}

# Col periscopio fuori si guarda; col periscopio dentro si ascolta. Quale dei
# due messaggi esca, fra "si vedono sagome" e "solo per le unita' riconosciute",
# dipende da distanza e luce: quello che deve valere sempre e' che con l'occhio
# fuori la pagina NON dica di star pedinando a orecchio.
alzaPeriscopio 1
PAGINA=$(c "${BASE_URL}/attacco")
if grep -q "Periscopio abbassato" <<< "${PAGINA}"; then
  printf '  \033[0;31mKO\033[0m    col periscopio fuori non si pedina a orecchio\n'; FALLITI=$((FALLITI+1))
else printf '  \033[0;32mok\033[0m    col periscopio fuori non si pedina a orecchio\n'; fi
contiene "i numeri della stazione d'attacco sono dichiarati stime" "sono <b>stime</b>" "${PAGINA}"

alzaPeriscopio 0
PAGINA=$(c "${BASE_URL}/attacco")
contiene "col periscopio dentro si pedina a orecchio" "Periscopio abbassato" "${PAGINA}"
# A orecchio nessuna unita' puo' risultare riconosciuta, e senza riconoscimento
# la sagoma non si disegna. (La parola compare solo nella colonna "Come" della
# tabella bersagli: la nota esplicativa, in questo ramo, non la usa.)
if grep -q 'riconosciuta' <<< "${PAGINA}"; then
  printf '  \033[0;31mKO\033[0m    a orecchio non si riconosce nessuna unita\n'; FALLITI=$((FALLITI+1))
else printf '  \033[0;32mok\033[0m    a orecchio non si riconosce nessuna unita\n'; fi
contiene "quadro tattico" "id=\"plotta\"" "${PAGINA}"
contiene "finestra di condotta" "Finestra di condotta" "${PAGINA}"

UNITA=$(php "${ROOT}/bin/_test_ingaggio.php" "${USER_NAME}" encounter)
[[ "${UNITA}" -gt 10 ]] && verifica "convoglio materializzato (${UNITA} unita)" "si" "si" || verifica "convoglio materializzato (${UNITA})" "si" "no"

TOKA=$(echo "${PAGINA}" | token_da)
BERSAGLIO=$(php "${ROOT}/bin/_test_ingaggio.php" "${USER_NAME}" bersaglio)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/attacco/lancia" \
  --data-urlencode "_token=${TOKA}" --data-urlencode "bersaglio=${BERSAGLIO}" \
  --data-urlencode "tubi[]=1" --data-urlencode "tubi[]=2" \
  --data-urlencode "spoletta=contatto" --data-urlencode "quota=4")
verifica "lancio dei siluri" "302" "${CODE}"

LANCIATI=$(php "${ROOT}/bin/_test_ingaggio.php" "${USER_NAME}" siluri)
[[ "${LANCIATI}" -ge 2 ]] && verifica "siluri in corsa (${LANCIATI})" "si" "si" || verifica "siluri in corsa (${LANCIATI})" "si" "no"

STATO=$(c -H 'Accept: application/json' "${BASE_URL}/api/incontro")
contiene "stato dell'incontro in JSON" '"incontro"' "${STATO}"

PAGINA=$(c "${BASE_URL}/attacco"); TOKA=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/attacco/disimpegna" --data-urlencode "_token=${TOKA}"
verifica "disimpegno" "chiuso" "$(php "${ROOT}/bin/_test_ingaggio.php" "${USER_NAME}" aperto)"

# 7e. Radio, BdU e mondo condiviso (F6)
PAGINA=$(c "${BASE_URL}/radio")
contiene "stanza radio" "Funkraum" "${PAGINA}"
contiene "segnali brevi disponibili" "Kurzsignale" "${PAGINA}"
contiene "avvertimento sulla radiogoniometria" "rilevamento" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/bdu")
contiene "pagina del comando" "Befehlshaber der U-Boote" "${PAGINA}"
contiene "gruppi operativi" "Rudeltaktik" "${PAGINA}"
contiene "rifornimento in mare" "Tipo XIV" "${PAGINA}"

# trasmissione: il battello e' immerso, deve essere rifiutata
TOKR=$(echo "$(c "${BASE_URL}/radio")" | token_da)
c -o /dev/null -X POST "${BASE_URL}/radio/trasmetti" --data-urlencode "_token=${TOKR}" --data-urlencode "kurz=consumo"
PAGINA=$(c "${BASE_URL}/radio")
contiene "in immersione non si trasmette" "emergere" "${PAGINA}"

# Si emerge e si trasmette un segnale breve.
#
# Fra una richiesta e l'altra il battello continua a vivere: caricare /radio lo
# fa avanzare, e un allarme aereo puo' rimandarlo sotto proprio in quel momento.
# Non e' un difetto del gioco, e' il gioco. La prova percio' ci riprova, come
# farebbe un comandante: si riemerge e si trasmette di nuovo.
# La stazione radio dev'essere in ordine: se e' in avaria non si trasmette, ed
# e' giusto cosi' — ma qui si verifica la trasmissione, non l'avaria. Come per
# le batterie, si parte da uno stato noto.
php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
App\Core\Database::run("UPDATE boat_systems SET state = \"ok\", repair_progress = 0 WHERE boat_id = ? AND skey = \"radio\"", [$b["id"]]);' "${USER_NAME}"

MESSAGGI=0
for TENTATIVO in 1 2 3; do
  PAGINA=$(c "${BASE_URL}/radio"); TOKR=$(echo "${PAGINA}" | token_da)
  php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
App\Core\Database::run("UPDATE boats SET mode=\"superficie\", depth_m=0, ordered_depth_m=0,
    auto_dive_fine_gts=NULL, auto_dive_quota=NULL WHERE id=?", [$b["id"]]);' "${USER_NAME}"

  CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/radio/trasmetti" \
    --data-urlencode "_token=${TOKR}" --data-urlencode "kurz=consumo")

  MESSAGGI=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
echo (int) (App\Core\Database::first("SELECT COUNT(*) n FROM radio_messages WHERE boat_id = ?", [$b["id"]])["n"] ?? 0);' "${USER_NAME}")
  [[ "${MESSAGGI}" -ge 1 ]] && break
done
verifica "trasmissione del segnale breve" "302" "${CODE}"
[[ "${MESSAGGI}" -ge 1 ]] && verifica "messaggio registrato (${MESSAGGI})" "si" "si" || verifica "messaggio registrato" "si" "no"

PAGINA=$(c "${BASE_URL}/statistiche")
contiene "statistiche di campagna pubbliche" "Rapporto di scambio" "${PAGINA}"

# 7f. Rifinitura (F7): trofei, esportazione, applicazione installabile
PAGINA=$(c "${BASE_URL}/trofei")
contiene "pagina dei trofei" "Trofei" "${PAGINA}"
contiene "trofei divisi per categoria" "Mestiere" "${PAGINA}"

PATROL=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
$b = App\Core\Database::first("SELECT b.id FROM boats b JOIN users u ON u.id=b.user_id WHERE u.username = ?", [$argv[1]]);
$p = App\Core\Database::first("SELECT id FROM patrols WHERE boat_id = ? ORDER BY id DESC LIMIT 1", [$b["id"]]);
echo (int) ($p["id"] ?? 0);' "${USER_NAME}")

KTB=$(c "${BASE_URL}/ktb/${PATROL}/esporta")
contiene "giornale esportabile" "KRIEGSTAGEBUCH" "${KTB}"
contiene "l'esportazione contiene gli eventi" "Mollati gli ormeggi" "${KTB}"

TIPO=$(c -o /dev/null -w '%{content_type}' "${BASE_URL}/ktb/${PATROL}/esporta")
[[ "${TIPO}" == text/plain* ]] && verifica "esportazione servita come testo" "si" "si" || verifica "esportazione come testo (${TIPO})" "si" "no"

CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/manifest.webmanifest")
verifica "manifesto dell'applicazione" "200" "${CODE}"
CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/sw.js")
verifica "service worker servito" "200" "${CODE}"

# l'area di amministrazione e' chiusa a chi non e' amministratore
CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/admin")
verifica "pannello di amministrazione negato ai giocatori" "403" "${CODE}"

# 8. Giornale di guerra
PAGINA=$(c "${BASE_URL}/ktb")
contiene "giornale di guerra compilato" "Mollati gli ormeggi" "${PAGINA}"

# La sagoma nel giornale si aggancia all'affondamento per l'ora esatta: se una
# riga di genere "affondamento" non trovasse la sua nave, resterebbe muta senza
# che nessuno se ne accorga.
SPAIATE=$(php -r '
require "/data/html/atlantik/bin/_bootstrap.php";
echo (int) App\Core\Database::first(
  "SELECT COUNT(*) n FROM patrol_events e
    WHERE e.kind = \"affondamento\"
      AND NOT EXISTS (SELECT 1 FROM sinkings s WHERE s.patrol_id = e.patrol_id AND s.gts = e.gts)"
)["n"];')
verifica "ogni riga di affondamento trova la sua nave" "0" "${SPAIATE}"
contiene "rapporti di posizione presenti" "Quadrato" "${PAGINA}"

# 9. Carta nautica
PAGINA=$(c "${BASE_URL}/carta")
contiene "tavolo di carteggio" "id=\"carta\"" "${PAGINA}"
contiene "dati della carta presenti" "data-carta" "${PAGINA}"
contiene "sigle Marinequadrat sulla carta" "quadrati" "${PAGINA}"

# Tratto della carta: due stili, stessa geometria. Il valore arriva dal browser
# e non deve poter entrare in tabella se non e' uno dei due previsti.
TOKC=$(echo "${PAGINA}" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/carta/stile" \
  --data-urlencode "_token=${TOKC}" --data-urlencode "stile=essenziale")
verifica "cambio del tratto accettato" "302" "${CODE}"
PAGINA=$(c "${BASE_URL}/carta")
contiene "il tratto scelto arriva alla tela" "stile&quot;:&quot;essenziale" "${PAGINA}"

TOKC=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/carta/stile" --data-urlencode "_token=${TOKC}" --data-urlencode "stile=bandiera_pirata"
PAGINA=$(c "${BASE_URL}/carta")
contiene "un tratto inventato viene respinto" "stile&quot;:&quot;essenziale" "${PAGINA}"

TOKC=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/carta/stile" --data-urlencode "_token=${TOKC}" --data-urlencode "stile=piena"
PAGINA=$(c "${BASE_URL}/carta")
contiene "si torna alla carta da tavolo" "stile&quot;:&quot;piena" "${PAGINA}"
contiene "la provenienza delle coste e mostrata" "fonte-coste" "${PAGINA}"

# Le figure di segnaposto escono sempre con la loro dichiarazione attaccata:
# se un giorno qualcuno le mettesse senza, questa prova lo ferma.
BDU=$(c "${BASE_URL}/bdu")
contiene "figura di segnaposto sulla pagina del BdU" "class=\"segnaposto\"" "${BDU}"
contiene "la figura dichiara cos'e'" "Ricostruzione, non riferimento documentale" "${BDU}"
contiene "coste caricate dalla carta" "coste.js" "${PAGINA}"
contiene "nomi della carta caricati" "etichette.js" "${PAGINA}"

# La carta illustrata fa da sfondo dove non si misura niente, e NON sulla
# plancia: la' la carta e' uno strumento, e quell'immagine non regge la misura.
# Se un giorno qualcuno la mettesse anche qui, questa prova lo ferma.
grep -q 'data-sfondo' <<< "${PAGINA}" \
  && verifica "il tavolo di carteggio non ha fondali illustrati" "no" "si" \
  || verifica "il tavolo di carteggio non ha fondali illustrati" "no" "no"
ZENTRALE=$(c "${BASE_URL}/zentrale")
grep -q 'data-sfondo' <<< "${ZENTRALE}" \
  && verifica "la plancia non ha fondali illustrati" "no" "si" \
  || verifica "la plancia non ha fondali illustrati" "no" "no"
STATISTICHE=$(c "${BASE_URL}/statistiche")
contiene "le pagine di terra hanno la carta dietro" 'data-sfondo="carta"' "${STATISTICHE}"

# 10. Rientro negato al largo
PAGINA=$(c "${BASE_URL}/zentrale"); TOK=$(echo "${PAGINA}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/rientro" --data-urlencode "_token=${TOK}"
PAGINA=$(c "${BASE_URL}/zentrale")
contiene "rientro negato lontano dalla base" "miglia da" "${PAGINA}"

# --- fascicolo pubblico -------------------------------------------------------
CMD=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$c = App\Core\Database::first("SELECT id FROM commanders WHERE user_id = ?", [(int) $u["id"]]);
echo (int) ($c["id"] ?? 0);' "${USER_NAME}")

PAGINA=$(c "${BASE_URL}/profilo/${CMD}")
contiene "fascicolo pubblico raggiungibile" "Fascicolo personale" "${PAGINA}"
contiene "il fascicolo dice che cosa ha affondato" "Che cosa ha affondato" "${PAGINA}"
contiene "il fascicolo mostra il ruolino delle missioni" "Ruolino delle missioni" "${PAGINA}"

PAGINA=$(c "${BASE_URL}/comandante/profilo")
contiene "pagina di modifica del proprio fascicolo" "repertorio storico" "${PAGINA}"
contiene "il repertorio dichiara da dove vengono le fotografie" "raccolta messa insieme a mano" "${PAGINA}"
contiene "e dichiara che la provenienza non e' verificata" "provenienza" "${PAGINA}"

# Un ritratto storico, con la sua attribuzione bene in vista.
TOKP=$(echo "${PAGINA}" | token_da)
CHIAVE=$(php -r '$r = require "'"${ROOT}"'/db/seed/ritratti.php"; echo array_key_first($r);')
c -o /dev/null -X POST "${BASE_URL}/comandante/ritratto" --data-urlencode "_token=${TOKP}"   --data-urlencode "ritratto=${CHIAVE}"
PAGINA=$(c "${BASE_URL}/profilo/${CMD}")
contiene "il ritratto mostra la licenza che lo accompagna" "Fotografia storica di" "${PAGINA}"

  # Il nome d'accesso non si regala. Sul PROPRIO fascicolo si vede; su quello di
  # un altro no — a chi guarda serve il comandante, non l'account, e un nome
  # d'accesso valido e' meta' di un tentativo d'intrusione (audit 19/09/2026).
  MIO=$(c "${BASE_URL}/profilo/${CMD}")
  contiene "sul proprio fascicolo l'account si vede" ">Account<" "${MIO}"

  ALTRUI_ID=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
  $r = App\Core\Database::first("SELECT id FROM commanders WHERE user_id <> (SELECT id FROM users WHERE username = ?) ORDER BY id DESC LIMIT 1", [$argv[1]]);
  echo (int) ($r["id"] ?? 0);' "${USER_NAME}")
  if [[ "${ALTRUI_ID}" -gt 0 ]]; then
    ALTRUI=$(c "${BASE_URL}/profilo/${ALTRUI_ID}")
    if grep -q ">Account<" <<< "${ALTRUI}"; then
      verifica "su quello di un altro no" "si" "no"
    else
      verifica "su quello di un altro no" "si" "si"
    fi
    contiene "ma il resto del fascicolo si legge lo stesso" "In servizio dal" "${ALTRUI}"
  fi

# Il fascicolo di un altro non si modifica: il modulo di modifica non c'e'.
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/comandante/ritratto/togli"   --data-urlencode "_token=${TOKP}" --data-urlencode "comandante=1")
verifica "un giocatore comune non tocca il fascicolo di un altro" "403" "${CODE}"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
