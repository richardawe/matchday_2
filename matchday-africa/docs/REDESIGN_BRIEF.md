# Redesign brief: Matchday Africa, "the match as an instrument"

Status: **draft for approval**, 19 Oct 2026. Nothing here is built yet. It sits on top of the home-page ordering and match-centre rebuild already waiting on the `claude/match-centre-ordering` branch.

## 1. What we're borrowing and why

**Anatomy** (github.com/wheresryan22/anatomy, MIT licence) is a kit for drawing crafted, interactive **isometric figures**: an idea explained as a real physical object, built from many small shaded parts, inside a framed card. Its rules translate well to a football site:

| Anatomy rule | What it means for us |
|---|---|
| **The object is the explanation.** Don't draw boxes and arrows; build a machine whose working *is* the idea. | Draw the match itself as an object fans recognise (a pitch on a bench, a scoreboard, a dial). Avoid generic charts. |
| **Every number comes from the model.** The readout and the motion are driven by one data source. | Every figure is drawn from our live match data (score, minute, events, stats). Nothing decorative or made up. |
| **Framed card**: `Fig n` top left, title top right, hint bottom left, live readout bottom right, monospaced. | A consistent "match card" frame across the site. |
| **Calm motion**: eased, slow enough to follow, still under reduced motion, paused offscreen. | Live updates glide in instead of flashing. Fits the 20-second live polling we've just built. |
| **One lit accent**; restrained palette; colour only when colour is the subject. | Keep our ink / gold / paper palette. Team colours appear only where the team is the subject. |
| **Many small, precise parts**: screws, rulers, ticks, label plates. | Gives the "premium, crafted" feel without photos or club logos. |

**What we won't copy:** Anatomy's subjects (engines, gauges) or its light-first look. Matchday Africa stays dark and gold, with paper-coloured sections (like the current "Matchday Pulse") as the light theme.

**three.js:** Anatomy itself doesn't use three.js. It draws in SVG with small WebGL layers, which is much lighter. The proposal is to use **Anatomy-style SVG for everything data-driven**, and **three.js for one optional showpiece** (section 3.4), where real 3D adds something.

## 2. What we have today

| Asset | State | Problem |
|---|---|---|
| 22 club "warrior" statues (`public/war/warriors/*.png`) | Used on the home page Premier League "war" takeover and match pages | **1.9 MB each, 42 MB in total**, plain PNG at 1086×1448. A slow phone downloads several of these on the home page. |
| Warriors trailer (`public/war/matchday-warriors-trailer.mp4`, 17.5 MB) | Built by hand with `scripts/make-trailer.sh` | The script only runs on a Mac (macOS font paths, `zsh`). |
| Social render workflow (`.github/workflows/render-social.yml`, `scripts/render-social.mjs`) | A stub that saves a JSON spec | Never renders anything ("add Remotion composition when templates are approved"). |
| Team badges | football-data.org crests for Premier League clubs | **None for the teams ESPN now adds** (CAF, Turkish, Europa League…). |
| Competition logos | Mostly missing | The match centre shows "PR" / "CA" initials in a box. |

**IP warning.** The warrior images show real club crests (Arsenal's crest is on the shield). Using them on our own pages is a risk the site already carries. **Automatically producing and posting them at scale** (social cards, every fixture) raises the trademark exposure: Premier League clubs enforce their marks. The brief proposes crest-free automated outputs (team colours plus our own artwork). I'm not a lawyer, and this is worth checking before anything posts automatically.

## 3. The concepts

### 3.1 Match page hero: "The Match Instrument" (the centrepiece)

An isometric **pitch on a bench**, drawn in SVG from the match data. It replaces the current flat scoreboard block at the top of the match page.

- **Timeline rail.** A ruled rail along the near touchline marked 0′–90′, with major ticks every 15′. A **carriage** slides along it to the live minute.
- **Incident pins.** Goals, cards and subs are small pins standing on the rail at their minute: a ball for a goal, a card for a card, an arrow for a sub. Hover or tap a pin and the readout reads the moment ("41′ · Goal · Saka").
- **Score drums.** A split-flap scoreboard on the far stand that turns when a goal arrives.
- **Possession dial.** A gauge on the bench whose needle sits at the possession split. Shots and corners show as small counters beside it.
- **Lit accent.** The scoring team's end glows softly for a few seconds after a goal (a small WebGL layer, with an SVG fallback).
- **Card frame.** `Fig 1` · `ARS v CHE · PREMIER LEAGUE` · hint `TAP A PIN TO READ THE MOMENT` · readout `52′ · 2–1 · possession 58%`.
- **Live.** It updates from the existing `/matches/{id}/live` endpoint every 20 seconds. Pins drop in, the carriage glides, the drums turn. It goes still under reduced motion.
- **Before kick-off** it shows the empty pitch with the kick-off time on the drums. **At full time** it freezes with the full incident row, which also makes the shareable image (3.5).
- **Size** (Anatomy's tiers): a **Hero**, about 80–150 parts. One component, drawn once per match and moved by data.

### 3.2 Home page: the pulse as instruments

Keep the three-column "Now, next and just finished" ordering (already built). Each live match gets a **miniature instrument**: a slim rail with the carriage at the minute and pins for goals, drawn in the same style. Fans can read the state of every live game at a glance without opening it. Upcoming and result rows stay as text for speed.

### 3.3 Match centre: engraved plates and generated badges

- **Competition plates.** Each competition heading gets a small isometric **engraved plate** (initials or a short code on a bevelled plate with corner screws). It's generated automatically for every league, so no licensed logos are needed and every competition looks consistent.
- **Generated team badges.** ESPN supplies each team's **primary and secondary colours**. We store them and draw a crest-free shield or roundel with the team's initials, so the hundreds of teams ESPN adds all get a tidy badge automatically. Real crests stay wherever we already have them.
- The live rows get the same mini rail as the home page.

### 3.4 Optional showpiece (three.js): "Hall of Warriors"

A dark 3D hall on the Matchday War page (not the home page) where today's Premier League fixtures stand as **pairs of warrior plinths facing each other** under team-coloured light. The camera drifts slowly; drag to orbit, tap a plinth to open the match.

- Uses the existing warrior artwork on plinth panels, so no new 3D models are needed.
- **Loaded only when someone opens it:** three.js is **about 171 KB gzipped** (measured, r170), too heavy for every home-page visit on mobile data. It doesn't load with Save-Data on, without WebGL 2 or on low-memory devices; the static warrior grid shows instead.
- Needs the crest decision in section 2 before it's built.

### 3.5 Automation: EPL images and cards without hand work

| Pipeline | What it does | Runs |
|---|---|---|
| **A. Image optimisation** | Converts the warrior PNGs (and any new art) to AVIF and WebP at three widths, served with `srcset`. Expected size drops from about 1.9 MB to roughly 60–150 KB per image (to measure). | In the existing deploy workflow, before the upload |
| **B. Fixture cards** | For every upcoming Premier League fixture, builds a "home v away" card (1200×630 for X/OG, 1080×1920 for stories) from an HTML template using warrior art (or crest-free badges) and kick-off time. Rebuilt with the final score at full time. | Scheduled job; feeds the existing X card and growth-post code |
| **C. Instrument stills** | At full time, captures the Match Instrument (3.1) as a PNG. The same picture fans saw becomes the result post and the image in the daily digest email. | Scheduled job after `espn:sync` marks a match finished |
| **D. Trailer** | Ports `make-trailer.sh` to run in GitHub Actions on Linux, started by hand when the art changes. | `workflow_dispatch` |
| **E. Social render** | Replaces the `render-social` stub with the HTML-template renderer from B, so there's one way to make images. Remotion isn't needed. | Existing workflow |

B and C render with **headless Chrome from HTML templates**. That's the same approach Anatomy uses for its screenshots, and the same tooling I used for your mockups, so no new paid service.

## 4. Design system additions

- **Type:** keep Georgia for display headings. Add one **monospaced** face for card corners, readouts and minutes (Anatomy uses system monospace; we can use IBM Plex Mono from Google Fonts for consistency).
- **Frame:** a shared Blade component `<x-figure fig="1" title="…" hint="…" readout="…">` for every instrument.
- **Tones:** base `lo`, structure `mid`, subject `hi`, one `lit` accent in gold. Team colours only on badges, pins and the goal glow.
- **Motion:** exponential follow for the carriage and needle, about 400 ms flips for the drums, no motion under `prefers-reduced-motion`, paused when offscreen.

## 5. Budgets and guardrails

| | Budget |
|---|---|
| Extra JavaScript on the home page and match page | ≤ 40 KB gzipped (SVG instruments, no three.js) |
| An inline instrument SVG | ≤ 60 KB |
| A warrior image on mobile | ≤ 150 KB |
| three.js | Hall of Warriors only, loaded on demand |
| Accessibility | Every instrument has a text equivalent (score, minute, incident list) for screen readers; keyboard can step through pins |
| Fallback | If JavaScript or WebGL fails, the page still shows the score and events as text |

## 6. Phasing

| Phase | Work | Rough size |
|---|---|---|
| 0 | Home ordering and match centre (built, awaiting your approval) | done |
| 1 | Image pipeline (A), stored team colours and generated badges, competition plates | 1–2 days |
| 2 | Match Instrument on the match page (3.1), plus mini rails on home and match centre (3.2) | 3–4 days |
| 3 | Fixture cards and instrument stills (B, C, E), wired to X cards and the digest | 2 days |
| 4 | Hall of Warriors (3.4), behind a flag | 2–3 days |
| 5 | Trailer in CI (D) | ½ day |

Each phase ships on its own and gets mockups (real screenshots, as before) for you to approve before it goes to `main`.

## 7. Decisions needed

1. **Concept:** is "the match as an instrument" (3.1) the right centrepiece, or do you want a different metaphor?
2. **Crests:** for automated outputs (cards, posts, the Hall), crest-free (team colours and generated badges) as recommended, or keep the crested warrior art and accept the risk?
3. **three.js:** build the Hall of Warriors (phase 4), or keep the site SVG-only for speed?
4. **Order:** happy with phases 1 → 5, or start with phase 2 (the visible centrepiece) first?
5. **Phase 0:** push the already-built home ordering and match centre to `main` now, before this starts?
