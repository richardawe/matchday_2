#!/bin/bash

# AI Match Preview System Deployment Script for cPanel
# This script updates the production environment with the new AI match preview system

echo "🚀 Starting AI Match Preview System Deployment..."

# Set variables
PROJECT_DIR="/home/$(whoami)/public_html"
BACKUP_DIR="/home/$(whoami)/backups/$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$BACKUP_DIR/deployment.log"

# Create backup directory
mkdir -p "$BACKUP_DIR"
echo "📁 Backup directory created: $BACKUP_DIR"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Function to check if command succeeded
check_status() {
    if [ $? -eq 0 ]; then
        log_message "✅ $1"
    else
        log_message "❌ $1"
        exit 1
    fi
}

# Navigate to project directory
cd "$PROJECT_DIR"
check_status "Changed to project directory"

# Create backup of current system
log_message "📦 Creating backup of current system..."
tar -czf "$BACKUP_DIR/current_system_backup.tar.gz" --exclude='vendor' --exclude='node_modules' --exclude='.git' .
check_status "System backup created"

# Backup database (if you have database access)
log_message "🗄️ Creating database backup..."
mysqldump -u$(grep DB_USERNAME .env | cut -d'=' -f2) -p$(grep DB_PASSWORD .env | cut -d'=' -f2) $(grep DB_DATABASE .env | cut -d'=' -f2) > "$BACKUP_DIR/database_backup.sql"
if [ $? -eq 0 ]; then
    log_message "✅ Database backup created"
else
    log_message "⚠️ Database backup failed (continuing anyway)"
fi

# Update environment variables
log_message "🔧 Updating environment variables..."
if [ ! -f .env ]; then
    log_message "❌ .env file not found. Please create it manually with the following variables:"
    echo "OPENROUTER_API_KEY=your_api_key_here"
    echo "OPENROUTER_BASE_URL=https://openrouter.ai/api/v1"
    echo "OPENROUTER_MODEL=anthropic/claude-3-haiku"
    echo "OPENROUTER_MAX_DAILY_REQUESTS=1000"
    echo "QUEUE_CONNECTION=database"
    echo "CACHE_DRIVER=file"
    exit 1
fi

# Add OpenRouter configuration if not exists
if ! grep -q "OPENROUTER_API_KEY" .env; then
    echo "" >> .env
    echo "# OpenRouter AI API Configuration" >> .env
    echo "OPENROUTER_API_KEY=your_api_key_here" >> .env
    echo "OPENROUTER_BASE_URL=https://openrouter.ai/api/v1" >> .env
    echo "OPENROUTER_MODEL=anthropic/claude-3-haiku" >> .env
    echo "OPENROUTER_MAX_DAILY_REQUESTS=1000" >> .env
    log_message "✅ OpenRouter environment variables added"
else
    log_message "ℹ️ OpenRouter environment variables already exist"
fi

# Update queue configuration
if ! grep -q "QUEUE_CONNECTION" .env; then
    echo "QUEUE_CONNECTION=database" >> .env
    log_message "✅ Queue configuration added"
fi

# Install/update Composer dependencies
log_message "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader
check_status "Composer dependencies installed"

# Run database migrations
log_message "🗄️ Running database migrations..."
php artisan migrate --force
check_status "Database migrations completed"

# Clear application cache
log_message "🧹 Clearing application cache..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
check_status "Application cache cleared"

# Optimize application
log_message "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
check_status "Application optimized"

# Set proper permissions
log_message "🔐 Setting proper permissions..."
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
chown -R $(whoami):$(whoami) storage/
chown -R $(whoami):$(whoami) bootstrap/cache/
check_status "Permissions set correctly"

# Test the system
log_message "🧪 Testing the system..."
php artisan list | grep -q "previews:generate"
if [ $? -eq 0 ]; then
    log_message "✅ Preview generation command found"
else
    log_message "❌ Preview generation command not found"
fi

# Test OpenRouter connection (if API key is set)
if [ "$(grep OPENROUTER_API_KEY .env | cut -d'=' -f2)" != "your_api_key_here" ]; then
    log_message "🔌 Testing OpenRouter connection..."
    php artisan tinker --execute="app('App\Services\OpenRouterService')->testConnection() ? 'Connection successful' : 'Connection failed'"
else
    log_message "⚠️ OpenRouter API key not set - skipping connection test"
fi

# Create cron job for preview generation (optional)
log_message "⏰ Setting up cron job for preview generation..."
CRON_JOB="*/30 * * * * cd $PROJECT_DIR && php artisan previews:generate --matches=5 --today >> /dev/null 2>&1"
if ! crontab -l 2>/dev/null | grep -q "previews:generate"; then
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    log_message "✅ Cron job created for preview generation"
else
    log_message "ℹ️ Cron job already exists"
fi

# Generate initial previews for today's matches
log_message "🤖 Generating initial previews for today's matches..."
php artisan previews:generate --matches=5 --today --force
if [ $? -eq 0 ]; then
    log_message "✅ Initial previews generated"
else
    log_message "⚠️ Initial preview generation failed (this is normal if no matches exist)"
fi

# Final status check
log_message "🎯 Deployment completed successfully!"
log_message "📊 System status:"
php artisan tinker --execute="echo 'Total previews: ' . App\Models\MatchPreview::count();"
php artisan tinker --execute="echo 'Featured previews: ' . App\Models\MatchPreview::where('is_featured', true)->count();"

echo ""
echo "🎉 AI Match Preview System Deployment Complete!"
echo "📁 Backup location: $BACKUP_DIR"
echo "📋 Log file: $LOG_FILE"
echo ""
echo "🔧 Next steps:"
echo "1. Set your OpenRouter API key in the .env file"
echo "2. Test the system by visiting your website"
echo "3. Check the admin dashboard for preview management"
echo "4. Monitor the logs for any issues"
echo ""
echo "📚 Useful commands:"
echo "- Generate previews: php artisan previews:generate --matches=10 --today"
echo "- Check preview stats: php artisan tinker --execute=\"app('App\Services\MatchPreviewService')->getStats()\""
echo "- View logs: tail -f storage/logs/laravel.log"
echo ""
echo "🚨 Important: Don't forget to set your OpenRouter API key!" 