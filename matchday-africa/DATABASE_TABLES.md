# 🗄️ Matchday Africa - Database Tables Reference

## Overview
This document lists all database tables in the Matchday Africa application, including the new social media and match preview features.

## Core Laravel Tables
- **users** - User accounts and authentication
- **cache** - Application caching
- **jobs** - Queue job management

## Football Data Tables
- **leagues** - Football leagues (Premier League, La Liga, etc.)
- **teams** - Football teams
- **football_matches** - Match fixtures and results
- **match_events** - Match events (goals, cards, substitutions)
- **standings** - League standings
- **players** - Player information

## User Interaction Tables
- **user_favorites** - User's favorite teams/leagues
- **match_chats** - Live match chat messages
- **match_chat_mentions** - User mentions in chat

## Content Management
- **blogs** - Blog articles and news

## Prediction System
- **prediction_matches** - Matches available for prediction
- **predictions** - User predictions
- **prediction_results** - Prediction scoring results
- **prediction_leaderboards** - User rankings

## NEW: Social Media Features
- **social_accounts** - OAuth social media accounts (Google, Twitter)
- **social_shares** - Track social media shares

## NEW: AI Match Previews
- **match_previews** - AI-generated match previews
- **football_matches.has_preview** - Flag indicating if match has AI preview

## Table Creation Commands

### Quick Setup (All Tables)
```bash
php artisan migrate --force
```

### Individual Table Creation
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

### Automated Setup Script
```bash
chmod +x setup-database.sh
./setup-database.sh
```

## New Features Tables

### Social Accounts Table
Stores OAuth social media account connections:
- `user_id` - Links to users table
- `provider` - OAuth provider (google, twitter)
- `provider_id` - Provider's user ID
- `provider_token` - OAuth access token
- `provider_refresh_token` - OAuth refresh token
- `provider_token_expires_at` - Token expiration

### Social Shares Table
Tracks social media sharing activity:
- `user_id` - User who shared (nullable for anonymous)
- `shareable_type` - Type of content shared (match, blog)
- `shareable_id` - ID of the shared content
- `platform` - Social platform (facebook, twitter, linkedin, whatsapp)
- `share_url` - URL that was shared
- `shared_at` - When the share occurred

### Match Previews Table
Stores AI-generated match previews:
- `match_id` - Links to football_matches table
- `title` - Preview title
- `content` - AI-generated preview content
- `generation_status` - Status of generation (pending, completed, failed)
- `featured` - Whether preview is featured
- `generated_at` - When preview was generated
- `is_active` - Whether preview is active

## Database Relationships

### User Relationships
- Users can have multiple social accounts
- Users can have multiple predictions
- Users can have multiple favorites
- Users can have multiple chat messages

### Match Relationships
- Matches belong to leagues
- Matches have home and away teams
- Matches can have multiple events
- Matches can have AI previews
- Matches can be shared on social media

### Content Relationships
- Blogs can be shared on social media
- Matches can be shared on social media
- All shareable content is tracked in social_shares table

## Production Notes

1. **Indexes**: All tables have proper indexes for performance
2. **Foreign Keys**: Proper foreign key constraints with cascade deletes
3. **Unique Constraints**: Prevent duplicate social accounts per provider
4. **Timestamps**: All tables have created_at and updated_at timestamps
5. **Nullable Fields**: Appropriate fields are nullable for flexibility

## Support

For database issues or questions:
- **Email**: info@3d7tech.com
- **Logs**: Check `storage/logs/laravel.log`
- **Migration Status**: Run `php artisan migrate:status`
