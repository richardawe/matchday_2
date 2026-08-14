# Matchday Growth Engine Operations

## cPanel runtime

The implemented application uses a Cloudflare D1 `DB` binding and is ready for Sites/Cloudflare deployment. If cPanel remains the required host, it needs Node.js 22+, HTTPS, a persistent process and cron; its MySQL database is not API-compatible with D1, so the SQL calls in `worker/index.ts` need a small MySQL adapter before traffic is switched. Do not upload the current build to plain PHP-only hosting.

Configure `.env` from `.env.example`. Never upload `.env` to source control.

Suggested cPanel cron schedule (Europe/London):

```cron
0 6 * * * cd /path/to/app && /path/to/node scripts/growth-cron.mjs sync
15 6 * * 1 cd /path/to/app && /path/to/node scripts/growth-cron.mjs generate
*/10 * * * * cd /path/to/app && /path/to/node scripts/growth-cron.mjs sync
*/5 * * * * cd /path/to/app && /path/to/node scripts/growth-cron.mjs publish
30 7 * * 1 cd /path/to/app && /path/to/node scripts/growth-cron.mjs digest
```

The frequent sync is inexpensive because the endpoint requests one cached 14-day competition window. Keep the football-data.org free-tier limit of 10 requests/minute in mind.

## Weekly workflow

1. Sync imports the next fourteen days and preserves the last valid records when the provider fails.
2. Generate selects three hero clashes and creates Instagram, TikTok and X pre/post drafts.
3. Review `/admin/growth`, request a 15-minute magic link using an allowlisted email and approve only validated copy.
4. Publish sends approved items to Buffer as approval-required drafts.
5. The weekly digest sends the three hero clashes through Resend; result sync updates Myth Grammar copy after verified full time.

## GitHub social rendering

Create a private repository, add cPanel FTP secrets, then dispatch `Render social campaign` with a campaign ID and signed render-spec URL. The workflow validates the spec and uploads generated files to `public/generated/`. The included runner is deliberately a safe validation scaffold; approved Remotion compositions must be added before MP4 rendering is enabled.

## Required production connections

- `FOOTBALL_DATA_TOKEN`: imports the Premier League's coming fourteen-day fixture window; without it the site serves the bundled matchweek safely.
- `ADMIN_EMAILS` and `RESEND_API_KEY`: enable the private, 15-minute magic-link campaign desk and weekly hero-clash digest.
- `BUFFER_API_KEY` and `BUFFER_CHANNELS`: send approved Instagram, TikTok and X copy to Buffer as approval-required drafts.
- `NEXT_PUBLIC_POSTHOG_KEY`: activates the consent-free product funnel instrumentation already loaded by the app.
- A verified sender domain and reviewed consent/unsubscribe copy are required before email is switched on.

## Launch sequence

- Run two matchweeks in shadow mode: generate assets, but do not configure `BUFFER_API_KEY`.
- Verify all club/provider mappings and rights-safe artwork.
- Add PostHog funnels for fixture view → game start → game complete → challenge share → friend join.
- Configure Resend and OneSignal only after consent copy and unsubscribe handling have been reviewed.
- Obtain UK intellectual-property review before public commercial promotion.
