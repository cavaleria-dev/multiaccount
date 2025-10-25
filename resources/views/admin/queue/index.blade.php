@extends('admin.layout')

@section('title', 'Задачи синхронизации')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold">Задачи синхронизации</h2>
            <p class="text-gray-600 mt-1">Детальный список всех задач в очереди</p>
        </div>
        <a href="{{ route('admin.queue.dashboard') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
            ← Назад к Dashboard
        </a>
    </div>

    {{-- Фильтры --}}
    <form method="GET" action="{{ route('admin.queue.tasks') }}" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Главный аккаунт</label>
            <select name="main_account_id" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['main_accounts'] ?? [] as $account)
                    <option value="{{ $account->account_id }}" {{ request('main_account_id') === $account->account_id ? 'selected' : '' }}>
                        {{ $account->account_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Дочерний аккаунт</label>
            <select name="account_id" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['child_accounts'] ?? [] as $account)
                    <option value="{{ $account->account_id }}" {{ request('account_id') === $account->account_id ? 'selected' : '' }}>
                        {{ $account->account_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Статус</label>
            <select name="status" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['statuses'] ?? [] as $status)
                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>
                        {{ ucfirst($status) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Тип сущности</label>
            <select name="entity_type" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['entity_types'] ?? [] as $type)
                    <option value="{{ $type }}" {{ request('entity_type') === $type ? 'selected' : '' }}>
                        {{ $type }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Операция</label>
            <select name="operation" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['operations'] ?? [] as $op)
                    <option value="{{ $op }}" {{ request('operation') === $op ? 'selected' : '' }}>
                        {{ $op }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Приоритет</label>
            <select name="priority" class="w-full border rounded px-3 py-2">
                <option value="">Все</option>
                @foreach($filterOptions['priorities'] ?? [] as $value => $label)
                    <option value="{{ $value }}" {{ request('priority') == $value ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
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

        <div class="flex items-end space-x-4">
            <label class="flex items-center">
                <input type="checkbox" name="scheduled_only" value="1" {{ request('scheduled_only') ? 'checked' : '' }} class="mr-2">
                <span class="text-sm">Только отложенные</span>
            </label>
            <label class="flex items-center">
                <input type="checkbox" name="errors_only" value="1" {{ request('errors_only') ? 'checked' : '' }} class="mr-2">
                <span class="text-sm">Только с ошибками</span>
            </label>
        </div>

        <div class="md:col-span-3">
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
                Применить фильтры
            </button>
            <a href="{{ route('admin.queue.tasks') }}" class="ml-2 bg-gray-300 text-gray-700 px-6 py-2 rounded hover:bg-gray-400">
                Сбросить
            </a>
        </div>
    </form>

    {{-- Таблица задач --}}
    @if(isset($error))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{{ $error }}</div>
    @elseif($tasks && $tasks->count() > 0)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Аккаунт</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Тип/Операция</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Приоритет</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Статус</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Попытки</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Действия</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($tasks as $task)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm">
                            <a href="{{ route('admin.queue.tasks.show', $task->id) }}" class="text-indigo-600 hover:text-indigo-900">
                                #{{ $task->id }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $task->created_at->format('d.m.Y H:i') }}
                            @if($task->scheduled_at && $task->scheduled_at > now())
                                <br><span class="text-xs text-purple-600">⏰ {{ $task->scheduled_at->format('d.m H:i') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $task->account->account_name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="font-medium">{{ $task->entity_type }}</span><br>
                            <span class="text-xs text-gray-500">{{ $task->operation }}</span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm">
                            @if($task->priority >= 10)
                                <span class="text-red-600">⬆ {{ $task->priority }}</span>
                            @elseif($task->priority >= 5)
                                <span class="text-yellow-600">➡ {{ $task->priority }}</span>
                            @else
                                <span class="text-gray-600">⬇ {{ $task->priority }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($task->status === 'pending')
                                <span class="px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800">Pending</span>
                            @elseif($task->status === 'processing')
                                <span class="px-2 py-1 text-xs rounded bg-blue-100 text-blue-800">Processing</span>
                            @elseif($task->status === 'completed')
                                <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">Completed</span>
                            @elseif($task->status === 'failed')
                                <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-800">Failed</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-sm">{{ $task->attempts }} / {{ $task->max_attempts }}</td>
                        <td class="px-4 py-3 text-sm">
                            <a href="{{ route('admin.queue.tasks.show', $task->id) }}" class="text-indigo-600 hover:text-indigo-900 text-xs">
                                Детали
                            </a>
                            @if($task->status === 'failed')
                                <form action="{{ route('admin.queue.tasks.retry', $task->id) }}" method="POST" class="inline ml-2">
                                    @csrf
                                    <button type="submit" class="text-green-600 hover:text-green-900 text-xs">
                                        Retry
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Пагинация --}}
        <div class="mt-4">
            {{ $tasks->links() }}
        </div>
    @else
        <p class="text-gray-500">Задач не найдено</p>
    @endif
</div>
@endsection
