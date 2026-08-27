#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE=""
usage(){ cat <<USAGE
Usage: sudo $0 [--admin-panel|--puppet-server|--all]
Without arguments an interactive menu is shown.
USAGE
}
[[ ${EUID} -eq 0 ]] || { echo "ERROR: Run as root." >&2; exit 1; }
case "${1:-}" in --admin-panel) MODE=admin;; --puppet-server) MODE=puppet;; --all) MODE=all;; -h|--help) usage;exit 0;; "") ;; *) usage;exit 2;; esac
if [[ -z "$MODE" ]]; then
cat <<MENU
==================================================
HomeLAB SimpleLAB Installer
==================================================
1) Install AdminPanel
2) Install Puppet Server
3) Install AdminPanel + Puppet Server
4) Exit
MENU
read -r -p "Select option: " choice
case "$choice" in 1) MODE=admin;; 2) MODE=puppet;; 3) MODE=all;; 4) exit 0;; *) echo "Invalid option." >&2;exit 2;; esac
fi
case "$MODE" in admin) exec "$ROOT_DIR/AdminPanel/install.sh";; puppet) exec "$ROOT_DIR/PuppetServerInstall.sh";; all) "$ROOT_DIR/AdminPanel/install.sh"; "$ROOT_DIR/PuppetServerInstall.sh";; esac
