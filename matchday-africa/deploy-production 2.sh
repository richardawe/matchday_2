#!/bin/bash

# Matchday Africa Production Deployment Script
# Run this script on the production server

echo "🚀 Starting Matchday Africa Production Deployment..."

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Please run this script from the Laravel project root directory"
    exit 1
fi

# Backup current .env if it exists
if [ -f ".env" ]; then
    echo "📦 Backing up current .env file..."
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
fi

# Set up production environment
echo "⚙️  Setting up production environment..."
if [ -f ".env.production" ]; then
    cp .env.production .env
    echo "✅ Production environment file activated"
else
    echo "❌ Error: .env.production file not found"
    exit 1
fi

# Install/Update Composer dependencies
echo "📦 Installing Composer dependencies..."
composer install --optimize-autoloader --no-dev --no-interaction

# Generate application key if not set
echo "🔑 Generating application key..."
php artisan key:generate --force

# Clear all caches
echo "🧹 Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Run database migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link

# Cache configuration for production
echo "⚡ Caching configuration for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set proper permissions
echo "🔐 Setting file permissions..."
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
chmod 644 .env

# Optimize autoloader
echo "🚀 Optimizing autoloader..."
composer dump-autoload --optimize

echo "✅ Deployment completed successfully!"
echo ""
echo "📋 Next steps:"
echo "1. Update OAuth redirect URIs in Google Cloud Console and Twitter Developer Portal"
echo "2. Set up SSL certificate in cPanel"
echo "3. Configure cron jobs for scheduled tasks"
echo "4. Test the application thoroughly"
echo ""
echo "🔗 OAuth URLs to update:"
echo "Google: https://matchday.africa/oauth-callback"
echo "Twitter: https://matchday.africa/auth/twitter/callback"
echo ""
echo "📧 For support: info@3d7tech.com"
