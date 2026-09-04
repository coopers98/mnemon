<?php

namespace App\Providers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Observers\DrawerObserver;
use App\Observers\WikiPageObserver;
use App\Passport\GuardsMalformedClientIds;
use App\Services\Digest\OpenAiDigestDriver;
use App\Services\DrawerWriteService;
use App\Services\EmbeddingManager;
use App\Services\SessionDigestService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmbeddingManager::class);

        $this->app->bind(SessionDigestService::class, function ($app) {
            $driver = match (config('mnemon.digest.driver', 'openai')) {
                'openai' => new OpenAiDigestDriver,
                default => throw new \RuntimeException('Unknown digest driver: '.config('mnemon.digest.driver')),
            };

            return new SessionDigestService(
                $driver,
                $app->make(DrawerWriteService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Drawer::observe(DrawerObserver::class);
        WikiPage::observe(WikiPageObserver::class);

        // Reject malformed client ids before they reach a uuid-typed column.
        // Without this, /oauth/authorize, the refresh_token grant and
        // /oauth/device/code all answer a bad client id with a 500.
        $this->app->singleton(
            ClientRepository::class,
            GuardsMalformedClientIds::class,
        );

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
        Passport::personalAccessTokensExpireIn(now()->addDays(90));

        Passport::authorizationView(fn ($p) => view('mcp.authorize', array_merge($p, [
            'wings' => Wing::orderBy('slug')->get(),
        ])));

        RateLimiter::for('mcp', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->currentAccessToken()->id ?? $request->ip())
                : Limit::perMinute(20)->by($request->ip());
        });
    }
}
