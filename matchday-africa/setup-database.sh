#!/bin/bash

# Matchday Africa Database Setup Script
# This script creates all database tables individually

echo "🗄️  Setting up Matchday Africa Database..."

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Please run this script from the Laravel project root directory"
    exit 1
fi

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "❌ Error: .env file not found. Please create it first."
    exit 1
fi

echo "📋 Creating database tables in order..."

# Core Laravel tables
echo "1️⃣  Creating core Laravel tables..."
php artisan migrate --path=database/migrations/0001_01_01_000000_create_users_table.php --force
php artisan migrate --path=database/migrations/0001_01_01_000001_create_cache_table.php --force
php artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php --force

# Football data tables
echo "2️⃣  Creating football data tables..."
php artisan migrate --path=database/migrations/2025_07_31_230509_create_leagues_table.php --force
php artisan migrate --path=database/migrations/2025_07_31_230531_create_teams_table.php --force
php artisan migrate --path=database/migrations/2025_07_31_230555_create_matches_table.php --force
php artisan migrate --path=database/migrations/2025_07_31_230601_create_match_events_table.php --force
php artisan migrate --path=database/migrations/2025_07_31_230620_create_standings_table.php --force
php artisan migrate --path=database/migrations/2025_08_01_152400_create_players_table.php --force

# User interaction tables
echo "3️⃣  Creating user interaction tables..."
php artisan migrate --path=database/migrations/2025_07_31_230830_create_user_favorites_table.php --force
php artisan migrate --path=database/migrations/2025_08_01_002038_create_match_chats_table.php --force
php artisan migrate --path=database/migrations/2025_08_01_010342_add_mentions_to_match_chats_table.php --force

# Blog system
echo "4️⃣  Creating blog system tables..."
php artisan migrate --path=database/migrations/2025_08_14_204115_create_blogs_table.php --force

# Prediction system
echo "5️⃣  Creating prediction system tables..."
php artisan migrate --path=database/migrations/2025_08_15_132759_create_prediction_matches_table.php --force
php artisan migrate --path=database/migrations/2025_08_15_132809_create_predictions_table.php --force
php artisan migrate --path=database/migrations/2025_08_15_132814_create_prediction_results_table.php --force
php artisan migrate --path=database/migrations/2025_08_15_132818_add_prediction_fields_to_football_matches_table.php --force
php artisan migrate --path=database/migrations/2025_08_15_132820_create_prediction_leaderboards_table.php --force
php artisan migrate --path=database/migrations/2025_08_15_132825_add_prediction_fields_to_users_table.php --force

# User roles
echo "6️⃣  Adding user roles..."
php artisan migrate --path=database/migrations/2025_09_09_210802_add_role_to_users_table.php --force

# Social media features (NEW)
echo "7️⃣  Creating social media tables..."
php artisan migrate --path=database/migrations/2025_09_11_110619_create_social_accounts_table.php --force
php artisan migrate --path=database/migrations/2025_09_11_110631_create_social_shares_table.php --force

# Match previews (NEW)
echo "8️⃣  Creating match preview tables..."
php artisan migrate --path=database/migrations/2025_01_15_000000_create_match_previews_table.php --force
php artisan migrate --path=database/migrations/2025_01_15_000001_add_has_preview_to_matches_table.php --force

echo "✅ Database setup completed successfully!"
echo ""
echo "📊 Tables created:"
echo "   - Core Laravel tables (users, cache, jobs)"
echo "   - Football data tables (leagues, teams, matches, events, standings, players)"
echo "   - User interaction tables (favorites, chats, mentions)"
echo "   - Blog system tables"
echo "   - Prediction system tables"
echo "   - User roles"
echo "   - Social media tables (social_accounts, social_shares)"
echo "   - Match preview tables"
echo ""
echo "🎉 Your database is ready for Matchday Africa!"
