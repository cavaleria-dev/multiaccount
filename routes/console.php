<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessSyncQueueJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Запускать обработку очереди синхронизации каждую минуту
// withoutOverlapping(5) - если Job не завершился за 5 минут, разрешить новый запуск
Schedule::job(new ProcessSyncQueueJob)->everyMinute()->withoutOverlapping(5);
