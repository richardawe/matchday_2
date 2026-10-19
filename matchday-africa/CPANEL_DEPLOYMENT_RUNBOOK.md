# Matchday Africa — cPanel Deployment Runbook

This runbook deploys the Laravel 12 application to `https://matchday.africa`, including Matchday War at `https://matchday.africa/war`.

## Automated CI/CD

`.github/workflows/matchday-africa.yml` tests PHP 8.2 and 8.4 with SQLite and GD,
builds Vite, and exercises deployment failure handling. Changes to reel source,
fonts, or army art also render a two-second Remotion clip. The stable **CI gate**
check requires the application tests and any selected reel check to pass.

On `main`, the deployment job restores the exact tested frontend artifact; it does
not rebuild it. Before touching the live site it verifies the release is still
`main`, connects by SSH with pinned host keys, and checks the existing Laravel
root, PHP extensions, Composer, writable directories, and production `.env`.
It then pre-renders a maintenance page (so mixed files need not boot Laravel),
uploads by FTPS, runs `deploy-production.sh` over
SSH, and verifies the public release SHA, health route, home, match list, match
detail, pulse JSON, and live match JSON. SSH and FTP must target the same Laravel
root; the uploaded release marker is checked from both paths.

### Configure access once

Use the cPanel account that owns the Laravel app. Linux root/sudo is unnecessary.
In GitHub → repository Settings → Environments → **production**, configure:

| Secret | Purpose |
| --- | --- |
| `CPANEL_FTP_SERVER` | FTP hostname, without a URL scheme |
| `CPANEL_FTP_USERNAME` | FTP account with access to the Laravel root |
| `CPANEL_FTP_PASSWORD` | FTP password |
| `CPANEL_FTP_SERVER_DIR` | FTP path to the Laravel root; may differ from the SSH absolute path |
| `CPANEL_FTP_PROTOCOL` | Optional; defaults to `ftps`; use `ftp` only if the host requires it |
| `CPANEL_FTP_PORT` | Optional; defaults to `21` |
| `CPANEL_SSH_HOST` | SSH hostname or IPv4 address |
| `CPANEL_SSH_USER` | cPanel SSH account name |
| `CPANEL_SSH_KEY` | Dedicated, noninteractive private deployment key; authorize its public key in cPanel |
| `CPANEL_SSH_PORT` | Optional; defaults to `22` |
| `CPANEL_SSH_KNOWN_HOSTS` | OpenSSH known_hosts entry verified against the host's trusted fingerprint |
| `CPANEL_APP_ROOT` | Absolute SSH path, e.g. `/home/CPANELUSER/matchday-africa`; no spaces |

Do not paste credentials into chat, commit them, or put them in a public web
folder. Obtain the SSH host fingerprint from the host/cPanel over a trusted
channel; an unverified `ssh-keyscan` result alone is insufficient. For a nonstandard
port the known_hosts entry uses `[hostname]:port`. The workflow enforces strict
host-key checking and deletes its temporary key files when the step finishes.
The same `production` environment supplies FTP secrets to `render-reel.yml`.

`php`, `composer`, `flock`, and `sha256sum` must be available in noninteractive SSH
sessions. PHP needs MySQL PDO, mbstring, curl, XML/DOM, and GD with FreeType/WebP.
If `REEL_RENDERER=server`, install Node 22 and npm in that session's PATH, or set
`REEL_NODE` in the server `.env` to its executable path. First installs must follow
the manual steps below to establish `.env`, vendor dependencies, and a database;
the automated pipeline deliberately refuses to create or rotate a production key.

Optionally set production environment **variable** `CPANEL_SMOKE_MATCH_ID` to a
stable existing fixture ID. Otherwise the HTTP check discovers a fixture from the
rendered pages. An empty database needs a chosen fixture before a full acceptance
check can pass. Never seed or reset production just to make deployment checks pass.

In repository branch rules, require **CI gate** for `main`, require pull requests,
and prevent force pushes. Limit the `production` environment to the `main` branch;
add reviewer approval if desired. These settings are configured through GitHub,
not by committing workflow YAML. A workflow commit does not establish that the
secrets, branch rules, or live server are configured.

### Server steps and retained state

`bash deploy-production.sh`, run from the Laravel root, checks production settings
without logging credentials, installs locked production dependencies, clears only
framework caches, runs migrations, rebuilds caches, reports migration/health/
scheduler state, restarts queue workers, and exits maintenance mode. It preserves
`.env` and the application key. It does not clear application cache locks.

The script creates story-card, reel, and generated-media directories. When the
server renderer is enabled, it runs `npm ci --omit=dev` only when the reel lockfile
changes or dependencies are missing. It runs the actual renderer check on first
installation and when renderer code, fonts, army art, or Node version changes.
Success hashes are written only after the render passes. With `off` or `github`,
server Node installation is unnecessary.

FTP excludes `.env`, `vendor`, storage/cache contents, public storage/uploads,
`public/generated`, reel dependencies/output, SQLite files, and the local Vite hot
marker. Committed fonts, army art, reel source, and the tested `public/build` are
uploaded. The legacy `render-social.yml` only saves a JSON artifact; it never syncs
or deletes production media.

### Failure and recovery

This remains an **in-place cPanel deployment**, with a short maintenance window;
it is not an atomic release-directory switch. Upload or server-step failures leave
the site in maintenance mode to avoid serving mixed files or incompatible schema.
The HTTP check can also fail after the app has been brought back up; inspect the
actual site rather than assuming maintenance status from a failed job alone.

Before the first automatic deployment, take the filesystem/database backups in
section 2. If a release fails, inspect the failed Actions step and Laravel logs,
correct access/dependencies or deploy a reviewed corrective commit, then rerun
`bash deploy-production.sh` from the confirmed app root. Restore a known-good
release and its compatible database backup if necessary. Do not automatically
roll back migrations or blindly run `php artisan up` after a partial upload.
Always repeat the public smoke check with the SHA of the recovered release.

Cron is an independent one-time hosting configuration: set the scheduler and queue
runner in section 14 and verify with `php artisan schedule:list`. A successful
upload or workflow does not prove cron is installed or external feeds are enabled.

## Live data, story cards, reels and Buffer (added Oct 2026)

These features are off by default. The setup, go-live order, extra scheduler jobs and the CI/CD steps
they need are in `AGENTS.md` at the repository root (sections 4 and 5). In short: run migrations, then
switch on `ESPN_ENABLED` (shadow first), run `cd reel && npm ci && node check.mjs` before setting
`REEL_RENDERER`, and add the `BUFFER_*` keys. Every `.env` change needs `php artisan optimize:clear`
(or `bash deploy-production.sh`), because production caches the config.

## 1. Confirm the hosting package

Before uploading anything, confirm that cPanel provides:

- PHP 8.2 or newer (PHP 8.3/8.4 is acceptable)
- MySQL or MariaDB
- Composer 2 through cPanel Terminal or SSH
- Cron Jobs
- SSL/TLS (AutoSSL or Let's Encrypt)
- PHP extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, and `xml`
- At least 300 MB free space; the current `public/war` media is approximately 98 MB before packaging/backups

If cPanel supports changing a domain's document root, use the preferred layout in step 6A. Otherwise use step 6B.

## 2. Back up the existing live site

In cPanel:

1. Open **Backup** or **Backup Wizard**.
2. Download a home-directory backup.
3. Export the existing database through **phpMyAdmin**.
4. Record the current PHP version, cron jobs, DNS records, mail settings, and document root.
5. Do not overwrite the live site until the staging or temporary URL passes the acceptance tests in step 18.

## 3. Prepare the production build locally

From the project directory:

```bash
cd /path/to/matchday-africa
composer install
npm ci
npm run build
php artisan test
```

Confirm these paths exist before packaging:

- `public/build/manifest.json`
- `public/build/assets/`
- `public/war/warriors/`
- `public/war/downloads/rights-safe/`
- `public/war/matchday-warriors-trailer.mp4`

Node.js is not required on cPanel if `npm run build` is completed locally and the compiled `public/build` directory is uploaded.

## 4. Create the upload archive

Package the application without secrets or development-only directories:

```bash
zip -r matchday-africa-production.zip . \
  -x '.git/*' '.env' 'node_modules/*' 'tests/*' \
     'storage/logs/*' 'database/database.sqlite'
```

Include `vendor/` if Composer is unavailable on the server. If Composer is available, it is better to omit `vendor/` and install production dependencies on cPanel.

Never put the local `.env`, live Stripe secret, database password, or API secrets in the archive.

## 5. Create the production database

In **cPanel → MySQL Databases**:

1. Create a database, for example `CPANELUSER_matchday`.
2. Create a dedicated database user with a strong unique password.
3. Add the user to the database with **ALL PRIVILEGES**.
4. Keep the cPanel prefixes exactly as displayed; they are part of the real database and user names.
5. Set database collation to `utf8mb4_unicode_ci` if cPanel asks.

Do not import the local SQLite file into production. Laravel migrations will create the MySQL schema.

## 6A. Preferred file layout: document root points to `public`

Upload and extract the project to:

```text
/home/CPANELUSER/matchday-africa/
```

Set the domain document root to:

```text
/home/CPANELUSER/matchday-africa/public
```

This is the safest Laravel layout because `.env`, `vendor`, application code, and storage remain outside the public web root. No edit to `public/index.php` is required.

## 6B. Fallback layout when the document root is fixed to `public_html`

Keep the private Laravel application at:

```text
/home/CPANELUSER/matchday-africa/
```

Copy the complete contents of `matchday-africa/public/`—including hidden `.htaccess`, `build`, `images`, and the full `war` directory—into:

```text
/home/CPANELUSER/public_html/
```

Then edit only these two lines in `/home/CPANELUSER/public_html/index.php`:

```php
require __DIR__.'/../matchday-africa/vendor/autoload.php';
$app = require_once __DIR__.'/../matchday-africa/bootstrap/app.php';
```

Do not place the whole Laravel project inside `public_html`.

## 7. Install production PHP dependencies

From cPanel Terminal or SSH:

```bash
cd /home/CPANELUSER/matchday-africa
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

If `composer` is unavailable, upload the locally generated `vendor/` directory built with the same major PHP version as the server.

## 8. Create the production `.env`

Create `/home/CPANELUSER/matchday-africa/.env`. Start from `.env.example`, then use values similar to the following:

```dotenv
APP_NAME="Matchday Africa"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://matchday.africa
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANELUSER_matchday
DB_USERNAME=CPANELUSER_matchdayuser
DB_PASSWORD=REPLACE_WITH_DATABASE_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=.matchday.africa
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public

MAIL_MAILER=smtp
MAIL_HOST=REPLACE_WITH_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=REPLACE_WITH_SMTP_USERNAME
MAIL_PASSWORD=REPLACE_WITH_SMTP_PASSWORD
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=hello@matchday.africa
MAIL_FROM_NAME="Matchday Africa"

FOOTBALL_DATA_API_KEY=
FOOTBALL_DATA_BASE_URL=https://api.football-data.org/v4
FOOTBALL_DATA_CACHE_DURATION=300
FOOTBALL_DATA_MAX_REQUESTS_PER_MINUTE=10
GIPHY_API_KEY=
OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=meta-llama/llama-3.3-70b-instruct:free
OPENROUTER_MAX_DAILY_REQUESTS=2000
ODDS_API_KEY=
ODDS_API_URL=https://api.the-odds-api.com/v4
ODDS_API_REGIONS=us,uk,eu,au
ODDS_API_MARKETS=h2h,spreads,totals

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://matchday.africa/auth/google/callback
TWITTER_CLIENT_ID=
TWITTER_CLIENT_SECRET=
TWITTER_REDIRECT_URI=https://matchday.africa/auth/twitter/callback
TWITTER_API_KEY=
TWITTER_API_SECRET=
TWITTER_ACCESS_TOKEN=
TWITTER_ACCESS_TOKEN_SECRET=
TWITTER_BEARER_TOKEN=

STRIPE_KEY=pk_live_REPLACE
STRIPE_SECRET=sk_live_REPLACE
STRIPE_WEBHOOK_SECRET=whsec_REPLACE
STRIPE_PREMIUM_PRICE_ID=price_REPLACE
CREATOR_REVENUE_SHARE=10

VITE_APP_NAME="Matchday Africa"
```

Leave optional integrations blank until configured. Features that depend on them will not be fully functional, but unrelated routes should continue working.

Generate the application key once:

```bash
cd /home/CPANELUSER/matchday-africa
php artisan key:generate
```

Keep this key for the lifetime of the installation. Changing it later invalidates encrypted sessions and other encrypted data.

## 9. Set safe permissions

Typical shared-hosting permissions are:

```bash
find /home/CPANELUSER/matchday-africa -type d -exec chmod 755 {} \;
find /home/CPANELUSER/matchday-africa -type f -exec chmod 644 {} \;
chmod -R 775 /home/CPANELUSER/matchday-africa/storage
chmod -R 775 /home/CPANELUSER/matchday-africa/bootstrap/cache
chmod 600 /home/CPANELUSER/matchday-africa/.env
```

Use cPanel File Manager permissions if shell `find` is unavailable. Never use `777` unless the hosting provider explicitly requires it and explains why.

## 10. Run all database migrations

The migrations are required. They create the core football, user, chat, prediction, Matchday War, growth, experience, and commerce tables—including `leagues`, whose absence previously caused the `/leagues` failure.

```bash
cd /home/CPANELUSER/matchday-africa
php artisan migrate:status
php artisan migrate --force
```

Do not run the default `DatabaseSeeder` in production because it creates a test user. If the War faction catalogue is empty, run only:

```bash
php artisan db:seed --class=WarFactionSeeder --force
```

Create the first real admin account through registration, then assign its role deliberately using a controlled database update or the project's role command/process. Do not retain a public test account.

## 11. Create the public storage link

```bash
php artisan storage:link
```

If symlinks are disabled by the host, ask support to enable the link from `public/storage` to `storage/app/public`, or copy published user assets through an approved deployment process. Do not expose the whole `storage` directory.

## 12. Clear and rebuild Laravel caches

Run these after `.env` is complete and migrations succeed:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If a route cache command reports a duplicate or closure-related route error, leave routes uncached and investigate; do not let that block the rest of the deployment.

## 13. Configure the Laravel scheduler

In **cPanel → Cron Jobs**, add one job every minute:

```cron
* * * * * /usr/local/bin/php /home/CPANELUSER/matchday-africa/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP binary shown by `which php` in cPanel Terminal if it differs. The job must run every minute; Laravel itself decides when to perform match syncing, standings updates, prediction scoring, notifications, digests, social posts, player syncing, and expired War-room cleanup.

After adding it, verify manually:

```bash
php artisan schedule:list
php artisan schedule:run
```

## 14. Configure the database queue

The app is configured for database queues. On shared hosting, use either:

**Preferred:** cPanel's application/process manager or Supervisor, if available:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

**Shared-hosting fallback:** add this cron every minute:

```cron
* * * * * /usr/local/bin/php /home/CPANELUSER/matchday-africa/artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1
```

Check failed jobs with:

```bash
php artisan queue:failed
```

## 15. Configure SSL, DNS, and HTTPS

1. Point the domain's A record to the cPanel server IP.
2. Wait for DNS propagation.
3. Run cPanel **AutoSSL**.
4. Confirm both `https://matchday.africa` and `https://www.matchday.africa` have valid certificates.
5. Choose one canonical hostname and redirect the other to it.
6. Confirm `.env` uses that exact HTTPS URL.
7. Enable **Force HTTPS Redirect** in cPanel only after SSL works.

## 16. Configure external services

### Google login

In Google Cloud Console add:

- Origin: `https://matchday.africa`
- Redirect URI: `https://matchday.africa/auth/google/callback`

### Twitter/X

Use:

- Callback URI: `https://matchday.africa/auth/twitter/callback`

Add the API credentials to `.env`, then rebuild the config cache.

### Stripe

1. Create the Premium product and recurring price in Stripe.
2. Put its `price_...` value in `STRIPE_PREMIUM_PRICE_ID`.
3. Add the production publishable and secret keys.
4. In Stripe Workbench/Webhooks, create:
   `https://matchday.africa/stripe/webhook`
5. Subscribe to the checkout/payment/subscription events used by the application.
6. Copy the endpoint signing secret into `STRIPE_WEBHOOK_SECRET`.
7. Run `php artisan config:cache` again.
8. Test with Stripe test mode before switching all four values to live mode.

### Football data, odds, GIFs, AI, mail, and social posting

Add each provider's production key and ensure the hosting account permits outbound HTTPS connections. After every `.env` change run:

```bash
php artisan config:cache
```

## 17. Load initial football data

After migration and API configuration, inspect available commands:

```bash
php artisan list | grep -E 'sync|prediction|matchday|preview'
```

Then run the project's sync commands appropriate to the enabled provider, for example:

```bash
php artisan sync:leagues
php artisan sync:matches
php artisan sync:standings
```

Use the exact names shown by `artisan list` if the command signatures differ. Confirm records in cPanel phpMyAdmin before opening the site to the public.

## 18. Production acceptance tests

Test on desktop and a physical mobile device:

1. `/` — homepage loads with no debug output.
2. `/login` and `/register` — accounts work and mail is received.
3. `/matches` — match list loads without paginator/collection errors.
4. `/leagues` — league cards load and the `leagues` table exists.
5. A match page — teams, status, chat, prediction entry, and sharing render.
6. `/predictions` — submit a test prediction and verify it is stored.
7. `/war` — warrior assets, trailer, wallpapers, and controls load.
8. `/war/match/{id}` — single-player battle works for 60 seconds.
9. Two separate devices — create, join, start, and finish a browser two-player War room.
10. `/shop` and `/premium` — products render.
11. Stripe test checkout — payment returns successfully and the webhook grants the correct entitlement.
12. `/library` — the purchased rights-safe download is accessible only to the entitled account.
13. Admin routes — accessible to an admin and blocked for ordinary users.
14. Scheduler — new entries appear in `storage/logs/laravel.log`.
15. Queue — no unexpected entries remain in `failed_jobs`.
16. Browser console and Network tab — no 404, 419, mixed-content, or 500 responses.

## 19. Go-live sequence

1. Put the current site in a brief maintenance window if replacing it.
2. Take a final file and database backup.
3. Upload the approved build.
4. Run `php artisan migrate --force`.
5. Run `php artisan optimize:clear` followed by the cache commands.
6. Check cron jobs and the queue worker.
7. Perform a five-minute smoke test of homepage, login, matches, predictions, War, shop, and webhook health.
8. Remove maintenance mode with `php artisan up` if it was enabled.
9. Monitor logs and Stripe webhook deliveries closely for the first 24 hours.

## 20. Future update procedure

For every release:

```bash
php artisan down --retry=60
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

Build front-end assets locally first and upload `public/build`. Upload changed `public/war` media only when it has changed; it is the largest part of the release.

## 21. Rollback and recovery

Before each release retain:

- The previous application archive
- The previous `public/build` directory
- A database export made immediately before migration
- A secure copy of the production `.env`

If a release fails, restore the previous files and database together. Do not roll application code back across a destructive database migration without restoring the matching database backup.

## 22. Common failures

### HTTP 500

```bash
tail -n 100 storage/logs/laravel.log
php artisan optimize:clear
```

Check PHP version, writable directories, `.env`, `APP_KEY`, Composer dependencies, and cPanel's error log.

### `no such table` or missing-table errors

The app is still using SQLite or migrations were not completed. Confirm `DB_CONNECTION=mysql`, clear configuration cache, then run:

```bash
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
php artisan config:cache
```

### CSS, JS, warrior images, or wallpapers missing

Confirm `public/build` and the entire `public/war` directory are in the active document root. Check filename case because Linux hosting is case-sensitive.

### 419 Page Expired

Check HTTPS, `APP_URL`, session database migrations, `SESSION_DOMAIN`, secure cookies, and stale configuration caches.

### Jobs or match updates do not run

Confirm the one-minute scheduler cron uses the correct PHP binary and absolute Artisan path. Then check `schedule:list`, Laravel logs, queued jobs, and API rate limits.

### Stripe payment succeeds but access is not granted

Check Stripe webhook deliveries, the endpoint signing secret, server time, application logs, and whether production is consistently using either test or live keys—not a mixture.
