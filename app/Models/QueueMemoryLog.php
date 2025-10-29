<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueueMemoryLog extends Model
{
    use HasFactory;

    protected $table = 'queue_memory_logs';

    public $timestamps = false; // Используем logged_at вместо created_at/updated_at

    protected $fillable = [
        'job_id',
        'batch_index',
        'task_count',
        'entity_type',
        'memory_current_mb',
        'memory_peak_mb',
        'memory_limit_mb',
        'duration_ms',
        'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'memory_current_mb' => 'float',
        'memory_peak_mb' => 'float',
        'memory_limit_mb' => 'float',
        'duration_ms' => 'integer',
        'batch_index' => 'integer',
        'task_count' => 'integer',
    ];

    /**
     * Scope для получения логов за период
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('logged_at', [$startDate, $endDate]);
    }

    /**
     * Scope для получения логов конкретного job
     */
    public function scopeByJobId($query, string $jobId)
    {
        return $query->where('job_id', $jobId);
    }

    /**
     * Scope для получения логов с памятью больше указанной
     */
    public function scopeHighMemory($query, float $thresholdMb = 300.0)
    {
        return $query->where('memory_current_mb', '>=', $thresholdMb);
    }

    /**
     * Scope для получения логов конкретного типа сущности
     */
    public function scopeForEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Проверить, превышен ли критический порог памяти (400MB)
     */
    public function isCriticalMemory(): bool
    {
        return $this->memory_peak_mb >= 400.0;
    }

    /**
     * Проверить, превышен ли предупреждающий порог памяти (300MB)
     */
    public function isWarningMemory(): bool
    {
        return $this->memory_peak_mb >= 300.0 && $this->memory_peak_mb < 400.0;
    }

    /**
     * Получить CSS класс для цветового кодирования
     */
    public function getMemoryColorClass(): string
    {
        if ($this->isCriticalMemory()) {
            return 'text-red-600 font-bold';
        }

        if ($this->isWarningMemory()) {
            return 'text-yellow-600';
        }

        return 'text-gray-900';
    }

    /**
     * Получить emoji индикатор для памяти
     */
    public function getMemoryIndicator(): string
    {
        if ($this->isCriticalMemory()) {
            return '🔴';
        }

        if ($this->isWarningMemory()) {
            return '🟡';
        }

        return '🟢';
    }
}
