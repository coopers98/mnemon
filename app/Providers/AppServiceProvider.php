<?php

namespace App\Providers;

use App\Listeners\PersistMcpTokenRestrictions;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Observers\DrawerObserver;
use App\Observers\WikiPageObserver;
use App\Services\EmbeddingManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmbeddingManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Drawer::observe(DrawerObserver::class);
        WikiPage::observe(WikiPageObserver::class);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
        Passport::personalAccessTokensExpireIn(now()->addDays(90));

        Passport::tokensCan([
            'palace.read' => 'Read drawers and palace metadata',
            'palace.write' => 'Add drawers',
            'wiki.read' => 'Read wiki pages, history, graph',
            'wiki.write' => 'Compile, lint, and write wiki pages',
        ]);

        Passport::authorizationView(fn ($p) => view('mcp.authorize', array_merge($p, [
            'wings' => Wing::orderBy('slug')->get(),
        ])));

        Event::listen(
            AccessTokenCreated::class,
            PersistMcpTokenRestrictions::class
        );

        RateLimiter::for('mcp', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->currentAccessToken()->id ?? $request->ip())
                : Limit::perMinute(20)->by($request->ip());
        });
    }
}
