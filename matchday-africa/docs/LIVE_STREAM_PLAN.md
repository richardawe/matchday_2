# Matchday Live: YouTube live streams and TikTok goal posts

Status (6 Nov 2026): **phase 1 built, switched off** (`STREAM_PAGE=false`). This replaces the first
discovery note, which proposed a VPS, Docker and a stream-key console. The owner asked for something
simpler, so that design is dropped.

## What the owner chose (6 Nov 2026)
- **YouTube: a fully automatic LIVE.** No tap from anyone. It shows the stream screen below.
- **TikTok: automatic goal posts, not LIVE.** TikTok only lets a person start a LIVE (in the app or
  LIVE Studio), and a bot that logs in or presses Go LIVE breaks its terms and risks the account. So
  TikTok gets short vertical goal videos posted through Buffer. Anyone can still go LIVE by hand on a
  big day and show the same screen.
- **Army art plus the API-Football player photo** (owner's choice, 6 Nov 2026). Goals and cards show
  the scoring side's army with the player's photo in a round badge. The photos are small (about 150 px)
  and come from API-Football, which doesn't license them for reuse, so check its terms before the first
  public stream. Bigger, licensed cut-outs (Getty, PA, Shutterstock Editorial) can replace them later.
- Mockup approved: https://claude.ai/artifact/H4LXF5BY7eGKqKAQvQeRni

## Phase 1, built: the stream screen `/stream`
- A fixed 1080×1920 page in the cartoon look, scaled to fit any window. Code: `StreamController`,
  `App\Services\Stream\StreamFeed`, `resources/views/stream.blade.php`, `resources/js/stream.js`,
  `resources/js/stream/*`, `resources/css/stream.css`, `config/stream.php`. Tests: `StreamPageTest`.
- `/stream/feed` returns one JSON snapshot: live matches, next kick-off, later fixtures, results, and
  the newest reel. It is cached for 1 second. The page asks for it every 3 s (`STREAM_POLL_SECONDS`)
  and changes in place, so the stream never shows a reload.
- The screens:
  - **During play:** scores, both armies, the Griot's latest line, possession and shots, and Beat
    the Griot when the match is in the round. Live matches take turns every 20 s, and a match that
    just scored stays on screen for 60 s.
  - **Goal and red card:** take the screen for 8 s with a drum call.
  - **Goal ruled out:** a short banner.
  - **"Updating…":** shown when the scores stop moving for 90 s.
  - **Between matches:** a countdown to the next kick-off (Lagos time), the latest full-time reel
    (or the last winner's army), later fixtures and results.
- **Goal ids** are numbered per side (`<match>:home:2`), so the screen announces a goal as soon as
  the score changes, even before the feed names the scorer. It fills in the name when the name
  arrives. A goal ruled out drops the score, so its id disappears. A restored goal isn't announced
  again (the same rule as the match alerts).
- **Sound:** talking-drum calls made in the browser with Web Audio. There's no music or samples,
  so nothing copyrighted reaches the stream. When `GRIOT_VOICE` has made a clip for the Griot's goal
  or red-card line, the screen plays it. A browser needs one tap first ("Tap to turn on sound").
  OBS and other capture tools don't.
- **Test mode:** `/stream?test=1` plays a made-up match day in 150 s. Add `&at=120` to start part
  way through: goal at 12 s, red card at 30 s, goal ruled out at 84 s, between matches at 120 s.
- **Speed:** a goal is on screen within about 3 s of reaching our database. The ESPN live pass runs
  about every 20 s, so viewers see goals roughly 20–40 s after they happen.

## Next phases
2. **YouTube, automatic.** Authorise the channel once (OAuth; Google Cloud project with the YouTube
   Data API v3, and live streaming switched on in YouTube Studio). Then, before each matchday,
   create and start a broadcast and end it after the last match. The stream screen still has to be
   *sent* to YouTube by something that renders a web page. Options to decide: a computer left on
   with OBS (a browser source on `/stream`, which starts streaming by itself), or a small cloud
   machine. The cPanel host can't run a browser or FFmpeg for hours.
3. **TikTok goal posts.** Render a 10 s vertical goal video per goal (Remotion, like the reels, with
   the TikTok design from the mockup) and send it through `buffer:publish` to `BUFFER_CHANNEL_TIKTOK`.
   Check Buffer's and TikTok's daily post limits first. A busy Saturday may mean only the biggest
   matches.
4. **Licensed player photos** (see above) if the API-Football ones aren't good enough or allowed.

## Owner's answers (6 Nov 2026, evening)
- YouTube: a "Connect YouTube" button in admin (OAuth once), then automatic broadcasts. Still to settle
  what renders and sends the picture (a computer with OBS, or a small cloud machine).
- TikTok goal posts: as many as the platforms allow.

## Switching it on
1. Deploy, then Server operations → `configure-stream` with the on/off switch (`home_v2`) on. That sets
   `STREAM_PAGE=true` and fills the Premier League player photos once (`players:photos --teams=25`).
2. Open `https://matchday.africa/stream?test=1` and watch a full loop.
3. Open `https://matchday.africa/stream` on a matchday.
