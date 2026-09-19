#!/usr/bin/env bash
#
# Atlantik — prova di ripristino.
#
# Una copia di sicurezza che non e' mai stata ripristinata non e' una copia di
# sicurezza: e' una speranza. Questo script prende l'ultimo dump, lo carica in
# un database temporaneo, conta cio' che e' tornato su e lo butta via.
#
# Richiede sudo perche' creare un database non e' un permesso dell'utente
# applicativo (e non deve esserlo).
#
# Uso:  sudo deploy/02-prova-ripristino.sh

set -euo pipefail

PROVA="atl_atlantik_prova"
ORIGINE="${ATLANTIK_BACKUP_DIR:-${SUDO_USER:+/home/${SUDO_USER}}/backup-atlantik}"
ORIGINE="${ORIGINE:-${HOME}/backup-atlantik}"

ULTIMO="$(ls -1t "${ORIGINE}"/atlantik-db-*.sql.gz 2>/dev/null | head -1 || true)"
if [[ -z "${ULTIMO}" ]]; then
  echo "Nessun dump trovato in ${ORIGINE}" >&2
  exit 1
fi
echo "Dump in prova: ${ULTIMO}"

pulisci() { mysql -e "DROP DATABASE IF EXISTS \`${PROVA}\`;" >/dev/null 2>&1 || true; }
trap pulisci EXIT

mysql -e "DROP DATABASE IF EXISTS \`${PROVA}\`; CREATE DATABASE \`${PROVA}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat "${ULTIMO}" | mysql "${PROVA}"

TAB=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${PROVA}';")
UTE=$(mysql -N -B -e "SELECT COUNT(*) FROM \`${PROVA}\`.users;")
BAT=$(mysql -N -B -e "SELECT COUNT(*) FROM \`${PROVA}\`.boats;")
NAV=$(mysql -N -B -e "SELECT COUNT(*) FROM \`${PROVA}\`.ships;")
EVE=$(mysql -N -B -e "SELECT COUNT(*) FROM \`${PROVA}\`.patrol_events;")

echo
echo "Ripristinati:  ${TAB} tabelle"
echo "               ${UTE} utenti, ${BAT} battelli"
echo "               ${NAV} navi, ${EVE} eventi di giornale"
echo
if [[ "${TAB}" -ge 40 && "${UTE}" -ge 1 ]]; then
  echo "PROVA SUPERATA — il ripristino funziona."
else
  echo "PROVA FALLITA — il dump non contiene quello che dovrebbe." >&2
  exit 1
fi
