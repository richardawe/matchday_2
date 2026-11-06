# Plan: automated TikTok LIVE and YouTube match streams

Status: **discovery only (step 1 of the feature initiation, 6 Nov 2026). Nothing is built yet.** This file
records how the site works today, how the stream fits into it, and the decisions the owner needs to make
before building starts.

## 1. What exists today

### Stack and hosting
- The live site is `matchday-africa/`: Laravel 12, PHP 8.2+, Blade, Vite, Tailwind and MySQL. It runs on a
  **cPanel shared host**. Cron runs `php artisan schedule:run` every minute. There is no Docker, root access,
  long-running daemon or open inbound port. The FFmpeg on the host is the reel renderer's small build.
- Node runs on the same host for the Remotion reel (`reel/`) and the Kokoro radio/Griot audio (`radio/`).
- Settings: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `BROADCAST_CONNECTION=log`. No WebSocket
  server (Reverb or Pusher) is installed. `MatchScoreUpdated` is broadcast from `FootballMatchObserver`,
  but the `log` driver just writes it to the log.
- The repo-root Next/Cloudflare app isn't deployed and isn't relevant here.

### Where scores come from
| Source | Code | Cadence | Role |
|---|---|---|---|
| ESPN public JSON (unofficial, terms ban automated use, accepted as a stopgap) | `app/Services/Espn/*`, `espn:sync` | `live`: every minute, 3 passes ≈ **every 20 s**; `details` (summary, stats, commentary): each live match about every 2 min; `fixtures`: every 6 h | Primary live source (`ESPN_ENABLED`, `ESPN_SHADOW=false`) |
| football-data.org | `FootballDataService`, `MatchService`, `matches:refresh` | `live` every minute, `today` every 15 min, `upcoming` every 6 h | Fallback, fixtures, standings |

It all polls. There's no push. Each pass writes to the `matches` table (`home_score`, `away_score`, `status`,
`minute`, `match_date` stored as **UTC wall-clock**, see AGENTS rule 5) and upserts `match_events`.

### How goal events flow
- There is no event bus. A goal is (a) a change to `matches.home_score`/`away_score` and (b) a `match_events`
  row with `type = goal`, `minute`, `player_name`, `team_id`, `is_own_goal`, `is_penalty`.
- ESPN event rows get a **stable id**: `football_data_id = crc(espn_id|type|clock|player)`
  (`EspnSyncService::saveEvents`). Rows that ESPN no longer reports get deleted, which is how a disallowed goal
  disappears.
- Code that reacts to goals today reads by **diffing state with idempotency keys**, not by subscribing to
  anything. `MatchAlerts::events()` emits `score:2-1`, `red:<id>` and `full_time`, and `match_alert_log` (unique
  `match_id,key`) makes sure each goes out once. `MatchStoryService` (`story:sync`) and the Griot timeline work the same way.
- Front-end refreshes: the home pulse (`/api/matchday-pulse`, JSON with a `live` HTML fragment) every 30 s, and the
  match chronicle (`/matches/{id}/chronicle`) every 20 s.

**What this means for latency:** a goal reaches our database ≤ ~20 s after ESPN shows it, plus ESPN's own
delay, which hasn't been measured yet. The scorer name comes in the same scoreboard pass. The acceptance
criterion ("on stream within 5 s of reaching the matchday backend") is achievable if the stream feed checks the
database every 1–2 s. End to end, viewers will still see goals about 20–40 s after they happen.

### Other things to reuse
- **Admin auth**: `auth` + `admin` middleware, with existing admin pages like `/admin/reels` and `/admin/radio`.
  `ADMIN_AUTH_*` isn't needed.
- **Operator notifications**: the only channels that actually send are the in-app inbox and **web push**
  (`App\Services\Alerts\Alerts::deliver`). Mail is `log` (nothing sends), and there's no WhatsApp or Telegram.
- **Art rules (AGENTS rule 6)**: automated outputs never show club crests. The stream is automated output, so it
  uses team names plus our own colour/monogram tiles, the same as `FixtureCardRenderer`, or the crest-free army statues.
- **Sound**: `radio/griot-sound.mjs` synthesises talking-drum calls. They're our own work and royalty-free, so they're a good goal cue.
- **Competition ranking**: `config/competitions.php` and `config/espn.php` slugs are the natural basis for `STREAM_COMPETITIONS`.
- **Stale matches**: `matches:close-stale` closes matches nobody updated. The window end should use real
  `FINISHED`, else kick-off + 150 min, and ignore `metadata.status_inferred_at`.

## 2. What doesn't fit the current host

The streamer (Chromium + Xvfb + FFmpeg running for 2 h+, an nginx-rtmp relay, 15 Mbps upload) **can't run on the
cPanel host**: there are no daemons, no Docker, process time limits and shared CPU. It needs a separate VPS.
That's new paid infrastructure, so it needs the owner's approval (see decisions).

Proposed split:

| Part | Runs on | Why |
|---|---|---|
| Scheduler (`stream_windows`, state machine), YouTube Data API calls, operator console, notifications, `/stream` page, feed endpoint | **Existing Laravel app** | Reuses the data, cron, admin auth and web push |
| Streamer worker (Chromium → FFmpeg → local nginx-rtmp → TikTok + YouTube) | **New VPS, Docker** | Needs long-running processes and bandwidth |

**Control link (pull, not push):** the VPS worker polls `GET /api/stream/worker/commands` every few seconds,
using a bearer token shared between the two servers. It picks up "start window X with these destinations" and
"stop", and posts health back. This avoids exposing a port on the VPS, and avoids Laravel needing SSH access. The TikTok key is
saved with Laravel's `encrypted` cast (AES using `APP_KEY`). It's handed to the worker once over HTTPS, never
logged, masked in the console, and wiped when the window ends.

**Live feed to `/stream`:** a short-lived **SSE** endpoint. Each connection lasts about 25 s, checks a cached snapshot
every second and sends deltas, then closes. `EventSource` reconnects automatically. Only the worker's
Chromium and the operator preview connect, so tying up a PHP worker is fine. If the host buffers or kills SSE,
the page falls back to fetching `/api/stream/snapshot` every 2 s. Both update the page in place, never by
reloading it.

**Goal events for the stream:** reuse the alert pattern. Diff each match's score and its `match_events` goal rows
on every tick. The event id is the `match_events.football_data_id` (or `score:h-a` when there's no row yet). A
score that goes down, or a goal row that disappears, emits `goal_disallowed`. The page de-duplicates by event id.
These are the de-duplication tests the brief asks for.

## 3. Decisions for the owner

1. **VPS for the streamer.** It costs money, so I need your OK and a provider. Suggested: Hetzner CPX31 (4 vCPU / 8 GB,
   about €15/month) or a similar box from DigitalOcean or Vultr, in Europe (London/Frankfurt) for low latency to
   TikTok's EU ingest and our Nigerian audience. I can write the Dockerfile and test it locally against a local RTMP
   server before any VPS exists.
2. **Data licence on air.** The live scores come mostly from ESPN's unofficial feed. You accepted that for the website
   (AGENTS rule 7). Showing the same data on TikTok and YouTube is more public and makes the same terms risk
   bigger. Do you accept that for streams? Or should streams use only football-data.org data, which is slower
   (about 1 min) and has no scorers on the free tier?
3. **Competitions.** Which ones get streamed at first? I suggest just the Premier League (`STREAM_COMPETITIONS=PL`).
4. **Operator alerts.** Web push and the in-app inbox to admin accounts are already built. OK to use them,
   or do you want Telegram added? That needs a bot token.
5. **Badges.** Team names plus our own colour/monogram tiles (crest-free, per rule 6), or the army statues?
6. **Host overlay.** A camera or mic overlay on the VPS means the operator sends a second feed in (RTMP/SRT from
   a phone app). Should that be built in phase 3, or left until the TikTok account is LIVE-eligible?
7. **YouTube.** Someone needs to create a Google Cloud project with the YouTube Data API v3 enabled, an OAuth
   client and the channel's live streaming turned on. Who owns the channel's Google account?

## 4. Build order (once the decisions are made)

1. `/stream` page plus snapshot/SSE feed, goal diffing with de-duplication tests and `?test=1`. Laravel only, off by default
   (`STREAM_ENABLED`). Can be deployed safely.
2. `stream_windows` migration, scheduler command (`stream:plan` every 5 min) with a tested state machine
   (`scheduled → awaiting_key → live → ended`, plus `failed`/`skipped`), and per-destination state.
3. Worker Docker image (`streamer/`: Playwright Chromium, Xvfb, FFmpeg, nginx-rtmp, a small Node supervisor),
   tested against a local RTMP server.
4. Operator console `/admin/streams`, worker command/health endpoints, web-push notice 20 min before each window.
5. YouTube broadcasts (OAuth refresh token stored as a secret, broadcasts scheduled per window, automatic start and end).
6. Hardening: reconnect backoff (5/15/30 s), alerts for low bitrate or a dropped stream, a 2-hour soak test.
