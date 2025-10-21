@extends('admin.layout')

@section('title', 'Детали лога #' . $log->id)

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">Лог #{{ $log->id }}</h2>
        <a href="{{ route('admin.logs.index') }}" class="text-indigo-600 hover:text-indigo-800">
            ← Назад к списку
        </a>
    </div>

    {{-- Основная информация --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div>
            <h3 class="font-semibold text-lg mb-3">Общая информация</h3>
            <dl class="space-y-2">
                <div>
                    <dt class="text-sm text-gray-600">Дата/Время:</dt>
                    <dd class="font-medium">{{ $log->created_at->format('Y-m-d H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">Метод:</dt>
                    <dd class="font-mono font-medium">{{ $log->method }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">HTTP Статус:</dt>
                    <dd>
                        <span class="px-3 py-1 rounded font-medium
                            {{ $log->response_status < 300 ? 'bg-green-100 text-green-800' : '' }}
                            {{ $log->response_status >= 400 && $log->response_status < 500 ? 'bg-yellow-100 text-yellow-800' : '' }}
                            {{ $log->response_status >= 500 ? 'bg-red-100 text-red-800' : '' }}
                            {{ $log->response_status === 429 ? 'bg-orange-100 text-orange-800' : '' }}">
                            {{ $log->response_status }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">Длительность:</dt>
                    <dd class="font-medium">{{ $log->duration_ms ?? 'N/A' }} мс</dd>
                </div>
            </dl>
        </div>

        <div>
            <h3 class="font-semibold text-lg mb-3">Контекст</h3>
            <dl class="space-y-2">
                @php
                    // Определить кто main, кто child на основе связи в child_accounts
                    $isAccountMain = DB::table('child_accounts')
                        ->where('parent_account_id', $log->account_id)
                        ->exists();

                    // Если account_id является parent, значит он главный
                    if ($isAccountMain) {
                        $mainAccount = $log->account;
                        $mainAccountId = $log->account_id;
                        $childAccount = $log->relatedAccount;
                        $childAccountId = $log->related_account_id;
                    } else {
                        // Иначе account_id - дочерний, related - главный
                        $mainAccount = $log->relatedAccount;
                        $mainAccountId = $log->related_account_id;
                        $childAccount = $log->account;
                        $childAccountId = $log->account_id;
                    }
                @endphp

                <div>
                    <dt class="text-sm text-gray-600">Главный аккаунт:</dt>
                    <dd>
                        <div class="font-medium">{{ $mainAccount?->account_name ?? 'Неизвестный' }}</div>
                        @if($mainAccountId)
                            <div class="font-mono text-xs text-gray-500" title="{{ $mainAccountId }}">
                                {{ Str::limit($mainAccountId, 20) }}
                            </div>
                        @endif
                    </dd>
                </div>

                @if($childAccountId)
                    <div>
                        <dt class="text-sm text-gray-600">Дочерний аккаунт:</dt>
                        <dd>
                            <div class="font-medium">{{ $childAccount?->account_name ?? 'Неизвестный' }}</div>
                            <div class="font-mono text-xs text-gray-500" title="{{ $childAccountId }}">
                                {{ Str::limit($childAccountId, 20) }}
                            </div>
                        </dd>
                    </div>
                @endif

                <div>
                    <dt class="text-sm text-gray-600">Направление:</dt>
                    <dd>
                        @if($log->direction === 'main_to_child')
                            @if(!$log->related_account_id)
                                {{-- Запрос К главному аккаунту (чтение из источника) --}}
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">Главный</span>
                            @else
                                {{-- Синхронизация ИЗ главного В дочерний --}}
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">Главный → Дочерний</span>
                            @endif
                        @elseif($log->direction === 'child_to_main')
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded">Дочерний → Главный</span>
                        @elseif($log->direction === 'internal')
                            <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded">Внутренний</span>
                        @else
                            <span class="text-sm text-gray-400">-</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-gray-600">Тип сущности:</dt>
                    <dd>{{ $log->entity_type ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">ID сущности:</dt>
                    <dd class="font-mono text-sm">{{ $log->entity_id ?? 'N/A' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Информация об операции --}}
    @if($log->operation_type || $log->operation_result)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-3">Операция</h3>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if($log->operation_type)
                    <div>
                        <dt class="text-sm text-gray-600 mb-1">Тип операции:</dt>
                        <dd>
                            @php
                                $operationLabels = [
                                    'load' => 'Загрузка',
                                    'create' => 'Создание',
                                    'update' => 'Обновление',
                                    'batch_create' => 'Пакетное создание',
                                    'batch_update' => 'Пакетное обновление',
                                    'search_existing' => 'Поиск существующих',
                                    'mapping' => 'Маппинг',
                                ];
                                $operationColors = [
                                    'load' => 'bg-indigo-100 text-indigo-800',
                                    'create' => 'bg-green-100 text-green-800',
                                    'update' => 'bg-yellow-100 text-yellow-800',
                                    'batch_create' => 'bg-green-100 text-green-800',
                                    'batch_update' => 'bg-yellow-100 text-yellow-800',
                                    'search_existing' => 'bg-purple-100 text-purple-800',
                                    'mapping' => 'bg-gray-100 text-gray-800',
                                ];
                            @endphp
                            <span class="px-3 py-1 rounded font-medium {{ $operationColors[$log->operation_type] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $operationLabels[$log->operation_type] ?? $log->operation_type }}
                            </span>
                        </dd>
                    </div>
                @endif

                @if($log->operation_result)
                    <div>
                        <dt class="text-sm text-gray-600 mb-1">Результат операции:</dt>
                        <dd>
                            @php
                                $resultLabels = [
                                    'success' => 'Успешно',
                                    'success_created' => 'Создано',
                                    'success_updated' => 'Обновлено',
                                    'found_existing' => 'Найден существующий',
                                    'not_found' => 'Не найдено',
                                    'error_412_duplicate' => 'Ошибка 412: Дубликат',
                                    'error' => 'Ошибка',
                                ];
                                $resultColors = [
                                    'success' => 'bg-green-100 text-green-800',
                                    'success_created' => 'bg-green-100 text-green-800',
                                    'success_updated' => 'bg-blue-100 text-blue-800',
                                    'found_existing' => 'bg-purple-100 text-purple-800',
                                    'not_found' => 'bg-yellow-100 text-yellow-800',
                                    'error_412_duplicate' => 'bg-red-100 text-red-800',
                                    'error' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            <span class="px-3 py-1 rounded font-medium {{ $resultColors[$log->operation_result] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $resultLabels[$log->operation_result] ?? $log->operation_result }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    @endif

    {{-- Endpoint --}}
    <div class="mb-6">
        <h3 class="font-semibold text-lg mb-2">Endpoint</h3>
        <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-sm">{{ $log->endpoint }}</pre>
    </div>

    {{-- Request Parameters (GET/POST params) --}}
    @if($log->request_params && count($log->request_params) > 0)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Параметры запроса</h3>
            <pre class="bg-blue-50 p-3 rounded overflow-x-auto text-xs border border-blue-200">{{ json_encode($log->request_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    {{-- Ошибка --}}
    @if($log->error_message)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Сообщение об ошибке</h3>
            <div class="bg-red-50 border border-red-200 p-3 rounded">
                <pre class="text-red-800 whitespace-pre-wrap">{{ $log->error_message }}</pre>
            </div>
        </div>
    @endif

    {{-- Детали ошибок МойСклад --}}
    @if($log->response_body && isset($log->response_body['errors']) && is_array($log->response_body['errors']) && count($log->response_body['errors']) > 0)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-3 text-red-700">📋 Детали ошибок МойСклад API</h3>
            @foreach($log->response_body['errors'] as $index => $error)
                <div class="bg-red-50 border-2 border-red-300 rounded-lg p-4 mb-4 shadow-sm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(isset($error['error']))
                            <div class="md:col-span-2">
                                <dt class="text-sm font-medium text-gray-700 mb-1">Ошибка:</dt>
                                <dd class="text-lg font-semibold text-red-800">{{ $error['error'] }}</dd>
                            </div>
                        @endif

                        @if(isset($error['error_message']))
                            <div class="md:col-span-2">
                                <dt class="text-sm font-medium text-gray-700 mb-1">Подробности:</dt>
                                <dd class="text-red-700 bg-white px-3 py-2 rounded border border-red-200">{{ $error['error_message'] }}</dd>
                            </div>
                        @endif

                        @if(isset($error['code']))
                            <div>
                                <dt class="text-sm font-medium text-gray-700 mb-1">Код ошибки:</dt>
                                <dd class="font-mono text-lg font-bold text-red-900">{{ $error['code'] }}</dd>
                            </div>
                        @endif

                        @if(isset($error['parameter']))
                            <div>
                                <dt class="text-sm font-medium text-gray-700 mb-1">Параметр:</dt>
                                <dd class="font-mono bg-white px-3 py-1 rounded border border-gray-300 inline-block">{{ $error['parameter'] }}</dd>
                            </div>
                        @endif

                        @if(isset($error['line']) || isset($error['column']))
                            <div>
                                <dt class="text-sm font-medium text-gray-700 mb-1">Позиция в JSON:</dt>
                                <dd class="font-mono text-sm">
                                    @if(isset($error['line']))<span class="bg-white px-2 py-1 rounded border">Line: {{ $error['line'] }}</span>@endif
                                    @if(isset($error['column']))<span class="bg-white px-2 py-1 rounded border ml-1">Column: {{ $error['column'] }}</span>@endif
                                </dd>
                            </div>
                        @endif

                        @if(isset($error['moreInfo']))
                            <div class="md:col-span-2">
                                <a href="{{ $error['moreInfo'] }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-medium underline">
                                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                    Документация МойСклад по этой ошибке
                                </a>
                            </div>
                        @endif

                        @if(isset($error['dependencies']) && is_array($error['dependencies']) && count($error['dependencies']) > 0)
                            <div class="md:col-span-2">
                                <dt class="text-sm font-medium text-gray-700 mb-2">⚠️ Зависимости (препятствуют удалению):</dt>
                                <dd class="text-xs bg-white p-3 rounded border border-gray-300 max-h-40 overflow-y-auto">
                                    <pre class="text-gray-800">{{ json_encode($error['dependencies'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </dd>
                            </div>
                        @endif

                        @if(isset($error['meta']))
                            <div class="md:col-span-2">
                                <details class="cursor-pointer">
                                    <summary class="text-sm font-medium text-gray-700 hover:text-gray-900">🔍 Метаданные сущности</summary>
                                    <dd class="mt-2 text-xs bg-white p-3 rounded border border-gray-300 max-h-40 overflow-y-auto">
                                        <pre class="text-gray-800">{{ json_encode($error['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </dd>
                                </details>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Request Payload --}}
    @if($log->request_payload)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Тело запроса</h3>
            <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-xs">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    {{-- Response Body --}}
    @if($log->response_body)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Тело ответа</h3>
            <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-xs">{{ json_encode($log->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    {{-- Response Size & Truncated Warning --}}
    @if($log->rate_limit_info && isset($log->rate_limit_info['response_size']))
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Информация об ответе</h3>
            <div class="bg-gray-50 p-4 rounded border border-gray-200">
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-sm text-gray-600 mb-1">Размер ответа:</dt>
                        <dd class="font-mono text-lg">
                            @php
                                $size = $log->rate_limit_info['response_size'];
                                if ($size < 1024) {
                                    echo $size . ' bytes';
                                } elseif ($size < 1024 * 1024) {
                                    echo round($size / 1024, 2) . ' KB';
                                } else {
                                    echo round($size / (1024 * 1024), 2) . ' MB';
                                }
                            @endphp
                        </dd>
                    </div>

                    @if(isset($log->rate_limit_info['response_truncated']) && $log->rate_limit_info['response_truncated'])
                        <div>
                            <dt class="text-sm text-gray-600 mb-1">Статус:</dt>
                            <dd>
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded font-medium inline-flex items-center">
                                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    ОБРЕЗАН (слишком большой)
                                </span>
                            </dd>
                        </div>
                    @endif
                </dl>

                @if(isset($log->rate_limit_info['response_truncated']) && $log->rate_limit_info['response_truncated'])
                    <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                        ⚠️ Ответ был обрезан для сохранения в базе данных (max 5MB). Ключевые поля (errors, meta) сохранены полностью.
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- МойСклад Special Headers --}}
    @if($log->rate_limit_info && (
        isset($log->rate_limit_info['lognex_auth_code']) ||
        isset($log->rate_limit_info['lognex_auth_message']) ||
        isset($log->rate_limit_info['api_version_deprecated']) ||
        isset($log->rate_limit_info['location'])
    ))
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">🔖 Специальные заголовки МойСклад</h3>
            <div class="bg-blue-50 border border-blue-200 p-4 rounded">
                <dl class="space-y-3">
                    @if(isset($log->rate_limit_info['lognex_auth_code']))
                        <div>
                            <dt class="text-sm text-gray-700 font-medium">X-Lognex-Auth (код ошибки аутентификации):</dt>
                            <dd class="font-mono text-red-800 bg-white px-2 py-1 rounded mt-1">{{ $log->rate_limit_info['lognex_auth_code'] }}</dd>
                        </div>
                    @endif

                    @if(isset($log->rate_limit_info['lognex_auth_message']))
                        <div>
                            <dt class="text-sm text-gray-700 font-medium">X-Lognex-Auth-Message:</dt>
                            <dd class="text-red-800 bg-white px-2 py-1 rounded mt-1">{{ $log->rate_limit_info['lognex_auth_message'] }}</dd>
                        </div>
                    @endif

                    @if(isset($log->rate_limit_info['api_version_deprecated']))
                        <div class="bg-red-100 border border-red-300 p-3 rounded">
                            <dt class="text-sm text-red-900 font-bold mb-1">⚠️ X-Lognex-API-Version-Deprecated:</dt>
                            <dd class="text-red-800">Версия API будет отключена: <span class="font-mono font-bold">{{ $log->rate_limit_info['api_version_deprecated'] }}</span></dd>
                        </div>
                    @endif

                    @if(isset($log->rate_limit_info['location']))
                        <div>
                            <dt class="text-sm text-gray-700 font-medium">Location (редирект):</dt>
                            <dd class="font-mono text-sm bg-white px-2 py-1 rounded mt-1 break-all">
                                <a href="{{ $log->rate_limit_info['location'] }}" target="_blank" class="text-indigo-600 hover:text-indigo-800 underline">
                                    {{ $log->rate_limit_info['location'] }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    @endif

    {{-- Rate Limit Info --}}
    @if($log->rate_limit_info)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Rate Limit Info</h3>
            <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-xs">{{ json_encode($log->rate_limit_info, JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endif
</div>
@endsection
