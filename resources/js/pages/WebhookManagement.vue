<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Управление Вебхуками</h1>
        <p class="mt-1 text-sm text-gray-500">
          Мониторинг и управление вебхуками МойСклад для всех аккаунтов
        </p>
      </div>
      <div class="flex space-x-3">
        <button
          @click="refreshData"
          :disabled="loading"
          class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
        >
          <svg class="h-4 w-4 mr-2" :class="{ 'animate-spin': loading }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Обновить
        </button>
        <button
          @click="healthCheckAll"
          :disabled="processing"
          class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
        >
          Проверить все
        </button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Всего аккаунтов</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ summary.totalAccounts }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-green-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Здоровых</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ summary.healthyAccounts }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-yellow-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">С проблемами</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ summary.degradedAccounts }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white overflow-hidden shadow rounded-lg">
        <div class="p-5">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-red-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Критичных</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ summary.criticalAccounts }}</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Accounts Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
      <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
        <h3 class="text-lg leading-6 font-medium text-gray-900">
          Аккаунты и статус вебхуков
        </h3>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Аккаунт
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Тип
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Вебхуки
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Статус
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Получено
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Ошибок
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Действия
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-if="loading" class="animate-pulse">
              <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                Загрузка...
              </td>
            </tr>
            <tr v-else-if="accounts.length === 0">
              <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                Нет аккаунтов
              </td>
            </tr>
            <tr v-else v-for="account in accounts" :key="account.account_id" class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">{{ account.account_name }}</div>
                <div class="text-sm text-gray-500 font-mono">{{ account.account_id }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                      :class="account.account_type === 'main' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'">
                  {{ account.account_type === 'main' ? 'Главный' : 'Дочерний' }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ account.active_webhooks }}/{{ account.total_webhooks }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                      :class="getHealthClass(account)">
                  {{ getHealthLabel(account) }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ account.total_received.toLocaleString() }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ account.total_failed }}</div>
                <div class="text-xs text-gray-500">{{ account.failure_rate }}%</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                <button
                  @click="viewAccount(account.account_id)"
                  class="text-blue-600 hover:text-blue-900"
                  title="Просмотр"
                >
                  Просмотр
                </button>
                <button
                  @click="reinstall(account)"
                  :disabled="processing"
                  class="text-orange-600 hover:text-orange-900 disabled:opacity-50"
                  title="Переустановить"
                >
                  Переустановить
                </button>
                <button
                  @click="healthCheck(account.account_id)"
                  :disabled="processing"
                  class="text-green-600 hover:text-green-900 disabled:opacity-50"
                  title="Проверка здоровья"
                >
                  Проверить
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Selected Account Details Modal -->
    <div v-if="selectedAccount" class="fixed z-10 inset-0 overflow-y-auto" @click.self="selectedAccount = null">
      <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-lg leading-6 font-medium text-gray-900">
                Детали аккаунта: {{ selectedAccount.account_name }}
              </h3>
              <button @click="selectedAccount = null" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="text-sm text-gray-500 mb-4">
              ID: <span class="font-mono">{{ selectedAccount.account_id }}</span>
            </div>
            <div class="space-y-4">
              <!-- Здесь можно добавить детальную информацию, логи, статистику -->
              <div class="bg-gray-50 p-4 rounded">
                <p class="text-sm text-gray-700">
                  Детальная информация будет загружена из API endpoints
                </p>
              </div>
            </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button
              @click="selectedAccount = null"
              type="button"
              class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
            >
              Закрыть
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';

const loading = ref(false);
const processing = ref(false);
const accounts = ref([]);
const selectedAccount = ref(null);

const summary = computed(() => {
  const total = accounts.value.length;
  let healthy = 0;
  let degraded = 0;
  let critical = 0;

  accounts.value.forEach(acc => {
    const failureRate = acc.failure_rate || 0;
    const inactiveWebhooks = acc.total_webhooks - acc.active_webhooks;

    if (inactiveWebhooks > 0 || failureRate > 10) {
      if (inactiveWebhooks > (acc.total_webhooks * 0.5) || failureRate > 25) {
        critical++;
      } else {
        degraded++;
      }
    } else {
      healthy++;
    }
  });

  return {
    totalAccounts: total,
    healthyAccounts: healthy,
    degradedAccounts: degraded,
    criticalAccounts: critical
  };
});

const fetchAccounts = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/admin/webhooks/accounts');
    accounts.value = response.data.data || [];
  } catch (error) {
    console.error('Failed to fetch webhook accounts:', error);
    alert('Ошибка загрузки данных: ' + (error.response?.data?.message || error.message));
  } finally {
    loading.value = false;
  }
};

const refreshData = () => {
  fetchAccounts();
};

const healthCheckAll = async () => {
  if (!confirm('Запустить проверку здоровья для всех аккаунтов?')) return;

  processing.value = true;
  try {
    await axios.post('/api/admin/webhooks/health-check-all', {
      auto_heal: true
    });
    alert('Проверка здоровья запущена. Результаты будут доступны через несколько минут.');
    setTimeout(fetchAccounts, 3000);
  } catch (error) {
    console.error('Health check failed:', error);
    alert('Ошибка: ' + (error.response?.data?.message || error.message));
  } finally {
    processing.value = false;
  }
};

const viewAccount = (accountId) => {
  const account = accounts.value.find(a => a.account_id === accountId);
  if (account) {
    selectedAccount.value = account;
  }
};

const reinstall = async (account) => {
  if (!confirm(`Переустановить все вебхуки для аккаунта "${account.account_name}"?`)) return;

  processing.value = true;
  try {
    await axios.post(`/api/admin/webhooks/${account.account_id}/reinstall`, {
      account_type: account.account_type
    });
    alert('Переустановка запущена. Обновите страницу через минуту.');
    setTimeout(fetchAccounts, 3000);
  } catch (error) {
    console.error('Reinstall failed:', error);
    alert('Ошибка: ' + (error.response?.data?.message || error.message));
  } finally {
    processing.value = false;
  }
};

const healthCheck = async (accountId) => {
  processing.value = true;
  try {
    await axios.post(`/api/admin/webhooks/${accountId}/health-check`, {
      auto_heal: true
    });
    alert('Проверка здоровья запущена.');
    setTimeout(fetchAccounts, 2000);
  } catch (error) {
    console.error('Health check failed:', error);
    alert('Ошибка: ' + (error.response?.data?.message || error.message));
  } finally {
    processing.value = false;
  }
};

const getHealthClass = (account) => {
  const failureRate = account.failure_rate || 0;
  const inactiveWebhooks = account.total_webhooks - account.active_webhooks;

  if (inactiveWebhooks > (account.total_webhooks * 0.5) || failureRate > 25) {
    return 'bg-red-100 text-red-800';
  } else if (inactiveWebhooks > 0 || failureRate > 10) {
    return 'bg-yellow-100 text-yellow-800';
  }
  return 'bg-green-100 text-green-800';
};

const getHealthLabel = (account) => {
  const failureRate = account.failure_rate || 0;
  const inactiveWebhooks = account.total_webhooks - account.active_webhooks;

  if (inactiveWebhooks > (account.total_webhooks * 0.5) || failureRate > 25) {
    return 'Критично';
  } else if (inactiveWebhooks > 0 || failureRate > 10) {
    return 'Проблемы';
  }
  return 'Здоров';
};

onMounted(() => {
  fetchAccounts();
});
</script>
