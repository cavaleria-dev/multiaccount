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
