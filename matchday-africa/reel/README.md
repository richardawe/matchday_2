# The Griot's 20-second reel

A Remotion project that turns a finished Premier League match into a 20-second vertical video:
title, one beat per goal or red card (with a talking-drum hit), the numbers, and the full-time verdict.
The soundtrack is synthesised in `audio.mjs`, so there is no music to license. Art is the crest-free
army cut-outs from `resources/armies` (built by `scripts/build-armies.mjs`).

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
