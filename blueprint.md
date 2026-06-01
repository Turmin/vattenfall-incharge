# InCharge monitor blueprint

## Setup

- `config/config.php` leest de databaseverbinding uit `C:\Web\knmi.database.credentials.php`.
- `setup.php` maakt de `incharge_*` tabellen aan en importeert `favo.json`.
- `admin/` maakt de eerste admin-login aan in `C:\Web\incharge.admin.credentials.php`.

## Runtime

- `bin/poll.php` haalt alle actieve favorieten direct op.
- `cron.php` draait database-managed jobs uit `incharge_cron_jobs`.
- Server cron kan elke minuut `php C:\Web\incharge\cron.php` aanroepen.

## API

- `api/status.php`
- `api/favorites.php`
- `api/history.php?favorite_id=3&period=24h`
- `api/availability.php?favorite_id=3&period=24h`

## Code

- `src/InchargeClient.php` bevat de publieke InCharge mobile API-flow.
- `src/InChargePoller.php` schrijft snapshots naar de database.
- `src/CronScheduleService.php` hergebruikt de KNMI cron-aanpak voor InCharge.
- `src/SnapshotRepository.php` berekent statusduur en availability-segmenten.
