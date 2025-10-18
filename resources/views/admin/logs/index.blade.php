@extends('admin.layout')

@section('title', 'Логи API')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-bold mb-6">Логи API запросов</h2>

    {{-- Фильтры --}}
    <form method="GET" action="{{ route('admin.logs.index') }}" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Главная франшиза</label>
            <select name="parent_account_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                <option value="">Все</option>
                @foreach($mainAccounts ?? [] as $account)
                    <option value="{{ $account->account_id }}" {{ request('parent_account_id') === $account->account_id ? 'selected' : '' }}>
                        {{ $account->account_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Дочерняя франшиза</label>
            <select name="child_account_id" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($childAccounts ?? [] as $account)
                    <option value="{{ $account->account_id }}" {{ request('child_account_id') === $account->account_id ? 'selected' : '' }}>
                        {{ $account->account_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Тип ошибки</label>
            <select name="status_range" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                <option value="4xx" {{ request('status_range') === '4xx' ? 'selected' : '' }}>4xx</option>
                <option value="5xx" {{ request('status_range') === '5xx' ? 'selected' : '' }}>5xx</option>
                <option value="429" {{ request('status_range') === '429' ? 'selected' : '' }}>429 (Rate Limit)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Дата от</label>
            <input type="date" name="start_date" value="{{ request('start_date') }}" class="w-full border rounded px-3 py-2">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Дата до</label>
            <input type="date" name="end_date" value="{{ request('end_date') }}" class="w-full border rounded px-3 py-2">
        </div>

        <div class="flex items-end">
            <label class="flex items-center">
                <input type="checkbox" name="errors_only" value="1" {{ request('errors_only') ? 'checked' : '' }} class="mr-2">
                <span class="text-sm">Только ошибки</span>
            </label>
        </div>

        <div class="md:col-span-3">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
                Применить фильтры
            </button>
            <a href="{{ route('admin.logs.index') }}" class="ml-2 bg-gray-300 text-gray-700 px-6 py-2 rounded hover:bg-gray-400">
                Сбросить
            </a>
        </div>
    </form>

    {{-- Таблица логов --}}
    @if(isset($error))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{{ $error }}</div>
    @elseif($logs && $logs->count() > 0)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата/Время</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Аккаунт</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Направление</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Метод</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Endpoint</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Длительность</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Действия</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($logs as $log)
                        <tr class="{{ $log->isError() ? 'bg-red-50' : '' }}">
                            <td class="px-4 py-3 text-sm">{{ $log->id }}</td>
                            <td class="px-4 py-3 text-sm">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3 text-sm">
                                <div class="text-xs text-gray-600" title="{{ $log->account_id }}">
                                    {{ $log->account?->account_name ?? Str::limit($log->account_id, 8) }}
                                </div>
                                @if($log->related_account_id)
                                    <div class="text-xs text-gray-500 mt-1" title="{{ $log->related_account_id }}">
                                        → {{ $log->relatedAccount?->account_name ?? Str::limit($log->related_account_id, 8) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if($log->direction === 'main_to_child')
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">Главный → Дочерний</span>
                                @elseif($log->direction === 'child_to_main')
                                    <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded">Дочерний → Главный</span>
                                @elseif($log->direction === 'internal')
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded">Внутренний</span>
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm font-mono">{{ $log->method }}</td>
                            <td class="px-4 py-3 text-sm text-xs truncate max-w-xs" title="{{ $log->endpoint }}">
                                {{ Str::limit($log->endpoint, 50) }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @php
                                    $statusDescriptions = [
                                        200 => 'OK', 301 => 'Redirect', 302 => 'Redirect', 303 => 'Redirect',
                                        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
                                        404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
                                        410 => 'API Deprecated', 412 => 'Missing Param', 413 => 'Too Large',
                                        414 => 'URI Too Long', 415 => 'Unsupported', 429 => 'Rate Limit',
                                        500 => 'Server Error', 502 => 'Bad Gateway', 503 => 'Unavailable', 504 => 'Timeout',
                                    ];
                                    $description = $statusDescriptions[$log->response_status] ?? '';
                                @endphp
                                <div class="flex flex-col gap-1">
                                    <span class="px-2 py-1 rounded text-xs font-medium inline-block
                                        {{ $log->response_status < 300 ? 'bg-green-100 text-green-800' : '' }}
                                        {{ $log->response_status >= 300 && $log->response_status < 400 ? 'bg-blue-100 text-blue-800' : '' }}
                                        {{ $log->response_status >= 400 && $log->response_status < 500 && $log->response_status !== 429 ? 'bg-yellow-100 text-yellow-800' : '' }}
                                        {{ $log->response_status >= 500 ? 'bg-red-100 text-red-800' : '' }}
                                        {{ $log->response_status === 429 ? 'bg-orange-100 text-orange-800' : '' }}"
                                        title="{{ $description }}">
                                        {{ $log->response_status }}
                                    </span>

                                    @if($log->rate_limit_info && isset($log->rate_limit_info['response_truncated']) && $log->rate_limit_info['response_truncated'])
                                        <span class="px-1 py-0.5 bg-yellow-50 text-yellow-700 rounded text-xs border border-yellow-300">
                                            ✂️ Truncated
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm">{{ $log->duration_ms ?? '-' }} ms</td>
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route('admin.logs.show', $log->id) }}" class="text-indigo-600 hover:text-indigo-900">
                                    Детали
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    @else
        <p class="text-gray-500">Логи не найдены</p>
    @endif
</div>
@endsection
