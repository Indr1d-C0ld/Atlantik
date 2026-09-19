#!/usr/bin/env bash
#
# Atlantik — ordini storti.
#
#   bash tests/e2e_ordini_storti.sh
#
# Tutte le prove fatte finora impartiscono ordini sensati. Questa fa l'opposto:
# manda a ogni postazione i valori che un modulo non dovrebbe mai produrre —
# numeri negativi, quote da diecimila metri, campi mancanti, stringhe al posto
# di cifre, notazione esponenziale, JSON rotto, testo lungo un chilometro — e
# pretende tre cose:
#
#   1. nessun 500. Un ordine assurdo si rifiuta, non fa cadere il server;
#   2. nessun errore nuovo nel diario dell'applicazione;
#   3. lo stato del battello resta dentro i suoi limiti. E' la verifica che
#      conta: un ordine respinto a video ma scritto nel database e' peggio di
#      un errore, perche' non si vede.
#
# Il battello e' di un giocatore di prova, che alla fine si porta via.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JAR="$(mktemp)"
UTENTE="prova storti $(date +%s)"
PASS="mareggiata8"
FALLITI=0
DIARIO="${ROOT}/storage/logs/app.log"

trap 'php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${UTENTE}"'" >/dev/null 2>&1; rm -f "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}

php "${ROOT}/bin/_prova_sfrena.php" >/dev/null 2>&1
echo "Prova end-to-end degli ordini storti — ${BASE_URL}"

php "${ROOT}/bin/_prova_utente.php" "${UTENTE}" "${PASS}" >/dev/null 2>&1
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${UTENTE}" --data-urlencode "password=${PASS}"

# Comandante, battello, e via in mare: gli ordini alla centrale si danno solo
# dal mare, e senza uscita non si proverebbe niente.
PAG=$(c "${BASE_URL}/comandante"); TOK=$(echo "${PAG}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/comandante/crea" --data-urlencode "_token=${TOK}" \
  --data-urlencode "nome=Storti Prova $(date +%s | tail -c 5)" --data-urlencode "nato_il=1912-08-08" \
  --data-urlencode "nato_a=Kiel" --data-urlencode "base=lorient"
PAG=$(c "${BASE_URL}/base"); TOK=$(echo "${PAG}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/uscita" --data-urlencode "_token=${TOK}"

STATO=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$b = App\Core\Database::first("SELECT state FROM boats WHERE user_id = ?", [(int) $u["id"]]);
echo $b["state"] ?? "-";' "${UTENTE}")
verifica "il battello di prova e' in mare" "mare" "${STATO}"

ERRORI_PRIMA=$(grep -c "ERROR" "${DIARIO}" 2>/dev/null || echo 0)

# --- controllo positivo, prima di tutto il resto -----------------------------
#
# Senza questo, la prova non varrebbe niente. Se il gettone fosse sbagliato, o
# la sessione scaduta, o la rotta cambiata di nome, OGNI ordine rimbalzerebbe
# prima di arrivare al controller — e una batteria di ordini storti che non
# arrivano da nessuna parte non fa cadere niente e passa in pieno. E' successo
# gia' in questo progetto, in altre forme: una prova che non puo' fallire.
#
# Quindi prima si da' un ordine SENSATO e si guarda che arrivi fino al
# database. Se questo non passa, tutto il resto non significa niente.
PAG=$(c "${BASE_URL}/zentrale"); TOK=$(echo "${PAG}" | token_da)
c -o /dev/null -X POST "${BASE_URL}/ordini" --data-urlencode "_token=${TOK}" \
  --data-urlencode "speed=7" --data-urlencode "depth=42"
ORDINATO=$(php -r 'require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$b = App\Core\Database::first("SELECT ordered_speed_kn, ordered_depth_m FROM boats WHERE user_id = ?", [(int) $u["id"]]);
printf("%.0f/%.0f", (float) $b["ordered_speed_kn"], (float) $b["ordered_depth_m"]);' "${UTENTE}")
verifica "un ordine sensato arriva fino al database (controllo positivo)" "7/42" "${ORDINATO}"

# --- la batteria --------------------------------------------------------------
#
# Ogni riga e' una rotta e un carico storto. Si guarda solo che non sia un 500:
# il resto lo verifica il controllo sui limiti, piu' sotto.
CINQUECENTO=0
PROVATE=0
storto() { # descrizione, rotta, coppie chiave=valore...
  local DESC="$1" ROTTA="$2"; shift 2
  local PAGINA TOKEN CODICE ARGS=()
  PAGINA=$(c "${BASE_URL}/zentrale")
  TOKEN=$(echo "${PAGINA}" | token_da)
  [[ -z "${TOKEN}" ]] && TOKEN=$(c "${BASE_URL}/base" | token_da)
  ARGS+=(--data-urlencode "_token=${TOKEN}")
  for kv in "$@"; do ARGS+=(--data-urlencode "${kv}"); done
  CODICE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}${ROTTA}" "${ARGS[@]}")
  PROVATE=$((PROVATE+1))
  if [[ "${CODICE}" =~ ^5 ]]; then
    printf '  \033[0;31mKO\033[0m    %s -> %s\n' "${DESC}" "${CODICE}"
    CINQUECENTO=$((CINQUECENTO+1)); FALLITI=$((FALLITI+1))
  fi
}

# Centrale: velocita' e quota
storto "velocita' negativa"          /ordini "speed=-40"
storto "velocita' assurda"           /ordini "speed=99999"
storto "velocita' non numerica"      /ordini "speed=avanti tutta"
storto "velocita' esponenziale"      /ordini "speed=1e400"
storto "quota negativa"              /ordini "depth=-500"
storto "quota da batiscafo"          /ordini "depth=12000"
storto "quota vuota"                 /ordini "depth="
storto "nessun campo"                /ordini
storto "silenzio fuori scala"        /ordini "silent=7"
storto "tutti storti insieme"        /ordini "speed=-1" "depth=-1" "silent=-1"

# Carteggio: la rotta arriva come JSON dal browser
storto "rotta non JSON"              /rotta "waypoints=questo non e' json"
storto "rotta JSON ma non lista"     /rotta 'waypoints={"lat":1}'
storto "rotta con punti senza lat"   /rotta 'waypoints=[{"lon":-12}]'
storto "rotta fuori dal pianeta"     /rotta 'waypoints=[{"lat":991,"lon":-999}]'
storto "rotta lunghissima"           /rotta "waypoints=$(php -r 'echo json_encode(array_fill(0, 500, ["lat" => 47, "lon" => -12]));')"
storto "rotta vuota"                 /rotta "waypoints=[]"

# Battello: riparazioni, paratie, turni
storto "riparazione di un sistema inesistente" /riparazione "skey=motore a vapore"
storto "riparazione senza campo"     /riparazione
storto "paratia inesistente"         /paratia "ckey=cambusa segreta" "sigilla=1"
storto "paratia con valore storto"   /paratia "ckey=prua" "sigilla=banana"
storto "turno inesistente"           /turno "watch=9"
storto "turno negativo"              /turno "watch=-3"

# Attacco
storto "lancio senza bersaglio"      /attacco/lancia "tubi[]=1"
storto "lancio da tubo inesistente"  /attacco/lancia "bersaglio=1" "tubi[]=99"
storto "lancio con quota assurda"    /attacco/lancia "bersaglio=1" "tubi[]=1" "quota=-9000"
storto "lancio con ventaglio enorme" /attacco/lancia "bersaglio=1" "tubi[]=1" "ventaglio=100000"
storto "manovra con rotta storta"    /attacco/manovra "heading=-4000"
storto "cannone senza bersaglio"     /attacco/cannone
storto "ingaggio di un incontro altrui" /attacco/ingaggia "convoy_id=999999"

# Radio e bacheca: testo libero
storto "trasmissione vuota"          /radio/trasmetti "tipo=rapporto" "testo="
storto "trasmissione chilometrica"   /radio/trasmetti "tipo=rapporto" "testo=$(head -c 5000 /dev/zero | tr '\0' 'A')"
storto "kurzsignal inventato"        /radio/trasmetti "tipo=kurzsignal" "kurz=aiuto"
storto "bacheca vuota"               /bacheca "testo="
storto "bacheca chilometrica"        /bacheca "testo=$(head -c 5000 /dev/zero | tr '\0' 'B')"

# Cantiere e carriera (si fanno in porto: qui devono rimbalzare, non rompersi)
storto "tipo di battello inventato"  /cantiere/tipo "type_key=Bismarck"
storto "allestimento negativo"       /cantiere/allestimento "siluri=-20" "nafta=-5"
storto "emblema inventato"           /cantiere/emblema "chiave=../../etc/passwd"
storto "apparato inventato"          /comandante/compra "ukey=raggio della morte"
storto "corso inventato"             /comandante/addestra "specialita=astronauti"

verifica "nessun ordine storto ha fatto cadere il server" "0" "${CINQUECENTO}"
printf '  \033[0;32mok\033[0m    %d carichi storti provati\n' "${PROVATE}"

# --- 2. il diario non si e' riempito di errori -------------------------------
ERRORI_DOPO=$(grep -c "ERROR" "${DIARIO}" 2>/dev/null || echo 0)
verifica "nessun errore nuovo nel diario" "${ERRORI_PRIMA}" "${ERRORI_DOPO}"

# --- 3. lo stato del battello e' rimasto dentro i limiti ---------------------
FUORI=$(php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$b = App\Core\Database::first("SELECT * FROM boats WHERE user_id = ?", [(int) $u["id"]]);
if ($b === null) { echo "battello sparito"; exit; }
$t = App\Sim\World::type((string) $b["type_key"]);
$limiti = [
  "ordered_speed_kn" => [0, max((float) $t["speed_surf_kn"], (float) $t["speed_sub_kn"])],
  "speed_kn"         => [0, max((float) $t["speed_surf_kn"], (float) $t["speed_sub_kn"]) + 0.5],
  "ordered_depth_m"  => [0, (float) $t["crush_depth_max_m"]],
  "depth_m"          => [0, (float) $t["crush_depth_max_m"]],
  "fuel_t"           => [0, 300], "battery_pct" => [0, 100], "air_pct" => [0, 100],
  "co2_pct"          => [0, 100], "provisions_days" => [0, 200],
  "hull_stress"      => [0, 100], "hull_integrity"  => [0, 100],
  "lat" => [-90, 90], "lon" => [-180, 180], "heading" => [0, 360],
  "silent" => [0, 1], "watch_no" => [1, 3],
];
$guai = [];
foreach ($limiti as $col => [$min, $max]) {
  $v = (float) $b[$col];
  if (is_nan($v) || $v < $min - 0.001 || $v > $max + 0.001) {
    $guai[] = sprintf("%s=%s (limiti %s..%s)", $col, $b[$col], $min, $max);
  }
}
echo implode("; ", $guai);' "${UTENTE}")
verifica "lo stato del battello e' rimasto dentro i limiti" "" "${FUORI}"

# --- 4. e il mondo non si e' sporcato ----------------------------------------
SPORCO=$(php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
$g = [];
$n = App\Core\Database::first("SELECT COUNT(*) n FROM boat_waypoints WHERE boat_id IN (SELECT id FROM boats WHERE user_id = ?)", [(int) $u["id"]]);
if ((int) $n["n"] > 24) { $g[] = "punti di rotta: " . $n["n"]; }
$r = App\Core\Database::first("SELECT MAX(CHAR_LENGTH(testo)) l FROM radio_messages WHERE boat_id IN (SELECT id FROM boats WHERE user_id = ?)", [(int) $u["id"]]);
if ((int) $r["l"] > 480) { $g[] = "messaggio radio da " . $r["l"] . " caratteri"; }
$b = App\Core\Database::first("SELECT MAX(CHAR_LENGTH(testo)) l FROM bacheca WHERE user_id = ?", [(int) $u["id"]]);
if ((int) $b["l"] > 1000) { $g[] = "messaggio in bacheca da " . $b["l"] . " caratteri"; }
echo implode("; ", $g);' "${UTENTE}")
verifica "niente di sovradimensionato e' finito nel database" "" "${SPORCO}"

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'; exit 0
fi
printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
exit 1
