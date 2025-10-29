@extends('admin.layout')

@section('title', 'Memory Logs')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold">Логи использования памяти</h2>
    <p class="text-gray-600 mt-1">Мониторинг памяти queue worker для отслеживания memory leaks</p>
</div>

@if(isset($error))
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ $error }}</div>
@elseif($logs)

{{-- Статистика --}}
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-gray-500 uppercase">Средняя память</h3>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $stats['avg_memory'] ?? 0 }} MB</p>
    </div>

    <div class="bg-red-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Пиковая память</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ $stats['max_memory'] ?? 0 }} MB</p>
    </div>

    <div class="bg-blue-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-blue-800 uppercase">Всего логов</h3>
        <p class="text-3xl font-bold text-blue-900 mt-2">{{ number_format($stats['total_logs'] ?? 0) }}</p>
    </div>

    <div class="bg-red-100 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Критичные (>400MB)</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ number_format($stats['critical_count'] ?? 0) }}</p>
    </div>

    <div class="bg-yellow-100 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-yellow-800 uppercase">Предупреждения (>300MB)</h3>
        <p class="text-3xl font-bold text-yellow-900 mt-2">{{ number_format($stats['warning_count'] ?? 0) }}</p>
    </div>
</div>

{{-- Фильтры --}}
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">Фильтры</h3>
    <form action="{{ route('admin.memory.index') }}" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Дата от</label>
            <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}"
                   class="w-full px-3 py-2 border border-gray-300 rounded">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Дата до</label>
            <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}"
                   class="w-full px-3 py-2 border border-gray-300 rounded">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Job ID</label>
            <input type="text" name="job_id" value="{{ $filters['job_id'] ?? '' }}"
                   placeholder="Поиск по Job ID"
                   class="w-full px-3 py-2 border border-gray-300 rounded">
        </div>

        <div class="flex items-end">
            <label class="inline-flex items-center">
                <input type="checkbox" name="high_memory_only" value="1"
                       {{ isset($filters['high_memory_only']) && $filters['high_memory_only'] ? 'checked' : '' }}
                       class="form-checkbox h-5 w-5 text-indigo-600">
                <span class="ml-2 text-sm text-gray-700">Только >300MB</span>
            </label>
        </div>

        <div class="md:col-span-4 flex gap-2">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded">
                Применить
            </button>
            <a href="{{ route('admin.memory.index') }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded">
                Сбросить
            </a>
        </div>
    </form>
</div>

{{-- Таблица логов --}}
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job ID</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Batch</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Tasks</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Current Memory</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Peak Memory</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Timestamp</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.memory.show', $log->job_id) }}"
                       class="text-indigo-600 hover:text-indigo-900 font-mono">
                        {{ $log->getMemoryIndicator() }} {{ substr($log->job_id, 0, 16) }}...
                    </a>
                </td>
                <td class="px-6 py-4 text-sm text-center">
                    @if($log->batch_index == 0)
                        <span class="text-green-600 font-semibold">START</span>
                    @elseif($log->batch_index == -1)
                        <span class="text-blue-600 font-semibold">END</span>
                    @else
                        {{ $log->batch_index }}
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-center">{{ $log->task_count }}</td>
                <td class="px-6 py-4 text-sm text-right">
                    <span class="font-mono {{ $log->memory_current_mb > 300 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($log->memory_current_mb, 1) }} MB
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-right">
                    <span class="font-mono {{ $log->getMemoryColorClass() }}">
                        {{ number_format($log->memory_peak_mb, 1) }} MB
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-center text-gray-500">
                    {{ $log->logged_at->format('Y-m-d H:i:s') }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                    Логи памяти не найдены. Попробуйте изменить фильтры.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Пагинация --}}
<div class="mt-4">
    {{ $logs->withQueryString()->links() }}
</div>

@endif
@endsection
