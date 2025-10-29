@extends('admin.layout')

@section('title', 'Детали вебхуков')

@section('content')
<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold">Детали вебхуков аккаунта</h2>
            <p class="text-gray-600 mt-1">{{ $account->account_name ?? $account->account_id }}</p>
        </div>
        <a href="{{ route('admin.webhooks.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400">
            ← Назад к списку
        </a>
    </div>
</div>

{{-- Информация об аккаунте --}}
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">Информация об аккаунте</h3>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <span class="text-sm text-gray-500">Account ID:</span>
            <p class="font-mono text-sm">{{ $account->account_id }}</p>
        </div>
        <div>
            <span class="text-sm text-gray-500">Название:</span>
            <p class="font-medium">{{ $account->account_name ?? 'Unknown' }}</p>
        </div>
        <div>
            <span class="text-sm text-gray-500">Тип:</span>
            <p>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                    {{ $accountType === 'main' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                    {{ $accountType === 'main' ? 'Главный' : 'Дочерний' }}
                </span>
            </p>
        </div>
        <div>
            <span class="text-sm text-gray-500">Общее здоровье:</span>
            <p>
                @php
                    $overallHealth = $healthReport['overall_health'];
                    $colors = [
                        'healthy' => 'bg-green-100 text-green-800',
                        'degraded' => 'bg-yellow-100 text-yellow-800',
                        'warning' => 'bg-orange-100 text-orange-800',
                        'critical' => 'bg-red-100 text-red-800',
                        'unknown' => 'bg-gray-100 text-gray-800',
                    ];
                    $labels = [
                        'healthy' => 'Здоров',
                        'degraded' => 'Деградирован',
                        'warning' => 'Внимание',
                        'critical' => 'Критично',
                        'unknown' => 'Неизвестно',
                    ];
                @endphp
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$overallHealth] ?? 'bg-gray-100 text-gray-800' }}">
                    {{ $labels[$overallHealth] ?? $overallHealth }}
                </span>
            </p>
        </div>
    </div>
</div>

{{-- Карточки статистики --}}
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-gray-500 uppercase">Всего вебхуков</h3>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $healthReport['summary']['total_webhooks'] }}</p>
        <div class="mt-2 text-xs text-gray-500">
            Активных: {{ $healthReport['summary']['active'] }} | Неактивных: {{ $healthReport['summary']['inactive'] }}
        </div>
    </div>

    <div class="bg-green-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-green-800 uppercase">Здоровые</h3>
        <p class="text-3xl font-bold text-green-900 mt-2">{{ $healthReport['summary']['healthy'] }}</p>
    </div>

    <div class="bg-yellow-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-yellow-800 uppercase">Деградированные</h3>
        <p class="text-3xl font-bold text-yellow-900 mt-2">{{ $healthReport['summary']['degraded'] }}</p>
    </div>

    <div class="bg-red-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Критичные</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ $healthReport['summary']['critical'] }}</p>
    </div>
</div>

{{-- Метрики и статистика --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    {{-- Общие метрики --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold mb-4">Общие метрики</h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Всего получено:</span>
                <span class="font-bold">{{ $healthReport['metrics']['total_received'] }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Ошибок:</span>
                <span class="font-bold text-red-600">{{ $healthReport['metrics']['total_failed'] }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Процент ошибок:</span>
                <span class="font-bold {{ $healthReport['metrics']['failure_rate'] < 5 ? 'text-green-600' : 'text-red-600' }}">
                    {{ number_format($healthReport['metrics']['failure_rate'], 2) }}%
                </span>
            </div>
        </div>
    </div>

    {{-- Статистика за 24 часа --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold mb-4">Последние 24 часа</h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Всего обработано:</span>
                <span class="font-bold">{{ $healthReport['recent_24h']['total'] }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Завершено успешно:</span>
                <span class="font-bold text-green-600">{{ $healthReport['recent_24h']['completed'] }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Ошибок:</span>
                <span class="font-bold text-red-600">{{ $healthReport['recent_24h']['failed'] }}</span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">В ожидании:</span>
                <span class="font-bold text-yellow-600">{{ $healthReport['recent_24h']['pending'] }}</span>
            </div>
            <div class="flex justify-between items-center pt-3 border-t">
                <span class="text-gray-600 font-medium">Успешность:</span>
                <span class="font-bold text-lg {{ $healthReport['recent_24h']['success_rate'] >= 95 ? 'text-green-600' : 'text-yellow-600' }}">
                    {{ number_format($healthReport['recent_24h']['success_rate'], 1) }}%
                </span>
            </div>
        </div>
    </div>
</div>

{{-- Список вебхуков --}}
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6 border-b border-gray-200">
        <h3 class="text-lg font-bold">Вебхуки</h3>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Тип сущности</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Действие</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Статус</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Здоровье</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Получено</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Ошибок</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Последнее событие</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($healthReport['webhooks'] as $webhook)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium">{{ $webhook['entity_type'] }}</td>
                    <td class="px-6 py-4 text-sm">{{ $webhook['action'] }}</td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ $webhook['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                            {{ $webhook['is_active'] ? 'Активен' : 'Неактивен' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $webhook['status_color'] }}">
                            {{ ucfirst($webhook['health_status']) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center text-sm">{{ $webhook['total_received'] }}</td>
                    <td class="px-6 py-4 text-center text-sm {{ $webhook['total_failed'] > 0 ? 'text-red-600 font-medium' : '' }}">
                        {{ $webhook['total_failed'] }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        @if($webhook['last_triggered_at'])
                            {{ \Carbon\Carbon::parse($webhook['last_triggered_at'])->format('d.m.Y H:i') }}
                        @else
                            <span class="text-gray-400">Никогда</span>
                        @endif
                    </td>
                </tr>
                @if($webhook['error_message'])
                <tr>
                    <td colspan="7" class="px-6 py-2 text-sm bg-red-50 text-red-700">
                        <strong>Ошибка:</strong> {{ $webhook['error_message'] }}
                    </td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Проблемы --}}
@if(count($healthReport['problems']) > 0)
<div class="bg-red-50 border-l-4 border-red-400 p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-medium text-red-800">Обнаружены проблемы ({{ count($healthReport['problems']) }})</h3>
            <div class="mt-2 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($healthReport['problems'] as $problem)
                    <li>
                        <strong>[{{ strtoupper($problem['severity']) }}]</strong>
                        {{ $problem['entity_type'] }}/{{ $problem['action'] }}: {{ $problem['message'] }}
                        @if(isset($problem['recommendation']))
                            <br><span class="ml-6 text-xs italic">Рекомендация: {{ $problem['recommendation'] }}</span>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Действия --}}
<div class="mt-6 flex space-x-4">
    <form action="{{ route('admin.webhooks.reinstall', $account->account_id) }}" method="POST" class="inline" onsubmit="return confirm('Переустановить все вебхуки для аккаунта {{ $account->account_name ?? $account->account_id }}?');">
        @csrf
        <input type="hidden" name="account_type" value="{{ $accountType }}">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
            Переустановить вебхуки
        </button>
    </form>

    <form action="{{ route('admin.webhooks.health-check', $account->account_id) }}" method="POST" class="inline">
        @csrf
        <input type="hidden" name="auto_heal" value="1">
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">
            Auto-Heal
        </button>
    </form>
</div>

@endsection
