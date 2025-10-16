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
        // Регистрация MoySkladService с ApiLogService (singleton для сохранения контекста)
        $this->app->singleton(\App\Services\MoySkladService::class, function ($app) {
            return new \App\Services\MoySkladService(
                $app->make(\App\Services\RateLimitHandler::class),
                $app->make(\App\Services\ApiLogService::class)
            );
        });
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
