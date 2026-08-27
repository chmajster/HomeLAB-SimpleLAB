# HomeLAB-SimpleLAB

HomeLAB-SimpleLAB automatyzuje onboarding maszyn Linux do HomeLAB. AdminPanel przydziela unikalny hostname, przechowuje stan VM i zwraca konfigurację Puppet. `OnBoardingvm.sh` ustawia hostname na nowej VM, instaluje Puppet Agent i uruchamia pierwszy przebieg agenta.

AdminPanel/Apache2 i Puppet Server są projektowane do działania na tej samej maszynie. Instalatory automatycznie zapisują IP tej maszyny jako `puppet_server_ip` oraz utrzymują zarządzany wpis `/etc/hosts` dla hostname Puppet Mastera.

## Architektura

```text
                    +-----------------------+
                    |       AdminPanel      |
                    | PHP + SQLite + REST   |
                    | Apache2               |
                    +-----------+-----------+
                                |
                     SAME SERVER / SAME IP
                                |
                    +-----------+-----------+
                    |      Puppet Server    |
                    |       TCP/8140        |
                    +-----------+-----------+
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
         Set hostname                 Add /etc/hosts
                                      Puppet mapping
                                               |
                                               v
                                      Install Puppet
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

Lub z jawnym adresem wspólnego serwera Apache/Puppet:

```bash
sudo ./AdminPanel/install.sh \
  --base-url http://10.0.0.10 \
  --puppet-hostname puppet.lab.local \
  --puppet-ip 10.0.0.10
```

Jeżeli `--puppet-ip` nie zostanie podane, instalator automatycznie wykryje główny adres IPv4 serwera.

Instalator:

- generuje hasło administratora i API token,
- inicjalizuje SQLite,
- konfiguruje Apache,
- zapisuje `puppet_server = puppet.lab.local`,
- zapisuje `puppet_server_ip = <IP serwera>`,
- dodaje zarządzany wpis do `/etc/hosts`,
- sprawdza `/api/v1/health`.

Przykładowy wpis na serwerze:

```text
10.0.0.10    puppet.lab.local    puppet    # HomeLAB-SimpleLAB Puppet Server
```

Ponowne uruchomienie instalatora zastępuje wpis oznaczony komentarzem `# HomeLAB-SimpleLAB Puppet Server` zamiast tworzyć duplikaty.

Dane runtime:

```text
/opt/HomeLAB-SimpleLAB/AdminPanel
/var/lib/homelab-simplelab/simplelab.db
/var/log/homelab-simplelab/app.log
/etc/homelab-simplelab/config.php
```

## Puppet Server

Dla Ubuntu 22.04/Debian 12 skrypty domyślnie wybierają Puppet 8. Dla Ubuntu 24.04/Debian 13 i nowszych wybierają Puppet 9. Aktualne repozytoria Puppet Core mogą wymagać konta `forge-key` i API key.

Apache2/AdminPanel i Puppet Server mają działać na tym samym adresie IP. Instalator Puppet również tworzy ten sam zarządzany wpis `/etc/hosts`.

```bash
sudo ./PuppetServerInstall.sh \
  --hostname puppet.lab.local \
  --server-ip 10.0.0.10 \
  --environment production \
  --repo-key 'PUPPET_CORE_API_KEY'
```

`--server-ip` jest opcjonalne. Bez niego adres IPv4 zostanie wykryty automatycznie.

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

API onboardingu zwraca zarówno hostname Puppet Mastera, jak i jego IP. Ponieważ Apache/AdminPanel i Puppet Master działają na jednym serwerze, VM automatycznie dodaje:

```text
10.0.0.10    puppet.lab.local    puppet    # HomeLAB-SimpleLAB Puppet Server
```

Dzięki temu `puppet.lab.local` działa nawet bez rekordu DNS. Jeżeli starsza instalacja AdminPanel nie ma jeszcze ustawionego `puppet_server_ip`, `OnBoardingvm.sh` użyje IP podanego jako adres AdminPanel, jeśli jest to adres IPv4.

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

Przykład odpowiedzi:

```json
{
  "success": true,
  "existing": false,
  "hostname": "SCL00001",
  "puppet": {
    "server": "puppet.lab.local",
    "server_ip": "10.0.0.10",
    "port": 8140,
    "environment": "production"
  }
}
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

### Puppet hostname nie rozwiązuje się na VM

Sprawdź:

```bash
grep 'HomeLAB-SimpleLAB Puppet Server' /etc/hosts
```

Oczekiwany wpis:

```text
10.0.0.10    puppet.lab.local    puppet    # HomeLAB-SimpleLAB Puppet Server
```

Sprawdź również konfigurację w AdminPanel -> Puppet: `Puppet Master IP` powinien wskazywać ten sam adres co Apache/AdminPanel.

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
