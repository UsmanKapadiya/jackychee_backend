<?php

namespace App\Providers;

use App\Services\HtmlSanitizer;
use App\Services\ImageService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(ImageService::class);
    }

    public function boot(): void
    {
        // Catch lazy-loading / mass-assignment mistakes during development
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Strong admin passwords: 12+ chars, mixed case, numbers, symbols, not in a known breach list
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Brute-force protection for the admin login
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) ($request->input('login') ?? $request->input('email'))).'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
