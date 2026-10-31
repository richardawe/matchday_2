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
   `storage/app/reels`), `public/storage`, `public/generated/` (reels, one folder per match, and published radio audio), `vendor/`.
   Media goes out as static files from `public/generated/`: the host serves static files with byte ranges but
   drops them from PHP responses, and iPhones and Buffer need ranges (`ReelService::url()`, `RadioBulletin::audioUrl()`).
5. **Times:** `matches.match_date` holds **UTC wall-clock** values (that's how the football-data.org sync
   writes them) while `APP_TIMEZONE` is `Africa/Lagos`. Compare kick-offs against `now('UTC')` and
   reinterpret with `Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')` (see
   `EspnSyncService::kickoff()`). Display formatting elsewhere still shows the raw value; fixing that is a separate task.
6. **Art:** automated outputs (cards, reels, arena, social posts) use only the crest-free statues in
   `public/war/downloads/rights-safe/`, processed by `scripts/build-armies.mjs` into
   `public/war/army/*.webp` (web) and `resources/armies/*.webp` (server cut-outs). Never use
   `public/war/warriors/` (they show club crests) for anything automated. One exception, approved by the owner on
   19 Oct 2026: Wolves' `wolf-warband` statue (from `warriors/wolves.png`, plinth text cropped) whose shield resembles the crest. Club → army mapping: `config/armies.php`.
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
| 20-second reel (Remotion) | `reel/`, `app/Services/ReelService.php`, `reel:render` command, `ReelController` | `reel:render` 1m (one reel per run, ~3 min after FT, 3 attempts per match) | `REEL_RENDERER=off\|server\|github` |
| Buffer → X, Instagram (live goal and red-card cards; reels go through growth_posts) | `app/Services/BufferPublisher.php`, `BufferClient.php` (ported from ngr.ltd), `buffer:publish`, `config/buffer.php`, table `social_posts` | `buffer:publish` 1m | `BUFFER_API_KEY` + `BUFFER_CHANNEL_*` |
| Planned social posts (reels, news, fixture previews with a GD fixture card, morning fixtures, night results; X all, Instagram image/video, TikTok reels) | `GrowthPostGenerator.php` (`twitter:engage`), `FixtureCardRenderer.php` (route `matches.card`), table `growth_posts` (per channel), admin `/admin/twitter` | `twitter:engage` 10m plans; `buffer:publish` 1m sends when a channel's slot is free | `BUFFER_AUTO` + `BUFFER_CHANNEL_*` |
| Reels-first homepage | `resources/views/home-v2.blade.php`, `HomeShowcase.php` (reels, matchweek board), `resources/js/home-reels.js`, shared blocks in `resources/views/partials/home/`; arena at `/war/arena` (`ArenaService.php`) | n/a | `HOME_V2` (preview `/?home=v2`) |
| Signup attribution + `/admin/users` | `app/Support/Attribution.php` (first-touch `md_attr` cookie), `CaptureAttribution` middleware, `User::creating` hook in `AppServiceProvider`, `Admin/UserController.php` | n/a | needs `users.utm_*` migration |
| Guest predictions + league invites | `GuestPredictions.php` (`md_guest` cookie, claimed on `Login`), `MatchPredictionRecorder.php`, `LeagueTable.php` (weekly Mon–Sun Lagos), `/prediction-leagues/invite/{code}`, `GET /auth/google` | n/a | needs `guest_predictions` migration |
| Follow/share/WhatsApp | `config/social.php`, components `social-follow`, `share-bar` (`App\Support\ShareLink`, UTM `utm_medium=share`), `whatsapp-channel`; reel pages `/reels`, `/reels/{match}` (`ReelPageController`) | n/a | `WHATSAPP_CHANNEL_URL` (CTA hidden while empty) |
| SEO | `SocialMetaService.php` (titles, OG/X tags, JSON-LD SportsEvent/SportsTeam/NewsArticle/VideoObject), `@section('meta_title'/'meta_description'/'meta_robots')`, `SitemapController` (`/sitemap.xml`), `public/robots.txt` | n/a | always on |
| Stale live matches | `StaleMatchCloser.php`, `matches:close-stale` (FINISHED after kick-off + 3h with no update for 30 min, or + 6h); flagged `metadata.status_inferred_at`, so scoring and reels wait (`FootballMatch::statusInferred()`) | every 10 min | always on |
| Signup nudge, footer email | `partials/signup-nudge.blade.php` (localStorage `md_nudge`), `EmailSubscriptionController`, table `email_subscribers` (capture only: no emails are sent yet) | n/a | needs `email_subscribers` migration |
| Live Griot timeline (ESPN commentary → standard plays → picked moments → Griot captions in 4 voices, shot map, momentum, Kokoro Griot voice clips) | `app/Services/Espn/EspnCommentaryMapper.php`, `app/Services/Griot/*` (`MomentPicker`, `GriotTimeline`, `GriotTeller`, `GriotWriter`), `partials/match-timeline`, `radio/griot-voice.mjs`, `radio/griot-writer.mjs`, tables `match_plays`, `griot_posts`; training in `griot-ml/` (repo root, not deployed) | plays saved by `espn:sync details`; `griot:timeline` 1m, `griot:write` 1m, `griot:voice` 1m | `GRIOT_TIMELINE`, `GRIOT_VOICE`, `GRIOT_WRITER` (+ migration) |
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
   the public https URL (Buffer fetches media from it). Then `BUFFER_AUTO=true` for the planned posts;
   watch `/admin/twitter` for the first sends (failed posts show Buffer's own error).
6. Griot timeline: `GRIOT_TIMELINE=true` (needs ESPN live with details). Then `php artisan griot:voice --audition`
   (writes samples to `storage/app/griot/audition`), listen, and set `GRIOT_VOICE=true`; the deploy installs the radio
   Node runtime when `GRIOT_VOICE` or `GRIOT_WRITER` is on. Picker and writer models come from `griot-ml/` (README there);
   ship each only when it beats the rules/templates on held-out matches.
7. Rollback any feature by switching its flag off and clearing caches.

**Server operations without a shell:** Actions → *Server operations* (`.github/workflows/server-ops.yml`,
`main` only, production environment, deploy key). `status` (release, migrations, Buffer settings with the key
masked, cron, post counts), `buffer-channels` (channel IDs), `configure-buffer` (writes `BUFFER_API_KEY` from
the production secret of that name plus channel IDs, `BUFFER_AUTO`, `BUFFER_SLOTS` into `.env` through
`matchday-africa/scripts/set-env.php`, which backs `.env` up first, then `config:cache`), `plan-posts` (plan and
send once), `clear-drafts` (delete unsent X drafts), `reel-check` (recent PL matches with events/chapters/reel state, finds Node 18+, `npm ci` + `node check.mjs`
in `reel/`), `configure-reel` (`REEL_RENDERER`, `REEL_NODE`, `REEL_GITHUB_TOKEN` from the production secret), `configure-reel-voice` (Kokoro commentary over new reels: `REEL_NARRATION` on/off),
`reel-render` (rebuild one match's chapters and render its reel now), `configure-espn` (`live`, `shadow` or `off`),
`espn-sync` (fixtures, live and details passes now, unmatched fixtures listed), `reel-queue` (backfill reels between two Lagos dates), `configure-home` (`HOME_V2` on or off), `configure-growth` (`WHATSAPP_CHANNEL_URL`, `none` hides the CTA). From a terminal: `gh workflow run server-ops.yml -f action=status`.

## 6. Environment variables added in this round
See the block at the end of `matchday-africa/.env.example` / `production.env.example`.

| Variable | Default | Meaning |
|---|---|---|
| `ESPN_ENABLED` | false | Use ESPN's feed |
| `ESPN_SHADOW` | true in templates | Only record ESPN's view and log differences |
| `REEL_RENDERER` | off | `server` (this host runs Node + Remotion) or `github` |
| `REEL_NODE` | node | Path to the Node binary for the scheduler |
| `REEL_VOICE` | en | Caption voice in reels: en, pcm, sw, za |
| `REEL_NARRATION` / `REEL_NARRATION_VOICE` | false / bm_george | Kokoro commentary over new reels (`radio/speak.mjs`, server renderer only; a failure renders without it) |
| `REEL_DELAY_MINUTES` | 3 | Wait after full time before rendering |
| `REEL_GITHUB_REPO` / `REEL_GITHUB_TOKEN` | this repo / empty | For the GitHub fallback |
| `BUFFER_API_KEY` | empty | Buffer API key |
| `BUFFER_MODE` | shareNow | Or `addToQueue` to use the Buffer schedule |
| `BUFFER_CHANNEL_X` / `_INSTAGRAM` / `_TIKTOK` | empty | Buffer channel IDs |
| `BUFFER_AUTO` | false | Queue and send planned posts (otherwise drafts on `/admin/twitter`) |
| `BUFFER_SLOTS` / `BUFFER_PREVIEWS_PER_DAY` | 08:00,13:00,19:00 / 3 | Lagos times; each account posts at most once per slot (live cards and reels included), so three a day. Fixture previews planned a day |
| `WHATSAPP_CHANNEL_URL` | empty | WhatsApp channel invite link for the homepage/match CTA |
| `BUFFER_MONTHLY_REQUESTS` / `_SOFT_LIMIT` / `_HARD_LIMIT` | 3000 / 2500 / 2900 | Buffer API budget, counted in `buffer_usage`; posting stops at the hard limit |
| `GRIOT_TIMELINE` | false | Store ESPN commentary as plays and show the live Griot timeline on match pages |
| `GRIOT_VOICE` / `GRIOT_VOICE_BLEND` / `GRIOT_VOICE_SPEED` | false / bm_george:0.6,am_onyx:0.4 / 0.92 | Kokoro clips per post in the Griot's voice (blend of two Kokoro voices, lowered, warmed) over a talking-drum groove with whistles, crowd and drum effects (`radio/griot-sound.mjs`); files in `public/generated/griot/`. Blend and speed also shape the radio's Griot voice |
| `RADIO_VOICE` | griot | Daily bulletin voice: `griot` (with groove and effects) or a Kokoro voice ID |
| `GRIOT_WRITER` / `GRIOT_WRITER_MODEL` | false / storage/app/griot/writer | Fine-tuned Qwen3-0.6B writer (transformers.js); every caption fact-checked, templates otherwise |
| `GRIOT_PICKER_MODEL` | storage/app/griot/picker.json | Learned moment picker; missing file = strict rules |

## 7. Open items and known issues
### Live Griot timeline (31 Oct 2026)
- Built on a branch, off by default. ESPN commentary (already in the summary the details pass fetches) is stored as
  standard plays; the provider's text is kept for reading details only and is never published or sent to the teacher.
- Training uses ESPN and StatsBomb open data (owner's decision, 31 Oct). StatsBomb needs attribution under its terms.
- Pidgin, Swahili/Sheng and Mzansi timeline lines in `GriotTeller.php` need a native speaker's review. Voice clips are
  English only (Kokoro has no Pidgin/Swahili voice).
- Picker and writer are not trained yet: the teacher labelling needs `OPENROUTER_API_KEY` (`griot-ml/label.py`) and the
  writer fine-tune a GPU. Until then the strict rules pick and the templates speak.
- Next step proposed (not built yet, waiting for the owner's go-ahead): "Plan A" writer the Requstory way: OpenRouter writes a large
  library of Griot lines with slots per moment kind; a CPU-trained bge-small head (as in requstory-ml `train.py`) chooses the
  line; slots are filled from facts. No GPU. "Plan B" (later): LoRA on Qwen3-0.6B on a Mac (`mps`) or Colab.
  Needs `OPENROUTER_API_KEY` in the environment to run fetch → label → train.

### Matchday Radio (21 Oct 2026)
- Live automatic publishing is enabled at 06:00/12:00/18:00 Lagos time; the minute cron is verified.
- Morning pilot published at `/listen/2` (100 seconds, 24 kbps Kokoro `bm_george`).
- Owner explicitly requested BBC Sport and Guardian RSS for radio on 21 Oct, with attribution in terms; no publisher licence was supplied. Credits and source links are required; do not treat attribution as a licence.
- RSS XML headers must split the opening characters in Blade: production enables PHP short tags.
- `radio:edition` at 06:00/12:00/18:00 Africa/Lagos; `radio:work` every minute in the background.
- `RADIO_ENABLED=false` and `RADIO_AUTO_PUBLISH=false` by default; new table `radio_bulletins`.
- Kokoro runtime in `matchday-africa/radio/` is independent of the unmerged reel voice branch.
- Compact Listen/Share player shows timed story illustrations during playback. audio_cues are measured from Kokoro samples, never estimated; only rights-safe Matchday artwork is used.
- Homepage radio sits above reels; `/listen`, `/listen/feed.xml`, admin `/admin/radio`.
- Only `RADIO_NEWS_SOURCES` is approved for radio; source permissions are not assumed.
- 22 Oct 2026: the owner decided radio may repeat source wording as long as the source is credited on air; the 12-word copy check was removed. Credits and rumour labels are still enforced.
- 31 Oct 2026: the owner chose the Griot as the radio voice (`RADIO_VOICE=griot`, the default): blended Kokoro voice over a
  synthesised talking-drum groove with sound effects (`radio/griot-sound.mjs`, shared with the live timeline clips).
  Switch with Server operations → `configure-radio-voice` (`griot` or a Kokoro voice ID such as `bm_george`).
- Radio uses its own model: `RADIO_MODEL` (production: `anthropic/claude-haiku-5.5`, medium reasoning kept out of the reply), falling back to `OPENROUTER_MODEL`.
- Read `matchday-africa/docs/RADIO.md` for Node/FFmpeg setup, pilot, publishing and recovery.
- Server operations: `radio-check`, `configure-radio` (draft/auto/off), `configure-radio-model` (`RADIO_MODEL`, e.g. `anthropic/claude-haiku-5.5`; `default` = `OPENROUTER_MODEL`), `radio-run`, `radio-status`, `radio-voice`, `radio-publish`, `configure-radio-voice`.

- Live `APP_TIMEZONE` was verified as `Africa/Lagos` on 19 October 2026; the production
  template now matches it. Kick-off display formatting remains a separate issue (rule 5).
- West Ham has no statue (`art => null`); it isn't in the 2026–27 Premier League. Wolves uses the approved `wolf-warband` exception.
- Native speakers should review the Pidgin, Swahili/Sheng and Mzansi captions in `app/Services/Griot.php`.
- Buffer's client is the one ngr.ltd and housedata.uk run in production (`api.buffer.com/graphql`, variables,
  Instagram type at `metadata.instagram.type` read from the live schema). Still check the first posts here,
  especially whether Instagram treats the reel video as a Reel.
- The X timeline only states the site's own facts. Don't quote `match_previews.preview_content` in automated
  posts: `MatchPreviewService` invents head-to-head records when it has none. Article cover photos come from
  other publishers, so they are never uploaded; X shows the article's link card.
- The reel took ~85 s to render on a 4-core container. A small shared host will be slower; `check.mjs` prints an estimate.
