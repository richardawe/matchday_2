# 🚀 Matchday Africa - Quick Deployment Checklist

## Pre-Deployment
- [ ] Assets built (`npm run build`)
- [ ] Production environment file created (`.env.production`)
- [ ] OAuth redirect URIs updated for production domain
- [ ] Database credentials ready
- [ ] SSL certificate available

## File Upload
- [ ] Upload all files to cPanel File Manager
- [ ] Rename `.env.production` to `.env`
- [ ] Set file permissions (755 for directories, 644 for files)
- [ ] Ensure `storage/` and `bootstrap/cache/` are writable

## Database Setup
- [ ] Create database in cPanel
- [ ] Create database user and assign permissions
- [ ] Update database credentials in `.env`
- [ ] Run migrations: `php artisan migrate --force`

### Alternative: Individual Table Creation
If you need to create tables individually, run these commands in order:

```bash
# Core Laravel tables
php artisan migrate --path=database/migrations/0001_01_01_000000_create_users_table.php
php artisan migrate --path=database/migrations/0001_01_01_000001_create_cache_table.php
php artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php

# Football data tables
php artisan migrate --path=database/migrations/2025_07_31_230509_create_leagues_table.php
php artisan migrate --path=database/migrations/2025_07_31_230531_create_teams_table.php
php artisan migrate --path=database/migrations/2025_07_31_230555_create_matches_table.php
php artisan migrate --path=database/migrations/2025_07_31_230601_create_match_events_table.php
php artisan migrate --path=database/migrations/2025_07_31_230620_create_standings_table.php
php artisan migrate --path=database/migrations/2025_08_01_152400_create_players_table.php

# User interaction tables
php artisan migrate --path=database/migrations/2025_07_31_230830_create_user_favorites_table.php
php artisan migrate --path=database/migrations/2025_08_01_002038_create_match_chats_table.php
php artisan migrate --path=database/migrations/2025_08_01_010342_add_mentions_to_match_chats_table.php

# Blog system
php artisan migrate --path=database/migrations/2025_08_14_204115_create_blogs_table.php

# Prediction system
php artisan migrate --path=database/migrations/2025_08_15_132759_create_prediction_matches_table.php
php artisan migrate --path=database/migrations/2025_08_15_132809_create_predictions_table.php
php artisan migrate --path=database/migrations/2025_08_15_132814_create_prediction_results_table.php
php artisan migrate --path=database/migrations/2025_08_15_132818_add_prediction_fields_to_football_matches_table.php
php artisan migrate --path=database/migrations/2025_08_15_132820_create_prediction_leaderboards_table.php
php artisan migrate --path=database/migrations/2025_08_15_132825_add_prediction_fields_to_users_table.php

# User roles
php artisan migrate --path=database/migrations/2025_09_09_210802_add_role_to_users_table.php

# Social media features (NEW)
php artisan migrate --path=database/migrations/2025_09_11_110619_create_social_accounts_table.php
php artisan migrate --path=database/migrations/2025_09_11_110631_create_social_shares_table.php

# Match previews (NEW)
php artisan migrate --path=database/migrations/2025_01_15_000000_create_match_previews_table.php
php artisan migrate --path=database/migrations/2025_01_15_000001_add_has_preview_to_matches_table.php
```

## Laravel Configuration
- [ ] Run `composer install --optimize-autoloader --no-dev`
- [ ] Generate app key: `php artisan key:generate`
- [ ] Create storage link: `php artisan storage:link`
- [ ] Cache configuration: `php artisan config:cache`
- [ ] Cache routes: `php artisan route:cache`
- [ ] Cache views: `php artisan view:cache`

## OAuth Configuration
- [ ] Google Cloud Console: Add `https://matchday.africa/oauth-callback`
- [ ] Twitter Developer Portal: Add `https://matchday.africa/auth/twitter/callback`
- [ ] Test OAuth login functionality

## Web Server
- [ ] Document root points to `public/` directory
- [ ] SSL certificate installed and working
- [ ] Force HTTPS redirect enabled
- [ ] `.htaccess` file in root (if needed)

## Cron Jobs
- [ ] Set up cron job for `php artisan schedule:run` (every 5 minutes)
- [ ] Set up cron job for match preview generation (daily at 6 AM)

## Testing
- [ ] Homepage loads correctly
- [ ] User registration works
- [ ] User login works (email/password)
- [ ] Google OAuth works
- [ ] Twitter OAuth works
- [ ] Match data loads
- [ ] Admin panel accessible
- [ ] Match preview generation works

## Security
- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] File permissions set correctly
- [ ] Sensitive files not accessible via web

## Performance
- [ ] OPcache enabled (if available)
- [ ] Database indexes optimized
- [ ] Images optimized
- [ ] CSS/JS minified

## Post-Deployment
- [ ] Monitor error logs
- [ ] Test all major functionality
- [ ] Set up monitoring
- [ ] Create backup schedule

## Emergency Contacts
- **Email**: info@3d7tech.com
- **Logs**: Check `storage/logs/laravel.log`
- **cPanel**: Check error logs in cPanel

---

## Quick Commands for Production Server

```bash
# After uploading files, run these commands:
cd /path/to/matchday-africa
chmod +x deploy-production.sh
./deploy-production.sh
```

## OAuth URLs to Update

**Google Cloud Console:**
- Authorized JavaScript origins: `https://matchday.africa`
- Authorized redirect URIs: `https://matchday.africa/oauth-callback`

**Twitter Developer Portal:**
- Callback URL: `https://matchday.africa/auth/twitter/callback`