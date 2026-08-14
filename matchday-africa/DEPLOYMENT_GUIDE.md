# 🚀 Production Deployment Guide for matchday.africa

## Overview
This guide will help you deploy the Matchday Africa Laravel application to cPanel shared hosting.

## Pre-Deployment Checklist ✅

### 1. Application Ready
- [x] Development/testing elements removed
- [x] Logo implemented and working
- [x] Production assets compiled (`npm run build`)
- [x] Laravel caches optimized
- [x] Production environment template created

### 2. cPanel Requirements
- [x] PHP 8.1+ enabled
- [ ] MySQL database created
- [ ] Domain pointing to hosting
- [ ] SSL certificate installed

---

## 🏗️ **STEP 1: Prepare Files for Upload**

### Create Upload Package
```bash
# 1. Create a zip of your Laravel app (excluding node_modules, .git)
zip -r matchday-africa-production.zip . -x "node_modules/*" ".git/*" "*.log" ".env"
```

### Files Structure for cPanel
```
Your cPanel File Manager:
├── matchday-africa/          # Laravel app root (above public_html)
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   ├── .env                  # Production environment file
│   ├── artisan
│   ├── composer.json
│   └── ...
└── public_html/              # Domain's public folder
    ├── .htaccess
    ├── index.php
    ├── images/
    ├── build/                # Compiled assets
    └── ...
```

---

## 🏗️ **STEP 2: Upload and Setup**

### 2.1 Upload Laravel Application
1. **Login to cPanel File Manager**
2. **Navigate to your home directory** (not public_html)
3. **Create folder** `matchday-africa`
4. **Upload and extract** your zip file into this folder
5. **Install dependencies**:
   ```bash
   cd matchday-africa
   composer install --optimize-autoloader --no-dev
   ```

### 2.2 Move Public Files
1. **Copy contents** of `matchday-africa/public/` to `public_html/`
2. **Important files to copy**:
   - `index.php`
   - `.htaccess`
   - `images/` folder (with your logo)
   - `build/` folder (compiled assets)

### 2.3 Update index.php
Edit `public_html/index.php` and update the paths:

```php
<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Update these paths to point to your Laravel app folder
require __DIR__.'/../matchday-africa/vendor/autoload.php';

$app = require_once __DIR__.'/../matchday-africa/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
```

---

## 🏗️ **STEP 3: Database Setup**

### 3.1 Create MySQL Database
1. **Go to cPanel MySQL Databases**
2. **Create new database**: `your_username_matchday`
3. **Create database user** with strong password
4. **Add user to database** with ALL PRIVILEGES

### 3.2 Import Database Schema
Upload and run these SQL files in cPanel phpMyAdmin:
1. Run migrations: `php artisan migrate --force`

---

## 🏗️ **STEP 4: Environment Configuration**

### 4.1 Create Production .env
Create `.env` file in `matchday-africa/` folder:

```env
APP_NAME="Matchday Africa"
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY
APP_DEBUG=false
APP_URL=https://matchday.africa

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=your_username_matchday
DB_USERNAME=your_db_username
DB_PASSWORD=your_db_password

# Your API Keys
FOOTBALL_DATA_API_KEY=your_actual_api_key
GIPHY_API_KEY=your_actual_giphy_key

# Session/Cache (use database for shared hosting)
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_email
MAIL_PASSWORD=your_email_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="hello@matchday.africa"
```

### 4.2 Generate Application Key
```bash
cd matchday-africa
php artisan key:generate
```

---

## 🏗️ **STEP 5: Final Setup**

### 5.1 Set Permissions
```bash
chmod -R 755 matchday-africa/
chmod -R 775 matchday-africa/storage/
chmod -R 775 matchday-africa/bootstrap/cache/
```

### 5.2 Run Final Commands
```bash
cd matchday-africa
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

### 5.3 Test Your Site
Visit `https://matchday.africa` and verify:
- [x] Homepage loads correctly
- [x] Logo displays properly
- [x] Navigation works
- [x] Database connections work
- [x] API integrations function

---

## 🛠️ **Troubleshooting**

### Common Issues & Solutions

#### 1. **500 Internal Server Error**
```bash
# Check Laravel logs
tail -f matchday-africa/storage/logs/laravel.log

# Common fixes:
chmod -R 775 storage/
php artisan config:clear
```

#### 2. **Missing Assets/Images**
- Verify `build/` folder copied to `public_html/`
- Check `.htaccess` file exists in `public_html/`
- Ensure images folder copied correctly

#### 3. **Database Connection Issues**
- Double-check database credentials in `.env`
- Verify database user has proper privileges
- Test connection in cPanel phpMyAdmin

#### 4. **API Not Working**
- Verify API keys in production `.env`
- Check if hosting provider blocks external API calls
- Test API endpoints manually

---

## 🔒 **Security Checklist**

- [x] `APP_DEBUG=false` in production
- [x] Strong `APP_KEY` generated
- [x] Database credentials secure
- [x] `.env` file permissions restricted
- [x] SSL certificate installed
- [x] Regular backups scheduled

---

## 📊 **Performance Optimization**

### Already Applied
- [x] Configuration cached
- [x] Routes cached  
- [x] Views cached
- [x] Assets compiled and minified
- [x] Composer optimized (--no-dev)

### Additional Recommendations
- Enable OPcache in cPanel PHP settings
- Use database session driver (better for shared hosting)
- Set up cron jobs for queue processing if needed
- Monitor storage usage (logs can grow large)

---

## 🔄 **Future Updates**

### Deployment Process
1. **Test changes locally**
2. **Build production assets**: `npm run build`
3. **Upload changed files only**
4. **Clear caches**: `php artisan config:clear && php artisan cache:clear`
5. **Test production site**

### Recommended Tools
- **Version Control**: Keep using Git for updates
- **Backup Strategy**: Regular database and file backups
- **Monitoring**: Set up uptime monitoring
- **Analytics**: Consider adding Google Analytics

---

## 📞 **Support Contacts**

- **Laravel Documentation**: https://laravel.com/docs
- **Football-data.org API**: https://www.football-data.org/documentation
- **Giphy API**: https://developers.giphy.com/docs/api

---

**🎉 Congratulations! Your Matchday Africa application is now live at https://matchday.africa**