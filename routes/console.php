<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessSyncQueueJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Запускать обработку очереди синхронизации каждую минуту
Schedule::job(new ProcessSyncQueueJob)->everyMinute()->withoutOverlapping();
