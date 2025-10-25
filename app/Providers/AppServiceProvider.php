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
        // Регистрация RateLimitTracker (singleton)
        $this->app->singleton(\App\Services\RateLimitTracker::class, function ($app) {
            return new \App\Services\RateLimitTracker();
        });

        // Регистрация MoySkladService с ApiLogService и RateLimitTracker (singleton для сохранения контекста)
        $this->app->singleton(\App\Services\MoySkladService::class, function ($app) {
            return new \App\Services\MoySkladService(
                $app->make(\App\Services\RateLimitHandler::class),
                $app->make(\App\Services\RateLimitTracker::class),
                $app->make(\App\Services\ApiLogService::class)
            );
        });

        // Регистрация TaskDispatcher с handlers (singleton)
        $this->app->singleton(\App\Services\Sync\TaskDispatcher::class, function ($app) {
            $dispatcher = new \App\Services\Sync\TaskDispatcher();

            // Зарегистрировать все handlers
            $dispatcher->registerHandlers([
                $app->make(\App\Services\Sync\Handlers\ProductSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\BatchProductSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\VariantSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\BatchVariantSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\ServiceSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\BatchServiceSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\BundleSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\BatchBundleSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\CustomerOrderSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\RetailDemandSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\PurchaseOrderSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\ImageSyncHandler::class),
                $app->make(\App\Services\Sync\Handlers\WebhookCheckHandler::class),
            ]);

            return $dispatcher;
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
