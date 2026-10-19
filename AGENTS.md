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
| `.github/workflows/matchday-africa.yml` | CI: tests + Vite build on PRs touching `matchday-africa/**`; on push to `main`, tests then **FTPS upload + SSH server steps + HTTP checks**. | n/a |
| `.github/workflows/render-reel.yml` | Fallback reel renderer (started by the site via the GitHub API when `REEL_RENDERER=github`). | n/a |
| `.github/workflows/render-social.yml` | Legacy metadata validator; saves a JSON artifact, never deploys media. | n/a |

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

## 4. CI/CD

The workflow now tests PHP 8.2/8.4 with GD, preserves the tested Vite build as an
artifact, and runs the Remotion smoke test when renderer code/art/fonts change.
Require the stable **CI gate** status in GitHub branch rules. Deployment is restricted
to `main`: SSH preflight → maintenance mode → FTPS upload → SSH production script →
public release/page/API checks. Missing SSH configuration fails before upload.
The required CI gate runs on every PR so documentation-only PRs cannot get stuck
waiting for a path-filtered check.

`deploy-production.sh` preserves the app key and data, installs locked dependencies,
runs migrations/caches/worker restart, and brings the app up only after server checks
pass. Server reels install on lock changes and render-check on source/asset changes.
FTP preserves generated media, uploads, storage, vendor, and local databases.
The actual cPanel web root is `/home/matchdayaf/public_html`; the private app is
`/home/matchdayaf/matchday-africa`. Configure server `APP_PUBLIC_PATH` and matching
GitHub `CPANEL_PUBLIC_ROOT`. Deploys back up the database/config before migrations,
publish into the served web root, and verify real CSS/JS responses as well as pages.
The fallback reel workflow uses the `production` environment; the legacy social
workflow never uploads its JSON stub to production.

See `matchday-africa/CPANEL_DEPLOYMENT_RUNBOOK.md` for exact FTP/SSH secret names,
verified SSH host-key setup, application-root mapping, branch rules, cron, and recovery.
Use the cPanel application owner; Linux root is unnecessary. Server access, GitHub
secret configuration, branch rules, and cron must be verified separately. Workflow
code alone does not establish that hosting is configured. A failed in-place upload
or server step leaves maintenance mode active; do not blindly reopen a partial release.

Local deployment regression checks: `python3 scripts/ci/test-deployment.py` from the
repository root, with PHP on PATH and Laravel vendor dependencies installed. Shell
checks: `shellcheck matchday-africa/deploy-production.sh scripts/ci/deploy-ssh.sh`.

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
- Live `APP_TIMEZONE` was verified as `Africa/Lagos` on 19 October 2026; the production
  template now matches it. Kick-off display formatting remains a separate issue (rule 5).
- West Ham and Wolves have no crest-free statue (`art => null` in `config/armies.php`); their cards use club colours only.
- Native speakers should review the Pidgin, Swahili/Sheng and Mzansi captions in `app/Services/Griot.php`.
- Buffer's GraphQL API is in public beta and was implemented from its docs without a live account. Check
  the first posts, especially whether Instagram treats the video as a Reel.
- The reel took ~85 s to render on a 4-core container. A small shared host will be slower; `check.mjs` prints an estimate.
