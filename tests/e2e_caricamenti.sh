#!/usr/bin/env bash
#
# Atlantik — quello che i giocatori portano da casa.
#
#   bash tests/e2e_caricamenti.sh
#
# Il caricamento di un'immagine e' l'unico punto in cui il gioco accetta un
# file binario da un estraneo, e va trattato come tale. Questa prova ci passa
# sopra tutto quello che un estraneo proverebbe:
#
#   - un PNG valido con del codice PHP appeso in coda (un "polyglot");
#   - un SVG, che e' un documento e puo' contenere script;
#   - un file che dichiara di essere un'immagine e non lo e';
#   - una bomba di decompressione: centodiciassette kilobyte che diventano
#     centoquarantaquattro megabyte di bitmap una volta aperti;
#   - e la domanda che nessuno si fa mai: se un .php finisse comunque in quella
#     cartella, il server lo eseguirebbe?
#
# Quest'ultima, il 19/09/2026, aveva una risposta imbarazzante: SI'. Nelle due
# cartelle c'era un .htaccess che dichiarava di negare l'esecuzione, ma la
# configurazione del gioco imposta AllowOverride None — quindi quegli .htaccess
# non venivano letti nemmeno. Una difesa scritta, documentata, creduta, e
# inerte. La regola vera sta adesso in deploy/apache-atlantik.conf, e questa
# prova esiste perche' nessuno debba piu' crederci sulla parola.
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/atlantik}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JAR="$(mktemp)"
UTENTE="prova caricamenti $(date +%s)"
PASS="fotografia66"
FALLITI=0
CARTELLA="${ROOT}/assets/img/ritratti/caricati"
TMP="$(mktemp -d)"

trap 'php "'"${ROOT}"'/bin/_cleanup_test_user.php" "'"${UTENTE}"'" >/dev/null 2>&1; rm -f "${JAR}" "${CARTELLA}"/_prova_*.php "${CARTELLA}"/_prova_*.webp; rm -rf "${TMP}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI+1)); fi
}

php "${ROOT}/bin/_prova_sfrena.php" >/dev/null 2>&1
echo "Prova end-to-end dei caricamenti — ${BASE_URL}"

php "${ROOT}/bin/_prova_utente.php" "${UTENTE}" "${PASS}" >/dev/null 2>&1
php -r '
require "'"${ROOT}"'/bin/_bootstrap.php";
$u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
if ($u !== null && App\Game\Comandante::corrente((int) $u["id"]) === null) {
    App\Game\Comandante::crea((int) $u["id"], ["nome" => "Caricamenti Prova " . substr((string) time(), -4),
        "nato_il" => "1912-01-01", "nato_a" => "Kiel", "ritratto" => "r1", "base" => "lorient"]);
}' "${UTENTE}" >/dev/null 2>&1

TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${UTENTE}" --data-urlencode "password=${PASS}"
TOK=$(c "${BASE_URL}/comandante/profilo" | token_da)

if [[ -z "${TOK}" ]]; then
  printf '  \033[0;33m--\033[0m    prova saltata: non si riesce ad aprire il profilo del giocatore di prova\n'
  exit 0
fi

carica() { # file, [tipo]
  c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/comandante/ritratto/carica" \
    -F "_token=${TOK}" -F "ritratto=@$1${2:+;type=$2}"
}
# Che cosa porta in faccia il comandante, adesso. E' questa la domanda giusta:
# contare i file nella cartella non funziona, perche' ogni caricamento
# SOSTITUISCE il ritratto precedente e la potatura degli orfani se lo porta via
# subito dopo. Il conto resterebbe uguale anche quando il caricamento e'
# riuscito benissimo.
ritratto_adesso() {
  php -r '
  require "'"${ROOT}"'/bin/_bootstrap.php";
  $u = App\Core\Database::first("SELECT id FROM users WHERE username = ?", [$argv[1]]);
  if ($u === null) { echo "-"; exit; }
  $c = App\Core\Database::first("SELECT ritratto_file FROM commanders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [(int) $u["id"]]);
  echo (string) ($c["ritratto_file"] ?? "-");' "${UTENTE}"
}
cambiato() { [[ "$1" != "$2" ]] && echo si || echo no; }

# --- 1. il controllo positivo -------------------------------------------------
#
# Prima di tutto: un'immagine buona deve passare. Senza questo, una batteria di
# rifiuti proverebbe soltanto che il caricamento e' rotto.
php -r '$im = imagecreatetruecolor(400, 500);
  imagefilledrectangle($im, 0, 0, 399, 499, imagecolorallocate($im, 60, 80, 100));
  imagepng($im, $argv[1]);' "${TMP}/buona.png"
PRIMA=$(ritratto_adesso)
carica "${TMP}/buona.png" "image/png" >/dev/null
DOPO=$(ritratto_adesso)
verifica "un'immagine buona viene accettata (controllo positivo)" "si" "$(cambiato "${PRIMA}" "${DOPO}")"

SALVATO="${CARTELLA}/${DOPO}"
verifica "e viene riscritta in WebP" "si" "$(file -b --mime-type "${SALVATO}" 2>/dev/null | grep -q webp && echo si || echo no)"
NOME="${DOPO%.webp}"
IMPRONTA=$(sha256sum "${SALVATO}" 2>/dev/null | cut -d' ' -f1)
verifica "col nome che e' l'impronta del contenuto" "${IMPRONTA}" "${NOME}"

# --- 2. quello che non deve entrare -------------------------------------------
printf '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>' > "${TMP}/cattivo.svg"
PRIMA=$(ritratto_adesso); carica "${TMP}/cattivo.svg" "image/svg+xml" >/dev/null
verifica "un SVG non entra" "no" "$(cambiato "${PRIMA}" "$(ritratto_adesso)")"

printf 'GIF89a<?php system($_GET["c"]); ?>' > "${TMP}/finto.gif"
PRIMA=$(ritratto_adesso); carica "${TMP}/finto.gif" "image/gif" >/dev/null
verifica "un file che finge di essere un'immagine non entra" "no" "$(cambiato "${PRIMA}" "$(ritratto_adesso)")"

printf 'non sono un immagine' > "${TMP}/testo.png"
PRIMA=$(ritratto_adesso); carica "${TMP}/testo.png" "image/png" >/dev/null
verifica "e nemmeno del testo con l'estensione giusta" "no" "$(cambiato "${PRIMA}" "$(ritratto_adesso)")"

# --- 3. il polyglot: entra, ma disarmato --------------------------------------
php -r '$im = imagecreatetruecolor(300, 300);
  imagefilledrectangle($im, 0, 0, 299, 299, imagecolorallocate($im, 130, 40, 40));
  imagepng($im, $argv[1]);
  file_put_contents($argv[1], file_get_contents($argv[1]) . "<?php system(\"id\"); ?>");' "${TMP}/polyglot.png"
PRIMA=$(ritratto_adesso); carica "${TMP}/polyglot.png" "image/png" >/dev/null
NUOVO="${CARTELLA}/$(ritratto_adesso)"
verifica "un PNG col codice appeso in coda viene accettato..." "si" "$(cambiato "${PRIMA}" "$(basename "${NUOVO}")")"
verifica "...ma del codice non resta traccia nel file salvato" "no" \
  "$(grep -qa 'system(' "${NUOVO}" 2>/dev/null && echo si || echo no)"

# --- 4. la bomba di decompressione --------------------------------------------
php -r '$im = imagecreatetruecolor(6000, 6000);
  imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
  imagepng($im, $argv[1], 9);' "${TMP}/bomba.png"
PESO=$(stat -c%s "${TMP}/bomba.png")
PRIMA=$(ritratto_adesso)
INIZIO=$(date +%s%N)
carica "${TMP}/bomba.png" "image/png" >/dev/null
DURATA=$(( ($(date +%s%N) - INIZIO) / 1000000 ))
verifica "una bomba di decompressione non entra" "no" "$(cambiato "${PRIMA}" "$(ritratto_adesso)")"
verifica "e viene respinta subito, senza aprirla" "1" "$(( DURATA < 400 ? 1 : 0 ))"
printf '        \033[0;90m%s KB sul filo, %s milioni di pixel, respinta in %s ms\033[0m\n' \
  "$(( PESO / 1024 ))" "36" "${DURATA}"

# --- 5. e una fotografia da telefono deve passare ------------------------------
php -r '$im = imagecreatetruecolor(4032, 3024);
  imagefill($im, 0, 0, imagecolorallocate($im, 70, 90, 110));
  imagejpeg($im, $argv[1], 85);' "${TMP}/telefono.jpg"
PRIMA=$(ritratto_adesso); carica "${TMP}/telefono.jpg" "image/jpeg" >/dev/null
ULTIMO=$(ritratto_adesso)
verifica "una fotografia da telefono (12,2 milioni di pixel) passa" "si" "$(cambiato "${PRIMA}" "${ULTIMO}")"

# --- 6. la cartella non si sfoglia e non si esegue -----------------------------
CODICE=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${HOST_HDR}" "${BASE_URL}/assets/img/ritratti/caricati/")
verifica "la cartella dei caricamenti non si sfoglia" "403" "${CODICE}"

printf '<?php echo "ESEGUITO"; ?>' > "${CARTELLA}/_prova_esecuzione.php"
RISPOSTA=$(curl -s -H "Host: ${HOST_HDR}" "${BASE_URL}/assets/img/ritratti/caricati/_prova_esecuzione.php")
rm -f "${CARTELLA}/_prova_esecuzione.php"
if [[ "${RISPOSTA}" == *ESEGUITO* ]]; then
  printf '  \033[0;31mKO\033[0m    un .php nella cartella dei caricamenti NON viene eseguito\n'
  printf '        \033[0;90mil server lo esegue: installa la regola con  sudo bash deploy/03-aggiorna-apache.sh\033[0m\n'
  FALLITI=$((FALLITI+1))
else
  printf '  \033[0;32mok\033[0m    un .php nella cartella dei caricamenti non viene eseguito\n'
fi

# Un'immagine vera, invece, si serve: la cartella non dev'essere murata.
if [[ -n "${ULTIMO}" && "${ULTIMO}" != "-" ]]; then
  CODICE=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${HOST_HDR}" "${BASE_URL}/assets/img/ritratti/caricati/${ULTIMO}")
  verifica "ma le immagini si servono regolarmente" "200" "${CODICE}"
fi

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'; exit 0
fi
printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
exit 1
