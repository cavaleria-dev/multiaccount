<?php

namespace App\Providers;

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
        // Inject циклические зависимости для синхронизационных сервисов
        // ProductSyncService → VariantSyncService → ProductSyncService (circular)
        // ProductSyncService → BundleSyncService → ProductSyncService (circular)

        $this->app->resolving(\App\Services\ProductSyncService::class, function ($productSyncService, $app) {
            if ($app->bound(\App\Services\VariantSyncService::class)) {
                $productSyncService->setVariantSyncService($app->make(\App\Services\VariantSyncService::class));
            }

            if ($app->bound(\App\Services\BundleSyncService::class)) {
                $productSyncService->setBundleSyncService($app->make(\App\Services\BundleSyncService::class));
            }
        });
    }
}
