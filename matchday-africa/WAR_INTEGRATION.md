# Matchday War Integration

`/war` is a native module of Matchday Africa. It reuses the existing Laravel authentication, MySQL database, match/team/event records, Football Data synchronisation, mail configuration and scheduler.

## Deploy

1. Back up the production database and application.
2. Upload this application source without `.env`, `node_modules`, `vendor`, logs or caches.
3. Preserve the production `.env`; no existing secret needs to be copied into browser code.
4. Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
5. Run `php artisan migrate --force`.
6. Run `php artisan db:seed --class=WarFactionSeeder` after teams have been synced.
7. Run `php artisan optimize:clear && php artisan optimize`.
8. Keep the existing `php artisan schedule:run` cron active.

The copied repository intentionally contains only tracked application source and
the War media assets. The source machine's `.env`, caches and runtime data were
not copied. Keep the production `.env` in place so the existing Football Data,
odds, OpenRouter, mail and social credentials remain server-side and continue to
serve both Matchday and `/war`.

## Migration and rollback

- The migration creates only `war_*` tables and adds no columns to existing tables.
- `war_factions.team_id`, campaign fixtures and user ownership use foreign keys to
  the existing Matchday records.
- Deploy during a normal maintenance window, then seed factions after team sync.
- To remove the module, roll back the final migration and remove the `/war` route,
  asset and view files. Existing Matchday data is untouched.

## Verification

```bash
php artisan route:list --path=war
php artisan migrate:status
npm run build
php artisan test --filter=WarModuleTest
```

The legacy repository currently has an unrelated SQLite migration-order issue:
an older migration alters `matches` before the base `matches` table is created.
Production MySQL already contains that table, but this should be cleaned up before
using `migrate:fresh` as the project's full CI database bootstrap.

## URLs

- Public experience: `/war`
- Live fixture JSON: `/war/api/fixtures`
- Match landing: `/war/match/{match}`
- Friend challenge: `/war/challenge/{match}`
- Campaign admin: `/admin/war`

The old Cloudflare/D1 worker is not used by this integrated edition.
