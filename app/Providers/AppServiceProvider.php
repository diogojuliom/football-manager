<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
        // Recovery links use the configured origin, never the untrusted request Host header.
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], false));
    }
}
