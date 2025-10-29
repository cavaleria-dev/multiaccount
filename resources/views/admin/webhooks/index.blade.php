@extends('admin.layout')

@section('title', 'Управление вебхуками')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold">Управление вебхуками</h2>
    <p class="text-gray-600 mt-1">Мониторинг и управление вебхуками МойСклад</p>
</div>

@if(isset($error))
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ $error }}</div>
@else

{{-- Карточки со статистикой --}}
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-gray-500 uppercase">Всего аккаунтов</h3>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $stats['total'] }}</p>
    </div>

    <div class="bg-green-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-green-800 uppercase">Здоровые</h3>
        <p class="text-3xl font-bold text-green-900 mt-2">{{ $stats['healthy'] }}</p>
        <p class="text-xs text-green-600 mt-1">&ge;95% успешных</p>
    </div>

    <div class="bg-yellow-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-yellow-800 uppercase">Деградированные</h3>
        <p class="text-3xl font-bold text-yellow-900 mt-2">{{ $stats['degraded'] }}</p>
        <p class="text-xs text-yellow-600 mt-1">85-95% успешных</p>
    </div>

    <div class="bg-red-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Критичные</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ $stats['critical'] }}</p>
        <p class="text-xs text-red-600 mt-1">&lt;85% успешных</p>
    </div>
</div>

{{-- Таблица аккаунтов --}}
<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b border-gray-200">
        <h3 class="text-lg font-bold">Аккаунты с вебхуками</h3>
    </div>

    @if($accounts->count() > 0)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Аккаунт</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тип</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Вебхуков</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Здоровье</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Успешных</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($accounts as $account)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">{{ $account['account_name'] }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ $account['account_id'] }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $account['account_type'] === 'main' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                                {{ $account['account_type'] === 'main' ? 'Главный' : 'Дочерний' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm">
                            @if($account['health'])
                                {{ count($account['health']['webhooks']) }}
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($account['health'])
                                @php
                                    $healthStatus = $account['health']['summary']['health_status'];
                                    $colors = [
                                        'healthy' => 'bg-green-100 text-green-800',
                                        'degraded' => 'bg-yellow-100 text-yellow-800',
                                        'warning' => 'bg-orange-100 text-orange-800',
                                        'critical' => 'bg-red-100 text-red-800',
                                    ];
                                    $labels = [
                                        'healthy' => 'Здоров',
                                        'degraded' => 'Деградирован',
                                        'warning' => 'Внимание',
                                        'critical' => 'Критично',
                                    ];
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$healthStatus] ?? 'bg-gray-100 text-gray-800' }}">
                                    {{ $labels[$healthStatus] ?? $healthStatus }}
                                </span>
                            @elseif($account['error'])
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    Ошибка
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center text-sm">
                            @if($account['health'])
                                @php
                                    $totalReceived = $account['health']['summary']['total_received'];
                                    $totalFailed = $account['health']['summary']['total_failed'];
                                    $successRate = $totalReceived > 0 ? (($totalReceived - $totalFailed) / $totalReceived * 100) : 0;
                                @endphp
                                <span class="font-medium {{ $successRate >= 95 ? 'text-green-600' : ($successRate >= 85 ? 'text-yellow-600' : 'text-red-600') }}">
                                    {{ number_format($successRate, 1) }}%
                                </span>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ $totalReceived - $totalFailed }}/{{ $totalReceived }}
                                </div>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-medium space-x-2">
                            <a href="{{ route('admin.webhooks.show', ['accountId' => $account['account_id'], 'account_type' => $account['account_type']]) }}"
                               class="text-indigo-600 hover:text-indigo-900">
                                Детали
                            </a>

                            <form action="{{ route('admin.webhooks.reinstall', $account['account_id']) }}" method="POST" class="inline" onsubmit="return confirm('Переустановить все вебхуки для аккаунта {{ $account['account_name'] }}?');">
                                @csrf
                                <input type="hidden" name="account_type" value="{{ $account['account_type'] }}">
                                <button type="submit" class="text-blue-600 hover:text-blue-900">
                                    Переустановить
                                </button>
                            </form>

                            <form action="{{ route('admin.webhooks.health-check', $account['account_id']) }}" method="POST" class="inline">
                                @csrf
                                <input type="hidden" name="auto_heal" value="1">
                                <button type="submit" class="text-green-600 hover:text-green-900">
                                    Auto-Heal
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="p-6 text-center text-gray-500">
            Нет аккаунтов с вебхуками
        </div>
    @endif
</div>

{{-- Проблемные аккаунты --}}
@if(count($problemAccounts) > 0)
<div class="mt-6 bg-red-50 border-l-4 border-red-400 p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
        </div>
        <div class="ml-3">
            <p class="text-sm text-red-700">
                <span class="font-semibold">{{ count($problemAccounts) }}</span> аккаунтов с проблемами вебхуков
            </p>
            <ul class="mt-2 text-sm text-red-600 list-disc list-inside">
                @foreach($problemAccounts as $problem)
                    <li>{{ $problem['account_name'] ?? $problem['account_id'] }}: {{ $problem['issue'] }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endif

@endif
@endsection
