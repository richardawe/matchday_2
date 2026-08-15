<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'premium_price' => env('STRIPE_PREMIUM_PRICE_ID'),
        'creator_share' => (int) env('CREATOR_REVENUE_SHARE', 10),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Football-Data API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the football-data.org API integration.
    | Get your API key from: https://www.football-data.org
    |
    */

    'football_data' => [
        'url' => env('FOOTBALL_DATA_BASE_URL', 'https://api.football-data.org/v4'),
        'key' => env('FOOTBALL_DATA_API_KEY'),
        'cache_duration' => env('FOOTBALL_DATA_CACHE_DURATION', 300), // 5 minutes default
        'max_requests_per_minute' => env('FOOTBALL_DATA_MAX_REQUESTS_PER_MINUTE', 10), // Free tier limit
    ],

    'giphy' => [
        'api_key' => env('GIPHY_API_KEY'),
        'base_url' => 'https://api.giphy.com/v1',
        'rating' => 'pg-13', // Content rating: g, pg, pg-13, r
        'limit' => 20, // Number of GIFs to return per search
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
        'fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('OPENROUTER_FALLBACK_MODELS', ''))
        ))),
        'max_daily_requests' => env('OPENROUTER_MAX_DAILY_REQUESTS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | The Odds API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for The Odds API integration for betting odds.
    | Get your API key from: https://the-odds-api.com
    |
    */

    'odds' => [
        'api_key' => env('ODDS_API_KEY'),
        'api_url' => env('ODDS_API_URL', 'https://api.the-odds-api.com/v4'),
        'regions' => env('ODDS_API_REGIONS', 'us,uk,eu,au'),
        'markets' => env('ODDS_API_MARKETS', 'h2h,spreads,totals'),
        'cache_duration' => env('ODDS_CACHE_DURATION', 3600), // 1 hour
        'max_requests_per_minute' => env('ODDS_MAX_REQUESTS_PER_MINUTE', 10),
        'max_retries' => env('ODDS_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Authentication Services
    |--------------------------------------------------------------------------
    |
    | Configuration for social authentication providers.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/auth/google/callback'),
    ],

    'twitter' => [
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect' => env('TWITTER_REDIRECT_URI', 'http://localhost:8000/auth/twitter/callback'),
    ],

    'twitter_api' => [
        'api_key' => env('TWITTER_API_KEY'),
        'api_secret' => env('TWITTER_API_SECRET'),
        'access_token' => env('TWITTER_ACCESS_TOKEN'),
        'access_token_secret' => env('TWITTER_ACCESS_TOKEN_SECRET'),
        'bearer_token' => env('TWITTER_BEARER_TOKEN'),
        'username' => 'matchdayafrica',
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Media Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for social media sharing and integration.
    |
    */

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

];
