<?php

namespace App\Providers;

use App\Console\Commands\PurgeTokens;
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
        // Registered in boot, after every provider's register(), so this wins the
        // name over Passport's own purge command. See PurgeTokens for why the
        // stock one is unsafe here.
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTokens::class]);
        }

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

        // Non-rotating refresh tokens. Under rotation League revokes the old
        // refresh token before the device has stored the new one, so a response
        // lost on the wire leaves the device holding a consumed token:
        // deterministic invalid_grant, and a headless machine needs a browser to
        // recover. Each exchange still issues a fresh refresh token, so devices
        // self-renew and the 90-day window still slides; only the revocation of
        // the superseded token is skipped. The cost is that superseded tokens
        // stay valid until their own expiry, which makes client revocation
        // (TokenRevoker::client) the reliable per-device kill switch.
        Passport::$revokeRefreshTokenAfterUse = false;

        // Passport 13 ships no device views. Without these two bindings the
        // container cannot resolve the response contracts and every device
        // screen is a 500 — which is what /oauth/device did in production.
        Passport::deviceUserCodeView('mcp.device-user-code');
        Passport::deviceAuthorizationView(fn ($p) => view('mcp.device-authorize', array_merge($p, [
            'wings' => Wing::orderBy('slug')->get(),
        ])));

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
