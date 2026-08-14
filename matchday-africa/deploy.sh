#!/bin/bash

# Matchday Africa - Prediction System Deployment Script
# Run this script on your production server after uploading files

echo "🚀 Starting Matchday Africa Prediction System Deployment..."

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Please run this script from the Laravel root directory"
    exit 1
fi

# Clear all caches
echo "🧹 Clearing caches..."
php artisan config:clear
php artisan view:clear
php artisan cache:clear
php artisan route:clear
php artisan queue:clear

# Run database migrations (if any new ones exist)
echo "📊 Running database migrations..."
php artisan migrate --force

# Create storage symlink if it doesn't exist
echo "🔗 Creating storage symlink..."
php artisan storage:link

# Set proper permissions
echo "🔐 Setting permissions..."
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Clear and optimize
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Test basic functionality
echo "🧪 Testing basic functionality..."
php artisan tinker --execute="echo 'Testing database connection...'; try { \App\Models\User::count(); echo 'Database connection: OK'; } catch(Exception \$e) { echo 'Database error: ' . \$e->getMessage(); }"

echo "✅ Deployment completed successfully!"
echo ""
echo "📋 Next steps:"
echo "1. Test admin login: admin@matchday-africa.com / password"
echo "2. Test user login: user@matchday-africa.com / password"
echo "3. Check admin prediction management"
echo "4. Check user prediction features"
echo "5. Monitor error logs for any issues"
echo ""
echo "🎯 Prediction System is now live!"
