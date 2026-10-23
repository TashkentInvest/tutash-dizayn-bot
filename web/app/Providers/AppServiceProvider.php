<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentTimezone;
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
        Gate::define('access-admin', fn ($user) => in_array(strtolower($user->email), array_map('strtolower', config('app.admin_emails', [])), true));
    }
}
