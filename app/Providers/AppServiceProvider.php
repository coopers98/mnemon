<?php

namespace App\Providers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Observers\DrawerObserver;
use App\Observers\WikiPageObserver;
use App\Services\EmbeddingManager;
use Illuminate\Support\ServiceProvider;
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
            'palace.read'  => 'Read drawers and palace metadata',
            'palace.write' => 'Add drawers',
            'wiki.read'    => 'Read wiki pages, history, graph',
            'wiki.write'   => 'Compile, lint, and write wiki pages',
        ]);

        Passport::authorizationView(fn ($p) => view('mcp.authorize', $p));
    }
}
