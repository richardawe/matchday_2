# Matchday Africa - Production Deployment Guide

## 🚀 Deployment to matchday.africa (cPanel)

### Prerequisites
- cPanel access to matchday.africa
- Database credentials for production
- FTP/SFTP access or cPanel File Manager
- PHP 8.1+ with required extensions

### Required PHP Extensions
- BCMath
- Ctype
- cURL
- DOM
- Fileinfo
- JSON
- Mbstring
- OpenSSL
- PCRE
- PDO
- Tokenizer
- XML
- MySQL/MySQLi

### Step 1: Database Setup

1. **Create Database in cPanel:**
   - Go to MySQL Databases
   - Create database: `matchday_africa`
   - Create user and assign to database
   - Note down credentials

2. **Update Database Configuration:**
   - Edit `.env.production` file
   - Update database credentials:
     ```
     DB_DATABASE=matchday_africa
     DB_USERNAME=your_cpanel_db_username
     DB_PASSWORD=your_cpanel_db_password
     ```

### Step 2: File Upload

1. **Upload Files to cPanel:**
   - Upload entire project to `public_html/` or subdirectory
   - Ensure all files are uploaded (including hidden files like `.env`)

2. **Set Permissions:**
   ```bash
   chmod -R 755 storage/
   chmod -R 755 bootstrap/cache/
   chmod 644 .env.production
   ```

### Step 3: Environment Configuration

1. **Rename Environment File:**
   ```bash
   mv .env.production .env
   ```

2. **Update Production Settings:**
   - Verify `APP_URL=https://matchday.africa`
   - Set `APP_DEBUG=false`
   - Set `APP_ENV=production`

### Step 4: OAuth Configuration

1. **Google OAuth:**
   - Go to Google Cloud Console
   - Update Authorized redirect URIs:
     - `https://matchday.africa/oauth-callback`
   - Update Authorized JavaScript origins:
     - `https://matchday.africa`

2. **Twitter OAuth:**
   - Go to Twitter Developer Portal
   - Update Callback URL:
     - `https://matchday.africa/auth/twitter/callback`

### Step 5: Laravel Setup

1. **Install Dependencies:**
   ```bash
   composer install --optimize-autoloader --no-dev
   ```

2. **Generate Application Key:**
   ```bash
   php artisan key:generate
   ```

3. **Run Migrations:**
   ```bash
   php artisan migrate --force
   ```

   **Alternative: Create Tables Individually (if needed):**
   ```bash
   # Core tables
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

4. **Clear Caches:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. **Create Storage Link:**
   ```bash
   php artisan storage:link
   ```

### Step 6: Web Server Configuration

1. **Document Root:**
   - Set document root to `public/` directory
   - Or create `.htaccess` in root to redirect to public

2. **Create .htaccess in Root:**
   ```apache
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteRule ^(.*)$ public/$1 [L]
   </IfModule>
   ```

### Step 7: SSL Certificate

1. **Enable SSL:**
   - Use cPanel SSL/TLS manager
   - Enable "Force HTTPS Redirect"

### Step 8: Cron Jobs

1. **Set up Cron Jobs in cPanel:**
   ```bash
   # Update live scores every 5 minutes
   */5 * * * * cd /path/to/matchday-africa && php artisan schedule:run >> /dev/null 2>&1
   
   # Generate daily match previews at 6 AM
   0 6 * * * cd /path/to/matchday-africa && php artisan match-previews:generate >> /dev/null 2>&1
   ```

### Step 9: Testing

1. **Test Basic Functionality:**
   - Visit https://matchday.africa
   - Test user registration/login
   - Test OAuth (Google/Twitter)
   - Test match data loading

2. **Test Admin Features:**
   - Login as admin
   - Test match preview generation
   - Test data synchronization

### Step 10: Performance Optimization

1. **Enable Caching:**
   - Enable OPcache in PHP settings
   - Configure Redis if available

2. **Database Optimization:**
   - Add indexes for frequently queried columns
   - Optimize database settings

### Troubleshooting

1. **Common Issues:**
   - 500 Error: Check file permissions and .env configuration
   - Database Connection: Verify credentials and host
   - OAuth Issues: Check redirect URIs and domain
   - Asset Loading: Ensure public/build/ files are uploaded

2. **Logs:**
   - Check `storage/logs/laravel.log` for errors
   - Check cPanel error logs

### Security Checklist

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] Strong database passwords
- [ ] SSL certificate enabled
- [ ] File permissions set correctly
- [ ] OAuth redirect URIs updated
- [ ] API keys secured

### Post-Deployment

1. **Monitor Performance:**
   - Check site speed and responsiveness
   - Monitor database performance
   - Check error logs regularly

2. **Backup Strategy:**
   - Set up regular database backups
   - Backup uploaded files
   - Keep code backups

### Support

For issues or questions, contact:
- Email: info@3d7tech.com
- Check logs in `storage/logs/laravel.log`
