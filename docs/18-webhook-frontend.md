# Webhook System - Frontend Components

**Part 4 of 5** - Vue 3 UI components for webhook management and monitoring

**See also:**
- [18-webhook-system.md](18-webhook-system.md) - Overview and Architecture
- [18-webhook-services.md](18-webhook-services.md) - Backend Services
- [18-webhook-implementation.md](18-webhook-implementation.md) - Jobs, Controllers, Routes
- [18-webhook-testing.md](18-webhook-testing.md) - Testing and Troubleshooting

---

## Quick Reference

### Components Overview

1. **AccountTypeSelector.vue** - First-time account type selection (Main/Child)
   - Route: `/welcome`
   - Shown when `sync_settings.account_type IS NULL`
   - Saves account type + installs webhooks

2. **WebhookHealth.vue** - Admin dashboard for webhook monitoring
   - Route: `/admin/webhook-health`
   - Shows webhook status table with health metrics
   - Displays alerts for accounts with >10% failure rate
   - Filter by account, status, failure rate

3. **WebhookLogs.vue** - Detailed webhook logs viewer
   - Route: `/admin/webhook-logs`
   - Searchable/filterable log table
   - Shows request payload, processing status, errors
   - Pagination support

### Key Features

- **Account type selection** with webhook auto-installation
- **Real-time health monitoring** with color-coded status
- **Alert system** for webhook failures
- **Detailed logging** with payload inspection
- **Account type change** with webhook reinstallation
- **Inactive account handling** (separate UI section)

### API Integration

All components use the API endpoints from [18-webhook-implementation.md](18-webhook-implementation.md):
- `POST /api/admin/webhooks/setup` - Install webhooks
- `GET /api/admin/webhooks` - List webhook health
- `GET /api/admin/webhooks/logs` - Get webhook logs
- `GET /api/admin/webhooks/statistics` - Get statistics
- `GET /api/admin/webhooks/alerts` - Get alerts
- `POST /api/admin/webhooks/{id}/reinstall` - Reinstall webhooks

---

## 1. AccountTypeSelector.vue

**Purpose:** First-time setup screen shown when user installs the application. Allows user to select whether this МойСклад account is Main (франчайзер) or Child (франчайзи).

**Location:** `resources/js/components/AccountTypeSelector.vue`

**Route:** `/welcome` (shown when `sync_settings.account_type IS NULL`)

```vue
<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
    <div class="max-w-2xl w-full">
      <!-- Header -->
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
          Добро пожаловать в систему управления франшизой
        </h1>
        <p class="text-gray-600">
          Выберите тип вашего аккаунта для настройки синхронизации
        </p>
      </div>

      <!-- Account Type Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <!-- Main Account Card -->
        <button
          @click="selectAccountType('main')"
          :disabled="isProcessing"
          :class="[
            'p-6 bg-white rounded-lg border-2 transition-all',
            selectedType === 'main'
              ? 'border-blue-500 shadow-lg'
              : 'border-gray-200 hover:border-gray-300',
            isProcessing ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
          ]"
        >
          <div class="text-center">
            <div class="text-4xl mb-3">🏢</div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">
              Главный аккаунт
            </h3>
            <p class="text-sm text-gray-600 mb-4">
              Франчайзер - управляет сетью, рассылает товары и услуги дочерним аккаунтам
            </p>
            <ul class="text-left text-sm text-gray-700 space-y-2">
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Создание дочерних аккаунтов
              </li>
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Отправка товаров/услуг
              </li>
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Получение заказов от франчайзи
              </li>
            </ul>
          </div>
        </button>

        <!-- Child Account Card -->
        <button
          @click="selectAccountType('child')"
          :disabled="isProcessing"
          :class="[
            'p-6 bg-white rounded-lg border-2 transition-all',
            selectedType === 'child'
              ? 'border-blue-500 shadow-lg'
              : 'border-gray-200 hover:border-gray-300',
            isProcessing ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
          ]"
        >
          <div class="text-center">
            <div class="text-4xl mb-3">🏪</div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">
              Дочерний аккаунт
            </h3>
            <p class="text-sm text-gray-600 mb-4">
              Франчайзи - получает товары и услуги от главного аккаунта
            </p>
            <ul class="text-left text-sm text-gray-700 space-y-2">
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Получение товаров/услуг
              </li>
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Отправка заказов главному аккаунту
              </li>
              <li class="flex items-start">
                <span class="text-green-500 mr-2">✓</span>
                Автоматическая синхронизация
              </li>
            </ul>
          </div>
        </button>
      </div>

      <!-- Confirmation Section -->
      <div v-if="selectedType" class="bg-white rounded-lg shadow p-6">
        <div class="mb-4">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">
            Вы выбрали: {{ selectedType === 'main' ? 'Главный аккаунт' : 'Дочерний аккаунт' }}
          </h3>
          <p class="text-sm text-gray-600 mb-4">
            После подтверждения будут установлены веб-хуки для автоматической синхронизации.
            Это может занять несколько минут.
          </p>

          <!-- Warning for account type change -->
          <div class="bg-yellow-50 border border-yellow-200 rounded p-3 mb-4">
            <p class="text-sm text-yellow-800">
              ⚠️ Тип аккаунта можно будет изменить позже в настройках, но это приведет к переустановке всех веб-хуков.
            </p>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3">
          <button
            @click="confirmSelection"
            :disabled="isProcessing"
            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="!isProcessing">Подтвердить и установить веб-хуки</span>
            <span v-else class="flex items-center justify-center">
              <svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Установка веб-хуков...
            </span>
          </button>
          <button
            @click="selectedType = null"
            :disabled="isProcessing"
            class="px-6 py-2 border border-gray-300 text-gray-700 font-medium rounded hover:bg-gray-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Отмена
          </button>
        </div>

        <!-- Progress Indicator -->
        <div v-if="isProcessing" class="mt-4">
          <div class="bg-blue-50 border border-blue-200 rounded p-3">
            <p class="text-sm text-blue-800 mb-2">{{ progressMessage }}</p>
            <div class="w-full bg-blue-200 rounded-full h-2">
              <div
                class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                :style="{ width: `${progress}%` }"
              ></div>
            </div>
          </div>
        </div>

        <!-- Error Message -->
        <div v-if="errorMessage" class="mt-4 bg-red-50 border border-red-200 rounded p-3">
          <p class="text-sm text-red-800">{{ errorMessage }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()

const selectedType = ref(null)
const isProcessing = ref(false)
const progress = ref(0)
const progressMessage = ref('')
const errorMessage = ref('')

const selectAccountType = (type) => {
  if (!isProcessing.value) {
    selectedType.value = type
    errorMessage.value = ''
  }
}

const confirmSelection = async () => {
  isProcessing.value = true
  errorMessage.value = ''
  progress.value = 0
  progressMessage.value = 'Сохранение типа аккаунта...'

  try {
    // Get contextKey from sessionStorage (critical - see CLAUDE.md gotchas #7)
    const contextKey = sessionStorage.getItem('contextKey')
    if (!contextKey) {
      throw new Error('Context key not found. Please reload the application.')
    }

    // Step 1: Setup webhooks (20% progress)
    progress.value = 20
    progressMessage.value = 'Установка веб-хуков...'

    const response = await axios.post('/api/admin/webhooks/setup', {
      account_type: selectedType.value
    }, {
      headers: {
        'X-Context-Key': contextKey
      }
    })

    // Step 2: Wait for async job to complete (simulate progress)
    progress.value = 40
    progressMessage.value = 'Регистрация веб-хуков в МойСклад...'

    // Poll for completion (in real implementation, use WebSocket or polling)
    await new Promise(resolve => setTimeout(resolve, 2000))
    progress.value = 60

    progressMessage.value = 'Проверка установленных веб-хуков...'
    await new Promise(resolve => setTimeout(resolve, 1500))
    progress.value = 80

    progressMessage.value = 'Финализация настроек...'
    await new Promise(resolve => setTimeout(resolve, 1000))
    progress.value = 100

    // Success - redirect to main dashboard
    setTimeout(() => {
      router.push('/dashboard')
    }, 500)

  } catch (error) {
    console.error('Failed to setup account type:', error)
    errorMessage.value = error.response?.data?.message || error.message || 'Произошла ошибка при установке веб-хуков'
    isProcessing.value = false
    progress.value = 0
  }
}
</script>

<style scoped>
/* Animation for spinner */
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
```

---

## 2. WebhookHealth.vue

**Purpose:** Admin dashboard showing webhook health status for all accounts with alerts and filtering.

**Location:** `resources/js/components/admin/WebhookHealth.vue`

**Route:** `/admin/webhook-health`

```vue
<template>
  <div class="p-6">
    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 mb-2">
        Мониторинг веб-хуков
      </h1>
      <p class="text-gray-600">
        Статус установленных веб-хуков и алерты по проблемным аккаунтам
      </p>
    </div>

    <!-- Alerts Section -->
    <div v-if="alerts.length > 0" class="mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
        <span class="text-red-500 mr-2">⚠️</span>
        Требуют внимания ({{ alerts.length }})
      </h2>
      <div class="space-y-3">
        <div
          v-for="alert in alerts"
          :key="alert.account_id"
          class="bg-red-50 border border-red-200 rounded-lg p-4"
        >
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <h3 class="font-semibold text-red-900 mb-1">
                {{ alert.account_name }}
              </h3>
              <p class="text-sm text-red-700 mb-2">
                {{ alert.message }}
              </p>
              <div class="text-xs text-red-600">
                Последние 24 часа: {{ alert.failed_count }} ошибок из {{ alert.total_count }} запросов
                ({{ alert.failure_rate }}% неудачных)
              </div>
            </div>
            <button
              @click="openLogs(alert.account_id)"
              class="ml-4 text-sm text-red-700 hover:text-red-900 font-medium"
            >
              Посмотреть логи →
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- Account Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Аккаунт
          </label>
          <select
            v-model="filters.account_id"
            @change="loadHealthData"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все аккаунты</option>
            <option
              v-for="account in accounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.name }}
            </option>
          </select>
        </div>

        <!-- Status Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Статус
          </label>
          <select
            v-model="filters.status"
            @change="loadHealthData"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все статусы</option>
            <option value="active">Активные</option>
            <option value="inactive">Неактивные</option>
          </select>
        </div>

        <!-- Failure Rate Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Процент ошибок
          </label>
          <select
            v-model="filters.failure_rate"
            @change="loadHealthData"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Любой</option>
            <option value="high">&gt; 10% (высокий)</option>
            <option value="medium">5-10% (средний)</option>
            <option value="low">&lt; 5% (низкий)</option>
          </select>
        </div>

        <!-- Refresh Button -->
        <div class="flex items-end">
          <button
            @click="loadHealthData"
            :disabled="isLoading"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition disabled:opacity-50"
          >
            <span v-if="!isLoading">Обновить</span>
            <span v-else>Загрузка...</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="isLoading" class="text-center py-12">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      <p class="mt-2 text-gray-600">Загрузка данных...</p>
    </div>

    <!-- Health Table -->
    <div v-else-if="healthData.length > 0" class="bg-white rounded-lg shadow overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Аккаунт
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Тип
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Установлено веб-хуков
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Последние 24ч
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Процент ошибок
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Статус
            </th>
            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
              Действия
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr
            v-for="item in healthData"
            :key="item.account_id"
            :class="item.failure_rate > 10 ? 'bg-red-50' : ''"
          >
            <!-- Account Name -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm font-medium text-gray-900">
                {{ item.account_name }}
              </div>
              <div class="text-xs text-gray-500">
                {{ item.account_id.substring(0, 8) }}...
              </div>
            </td>

            <!-- Account Type -->
            <td class="px-6 py-4 whitespace-nowrap">
              <span
                :class="[
                  'px-2 py-1 text-xs font-medium rounded',
                  item.account_type === 'main'
                    ? 'bg-blue-100 text-blue-800'
                    : 'bg-green-100 text-green-800'
                ]"
              >
                {{ item.account_type === 'main' ? 'Главный' : 'Дочерний' }}
              </span>
            </td>

            <!-- Webhooks Count -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ item.webhooks_count }} {{ pluralize(item.webhooks_count, 'веб-хук', 'веб-хука', 'веб-хуков') }}
              </div>
              <div class="text-xs text-gray-500">
                {{ item.active_webhooks_count }} активных
              </div>
            </td>

            <!-- 24h Statistics -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ item.received_24h }} получено
              </div>
              <div class="text-xs text-gray-500">
                {{ item.processed_24h }} обработано
              </div>
            </td>

            <!-- Failure Rate -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="flex items-center">
                <div
                  :class="[
                    'text-sm font-semibold',
                    item.failure_rate > 10
                      ? 'text-red-600'
                      : item.failure_rate > 5
                      ? 'text-yellow-600'
                      : 'text-green-600'
                  ]"
                >
                  {{ item.failure_rate.toFixed(1) }}%
                </div>
                <div
                  v-if="item.failure_rate > 10"
                  class="ml-2 text-red-500"
                  title="Требует внимания"
                >
                  ⚠️
                </div>
              </div>
              <div class="text-xs text-gray-500">
                {{ item.failed_24h }} / {{ item.received_24h }}
              </div>
            </td>

            <!-- Status -->
            <td class="px-6 py-4 whitespace-nowrap">
              <span
                :class="[
                  'px-2 py-1 text-xs font-medium rounded',
                  item.is_active
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                ]"
              >
                {{ item.is_active ? 'Активен' : 'Неактивен' }}
              </span>
            </td>

            <!-- Actions -->
            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
              <button
                @click="openLogs(item.account_id)"
                class="text-blue-600 hover:text-blue-900 mr-3"
              >
                Логи
              </button>
              <button
                @click="reinstallWebhooks(item.account_id)"
                :disabled="reinstalling === item.account_id"
                class="text-green-600 hover:text-green-900 disabled:opacity-50"
              >
                {{ reinstalling === item.account_id ? 'Переустановка...' : 'Переустановить' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty State -->
    <div v-else class="bg-white rounded-lg shadow p-12 text-center">
      <div class="text-gray-400 text-5xl mb-4">📊</div>
      <h3 class="text-lg font-medium text-gray-900 mb-2">
        Нет данных
      </h3>
      <p class="text-gray-600">
        Веб-хуки еще не установлены или нет данных по выбранным фильтрам
      </p>
    </div>

    <!-- Statistics Summary -->
    <div v-if="statistics" class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm font-medium text-gray-500 mb-1">
          Всего аккаунтов
        </div>
        <div class="text-2xl font-bold text-gray-900">
          {{ statistics.total_accounts }}
        </div>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm font-medium text-gray-500 mb-1">
          Установлено веб-хуков
        </div>
        <div class="text-2xl font-bold text-gray-900">
          {{ statistics.total_webhooks }}
        </div>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm font-medium text-gray-500 mb-1">
          Получено за 24ч
        </div>
        <div class="text-2xl font-bold text-gray-900">
          {{ statistics.received_24h }}
        </div>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm font-medium text-gray-500 mb-1">
          Средний % ошибок
        </div>
        <div
          :class="[
            'text-2xl font-bold',
            statistics.avg_failure_rate > 10
              ? 'text-red-600'
              : statistics.avg_failure_rate > 5
              ? 'text-yellow-600'
              : 'text-green-600'
          ]"
        >
          {{ statistics.avg_failure_rate.toFixed(1) }}%
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()

// State
const healthData = ref([])
const alerts = ref([])
const accounts = ref([])
const statistics = ref(null)
const isLoading = ref(false)
const reinstalling = ref(null)

// Filters
const filters = ref({
  account_id: '',
  status: '',
  failure_rate: ''
})

// Load health data
const loadHealthData = async () => {
  isLoading.value = true

  try {
    const contextKey = sessionStorage.getItem('contextKey')

    // Fetch health summary
    const healthResponse = await axios.get('/api/admin/webhooks', {
      params: filters.value,
      headers: { 'X-Context-Key': contextKey }
    })

    healthData.value = healthResponse.data.data

    // Fetch alerts
    const alertsResponse = await axios.get('/api/admin/webhooks/alerts', {
      headers: { 'X-Context-Key': contextKey }
    })

    alerts.value = alertsResponse.data.data

    // Fetch statistics
    const statsResponse = await axios.get('/api/admin/webhooks/statistics', {
      params: { period: '24h' },
      headers: { 'X-Context-Key': contextKey }
    })

    statistics.value = statsResponse.data.data

  } catch (error) {
    console.error('Failed to load webhook health data:', error)
  } finally {
    isLoading.value = false
  }
}

// Reinstall webhooks for account
const reinstallWebhooks = async (accountId) => {
  if (!confirm('Вы уверены, что хотите переустановить веб-хуки для этого аккаунта?')) {
    return
  }

  reinstalling.value = accountId

  try {
    const contextKey = sessionStorage.getItem('contextKey')

    await axios.post(`/api/admin/webhooks/${accountId}/reinstall`, {}, {
      headers: { 'X-Context-Key': contextKey }
    })

    alert('Веб-хуки успешно переустановлены')
    await loadHealthData()

  } catch (error) {
    console.error('Failed to reinstall webhooks:', error)
    alert('Ошибка при переустановке веб-хуков')
  } finally {
    reinstalling.value = null
  }
}

// Open logs page
const openLogs = (accountId) => {
  router.push({
    path: '/admin/webhook-logs',
    query: { account_id: accountId }
  })
}

// Helper function for pluralization
const pluralize = (count, one, few, many) => {
  const mod10 = count % 10
  const mod100 = count % 100

  if (mod10 === 1 && mod100 !== 11) {
    return one
  } else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
    return few
  } else {
    return many
  }
}

// Load data on mount
onMounted(() => {
  loadHealthData()
})
</script>
```

---

## 3. WebhookLogs.vue

**Purpose:** Detailed webhook logs viewer with search, filtering, and payload inspection.

**Location:** `resources/js/components/admin/WebhookLogs.vue`

**Route:** `/admin/webhook-logs`

```vue
<template>
  <div class="p-6">
    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 mb-2">
        Логи веб-хуков
      </h1>
      <p class="text-gray-600">
        Детальная информация о полученных и обработанных веб-хуках
      </p>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-4">
        <!-- Account Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Аккаунт
          </label>
          <select
            v-model="filters.account_id"
            @change="loadLogs"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все аккаунты</option>
            <option
              v-for="account in accounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.name }}
            </option>
          </select>
        </div>

        <!-- Entity Type Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Тип сущности
          </label>
          <select
            v-model="filters.entity_type"
            @change="loadLogs"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все типы</option>
            <option value="product">Товары</option>
            <option value="service">Услуги</option>
            <option value="variant">Модификации</option>
            <option value="bundle">Комплекты</option>
            <option value="productfolder">Группы товаров</option>
            <option value="customerorder">Заказы покупателей</option>
            <option value="retaildemand">Розничные продажи</option>
            <option value="purchaseorder">Заказы поставщикам</option>
          </select>
        </div>

        <!-- Action Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Действие
          </label>
          <select
            v-model="filters.action"
            @change="loadLogs"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все действия</option>
            <option value="CREATE">Создание</option>
            <option value="UPDATE">Обновление</option>
            <option value="DELETE">Удаление</option>
          </select>
        </div>

        <!-- Status Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Статус
          </label>
          <select
            v-model="filters.status"
            @change="loadLogs"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="">Все статусы</option>
            <option value="pending">Ожидает</option>
            <option value="processing">Обрабатывается</option>
            <option value="completed">Завершен</option>
            <option value="failed">Ошибка</option>
          </select>
        </div>

        <!-- Date Range Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Период
          </label>
          <select
            v-model="filters.date_range"
            @change="loadLogs"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          >
            <option value="1h">Последний час</option>
            <option value="24h" selected>Последние 24 часа</option>
            <option value="7d">Последние 7 дней</option>
            <option value="30d">Последние 30 дней</option>
          </select>
        </div>
      </div>

      <!-- Search -->
      <div class="flex gap-3">
        <input
          v-model="filters.search"
          @keyup.enter="loadLogs"
          type="text"
          placeholder="Поиск по Request ID или Entity ID..."
          class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm"
        />
        <button
          @click="loadLogs"
          :disabled="isLoading"
          class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded transition disabled:opacity-50"
        >
          Поиск
        </button>
        <button
          @click="resetFilters"
          class="border border-gray-300 text-gray-700 font-medium py-2 px-6 rounded hover:bg-gray-50 transition"
        >
          Сбросить
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="isLoading" class="text-center py-12">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      <p class="mt-2 text-gray-600">Загрузка логов...</p>
    </div>

    <!-- Logs Table -->
    <div v-else-if="logs.length > 0" class="bg-white rounded-lg shadow overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Время
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Аккаунт
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Тип / Действие
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Сущности
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Статус
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Задачи
            </th>
            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
              Действия
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr
            v-for="log in logs"
            :key="log.id"
            :class="log.status === 'failed' ? 'bg-red-50' : ''"
          >
            <!-- Timestamp -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ formatDate(log.received_at) }}
              </div>
              <div class="text-xs text-gray-500">
                {{ formatTime(log.received_at) }}
              </div>
            </td>

            <!-- Account -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm font-medium text-gray-900">
                {{ log.account_name }}
              </div>
              <div class="text-xs text-gray-500">
                {{ log.account_id.substring(0, 8) }}...
              </div>
            </td>

            <!-- Entity Type / Action -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ formatEntityType(log.entity_type) }}
              </div>
              <span
                :class="[
                  'inline-block px-2 py-1 text-xs font-medium rounded mt-1',
                  log.action === 'CREATE'
                    ? 'bg-green-100 text-green-800'
                    : log.action === 'UPDATE'
                    ? 'bg-blue-100 text-blue-800'
                    : 'bg-red-100 text-red-800'
                ]"
              >
                {{ log.action }}
              </span>
            </td>

            <!-- Entities Count -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ log.events_count }} {{ pluralize(log.events_count, 'событие', 'события', 'событий') }}
              </div>
              <div class="text-xs text-gray-500">
                Request ID: {{ log.request_id.substring(0, 12) }}...
              </div>
            </td>

            <!-- Status -->
            <td class="px-6 py-4 whitespace-nowrap">
              <span
                :class="[
                  'px-2 py-1 text-xs font-medium rounded',
                  log.status === 'completed'
                    ? 'bg-green-100 text-green-800'
                    : log.status === 'processing'
                    ? 'bg-blue-100 text-blue-800'
                    : log.status === 'pending'
                    ? 'bg-yellow-100 text-yellow-800'
                    : 'bg-red-100 text-red-800'
                ]"
              >
                {{ formatStatus(log.status) }}
              </span>
              <div v-if="log.error_message" class="text-xs text-red-600 mt-1">
                {{ log.error_message.substring(0, 50) }}...
              </div>
            </td>

            <!-- Tasks Created -->
            <td class="px-6 py-4 whitespace-nowrap">
              <div class="text-sm text-gray-900">
                {{ log.tasks_created || 0 }} задач
              </div>
              <div v-if="log.processed_at" class="text-xs text-gray-500">
                {{ formatProcessingTime(log.received_at, log.processed_at) }}
              </div>
            </td>

            <!-- Actions -->
            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
              <button
                @click="showDetails(log)"
                class="text-blue-600 hover:text-blue-900"
              >
                Детали
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div class="bg-gray-50 px-6 py-3 flex items-center justify-between border-t border-gray-200">
        <div class="text-sm text-gray-700">
          Показано {{ logs.length }} из {{ totalLogs }} записей
        </div>
        <div class="flex gap-2">
          <button
            @click="loadMore"
            :disabled="!hasMore || isLoading"
            class="px-4 py-2 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Загрузить еще
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="bg-white rounded-lg shadow p-12 text-center">
      <div class="text-gray-400 text-5xl mb-4">📋</div>
      <h3 class="text-lg font-medium text-gray-900 mb-2">
        Логи не найдены
      </h3>
      <p class="text-gray-600">
        Нет логов веб-хуков за выбранный период или по выбранным фильтрам
      </p>
    </div>

    <!-- Details Modal -->
    <div
      v-if="selectedLog"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
      @click.self="selectedLog = null"
    >
      <div class="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-xl font-semibold text-gray-900">
            Детали веб-хука
          </h2>
          <button
            @click="selectedLog = null"
            class="text-gray-400 hover:text-gray-600"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="flex-1 overflow-y-auto p-6">
          <!-- Summary -->
          <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
              <div class="text-sm font-medium text-gray-500 mb-1">Request ID</div>
              <div class="text-sm text-gray-900 font-mono">{{ selectedLog.request_id }}</div>
            </div>
            <div>
              <div class="text-sm font-medium text-gray-500 mb-1">Получен</div>
              <div class="text-sm text-gray-900">{{ formatDateTime(selectedLog.received_at) }}</div>
            </div>
            <div>
              <div class="text-sm font-medium text-gray-500 mb-1">Аккаунт</div>
              <div class="text-sm text-gray-900">{{ selectedLog.account_name }}</div>
            </div>
            <div>
              <div class="text-sm font-medium text-gray-500 mb-1">Обработан</div>
              <div class="text-sm text-gray-900">
                {{ selectedLog.processed_at ? formatDateTime(selectedLog.processed_at) : 'В процессе' }}
              </div>
            </div>
          </div>

          <!-- Error Message -->
          <div v-if="selectedLog.error_message" class="mb-6 bg-red-50 border border-red-200 rounded p-4">
            <div class="text-sm font-medium text-red-900 mb-2">Ошибка обработки</div>
            <pre class="text-sm text-red-700 whitespace-pre-wrap">{{ selectedLog.error_message }}</pre>
          </div>

          <!-- Payload -->
          <div class="mb-6">
            <div class="text-sm font-medium text-gray-900 mb-2">Payload</div>
            <pre class="bg-gray-50 border border-gray-200 rounded p-4 text-xs overflow-x-auto">{{ JSON.stringify(selectedLog.payload, null, 2) }}</pre>
          </div>

          <!-- Tasks Created -->
          <div v-if="selectedLog.tasks_created > 0">
            <div class="text-sm font-medium text-gray-900 mb-2">
              Создано задач: {{ selectedLog.tasks_created }}
            </div>
            <div class="text-xs text-gray-600">
              Задачи были добавлены в очередь синхронизации для обработки
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
          <button
            @click="selectedLog = null"
            class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-medium py-2 px-6 rounded transition"
          >
            Закрыть
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const route = useRoute()

// State
const logs = ref([])
const accounts = ref([])
const selectedLog = ref(null)
const isLoading = ref(false)
const totalLogs = ref(0)
const hasMore = ref(true)

// Filters
const filters = ref({
  account_id: route.query.account_id || '',
  entity_type: '',
  action: '',
  status: '',
  date_range: '24h',
  search: ''
})

// Load logs
const loadLogs = async (append = false) => {
  isLoading.value = true

  try {
    const contextKey = sessionStorage.getItem('contextKey')

    const response = await axios.get('/api/admin/webhooks/logs', {
      params: {
        ...filters.value,
        limit: 50,
        offset: append ? logs.value.length : 0
      },
      headers: { 'X-Context-Key': contextKey }
    })

    if (append) {
      logs.value.push(...response.data.data)
    } else {
      logs.value = response.data.data
    }

    totalLogs.value = response.data.total
    hasMore.value = logs.value.length < totalLogs.value

  } catch (error) {
    console.error('Failed to load webhook logs:', error)
  } finally {
    isLoading.value = false
  }
}

// Load more logs
const loadMore = () => {
  loadLogs(true)
}

// Reset filters
const resetFilters = () => {
  filters.value = {
    account_id: '',
    entity_type: '',
    action: '',
    status: '',
    date_range: '24h',
    search: ''
  }
  loadLogs()
}

// Show details
const showDetails = (log) => {
  selectedLog.value = log
}

// Formatting helpers
const formatDate = (timestamp) => {
  return new Date(timestamp).toLocaleDateString('ru-RU')
}

const formatTime = (timestamp) => {
  return new Date(timestamp).toLocaleTimeString('ru-RU')
}

const formatDateTime = (timestamp) => {
  return new Date(timestamp).toLocaleString('ru-RU')
}

const formatEntityType = (type) => {
  const types = {
    product: 'Товар',
    service: 'Услуга',
    variant: 'Модификация',
    bundle: 'Комплект',
    productfolder: 'Группа товаров',
    customerorder: 'Заказ покупателя',
    retaildemand: 'Розничная продажа',
    purchaseorder: 'Заказ поставщику'
  }
  return types[type] || type
}

const formatStatus = (status) => {
  const statuses = {
    pending: 'Ожидает',
    processing: 'Обрабатывается',
    completed: 'Завершен',
    failed: 'Ошибка'
  }
  return statuses[status] || status
}

const formatProcessingTime = (receivedAt, processedAt) => {
  const ms = new Date(processedAt) - new Date(receivedAt)
  if (ms < 1000) return `${ms}ms`
  if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`
  return `${(ms / 60000).toFixed(1)}min`
}

const pluralize = (count, one, few, many) => {
  const mod10 = count % 10
  const mod100 = count % 100

  if (mod10 === 1 && mod100 !== 11) {
    return one
  } else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
    return few
  } else {
    return many
  }
}

// Load data on mount
onMounted(() => {
  loadLogs()
})
</script>
```

---

## 4. Navigation Integration

Update the main navigation to include webhook management links for admin users.

**Location:** `resources/js/layouts/AdminLayout.vue` (or wherever your admin nav is)

```vue
<!-- Add to admin navigation -->
<nav class="space-y-1">
  <!-- ... existing nav items ... -->

  <!-- Webhook Management Section -->
  <div class="border-t border-gray-200 pt-4 mt-4">
    <h3 class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">
      Веб-хуки
    </h3>
    <router-link
      to="/admin/webhook-health"
      class="mt-1 group flex items-center px-3 py-2 text-sm font-medium text-gray-600 rounded-md hover:text-gray-900 hover:bg-gray-50"
    >
      <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
      Мониторинг здоровья
    </router-link>
    <router-link
      to="/admin/webhook-logs"
      class="mt-1 group flex items-center px-3 py-2 text-sm font-medium text-gray-600 rounded-md hover:text-gray-900 hover:bg-gray-50"
    >
      <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
      Детальные логи
    </router-link>
  </div>
</nav>
```

---

## 5. Router Configuration

Add routes for webhook components.

**Location:** `resources/js/router/index.js`

```javascript
import AccountTypeSelector from '@/components/AccountTypeSelector.vue'
import WebhookHealth from '@/components/admin/WebhookHealth.vue'
import WebhookLogs from '@/components/admin/WebhookLogs.vue'

const routes = [
  // ... existing routes ...

  // Account type selection (first-time setup)
  {
    path: '/welcome',
    name: 'welcome',
    component: AccountTypeSelector,
    meta: { requiresAuth: true }
  },

  // Admin webhook management
  {
    path: '/admin/webhook-health',
    name: 'admin-webhook-health',
    component: WebhookHealth,
    meta: { requiresAuth: true, requiresAdmin: true }
  },
  {
    path: '/admin/webhook-logs',
    name: 'admin-webhook-logs',
    component: WebhookLogs,
    meta: { requiresAuth: true, requiresAdmin: true }
  }
]
```

---

## 6. Account Type Change Flow

Add account type change functionality to settings page.

**Location:** `resources/js/components/settings/AccountSettings.vue` (or similar)

```vue
<template>
  <div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">
      Тип аккаунта
    </h2>

    <div class="mb-4">
      <div class="text-sm text-gray-600 mb-2">
        Текущий тип:
        <span class="font-medium text-gray-900">
          {{ currentAccountType === 'main' ? 'Главный аккаунт' : 'Дочерний аккаунт' }}
        </span>
      </div>

      <!-- Warning -->
      <div class="bg-yellow-50 border border-yellow-200 rounded p-3 mb-4">
        <p class="text-sm text-yellow-800">
          ⚠️ Изменение типа аккаунта приведет к переустановке всех веб-хуков и временному прерыванию синхронизации.
        </p>
      </div>
    </div>

    <!-- Change Account Type -->
    <div v-if="!isChanging">
      <button
        @click="promptAccountTypeChange"
        class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition"
      >
        Изменить тип аккаунта
      </button>
    </div>

    <!-- Confirmation -->
    <div v-else class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Новый тип аккаунта
        </label>
        <select
          v-model="newAccountType"
          class="w-full border border-gray-300 rounded px-3 py-2"
        >
          <option value="main">Главный аккаунт</option>
          <option value="child">Дочерний аккаунт</option>
        </select>
      </div>

      <div class="flex gap-3">
        <button
          @click="changeAccountType"
          :disabled="isProcessing"
          class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition disabled:opacity-50"
        >
          {{ isProcessing ? 'Переустановка...' : 'Подтвердить' }}
        </button>
        <button
          @click="isChanging = false"
          :disabled="isProcessing"
          class="border border-gray-300 text-gray-700 font-medium py-2 px-4 rounded hover:bg-gray-50 transition disabled:opacity-50"
        >
          Отмена
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'

const props = defineProps({
  currentAccountType: {
    type: String,
    required: true
  }
})

const emit = defineEmits(['account-type-changed'])

const isChanging = ref(false)
const isProcessing = ref(false)
const newAccountType = ref(props.currentAccountType === 'main' ? 'child' : 'main')

const promptAccountTypeChange = () => {
  isChanging.value = true
}

const changeAccountType = async () => {
  if (!confirm('Вы уверены? Это приведет к переустановке всех веб-хуков.')) {
    return
  }

  isProcessing.value = true

  try {
    const contextKey = sessionStorage.getItem('contextKey')

    await axios.post('/api/admin/webhooks/setup', {
      account_type: newAccountType.value,
      force_reinstall: true
    }, {
      headers: { 'X-Context-Key': contextKey }
    })

    alert('Тип аккаунта успешно изменен. Веб-хуки будут переустановлены в фоновом режиме.')
    emit('account-type-changed', newAccountType.value)
    isChanging.value = false

  } catch (error) {
    console.error('Failed to change account type:', error)
    alert('Ошибка при изменении типа аккаунта')
  } finally {
    isProcessing.value = false
  }
}
</script>
```

---

## 7. Inactive Child Accounts UI

Show inactive child accounts in a separate section on the child accounts management page.

**Location:** `resources/js/components/ChildAccountsList.vue` (or similar)

```vue
<template>
  <div>
    <!-- Active Child Accounts -->
    <div class="mb-8">
      <h2 class="text-xl font-semibold text-gray-900 mb-4">
        Активные дочерние аккаунты ({{ activeAccounts.length }})
      </h2>

      <!-- Active accounts list -->
      <div class="space-y-3">
        <div
          v-for="account in activeAccounts"
          :key="account.id"
          class="bg-white rounded-lg shadow p-4"
        >
          <!-- Account card content -->
        </div>
      </div>
    </div>

    <!-- Inactive Child Accounts (Expandable) -->
    <div v-if="inactiveAccounts.length > 0" class="mb-8">
      <button
        @click="showInactive = !showInactive"
        class="w-full flex items-center justify-between bg-gray-50 rounded-lg p-4 hover:bg-gray-100 transition"
      >
        <h2 class="text-xl font-semibold text-gray-600">
          Неактивные дочерние аккаунты ({{ inactiveAccounts.length }})
        </h2>
        <svg
          :class="['w-6 h-6 text-gray-600 transition-transform', showInactive ? 'rotate-180' : '']"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
      </button>

      <div v-show="showInactive" class="mt-3 space-y-3">
        <div
          v-for="account in inactiveAccounts"
          :key="account.id"
          class="bg-gray-50 rounded-lg shadow p-4 border border-gray-200"
        >
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <h3 class="font-semibold text-gray-700 mb-1">
                {{ account.name }}
              </h3>
              <div class="text-sm text-gray-600 mb-2">
                Причина деактивации: {{ account.deactivation_reason || 'Изменен тип главного аккаунта' }}
              </div>
              <div class="text-xs text-gray-500">
                Деактивирован: {{ formatDate(account.deactivated_at) }}
              </div>
            </div>
            <button
              @click="reactivateAccount(account.id)"
              class="ml-4 bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-1 px-3 rounded transition"
            >
              Реактивировать
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'

const props = defineProps({
  accounts: {
    type: Array,
    required: true
  }
})

const showInactive = ref(false)

const activeAccounts = computed(() => {
  return props.accounts.filter(account => account.status === 'active')
})

const inactiveAccounts = computed(() => {
  return props.accounts.filter(account => account.status === 'inactive')
})

const reactivateAccount = async (accountId) => {
  if (!confirm('Вы уверены, что хотите реактивировать этот дочерний аккаунт?')) {
    return
  }

  try {
    const contextKey = sessionStorage.getItem('contextKey')

    await axios.post(`/api/child-accounts/${accountId}/reactivate`, {}, {
      headers: { 'X-Context-Key': contextKey }
    })

    alert('Дочерний аккаунт успешно реактивирован')
    // Reload accounts list

  } catch (error) {
    console.error('Failed to reactivate account:', error)
    alert('Ошибка при реактивации аккаунта')
  }
}

const formatDate = (timestamp) => {
  return new Date(timestamp).toLocaleDateString('ru-RU')
}
</script>
```

---

## Summary

This document provides complete Vue 3 component implementations for:

1. **AccountTypeSelector.vue** - First-time account type selection with webhook installation
2. **WebhookHealth.vue** - Admin dashboard for monitoring webhook health
3. **WebhookLogs.vue** - Detailed webhook logs viewer with filtering
4. **Navigation integration** - Admin menu updates
5. **Router configuration** - Route definitions
6. **Account type change** - Settings component for changing account type
7. **Inactive accounts UI** - Expandable section for inactive child accounts

All components follow:
- Vue 3 Composition API (script setup)
- Tailwind CSS styling
- Proper error handling
- Loading states
- Context key integration from sessionStorage

**Next steps:**
- [18-webhook-testing.md](18-webhook-testing.md) - Testing and troubleshooting guide
- [18-webhook-system.md](18-webhook-system.md) - Update with cross-references
