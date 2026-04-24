<?php

namespace App\Providers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Observers\DrawerObserver;
use App\Observers\WikiPageObserver;
use App\Services\EmbeddingManager;
use Illuminate\Support\ServiceProvider;

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
    }
}
