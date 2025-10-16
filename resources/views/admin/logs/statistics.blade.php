@extends('admin.layout')

@section('title', 'Статистика API')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-bold mb-6">Статистика API запросов</h2>

    {{-- Фильтр по датам --}}
    <form method="GET" action="{{ route('admin.statistics') }}" class="mb-6 flex gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Дата от</label>
            <input type="date" name="start_date" value="{{ $startDate ?? '' }}" class="border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Дата до</label>
            <input type="date" name="end_date" value="{{ $endDate ?? '' }}" class="border rounded px-3 py-2">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
                Применить
            </button>
            <a href="{{ route('admin.statistics') }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded hover:bg-gray-400">
                Сбросить
            </a>
        </div>
    </form>

    @if(isset($error))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{{ $error }}</div>
    @elseif($statistics && $statistics['total_requests'] > 0)
        {{-- Период выборки --}}
        @if($startDate || $endDate)
            <div class="mb-4 text-sm text-gray-600">
                <span class="font-medium">Период:</span>
                @if($startDate && $endDate)
                    с {{ $startDate }} по {{ $endDate }}
                @elseif($startDate)
                    с {{ $startDate }}
                @elseif($endDate)
                    до {{ $endDate }}
                @endif
            </div>
        @else
            <div class="mb-4 text-sm text-gray-600">
                <span class="font-medium">Период:</span> За всё время
            </div>
        @endif

        {{-- Основные метрики --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 p-4 rounded">
                <div class="text-sm text-gray-600">Всего запросов</div>
                <div class="text-2xl font-bold text-blue-600">{{ number_format($statistics['total_requests']) }}</div>
            </div>
            <div class="bg-green-50 p-4 rounded">
                <div class="text-sm text-gray-600">Успешных</div>
                <div class="text-2xl font-bold text-green-600">{{ number_format($statistics['success_requests']) }}</div>
            </div>
            <div class="bg-red-50 p-4 rounded">
                <div class="text-sm text-gray-600">Ошибок</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($statistics['error_requests']) }}</div>
            </div>
            <div class="bg-orange-50 p-4 rounded">
                <div class="text-sm text-gray-600">Rate Limit (429)</div>
                <div class="text-2xl font-bold text-orange-600">{{ number_format($statistics['rate_limit_errors']) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-purple-50 p-4 rounded">
                <div class="text-sm text-gray-600">Процент ошибок</div>
                <div class="text-2xl font-bold text-purple-600">{{ $statistics['error_rate'] }}%</div>
            </div>
            <div class="bg-indigo-50 p-4 rounded">
                <div class="text-sm text-gray-600">Средняя длительность</div>
                <div class="text-2xl font-bold text-indigo-600">{{ $statistics['avg_duration_ms'] }} мс</div>
            </div>
        </div>

        {{-- Ошибки по статусам --}}
        @if($statistics['errors_by_status']->count() > 0)
            <div class="mb-6">
                <h3 class="font-semibold text-lg mb-3">Распределение ошибок по статусам</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">HTTP Статус</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Количество</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($statistics['errors_by_status'] as $stat)
                                <tr>
                                    <td class="px-4 py-2">{{ $stat->response_status }}</td>
                                    <td class="px-4 py-2">{{ $stat->count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        {{-- Нет данных --}}
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-8 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Нет данных за выбранный период</h3>
            <p class="text-gray-500 mb-4">
                @if($startDate || $endDate)
                    Попробуйте изменить фильтры или сбросить их для просмотра всей статистики.
                @else
                    Логи API запросов пока отсутствуют. Они появятся после первых запросов к МойСклад API.
                @endif
            </p>
            @if($startDate || $endDate)
                <a href="{{ route('admin.statistics') }}" class="inline-block bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
                    Показать за всё время
                </a>
            @endif
        </div>
    @endif
</div>
@endsection
