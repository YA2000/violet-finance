# Deployment and operations

## The server

Ubuntu + CloudPanel, PHP 8.3 (`php8.3`), MySQL/MariaDB, nginx. Two sites are possible: **live** and **test**.
Source checkout: `/opt/violet-src` (branch `main` of `github.com/YA2000/violet-finance`).
Settings of the tool: `/etc/violet-deploy.conf` (`LIVE_SITE`, `LIVE_USER`, `TEST_SITE`, `TEST_USER`, `BACKUPS`, `KEEP`, `OFFSITE`).
First installation: [DEPLOY_AR.md](../DEPLOY_AR.md); tool installation: `deploy/server-setup.sh`.

## The `violet` command (run as root: `sudo violet ...`)

| Command | What it does |
|---|---|
| `violet status` | Version on GitHub, on test, on live; last backups. |
| `violet update test` | Backup → maintenance mode → new code → `composer install --no-dev` → `migrate` → caches → **check the login page answers** → done. |
| `violet promote` | Install on live exactly the commit running on test. |
| `violet update live` | Same as update test, straight on live. |
| `violet rollback live` | Put back code **and database** from the last backup (asks YES; data entered after it is lost). |
| `violet sync-test` | Copy live database + uploaded files into test (to try an update on real data). |
| `violet log live` | The last error lines of the site log — what is behind an error 500. `violet log live 30` for more. |
| `violet backup live` | A backup now. |

Automatic safety: if `migrate` fails **or the login page answers 500 after the update**, the tool restores the backup by
itself, prints the last errors and stops.

## Recommended way to release

1. Push to `main` → GitHub runs the tests (green tick on the commit). Red = do not deploy.
2. `sudo violet sync-test && sudo violet update test` → open the test site, check the pages that changed.
3. `sudo violet promote`.

## Backups

- Each update keeps `db.sql`, `.env`, `code.tgz`, `storage-app.tgz` in `/root/violet-backups/<site>-<date>` (last `KEEP`, default 10).
- **Daily backup** (recommended): `crontab -e` as root →
  `30 2 * * * /usr/local/bin/violet backup live >> /var/log/violet-backup.log 2>&1`
- **Off the server**: set `OFFSITE=backup@other-host:/srv/violet` (SSH key, no password) or a mounted folder in
  `/etc/violet-deploy.conf`. Every backup is then copied there with rsync. A backup on the same disk does not survive a disk loss.
- Restore one by hand: `mysql DB < db.sql`, untar `code.tgz` into the site, `tar xzf storage-app.tgz -C site`.

## Error 500 — what to do

1. `sudo violet log live` → the first ERROR line says the cause (file, line, SQL).
2. If the site must work now: `sudo violet rollback live`.
3. Typical causes:

| Message | Fix |
|---|---|
| `Unknown column` / `Base table not found` | A migration did not run: `cd SITE && sudo -u USER php8.3 artisan migrate --force`. |
| `View [...] not found`, `Route [...] not defined` | Stale caches: `php artisan optimize:clear && php artisan config:cache route:cache view:cache`. |
| `Permission denied` on storage / bootstrap/cache | `chown -R USER:USER SITE && chmod -R ug+rwX SITE/storage SITE/bootstrap/cache`. |
| `The MAC is invalid` / decrypt errors | `APP_KEY` changed: put back the `.env` from the backup. |
| `Class ... not found` | `composer install --no-dev -o` in the site folder. |

## Error monitoring

Laravel writes errors to `storage/logs/laravel-YYYY-MM-DD.log` (daily files). `violet log live` reads the latest.
For alerts by e-mail or a service (Sentry, Flare, Better Stack), set `MAIL_*` first, then add the service's Laravel
package and DSN in `.env` — nothing else in the code needs to change.

## Useful artisan commands

| Command | Use |
|---|---|
| `php artisan violet:2fa-reset user@x` | Turn off two-factor of a user who lost the phone. |
| `php artisan violet:audit-verify` | Check the audit chain. |
| `php artisan violet:expenses-rebuild` | Rebuild `spend_lines` from every final form. |
| `php artisan migrate --force` | Apply database changes (the update does it). |
| `php artisan down` / `up` | Maintenance mode by hand. |
