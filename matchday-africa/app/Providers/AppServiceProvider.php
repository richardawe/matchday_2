<?php

namespace App\Providers;

use App\Models\FootballMatch;
use App\Models\User;
use App\Support\Attribution;
use App\Observers\FootballMatchObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($path = config('app.public_path')) {
            $oldPublic = $this->app->publicPath();
            $this->app->usePublicPath($path);
            // filesystems.php was loaded before providers, so rebind its default link too.
            $links = config('filesystems.links', []);
            unset($links[$oldPublic.'/storage'], $links[base_path('public/storage')]);
            $links[$path.'/storage'] = storage_path('app/public');
            config(['filesystems.links' => $links]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        FootballMatch::observe(FootballMatchObserver::class);

        // Every signup path (email, Google, X) stores the visitor's first-touch source.
        User::creating(function (User $user) {
            foreach (Attribution::forUser() as $key => $value) {
                $user->{$key} ??= $value;
            }
        });
    }
}
