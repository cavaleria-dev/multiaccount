@extends('admin.layout')

@section('title', 'Webhook Processing Monitoring')

@section('content')
<div class="container mx-auto px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">Webhook Processing Monitoring</h1>

    {{-- Filters --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" action="{{ route('admin.webhooks.monitoring') }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                {{-- Period --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Период</label>
                    <select name="period" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="last_24h" {{ $filters['period'] === 'last_24h' ? 'selected' : '' }}>Последние 24 часа</option>
                        <option value="last_7d" {{ $filters['period'] === 'last_7d' ? 'selected' : '' }}>Последние 7 дней</option>
                        <option value="last_30d" {{ $filters['period'] === 'last_30d' ? 'selected' : '' }}>Последние 30 дней</option>
                        <option value="custom" {{ $filters['period'] === 'custom' ? 'selected' : '' }}>Свой период</option>
                    </select>
                </div>

                {{-- Account --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Аккаунт</label>
                    <select name="account_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Все аккаунты</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->account_id }}" {{ $filters['account_id'] === $account->account_id ? 'selected' : '' }}>
                                {{ $account->account_name ?? $account->account_id }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Status --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Все статусы</option>
                        <option value="pending" {{ $filters['status'] === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="processing" {{ $filters['status'] === 'processing' ? 'selected' : '' }}>Processing</option>
                        <option value="completed" {{ $filters['status'] === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="failed" {{ $filters['status'] === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>

                {{-- Entity Type --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип сущности</label>
                    <select name="entity_type" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Все типы</option>
                        <option value="product" {{ $filters['entity_type'] === 'product' ? 'selected' : '' }}>Product</option>
                        <option value="service" {{ $filters['entity_type'] === 'service' ? 'selected' : '' }}>Service</option>
                        <option value="variant" {{ $filters['entity_type'] === 'variant' ? 'selected' : '' }}>Variant</option>
                        <option value="bundle" {{ $filters['entity_type'] === 'bundle' ? 'selected' : '' }}>Bundle</option>
                        <option value="customerorder" {{ $filters['entity_type'] === 'customerorder' ? 'selected' : '' }}>Customer Order</option>
                        <option value="retaildemand" {{ $filters['entity_type'] === 'retaildemand' ? 'selected' : '' }}>Retail Demand</option>
                    </select>
                </div>

                {{-- Action --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Действие</label>
                    <select name="action" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Все действия</option>
                        <option value="CREATE" {{ $filters['action'] === 'CREATE' ? 'selected' : '' }}>CREATE</option>
                        <option value="UPDATE" {{ $filters['action'] === 'UPDATE' ? 'selected' : '' }}>UPDATE</option>
                        <option value="DELETE" {{ $filters['action'] === 'DELETE' ? 'selected' : '' }}>DELETE</option>
                    </select>
                </div>

                {{-- Errors Only --}}
                <div class="flex items-end">
                    <label class="flex items-center">
                        <input type="checkbox" name="errors_only" value="1" {{ $filters['errors_only'] ? 'checked' : '' }}
                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-gray-700">Только ошибки</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    Обновить
                </button>
            </div>
        </form>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        {{-- Total Webhooks --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Всего Webhooks</p>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($summary['total_webhooks']) }}</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                Pending: {{ $summary['pending'] }} | Processing: {{ $summary['processing'] }}
            </p>
        </div>

        {{-- Success Rate --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Success Rate</p>
                    <p class="text-3xl font-bold {{ $summary['success_rate'] >= 90 ? 'text-green-600' : ($summary['success_rate'] >= 70 ? 'text-yellow-600' : 'text-red-600') }}">
                        {{ number_format($summary['success_rate'], 1) }}%
                    </p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                Completed: {{ $summary['completed'] }} / {{ $summary['total_webhooks'] }}
            </p>
        </div>

        {{-- Failed Webhooks --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Ошибки</p>
                    <p class="text-3xl font-bold {{ $summary['failed'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($summary['failed']) }}
                    </p>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                Partial updates failed: {{ $summary['partial_updates_failed'] }}
            </p>
        </div>

        {{-- Pending Tasks --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">В очереди</p>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($summary['pending_tasks']) }}</p>
                </div>
                <div class="p-3 bg-yellow-100 rounded-full">
                    <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                @if($summary['avg_processing_time'])
                    Avg time: {{ number_format($summary['avg_processing_time']) }}ms
                @else
                    No timing data
                @endif
            </p>
        </div>
    </div>

    {{-- Webhook Logs --}}
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold">📊 Webhook Logs</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Updated Fields</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Processing Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($webhookLogs as $log)
                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="toggleDetails('webhook-{{ $log->id }}')">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $log->id }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                {{ $log->account->account_name ?? substr($log->account_id, 0, 8) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="font-mono">{{ $log->entity_type }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    {{ $log->action === 'CREATE' ? 'bg-blue-100 text-blue-800' : '' }}
                                    {{ $log->action === 'UPDATE' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $log->action === 'DELETE' ? 'bg-red-100 text-red-800' : '' }}">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                @if($log->updated_fields && count($log->updated_fields) > 0)
                                    <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded">
                                        {{ count($log->updated_fields) }} fields
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    {{ $log->status === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $log->status === 'failed' ? 'bg-red-100 text-red-800' : '' }}
                                    {{ $log->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                    {{ $log->status === 'processing' ? 'bg-blue-100 text-blue-800' : '' }}">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                @if($log->processing_time_ms)
                                    {{ number_format($log->processing_time_ms) }}ms
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                {{ $log->created_at->format('d.m.Y H:i:s') }}
                            </td>
                        </tr>
                        <tr id="webhook-{{ $log->id }}" class="hidden bg-gray-50">
                            <td colspan="8" class="px-6 py-4">
                                <div class="text-sm space-y-2">
                                    @if($log->updated_fields)
                                        <div>
                                            <strong>Updated Fields:</strong>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach($log->updated_fields as $field)
                                                    <span class="px-2 py-1 text-xs bg-indigo-100 text-indigo-800 rounded">{{ $field }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if($log->error_message)
                                        <div>
                                            <strong class="text-red-600">Error:</strong>
                                            <pre class="mt-1 p-2 bg-red-50 text-red-800 text-xs rounded overflow-x-auto">{{ $log->error_message }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                Нет данных за выбранный период
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($webhookLogs->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $webhookLogs->links() }}
            </div>
        @endif
    </div>

    {{-- Entity Update Logs (Errors) --}}
    @if($updateErrors->isNotEmpty())
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-red-600">🔄 Partial Update Errors</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Strategy</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accounts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($updateErrors as $error)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="toggleDetails('update-{{ $error->id }}')">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $error->id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="font-mono">{{ $error->entity_type }}</span>
                                    <br>
                                    <span class="text-xs text-gray-500">{{ substr($error->main_entity_id, 0, 8) }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        {{ $error->update_strategy === 'SKIP' ? 'bg-gray-100 text-gray-800' : '' }}
                                        {{ $error->update_strategy === 'PRICES_ONLY' ? 'bg-purple-100 text-purple-800' : '' }}
                                        {{ $error->update_strategy === 'ATTRIBUTES_ONLY' ? 'bg-indigo-100 text-indigo-800' : '' }}
                                        {{ $error->update_strategy === 'BASE_FIELDS_ONLY' ? 'bg-teal-100 text-teal-800' : '' }}
                                        {{ $error->update_strategy === 'MIXED_SIMPLE' ? 'bg-blue-100 text-blue-800' : '' }}
                                        {{ $error->update_strategy === 'FULL_SYNC' ? 'bg-orange-100 text-orange-800' : '' }}">
                                        {{ $error->update_strategy }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div class="text-xs">
                                        <div>Main: {{ $error->mainAccount->account_name ?? substr($error->main_account_id, 0, 8) }}</div>
                                        <div>Child: {{ $error->childAccount->account_name ?? substr($error->child_account_id, 0, 8) }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="text-red-600 text-xs">{{ Str::limit($error->error_message, 50) }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    @if($error->processing_time_ms)
                                        {{ number_format($error->processing_time_ms) }}ms
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $error->created_at->format('d.m.Y H:i:s') }}
                                </td>
                            </tr>
                            <tr id="update-{{ $error->id }}" class="hidden bg-red-50">
                                <td colspan="7" class="px-6 py-4">
                                    <div class="text-sm space-y-2">
                                        @if($error->error_message)
                                            <div>
                                                <strong class="text-red-600">Full Error:</strong>
                                                <pre class="mt-1 p-2 bg-white text-red-800 text-xs rounded overflow-x-auto">{{ $error->error_message }}</pre>
                                            </div>
                                        @endif
                                        @if($error->updated_fields_received)
                                            <div>
                                                <strong>Received Fields:</strong>
                                                <div class="mt-1 flex flex-wrap gap-1">
                                                    @foreach($error->updated_fields_received as $field)
                                                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded">{{ $field }}</span>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        @if($error->fields_classified)
                                            <div>
                                                <strong>Classified:</strong>
                                                <pre class="mt-1 p-2 bg-white text-xs rounded overflow-x-auto">{{ json_encode($error->fields_classified, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Sync Queue Tasks --}}
    @if($queueTasks->isNotEmpty())
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold">⏳ Related Queue Tasks</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Task ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Operation</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attempts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($queueTasks as $task)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <a href="{{ route('admin.queue.tasks.show', $task->id) }}" class="text-indigo-600 hover:text-indigo-900">
                                        #{{ $task->id }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="font-mono">{{ $task->entity_type }}</span>
                                    <br>
                                    <span class="text-xs text-gray-500">{{ substr($task->entity_id, 0, 8) }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $task->operation }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        {{ $task->status === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                                        {{ $task->status === 'failed' ? 'bg-red-100 text-red-800' : '' }}
                                        {{ $task->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                        {{ $task->status === 'processing' ? 'bg-blue-100 text-blue-800' : '' }}">
                                        {{ $task->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $task->priority }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $task->attempts }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $task->scheduled_at ? $task->scheduled_at->format('d.m.Y H:i:s') : '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($task->status === 'failed')
                                        <form action="{{ route('admin.queue.tasks.retry', $task->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-900">Retry</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
function toggleDetails(id) {
    const element = document.getElementById(id);
    if (element) {
        element.classList.toggle('hidden');
    }
}
</script>
@endsection
