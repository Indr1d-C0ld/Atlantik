#!/usr/bin/env bash
#
# Atlantik — copia di sicurezza notturna.
#
# Salva due cose, che insieme bastano a ricostruire tutto:
#   1. il database (mysqldump coerente, senza bloccare il gioco);
#   2. il repository del codice, come bundle Git autoportante.
#
# Le credenziali non compaiono mai sulla riga di comando (sarebbero visibili
# in 'ps'): si passano da un file temporaneo a 0600, cancellato all'uscita.
#
# Uso:  deploy/backup.sh          (idempotente, pensato per il cron)
# Ripristino di un dump:
#   zcat FILE.sql.gz | mysql -u atl_atlantik -p atl_atlantik
# Ripristino del codice da un bundle:
#   git clone atlantik-codice-AAAAMMGG.bundle atlantik

set -euo pipefail

PROGETTO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINAZIONE="${ATLANTIK_BACKUP_DIR:-${HOME}/backup-atlantik}"
GIORNI="${ATLANTIK_BACKUP_GIORNI:-14}"
REGISTRO="${PROGETTO}/storage/logs/backup.log"

mkdir -p "${DESTINAZIONE}" "$(dirname "${REGISTRO}")"
chmod 700 "${DESTINAZIONE}"

nota() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "${REGISTRO}"; }

# --- credenziali, lette dalla configurazione vera -------------------------
leggi() { php -r '
    require "'"${PROGETTO}"'/bin/_bootstrap.php";
    echo (string) App\Core\Config::get("db." . $argv[1], "");
' "$1"; }

DB_HOST="$(leggi host)"; DB_PORT="$(leggi port)"
DB_NOME="$(leggi name)"; DB_UTENTE="$(leggi user)"; DB_PASS="$(leggi pass)"

if [[ -z "${DB_NOME}" || -z "${DB_UTENTE}" ]]; then
  nota "ERRORE: configurazione del database non leggibile"
  echo "Configurazione del database non leggibile." >&2
  exit 1
fi

CRED="$(mktemp)"
chmod 600 "${CRED}"
trap 'rm -f "${CRED}"' EXIT
cat > "${CRED}" <<CONF
[client]
host=${DB_HOST:-127.0.0.1}
port=${DB_PORT:-3306}
user=${DB_UTENTE}
password=${DB_PASS}
CONF

# --- 1. database -----------------------------------------------------------
STAMPA="$(date '+%Y%m%d-%H%M')"
DUMP="${DESTINAZIONE}/atlantik-db-${STAMPA}.sql.gz"

mysqldump --defaults-file="${CRED}" \
  --single-transaction --quick --routines --events --triggers \
  --default-character-set=utf8mb4 \
  "${DB_NOME}" | gzip -9 > "${DUMP}"

if [[ ! -s "${DUMP}" ]]; then
  nota "ERRORE: dump vuoto, rimosso"
  rm -f "${DUMP}"
  exit 1
fi
chmod 600 "${DUMP}"

# --- 2. codice -------------------------------------------------------------
BUNDLE="${DESTINAZIONE}/atlantik-codice-${STAMPA}.bundle"
if git -C "${PROGETTO}" rev-parse --git-dir >/dev/null 2>&1; then
  if git -C "${PROGETTO}" bundle create "${BUNDLE}" --all >/dev/null 2>&1; then
    chmod 600 "${BUNDLE}"
  else
    nota "avviso: nessun commit da imbustare"
    rm -f "${BUNDLE}"
  fi
fi

# --- 3. potatura -----------------------------------------------------------
find "${DESTINAZIONE}" -maxdepth 1 -type f \
     \( -name 'atlantik-db-*.sql.gz' -o -name 'atlantik-codice-*.bundle' \) \
     -mtime "+${GIORNI}" -delete

nota "ok — $(du -h "${DUMP}" | cut -f1) di database, conservazione ${GIORNI} giorni"
echo "Copia eseguita: ${DUMP}"
