# Matchday Africa: agent guide

Read this first. It is the project memory for coding agents (Codex reads `AGENTS.md`; Claude Code
reads `CLAUDE.md`, which points here). Last updated 19 Oct 2026, after the live-data, story-card,
reel, Buffer and arena work (commits up to `22bcc73` on `main`).

## 1. What is where

| Path | What it is | Deployed? |
|---|---|---|
| `matchday-africa/` | **The live site**: Laravel 12, PHP 8.2+, Blade + Vite + Tailwind, MySQL in production, SQLite in tests. Served at https://matchday.africa from cPanel. | Yes |
| `matchday-africa/reel/` | Separate Node project (Remotion 4.0.532, React 18) that renders the 20-second full-time reel. Own `package.json` / `package-lock.json`. | Uploaded as source; needs `npm ci` on the server |
| Repo root (`app/`, `worker/`, `db/`, `scripts/`, `package.json`, `next.config.ts`) | An older Next/vinext + Cloudflare D1 starter. **Not deployed.** Don't build or change it for production. | No |
| `.github/workflows/matchday-africa.yml` | CI: tests + Vite build on PRs touching `matchday-africa/**`; on push to `main`, tests then **FTP deploy to production**. | n/a |
| `.github/workflows/render-reel.yml` | Fallback reel renderer (started by the site via the GitHub API when `REEL_RENDERER=github`). | n/a |
| `.github/workflows/render-social.yml` | Legacy stub (only saves a JSON spec). Superseded by the reel pipeline. | n/a |

Key docs in `matchday-africa/`: `CPANEL_DEPLOYMENT_RUNBOOK.md` (server setup), `deploy-production.sh`
(post-upload steps), `docs/ESPN_LIVE_DATA_PLAN.md` (live data design and risks), `docs/redesign-brief.html`
(the Griot concept), `reel/README.md` (reel renderer).

## 2. Rules

1. **A push to `main` that touches `matchday-africa/**` deploys to production.** Work on a branch and
   run the tests first.
2. **Tests:** `cd matchday-africa && composer install && npm ci && npm run build && php vendor/bin/phpunit`.
   The Vite build is required: views fail without `public/build/manifest.json`. CI uses `php artisan test`
   (it also exits 0 when green). Expect 91+ tests to pass.
3. **Every new feature is off until switched on in `.env`** (`ESPN_ENABLED`, `REEL_RENDERER`, `BUFFER_*`)
   and stays quiet until its migration exists (`MatchStoryService::ready()`, `Schema::hasTable` guards). Keep it that way.
4. **Never delete or overwrite on the server:** `.env`, `storage/` (incl. `storage/app/story-cards`,
   `storage/app/reels`), `public/storage`, `public/generated/` (GitHub-rendered reels, one folder per match), `vendor/`.
5. **Times:** `matches.match_date` holds **UTC wall-clock** values (that's how the football-data.org sync
   writes them) while `APP_TIMEZONE` is `Africa/Lagos`. Compare kick-offs against `now('UTC')` and
   reinterpret with `Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')` (see
   `EspnSyncService::kickoff()`). Display formatting elsewhere still shows the raw value; fixing that is a separate task.
6. **Art:** automated outputs (cards, reels, arena, social posts) use only the crest-free statues in
   `public/war/downloads/rights-safe/`, processed by `scripts/build-armies.mjs` into
   `public/war/army/*.webp` (web) and `resources/armies/*.webp` (server cut-outs). Never use
   `public/war/warriors/` (they show club crests) for anything automated. Club → army mapping: `config/armies.php`.
7. **Live data:** the ESPN feed is unofficial and its terms ban automated use. The owner accepted that as a
   stopgap. Keep the circuit breaker (`EspnClient`) and the football-data.org fallback working.
8. **Commit messages** describe the change; don't put model names in them.

## 3. Feature map

| Feature | Main code | Scheduler (routes/console.php) | Switch |
|---|---|---|---|
| ESPN live scores, fixtures, events, stats | `app/Services/Espn/*`, `app/Console/Commands/EspnSync.php`, `config/espn.php` | `espn:sync fixtures` 6h, `espn:sync live` 1m (3 passes), `espn:sync details` 1m | `ESPN_ENABLED`, `ESPN_SHADOW` |
| football-data.org (fallback, standings) | `app/Services/FootballDataService.php`, `MatchService.php` | `matches:refresh *`, `sync:standings` | `FOOTBALL_DATA_API_KEY` |
| Competition ranking (home pulse, match centre) | `config/competitions.php`, `League::competition_rank/region` | n/a | n/a |
| Griot story chapters (kick-off, goals, red cards, HT, FT) | `app/Services/MatchStoryService.php`, `Griot.php` (captions in en/pcm/sw/za), `app/Models/MatchMoment.php` | `story:sync` 1m (also after each ESPN live pass) | needs `match_moments` migration |
| Story cards (PNG, GD) | `app/Services/StoryCardRenderer.php`, route `moments.card` | drawn on first request, cached in `storage/app/story-cards` | needs GD with FreeType + WebP |
| 20-second reel (Remotion) | `reel/`, `app/Services/ReelService.php`, `reel:render` command, `ReelController` | `reel:render` 1m (one reel per run, ~3 min after FT) | `REEL_RENDERER=off\|server\|github` |
| Buffer → X, Instagram, TikTok | `app/Services/BufferPublisher.php`, `buffer:publish`, `config/buffer.php`, table `social_posts` | `buffer:publish` 1m | `BUFFER_API_KEY` + `BUFFER_CHANNEL_*` |
| Home arena | `resources/views/partials/home-arena.blade.php`, `resources/js/arena.js` (GSAP), `arena3d.js` (three.js, desktop only) | rides the home pulse refresh | always on when a Premier League match qualifies |

## 4. CI/CD: what the pipeline must do

This is the task list for setting up automatic deploys. What exists today: the deploy workflow tests,
builds and **uploads files by FTP only**. It can't run migrations, clear caches or install the reel's
Node packages, so those still need a manual `bash deploy-production.sh` in cPanel Terminal.

### 4.1 Make deploys run the server steps automatically
- Preferred: if the host allows SSH, add a step after the FTP upload in `matchday-africa.yml` that SSHes in
  (for example `appleboy/ssh-action`, pinned to a release) and runs `cd <app root> && bash deploy-production.sh`.
  New secrets in the `production` environment: `CPANEL_SSH_HOST`, `CPANEL_SSH_USER`, `CPANEL_SSH_KEY`,
  `CPANEL_SSH_PORT`. Ask the owner whether SSH is enabled before building this.
- If there is no SSH, don't invent a public "run migrations" URL without the owner's sign-off. Document
  the manual step instead.

### 4.2 Extend `deploy-production.sh`
Add, after `composer install` and before the caches:
1. `mkdir -p storage/app/story-cards storage/app/reels public/generated/reels`
2. If `.env` has `REEL_RENDERER=server` and `reel/package-lock.json` changed since the last run (keep a
   hash in `storage/app/reel-lock.sha`): `(cd reel && npm ci --omit=dev --no-audit --no-fund)`. Remotion
   downloads its headless Chrome on first use. The first time, also run `(cd reel && node check.mjs)` and
   stop with its message if it fails.
3. Keep `php artisan migrate --force` (three new migrations: ESPN columns, `match_moments`, `social_posts`).
4. Keep `config:cache`. It means **any `.env` change needs this script (or `php artisan optimize:clear`) again**.

### 4.3 Fix the FTP sync rules in `matchday-africa.yml`
- Add to `exclude`: `**/public/generated/**`, `**/reel/node_modules/**`, `**/reel/out/**`.
- Make sure these committed build outputs **are** uploaded: `resources/armies/**`, `resources/fonts/**`,
  `public/war/army/**`, `reel/**` (source only).
- `public/build` is produced in CI by `npm run build` and must be uploaded (it is, via `local-dir`).

### 4.4 Add a reel smoke test to CI
A job that runs only when `matchday-africa/reel/**` changes:
`cd matchday-africa/reel && npm ci && node check.mjs` on `ubuntu-latest` (about 1–2 minutes; Remotion fetches
its own Chrome and ffmpeg). It proves the reel still renders before deploy.

### 4.5 Server cron (one-off, cPanel → Cron Jobs)
- `* * * * * /usr/local/bin/php /home/CPANELUSER/matchday-africa/artisan schedule:run >> /dev/null 2>&1`
- Queue fallback if no worker: see the runbook §14.
- The ESPN, story, reel and Buffer jobs use `runInBackground()` / `withoutOverlapping()`; `exec` must be
  allowed for the PHP CLI. Check with `php artisan schedule:list`.
- If `node` isn't on cron's PATH, set `REEL_NODE` to the full path (cPanel's "Setup Node.js App" shows it).

### 4.6 Secrets checklist (GitHub → Settings → Environments → production)
`CPANEL_FTP_SERVER`, `CPANEL_FTP_USERNAME`, `CPANEL_FTP_PASSWORD`, `CPANEL_FTP_SERVER_DIR` (app root,
ending in `/`), optional `CPANEL_FTP_PROTOCOL`, `CPANEL_FTP_PORT`; plus SSH secrets if 4.1 is used.
`render-reel.yml` reuses the FTP secrets. For `REEL_RENDERER=github`, the **server** `.env` needs
`REEL_GITHUB_TOKEN` (a fine-grained token on this repo with Actions: read and write).

### 4.7 After every deploy, check
- `GET /up` returns 200; `/`, `/matches`, a match page and `/matches/{id}/live` return 200.
- `php artisan migrate:status` shows nothing pending; `php artisan matchday:health` looks sane.
- The admin dashboard's "Live feed (ESPN)" line and recent sync runs.

## 5. Go-live order for the new features (server `.env`, then `optimize:clear` or the deploy script)
1. `php artisan migrate --force`.
2. `ESPN_ENABLED=true`, `ESPN_SHADOW=true` for one matchday; check the log for `ESPN shadow difference`
   and the admin dashboard for unlinked fixtures (fix with `team_aliases` in `config/espn.php`).
3. `ESPN_SHADOW=false`: ESPN becomes the primary live source; cards and chapters follow automatically.
4. `cd reel && npm ci && node check.mjs`: on PASS set `REEL_RENDERER=server`, else `github` + token.
5. Buffer: create a key at publish.buffer.com/settings/api, set `BUFFER_API_KEY`, run
   `php artisan buffer:publish --channels`, fill `BUFFER_CHANNEL_X/INSTAGRAM/TIKTOK`. `APP_URL` must be
   the public https URL (Buffer fetches media from it).
6. Rollback any feature by switching its flag off and clearing caches.

## 6. Environment variables added in this round
See the block at the end of `matchday-africa/.env.example` / `production.env.example`.

| Variable | Default | Meaning |
|---|---|---|
| `ESPN_ENABLED` | false | Use ESPN's feed |
| `ESPN_SHADOW` | true in templates | Only record ESPN's view and log differences |
| `REEL_RENDERER` | off | `server` (this host runs Node + Remotion) or `github` |
| `REEL_NODE` | node | Path to the Node binary for the scheduler |
| `REEL_VOICE` | en | Caption voice in reels: en, pcm, sw, za |
| `REEL_DELAY_MINUTES` | 3 | Wait after full time before rendering |
| `REEL_GITHUB_REPO` / `REEL_GITHUB_TOKEN` | this repo / empty | For the GitHub fallback |
| `BUFFER_API_KEY` | empty | Buffer API key |
| `BUFFER_MODE` | shareNow | Or `addToQueue` to use the Buffer schedule |
| `BUFFER_CHANNEL_X` / `_INSTAGRAM` / `_TIKTOK` | empty | Buffer channel IDs |

## 7. Open items and known issues
- `production.env.example` sets `APP_TIMEZONE` twice (`Africa/Lagos`, then `UTC`). Confirm which the
  live `.env` uses before changing any time logic (rule 5 assumes `Africa/Lagos`).
- West Ham and Wolves have no crest-free statue (`art => null` in `config/armies.php`); their cards use club colours only.
- Native speakers should review the Pidgin, Swahili/Sheng and Mzansi captions in `app/Services/Griot.php`.
- Buffer's GraphQL API is in public beta and was implemented from its docs without a live account. Check
  the first posts, especially whether Instagram treats the video as a Reel.
- The reel took ~85 s to render on a 4-core container. A small shared host will be slower; `check.mjs` prints an estimate.
