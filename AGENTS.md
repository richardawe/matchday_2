# Matchday Africa: agent guide

Read this first. It is the project memory for coding agents (Codex reads `AGENTS.md`; Claude Code
reads `CLAUDE.md`, which points here). Last updated 8 Nov 2026, after the social queue for the posting bot (social autopost off), Beat the Griot, the stream screen, YouTube LIVE
and Shorts, player photos, social media uploads (PRs #55–#66), and club and player names in the Griot's words with a
20-second commentary reel (see section 7 "Griot names and the commentary reel").

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
   `public/war/warriors/` (they show club crests) for anything automated. Second exception, the owner's choice on 6 Nov 2026: API-Football player photos
   (`players:photos`, saved in `public/generated/players/`) on the stream's goal and red-card screens, the goal and red-card
   chapter cards (`StoryCardRenderer`, so also the live X/Instagram posts) and the Griot timeline's goal/red-card posts; they may show kit crests. One exception, approved by the owner on
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
| 20-second reel (Remotion) | `reel/`, `app/Services/ReelService.php`, `reel:render` command, `ReelController` | `reel:render` 1m (one reel per run on the server, every waiting match at once on GitHub, `REEL_DELAY_MINUTES` after FT, 3 attempts per match; re-rendered once if the story changes within 30 min) | `REEL_RENDERER=off\|server\|github`, `REEL_OVERFLOW` |
| Bright v2 reel (comic panels, army moods, always 20 s, Griot timeline moments; opening frame shows club names and scorers) | `reel/src/ReelV2.jsx`, moods and the 20 s running order in `reel/src/timeline.js` (checked by `node reel/test-timeline.mjs`, run from `ReelTimelineTest`), `ReelService::timelineBeats()`, solid cut-outs `public/war/army/*-solid.webp` and poses `public/war/army/poses/` from `scripts/build-armies.mjs`, approved poses in `config/armies.php` `poses`; `node reel/preview.mjs` renders one still per scene | as above | `REEL_STYLE=v2` |
| Match alerts + inbox ("Alert me" on any match, accounts only; kick-off reminder, kick-off, goals, red cards, HT, FT, reel) and messages to users | `app/Services/Alerts/*` (`WebPush` = RFC 8291/8292 with OpenSSL only, `MatchAlerts`, `Alerts`), `AlertController`, `Admin/AnnouncementController` (`/admin/messages`), `public/sw.js`, `public/manifest.webmanifest`, `resources/js/alerts.js`, tables `notifications`, `push_subscriptions`, `match_alerts`, `match_alert_log`, `announcements` | `alerts:scan` 1m | `PUSH_ENABLED` + VAPID keys (`push:keys --write`); inbox needs the migration |
| Buffer → X, Instagram (live goal and red-card cards; reels go through growth_posts) | `app/Services/BufferPublisher.php`, `BufferClient.php` (ported from ngr.ltd), `buffer:publish`, `config/buffer.php`, table `social_posts` | `buffer:publish` 1m | `BUFFER_API_KEY` + `BUFFER_CHANNEL_*` |
| Social queue for the posting bot (the site's own social posting is off by default; a separate bot posts through Buffer) | `App\Support\SocialAutopost` (the one switch, checked in `BufferClient` mutations/`createPost`, `BufferPublisher`, `buffer:publish`, `TwitterService::postTweet`, the Twitter commands, `GrowthPostGenerator` queueing and redraw reposts, `ReelService::queue` repost flag, admin `/admin/twitter` queue), `App\Services\SocialQueue` (items `reel:<match_id>` for PL reels and `growth:<id>` for previews, fixtures, results, griot, banter and news; last 14 days, newest first; static mp4 under `public/generated/reels`, length from the mp4 header, YouTube Short, card image, caption without emoji or arrows, posted state per channel x/instagram/tiktok/youtube), admin `/admin/social-queue` (Mark posted / Undo), API `GET /api/social-queue`, `POST /api/social-queue/{id}/posted` (`routes/api.php`, bearer token middleware `SocialQueueToken`, 60/30 requests a minute per IP), table `social_queue_posts` | n/a (`buffer:publish` stays scheduled and prints "Social autopost is off; nothing sent.") | `SOCIAL_AUTOPOST_ENABLED` (default false), `SOCIAL_QUEUE_TOKEN` (+ migration) |
| Planned social posts (reels, news, fixture previews with a GD fixture card, morning fixtures, night results, "New look!" reposts of redrawn reels; X all, Instagram image/video, TikTok reels) | `GrowthPostGenerator.php` (`twitter:engage`), `FixtureCardRenderer.php` (route `matches.card`; `drawCartoon` in the cartoon look; always link it with `FixtureCardRenderer::url()`, which is versioned by look and result), table `growth_posts` (per channel), admin `/admin/twitter` | `twitter:engage` 10m plans; `buffer:publish` 1m sends when a channel's slot is free | `BUFFER_AUTO` + `BUFFER_CHANNEL_*` |
| Homepage top (both homes) | radio as a slim bar `partials/home/radio-bar` (plays in place via `resources/js/radio.js`), then live scores `partials/home/live-now` while a match is credibly live (refilled by the pulse JSON `live` key every 30 s), then the Watch live strip `partials/home/watch-live` (`App\Support\StreamLink`: the live YouTube matchday broadcast, public or unlisted and never a `test-` one, else `/stream`; hidden while `STREAM_PAGE` is off) | n/a | radio needs `RADIO_ENABLED` |
| Reels-first homepage | `resources/views/home-v2.blade.php`, `HomeShowcase::rolling()` (every reel of the last 7 days, newest match first; `rollingKey()`), `partials/home/reels-strip` (one row of reel cards, no headline panel; each card links to its match page; fixture cards 16:9 when there are no reels), `resources/js/home-reels.js` (the first reel fully on screen plays muted and hands over to the next when it ends, back to the newest after the last; Sound on/off; arrows on desktop). The pulse refresh (`/home/pulse?reels=<key>`) sends `reels_html` when a reel arrives or turns a week old, and `app.js` swaps the strip unless the viewer has the sound on, shared blocks in `resources/views/partials/home/`; arena at `/war/arena` (`ArenaService.php`) | n/a | `HOME_V2` (preview `/?home=v2`) |
| Signup attribution + `/admin/users` | `app/Support/Attribution.php` (first-touch `md_attr` cookie), `CaptureAttribution` middleware, `User::creating` hook in `AppServiceProvider`, `Admin/UserController.php` | n/a | needs `users.utm_*` migration |
| Social landing page `/start` (bio link; `/tiktok` and `/watch` 301 to it with the query kept): one primary action (open Beat the Griot round, else the next match with in-scope players, PL first, else email match alerts), today's African players (`App\Services\AfricanFocus`, shared with the home focus block), radio/slideshows/reels, signup; every link carries the visit's UTM tags (`StartPage::carry`). Bio URL: `https://matchday.africa/start?utm_source=tiktok&utm_medium=social&utm_campaign=bio` (swap the source per app). Reel pages also offer Beat the Griot or the next match call plus signup | `StartController`, `App\Services\StartPage`, `resources/views/start.blade.php`, `partials/signup-options` (`utmTags`) | n/a | always on |
| Guest predictions and match-page calls | `GuestPredictions.php` (`md_guest` cookie, claimed on `Login`), `MatchPredictionRecorder.php` (one system round per match), scoring `PredictionScoringService` (score 3/1, result 1; `predictions:score` 5m), record `/predictions/history`; `/predictions` and old round/league links redirect to Beat the Griot; `GET /auth/google` | `predictions:score` 5m | needs `guest_predictions` migration |
| Follow/share/WhatsApp | `config/social.php`, components `social-follow`, `share-bar` (`App\Support\ShareLink`, UTM `utm_medium=share`), `whatsapp-channel`; reel pages `/reels`, `/reels/{match}` (`ReelPageController`) | n/a | `WHATSAPP_CHANNEL_URL` (CTA hidden while empty) |
| SEO | `SocialMetaService.php` (titles, OG/X tags, JSON-LD SportsEvent/SportsTeam/NewsArticle/VideoObject), `@section('meta_title'/'meta_description'/'meta_robots')`, `SitemapController` (`/sitemap.xml`), `public/robots.txt` | n/a | always on |
| Stale live matches | `StaleMatchCloser.php`, `matches:close-stale` (FINISHED after kick-off + 3h with no update for 30 min, or + 6h); flagged `metadata.status_inferred_at`, so scoring and reels wait (`FootballMatch::statusInferred()`) | every 10 min | always on |
| Signup nudge, footer email | `partials/signup-nudge.blade.php` (localStorage `md_nudge`), `EmailSubscriptionController`, table `email_subscribers` (capture only: no emails are sent yet) | n/a | needs `email_subscribers` migration |
| Live Griot timeline (ESPN commentary → standard plays → picked moments → Griot captions in 4 voices, shot map, momentum, Kokoro Griot voice clips) | `app/Services/Espn/EspnCommentaryMapper.php`, `app/Services/Griot/*` (`MomentPicker`, `GriotTimeline`, `GriotTeller`, `GriotWriter`), `partials/match-timeline`, `radio/griot-voice.mjs`, `radio/griot-writer.mjs`, tables `match_plays`, `griot_posts`; training in `griot-ml/` (repo root, not deployed) | plays saved by `espn:sync details`; `griot:timeline` 1m, `griot:write` 1m, `griot:voice` 1m | `GRIOT_TIMELINE`, `GRIOT_VOICE`, `GRIOT_WRITER` (+ migration) |
| Cartoon look (whole site, admin, Matchday War) | `docs/CARTOON.md`; `config/site.php`, `App\Support\SiteStyle` + `SiteStylePreview` middleware (`?style=cartoon`, cookie `md_style`), `resources/css/cartoon.css` (+ generated `cartoon-auto.css`, `scripts/build-cartoon-css.py`), `resources/js/war/war-cartoon.css` + `art.ts`, `App\Support\ArmyMoods` (mood thumbnails `public/war/army/moods/`, `armies:colours`) | n/a | `SITE_STYLE=cartoon` (default classic) |
| Installable app (iPhone + Android home screen) | `public/manifest.webmanifest` (maskable icons, shortcuts), `public/sw.js` (registered on every page; push, notification taps, offline screen `public/offline.html` for page loads only, no other caching), `resources/js/install.js` (banner `partials/install-banner`: Install on Android, Share → Add to Home Screen on iPhone from the 2nd page view, then "Turn on match alerts" inside the app; localStorage `md_install`), page `/app` (`app-install.blade.php`), Android badge `public/icons/badge-96.png`, back button for the installed app (`components/app-back`, standalone only, never on home) | n/a | always on; alerts in the app need `PUSH_ENABLED` |
| Beat the Griot (each PL round the Griot picks 3 matches, calls the scores; fans who beat its points go into a draw, 10 win 1GB of data, credited by hand) | `app/Services/Griot/GriotChallengeService.php` (plan, remind, score, draw), `GriotForecaster.php` (Poisson from the last 10 results: likeliest result, then score), `ChallengeLines.php` (4 voices, facts only), `ChallengeCardRenderer.php` (square calls/verdict cards), `GriotChallengeController`, page `/beat-the-griot` (`/griot`), home strip `partials/home/griot-challenge`, match badge `partials/griot-match`, admin `/admin/griot` (winner codes, Mark credited; each round's fans with an account and how many calls they made, guests counted; in-app/push messages to a round's fans or to everyone who has played, also audience `griot` on `/admin/messages`), tables `griot_challenges`, `griot_challenge_matches`, `griot_challenge_entries`, `griot_challenge_winners`; fans' calls are ordinary `user_predictions`/`guest_predictions` (a call on the match page counts) | `griot:challenge` 10m; `twitter:engage` posts the calls card when a round opens and the verdict the next morning (type `griot`) | `GRIOT_CHALLENGE` (+ migration) |
| Banter cards (9:16 WhatsApp status cards at full time: winner in victory, loser in the rain, 4 voices) | `app/Services/BanterCardRenderer.php`, route `matches.banter`, `partials/banter-share` (match page, `resources/js/banter.js`: Web Share with the image on phones, wa.me text otherwise); the losing side's card of each Beat the Griot match goes to X (type `banter`) | n/a | `BANTER_CARDS` |
| Player photos (API-Football squads, one call per team, each squad refreshed weekly; also from match ratings after full time) | `App\Services\PlayerPhotos` (saves `public/generated/players/{id}.png`, adds players our squads lack), `App\Support\PlayerNames` ("M. Salah" = "Mohamed Salah"), `players:photos`, admin `/admin/player-photos` (`Admin\PlayerPhotoController`: photos per club, squad gallery, players with no photo or not matched to API-Football); `PlayerService` updates squads in place (no more delete-and-recreate, so photos and `/players/{id}` links survive); `ApiFootballEnrichmentService` spends two calls on ESPN-covered matches (line-ups, ratings + photos) | `players:photos` daily 04:45 | `API_FOOTBALL_KEY`, `API_FOOTBALL_PHOTO_LEAGUES` (default `PL:39`) |
| Matchday Live stream screen (`/stream`, 1080×1920 for YouTube LIVE; one live match: that match with army art and the Griot; two or more: the multi-match board, every score with scorers and red cards plus "As it happens", the newest moments across all matches from `StreamFeed::events()`; goals and red cards take the screen with drum calls; countdown between matches; `?test=1` fake match day with three live matches) | `StreamController`, `App\Services\Stream\StreamFeed` (`/stream/feed` snapshot, stable goal ids per side), `resources/views/stream.blade.php`, `resources/js/stream.js` + `resources/js/stream/*`, `resources/css/stream.css`, `config/stream.php`; plan and next phases in `docs/LIVE_STREAM_PLAN.md` | n/a | `STREAM_PAGE`, `STREAM_COMPETITIONS` |
| YouTube LIVE (connect the channel once; automatic broadcasts only while `YOUTUBE_MIN_MATCHES` (3) streamed matches are on at once, from 15 min before until the last of them ends; admin can still schedule any matchday; reusable stream key, auto-start when the picture arrives, ended after the last match; private 30-min test broadcasts) | `Admin\YouTubeController` (`/admin/youtube`, OAuth callback `/admin/youtube/callback`), `App\Services\YouTube\YouTubeClient` (OAuth, token refresh, liveStreams/liveBroadcasts), `YouTubeBroadcasts` (windows from `stream.competitions`, title/description), `youtube:broadcasts`, tables `youtube_accounts` (tokens and stream key encrypted with APP_KEY), `youtube_broadcasts`, `config/youtube.php` | `youtube:broadcasts` 10m | `YOUTUBE_CLIENT_ID/SECRET` (Server operations `configure-youtube`), `YOUTUBE_AUTO`, `YOUTUBE_PRIVACY` (+ migration) |
| Colour slideshows (1080×1920, five slides, ~16 s, music at −14 LUFS; previews, post-match, Team of the Week on Mondays, milestones, national teams) for African players and players of **confirmed** African descent, any league, Premier League first | `app/Services/Slideshow/*` (`SlideshowPicker` priority bands, `SlideshowFacts` DB-only facts, `SlideshowDeck` slides + captions, `SlideshowRenderer` GD with the safe-zone check, `SlideshowVideo` ffmpeg two-pass loudnorm, `CommonsPhotos` Wikidata→Commons licence-checked photos, `Slideshows` render/publish/approve), `App\Support\AfricanScope` (nationality or confirmed `player_heritages` row; "of X descent" only when confirmed), `App\Support\SocialLink` (UTM links), flags `resources/flags/*.png` (MIT flag-icons), `config/slideshow.php` (music credit record, club and nation colours), tables `slideshows`, `player_heritages`; pages `/slideshows/{id}`, strips on `/reels`, match and player pages (approved only); admin `/admin/slideshows` (approve, take off, regenerate); queue items `slideshow:<id>`; Shorts via `SlideshowUploads` (own columns, never `matches.youtube_*`) | `slideshow:generate daily` 05:40 London, `slideshow:generate post_match` 10m, `slideshow:render` 1m; uploads ride `youtube:uploads` after reels | `SLIDESHOW_ENABLED` (+ migration, music file); Shorts `YOUTUBE_UPLOADS`, `SLIDESHOW_YOUTUBE_DAILY` |
| Social media uploads (creatives for the social accounts) | `Admin\SocialMediaController`, `/admin/social-media` (MP4, MOV, JPG, PNG up to 200 MB on the public disk at `social/<yyyy-mm>/`, public URL + copy button, shows PHP upload limits); the Call out the Griot video is a static file at `public/social/griot/call_out_the_griot.mp4` | n/a | always on (admin only) |
| YouTube Shorts (every new reel uploaded as a public Short; title "Home h-a Away \| hook #Shorts", factual one-line summary, match link with UTM; at most `YOUTUBE_DAILY_UPLOADS` a quota day, the rest wait; resumable, 3 retries) | `App\Services\YouTube\ReelUploads` (queued from `ReelService::mark` when a reel becomes ready), job `UploadReelToYouTube`, `youtube:uploads`, `youtube:backfill {--days=7}`, admin `/admin/youtube/uploads` (status, Retry upload); columns `matches.youtube_*`; uses the channel connected at `/admin/youtube` (refresh token encrypted in `youtube_accounts`), refuses any channel but `YOUTUBE_CHANNEL_ID` | `youtube:uploads` 5m | `YOUTUBE_UPLOADS` (the deploy writes it from the GitHub variable, default true) + migration |
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
7. Cartoon look, reels at the final whistle and alerts: Server operations → `configure-release` (`reel_style` cartoon, `reels_now` on,
   `push` on). It migrates, creates VAPID keys once, sets `REEL_DELAY_MINUTES=1` and `REEL_OVERFLOW=github` when
   `REEL_GITHUB_TOKEN` is set. Preview the look first with `reel-render` on a past match.
8. Rollback any feature by switching its flag off and clearing caches.

**Server operations without a shell:** Actions → *Server operations* (`.github/workflows/server-ops.yml`,
`main` only, production environment, deploy key). `status` (release, migrations, Buffer settings with the key
masked, cron, post counts), `buffer-channels` (channel IDs), `configure-buffer` (writes `BUFFER_API_KEY` from
the production secret of that name plus channel IDs, `BUFFER_AUTO`, `BUFFER_SLOTS` into `.env` through
`matchday-africa/scripts/set-env.php`, which backs `.env` up first, then `config:cache`), `plan-posts` (plan and
send once), `clear-drafts` (delete unsent X drafts), `reel-check` (recent PL matches with events/chapters/reel state, finds Node 18+, `npm ci` + `node check.mjs`
in `reel/`), `configure-reel` (`REEL_RENDERER`, `REEL_NODE`, `REEL_GITHUB_TOKEN` from the production secret), `configure-reel-voice` (Kokoro commentary over new reels: `REEL_NARRATION` on/off),
`reel-render` (rebuild one match's chapters and render its reel now), `configure-release` (the look: `cartoon` sets `SITE_STYLE=cartoon` and `REEL_STYLE=v2`; instant Buffer reels; web push; see go-live step 7), `configure-espn` (`live`, `shadow` or `off`),
`espn-sync` (fixtures, live and details passes now, unmatched fixtures listed), `reel-queue` (backfill reels between two Lagos dates), `configure-home` (`HOME_V2` on or off), `configure-challenge` (Beat the Griot and banter cards together, `on` or `off` in the `home_v2` input: the workflow is at GitHub's 25-input limit; migrates, then runs `griot:challenge` once), `configure-stream` (`STREAM_PAGE` on or off in the `home_v2` input; on also runs `players:photos --teams=25` once), `configure-youtube` (copies the `YOUTUBE_CLIENT_ID`/`YOUTUBE_CLIENT_SECRET` production secrets, `YOUTUBE_AUTO` on/off in the `home_v2` input, migrates), `configure-growth` (`WHATSAPP_CHANNEL_URL`, `none` hides the CTA). From a terminal: `gh workflow run server-ops.yml -f action=status`.

## 6. Environment variables added in this round
See the block at the end of `matchday-africa/.env.example` / `production.env.example`.

| Variable | Default | Meaning |
|---|---|---|
| `ESPN_ENABLED` | false | Use ESPN's feed |
| `ESPN_SHADOW` | true in templates | Only record ESPN's view and log differences |
| `REEL_RENDERER` | off | `server` (this host runs Node + Remotion) or `github` |
| `REEL_NODE` | node | Path to the Node binary for the scheduler |
| `REEL_VOICE` | en | Caption voice in reels: en, pcm, sw, za |
| `REEL_NARRATION` / `REEL_NARRATION_VOICE` | false / bm_george | Kokoro commentary over new reels: one commentator's call from kick-off to the final whistle, written by `App\Services\ReelCommentary` (`radio/speak.mjs`, server renderer only; a failure renders without it) |
| `REEL_DELAY_MINUTES` | 3 | Wait after full time before rendering |
| `REEL_GITHUB_REPO` / `REEL_GITHUB_TOKEN` | this repo / empty | For the GitHub fallback |
| `SOCIAL_AUTOPOST_ENABLED` | false | The site posts to Buffer/X/Instagram/TikTok itself. Off: nothing is sent (listing Buffer channels still works), planned posts stay drafts, reels and YouTube are unaffected |
| `SOCIAL_QUEUE_TOKEN` | empty | Bearer token for `/api/social-queue` (the posting bot). Empty: the API answers 401 to everyone |
| `BUFFER_API_KEY` | empty | Buffer API key |
| `BUFFER_MODE` | shareNow | Or `addToQueue` to use the Buffer schedule |
| `BUFFER_CHANNEL_X` / `_INSTAGRAM` / `_TIKTOK` | empty | Buffer channel IDs |
| `BUFFER_AUTO` | false | Queue and send planned posts (otherwise drafts on `/admin/twitter`) |
| `BUFFER_REELS_NOW` / `BUFFER_REELS_PER_DAY` | false / 10 | Post a fresh reel (made in the last 6 h) on every channel at once, outside the slots and without using one |
| `REEL_STYLE` | classic | `v2`: bright comic reel told from the Griot timeline (falls back to chapters) |
| `REEL_OVERFLOW` | off | `github`: with the server renderer, reels finishing together beyond the first go to GitHub |
| `SITE_STYLE` | classic | `cartoon`: the bright comic look everywhere (preview per browser with `?style=cartoon`) |
| `PUSH_ENABLED` / `PUSH_VAPID_*` | false / empty | Web push for match alerts; `php artisan push:keys --write` creates the keys (changing them makes every browser subscribe again) |
| `BUFFER_SLOTS` / `BUFFER_PREVIEWS_PER_DAY` | 08:00,13:00,19:00 / 3 | Lagos times; each account posts at most once per slot (live cards and reels included), so three a day. Fixture previews planned a day |
| `WHATSAPP_CHANNEL_URL` | empty | WhatsApp channel invite link for the homepage/match CTA |
| `BUFFER_MONTHLY_REQUESTS` / `_SOFT_LIMIT` / `_HARD_LIMIT` | 3000 / 2500 / 2900 | Buffer API budget, counted in `buffer_usage`; posting stops at the hard limit |
| `GRIOT_TIMELINE` | false | Store ESPN commentary as plays and show the live Griot timeline on match pages |
| `GRIOT_VOICE` / `GRIOT_VOICE_BLEND` / `GRIOT_VOICE_SPEED` | false / bm_george:0.6,am_onyx:0.4 / 0.92 | Kokoro clips per post in the Griot's voice (blend of two Kokoro voices, lowered, warmed) over a talking-drum groove with drum calls (beats only, no crowd: owner's choice, 1 Nov) (`radio/griot-sound.mjs`); files in `public/generated/griot/`. Blend and speed also shape the radio's Griot voice |
| `RADIO_VOICE` | griot | Daily bulletin voice: `griot` (with groove and effects) or a Kokoro voice ID |
| `GRIOT_WRITER` / `GRIOT_WRITER_MODEL` | false / storage/app/griot/writer | Fine-tuned Qwen3-0.6B writer (transformers.js); every caption fact-checked, templates otherwise |
| `GRIOT_PICKER_MODEL` | storage/app/griot/picker.json | Learned moment picker; missing file = strict rules |
| `GRIOT_CHALLENGE` / `GRIOT_CHALLENGE_WINNERS` / `GRIOT_CHALLENGE_PRIZE` | false / 10 / 1GB of data | Beat the Griot; winners drawn each round, Nigeria numbers (MTN, Airtel, Glo, 9mobile), credited by hand from `/admin/griot` |
| `BANTER_CARDS` | false | Full-time banter cards on match pages |
| `STREAM_PAGE` / `STREAM_COMPETITIONS` | false / PL,eng.1 | The `/stream` screen for live streams; competitions by league short code or ESPN slug |
| `YOUTUBE_CLIENT_ID` / `_SECRET` / `YOUTUBE_AUTO` / `YOUTUBE_PRIVACY` / `YOUTUBE_MIN_MATCHES` | empty / empty / false / unlisted / 3 | YouTube LIVE: OAuth client, automatic broadcasts, their privacy, how many matches at once open one |
| `SLIDESHOW_ENABLED` / `SLIDESHOW_FFMPEG` / `SLIDESHOW_MUSIC_PATH` / `SLIDESHOW_MUSIC_SOURCE_URL` / `SLIDESHOW_COMMONS` / `SLIDESHOW_YOUTUBE_DAILY` | false / `RADIO_FFMPEG` / `music/hot-amapiano-beats.mp3` (under storage/app) / empty / true / 2 | Colour slideshows: generation switch, ffmpeg binary (needs libx264, aac, xfade, zoompan, loudnorm: `slideshow:render --check`), the private music file, where the track came from, Commons photo lookups, slideshow Shorts per quota day (shared with reels, which go first) |
| `YOUTUBE_CHANNEL_ID` / `YOUTUBE_UPLOADS` / `YOUTUBE_DAILY_UPLOADS` | Matchday Africa channel / false / 6 | YouTube Shorts: the only channel allowed, automatic reel uploads, uploads per quota day (1,600 of 10,000 units each). **Every deploy writes** `YOUTUBE_CLIENT_ID`/`_SECRET` (secrets), `YOUTUBE_CHANNEL_ID` and `YOUTUBE_UPLOADS` (repository variables; the channel defaults to `UCJ9Asmir1hBOHHQs9qSCkHg` in `config/youtube.php`, uploads to `true`) into the server `.env` (`scripts/ci/deploy-ssh.sh`, via `set-env.php`; blank secrets leave the server value alone) |

## 7. Open items and known issues
### State on 3 Nov 2026 (end of session)
- Live in production: `SITE_STYLE=cartoon`, `REEL_STYLE=v2`, `BUFFER_REELS_NOW=true`, `REEL_DELAY_MINUTES=1`, `PUSH_ENABLED=true`
  (VAPID keys set), `GRIOT_TIMELINE=true`, `REEL_RENDERER=server` with no `REEL_GITHUB_TOKEN` (owner is happy with
  server-only queueing). `.env` backup from that change: `.env.backup-20261103-111103`.
- Brand: one logo everywhere (`.matchday-wordmark` sun sticker + ink `.matchday-monogram` tile with a sun Anton "MD",
  same as `public/favicon.svg` and `public/icons/*`). Share image `public/images/social-default.png` = the Roman legion
  card (owner's pick), versioned `?v=20261103` in `SocialMetaService`/`Blog`. Branded error pages in `resources/views/errors/`
  (the 503 is the deploy maintenance page and skips Vite if the manifest is missing).
- Clubs outside the army gallery show their `logo_url` badge in mood slots on web pages only (`ArmyMoods`, `is-logo`);
  automated outputs stay crest-free (fixture cards use monogram tiles).
- Radio: the 12:00 Lagos edition on 3 Nov failed the number check ("two from nine" vs "2 from 9"); `RadioScript::numbersIn()`
  now accepts number words and 12-hour kick-off times. A missed edition is not re-run automatically.
- Reel backfill run on 3 Nov (20 Oct–3 Nov): 20 reels queued for redrawing; 10 from the latest round are reposted
  4 a day from 4 Nov 09:00 Lagos.
- Radio on 3 Nov: the 18:00 edition first failed the AI fact check because the prompt said "lead with the most
  important African news" and the writer said so on air; the prompt now forbids ranking or characterising stories
  (#49). Bulletin 44 was re-run with Server operations `radio-retry` and published at 18:28 Lagos.
- Statue cut-outs come from BiRefNet mattes committed in `public/war/downloads/rights-safe/masks/` (#48); the old
  brightness flood fill lost dark legs/faces and kept smoky blobs. Reel bursts sit above the other army, clear of the hero.
  `ReelService::V2_REVISION` (`v2r2`) marks the art revision; `reel:queue` redraws reels from older revisions.
- Reels: on 3 Nov evening all 20 reels from 20 Oct–3 Nov were re-queued for the v2r2 art; the latest round's 10 are
  reposted ("New look!") 4 a day from 4 Nov 09:00 Lagos.
- Home reels hero is a swipeable strip (#46). All PRs through #49 are merged and deployed.
- Branch protection requires PRs to be up to date with `main`: after each merge, update the next PR's branch and wait
  for its CI gate before merging. Don't deploy across 05:00/11:00/17:00 UTC (radio editions).
- Story chapter cards (`StoryCardRenderer`, also the live X/Instagram goal and red-card posts) follow the cartoon look since 6 Nov
  (`drawCartoonChapter`; `?look=cartoon` in the card URL and `-cartoon` in the cached file). The match chronicle
  endpoint (`/matches/{id}/chronicle`) is JSON, not a page.
- Local review tooling used on 3 Nov: a demo SQLite database plus Playwright scripts that screenshot every page at
  phone width and flag elements still in the classic serif/beige/Tailwind colours. Re-create them if needed; they are not in the repo.

### African player pages and the weekly roster (12 Nov 2026, prompt 2 of 4)
- In scope everywhere (index, player pages, roster, match pages, home focus, line-up enrichment, slideshows): an African
  nationality or a **confirmed** `player_heritages` row with a source (`App\Support\AfricanScope`). `/african-players/{id}` 404s
  for anyone else; "of {country} descent" only for confirmed rows; the source link shows to admins only.
- `App\Services\PlayerPages`: page/landing URLs (`PlayerPages::url($player, 'tiktok')` adds social UTM), this season's league
  numbers (goals/assists from events in the Premier League; starts and matchday squads from stored API-Football line-ups only
  when every finished league match of the club has one; a missing number is left out, a real zero shown), club fixtures, the
  next match to call. Pages: `/african-players/premier-league` and `/african-players/league/{code}` (roster grouped by club,
  as-of date, cached 6 h, `players:roster` rebuilds it every morning at 06:20 London), `/african-players/{id}/photo.jpg` (the
  credited Commons photo). Index/history/roster/player pages have their own titles (the old index set its title inside the
  content section, so the home title showed).
- Heritage seed: the owner's `players_roster.md` (checked 12 Nov 2026: 36 African internationals, 22 players of African descent,
  4 to verify) is converted to `database/seeders/data/african_heritage_epl.json` (59 rows; Konsa has two countries). A migration
  imports it once; `players:heritage-import` re-runs it every Monday 05:00 after the squad sync for newly synced players, and by
  hand. `to_verify` rows (Eze, Barry, Ampadu, Frimpong) go in pending, the rest confirmed; rows an editor confirmed (`confirmed_by`)
  or rejected are never touched. Matching: same name at the same club; else, for transfers, the only player in the database with
  the same full name (first initial + surname; a one-word namesake like "Nicolas" never matches). Against the 2025/26 dump in
  `db/` it matched 45 of 59; the rest are summer 2026 signings the squad sync will bring in.
- Heritage `kind`: `descent` ("of Nigerian descent", several countries joined: "of Congolese and Angolan descent") or
  `international` for a sourced senior African cap when the stored nationality is another country (Wan-Bissaka, Hadjam,
  Guessand): the page then names the country, never "descent".
- The owner's reference pack (prompts, roster, hand slideshow builder, music licence) lives untracked in `matchday/` at the
  repository root when supplied; it is excluded from git (`.git/info/exclude`) because it holds the MP3 and source photos.

### Colour slideshows (12 Nov 2026)
- Built from the owner's prompt pack (prompt 1 of 4: slideshows; then player pages, UTM/CTAs, admin review queue). Off until
  `SLIDESHOW_ENABLED=true`, the migration, and the licensed track at `storage/app/music/hot-amapiano-beats.mp3`
  ("Hot Amapiano Beats" by Alex Morgan, album Pop, Afro & Party Instrumentals, CC BY 4.0, from the Free Music Archive; owner's
  `music/LICENCES.md`, 12 Nov). The audio is never in the repository: every deploy runs `slideshow:music`, which downloads it from
  the FMA storage link in `config/slideshow.php` into `storage/app/music/` and checks its sha256; an admin can also upload a file at
  `/admin/slideshows`. Instagram, TikTok, YouTube and the slideshow page carry the full required credit line (licence and source
  links, "Trimmed, faded and loudness-normalised"); X carries the short credit to stay under 280. True peak is capped at −2 dBTP
  (the master peaks at −0.1 and AAC overshoots); measured −14.1 LUFS, −1.4 dBFS peak on a real render. The look is ported from the owner's hand
  pipeline (`matchday/slideshows/build_slideshows.py`, `make_video.sh`, supplied 12 Nov): steep diagonal split (home top left,
  away bottom right) with a white seam, secondary pinstripes and faint rays; a centred stack of a date pill with flags (kit
  swatches for clubs), big flags or club colour tiles with name tags, a round portrait in the side's colours, cream panels with
  team-coloured borders and table highlights, the site's crest-free match card on the last slide, the matchday.africa pill;
  Anton + Asap Condensed (OFL, `resources/fonts/`). Running order: 01 date/sides/player/kick-off (or scorers), players, table,
  form, question + card; slides without data are dropped. The render fails unless the whole content box is inside y 150–1540 and
  the 800 px column, side margins within 20 px and the centre within 25 px of y 845 (as the hand script checks). Ken Burns on every
  slide from 1.0, capped by the content height (≤ 1.04) and anchored on the column's right edge (x 940) so it grows left; the hand
  script anchors x 140, which pushes content into the right-hand 140 px, so that part was deliberately not copied. Photo crops are
  head-and-shoulders squares (`CommonsPhotos::crop`). Nation colours follow the hand `teams.json`.
- Every slideshow needs an editor's approval (`/admin/slideshows`) before public pages, `/api/social-queue` or YouTube see it;
  a re-render needs approving again. The full review queue (caption edits, reject reasons, heritage confirmation UI) is prompt 4.
- `player_heritages` exists now (pending/confirmed/rejected + source URL); only confirmed rows with a source count. Seeding from
  the roster and the admin UI are prompts 2 and 4. Player pages still 404 for descent-only players until prompt 2, so their
  milestone decks link to the match page.
- Season goal totals come from match events and are only shown for the Premier League (every match synced); other leagues show
  match-level facts only.
- Slide 01 photos: Wikidata image of the one footballer with that name (and our date of birth when known) → Commons licence
  check (CC0, PD, CC BY, CC BY-SA). Pin a file by hand with `php artisan slideshow:photo {player} "File:Name.jpg"`.
- ffmpeg: the server's ffmpeg may be the reel renderer's small Remotion build (no xfade, zoompan or afade). `SlideshowVideo`
  falls back to that binary when `SLIDESHOW_FFMPEG`/`RADIO_FFMPEG` doesn't run, and in `auto` mode draws every frame itself (zoom and
  crossfades, GD bilinear, JPEG frames streamed into ffmpeg's image2pipe, nothing on disk; ~31 s a deck on 4 cores, against ~17 s
  with the filters) when xfade/zoompan are missing. Audio fades are `volume` expressions in both modes. Checks: `/admin/slideshows`, `php artisan slideshow:render --check`,
  every deploy log, Server operations `slideshow-check`; switch with `configure-slideshows` (home_v2 on/off; migrates, checks,
  looks up the week's photos).
- `slideshow:photos` (05:10 London) looks up slide photos for in-scope players at clubs playing in the next week; editors pin a
  Commons file or link per featured player on `/admin/slideshows` (licence still checked).
- Nationality codes: the player sync wrote TAN, GAM and three-letter fallbacks (LIB, SUD, MAL …) the African list never matched.
  `PlayerService::AFRICAN_NAMES` maps them, `DiscoveryController::AFRICA` also lists TAN, GAM, NIG and STP, and a migration
  re-codes stored rows by nationality name (Malta's MAL is left alone).
- `ReelUploads` now delegates the resumable upload and quota to `App\Services\YouTube\ShortsUpload` (same cache keys).

### Social posting moved to a bot (8 Nov 2026)
- Owner's decision: the site no longer posts to social media by itself. Reels are still rendered, served from
  `public/generated/reels/<id>/<id>.mp4` and uploaded as YouTube Shorts; YouTube LIVE is unchanged. A separate bot
  posts through Buffer from `GET /api/social-queue` and reports back with `POST /api/social-queue/{id}/posted`
  (`{channel, external_post_id, url}`); admins can mark posts by hand at `/admin/social-queue`.
- Production: **every deploy writes** `SOCIAL_AUTOPOST_ENABLED` (repository variable, default `false`) and
  `SOCIAL_QUEUE_TOKEN` (production secret; blank leaves the server value alone; at least 32 of `A-Za-z0-9_-`) into the
  server `.env` (`scripts/ci/deploy-ssh.sh`), and the deploy migrates and rebuilds the config cache. Make the token with
  `php -r "echo bin2hex(random_bytes(32));"`, store it as the GitHub secret `SOCIAL_QUEUE_TOKEN` and in the bot's own
  secure setting, then redeploy. By hand instead: set both in `.env`, `php artisan migrate --force`, `php artisan config:clear`
  (and `config:cache`). Keep the token private: never paste it in chat, tickets, commits or screenshots.
- Turning `SOCIAL_AUTOPOST_ENABLED=true` brings back the old Buffer posting (with `BUFFER_AUTO` etc.). Don't run both
  that and the bot, or posts go out twice.

### Griot names and the commentary reel (8 Nov 2026)
- Owner (8 Nov): YouTube posts work. "The army names are not resonating": the Griot's words now use **club and player names**
  and say the event outright ("GOAL! Bukayo Saka puts Arsenal in front" / "Arsenal ahead in the 63rd minute. Arsenal 2–1
  Chelsea."). Changed: chapter captions (`Griot.php`, so story cards, live X/Instagram posts and reels), timeline captions
  (`GriotTeller.php`, so match pages, the stream and Griot voice clips), writer inputs (`GriotWriter`, keys still `army`/`opp_army`
  for the training data, values are club names), reel title and stats screens, stream labels. Club names come from
  `App\Support\ClubName` (`short` on captions, `full` in scores, `spoken` for commentary). Army art stays everywhere; army
  names stay on /war, the arena and the Griot challenge page. Pidgin, Sheng and Mzansi lines were rewritten too and still need a
  native speaker's review.
- `Griot::at()` gives "in the 41st minute" / "in stoppage time (90+3)"; `Griot::joined()` joins headline and line for posts.
- The v2 reel is **always 20 seconds** (2 s opening, 14 s of moments shared by weight, numbers, final whistle; a quiet match's
  spare time goes to the numbers and the final whistle). Busy matches drop pressure, injuries, kick-off, chances and half-time
  first, then early goals. `ReelService::V2_REVISION` is `v2r3`: run Server operations → `reel-queue` to redraw older reels.
- The reel commentary (`REEL_NARRATION`) is one continuous call: "Arsenal, Chelsea. Here we go!" → "Goal, Saka! It's one nil to
  Arsenal." → … → "Full time! Arsenal win it, two one!" (`ReelCommentary`; speak.mjs picks the longest wording that fits each
  scene and logs it as `text`). Check that `REEL_NARRATION=true` on the server (Server operations → `configure-reel-voice`).
- Player photos: the files were downloading and serving fine (e.g. `/generated/players/6850.png`), but only the African
  players pages and the stream showed them. PR #64 (photos on goal and red-card cards and in the timeline, `/admin/player-photos`)
  was merged into this branch. Photo coverage is still low (1 of 24 players on `/african-players`): `players:photos` covers
  the Premier League only (`API_FOOTBALL_PHOTO_LEAGUES`) and is held back by API-Football's free-plan rate limit; check
  `/admin/player-photos` for clubs not linked or players not matched.
- Homepage and menu trimmed (owner, 8 Nov: "too many links, no link to the stream"): menu = Matches, Reels, Watch live,
  Beat the Griot, War, Listen + More (players, teams, leagues, articles, community, shop, premium); the reels home lost the
  social block, the matchweek board, the leagues list, the arena line and the duplicate match links under African players;
  the "Today" block shows next and results only (live scores lead the page); Play = Watch live, Beat the Griot, War;
  footer quick links cut to seven, social handles listed once. "Watch live" sits in the menu, under the live scores, in
  Play and in the footer.
- Local Kokoro runs need `NODE_USE_ENV_PROXY=1 NODE_EXTRA_CA_CERTS=/root/.ccr/ca-bundle.crt` in the cloud container (Node's
  fetch ignores the proxy otherwise). Remotion here: `REEL_BROWSER=/opt/pw-browsers/chromium-1243/chrome-linux64/chrome`.

### YouTube Shorts (7 Nov 2026)
- Google Cloud project "Matchday YouTube Uploader" (`matchday-youtube-uploader`), OAuth web client "Matchday site", app In production
  (unverified). Channel `UCJ9Asmir1hBOHHQs9qSCkHg`. Connect once at `/admin/youtube/connect` (same connection as YouTube LIVE).
- Videos uploaded through an API project that has not passed YouTube's API compliance audit are locked to private by YouTube;
  the admin page flags such uploads. Fix: the audit form for the project, not code.
- 12 reels uploaded by hand before this (matches 4877–5085) are recorded by the migration so they are never uploaded again.
- Built on the existing `YouTubeClient` (Laravel HTTP) rather than google/apiclient, which would add a very large dependency for two calls.

### Beat the Griot and banter cards (4 Nov 2026)
- Old prediction engine removed on 4 Nov (owner's decision): hand-made admin rounds, prediction seasons, goalscorer and
  total-goals types, per-match sponsor prizes, the leaderboard page and table writes, prediction leagues and invites, and the
  debug scoring commands. Their tables (`prediction_seasons`, `match_sponsors`, `match_prize_awards`, `prediction_leaderboards`,
  `prediction_groups`, `prediction_group_members`) are left in the database untouched. Kept: past predictions and points,
  `/predictions/history`, match-page calls, guest predictions, `predictions:score`. Ad slots (`SponsorPlacement`) are separate and stay.
- Owner's choices (4 Nov): three matches per round; prize 1GB of data; 10 fans drawn at random from those who beat the
  Griot; winners message one of Matchday's social accounts with their code, number and network and an admin credits them by
  hand (no payout API); Nigeria only for now (Kenya and South Africa later). Switched on in production on 4 Nov at 12:53 UTC
  (`configure-challenge` on; `.env` backup `.env.backup-20261104-125320`); first round Fri 6 – Sun 8 Nov.
- The Griot's calls come from the site's own results only; its lines quote form only when five results back the call.
  Pidgin, Sheng and Mzansi lines (`ChallengeLines`, `BanterCardRenderer`) need a native speaker's review.
- Mockup approved by the owner: https://claude.ai/artifact/9zNsmk7vXbvdQ7xWSYY13q

### Redrawn reels (3 Nov 2026)
- `reel:queue` (Server operations → `reel-queue`) redraws reels made in an earlier look (`metadata.reel.style`).
  Owner's choice: only the latest round's redrawn reels go out on social again ("New look!" posts, content key
  `reel:{id}:redraw`), four a day at 09:00/12:00/15:00/18:00 Lagos, newest first (`GrowthPostGenerator::redraws`).

### Cartoon look (3 Nov 2026)
- Owner approved the mockups and asked for the whole site, admin and /war in the cartoon style. Built behind `SITE_STYLE`
  (default classic); preview with `?style=cartoon`. Plan and state in `matchday-africa/docs/CARTOON.md`.
- In the cartoon look /war uses the crest-free statues (`resources/js/war/art.ts`); the classic /war still shows
  `public/war/warriors/` portraits, which carry club crests.
- The generated overrides follow app.css: rerun `python3 scripts/build-cartoon-css.py` after editing app.css or the war styles.

### Reel v2, instant reels and match alerts (3 Nov 2026)
- Owner's choices (3 Nov): keep the statue style with bright comic framing; try drawn mood treatments first (no new
  art yet); new pose art (victory, dejected, defeated per army) will be generated without crests, sponsors or maker
  logos. The City, Arsenal and United poses the owner shared carry crests and must not be used. Each pose file
  goes in `public/war/downloads/rights-safe/poses/<art>-<mood>.png`, is checked by a person, then listed in
  `config/armies.php` `poses` (approval date in a comment) and built with `node scripts/build-armies.mjs`.
- Solid cut-outs flood-fill from the edges; statues shot against lit, smoky backdrops need a per-statue level
  (`SOLID_LEVELS` in `build-armies.mjs`). Venetian Marine still loses part of its cloak; new art on plain black fixes it.
- v2 renders ~83 s for a 32 s reel on 4 cores, about 8-9 MB (CRF 30). A turning sunburst tripled the size: keep backgrounds still.
- Owner's choices for alerts (3 Nov): in-app inbox + web push only (no email/WhatsApp yet); "Alert me" on any match;
  accounts required (guests are told so). Users don't message each other. Email of alerts is not built.
- iPhones get web push only after "Add to Home Screen" (manifest added); the inbox explains this.

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
  synthesised talking-drum groove, beats only, ducked to a quarter under the voice (`radio/griot-sound.mjs`, shared with
  the live timeline clips). The server's FFmpeg is the reel renderer's small build: do the audio processing in JS and use FFmpeg only for loudnorm + MP3.
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
