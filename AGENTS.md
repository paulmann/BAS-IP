# AGENTS.md — BAS-IP Gate Manager 2.0.2 «Kronos»

Agent-facing guide: how to install BAS-IP Gate Manager, run it, and report problems responsibly.
Human documentation: [README.md](README.md), [wiki/](wiki/), [CHANGELOG.md](CHANGELOG.md).

> Verified against repository state on 2026-10-22. Version covered: 2.0.2
> (`App::VERSION` in `src/BasIp/Core/App.php`, `package.json`, `CHANGELOG.md`, release `v2.0.2`).

## 1. What it is

Self-hosted PHP web application for a residential complex: residents and roles, billing with
recalculation, guest QR passes, an audit log of every barrier opening, mailings and a REST API.
It is a normal PHP application plus a CLI (`bin/bas-ip`), not a daemon or managed service; all
front-end assets are vendored, so the UI needs no external CDN.

| | |
|---|---|
| Runtime / platform | PHP 8.2+ (8.5 recommended) on Linux with nginx + PHP-FPM or Apache. MySQL 8.0 / MariaDB 10.6+ / PostgreSQL 13+ / SQLite 3. |
| Entry points | Web: `public/index.php` (document root must be `public/`). CLI: `php bin/bas-ip`. |
| Writes to | `.bas-ip.config.local.php` (0600), `public/install.lock`, `storage/` (logs, sessions, cache, queue, backups, exports, uploads, encrypted secrets), database schema and data. |
| Network access | BAS-IP panel at `device.base_url` (default `http://192.168.1.20/api/v0/`), configured SMTP / Telegram / SMS / webhook endpoints, GitHub API for update checks when `update.enabled` is true (the default). |

## 2. Preconditions

| Requirement | Why | Check |
|---|---|---|
| PHP >= 8.2 | `bin/bas-ip` exits below 8.2 | `php -v` |
| Extensions `json`, `mbstring`, `pdo`, `curl` (required to start); `openssl`, `zip`, `pdo_mysql` (secrets, backups/updates, MySQL) | CLI and features | `php -m` |
| Database reachable with the credentials you will configure | schema and all data | `mysql -h 127.0.0.1 -u bas_ip -p -e 'select 1'` |
| `storage/` writable by the web-server user | sessions, logs, queue, cache | `php bin/bas-ip doctor` after install |
| Document root is `public/`; root `.htaccess` denies everything | keeps `config/`, `storage/`, `src/` outside the web root | web-server config |
| `app.key` (base64, 32 bytes) | CSRF, signed guest links, secret encryption | `php bin/bas-ip key:generate --show` |
| cron in production | `schedule:run` marks overdue, expires passes, drains queue, runs backups | crontab |

No Composer or npm install is required at runtime: PHP code and assets are committed; npm only
rebuilds assets (`package.json` scripts). If a check fails, stop and report.

## 3. Install

### 3.1 From a release (preferred)

Releases exist: latest is `v2.0.2` (verified with `gh release list -R paulmann/BAS-IP`) with assets
`bas-ip-2.0.2.zip`, `bas-ip-2.0.2.zip.sha256`; page: https://github.com/paulmann/BAS-IP/releases/tag/v2.0.2

```bash
# download bas-ip-2.0.2.zip and bas-ip-2.0.2.zip.sha256 from that release page, then:
sha256sum -c bas-ip-2.0.2.zip.sha256   # command quoted in the v2.0.2 release notes
unzip bas-ip-2.0.2.zip                 # internal archive layout: Not documented in this repository
```

Upgrade caveat: 2.0.0 cannot self-update to 2.0.2 on PHP 8.5; unpack the archive over the existing
directory, then run `php bin/bas-ip migrate`.

### 3.2 From source

```bash
git clone https://github.com/paulmann/BAS-IP.git
cd BAS-IP
php bin/bas-ip serve        # built-in server, http://localhost:8080
# production: point nginx/Apache at public/, make storage/ writable:
chown -R www-data:www-data storage && chmod -R ug+rwX storage && chmod 750 storage
```

Create the database first (MySQL; user grant statements are in `wiki/01-Installation.md`):

```sql
CREATE DATABASE bas_ip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Install through the web installer (recommended) or the CLI:

- Web installer: open the site; without a local config it redirects to `/install`. Five steps
  (requirements -> database test -> application -> panel handshake -> administrator); it writes
  `.bas-ip.config.local.php` (0600), generates `app.key`, migrates, creates the administrator and
  `public/install.lock`, then logs you in.
- CLI: `php bin/bas-ip install --db=mysql --admin=admin` (interactive), or `--no-interaction` with
  `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password`, `--app-name`, `--url`,
  `--timezone`, `--email`, `--name`, `--device-*`; `--demo` seeds data, `--force` replaces config.

### 3.3 Verify

```bash
php bin/bas-ip version
# BAS-IP Gate Manager 2.0.2 (Kronos)
php bin/bas-ip doctor           # exit 0 when there are no errors; --json for machines
php bin/bas-ip health --json    # exit 0 = ok or degraded, 1 = down
curl -fsS https://gate.example.com/health.json   # 200, status ok (verified in v2.0.2 notes)
```

Installer routes return 403 after installation. To re-run the installer, delete `public/install.lock`.

## 4. Configure

Precedence, lowest to highest (verified in `src/BasIp/Core/Config.php`): `config/config.php` defaults
-> `.bas-ip.config.local.php` in the project root -> `.env` / `BASIP_*` environment variables
(highest); installer runtime overrides last.

Minimal `.bas-ip.config.local.php` (template: `.bas-ip.config.local.php.example`; only differences
from the defaults are needed):

```php
<?php
declare(strict_types=1);
return [
    'app' => ['env' => 'production', 'debug' => false,
              'url' => 'https://gate.example.com', 'timezone' => 'Europe/Moscow'],
    'db'  => ['default' => 'mysql', 'connections' => ['mysql' => [
        'host' => '127.0.0.1', 'port' => 3306, 'database' => 'bas_ip',
        'username' => 'bas_ip', 'password' => '${ENV:DB_PASSWORD}',
    ]]],
];
```

- `app.key` is mandatory: `php bin/bas-ip key:generate --show` prints it, `--write` stores it in an
  existing local file. Permissions: config `0600`, files `0640`, directories `0750`. Secrets:
  `php bin/bas-ip secret:set DEVICE_PASSWORD`, used as `${secret:DEVICE_PASSWORD}` (AES-256-GCM,
  encrypted store under `storage/`); environment values use `${ENV:NAME}`.
- `.env` (template `.env.example`) overrides any key by prefixing it with `BASIP_`, nested keys with
  a double underscore; aliases such as `APP_ENV`, `DB_HOST`, `DB_PASSWORD` also work. Verify the
  merge with `php bin/bas-ip config:show` (secrets masked) or `config:show db.default`.
- Web server: document root `public/`; nginx needs `try_files $uri $uri/ /index.php?$query_string`
  and `client_max_body_size 16m` for imports; nginx/Apache examples: `wiki/01-Installation.md`.
  Production cron (README): `* * * * * cd /var/www/bas-ip && php bin/bas-ip schedule:run --quiet`.

## 5. Use

`php bin/bas-ip` lists 71 commands; any command accepts `--help`
(for example `php bin/bas-ip install --help`).

| Purpose | Command | Expected result |
|---|---|---|
| Health for monitoring | `php bin/bas-ip health --json` | JSON checks; exit 0 unless `down` |
| Full self-diagnosis | `php bin/bas-ip doctor --json` | issue list; exit 1 when errors exist |
| Import residents (preview) | `php bin/bas-ip residents:import residents.csv --dry-run` | per-row create/update preview, nothing written |
| Import residents (apply) | `php bin/bas-ip residents:import residents.csv --mode=upsert` | total, created, updated, skipped, failed |
| Billing dry run | `php bin/bas-ip billing:charge --total=15000 --period=10.2026 --dry-run` | calculation only, no invoices |
| Billing for real | `php bin/bas-ip billing:charge --total=15000 --period=10.2026 --yes` | invoices created; `--notify` queues mailings |
| Barrier state (read-only) | `php bin/bas-ip gate:status --json` | availability, SIP, device time, drift |
| Open the barrier | `php bin/bas-ip gate:open --apartment=12` | opens hardware and writes the audit event; exit 2 on refusal |
| Backup | `php bin/bas-ip backup:create --type=db` | backup file name, size, path |
| Update check | `php bin/bas-ip update:check` | current and available version, channel, repo |

**Output / exit codes:** `0` success, `1` error (`wiki/15-CLI.md`). Specific codes: `doctor` returns 1
when it counted errors; `health` returns 0 for `ok`/`degraded` and 1 for `down`; `gate:open` returns
2 on refusal; `residents:import` returns 1 when rows failed; `queue:run` returns 1 on failed
deliveries. JSON output: `doctor`, `health`, `config:show`, `billing:preview`, `gate:status`,
`update:check`.

## 6. Safety and rollback

- Changes: writes the local config and `install.lock`, creates/migrates database tables, writes
  under `storage/` (encrypted secret store, update snapshots); it does not edit web-server config or
  install cron/systemd units - an operator adds those.
- Never does on its own: no system packages, firewall changes or files outside the project
  directory, no cloud calls unless a channel, device or update check is configured and invoked.
- Undo / uninstall (from `wiki/01-Installation.md`): optionally `php bin/bas-ip maintenance --on`,
  then remove the project directory, drop the database (`DROP DATABASE bas_ip;`) and remove the cron
  entry or queue worker you added. `storage/` holds backups and logs - keep it if needed.
- Destructive, never without explicit human approval: `gate:open` / `gate:close` (moves real
  hardware), `backup:restore` (overwrites current data), `migrate:fresh` (drops the schema),
  `update:run` / `update:rollback` (rewrites application files), `doctor --fix` (creates and chmods
  `storage/`), `billing:charge` without `--dry-run` (creates invoices, queues mailings),
  `residents:import` without `--dry-run` (writes rows).
- Safe first-run order, do not skip: 1) help (`php bin/bas-ip`, then `--help`) -> 2) read-only
  diagnosis (`doctor`, `health`, `version`) -> 3) dry runs (`residents:import --dry-run`,
  `billing:charge --dry-run`, device driver `dryrun`) -> 4) one object (one resident row, one
  confirmed charge, one `gate:test`) -> 5) everything (full import, monthly charge, cron). On
  production back up first, keep `dry-run`/`dryrun` for the first pass, never open to test.

## 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| `BAS-IP Gate Manager требует PHP 8.2 или новее`, exit 1 | wrong `php` binary | run a 8.2+ binary; the message prints `PHP_BINARY` |
| `Не загружено обязательное расширение PHP: ext-json` | missing extension | install it for the CLI SAPI (`php -m`) |
| Browser always redirects to `/install` | no local config and no `public/install.lock` | run the installer |
| `/install` returns 403 `Система уже установлена` | `public/install.lock` exists | delete `public/install.lock` |
| `doctor` reports `storage/` problems | web-server user cannot write `storage/` | fix ownership and modes (3.2), then `doctor --fix` if appropriate |
| `update:run` prints `Обновления отключены (update.enabled = false)` | updates disabled in config | enable `update.enabled` or install the archive manually |
| `health` exits 1 or `/health.json` is not 200 | database, storage or key broken | read `doctor` output and logs under `storage/logs` |

## 8. Limits

- PHP 8.2+ only; older PHP is rejected by the CLI. PHP 8.5 is the target version.
- One installation per application directory and database; parallel installations need `db.prefix`.
- SQLite suits a small house or demo; MySQL/MariaDB is the recommended production database.
- Background work is CLI-driven: `schedule:run` every minute (cron) or a `queue:work` worker.
- Update channel is `stable` against `update.repo` (default `paulmann/BAS-IP`); offline hosts must
  install release archives by hand.
- Container and Windows production deployment are Not documented in this repository; the CLI header
  states the console works on Windows. Test counts differ: README says 200 tests / 850+ checks,
  `CHANGELOG.md` for 2.0.1/2.0.2 records 217 tests / 930 assertions; `php tests/run.php` measures it.

## 9. Sources of truth

- `README.md` - quick start, requirements, config layers, tests, layout, update, license;
  `CHANGELOG.md` and the `v2.0.2` release notes - version, requirements, upgrade caveat.
- `wiki/01-Installation.md` - requirements, installer, manual install, nginx/Apache, cron, uninstall;
  `wiki/03-Configuration.md` - configuration keys and layers; `wiki/15-CLI.md` - all 71 commands,
  options, exit-code convention.
- `src/BasIp/Console/commands/*.php` - every option and exit code used above; `bin/bas-ip` - PHP 8.2
  gate, required extensions, CLI entry point; `src/BasIp/Core/App.php` - `VERSION = '2.0.2'`,
  `CODENAME = 'Kronos'`; `src/BasIp/Core/Config.php` - config precedence.
- `public/index.php`, `public/router.php`, `public/.htaccess`, `.htaccess`, `tests/run.php`,
  `tools/smoke.php` - document root, install redirect, installer lock, test entry points.

Runtime checks were made with PHP 8.5.0 CLI on this machine: `php bin/bas-ip` (71 commands) and
`php bin/bas-ip version` -> `BAS-IP Gate Manager 2.0.2 (Kronos)`, plus `--help` for the commands
above. The web installer and database paths were verified by reading source, not executed here.

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique PHP / MySQL / web server version combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
