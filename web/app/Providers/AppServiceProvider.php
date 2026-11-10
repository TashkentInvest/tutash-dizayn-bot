<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
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
        FilamentTimezone::set(config('app.display_timezone'));

        // Same rule as the panel: the photo/file routes are for allow-listed admins only (not any row of `users`).
        // Mini App: limited per Telegram user (many staff can share one mobile carrier IP), answered in Uzbek
        RateLimiter::for('tg', fn (Request $request) => Limit::perMinute(120)
            ->by('tg:'.($request->attributes->get('tg_user')['id'] ?? $request->ip()))
            ->response(fn () => response()->json(['error' => 'Juda ko‘p so‘rov. Bir daqiqadan keyin qayta urinib ko‘ring.'], 429)));

        Gate::define('access-admin', fn ($user) => in_array(strtolower($user->email), array_map('strtolower', config('app.admin_emails', [])), true));
    }
}
