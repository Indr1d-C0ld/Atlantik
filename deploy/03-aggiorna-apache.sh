#!/usr/bin/env bash
#
# Atlantik — aggiorna la configurazione Apache del gioco.
#
#   sudo bash deploy/03-aggiorna-apache.sh
#
# Serve quando deploy/apache-atlantik.conf cambia: copia la nuova versione in
# /etc/apache2/conf-available/atlantik.conf, tiene da parte quella vecchia,
# CONTROLLA LA SINTASSI prima di ricaricare, e se il controllo fallisce rimette
# indietro la vecchia senza toccare il server.
#
# Alla fine verifica sul campo: mette un file .php nella cartella dei
# caricamenti, lo chiede al server, e pretende che NON venga eseguito. Se viene
# eseguito lo dice chiaro e tondo — una difesa che non si prova non e' una
# difesa, ed e' esattamente com'era prima del 19/09/2026.
#
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Serve sudo: sudo bash deploy/03-aggiorna-apache.sh" >&2
  exit 1
fi

RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SORGENTE="${RADICE}/deploy/apache-atlantik.conf"
DESTINAZIONE="/etc/apache2/conf-available/atlantik.conf"
COPIA="/etc/apache2/conf-available/atlantik.conf.prima-$(date +%Y%m%d-%H%M%S)"

[[ -f "${SORGENTE}" ]] || { echo "Manca ${SORGENTE}" >&2; exit 1; }

echo "→ copia di sicurezza della configurazione attuale"
if [[ -f "${DESTINAZIONE}" ]]; then
  cp -a "${DESTINAZIONE}" "${COPIA}"
  echo "   ${COPIA}"
fi

echo "→ installo la nuova"
install -m 0644 -o root -g root "${SORGENTE}" "${DESTINAZIONE}"

echo "→ controllo la sintassi"
if ! apache2ctl -t 2>&1 | tail -3; then
  echo "   sintassi rifiutata: rimetto la vecchia e non ricarico" >&2
  if [[ -f "${COPIA}" ]]; then cp -a "${COPIA}" "${DESTINAZIONE}"; fi
  exit 1
fi

echo "→ abilito e ricarico"
a2enconf atlantik >/dev/null 2>&1 || true
systemctl reload apache2

echo "→ verifica sul campo: un .php nella cartella dei caricamenti non deve eseguirsi"
CARTELLA="${RADICE}/assets/img/ritratti/caricati"
PROVA="${CARTELLA}/_verifica_esecuzione.php"
printf '<?php echo "ESEGUITO"; ?>' > "${PROVA}"
chmod 0644 "${PROVA}"
RISPOSTA="$(curl -s -H 'Host: localhost' "http://localhost/atlantik/assets/img/ritratti/caricati/_verifica_esecuzione.php" || true)"
CODICE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' "http://localhost/atlantik/assets/img/ritratti/caricati/_verifica_esecuzione.php" || true)"
rm -f "${PROVA}"

if [[ "${RISPOSTA}" == *ESEGUITO* ]]; then
  echo "   ✗ IL FILE E' STATO ESEGUITO (HTTP ${CODICE}). La regola non e' attiva." >&2
  exit 1
fi
echo "   ✓ non eseguito (HTTP ${CODICE})"
echo
echo "Fatto. Adesso: bash tests/e2e_caricamenti.sh"
