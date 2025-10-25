@extends('admin.layout')

@section('title', 'Rate Limits')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold">Мониторинг Rate Limits</h2>
            <p class="text-gray-600 mt-1">Текущее состояние rate limits для всех main аккаунтов</p>
        </div>
        <a href="{{ route('admin.queue.dashboard') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
            ← Назад к Dashboard
        </a>
    </div>

    @if(isset($error))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{{ $error }}</div>
    @elseif(count($statuses) > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($statuses as $status)
                <div class="rounded-lg shadow p-6 {{ $status['status'] === 'exhausted' ? 'bg-red-50 border-2 border-red-400' : ($status['status'] === 'warning' ? 'bg-yellow-50 border-2 border-yellow-400' : ($status['status'] === 'ok' ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200')) }}">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="font-bold text-lg">{{ $status['account_name'] }}</h3>
                            <p class="text-xs text-gray-500 mt-1">{{ substr($status['account_id'], 0, 16) }}...</p>
                        </div>
                        <span class="px-2 py-1 text-xs rounded font-semibold
                            {{ $status['status'] === 'exhausted' ? 'bg-red-600 text-white' : ($status['status'] === 'warning' ? 'bg-yellow-600 text-white' : ($status['status'] === 'ok' ? 'bg-green-600 text-white' : 'bg-gray-400 text-white')) }}">
                            {{ $status['status_text'] }}
                        </span>
                    </div>

                    @if($status['limit'] !== null)
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Лимит:</span>
                                <span class="font-semibold">{{ $status['limit'] }} req/min</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Осталось:</span>
                                <span class="font-semibold {{ $status['remaining'] <= 5 ? 'text-red-600' : ($status['remaining'] <= 15 ? 'text-yellow-600' : 'text-green-600') }}">
                                    {{ $status['remaining'] }}
                                </span>
                            </div>

                            {{-- Прогресс-бар --}}
                            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                @php
                                    $percentage = ($status['remaining'] / $status['limit']) * 100;
                                    $color = $percentage <= 10 ? 'bg-red-600' : ($percentage <= 33 ? 'bg-yellow-600' : 'bg-green-600');
                                @endphp
                                <div class="{{ $color }} h-2 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>

                            @if(isset($status['seconds_until_reset']) && $status['seconds_until_reset'] > 0)
                                <div class="flex justify-between text-xs text-gray-500 mt-2">
                                    <span>Сброс через:</span>
                                    <span>{{ gmdate('i:s', $status['seconds_until_reset']) }}</span>
                                </div>
                            @endif

                            @if($status['last_updated'])
                                <div class="text-xs text-gray-400 mt-2">
                                    Обновлено: {{ date('d.m.Y H:i:s', $status['last_updated']) }}
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Нет данных о rate limit</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 bg-blue-50 border-l-4 border-blue-400 p-4">
            <p class="text-sm text-blue-700">
                <strong>Как работает:</strong> Данные обновляются после каждого API запроса к МойСклад.
                Safety threshold = 5 запросов (worker останавливает отправку когда осталось ≤ 5 запросов).
                TTL кеша = 120 секунд.
            </p>
        </div>
    @else
        <p class="text-gray-500">Нет активных main аккаунтов с задачами в очереди</p>
    @endif
</div>
@endsection
