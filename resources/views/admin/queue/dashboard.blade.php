@extends('admin.layout')

@section('title', 'Очереди синхронизации')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold">Мониторинг очередей синхронизации</h2>
    <p class="text-gray-600 mt-1">Сводная статистика по задачам синхронизации</p>
</div>

@if(isset($error))
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ $error }}</div>
@elseif($statistics)

{{-- Карточки со статистикой --}}
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-gray-500 uppercase">Всего в очереди</h3>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $statistics['total'] }}</p>
    </div>

    <div class="bg-yellow-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-yellow-800 uppercase">Ожидают</h3>
        <p class="text-3xl font-bold text-yellow-900 mt-2">{{ $statistics['by_status']['pending'] ?? 0 }}</p>
    </div>

    <div class="bg-blue-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-blue-800 uppercase">Обрабатываются</h3>
        <p class="text-3xl font-bold text-blue-900 mt-2">{{ $statistics['by_status']['processing'] ?? 0 }}</p>
    </div>

    <div class="bg-green-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-green-800 uppercase">Завершено</h3>
        <p class="text-3xl font-bold text-green-900 mt-2">{{ $statistics['by_status']['completed'] ?? 0 }}</p>
    </div>

    <div class="bg-red-50 rounded-lg shadow p-6">
        <h3 class="text-sm font-medium text-red-800 uppercase">Ошибки</h3>
        <p class="text-3xl font-bold text-red-900 mt-2">{{ $statistics['by_status']['failed'] ?? 0 }}</p>
    </div>
</div>

@if($statistics['scheduled_count'] > 0)
<div class="bg-purple-50 border-l-4 border-purple-400 p-4 mb-6">
    <p class="text-purple-700">
        <span class="font-semibold">{{ $statistics['scheduled_count'] }}</span> задач отложены на будущее (scheduled_at > now)
    </p>
</div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    {{-- Статистика по main аккаунтам --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold mb-4">По главным аккаунтам</h3>
        @if(count($statistics['by_main_account']) > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Аккаунт</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Pending</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Processing</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Failed</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Всего</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($statistics['by_main_account'] as $accountId => $stats)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-sm">
                                <a href="{{ route('admin.queue.tasks', ['main_account_id' => $accountId]) }}" class="text-indigo-600 hover:text-indigo-900">
                                    {{ $stats['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-center text-sm text-yellow-600">{{ $stats['pending'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm text-blue-600">{{ $stats['processing'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm text-red-600">{{ $stats['failed'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm font-semibold">
                                {{ ($stats['pending'] ?? 0) + ($stats['processing'] ?? 0) + ($stats['completed'] ?? 0) + ($stats['failed'] ?? 0) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500">Нет задач</p>
        @endif
    </div>

    {{-- Статистика по типам сущностей --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-bold mb-4">По типам сущностей</h3>
        @if(count($statistics['by_entity_type']) > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Тип</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Pending</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Processing</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Failed</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Всего</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($statistics['by_entity_type'] as $entityType => $stats)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-sm">
                                <a href="{{ route('admin.queue.tasks', ['entity_type' => $entityType]) }}" class="text-indigo-600 hover:text-indigo-900">
                                    {{ $entityType }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-center text-sm text-yellow-600">{{ $stats['pending'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm text-blue-600">{{ $stats['processing'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm text-red-600">{{ $stats['failed'] ?? 0 }}</td>
                            <td class="px-4 py-2 text-center text-sm font-semibold">
                                {{ ($stats['pending'] ?? 0) + ($stats['processing'] ?? 0) + ($stats['completed'] ?? 0) + ($stats['failed'] ?? 0) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500">Нет задач</p>
        @endif
    </div>
</div>

{{-- Последние failed задачи --}}
@if($statistics['recent_failed']->count() > 0)
<div class="bg-white rounded-lg shadow p-6">
    <h3 class="text-lg font-bold mb-4">Последние проваленные задачи (топ 10)</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Аккаунт</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Тип</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ошибка</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Действия</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($statistics['recent_failed'] as $task)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-sm">
                        <a href="{{ route('admin.queue.tasks.show', $task->id) }}" class="text-indigo-600 hover:text-indigo-900">
                            #{{ $task->id }}
                        </a>
                    </td>
                    <td class="px-4 py-2 text-sm text-gray-500">{{ $task->updated_at->format('d.m.Y H:i') }}</td>
                    <td class="px-4 py-2 text-sm">{{ $task->account->account_name ?? 'Unknown' }}</td>
                    <td class="px-4 py-2 text-sm">{{ $task->entity_type }}</td>
                    <td class="px-4 py-2 text-sm text-red-600">{{ Str::limit($task->error, 50) }}</td>
                    <td class="px-4 py-2 text-sm">
                        <form action="{{ route('admin.queue.tasks.retry', $task->id) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-indigo-600 hover:text-indigo-900 text-xs">
                                Retry
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="mt-6 flex gap-4">
    <a href="{{ route('admin.queue.tasks') }}" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700">
        Посмотреть все задачи
    </a>
    <a href="{{ route('admin.queue.rate-limits') }}" class="bg-gray-600 text-white px-6 py-2 rounded hover:bg-gray-700">
        Rate Limits
    </a>
</div>

@endif
@endsection
