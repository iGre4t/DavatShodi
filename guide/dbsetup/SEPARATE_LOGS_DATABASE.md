# Separate logs database

Create two MySQL databases in cPanel and assign the application database user to both.

Set these values in `api/config.php` (or `api/data/db-config.json`):

```php
'host' => 'localhost',
'dbname' => 'CPANELUSER_core',
'user' => 'CPANELUSER_app',
'password' => 'CORE_PASSWORD',
'logs_host' => 'localhost',
'logs_port' => 3306,
'logs_dbname' => 'CPANELUSER_logs',
'logs_user' => 'CPANELUSER_app',
'logs_password' => 'CORE_PASSWORD',
```

Import the core dump into `CPANELUSER_core` and the logs dump into
`CPANELUSER_logs`. The logs database contains panel audit events and every
`TC_*_activity_logs` / `EGM_*_activity_logs` table. Login-attempt tables stay
in the core database because they are live authentication/security state.

For a single compressed logs dump larger than phpMyAdmin's upload limit, use
cPanel Terminal/SSH:

```sh
gzip -dc DavatShodi_MCI_logs_full.sql.gz | mysql -u CPANELUSER_app -p CPANELUSER_logs
```
