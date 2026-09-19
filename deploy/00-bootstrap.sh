#!/usr/bin/env bash
#
# Parametri d'ambiente (facoltativi, con default ragionevoli):
#
#   OWNER_USER   utente che possiede la cartella del sito (default: chi invoca sudo)
#   SITE_DIR     cartella servita dal web server (default: quella che contiene questo script)
#   CONFIG_DIR   cartella dei segreti, FUORI dal DocumentRoot (default: /etc/atlantik)
#   PUBLIC_URL   indirizzo pubblico del gioco
#   ADMIN_EMAIL  destinatario delle notifiche di amministrazione
#
#
# Atlantik — bootstrap dell'ambiente su Balthasar.
# Da eseguire UNA VOLTA con sudo:   sudo bash deploy/00-bootstrap.sh
#
# Idempotente: puo' essere rilanciato senza danni. Non tocca i vhost esistenti
# (usa conf-available/a2enconf), fa backup di cio' che sostituisce e verifica la
# configurazione Apache con apache2ctl configtest PRIMA del reload.
#
# Cosa fa:
#   1. rende la cartella del sito scrivibile dall'utente indicato, gruppo www-data (setgid)
#   2. crea la cartella dei segreti (fuori dal DocumentRoot)
#   3. crea database e utente MariaDB atl_atlantik con password generata
#   4. scrive il file di configurazione se non esiste
#   5. installa e abilita la conf Apache di Atlantik
#
set -euo pipefail

OWNER_USER="${OWNER_USER:-$(logname 2>/dev/null || echo "${SUDO_USER:-root}")}"
OWNER_GROUP="www-data"
PROJECT_DIR="${SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
CONFIG_DIR="${CONFIG_DIR:-/etc/atlantik}"
CONFIG_FILE="${CONFIG_DIR}/config.php"
DB_NAME="atl_atlantik"
DB_USER="atl_atlantik"
APACHE_CONF_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/apache-atlantik.conf"
APACHE_CONF_DST="/etc/apache2/conf-available/atlantik.conf"
STAMP="$(date +%Y%m%d-%H%M%S)"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[0;32m%s\033[0m\n' "$*"; }
warn(){ printf '    \033[0;33m%s\033[0m\n' "$*"; }

if [[ "${EUID}" -ne 0 ]]; then
  echo "Questo script va eseguito con sudo." >&2
  exit 1
fi

# --- 1. Directory di progetto ------------------------------------------------
say "1/5  Directory di progetto ${PROJECT_DIR}"
mkdir -p "${PROJECT_DIR}"
chown -R "${OWNER_USER}:${OWNER_GROUP}" "${PROJECT_DIR}"
chmod 2775 "${PROJECT_DIR}"
ok "$(stat -c '%U:%G %a' "${PROJECT_DIR}")  ${PROJECT_DIR}"

# --- 2. Directory dei segreti ------------------------------------------------
say "2/5  Directory di configurazione ${CONFIG_DIR}"
mkdir -p "${CONFIG_DIR}"
chown "${OWNER_USER}:${OWNER_GROUP}" "${CONFIG_DIR}"
chmod 2750 "${CONFIG_DIR}"
ok "$(stat -c '%U:%G %a' "${CONFIG_DIR}")  ${CONFIG_DIR}"

# --- 3. Database -------------------------------------------------------------
say "3/5  Database MariaDB ${DB_NAME}"
DB_EXISTS="$(mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';")"
USER_EXISTS="$(mariadb -N -B -e "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';")"

if [[ -f "${CONFIG_FILE}" ]]; then
  # Config gia' presente: NON si tocca la password, si riusa quella del file.
  DB_PASS="$(php -r '$c=require "'"${CONFIG_FILE}"'"; echo $c["db"]["pass"] ?? "";')"
  if [[ -z "${DB_PASS}" ]]; then
    echo "Config presente ma senza password DB leggibile: intervento manuale richiesto." >&2
    exit 1
  fi
  warn "config.php gia' presente: riuso la password esistente (nessuna rigenerazione)."
else
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)"
fi

mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mariadb -e "FLUSH PRIVILEGES;"
[[ "${DB_EXISTS}" == "0" ]] && ok "database creato" || ok "database gia' esistente (conservato)"
[[ "${USER_EXISTS}" == "0" ]] && ok "utente creato" || ok "utente gia' esistente (password riallineata al config)"

# --- 4. File di configurazione ----------------------------------------------
say "4/5  ${CONFIG_FILE}"
if [[ -f "${CONFIG_FILE}" ]]; then
  ok "gia' presente: lasciato intatto"
else
  cat > "${CONFIG_FILE}" <<PHPEOF
<?php

declare(strict_types=1);

/**
 * Atlantik — configurazione con i segreti. FUORI dal DocumentRoot.
 * Generato da deploy/00-bootstrap.sh il ${STAMP}.
 */

return [
    'app' => [
        'name'        => 'Atlantik',
        'env'         => 'production',
        'debug'       => false,
        'timezone'    => 'Europe/Rome',
        'pretty_urls' => true,
        'base_path'   => null,
        'public_url'  => '${PUBLIC_URL:-http://localhost/atlantik}',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => '${DB_NAME}',
        'user'    => '${DB_USER}',
        'pass'    => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'atlantik_sess',
        'session_ttl'  => 60 * 60 * 8,
    ],

    // Trasporto e-mail: 'log' finche' non si compilano le credenziali Brevo.
    // Le chiavi reali vanno copiate dal config di SubSpazio (stesso account Brevo).
    'mail' => [
        'transport'   => 'log',   // 'smtp' dopo aver messo le credenziali Brevo
        'smtp_host'   => 'smtp-relay.brevo.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => 'CAMBIAMI',
        'smtp_pass'   => 'CAMBIAMI',
        'from_email'  => 'CAMBIAMI',   // deve essere un mittente verificato in Brevo
        'from_name'   => 'Atlantik — BdU',
        'timeout'     => 15,
    ],

    'notify' => [
        'new_registration' => true,
        'admin_email'      => '${ADMIN_EMAIL:-root@localhost}',
    ],

    'world' => [
        'seed'       => ${RANDOM}${RANDOM},
        'time_ratio' => 30,   // 1 minuto reale = 30 minuti di gioco
    ],
];
PHPEOF
  chown "${OWNER_USER}:${OWNER_GROUP}" "${CONFIG_FILE}"
  chmod 0640 "${CONFIG_FILE}"
  ok "creato ($(stat -c '%U:%G %a' "${CONFIG_FILE}"))"
fi

# --- 5. Apache ---------------------------------------------------------------
say "5/5  Configurazione Apache"
if [[ ! -f "${APACHE_CONF_SRC}" ]]; then
  echo "Manca ${APACHE_CONF_SRC}" >&2
  exit 1
fi
if [[ -f "${APACHE_CONF_DST}" ]]; then
  cp -a "${APACHE_CONF_DST}" "${APACHE_CONF_DST}.bak-${STAMP}"
  ok "backup: ${APACHE_CONF_DST}.bak-${STAMP}"
fi
install -m 0644 -o root -g root "${APACHE_CONF_SRC}" "${APACHE_CONF_DST}"
a2enmod rewrite >/dev/null 2>&1 || true
a2enconf atlantik >/dev/null
if apache2ctl configtest 2>&1 | tail -1 | grep -q "Syntax OK"; then
  systemctl reload apache2
  ok "configtest OK, apache2 ricaricato"
else
  warn "configtest FALLITO: nessun reload eseguito. Output:"
  apache2ctl configtest || true
  exit 1
fi

say "Fatto."
cat <<SUMMARY
    Progetto : ${PROJECT_DIR}          (${OWNER_USER}:${OWNER_GROUP}, 2775)
    Segreti  : ${CONFIG_FILE}
    Database : ${DB_NAME} / utente ${DB_USER} @localhost
    URL      : ${PUBLIC_URL:-http://localhost/atlantik}/

    La password del DB e' SOLO dentro ${CONFIG_FILE} (0640). Non compare nei log.
    Il cron del tick verra' installato in fase F1, quando bin/tick.php esistera'.
SUMMARY
