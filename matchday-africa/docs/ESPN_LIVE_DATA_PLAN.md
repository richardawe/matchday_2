# Plan: free live match data from ESPN's public feed

Status: **draft, awaiting approval**. Researched 18 October 2026 against the live feed and the current `matchday-africa` code.

## 1. Summary

ESPN runs an undocumented JSON feed for its own apps and website. It needs no key and covers the Premier League, the big European leagues, AFCON, CAF Champions League, CAF Confederation Cup, World Cup qualifying (CAF) and the South African Premiership. We would read it from the server on a schedule, save the results to our own database, and keep football-data.org and API-Football as backups.

**The main risk is legal, not technical** (section 3). ESPN's terms explicitly ban automated access, for any purpose, and commercial use. Matchday Africa doesn't take payments, which helps, but automated polling still breaks the terms. Please read that section before approving.

## 2. What the research found

### 2.1 Endpoints (tested from our environment)

| Purpose | URL | Notes |
|---|---|---|
| Matches for a league on a day | `https://site.api.espn.com/apis/site/v2/sports/soccer/{league}/scoreboard?dates=YYYYMMDD` | Score, status, clock, venue, team IDs and logos, plus goals and cards with player names and minutes (`details`) |
| One match in full | `https://site.api.espn.com/apis/site/v2/sports/soccer/{league}/summary?event={id}` | Every key event (goals, cards, subs), 28 team stats per side, line-ups, text commentary |
| League index | `https://sports.core.api.espn.com/v2/sports/soccer/leagues?limit=1000` | Lists 220 leagues; some working leagues (e.g. `nga.1`) aren't in it |

- Date **ranges** (`dates=20261012-20261018`) returned HTTP 400 on every league we tried, so we'll query one day at a time.
- Responses come back with `cache-control: max-age=5`, so ESPN's own cache refreshes about every 5 seconds. Response times were 170–400 ms.
- No published rate limits. Community guides don't give any either. We'll keep traffic low (section 5.4).

### 2.2 Coverage for our leagues (tested 18 Oct 2026)

| League | Slug | This weekend's matches | Goals/cards in feed | Full stats/line-ups |
|---|---|---|---|---|
| Premier League | `eng.1` | ✅ | ✅ | ✅ |
| Championship, FA Cup, League Cup | `eng.2`, `eng.fa`, `eng.league_cup` | ✅ | ✅ | ✅ |
| La Liga, Serie A, Bundesliga, Ligue 1, Eredivisie, Primeira Liga, Süper Lig | `esp.1`, `ita.1`, `ger.1`, `fra.1`, `ned.1`, `por.1`, `tur.1` | ✅ | ✅ | ✅ |
| Champions/Europa/Conference League | `uefa.champions`, `uefa.europa`, `uefa.europa.conf` | ✅ | ✅ | ✅ |
| South African Premiership | `rsa.1` | ✅ | ✅ (5 details on Siwelele v Polokwane) | partial |
| CAF Champions League | `caf.champions` | ✅ 13 matches | ❌ score only | ❌ |
| CAF Confederation Cup | `caf.confed` | ✅ | ❌ score only (expected, same as above) | ❌ |
| AFCON, AFCON qualifying, WC qualifying CAF | `caf.nations`, `caf.nations_qual`, `fifa.worldq.caf` | ✅ | ✅ when played (to confirm at next window) | ✅ likely |
| Nigerian NPFL | `nga.1` | ❌ feed still on the **2025–26** season (last match 24 May 2026) | ❌ | ❌ |
| Ghana Premier League | `gha.1` | ❌ still on 2025–26 | ❌ | ❌ |
| Kenyan, Ugandan, Zambian leagues | `ken.1`, `uga.1`, `zam.1` | ❌ still on 2025–26 | ❌ | ❌ |
| Egypt, Morocco, Tunisia, Algeria, Senegal, Côte d'Ivoire, Cameroon | various | ❌ not available (HTTP 400) | – | – |

So ESPN fixes our **European leagues and CAF competitions** (scores at least), but it does **not** fix domestic African leagues. Those need fan reporting (section 8) or a source we haven't found yet.

### 2.3 How fresh is it?

- On 18 Oct (22:17–22:23 UTC) we polled live matches in Argentina, Brazil and MLS every 10 seconds for 5 minutes. The clock in Argentina went 59' → 64' in step with real time, about one minute per minute, so the feed is genuinely live with no fixed delay. Every request succeeded (170–600 ms) with no blocking.
- No goal was scored during the test, so **we still need to measure how quickly a goal appears**. This happens during Phase 1 shadow mode (section 6).
- Finished matches had complete goal and card details within minutes of full time.

## 3. Legal and terms risk (please read)

1. **ESPN's terms ban this use.** The Disney terms of use that cover ESPN limit use to "personal, noncommercial use only" and forbid accessing or extracting content "using a robot, spider, script, or other automated means", plus "any commercial or business-related use". Matchday Africa doesn't take payments, which lowers the risk. But the ban on automated access applies to anyone, commercial or not, so polling the feed from our server still breaks the terms. Two things would make the site count as "commercial or business-related" if they go live: the code already includes a shop, premium checkout (Stripe) and sponsor slots, and advertising would too. The practical consequences range from being blocked (likeliest) to a cease-and-desist letter.
2. **Scores themselves are facts.** In the US, *NBA v Motorola* (1997) allowed a company to send real-time scores it collected itself by watching games. Facts aren't copyrightable, but the terms are a contract question, separate from copyright.
3. **UK/EU database right is a real risk.** In *Football Dataco v Sportradar* (2013), the Court of Appeal found that copying a substantial part of a live football database infringed database right, and that a foreign website serving UK users could be liable in the UK. ESPN's soccer data is almost certainly licensed from a commercial supplier, and copying it at scale could fall into this.
4. **What lowers the risk:**
   - Take only facts (score, minute, scorer, card, minute) and write our own text. **Never copy ESPN commentary text, news, photos or videos.**
   - Don't hotlink ESPN logos; we keep using our own team logos.
   - Don't mention ESPN on the site.
   - Keep request volume low and send a normal user agent.
   - Build it so ESPN can be switched off with one setting, and the site falls back to the current providers.
   - Treat ESPN as a **stopgap**, with fan reporting as the long-term own source.

I'm not a lawyer. If Matchday Africa grows or makes real money, get proper legal advice, or move to a licensed feed then.

## 4. Where this fits in the current code

What already exists, and what changes:

- **Scheduler** (`routes/console.php`): `matches:refresh live` runs every minute; `today` every 15 min; `results` every 5 min. The ESPN jobs would slot in alongside these.
- **Match page polling** (`resources/js/app.js`): the "chronicle" panel already asks `/matches/{id}/chronicle` every 20 s when live and updates the score. It doesn't update the minute, status or events list, and only runs when a chronicle story exists. We'll extend this rather than build something new.
- **Database:** `leagues`, `teams`, `matches` and `match_events` all require a unique, non-null `football_data_id`. Matches that only ESPN knows about (most CAF games) need a new way to be keyed (section 5.2).
- **Status values:** our app uses football-data.org's (`SCHEDULED`, `IN_PLAY`, `PAUSED`, `FINISHED`…). We'll map ESPN's onto these, so the rest of the app doesn't change.
- **Old code:** `app/Console/Kernel.php` has a second schedule that Laravel 12 never runs. We'll delete it to avoid confusion.

## 5. Design

### 5.1 New pieces

| File | Job |
|---|---|
| `config/espn.php` | On/off switch, list of leagues (ESPN slug ↔ our league), poll intervals |
| `app/Services/Espn/EspnClient.php` | HTTP calls with a short timeout, no retries and no sleeping (a failed poll is simply retried on the next run), and a circuit breaker |
| `app/Services/Espn/EspnMatchMapper.php` | Turns ESPN JSON into our match/event fields, including status mapping |
| `app/Services/Espn/EspnSyncService.php` | Finds or creates leagues/teams/matches and saves scores and events |
| `app/Console/Commands/EspnSync.php` | `espn:sync fixtures` / `espn:sync live` / `espn:sync details` |
| Migration | New columns (5.2) |
| Admin page | List of unmatched teams/matches to link by hand |
| Tests | Use saved ESPN JSON files from today as fixtures, never the real feed |

### 5.2 Database changes

- Add a nullable, unique `espn_id` to `leagues`, `teams` and `matches`.
- Make `football_data_id` **nullable** on those three tables, so ESPN-only matches (CAF) can exist. The current unique index still works with nulls.
- Add a `data_source` column to `matches` (`football-data`, `espn`, `api-football`, `manual`) so we know who last updated it.
- `match_events` keeps its required `football_data_id`. We fill it with a stable hash of `espn|{match}|{event id}`, the same trick `ApiFootballEnrichmentService` already uses, so re-syncing never creates duplicates.

### 5.3 Matching ESPN games to games we already have

Most Premier League games already exist from football-data.org, so we must link them, not duplicate them:

1. Map league to league from config (e.g. `eng.1` → our `PL` league).
2. For each ESPN match, look for a match in that league with the same kick-off (±3 hours) and team names that match after normalising (lower case, strip accents, drop "FC/AFC", plus an alias table: "Wolverhampton Wanderers" = "Wolves").
3. On a match, save `espn_id` on the match **and on both teams**. After the first weekend, teams match by ID, not by name.
4. No match → for leagues football-data.org covers, log it on the admin page (no new row). For ESPN-only leagues (CAF), create the teams and match.

### 5.4 Polling schedule and volume

Hosting cron only fires once a minute, so the live command loops inside each run.

| Job | When | What it does | Rough requests |
|---|---|---|---|
| `espn:sync fixtures` | every 6 h | Scoreboard for each league for the next 10 days and the past 2 | ~20 leagues × 13 days ≈ 260 per run, spread out with pauses |
| `espn:sync live` | every minute, polling 3 times at 0 s, 20 s and 40 s | Scoreboard **only for leagues with a match between kick-off −15 min and +3 h** | Busy Saturday: ~8 leagues × 3 = 24 per minute |
| `espn:sync details` | every 2 min while live, once at full time | Summary per live match for leagues with full coverage: stats, subs, line-ups | ~1 per live match per 2 min |

- **Updates on the page happen within about 20–40 seconds** of ESPN having them. Currently it's 5 minutes or more for scores, and 30–60 minutes for events.
- Each job uses `withoutOverlapping`, has a hard time limit, and never sleeps waiting for a rate limit.
- **Circuit breaker:** after 5 failures in a row (403, 429, timeouts or unexpected JSON), ESPN is paused for 15 minutes and football-data.org takes over. This shows on the admin sync page.

### 5.5 Which source wins

| Data | First choice | Fallback |
|---|---|---|
| Live score, minute, status | ESPN | football-data.org (`matches:refresh live`, unchanged) |
| Goals and cards | ESPN | API-Football (existing enrichment) |
| Stats, subs, line-ups | ESPN summary | API-Football |
| Fixtures for leagues football-data.org covers | football-data.org (keeps current IDs) | ESPN |
| CAF competitions (fixtures and scores) | ESPN | – |
| Standings | football-data.org (unchanged) | – |

A source only overwrites a score if its data is newer, so a slow source can't put the score back to an old value. After that, API-Football's 100 daily calls are spent only on what ESPN doesn't cover.

### 5.6 Status mapping

| ESPN | Ours |
|---|---|
| `STATUS_SCHEDULED` | `SCHEDULED` |
| `STATUS_FIRST_HALF`, `STATUS_SECOND_HALF`, extra-time halves | `IN_PLAY` |
| `STATUS_HALFTIME`, end-of-regulation breaks | `PAUSED` |
| `STATUS_SHOOTOUT` | `PENALTY_SHOOTOUT` |
| `STATUS_FULL_TIME`, `STATUS_FINAL_AET`, `STATUS_FINAL_PEN` | `FINISHED` |
| `STATUS_POSTPONED`, `STATUS_CANCELED`, `STATUS_ABANDONED` | `POSTPONED` / `CANCELLED` |
| anything unknown | leave status unchanged and log it |

The minute comes from `status.displayClock` (e.g. `45'+2'`) into `minute` (and the added time into match metadata).

### 5.7 Match page

- Extend `/matches/{id}/chronicle` (or add a lighter `/matches/{id}/live`) to also return the minute, the status and the events list, cached for 10 s, so 1,000 fans watching costs one database read every 10 s.
- `app.js`: always poll on live match pages (not only when a chronicle story exists), and update the minute, status and events list without reloading.
- Fix `status_display` so `IN_PLAY` and `PAUSED` show "Live" and "Half Time" instead of the raw code.

## 6. Rollout

1. **Phase 1, behind a switch (≈1–2 days):** client, mapper, migration and tests. Run `espn:sync` in **shadow mode**: write what ESPN says into metadata only, and log differences against the current sources on a matchday.
2. **Phase 2, live scores (≈1 day):** turn on ESPN as first choice for score/minute/status in Europe's big leagues and the CAF competitions. Do the match page polling work.
3. **Phase 3, events and stats (≈1 day):** goals, cards, subs and stats from ESPN; cut API-Football back to what ESPN can't cover.
4. **Phase 4, clean-up:** remove dead `Kernel.php`, update the admin health page, write a runbook.

Each phase deploys via the existing GitHub Action to cPanel and can be turned off with `ESPN_ENABLED=false`, no code change needed.

## 7. How we'll know it worked

- Median time from a real goal to it showing on our match page: **under 60 seconds** (measured against ESPN's own site on two matchdays).
- No duplicate matches or events after a full weekend.
- Admin's unmatched-team list is empty after the first weekend's fixes.
- Site keeps showing scores (from football-data.org) with ESPN switched off.

## 8. What this doesn't solve: domestic African leagues

ESPN has nothing current for the NPFL, Ghana, Kenya, Egypt, Morocco and others. The suggestion is **fan reporting**, as a separate project after this one:

- Trusted users (by reputation, or approved reporters at grounds) submit goal/card/final score from the match page.
- A result goes live when 2–3 reporters agree, or immediately from an admin. It's labelled "fan-reported".
- This is data we collect ourselves, the same principle that made the Motorola case legal, so it carries none of the risks in section 3. It's also something none of the big apps do well for African football.

## 9. Decisions needed

1. **Accept the terms risk in section 3** and use ESPN as a stopgap? (Yes / No)
2. **Leagues to switch on:** all those in 2.2 with ✅, or a shorter list?
3. **Do the match-page work (5.7) in the same change**, or separately?
4. **Plan the fan-reporting project next**? (Yes / Later)

## Sources

- ESPN feed: tested directly on 18 Oct 2026 (scoreboard, summary and league index endpoints).
- [Disney terms of use (covers ESPN)](https://disneytermsofuse.com/english/), sections 2.A, 2.B, 3.H.
- [NBA v Motorola, 105 F.3d 841 (2d Cir. 1997)](https://law.justia.com/cases/federal/appellate-courts/F3/105/841/598844/)
- [Football Dataco v Stan James & Sportradar [2013] EWCA Civ 27](https://caselaw.nationalarchives.gov.uk/ewca/civ/2013/27), and [SCL summary](https://www.scl.org/2720-database-rights-court-of-appeal-judgment/)
- [Zuplo: ESPN hidden API guide](https://zuplo.com/learning-center/espn-hidden-api-guide) (community documentation, unofficial)
