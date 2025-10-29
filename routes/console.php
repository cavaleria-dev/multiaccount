<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessSyncQueueJob;
use App\Jobs\CheckWebhookHealthJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Запускать обработку очереди синхронизации каждую минуту
// withoutOverlapping(5) - если Job не завершился за 5 минут, разрешить новый запуск
Schedule::job(new ProcessSyncQueueJob)->everyMinute()->withoutOverlapping(5);

// Очистка временных файлов изображений (старше 24 часов) - запускать ежедневно в 3:00
Schedule::command('sync:cleanup-temp-images')->dailyAt('03:00');

// Проверка здоровья вебхуков всех аккаунтов каждый час с auto-healing
// withoutOverlapping(10) - если проверка не завершилась за 10 минут, разрешить новый запуск
Schedule::job(new CheckWebhookHealthJob(null, true))
    ->hourly()
    ->withoutOverlapping(10)
    ->name('webhook-health-check')
    ->onFailure(function () {
        \Log::error('Scheduled webhook health check failed');
    });
