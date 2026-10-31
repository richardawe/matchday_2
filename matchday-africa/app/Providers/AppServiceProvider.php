<?php

namespace App\Providers;

use App\Models\FootballMatch;
use App\Models\User;
use App\Services\GuestPredictions;
use App\Support\Attribution;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        // A visitor's guest picks and league invite follow them into their account.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                try {
                    app(GuestPredictions::class)->claim($event->user, request());
                } catch (\Throwable $e) {
                    Log::warning('Guest predictions could not be claimed', ['user_id' => $event->user->id, 'error' => $e->getMessage()]);
                }
            }
        });

        // Every signup path (email, Google, X) stores the visitor's first-touch source.
        User::creating(function (User $user) {
            foreach (Attribution::forUser() as $key => $value) {
                $user->{$key} ??= $value;
            }
        });
    }
}
