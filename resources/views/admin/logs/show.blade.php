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
                <div>
                    <dt class="text-sm text-gray-600">Account ID:</dt>
                    <dd class="font-mono text-sm">{{ $log->account_id ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">Направление:</dt>
                    <dd>{{ $log->direction ?? 'N/A' }}</dd>
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

    {{-- Endpoint --}}
    <div class="mb-6">
        <h3 class="font-semibold text-lg mb-2">Endpoint</h3>
        <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-sm">{{ $log->endpoint }}</pre>
    </div>

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

    {{-- Rate Limit Info --}}
    @if($log->rate_limit_info)
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Rate Limit Info</h3>
            <pre class="bg-gray-100 p-3 rounded overflow-x-auto text-xs">{{ json_encode($log->rate_limit_info, JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endif
</div>
@endsection
