# 📋 **Deployment Checklist for matchday.africa**

## Pre-Upload Checklist ✅

### Local Preparation
- [ ] **Remove development routes** (done ✅)
- [ ] **Logo implemented** (done ✅)  
- [ ] **Production assets built** (`npm run build`) (done ✅)
- [ ] **Laravel optimized** (configs cached) (done ✅)
- [ ] **Environment template created** (done ✅)
- [ ] **All features tested locally**

### Files to Upload
- [ ] **Main Laravel app** → `matchday-africa/` folder on server
- [ ] **Public files** → `public_html/` folder on server
- [ ] **Logo file** → `public_html/images/matchday-africa-logo.svg`
- [ ] **Compiled assets** → `public_html/build/` folder

---

## cPanel Setup Checklist 🏗️

### 1. Hosting Configuration
- [ ] **PHP 8.1+ enabled** in cPanel
- [ ] **Composer available** (check in Terminal)
- [ ] **MySQL database created**
- [ ] **Database user created with ALL privileges**
- [ ] **SSL certificate installed**
- [ ] **Domain pointing to hosting**

### 2. File Upload & Structure
- [ ] **Upload Laravel app** to `~/matchday-africa/` (not public_html)
- [ ] **Run composer install** in `matchday-africa/` folder
- [ ] **Copy public folder contents** to `public_html/`
- [ ] **Update index.php paths** in `public_html/`
- [ ] **Copy .htaccess** (use `public_html.htaccess` template)

### 3. Environment Setup
- [ ] **Create .env file** in `matchday-africa/` folder
- [ ] **Set production database credentials**
- [ ] **Add Football-data.org API key**
- [ ] **Add Giphy API key**
- [ ] **Generate new APP_KEY** (`php artisan key:generate`)
- [ ] **Set APP_DEBUG=false**
- [ ] **Set APP_ENV=production**

### 4. Database Migration
- [ ] **Run migrations**: `php artisan migrate --force`
- [ ] **Test database connection**
- [ ] **Verify tables created**

### 5. File Permissions
- [ ] **Set folder permissions**: `chmod -R 755 matchday-africa/`
- [ ] **Set storage permissions**: `chmod -R 775 matchday-africa/storage/`
- [ ] **Set cache permissions**: `chmod -R 775 matchday-africa/bootstrap/cache/`

### 6. Final Optimization
- [ ] **Cache config**: `php artisan config:cache`
- [ ] **Cache routes**: `php artisan route:cache`
- [ ] **Cache views**: `php artisan view:cache`
- [ ] **Create storage link**: `php artisan storage:link`

---

## Testing Checklist 🧪

### Functionality Tests
- [ ] **Homepage loads** at https://matchday.africa
- [ ] **Logo displays correctly** in navigation and hero
- [ ] **Navigation links work** (Matches, Leagues, Teams)
- [ ] **Match listings load** with proper data
- [ ] **League pages work** with standings
- [ ] **Team pages display** correctly
- [ ] **Chat functionality works** on match pages
- [ ] **GIF search works** in chat
- [ ] **User registration/login** functions
- [ ] **Profile pages accessible**

### API Integration Tests
- [ ] **Football-data.org API** responds correctly
- [ ] **Match data syncing** works
- [ ] **League logos** load from API
- [ ] **Team logos** display properly
- [ ] **Giphy API** returns GIFs for chat

### Performance Tests
- [ ] **Page load times** < 3 seconds
- [ ] **Images optimize** and load quickly
- [ ] **No console errors** in browser
- [ ] **Mobile responsive** design works
- [ ] **SSL certificate** active and working

---

## Security Verification 🔒

- [ ] **APP_DEBUG=false** in production
- [ ] **Strong APP_KEY** generated
- [ ] **Database credentials** secure
- [ ] **.env file** not publicly accessible
- [ ] **HTTPS redirects** working
- [ ] **Security headers** enabled in .htaccess
- [ ] **Sensitive files** hidden (.env, composer files)

---

## Post-Launch Tasks 🚀

### Immediate (First 24 hours)
- [ ] **Monitor error logs** for issues
- [ ] **Test all major features** thoroughly
- [ ] **Check site speed** with tools like GTmetrix
- [ ] **Verify SEO basics** (meta tags, titles)
- [ ] **Set up uptime monitoring**

### Within First Week
- [ ] **Configure backups** (database and files)
- [ ] **Set up analytics** (Google Analytics)
- [ ] **Monitor API usage** and quotas
- [ ] **Test email functionality** if needed
- [ ] **Document any custom configurations**

### Ongoing Maintenance
- [ ] **Regular backups** scheduled
- [ ] **Monitor storage space** usage
- [ ] **Update dependencies** when needed
- [ ] **Clear logs** periodically
- [ ] **Monitor API rate limits**

---

## Emergency Contacts & Resources 📞

### Technical Support
- **Hosting Provider Support**: [Your hosting provider's contact]
- **Football-data.org**: https://www.football-data.org/contact
- **Giphy Support**: https://support.giphy.com

### Documentation
- **Laravel Docs**: https://laravel.com/docs
- **Deployment Guide**: `DEPLOYMENT_GUIDE.md`
- **cPanel Manual**: [Your hosting provider's cPanel guide]

---

## Quick Reference Commands 💻

```bash
# Production optimization
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

# Clear caches (for updates)
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Check application status
php artisan about
php artisan route:list
```

---

## Rollback Plan 🔄

In case of issues:
1. **Backup current files** before any changes
2. **Restore previous working version**
3. **Clear all caches**: `php artisan config:clear && php artisan cache:clear`
4. **Check error logs**: `tail -f storage/logs/laravel.log`
5. **Test step by step** to identify issue

---

**✅ Once all items are checked, your application will be successfully deployed to production!**