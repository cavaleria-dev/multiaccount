@extends('admin.layout')

@section('title', 'Детали задачи #' . $task->id)

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">Задача #{{ $task->id }}</h2>
        <a href="{{ route('admin.queue.tasks') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
            ← Назад к списку
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        {{-- Основная информация --}}
        <div class="bg-gray-50 rounded p-4">
            <h3 class="font-bold mb-3">Основная информация</h3>
            <div class="space-y-2 text-sm">
                <div><span class="font-medium">ID:</span> {{ $task->id }}</div>
                <div><span class="font-medium">Статус:</span>
                    @if($task->status === 'pending')
                        <span class="px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800">Pending</span>
                    @elseif($task->status === 'processing')
                        <span class="px-2 py-1 text-xs rounded bg-blue-100 text-blue-800">Processing</span>
                    @elseif($task->status === 'completed')
                        <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">Completed</span>
                    @elseif($task->status === 'failed')
                        <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-800">Failed</span>
                    @endif
                </div>
                <div><span class="font-medium">Тип сущности:</span> {{ $task->entity_type }}</div>
                <div><span class="font-medium">Entity ID:</span> <code class="text-xs bg-gray-200 px-1">{{ $task->entity_id }}</code></div>
                <div><span class="font-medium">Операция:</span> {{ $task->operation }}</div>
                <div><span class="font-medium">Приоритет:</span> {{ $task->priority }}</div>
                <div><span class="font-medium">Попытки:</span> {{ $task->attempts }} / {{ $task->max_attempts }}</div>
            </div>
        </div>

        {{-- Аккаунты --}}
        <div class="bg-gray-50 rounded p-4">
            <h3 class="font-bold mb-3">Аккаунты</h3>
            <div class="space-y-2 text-sm">
                <div>
                    <span class="font-medium">Child Account:</span><br>
                    {{ $task->account->account_name ?? 'Unknown' }}<br>
                    <code class="text-xs bg-gray-200 px-1">{{ $task->account_id }}</code>
                </div>
                @if(isset($task->payload['main_account_id']))
                <div class="mt-2">
                    <span class="font-medium">Main Account ID:</span><br>
                    <code class="text-xs bg-gray-200 px-1">{{ $task->payload['main_account_id'] }}</code>
                </div>
                @endif
            </div>
        </div>

        {{-- Временные метки --}}
        <div class="bg-gray-50 rounded p-4">
            <h3 class="font-bold mb-3">Временные метки</h3>
            <div class="space-y-2 text-sm">
                <div><span class="font-medium">Создана:</span> {{ $task->created_at->format('d.m.Y H:i:s') }}</div>
                <div><span class="font-medium">Обновлена:</span> {{ $task->updated_at->format('d.m.Y H:i:s') }}</div>
                @if($task->scheduled_at)
                <div><span class="font-medium">Запланирована на:</span> {{ $task->scheduled_at->format('d.m.Y H:i:s') }}</div>
                @endif
                @if($task->started_at)
                <div><span class="font-medium">Начата:</span> {{ $task->started_at->format('d.m.Y H:i:s') }}</div>
                @endif
                @if($task->completed_at)
                <div><span class="font-medium">Завершена:</span> {{ $task->completed_at->format('d.m.Y H:i:s') }}</div>
                @endif
            </div>
        </div>

        {{-- Действия --}}
        <div class="bg-gray-50 rounded p-4">
            <h3 class="font-bold mb-3">Действия</h3>
            <div class="space-y-2">
                @if($task->status === 'failed')
                    <form action="{{ route('admin.queue.tasks.retry', $task->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                            Перезапустить задачу
                        </button>
                    </form>
                @endif
                @if(in_array($task->status, ['pending', 'failed']))
                    <form action="{{ route('admin.queue.tasks.delete', $task->id) }}" method="POST" onsubmit="return confirm('Удалить задачу?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                            Удалить задачу
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- Ошибка --}}
    @if($task->error)
    <div class="mb-6">
        <h3 class="font-bold mb-2">Ошибка</h3>
        <div class="bg-red-50 border border-red-200 rounded p-4">
            <pre class="text-sm text-red-800 whitespace-pre-wrap">{{ $task->error }}</pre>
        </div>
    </div>
    @endif

    {{-- Rate Limit Info --}}
    @if($task->rate_limit_info)
    <div class="mb-6">
        <h3 class="font-bold mb-2">Rate Limit Info</h3>
        <div class="bg-blue-50 border border-blue-200 rounded p-4">
            <pre class="text-sm text-gray-800">{{ json_encode($task->rate_limit_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </div>
    @endif

    {{-- Payload --}}
    @if($task->payload)
    <div>
        <h3 class="font-bold mb-2">Payload</h3>
        <div class="bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
            <pre class="text-sm text-gray-800">{{ json_encode($task->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </div>
    @endif
</div>
@endsection
