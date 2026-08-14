<?php

namespace App\Providers;

use App\Models\FootballMatch;
use App\Observers\FootballMatchObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        FootballMatch::observe(FootballMatchObserver::class);
    }
}
