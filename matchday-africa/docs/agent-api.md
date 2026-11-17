# Agent API and the agent role

The social media assistant ("Matchday agent") has its own login with the **agent** role and a
long-lived API token. It can read the site's content data, request video renders, mark social posts
as posted and write **draft** articles. It cannot open billing, payments, users, secrets or settings,
and it cannot delete anything. Every API request, and every change it makes in the admin, is written
to the audit log on `/admin/agent`.

## 1. Create the agent login and token

Run the migrations first (every deploy does this).

**From the admin (no shell needed):** log in as an admin, open **Admin → Content → Agent access**
(`/admin/agent`):

1. Fill in *Name* (`Matchday agent`), *Email* and a *Password* (12+ characters), then **Save agent login**.
2. Click **Issue new token**. The token (`mda_…`) is shown **once**. Copy it into the assistant's
   secure setting. Only a SHA-256 hash is stored, so it can't be shown again; issue a new one if it's lost
   (older tokens are revoked by default).
3. Revoke a token there at any time.

**From a private terminal (cPanel Terminal or SSH, never a CI log):**

```bash
cd ~/matchday-africa
php artisan agent:create agent@example.com                      # random password + token, each printed once
php artisan agent:create agent@example.com --password='…'       # set the password yourself
php artisan agent:create agent@example.com --no-token           # update the login only
```

Running it again for the same email issues a fresh token and revokes the old one. An email that
already belongs to an admin or a member is refused: the agent always has its own account.

The agent logs in at `https://matchday.africa/login` with that email and password and lands on the
**Content studio** (`/admin/studio`).

### What the agent can open in the admin

| Page | Allowed |
|---|---|
| Content studio `/admin/studio` | view, Render story, edit hook/banter/captions |
| Social queue `/admin/social-queue` | view, Mark posted (not Undo) |
| Articles `/admin/blogs` | list, view, preview, create and edit **drafts** (anything it saves is a draft; published articles are closed) |
| Slideshows, Matches, Match previews | view only |
| Everything else (dashboard, signups, users, commerce, payments, YouTube, secrets, uploads, sync, cache, any delete) | **403** |

The list lives in `App\Http\Middleware\AdminMiddleware::AGENT_ROUTES`; `tests/Feature/AgentAccessTest`
walks every admin route and checks the rest answer 403.

## 2. Using the API

Base URL `https://matchday.africa/api/agent`. Send the token as a bearer token. Responses are JSON.

```bash
export MD_TOKEN='mda_…'   # from the assistant's secure setting, never from a file in the repo
curl -s https://matchday.africa/api/agent/matches?status=ft -H "Authorization: Bearer $MD_TOKEN"
```

* **Limits:** 120 requests a minute per token (`AGENT_API_PER_MINUTE`); writes (`POST`) 20 a minute
  (`AGENT_API_WRITES_PER_MINUTE`). Over the limit: `429` with `Retry-After`.
* **Errors:** `401` bad, missing or revoked token; `404` unknown id; `422` invalid input (with `errors`);
  `503` the feature is switched off or not migrated (the message says which).
* **Times:** ISO 8601 UTC (`…Z`). `from`/`to` are UTC dates (`YYYY-MM-DD`).
* **Links** carry no UTM tags except captions in the social queue (`utm_campaign=story`, etc.).

### GET /matches

`status=live|ft|upcoming`, `from`, `to`, `competition` (short code `PL`, ESPN slug `eng.1`, name or id), `limit` (max 100, default 30).
Finished and live matches newest first; upcoming soonest first.

```bash
curl -s "https://matchday.africa/api/agent/matches?status=ft&from=2026-11-15&to=2026-11-16&competition=PL" \
  -H "Authorization: Bearer $MD_TOKEN"
```

Each match: `id, home, away, competition, competition_code, kickoff_utc, status, home_score, away_score, url,
minute, half_time, venue, referee, attendance, events[] {type: goal|red_card, minute, extra_minute, side, player,
assist, own_goal, penalty}, stats {possession, shots, …: {home, away}}, lineups (API-Football or ESPN, as stored),
standings[] (the competition's current table), stories[] {id, lang, status, mp4_url}`.

### GET /players

`country` (FIFA code such as `NGA` or a name; includes confirmed heritage), `club` (team id or part of the name),
`african=true` (only players we cover: an African nationality or a confirmed, sourced heritage record), `limit`.

```bash
curl -s "https://matchday.africa/api/agent/players?african=true&club=Fulham" -H "Authorization: Bearer $MD_TOKEN"
```

Each player: `nationality, nationality_code, in_african_scope, african_label` ("Nigeria", "of Ghanaian descent"),
`heritage[] {country, kind, status, source_url}`, `season {goals, assists, league}` (Premier League only, from
match events), `photo {url, credit, licence, licence_url, source_url, artist}` (licence-checked Wikimedia Commons
photos only; null when there is none; always show the credit), `page_url`.

### GET /radio

`latest=true` for the newest published edition, else `limit` (max 30).

```bash
curl -s "https://matchday.africa/api/agent/radio?latest=true" -H "Authorization: Bearer $MD_TOKEN"
```

Each edition: `audio_url` (static mp3), `video_url` (when the radio video exists), `transcript` (the full script),
`running_order[] {position, text, start, end, stories[] {headline, source, url, rumour}}`, `disclosure`
(the AI/voice line that must go with any post).

### GET /articles

`status=draft|published|archived`, `limit`. Drafts included. `url` is set for published articles only.

### POST /articles (draft only)

```bash
curl -s -X POST https://matchday.africa/api/agent/articles -H "Authorization: Bearer $MD_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Iwobi rescues Fulham","content":"<p>…</p>","excerpt":"…","match_id":123,"sources":["https://…"]}'
```

Always saved as a **draft** (a `status` field is ignored). An editor publishes it from `/admin/blogs`.
Returns `201` with the article.

### GET /predictions

`days` (1-60, default 14). Per match with calls: `entries {accounts, guests}`, `calls {home_win, draw, away_win, top_scores}`,
`result {score, exact, right_result, points_awarded}` after full time; and the last five Beat the Griot rounds
(`griot_points, players, beat_the_griot, winners_drawn, calls[]`). Counts only: no names, emails or phone numbers.

### GET /analytics

`days=1|7|30` (default 7, Lagos days).

```bash
curl -s "https://matchday.africa/api/agent/analytics?days=7" -H "Authorization: Bearer $MD_TOKEN"
```

`visits {total, by_source[], by_campaign[], by_source_campaign[], by_day[]}`: page loads that carry `utm_source`,
counted once per browser session per tag set (bots skipped). Counting starts with this release; sessions without
UTM tags and click maps stay in Microsoft Clarity. `signups {total, by_source[], by_campaign[]}` from first-touch
attribution on new accounts.

### POST /render and GET /render/{id}

```bash
# A 61-75 s match story (lang en or fr; hook and banter are optional editor lines)
curl -s -X POST https://matchday.africa/api/agent/render -H "Authorization: Bearer $MD_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"match_story","match_id":123,"hook":"Iwobi at the death","banter":"Arsenal fans, breathe","lang":"en"}'
# → 202 {"id":"story:45","status":"pending","status_url":"https://matchday.africa/api/agent/render/story:45"}

# A radio video for an episode (published Matchday Radio edition)
curl -s -X POST https://matchday.africa/api/agent/render -H "Authorization: Bearer $MD_TOKEN" \
  -H "Content-Type: application/json" -d '{"type":"radio_video","episode_id":88}'

# A colour slideshow for a finished match (an editor approves slideshows before they are public)
curl -s -X POST https://matchday.africa/api/agent/render -H "Authorization: Bearer $MD_TOKEN" \
  -H "Content-Type: application/json" -d '{"type":"slideshow","match_id":123}'

curl -s https://matchday.africa/api/agent/render/story:45 -H "Authorization: Bearer $MD_TOKEN"
```

Status: `pending → rendering → ready` (or `failed` with `error`; retried up to 3 times). When ready:
`mp4_url` (static, `200 video/mp4`, byte ranges), `cover_url` (JPG), `duration_seconds`, `hook`, `captions`
per channel, `photo_credit`, and `social_queue_id`. A match story takes one to two minutes to render once
it starts; renders start each minute. Requesting the same type, match and language again re-renders it
(the old video is replaced at the same URL when the new one is ready).

### GET /social-queue and POST /social-queue/{id}/posted

The same list as the posting bot's `/api/social-queue` (reels, previews, slideshows, radio, and the new
`story:<id>` items).

```bash
curl -s https://matchday.africa/api/agent/social-queue -H "Authorization: Bearer $MD_TOKEN"
curl -s -X POST https://matchday.africa/api/agent/social-queue/story:45/posted -H "Authorization: Bearer $MD_TOKEN" \
  -H "Content-Type: application/json" -d '{"channel":"tiktok","external_post_id":"7311…","url":"https://www.tiktok.com/@matchday_africa/video/7311…"}'
```

A `match_story` item:

```json
{
  "id": "story:45", "type": "match_story", "status": "ready", "lang": "en",
  "duration_seconds": 68.0, "hook": "Late drama! Alex Iwobi levels it in the 89th minute",
  "video_url": "https://matchday.africa/generated/stories/45/story.mp4",
  "cover_url": "https://matchday.africa/generated/stories/45/cover.jpg",
  "captions": {"tiktok": "…", "instagram": "…", "x": "… (≤280)", "youtube": "…"},
  "hashtags": "#MatchdayAfrica #AfricanFootball",
  "photo_credit": "Jane Doe / Wikimedia Commons / CC BY-SA 4.0",
  "landing_urls": {"tiktok": "https://matchday.africa/matches/123?utm_source=tiktok&utm_medium=social&utm_campaign=story&utm_content=match-123", "…": "…"},
  "match": {"id": 123, "home": "Arsenal", "away": "Fulham", "score": "2-2", "…": "…"},
  "channels": {"tiktok": {"posted": false, "…": "…"}, "…": "…"}
}
```

`radio_video` items carry the edition's AI/voice disclosure in their captions; post them as they are.

## 3. Match-story videos (how they are made)

* After full time (`story:render`, every minute; on in production because every deploy writes `STORY_VIDEOS=true`, unless the repository variable `STORY_VIDEOS` is `false`): finished matches in `STORY_COMPETITIONS`
  (default `PL,eng.1`) are queued `STORY_DELAY_MINUTES` (2) after the last update, and rendered up to
  `STORY_CONCURRENCY` (2) at a time. A Premier League Saturday's six 15:00 matches are ready within about
  five minutes of the whistle. If the score or goals change within 45 minutes, the story is drawn again once.
* Only verified data: the score, goals with minutes and assists, red cards, the match's stats columns, the
  current table, earlier results between the clubs, each club's form and next fixture, and the featured
  African (or confirmed African-descent) player's numbers. Missing facts are left out, never estimated.
* 1080x1920, 30 fps, H.264 + AAC, 61-75 s (cuts of 3-5 s; quiet matches are padded with verified recaps,
  busy ones lose optional cuts). Black and #FFD23F with each club's colours, Ken Burns zooms, kinetic text,
  score counter. Text stays inside the TikTok safe zone (top 150 px, bottom 380 px, right 140 px clear):
  a render fails rather than break it.
* Photos: only the licence-checked Wikimedia Commons library (credit stored and shown). No crests, broadcast
  footage or agency photos.
* Music: only `matchday_original_beat_v1.mp3`, looked for in `resources/audio/`, `storage/app/music/`
  or `STORY_MUSIC_PATH`. Until it is there, stories render silent and the studio says so.
  Check with `php artisan story:render --check`.
* French: `lang=fr` puts the on-screen text and captions in French.
