# The Griot's 20-second reel

A Remotion project that turns a finished Premier League match into a 20-second vertical video:
the final score and the key moment (both fully drawn on the first frame, because viewers decide in
about two seconds), one beat per goal or red card (with a talking-drum hit), the numbers, and the
full-time verdict. The poster is a still from the opening.
The soundtrack is synthesised in `audio.mjs`, so there is no music to license. Art is the crest-free
army cut-outs from `resources/armies` (built by `scripts/build-armies.mjs`).

## Style v2: the bright comic reel (`REEL_STYLE=v2`)

`src/ReelV2.jsx` tells the same match as comic panels: club-coloured skies with a sunburst and
halftone, a sound-effect burst per moment (GOAL!, CLANG!, SO CLOSE!, OFF!, NO GOAL!), speech-box
captions and a scoreboard that always shows the score at that moment. Each army has a mood per
scene (`moods()` in `src/timeline.js`): warrior at kick-off, victory for a goal or a win, dejected
after conceding, hitting the woodwork, missing a big chance, a red card or a goal ruled out, and
defeated at full time. With `GRIOT_TIMELINE` on, the moments come from the live Griot timeline;
otherwise from the chapters. Length follows the story, 20 to 45 seconds.

Art: the solid cut-outs (`public/war/army/<art>-solid.webp`, ink line baked in) and, per mood,
an approved pose (`public/war/army/poses/<art>-<mood>.webp`, listed in `config/armies.php`).
Without a pose the statue is posed by treatment (a victory leaps and glows, a dejected army sags
grey in the rain, a defeated one topples on cracked ground).

Preview without a full render (one still per scene):
`REEL_BROWSER=/path/to/chrome node preview.mjs --home roman-legion --away ottoman-janissary`

## Turning it on

1. On the server, in the app folder: `cd reel && npm ci && node check.mjs`
   - **PASS** → in `.env` set `REEL_RENDERER=server` (and `REEL_NODE=/path/to/node` if `node`
     isn't on the cron PATH; cPanel's Node app shows the path).
   - **FAIL** → set `REEL_RENDERER=github` and `REEL_GITHUB_TOKEN` (a fine-grained token for this
     repo with *Actions: read and write*). `.github/workflows/render-reel.yml` renders and uploads
     the MP4 by FTP using the existing cPanel secrets.
2. `php artisan optimize:clear`

The scheduler's `reel:render` job then makes a reel about 3 minutes after each full time
(`REEL_DELAY_MINUTES`). Re-render one by hand with `php artisan reel:render <match id>`.

## Local preview

`node render.mjs --props src/sample.json --out out/sample.mp4` (add `art_path` to both sides first).
