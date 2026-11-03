# The cartoon look

The owner approved the cartoon mockups on 3 Nov 2026 (homepage, match page, predictions, stories, reels,
radio, league table, team page, sign-up, admin, and a rethought Matchday War). This is how the site gets
there without a rewrite, and how to switch it on.

## Plan

| Step | What | State |
|---|---|---|
| 1 | Switch: `SITE_STYLE=classic\|cartoon` (default classic); anyone previews with `?style=cartoon` (`?style=classic` to go back), kept 30 days in the `md_style` cookie | Done |
| 2 | Kit: paper ground with halftone, ink outlines and hard shadows, Anton / IBM Plex Sans / Plex Mono (served with the site as WOFF2, 129 KB), sun, live red, sky blue, club colours | Done (`resources/css/cartoon.css`) |
| 3 | Every page: the dark surfaces and light text `app.css` hard-codes are flipped by generated overrides; components are hand-styled on top | Done (`scripts/build-cartoon-css.py` -> `cartoon-auto.css`) |
| 4 | Army moods on the site: Today cards, Griot timeline, league table (mood after the last game) | Done (`ArmyMoods`, `war/army/moods/`) |
| 5 | Matchday War rethought: the face-off opens the page under the clubs' skies, then the game, the armies as trading cards ("Arsenal – The Roman Legion"), the Myth Grammar as panels, the film last; crest-free statues | Done (`resources/js/war/war-cartoon.css`, `art.ts`) |
| 6 | Admin: same ink, calmer (thinner lines, white panels, no bursts) | Done |
| 7 | Arena (`/war/arena`): club skies, solid statues, score and title in speech boxes | Done |
| 8 | Later: club names in the Griot's live captions; army images in phone alerts; "Alert me all season" on team pages | Not built |

## Switching it on

1. Deploy (the look stays classic).
2. Preview on the live site: open `https://matchday.africa/?style=cartoon`, then browse normally.
3. For everyone: Actions → Server operations → `configure-release` with `reel_style: cartoon` (sets `SITE_STYLE=cartoon`
   and the v2 reels). Back: the same with `classic`. By hand: `SITE_STYLE` in `.env`, then `php artisan config:cache`.

## Working on it

- Everything is scoped under `body.md-cartoon` (site) or `body.war-cartoon` (Matchday War); classic never
  loads the cartoon stylesheet.
- After changing `app.css`, `war.css` or `game.css`, run `python3 scripts/build-cartoon-css.py` so the
  generated overrides follow.
- New army art: `php artisan armies:colours` (sky colours) then `node scripts/build-armies.mjs` (solid cut-outs
  and mood thumbnails).
- Fixed on the way (both looks): the match page's reel strip and the stories grid made phones scroll sideways.
- The generator also maps the classic serif (Georgia) to Anton and the classic parchment backgrounds to the
  cartoon paper, so new classic rules pick up the look without hand-written overrides.
- Brand (3 Nov): the logo is one rule everywhere (`.matchday-wordmark` sticker with the ink `.matchday-monogram`
  tile and a sun Anton "MD", the same as the favicon). The share image (`public/images/social-default.png`), the
  fixture cards (`FixtureCardRenderer::drawCartoon`) and the error pages (`resources/views/errors/`) follow it.
  Older admin pages that use stock Tailwind button colours are restyled by class in `cartoon.css`.
- Reels record the look they were drawn in (`metadata.reel.style`); `reel:queue` (Server operations →
  `reel-queue`) redraws reels from an earlier look in the current one without posting them to social again.
- Solid cut-outs come from model mattes (`public/war/downloads/rights-safe/masks/<art>.png`, white = statue), made
  with rembg's BiRefNet (general, lite) on 3 Nov after the brightness flood fill lost dark legs, faces and horses and
  left smoky blobs. New statue art: make its matte the same way, check it by eye, then run `node scripts/build-armies.mjs`.
  Bump `ReelService::V2_REVISION` when the art or reel layout changes enough that made reels should be redrawn.
- Still dark: the live goal and red-card story cards (`StoryCardRenderer`).
