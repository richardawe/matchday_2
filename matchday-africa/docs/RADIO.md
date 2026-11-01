# Matchday Radio

Three editions daily: **06:00, 12:00 and 18:00 Africa/Lagos**. No midnight edition.
Radio appears before the reels, on both homepage versions. `/listen` holds the latest
edition and the last 30 editions; `/listen/feed.xml` exposes the last 50 playable editions.
The AI/Kokoro disclosure lives inside **Transcript & sources**, not the player headline
or the spoken introduction. Every episode has a stable URL and RSS GUID.

## Enable a private pilot

1. Deploy the branch after tests; run migrations. All radio behaviour defaults to off.
2. Provide Node **22.13+**, npm and an FFmpeg build with **libmp3lame**. Configure
   `RADIO_NODE` and `RADIO_FFMPEG` with absolute paths if they are not on cPanel's cron PATH.
   Do not install or run speech synthesis in the web request process.
3. In `radio/`, run `npm ci --omit=dev --no-audit --no-fund`. Future deployments install
   locked radio dependencies when `RADIO_ENABLED=true`. First inference downloads the
   Kokoro q8 model from Hugging Face. The model cache is kept in `storage/app/tts-cache`,
   outside `node_modules`. Set `RADIO_TTS_CACHE` to override it.
4. `php artisan radio:check` checks the runtime; `php artisan radio:check --sample`
   generates `storage/app/radio/audition.mp3`. Audition it, including pronunciation.
   The voice defaults to **griot** (owner's choice, 31 Oct 2026): two stock Kokoro voices blended
   (`GRIOT_VOICE_BLEND`), lowered and warmed, over a synthesised talking-drum groove that ducks while he
   speaks, with a drum call to open, a drum accent on each story and a drum roll to close (beats only: the owner
   asked on 1 Nov 2026 for no crowd effects and for the beat never to drown the voice)
   (`radio/griot-sound.mjs`; no recorded music, so nothing to license). It is not presented as an African
   accent. `RADIO_VOICE=bm_george` (or Actions → Server operations → `configure-radio-voice`) goes back to
   the plain voice. Voice replacement is isolated in `RadioAudio`.
5. Confirm the existing OpenRouter key/model is working. Set `RADIO_ENABLED=true`,
   leave `RADIO_AUTO_PUBLISH=false`, then `php artisan config:cache`.
6. `/admin/radio` → Queue current edition. The existing minute cron runs `radio:work`
   in the background; for a local pilot, call it manually once per stage. It writes a
   private draft. Review and optionally edit paragraphs; edits pass the factual checks
   again. **Approve script & generate audio**, listen to the private preview, then
   **Publish checked audio**. The public homepage shows only published audio.

Radio must share the persistent cache store between cron and web workers. Laravel's
`radio-pipeline` lock serialises workers and admin mutations. Do not use an in-memory
array cache in production. There is no new queue daemon requirement.

Server operations in GitHub Actions also supports `radio-check` (find Node/FFmpeg
and install the locked runtime), `configure-radio` (`draft`, `auto`, or `off`),
`radio-run` (queue and write the current edition), `radio-refresh` (a new recording for the current slot without overwriting published audio), `radio-status` (script and facts),
`radio-voice` (reviewed bulletin ID), `radio-publish` (checked audio ID), and
`radio-retry` (explicit retry of a failed bulletin ID).
The deployment key stays in the production environment. Configuration updates only
the radio keys through the backed-up `.env` editor. `radio-check` also looks for the
MP3-capable FFmpeg bundled with the existing server reel renderer.

## Sources and accuracy

- By default, **no third-party news source is approved for radio**. Editions can use
  the existing match database's recent results and upcoming fixtures. After checking
  reuse rights, add comma-separated source names from `config/news.php` to
  `RADIO_NEWS_SOURCES`. Adding a feed to the articles configuration does not approve
  it for radio automatically. BBC/Guardian permission is not implied by RSS access.
- Radio calls the existing RSS discovery function with the approved subset. It does
  not publish articles, fetch full article bodies or use publisher images. Article
  daily limits and article publication status are independent of radio coverage.
- News is restricted to the previous 24 hours; future-dated items are excluded.
  African player names (from the player database), CAF/AFCON and the existing Africa
  keywords influence ranking. Duplicate titles are clustered, and news covered in a
  published edition in the last three days is excluded. Coverage starts on publication.
- Facts are frozen with source URL, source time and each script paragraph's supporting
  fact keys. UTC match dates are reinterpreted before Lagos time is spoken. Generated
  previews and automated article prose are never source facts.
- The writer may only use those facts. Checks reject unknown fact IDs, unsupported
  digits, unlabelled rumours, missing spoken attribution, copied 12-word source runs,
  HTML and oversized scripts. A separate model request checks claims, names, scores
  and uncertainty against the cited facts. Failed or malformed review blocks the job.
  This reduces errors; it is not proof of correctness. Human review is the launch default.
  Rejected candidate scripts and their source snapshots remain private in the desk
  for diagnosis. A fenced JSON response is parsed, but approval still requires the
  exact boolean `true`; rejected, empty or malformed checks never approve publication.
- Aim for 400–650 words only when facts support it. Sparse editions are shorter. Do
  not pad quiet news days with invented context. Facts older than six hours cannot
  proceed to speech or publication; the displayed recording time remains visible.

## Audio and publishing

Kokoro runs on CPU with q8 weights and short chunks. The renderer synthesises an
original 1.2-second ident, adds pauses, normalises loudness and encodes **24 kbps mono
MP3**, then decodes the finished file to check it. Three to five minutes uses roughly
0.54–0.90 MB. A duration above five minutes is rejected rather than silently truncated.

Each render has a unique filename under **private** `storage/app/radio/{id}/`.
Do not expose this directory with a public symlink. The admin audio route requires
admin authentication; public routes require the published state and a matching file
length. Symfony file responses support byte ranges. Audio never autoplays or preloads;
native controls still work without JavaScript. The player offers speed and download.

When a bulletin is playing, other media are paused. Navigating to another Laravel
page ends playback; continuous playback across navigation is outside this release.

## Automatic operation and recovery

After the pilot, set `RADIO_AUTO_PUBLISH=true` and rebuild the config cache. This
applies to newly generated editions; existing drafts still need review. One edition
per Lagos date/slot is enforced by a unique database key. `radio:edition` is safe to
repeat; `radio:work` does either script or speech work per run. Normal publication
therefore follows the time slot by a few minutes, not exactly at the minute boundary.

Failures leave the last playable edition untouched. Each stage has three attempts,
five minutes apart; a stopped worker is recovered after 30 minutes. Pending editions
expire after six hours. The desk shows the failure; an admin may explicitly retry.
A delay notice appears 20 minutes after the next scheduled edition is overdue; the
evening edition remains current overnight. No incomplete audio,
failed factual review or private draft enters the RSS feed. A newer edition cannot
be replaced by an older slot. The last playable file is selected if a newer file is missing.

**Hold edition** stops a draft. **Withdraw edition** removes a published episode
from the public routes and feed while preserving its files and audit data. It cannot
recall copies a podcast app or listener already downloaded. Published audio is never
overwritten. Set `RADIO_ENABLED=false` and rebuild config to pause all radio work
and hide public access. Never delete `storage/` or its model cache on deployment.

## Checks

```sh
npm run build
php vendor/bin/phpunit
cd radio
npm ci
npm test
node render.mjs --check
```

The Node tests exercise chunk continuity and actual FFmpeg MP3 encoding/decoding
without downloading a model. The optional Kokoro audition proves inference on the
target host. Monitor the first day's script lengths, source balance, pronunciation,
render time, failures and OpenRouter usage before leaving automatic publishing on.

## Player and editorial update — 21 October 2026

The compact player shows Listen and Share. Native sharing opens the phone share sheet; other browsers copy the episode link. Without JavaScript, native audio controls remain. Transcript, AI information and sources sit under About this bulletin on episode pages; archives and RSS have no prominent links on the player.

The owner explicitly instructed use of BBC Sport and Guardian feeds with attribution on 21 October; this instruction is not evidence of a publisher licence. Configure names through Server operations configure-radio, radio_sources: BBC Sport,The Guardian Football. Terms at /terms credit the publishers without claiming permission. Each news story has a spoken source credit and an original source link. Scripts lead with news and group a maximum of three results, then up to three upcoming fixtures; repeated fact IDs and repeated match-centre credits are rejected.

Story images appear after playback begins. New renders save audio_cues measured from actual Kokoro samples, including ident and pauses. The player follows currentTime after seeking; old audio has no guessed cues. Club artwork comes from rights-safe army assets; other stories use the original football illustration, with no publisher photos. The audio_cues migration is required before rendering new audio.
