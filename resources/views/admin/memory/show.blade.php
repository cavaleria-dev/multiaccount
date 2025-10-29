@extends('admin.layout')

@section('title', 'Memory Log Details')

@section('content')
<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold">Детали Job: <span class="font-mono text-indigo-600">{{ $jobId }}</span></h2>
            <p class="text-gray-600 mt-1">Подробная информация об использовании памяти</p>
        </div>
        <a href="{{ route('admin.memory.index') }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded">
            ← Назад к списку
        </a>
    </div>
</div>

{{-- Статистика по job --}}
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-gray-500 uppercase">Всего задач</h3>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $stats['total_tasks'] }}</p>
    </div>

    <div class="bg-blue-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-blue-800 uppercase">Средняя память</h3>
        <p class="text-3xl font-bold text-blue-900 mt-2">{{ $stats['avg_memory'] }} MB</p>
    </div>

    <div class="bg-red-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Максимум</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ $stats['max_memory'] }} MB</p>
    </div>

    <div class="bg-green-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-green-800 uppercase">Минимум</h3>
        <p class="text-3xl font-bold text-green-900 mt-2">{{ $stats['min_memory'] }} MB</p>
    </div>
</div>

{{-- Время выполнения --}}
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">Время выполнения</h3>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <span class="text-sm text-gray-500">Начало:</span>
            <span class="font-semibold ml-2">{{ $stats['started_at']->format('Y-m-d H:i:s') }}</span>
        </div>
        <div>
            <span class="text-sm text-gray-500">Завершение:</span>
            <span class="font-semibold ml-2">{{ $stats['completed_at']->format('Y-m-d H:i:s') }}</span>
        </div>
        <div>
            <span class="text-sm text-gray-500">Длительность:</span>
            <span class="font-semibold ml-2">
                {{ $stats['started_at']->diffInSeconds($stats['completed_at']) }} секунд
            </span>
        </div>
        <div>
            <span class="text-sm text-gray-500">Чекпоинтов:</span>
            <span class="font-semibold ml-2">{{ $stats['checkpoints'] }}</span>
        </div>
    </div>
</div>

{{-- График памяти (простая визуализация) --}}
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">График использования памяти</h3>

    <div class="space-y-3">
        @foreach($logs as $log)
            @php
                $percentage = ($log->memory_peak_mb / 512) * 100; // Assuming 512MB limit
                $color = $log->isCriticalMemory() ? 'bg-red-500' :
                         ($log->isWarningMemory() ? 'bg-yellow-500' : 'bg-green-500');
            @endphp

            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-gray-700">
                        @if($log->batch_index == 0)
                            START
                        @elseif($log->batch_index == -1)
                            END
                        @else
                            Batch {{ $log->batch_index }}
                        @endif
                    </span>
                    <span class="text-sm text-gray-600">
                        {{ number_format($log->memory_peak_mb, 1) }} MB
                    </span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-4">
                    <div class="{{ $color }} h-4 rounded-full" style="width: {{ min($percentage, 100) }}%"></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-gray-500">
        <span>0 MB</span>
        <span>256 MB</span>
        <span>512 MB</span>
    </div>
</div>

{{-- Таблица детальных логов --}}
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Checkpoint</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Tasks</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Current MB</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Peak MB</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Limit MB</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Timestamp</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($logs as $log)
            <tr class="hover:bg-gray-50 {{ $log->isCriticalMemory() ? 'bg-red-50' : ($log->isWarningMemory() ? 'bg-yellow-50' : '') }}">
                <td class="px-6 py-4 text-sm font-medium">
                    {{ $log->getMemoryIndicator() }}
                    @if($log->batch_index == 0)
                        <span class="text-green-600">START</span>
                    @elseif($log->batch_index == -1)
                        <span class="text-blue-600">END</span>
                    @else
                        Batch {{ $log->batch_index }}
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-center">{{ $log->task_count }}</td>
                <td class="px-6 py-4 text-sm text-right font-mono {{ $log->memory_current_mb > 300 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ number_format($log->memory_current_mb, 2) }}
                </td>
                <td class="px-6 py-4 text-sm text-right font-mono {{ $log->getMemoryColorClass() }}">
                    {{ number_format($log->memory_peak_mb, 2) }}
                </td>
                <td class="px-6 py-4 text-sm text-right font-mono text-gray-500">
                    {{ number_format($log->memory_limit_mb, 0) }}
                </td>
                <td class="px-6 py-4 text-sm text-center text-gray-500">
                    {{ $log->logged_at->format('H:i:s') }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Предупреждения --}}
@if($stats['max_memory'] > 400)
<div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded mt-6">
    <h4 class="font-bold mb-2">🔴 КРИТИЧНО: Превышен предел памяти!</h4>
    <p>Максимальное использование памяти ({{ $stats['max_memory'] }} MB) превысило критический порог (400 MB).</p>
    <p class="mt-2">Рекомендуется проверить memory leaks и увеличить частоту garbage collection.</p>
</div>
@elseif($stats['max_memory'] > 300)
<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-6 py-4 rounded mt-6">
    <h4 class="font-bold mb-2">🟡 ПРЕДУПРЕЖДЕНИЕ: Высокое использование памяти</h4>
    <p>Максимальное использование памяти ({{ $stats['max_memory'] }} MB) близко к предупреждающему порогу (300 MB).</p>
    <p class="mt-2">Рекомендуется мониторить ситуацию.</p>
</div>
@else
<div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded mt-6">
    <h4 class="font-bold mb-2">🟢 ОК: Нормальное использование памяти</h4>
    <p>Максимальное использование памяти ({{ $stats['max_memory'] }} MB) в пределах нормы.</p>
</div>
@endif
@endsection
