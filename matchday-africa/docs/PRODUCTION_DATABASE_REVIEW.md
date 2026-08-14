# Production Database Copy Review

Reviewed file: `../db/new_matchday_africa.sql` (workspace-relative location: `db/new_matchday_africa.sql`)

Review date: 14 August 2026

## Executive finding

The file is a complete-looking phpMyAdmin export from MariaDB 10.6.27, generated on 11 August 2026. It contains the existing production schema and production data, but it is **not yet the schema required by the current application code**. It contains 28 recorded migrations and 26 tables; the current codebase contains later Matchday War, growth, experience, prediction-scoring, and commerce migrations that must run after this dump is restored.

The correct deployment order is:

1. Create a new empty MySQL/MariaDB database.
2. Import this SQL dump into the empty database.
3. Configure the deployed Laravel application to use that database.
4. Run `php artisan migrate --force` from the current codebase.
5. Seed only `WarFactionSeeder` if the new `war_factions` table needs its catalogue.
6. Clear cache and stale queue/session data as described below.

Do **not** migrate an empty database first and then import this dump: its unconditional `CREATE TABLE` statements will conflict with tables already created by migrations.

## Dump characteristics

- Size: approximately 85 MB
- Format: UTF-8 phpMyAdmin SQL dump
- Source database: `new_matchday_africa`
- Source server: MariaDB 10.6.27
- Transaction wrapper: present (`START TRANSACTION` / `COMMIT`)
- Character set: `utf8mb4`
- Storage engine: InnoDB
- Tables: 26
- Recorded migrations: 28
- Foreign-key constraints in dump: 19
- Stored procedures, views, triggers, or `DEFINER` clauses: none detected
- SHA-256 at review time: `f4034c282a6e37318db5e6d77ee10057b3609d20b47a2e563be3ba6613c07743`

## Existing tables

The dump contains:

`blogs`, `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `leagues`, `match_chats`, `match_events`, `match_previews`, `matches`, `migrations`, `password_reset_tokens`, `players`, `prediction_leaderboards`, `prediction_set_matches`, `prediction_sets`, `sessions`, `social_accounts`, `social_shares`, `standings`, `teams`, `twitter_tokens`, `user_favorites`, `user_predictions`, and `users`.

## Tables missing from the production copy

The following current-application tables are absent and should be created by the new migrations:

- Matchday War: `war_factions`, `war_rooms`, `war_campaigns`, `war_referrals`, `war_subscribers`
- Growth and personalisation: `prediction_groups`, `prediction_group_members`, `notification_preferences`, `analytics_events`
- Experience and creators: `creator_profiles`, `match_stories`, `user_badges`
- Commerce: `commerce_products`, `commerce_orders`, `commerce_order_items`, `digital_entitlements`, `creator_earnings`, `sponsor_placements`

The migration `2026_08_14_000001_add_scoring_state_to_user_predictions` is also absent from the migration history. The dump already has an `is_scored` column, but the migration is intentionally defensive and checks whether the column exists before adding it. It will still perform the scoring-state data correction, including resetting old goalscorer predictions for safe rescoring.

## Approximate populated-row inventory

The dump contains approximately:

| Table | Rows |
|---|---:|
| users | 168 |
| matches | 3,841 |
| teams | 305 |
| players | 3,977 |
| leagues | 13 |
| standings | 224 |
| user_predictions | 565 |
| prediction_sets | 15 |
| prediction_set_matches | 127 |
| prediction_leaderboards | 134 |
| match_previews | 105 |
| match_chats | 24 |
| blogs | 19 |
| social_accounts | 17 |
| social_shares | 238 |
| sessions | 265 |
| cache | 7,490 |
| jobs | 18,631 |
| password_reset_tokens | 12 |

Counts were derived from the dump structure without displaying private row contents.

## Important operational risks

### 1. Large queue backlog

The `jobs` table contains approximately 18,631 queued jobs. Do not immediately start a production queue worker after importing the dump. Old jobs may send stale notifications, duplicate social posts, process outdated match data, or consume API quotas.

After import, inspect queue types and dates. Unless there is a confirmed business need to preserve them, clear the backlog before enabling the queue worker:

```bash
php artisan queue:clear database --queue=default
```

Take a database backup first. Then allow the current scheduler to create fresh work.

### 2. Stale cache and sessions

The dump contains 7,490 cache rows and 265 session rows. These are runtime state, not business records. Clear them after restore:

```bash
php artisan cache:clear
```

For a clean cutover, invalidate imported sessions so users sign in again:

```sql
TRUNCATE TABLE sessions;
DELETE FROM password_reset_tokens;
```

This avoids migrating stale sessions and old password-reset links to the new host.

### 3. Sensitive production data

The dump contains personally identifiable and security-sensitive information, including:

- User names and email addresses
- Password hashes and remember tokens
- Session payloads, IP addresses, and browser user agents
- Password reset tokens
- Social-provider access and refresh tokens
- Twitter/X access and refresh tokens
- Match-chat content

The SQL file is currently untracked but **not ignored by Git**. It must never be committed, attached to a public ticket, uploaded to the public web root, or shared through an unencrypted channel. Store it outside the repository or add a repository ignore rule such as `db/*.sql` before any Git operation.

After a migration between hosting environments, consider revoking/rotating third-party OAuth tokens if the dump has been exposed outside controlled storage.

### 4. Media files are not in the database

Database paths such as blog images refer to files under application storage. Importing this SQL file alone will not restore uploaded images or other storage assets. Copy the production `storage/app/public` contents separately and recreate `public/storage` with:

```bash
php artisan storage:link
```

The static Matchday War media is deployed from the codebase's `public/war` directory and is not supplied by this database dump.

## Recommended cPanel import sequence

1. Place the SQL file in a private location outside `public_html`.
2. Create an empty cPanel MySQL/MariaDB database and database user.
3. Import with phpMyAdmin if its upload and execution limits accept 85 MB. Otherwise use cPanel Terminal:

   ```bash
   mysql -u CPANEL_DB_USER -p CPANEL_DATABASE < /private/path/new_matchday_africa.sql
   ```

4. Verify that the import completed through the final `COMMIT` and that 26 tables exist.
5. Point the current Laravel `.env` to the imported database.
6. Run:

   ```bash
   php artisan optimize:clear
   php artisan migrate:status
   php artisan migrate --force
   php artisan db:seed --class=WarFactionSeeder --force
   ```

7. Back up the upgraded database before clearing runtime state.
8. Review and clear the old queue backlog.
9. Clear cache, imported sessions, and old password-reset tokens.
10. Copy production media storage and create the storage link.
11. Run application acceptance tests before enabling scheduler and queue cron jobs.

## Post-migration verification queries

Run these through phpMyAdmin or MySQL after `artisan migrate --force`:

```sql
SELECT COUNT(*) AS table_count
FROM information_schema.tables
WHERE table_schema = DATABASE();

SELECT migration, batch
FROM migrations
ORDER BY id DESC
LIMIT 10;

SELECT COUNT(*) AS users FROM users;
SELECT COUNT(*) AS matches FROM matches;
SELECT COUNT(*) AS predictions FROM user_predictions;
SELECT COUNT(*) AS pending_jobs FROM jobs;
SELECT COUNT(*) AS war_factions FROM war_factions;
SELECT COUNT(*) AS products FROM commerce_products;
```

Check for orphaned references:

```sql
SELECT COUNT(*) AS orphan_matches_home
FROM matches m LEFT JOIN teams t ON t.id = m.home_team_id
WHERE t.id IS NULL;

SELECT COUNT(*) AS orphan_matches_away
FROM matches m LEFT JOIN teams t ON t.id = m.away_team_id
WHERE t.id IS NULL;

SELECT COUNT(*) AS orphan_predictions_user
FROM user_predictions p LEFT JOIN users u ON u.id = p.user_id
WHERE u.id IS NULL;

SELECT COUNT(*) AS orphan_predictions_match
FROM user_predictions p LEFT JOIN matches m ON m.id = p.match_id
WHERE m.id IS NULL;
```

Every orphan count should be zero.

## Final decision

The dump is suitable as the **production-data starting point**, provided it is imported into an empty database and immediately upgraded with the current Laravel migrations. It is not a standalone final database for the current code. Queue, cache, session, reset-token, media, and sensitive-token handling must be addressed before go-live.
