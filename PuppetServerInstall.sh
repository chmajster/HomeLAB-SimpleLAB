#!/usr/bin/env bash
set -euo pipefail

PUPPET_HOSTNAME="puppet.lab.local"
PUPPET_ENV="production"
PUPPET_MAJOR=""
PUPPET_REPO_USER="${PUPPET_REPO_USER:-forge-key}"
PUPPET_REPO_KEY="${PUPPET_REPO_KEY:-}"
AUTOSIGN=0

usage() {
  cat <<USAGE
Usage: sudo $0 [options]
  --hostname NAME         Puppet Server hostname (default: puppet.lab.local)
  --environment NAME      Default environment (default: production)
  --puppet-major 8|9      Override Puppet major version
  --repo-user USER        Puppet repository user (default: forge-key)
  --repo-key KEY          Puppet Core/Forge API key when required
  --autosign              Enable autosign (not recommended outside isolated labs)
  -h, --help              Show help
USAGE
}

die(){ echo "ERROR: $*" >&2; exit 1; }
[[ ${EUID} -eq 0 ]] || die "Run as root."

while [[ $# -gt 0 ]]; do
  case "$1" in
    --hostname) PUPPET_HOSTNAME="${2:-}"; shift 2 ;;
    --environment) PUPPET_ENV="${2:-}"; shift 2 ;;
    --puppet-major) PUPPET_MAJOR="${2:-}"; shift 2 ;;
    --repo-user) PUPPET_REPO_USER="${2:-}"; shift 2 ;;
    --repo-key) PUPPET_REPO_KEY="${2:-}"; shift 2 ;;
    --autosign) AUTOSIGN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done

[[ "$PUPPET_HOSTNAME" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || die "Invalid hostname."
[[ "$PUPPET_ENV" =~ ^[A-Za-z0-9_-]+$ ]] || die "Invalid environment."
[[ -r /etc/os-release ]] || die "/etc/os-release not found."
# shellcheck disable=SC1091
source /etc/os-release
case "${ID:-}" in ubuntu|debian) ;; *) die "Unsupported OS: ${ID:-unknown}" ;; esac
CODENAME="${VERSION_CODENAME:-}"
[[ -n "$CODENAME" ]] || die "Cannot determine VERSION_CODENAME."

if [[ -z "$PUPPET_MAJOR" ]]; then case "$CODENAME" in jammy|bookworm) PUPPET_MAJOR=8 ;; *) PUPPET_MAJOR=9 ;; esac; fi
[[ "$PUPPET_MAJOR" == 8 || "$PUPPET_MAJOR" == 9 ]] || die "Puppet major must be 8 or 9."

printf '==================================================\nHomeLAB SimpleLAB - Puppet Server Installer\n==================================================\n'
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl >/dev/null
hostnamectl set-hostname "$PUPPET_HOSTNAME"
printf '%s\n' "$PUPPET_HOSTNAME" > /etc/hostname

RELEASE_DEB="/tmp/puppet${PUPPET_MAJOR}-release-${CODENAME}.deb"
RELEASE_URL="https://apt-puppetcore.puppet.com/public/puppet${PUPPET_MAJOR}-release-${CODENAME}.deb"
curl --fail --location --silent --show-error "$RELEASE_URL" -o "$RELEASE_DEB"
dpkg -i "$RELEASE_DEB" >/dev/null
rm -f "$RELEASE_DEB"

if [[ -n "$PUPPET_REPO_KEY" ]]; then
  cat > /etc/apt/auth.conf.d/apt-puppetcore-puppet.conf <<AUTH
machine apt-puppetcore.puppet.com
login ${PUPPET_REPO_USER}
password ${PUPPET_REPO_KEY}
AUTH
  chmod 600 /etc/apt/auth.conf.d/apt-puppetcore-puppet.conf
fi

apt-get update -qq || die "Puppet repository update failed. Provide --repo-key if repository authentication is required."
apt-get install -y -qq puppetserver >/dev/null || die "puppetserver installation failed."
install -d -m 0755 "/etc/puppetlabs/code/environments/${PUPPET_ENV}/manifests"
if [[ ! -f "/etc/puppetlabs/code/environments/${PUPPET_ENV}/manifests/site.pp" ]]; then
  cat > "/etc/puppetlabs/code/environments/${PUPPET_ENV}/manifests/site.pp" <<'PP'
node default {
  notify { 'HomeLAB SimpleLAB Puppet Server is working': }
}
PP
fi
install -d -m 0755 /etc/puppetlabs/puppet
cat > /etc/puppetlabs/puppet/puppet.conf <<CONF
[server]
certname = ${PUPPET_HOSTNAME}
environment = ${PUPPET_ENV}
CONF

if [[ $AUTOSIGN -eq 1 ]]; then printf '*\n' > /etc/puppetlabs/puppet/autosign.conf; chmod 0644 /etc/puppetlabs/puppet/autosign.conf; else rm -f /etc/puppetlabs/puppet/autosign.conf; fi
systemctl enable --now puppetserver
systemctl is-active --quiet puppetserver || die "puppetserver service is not active."

cat <<OUT
==================================================
Puppet Server installed successfully
==================================================
Hostname: ${PUPPET_HOSTNAME}
Port:     8140
Service:  puppetserver
Status:   running
Environment: ${PUPPET_ENV}
Puppet major: ${PUPPET_MAJOR}

Check certificates:
/opt/puppetlabs/bin/puppetserver ca list

Sign certificate:
/opt/puppetlabs/bin/puppetserver ca sign --certname <hostname>

Sign all certificates:
/opt/puppetlabs/bin/puppetserver ca sign --all
OUT
