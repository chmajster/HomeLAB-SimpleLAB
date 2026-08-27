#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/opt/HomeLAB-SimpleLAB/AdminPanel"
DB_DIR="/var/lib/homelab-simplelab"
LOG_DIR="/var/log/homelab-simplelab"
CONFIG_DIR="/etc/homelab-simplelab"
ADMIN_USER="admin"
ADMIN_PASSWORD="${SIMPLELAB_ADMIN_PASSWORD:-}"
API_TOKEN="${SIMPLELAB_API_TOKEN:-}"
BASE_URL="${SIMPLELAB_BASE_URL:-}"
PUPPET_HOSTNAME="${SIMPLELAB_PUPPET_HOSTNAME:-puppet.lab.local}"
PUPPET_IP="${SIMPLELAB_PUPPET_IP:-}"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage(){ cat <<USAGE
Usage: sudo $0 [options]
  --admin-user USER
  --admin-password PASS
  --api-token TOKEN
  --base-url URL
  --puppet-hostname NAME  Puppet Server hostname (default: puppet.lab.local)
  --puppet-ip IP          Puppet/AdminPanel server IP (default: auto-detect)
  -h, --help
USAGE
}
die(){ echo "ERROR: $*" >&2; exit 1; }
valid_ipv4(){
  local ip="$1" a b c d
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  (( 10#$a <= 255 && 10#$b <= 255 && 10#$c <= 255 && 10#$d <= 255 ))
}
[[ ${EUID} -eq 0 ]] || die "Run as root."
while [[ $# -gt 0 ]]; do
  case "$1" in
    --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --api-token) API_TOKEN="${2:-}"; shift 2 ;;
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --puppet-hostname) PUPPET_HOSTNAME="${2:-}"; shift 2 ;;
    --puppet-ip) PUPPET_IP="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
done
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "Invalid admin username."
[[ "$PUPPET_HOSTNAME" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || die "Invalid Puppet hostname."

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 php libapache2-mod-php php-sqlite3 php-curl sqlite3 curl ca-certificates openssl rsync iproute2 >/dev/null
[[ -n "$ADMIN_PASSWORD" ]] || ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n')"
[[ -n "$API_TOKEN" ]] || API_TOKEN="slab_$(openssl rand -hex 24)"

if [[ -z "$PUPPET_IP" ]]; then
  PUPPET_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}' || true)"
fi
if [[ -z "$PUPPET_IP" ]]; then
  PUPPET_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
fi
[[ -n "$PUPPET_IP" ]] || die "Cannot determine local Puppet/AdminPanel IP. Use --puppet-ip."
valid_ipv4 "$PUPPET_IP" || die "Invalid Puppet/AdminPanel IPv4 address: $PUPPET_IP"

# Apache/AdminPanel and Puppet Server are hosted on the same machine.
# Use a managed marker so rerunning the installer replaces, rather than duplicates, the entry.
sed -i '/# HomeLAB-SimpleLAB Puppet Server$/d' /etc/hosts
printf '%s\t%s\tpuppet\t# HomeLAB-SimpleLAB Puppet Server\n' "$PUPPET_IP" "$PUPPET_HOSTNAME" >> /etc/hosts

install -d -m 0755 /opt/HomeLAB-SimpleLAB "$APP_DIR"
rsync -a --delete --exclude 'data/*.db' --exclude 'data/*.sqlite*' "$SOURCE_DIR/" "$APP_DIR/"
install -d -m 0750 "$DB_DIR" "$LOG_DIR" "$CONFIG_DIR"
touch "$LOG_DIR/app.log"
cat > "$CONFIG_DIR/config.php" <<PHP
<?php
return [
    'app_name' => 'HomeLAB SimpleLAB',
    'db_path' => '${DB_DIR}/simplelab.db',
    'log_path' => '${LOG_DIR}/app.log',
    'session_name' => 'simplelab_session',
];
PHP
chmod 0640 "$CONFIG_DIR/config.php"
php "$APP_DIR/bin/init.php" --admin-user "$ADMIN_USER" --admin-password "$ADMIN_PASSWORD" --api-token "$API_TOKEN"
SIMPLELAB_DB_PATH="$DB_DIR/simplelab.db" php -r '
$db=new PDO("sqlite:".getenv("SIMPLELAB_DB_PATH"));
$values=["puppet_server"=>$argv[1],"puppet_server_ip"=>$argv[2]];
if ($argv[3] !== "") { $values["base_url"]=$argv[3]; }
$stmt=$db->prepare("INSERT INTO settings(key,value,updated_at) VALUES(:k,:v,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP");
foreach($values as $k=>$v){$stmt->execute([":k"=>$k,":v"=>$v]);}
' "$PUPPET_HOSTNAME" "$PUPPET_IP" "$BASE_URL"

chown -R root:www-data "$APP_DIR" "$CONFIG_DIR"
find "$APP_DIR" -type d -exec chmod 0755 {} +
find "$APP_DIR" -type f -exec chmod 0644 {} +
chmod 0755 "$APP_DIR/install.sh"
chown -R www-data:www-data "$DB_DIR" "$LOG_DIR"
chmod 0750 "$DB_DIR" "$LOG_DIR"
chmod 0640 "$DB_DIR/simplelab.db" "$LOG_DIR/app.log"

cat > /etc/apache2/sites-available/homelab-simplelab.conf <<APACHE
<VirtualHost *:80>
    ServerName _default_
    DocumentRoot ${APP_DIR}
    DirectoryIndex index.php
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory ${APP_DIR}/data>
        Require all denied
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/homelab-simplelab-error.log
    CustomLog \${APACHE_LOG_DIR}/homelab-simplelab-access.log combined
</VirtualHost>
APACHE

a2enmod rewrite >/dev/null
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite homelab-simplelab >/dev/null
apache2ctl configtest
systemctl enable --now apache2
systemctl reload apache2
for _ in {1..10}; do if curl --fail --silent --max-time 2 http://127.0.0.1/api/v1/health >/dev/null; then break; fi; sleep 1; done
curl --fail --silent --max-time 5 http://127.0.0.1/api/v1/health >/dev/null || die "Health endpoint is not responding."
cat <<OUT
==================================================
HomeLAB SimpleLAB AdminPanel installed
==================================================
URL:             ${BASE_URL:-http://${PUPPET_IP}/}
Admin user:      ${ADMIN_USER}
Admin password:  ${ADMIN_PASSWORD}
API token:       ${API_TOKEN}
Puppet hostname: ${PUPPET_HOSTNAME}
Puppet IP:       ${PUPPET_IP}
Hosts entry:     ${PUPPET_IP} ${PUPPET_HOSTNAME} puppet
Database:        ${DB_DIR}/simplelab.db
Logs:            ${LOG_DIR}/app.log

Save the password and API token now. The API token is stored only as a hash.
OUT
