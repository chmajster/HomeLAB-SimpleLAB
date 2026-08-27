#!/usr/bin/env bash
set -euo pipefail

PROGRAM_NAME="HomeLAB SimpleLAB - VM OnBoarding"
SERVER=""
TOKEN="${SIMPLELAB_API_TOKEN:-}"
PUPPET_REPO_USER="${PUPPET_REPO_USER:-forge-key}"
PUPPET_REPO_KEY="${PUPPET_REPO_KEY:-}"
PUPPET_MAJOR=""
WAIT_SECONDS=300

usage() {
  cat <<USAGE
Usage:
  sudo $0 <ADMIN_PANEL_IP_OR_URL> [--token TOKEN]
  sudo $0 --server <ADMIN_PANEL_IP_OR_URL> --token TOKEN [options]

Options:
  --server VALUE          AdminPanel IP or URL
  --token TOKEN           HomeLAB SimpleLAB API bearer token
  --puppet-major 8|9      Override Puppet major version
  --repo-user USER        Puppet Core repository user (default: forge-key)
  --repo-key KEY          Puppet Core/Forge API key when required
  --wait-seconds N        Maximum wait for first Puppet run (default: 300)
  -h, --help              Show this help
USAGE
}

log() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "Run this script as root."

if [[ $# -gt 0 && "$1" != --* ]]; then
  SERVER="$1"
  shift
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --server) SERVER="${2:-}"; shift 2 ;;
    --token) TOKEN="${2:-}"; shift 2 ;;
    --puppet-major) PUPPET_MAJOR="${2:-}"; shift 2 ;;
    --repo-user) PUPPET_REPO_USER="${2:-}"; shift 2 ;;
    --repo-key) PUPPET_REPO_KEY="${2:-}"; shift 2 ;;
    --wait-seconds) WAIT_SECONDS="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done

[[ -n "$SERVER" ]] || { usage; exit 2; }
[[ -n "$TOKEN" ]] || die "API token is required. Use --token or SIMPLELAB_API_TOKEN."
[[ "$WAIT_SECONDS" =~ ^[0-9]+$ ]] || die "--wait-seconds must be an integer."

if [[ "$SERVER" =~ ^https?:// ]]; then BASE_URL="${SERVER%/}"; else BASE_URL="http://${SERVER%/}"; fi

printf '==================================================\n%s\n==================================================\n\n' "$PROGRAM_NAME"
log "[1/7] Checking operating system..."
[[ -r /etc/os-release ]] || die "/etc/os-release not found."
# shellcheck disable=SC1091
source /etc/os-release
OS_ID="${ID:-unknown}"; OS_VERSION="${VERSION_ID:-unknown}"; CODENAME="${VERSION_CODENAME:-}"; ARCH="$(uname -m)"
case "$OS_ID" in ubuntu|debian) ;; *) die "Unsupported OS: $OS_ID $OS_VERSION" ;; esac
if [[ -z "$CODENAME" ]]; then CODENAME="$(. /etc/os-release; printf '%s' "${VERSION_CODENAME:-}")"; fi
[[ -n "$CODENAME" ]] || die "Cannot determine distribution codename."
if [[ -z "$PUPPET_MAJOR" ]]; then case "$CODENAME" in jammy|bookworm) PUPPET_MAJOR="8" ;; *) PUPPET_MAJOR="9" ;; esac; fi
[[ "$PUPPET_MAJOR" == "8" || "$PUPPET_MAJOR" == "9" ]] || die "Puppet major must be 8 or 9."

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl jq iproute2 >/dev/null
MACHINE_ID="$(tr -d '[:space:]' < /etc/machine-id)"
[[ "$MACHINE_ID" =~ ^[A-Za-z0-9._:-]{8,128}$ ]] || die "Invalid /etc/machine-id."
CURRENT_HOSTNAME="$(hostname)"
PRIMARY_IFACE="$(ip route show default 2>/dev/null | awk 'NR==1 {print $5}' || true)"; PRIMARY_IP=""; PRIMARY_MAC=""
if [[ -n "$PRIMARY_IFACE" ]]; then
  PRIMARY_IP="$(ip -4 addr show dev "$PRIMARY_IFACE" 2>/dev/null | awk '/inet / {sub(/\/.*/,"",$2); print $2; exit}' || true)"
  PRIMARY_MAC="$(cat "/sys/class/net/${PRIMARY_IFACE}/address" 2>/dev/null || true)"
fi
PAYLOAD="$(jq -n --arg machine_id "$MACHINE_ID" --arg current_hostname "$CURRENT_HOSTNAME" --arg ip "$PRIMARY_IP" --arg mac "$PRIMARY_MAC" --arg os "$OS_ID" --arg os_version "$OS_VERSION" --arg architecture "$ARCH" '{machine_id:$machine_id,current_hostname:$current_hostname,ip:$ip,mac:$mac,os:$os,os_version:$os_version,architecture:$architecture}')"

log "[2/7] Connecting to AdminPanel..."
TMP_RESPONSE="$(mktemp)"; trap 'rm -f "$TMP_RESPONSE"' EXIT
HTTP_CODE="$(curl --silent --show-error --connect-timeout 10 --max-time 30 -o "$TMP_RESPONSE" -w '%{http_code}' -X POST "${BASE_URL}/api/v1/onboarding" -H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json' --data "$PAYLOAD")" || die "Cannot connect to AdminPanel: $BASE_URL"
[[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "201" ]] || die "AdminPanel returned HTTP $HTTP_CODE: $(cat "$TMP_RESPONSE")"
jq -e '.success == true and (.hostname|type=="string") and (.puppet.server|type=="string") and (.puppet.environment|type=="string")' "$TMP_RESPONSE" >/dev/null || die "AdminPanel returned invalid JSON configuration."
NEW_HOSTNAME="$(jq -r '.hostname' "$TMP_RESPONSE")"
PUPPET_SERVER="$(jq -r '.puppet.server' "$TMP_RESPONSE")"
PUPPET_SERVER_IP="$(jq -r '.puppet.server_ip // empty' "$TMP_RESPONSE")"
PUPPET_ENV="$(jq -r '.puppet.environment' "$TMP_RESPONSE")"
PUPPET_PORT="$(jq -r '.puppet.port // 8140' "$TMP_RESPONSE")"
[[ "$NEW_HOSTNAME" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]] || die "Invalid hostname received from API: $NEW_HOSTNAME"
[[ "$PUPPET_SERVER" =~ ^[A-Za-z0-9.-]+$ ]] || die "Invalid Puppet server received from API."
[[ "$PUPPET_ENV" =~ ^[A-Za-z0-9_-]+$ ]] || die "Invalid Puppet environment received from API."
[[ "$PUPPET_PORT" =~ ^[0-9]+$ ]] || die "Invalid Puppet port received from API."
if [[ -n "$PUPPET_SERVER_IP" && ! "$PUPPET_SERVER_IP" =~ ^[0-9A-Fa-f:.]+$ ]]; then
  die "Invalid Puppet server IP received from API."
fi
log "[3/7] Received hostname: $NEW_HOSTNAME"

log "[4/7] Setting hostname and Puppet hosts entry..."
hostnamectl set-hostname "$NEW_HOSTNAME"; printf '%s\n' "$NEW_HOSTNAME" > /etc/hostname
if grep -qE '^127\.0\.1\.1[[:space:]]+' /etc/hosts; then sed -i -E "s/^127\.0\.1\.1[[:space:]]+.*/127.0.1.1\t${NEW_HOSTNAME}/" /etc/hosts; else printf '127.0.1.1\t%s\n' "$NEW_HOSTNAME" >> /etc/hosts; fi

# AdminPanel and Puppet Server are co-located. The API returns the server IP so
# the new VM can resolve the Puppet hostname even before a DNS record exists.
sed -i '/# HomeLAB-SimpleLAB Puppet Server$/d' /etc/hosts
if [[ -n "$PUPPET_SERVER_IP" ]]; then
  printf '%s\t%s\tpuppet\t# HomeLAB-SimpleLAB Puppet Server\n' "$PUPPET_SERVER_IP" "$PUPPET_SERVER" >> /etc/hosts
  log "      /etc/hosts: $PUPPET_SERVER_IP -> $PUPPET_SERVER"
fi

log "[5/7] Installing Puppet Agent..."
RELEASE_DEB="/tmp/puppet${PUPPET_MAJOR}-release-${CODENAME}.deb"; RELEASE_URL="https://apt-puppetcore.puppet.com/public/puppet${PUPPET_MAJOR}-release-${CODENAME}.deb"
curl --fail --location --silent --show-error --connect-timeout 10 --max-time 60 "$RELEASE_URL" -o "$RELEASE_DEB"
dpkg -i "$RELEASE_DEB" >/dev/null; rm -f "$RELEASE_DEB"
AUTH_FILE="/etc/apt/auth.conf.d/apt-puppetcore-puppet.conf"
if [[ -n "$PUPPET_REPO_KEY" ]]; then printf 'machine apt-puppetcore.puppet.com\nlogin %s\npassword %s\n' "$PUPPET_REPO_USER" "$PUPPET_REPO_KEY" > "$AUTH_FILE"; chmod 600 "$AUTH_FILE"; fi
apt-get update -qq || die "Puppet repository update failed. Current Puppet Core repositories may require --repo-key."
apt-get install -y -qq puppet-agent >/dev/null || die "puppet-agent installation failed. If Puppet Core authentication is enabled, provide --repo-key."
PUPPET_BIN="/opt/puppetlabs/bin/puppet"; [[ -x "$PUPPET_BIN" ]] || PUPPET_BIN="$(command -v puppet || true)"; [[ -x "$PUPPET_BIN" ]] || die "Puppet binary not found after installation."

log "[6/7] Configuring Puppet Agent..."
install -d -m 0755 /etc/puppetlabs/puppet
cat > /etc/puppetlabs/puppet/puppet.conf <<CONF
[main]
server = ${PUPPET_SERVER}
certname = ${NEW_HOSTNAME}
environment = ${PUPPET_ENV}
serverport = ${PUPPET_PORT}

[agent]
runinterval = 30m
CONF

log "[7/7] Starting Puppet Agent..."
set +e
timeout "$WAIT_SECONDS" "$PUPPET_BIN" agent -t --server "$PUPPET_SERVER" --serverport "$PUPPET_PORT" --certname "$NEW_HOSTNAME" --environment "$PUPPET_ENV" --waitforcert 30
PUPPET_RC=$?
set -e
if [[ $PUPPET_RC -eq 124 ]]; then log "Puppet CSR is still waiting for signing after ${WAIT_SECONDS}s."; log "Sign it on the Puppet Server and run: $PUPPET_BIN agent -t"; exit 20; fi
if [[ $PUPPET_RC -ne 0 && $PUPPET_RC -ne 2 ]]; then die "Puppet Agent finished with exit code $PUPPET_RC."; fi
printf '\nOnboarding completed successfully.\n'
