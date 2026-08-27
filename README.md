# HomeLAB-SimpleLAB

HomeLAB-SimpleLAB automatyzuje onboarding maszyn Linux do HomeLAB. AdminPanel przydziela unikalny hostname, przechowuje stan VM i zwraca konfigurację Puppet. `OnBoardingvm.sh` ustawia hostname na nowej VM, instaluje Puppet Agent i uruchamia pierwszy przebieg agenta.

## Architektura

```text
                    +-----------------------+
                    |       AdminPanel      |
                    | PHP + SQLite + REST   |
                    +-----------+-----------+
                                |
                  +-------------+-------------+
                  |                           |
                  v                           v
           Hostname Generator          Puppet Settings
                  |                           |
                  +-------------+-------------+
                                |
                                v
                       +----------------+
                       |  New Linux VM  |
                       +-------+--------+
                               |
                      OnBoardingvm.sh
                               |
                +--------------+--------------+
                |                             |
                v                             v
         Set hostname                  Install Puppet
                                              |
                                              v
                                      Puppet Master
```

## Struktura

```text
HomeLAB-SimpleLAB/
├── AdminPanel/
│   ├── api/index.php
│   ├── assets/css/style.css
│   ├── assets/js/app.js
│   ├── bin/init.php
│   ├── config/
│   ├── data/schema.sql
│   ├── includes/
│   ├── pages/
│   ├── .htaccess
│   ├── index.php
│   ├── install.sh
│   └── router.php
├── tests/test_api.sh
├── OnBoardingvm.sh
├── PuppetServerInstall.sh
├── install.sh
└── VERSION
```

## Instalacja AdminPanel

```bash
git clone https://github.com/chmajster/HomeLAB-SimpleLAB.git
cd HomeLAB-SimpleLAB
sudo ./install.sh --admin-panel
```

Lub:

```bash
sudo ./AdminPanel/install.sh --base-url http://10.0.0.10
```

Instalator generuje hasło administratora i API token, inicjalizuje SQLite, konfiguruje Apache i sprawdza `/api/v1/health`.

Dane runtime:

```text
/opt/HomeLAB-SimpleLAB/AdminPanel
/var/lib/homelab-simplelab/simplelab.db
/var/log/homelab-simplelab/app.log
/etc/homelab-simplelab/config.php
```

## Puppet Server

Dla Ubuntu 22.04/Debian 12 skrypty domyślnie wybierają Puppet 8. Dla Ubuntu 24.04/Debian 13 i nowszych wybierają Puppet 9. Aktualne repozytoria Puppet Core mogą wymagać konta `forge-key` i API key.

```bash
sudo ./PuppetServerInstall.sh \
  --hostname puppet.lab.local \
  --environment production \
  --repo-key 'PUPPET_CORE_API_KEY'
```

Autosign jest domyślnie wyłączony. W izolowanym labie można jawnie użyć `--autosign`.

```bash
sudo /opt/puppetlabs/bin/puppetserver ca list
sudo /opt/puppetlabs/bin/puppetserver ca sign --certname SCL00001
```

## Onboarding VM

```bash
sudo ./OnBoardingvm.sh 10.0.0.10 --token 'slab_xxx'
```

Pełny przykład z poświadczeniem Puppet Core:

```bash
sudo ./OnBoardingvm.sh \
  --server http://10.0.0.10 \
  --token 'slab_xxx' \
  --repo-key 'PUPPET_CORE_API_KEY'
```

Skrypt wysyła `machine_id`, aktualny hostname, IP, MAC, system i architekturę. `/etc/machine-id` jest identyfikatorem idempotencji: ta sama VM zawsze otrzymuje wcześniej przypisany hostname.

## Hostname Patterns

Domyślne wzorce:

```text
SCLXXXXX -> SCL00001, SCL00002, ...
SRLXXXX  -> SRL0001, SRL0002, ...
```

Panel pozwala dodawać i edytować własne wzorce, np. `DEVXXXX`, `LNX-XXXXX` lub `DB####`. Tylko jeden wzorzec jest aktywny. Numer jest przydzielany w transakcji SQLite `BEGIN IMMEDIATE`, więc równoległe onboardingi nie dostają tego samego hostname.

## API

Health bez tokenu:

```bash
curl http://10.0.0.10/api/v1/health
```

Onboarding:

```bash
curl -X POST http://10.0.0.10/api/v1/onboarding \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "machine_id":"123456789abcdef",
    "current_hostname":"ubuntu",
    "ip":"10.0.10.34",
    "mac":"BC:24:11:11:22:33",
    "os":"ubuntu",
    "os_version":"24.04",
    "architecture":"x86_64"
  }'
```

Pierwsza rejestracja zwraca HTTP `201`. Ponowny onboarding tej samej VM zwraca HTTP `200`, `existing: true` i ten sam hostname.

Endpointy:

```text
GET    /api/v1/health
POST   /api/v1/onboarding
GET    /api/v1/settings
GET    /api/v1/vms
GET    /api/v1/vms/{id}
DELETE /api/v1/vms/{id}
GET    /api/v1/patterns
```

Poza `/health` endpointy wymagają `Authorization: Bearer TOKEN`.

## AdminPanel

Menu:

```text
Dashboard
VMs
Hostname Patterns
Puppet
API
Settings
```

Panel posiada logowanie sesyjne, CSRF dla operacji zmieniających stan, PDO prepared statements, escaping HTML, widok szczegółów VM i rotację API tokenu. Token jest przechowywany jako hash; nowa wartość jest pokazywana tylko podczas instalacji lub bezpośrednio po rotacji.

## Testy

```bash
bash -n OnBoardingvm.sh
bash -n PuppetServerInstall.sh
bash -n install.sh
bash -n AdminPanel/install.sh
bash -n tests/test_api.sh
find AdminPanel -name '*.php' -print0 | xargs -0 -n1 php -l
```

Test API na uruchomionym panelu:

```bash
SIMPLELAB_BASE_URL=http://10.0.0.10 \
SIMPLELAB_API_TOKEN='slab_xxx' \
./tests/test_api.sh
```

Lokalny tryb developerski:

```bash
export SIMPLELAB_DB_PATH="$PWD/AdminPanel/data/test.db"
export SIMPLELAB_LOG_PATH="$PWD/AdminPanel/data/test.log"
php AdminPanel/bin/init.php --admin-password admin123 --api-token test_token_1234567890
php -S 127.0.0.1:8080 AdminPanel/router.php
```

## Troubleshooting

### HTTP 401

Sprawdź API token. Token po stronie serwera jest przechowywany wyłącznie jako hash.

### Puppet repository error

Aktualne repozytoria Puppet Core mogą wymagać poświadczeń. Użyj `--repo-key` albo `PUPPET_REPO_KEY`.

### Agent czeka na certyfikat

```bash
sudo /opt/puppetlabs/bin/puppetserver ca list
sudo /opt/puppetlabs/bin/puppetserver ca sign --certname HOSTNAME
```

Następnie na VM:

```bash
sudo /opt/puppetlabs/bin/puppet agent -t
```

### Logi

```bash
sudo tail -f /var/log/homelab-simplelab/app.log
sudo tail -f /var/log/apache2/homelab-simplelab-error.log
```
