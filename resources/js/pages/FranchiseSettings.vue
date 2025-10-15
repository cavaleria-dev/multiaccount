<template>
  <div class="space-y-6">
    <!-- Заголовок -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900">Настройки синхронизации</h1>
        <p class="mt-2 text-sm text-gray-700" v-if="accountName">
          Аккаунт: <span class="font-medium">{{ accountName }}</span>
        </p>
      </div>
      <router-link
        to="/app/accounts"
        class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-700"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Назад к списку аккаунтов
      </router-link>
    </div>

    <!-- Индикатор загрузки -->
    <div v-if="loading" class="bg-white shadow rounded-lg p-8 text-center">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      <p class="mt-2 text-sm text-gray-500">Загрузка настроек...</p>
    </div>

    <!-- Сообщение об ошибке -->
    <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
      <p class="text-sm text-red-800 font-medium">{{ error }}</p>
      <details class="mt-2">
        <summary class="text-xs text-red-600 cursor-pointer">Показать детали</summary>
        <pre class="mt-2 text-xs text-red-700 bg-red-100 p-2 rounded overflow-auto">{{ JSON.stringify({ accountId: accountId, route: $route }, null, 2) }}</pre>
      </details>
    </div>

    <!-- Debug info -->
    <div v-if="!loading && !error && !accountId" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
      <p class="text-sm text-yellow-800">⚠️ Account ID отсутствует</p>
      <p class="text-xs text-yellow-700 mt-1">Route params: {{ JSON.stringify($route.params) }}</p>
    </div>

    <!-- Форма настроек -->
    <form v-if="!loading && !error" @submit.prevent="saveSettings" class="space-y-4">
      <!-- Главный выключатель синхронизации -->
      <div class="bg-gradient-to-r from-indigo-500 to-purple-600 shadow-lg rounded-lg p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="bg-white rounded-lg p-2">
              <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div>
              <h3 class="text-base font-semibold text-white">Синхронизация</h3>
              <p class="text-xs text-indigo-100">Глобальное управление всеми настройками</p>
            </div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input
              v-model="settings.sync_enabled"
              type="checkbox"
              class="sr-only peer"
            />
            <div class="w-14 h-7 bg-white/20 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-white/30 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-white"></div>
            <span class="ml-3 text-sm font-medium text-white">{{ settings.sync_enabled ? 'Вкл' : 'Выкл' }}</span>
          </label>
        </div>
      </div>

      <!-- Grid для компактных секций (2 колонки) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- Синхронизация товаров -->
        <div class="bg-white shadow rounded-lg p-5">
          <h3 class="text-base font-medium text-gray-900 mb-3">Синхронизация товаров</h3>
          <div class="space-y-3">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_products"
                v-model="settings.sync_products"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-2 text-sm">
              <label for="sync_products" class="font-medium text-gray-700">Товары</label>
            </div>
          </div>
          <div class="flex items-center">
            <input id="sync_variants" v-model="settings.sync_variants" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_variants" class="ml-2 text-sm font-medium text-gray-700">Модификации</label>
          </div>
          <div class="flex items-center">
            <input id="sync_bundles" v-model="settings.sync_bundles" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_bundles" class="ml-2 text-sm font-medium text-gray-700">Комплекты</label>
          </div>
          <div class="flex items-center">
            <input id="sync_services" v-model="settings.sync_services" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_services" class="ml-2 text-sm font-medium text-gray-700">Услуги</label>
          </div>
          <div class="flex items-center">
            <input id="sync_images" v-model="settings.sync_images" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_images" class="ml-2 text-sm font-medium text-gray-700">Изображения</label>
          </div>
          <div class="flex items-center">
            <input id="sync_images_all" v-model="settings.sync_images_all" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_images_all" class="ml-2 text-sm font-medium text-gray-700">Все изображения</label>
          </div>
          <div class="flex items-center">
            <input id="sync_prices" v-model="settings.sync_prices" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            <label for="sync_prices" class="ml-2 text-sm font-medium text-gray-700">Цены</label>
          </div>
        </div>
        </div>
      </div>

      <!-- Синхронизация заказов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Синхронизация документов</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_customer_orders"
                v-model="settings.sync_customer_orders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_customer_orders" class="font-medium text-gray-700">Заказы покупателей</label>
              <p class="text-gray-500">Синхронизировать заказы покупателей из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_customer_orders" class="ml-7 space-y-3">
            <SearchableSelect
              v-model="settings.customer_order_state_id"
              label="Статус заказа"
              placeholder="Выберите статус"
              :options="customerOrderStates"
              :loading="loadingCustomerOrderStates"
              :error="customerOrderStatesError"
              :initial-name="targetObjectsMeta?.customer_order_state_id?.name"
              :can-create="true"
              :show-color="true"
              @open="loadCustomerOrderStates"
              @create="showCreateCustomerOrderStateModal = true"
              @clear="clearCustomerOrderState"
            />
            <SearchableSelect
              v-model="settings.customer_order_sales_channel_id"
              label="Канал продаж"
              placeholder="Выберите канал продаж"
              :options="salesChannels"
              :loading="loadingSalesChannels"
              :error="salesChannelsError"
              :initial-name="targetObjectsMeta?.customer_order_sales_channel_id?.name"
              :can-create="true"
              @open="loadSalesChannels"
              @create="showCreateSalesChannelModal = true"
              @clear="clearCustomerOrderSalesChannel"
            />
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_retail_demands"
                v-model="settings.sync_retail_demands"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_retail_demands" class="font-medium text-gray-700">Розничные продажи</label>
              <p class="text-gray-500">Синхронизировать розничные продажи из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_retail_demands" class="ml-7 space-y-3">
            <SearchableSelect
              v-model="settings.retail_demand_state_id"
              label="Статус розничной продажи"
              placeholder="Выберите статус"
              :options="customerOrderStates"
              :loading="loadingCustomerOrderStates"
              :error="customerOrderStatesError"
              :initial-name="targetObjectsMeta?.retail_demand_state_id?.name"
              :can-create="true"
              :show-color="true"
              @open="loadCustomerOrderStates"
              @create="showCreateRetailDemandStateModal = true"
              @clear="clearRetailDemandState"
            />
            <SearchableSelect
              v-model="settings.retail_demand_sales_channel_id"
              label="Канал продаж"
              placeholder="Выберите канал продаж"
              :options="salesChannels"
              :loading="loadingSalesChannels"
              :error="salesChannelsError"
              :initial-name="targetObjectsMeta?.retail_demand_sales_channel_id?.name"
              :can-create="true"
              @open="loadSalesChannels"
              @create="showCreateSalesChannelModal = true"
              @clear="clearRetailDemandSalesChannel"
            />
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_purchase_orders"
                v-model="settings.sync_purchase_orders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_purchase_orders" class="font-medium text-gray-700">Заказы поставщику</label>
              <p class="text-gray-500">Синхронизировать заказы поставщику из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_purchase_orders" class="ml-7 space-y-3">
            <SearchableSelect
              v-model="settings.purchase_order_state_id"
              label="Статус заказа поставщику"
              placeholder="Выберите статус"
              :options="purchaseOrderStates"
              :loading="loadingPurchaseOrderStates"
              :error="purchaseOrderStatesError"
              :initial-name="targetObjectsMeta?.purchase_order_state_id?.name"
              :can-create="true"
              :show-color="true"
              @open="loadPurchaseOrderStates"
              @create="showCreatePurchaseOrderStateModal = true"
              @clear="clearPurchaseOrderState"
            />
            <SearchableSelect
              v-model="settings.purchase_order_sales_channel_id"
              label="Канал продаж для заказов поставщику"
              placeholder="Выберите канал продаж"
              :options="salesChannels"
              :loading="loadingSalesChannels"
              :error="salesChannelsError"
              :initial-name="targetObjectsMeta?.purchase_order_sales_channel_id?.name"
              :can-create="true"
              @open="loadSalesChannels"
              @create="showCreateSalesChannelModal = true"
              @clear="clearPurchaseOrderSalesChannel"
            />
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
              <p class="text-xs text-yellow-800">
                <strong>⚠️ Примечание:</strong> ID контрагента-поставщика (supplier_counterparty_id) в данный момент не поддерживает выбор через интерфейс и должен быть настроен в базе данных.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Настройки целевых объектов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Целевые объекты в главном аккаунте</h3>
        <div class="space-y-4">
          <SearchableSelect
            v-model="settings.target_organization_id"
            label="Организация"
            placeholder="Выберите организацию"
            :options="organizations"
            :loading="loadingOrganizations"
            :error="organizationsError"
            :initial-name="targetObjectsMeta?.target_organization_id?.name"
            required
            @open="loadOrganizations"
          />
          <p class="mt-1 text-xs text-gray-500">Организация для создаваемых документов</p>

          <SearchableSelect
            v-model="settings.target_store_id"
            label="Склад"
            placeholder="Выберите склад"
            :options="stores"
            :loading="loadingStores"
            :error="storesError"
            :initial-name="targetObjectsMeta?.target_store_id?.name"
            :can-create="true"
            @open="loadStores"
            @create="showCreateStoreModal = true"
            @clear="clearTargetStore"
          />
          <p class="mt-1 text-xs text-gray-500">Склад для создаваемых документов (опционально)</p>

          <SearchableSelect
            v-model="settings.target_project_id"
            label="Проект"
            placeholder="Выберите проект"
            :options="projects"
            :loading="loadingProjects"
            :error="projectsError"
            :initial-name="targetObjectsMeta?.target_project_id?.name"
            :can-create="true"
            @open="loadProjects"
            @create="showCreateProjectModal = true"
            @clear="clearTargetProject"
          />
          <p class="mt-1 text-xs text-gray-500">Проект для создаваемых документов (опционально)</p>

          <SearchableSelect
            v-model="settings.responsible_employee_id"
            label="Ответственный сотрудник"
            placeholder="Выберите сотрудника"
            :options="employees"
            :loading="loadingEmployees"
            :error="employeesError"
            :initial-name="targetObjectsMeta?.responsible_employee_id?.name"
            @open="loadEmployees"
            @clear="clearResponsibleEmployee"
          />
          <p class="mt-1 text-xs text-gray-500">Ответственный за создаваемые документы</p>
        </div>
      </div>

      <!-- Расширенные настройки товаров -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Расширенные настройки товаров</h3>
        <div class="space-y-6">
          <!-- Product match field -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Поле для сопоставления товаров</label>
            <select
              v-model="settings.product_match_field"
              class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            >
              <option value="code">Код (code)</option>
              <option value="article">Артикул (article)</option>
              <option value="externalCode">Внешний код (externalCode)</option>
              <option value="barcode">Штрихкод (первый barcode)</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">По какому полю искать существующие товары в дочернем аккаунте</p>
          </div>

          <!-- Create product folders -->
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="create_product_folders"
                v-model="settings.create_product_folders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="create_product_folders" class="font-medium text-gray-700">Создавать группы товаров</label>
              <p class="text-gray-500">Создавать соответствующие группы товаров в дочернем аккаунте (структура каталога)</p>
            </div>
          </div>

          <!-- Sync all products button -->
          <div class="border-t border-gray-200 pt-4">
            <button
              type="button"
              @click="syncAllProducts"
              :disabled="syncing"
              class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-all"
            >
              <svg v-if="syncing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span v-if="syncing">Синхронизация...</span>
              <span v-else>Синхронизировать все товары</span>
            </button>
            <p v-if="syncProgress" class="mt-2 text-sm text-green-600">{{ syncProgress }}</p>
            <p class="mt-2 text-xs text-gray-500">Запустит синхронизацию всех товаров согласно настройкам и фильтрам</p>
          </div>
        </div>
      </div>

      <!-- Price mappings -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Сопоставление типов цен</h3>
        <p class="text-sm text-gray-500 mb-2">
          Задайте соответствие между типами цен главного и дочернего аккаунтов. Пусто = синхронизировать все типы цен.
        </p>
        <div class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-4">
          <p class="text-xs text-blue-800">
            <strong>💰 Закупочная цена</strong> - специальный тип для поля buyPrice товаров, услуг и модификаций.
            Можно сопоставлять с другими типами цен или оставить как buyPrice.
          </p>
        </div>

        <div v-if="loadingPriceTypes" class="text-center py-4">
          <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
          <p class="text-sm text-gray-500 mt-2">Загрузка типов цен...</p>
        </div>

        <div v-else class="space-y-3">
          <div
            v-for="(mapping, index) in priceMappings"
            :key="`price-mapping-${index}`"
            class="flex gap-3 items-start"
          >
            <div class="flex-1">
              <label class="block text-sm font-semibold text-gray-800 mb-2">Тип цены (главный)</label>
              <select
                v-model="mapping.main_price_type_id"
                class="block w-full rounded-lg border-2 border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm transition-all duration-200 hover:border-indigo-400 hover:shadow-md focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none cursor-pointer"
              >
                <option value="" class="text-gray-500">Выберите тип цены...</option>
                <option
                  v-for="pt in priceTypes.main"
                  :key="pt.id"
                  :value="pt.id"
                  :class="{ 'font-bold': pt.id === 'buyPrice' }"
                  :style="pt.id === 'buyPrice' ? 'background: linear-gradient(to right, #fffbeb, #fef3c7);' : ''"
                >
                  {{ pt.id === 'buyPrice' ? '💰 ' : '' }}{{ pt.name }}
                </option>
              </select>
            </div>
            <div class="flex-1">
              <label class="block text-sm font-semibold text-gray-800 mb-2">Тип цены (дочерний)</label>
              <div class="flex gap-2">
                <select
                  v-model="mapping.child_price_type_id"
                  class="block w-full rounded-lg border-2 border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 shadow-sm transition-all duration-200 hover:border-indigo-400 hover:shadow-md focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none cursor-pointer"
                >
                  <option value="" class="text-gray-500">Выберите тип цены...</option>
                  <option
                    v-for="pt in priceTypes.child"
                    :key="pt.id"
                    :value="pt.id"
                    :class="{ 'font-bold': pt.id === 'buyPrice' }"
                    :style="pt.id === 'buyPrice' ? 'background: linear-gradient(to right, #fffbeb, #fef3c7);' : ''"
                  >
                    {{ pt.id === 'buyPrice' ? '💰 ' : '' }}{{ pt.name }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showCreatePriceTypeForm(index)"
                  class="flex-shrink-0 px-2 py-1 text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded transition-colors"
                  title="Создать новый тип цены"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                </button>
              </div>

              <!-- Inline форма создания типа цены -->
              <div
                v-if="creatingPriceTypeForIndex === index"
                class="mt-3 p-3 bg-gray-50 border border-gray-200 rounded-md"
              >
                <label class="block text-xs font-medium text-gray-700 mb-2">Новый тип цены:</label>
                <input
                  ref="newPriceTypeInput"
                  v-model="newPriceTypeName"
                  type="text"
                  placeholder="Название типа цены"
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm mb-2"
                  @keyup.enter="createNewPriceType(index)"
                  @keyup.escape="hideCreatePriceTypeForm"
                  autofocus
                />
                <p v-if="createPriceTypeError" class="text-xs text-red-600 mb-2">{{ createPriceTypeError }}</p>
                <div class="flex gap-2">
                  <button
                    type="button"
                    @click="createNewPriceType(index)"
                    :disabled="creatingPriceType"
                    class="flex-1 px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md disabled:opacity-50 transition-colors"
                  >
                    <span v-if="creatingPriceType">Создание...</span>
                    <span v-else>Создать</span>
                  </button>
                  <button
                    type="button"
                    @click="hideCreatePriceTypeForm"
                    :disabled="creatingPriceType"
                    class="flex-1 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-md disabled:opacity-50 transition-colors"
                  >
                    Отмена
                  </button>
                </div>
              </div>
            </div>
            <button
              type="button"
              @click="removePriceMapping(index)"
              class="mt-6 text-gray-400 hover:text-red-600 focus:outline-none transition-colors flex-shrink-0"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>

          <button
            type="button"
            @click="addPriceMapping"
            class="w-full px-3 py-2 border border-dashed border-gray-300 rounded-md text-sm text-gray-600 hover:border-indigo-500 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors"
          >
            + Добавить сопоставление
          </button>
        </div>
      </div>

      <!-- Attribute selection -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Выбор дополнительных полей для синхронизации</h3>
        <p class="text-sm text-gray-500 mb-4">
          Выберите дополнительные поля (атрибуты), которые нужно синхронизировать. Пусто = синхронизировать все поля.
        </p>

        <div v-if="loadingAttributes" class="text-center py-4">
          <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
          <p class="text-sm text-gray-500 mt-2">Загрузка атрибутов...</p>
        </div>

        <div v-else-if="attributes.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <p class="text-sm text-gray-500 mt-3">Дополнительных полей не найдено</p>
        </div>

        <div v-else class="max-h-64 overflow-y-auto border border-gray-200 rounded-md p-3 space-y-2">
          <label
            v-for="attr in attributes"
            :key="attr.id"
            class="flex items-center py-1 px-2 hover:bg-gray-50 rounded cursor-pointer transition-colors"
          >
            <input
              type="checkbox"
              :value="attr.id"
              v-model="selectedAttributes"
              class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded mr-2"
            />
            <span class="text-sm text-gray-900">{{ attr.name }}</span>
            <span class="ml-2 text-xs text-gray-500">({{ attr.type }})</span>
          </label>
        </div>

        <p v-if="selectedAttributes.length > 0" class="mt-3 text-sm text-gray-600">
          Выбрано атрибутов: <span class="font-medium text-indigo-600">{{ selectedAttributes.length }}</span>
        </p>
      </div>

      <!-- Фильтрация товаров -->
      <div class="bg-white shadow rounded-lg p-6">
        <div class="flex items-start mb-4">
          <div class="flex items-center h-5">
            <input
              id="product_filters_enabled"
              v-model="settings.product_filters_enabled"
              type="checkbox"
              class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
            />
          </div>
          <div class="ml-3">
            <label for="product_filters_enabled" class="text-sm font-medium text-gray-700">Включить фильтрацию товаров</label>
            <p class="text-sm text-gray-500">Использовать фильтры для выборочной синхронизации товаров</p>
          </div>
        </div>

        <div v-if="settings.product_filters_enabled">
          <ProductFilterBuilder
            v-model="settings.product_filters"
            :account-id="accountId"
            :attributes="attributes"
            :folders="folders"
            :loading-folders="loadingFolders"
          />
        </div>
      </div>

      <!-- Автосоздание объектов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Автоматическое создание</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_attributes"
                v-model="settings.auto_create_attributes"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_attributes" class="font-medium text-gray-700">Дополнительные поля</label>
              <p class="text-gray-500">Автоматически создавать доп. поля, если их нет в дочернем аккаунте</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_characteristics"
                v-model="settings.auto_create_characteristics"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_characteristics" class="font-medium text-gray-700">Характеристики</label>
              <p class="text-gray-500">Автоматически создавать характеристики для модификаций</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_price_types"
                v-model="settings.auto_create_price_types"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_price_types" class="font-medium text-gray-700">Типы цен</label>
              <p class="text-gray-500">Автоматически создавать типы цен, если их нет в дочернем аккаунте</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Кнопки -->
      <div class="flex justify-between items-center">
        <button
          type="button"
          @click="$router.push('/app/accounts')"
          class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Отмена
        </button>
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        >
          <span v-if="saving">Сохранение...</span>
          <span v-else>Сохранить настройки</span>
        </button>
      </div>
    </form>

    <!-- Сообщение об успешном сохранении -->
    <div v-if="saveSuccess" class="fixed bottom-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg">
      <p class="text-sm text-green-800">✓ Настройки успешно сохранены</p>
    </div>

    <!-- Modals -->
    <CreateProjectModal
      :show="showCreateProjectModal"
      @close="showCreateProjectModal = false"
      @created="handleProjectCreated"
      ref="createProjectModalRef"
    />

    <CreateStoreModal
      :show="showCreateStoreModal"
      @close="showCreateStoreModal = false"
      @created="handleStoreCreated"
      ref="createStoreModalRef"
    />

    <CreateSalesChannelModal
      :show="showCreateSalesChannelModal"
      @close="showCreateSalesChannelModal = false"
      @created="handleSalesChannelCreated"
      ref="createSalesChannelModalRef"
    />

    <CreateStateModal
      :show="showCreateCustomerOrderStateModal"
      entity-type="customerorder"
      @close="showCreateCustomerOrderStateModal = false"
      @created="handleCustomerOrderStateCreated"
      ref="createCustomerOrderStateModalRef"
    />

    <CreateStateModal
      :show="showCreateRetailDemandStateModal"
      entity-type="customerorder"
      @close="showCreateRetailDemandStateModal = false"
      @created="handleRetailDemandStateCreated"
      ref="createRetailDemandStateModalRef"
    />

    <CreateStateModal
      :show="showCreatePurchaseOrderStateModal"
      entity-type="customerorder"
      @close="showCreatePurchaseOrderStateModal = false"
      @created="handlePurchaseOrderStateCreated"
      ref="createPurchaseOrderStateModalRef"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import ProductFilterBuilder from '../components/ProductFilterBuilder.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import CreateProjectModal from '../components/CreateProjectModal.vue'
import CreateStoreModal from '../components/CreateStoreModal.vue'
import CreateSalesChannelModal from '../components/CreateSalesChannelModal.vue'
import CreateStateModal from '../components/CreateStateModal.vue'

const route = useRoute()
const router = useRouter()

const accountId = ref(route.params.accountId)
const accountName = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const saveSuccess = ref(false)
const filterJsonError = ref(null)

// Extended settings
const priceTypes = ref({ main: [], child: [] })
const attributes = ref([])
const folders = ref([])
const loadingPriceTypes = ref(false)
const loadingAttributes = ref(false)
const loadingFolders = ref(false)
const syncing = ref(false)
const syncProgress = ref(null)

// Create price type state
const creatingPriceTypeForIndex = ref(null)
const newPriceTypeName = ref('')
const creatingPriceType = ref(false)
const createPriceTypeError = ref(null)

// Target objects state
const organizations = ref([])
const stores = ref([])
const projects = ref([])
const employees = ref([])
const salesChannels = ref([])
const customerOrderStates = ref([])
const purchaseOrderStates = ref([])

const loadingOrganizations = ref(false)
const loadingStores = ref(false)
const loadingProjects = ref(false)
const loadingEmployees = ref(false)
const loadingSalesChannels = ref(false)
const loadingCustomerOrderStates = ref(false)
const loadingPurchaseOrderStates = ref(false)

const organizationsError = ref(null)
const storesError = ref(null)
const projectsError = ref(null)
const employeesError = ref(null)
const salesChannelsError = ref(null)
const customerOrderStatesError = ref(null)
const purchaseOrderStatesError = ref(null)

// Modal state
const showCreateProjectModal = ref(false)
const showCreateStoreModal = ref(false)
const showCreateSalesChannelModal = ref(false)
const showCreateCustomerOrderStateModal = ref(false)
const showCreateRetailDemandStateModal = ref(false)
const showCreatePurchaseOrderStateModal = ref(false)

// Modal refs
const createProjectModalRef = ref(null)
const createStoreModalRef = ref(null)
const createSalesChannelModalRef = ref(null)
const createCustomerOrderStateModalRef = ref(null)
const createRetailDemandStateModalRef = ref(null)
const createPurchaseOrderStateModalRef = ref(null)

// Target objects metadata (for displaying names)
const targetObjectsMeta = ref({})

const settings = ref({
  sync_enabled: true,
  sync_products: true,
  sync_variants: true,
  sync_bundles: true,
  sync_services: true,
  sync_images: true,
  sync_images_all: false,
  sync_prices: true,
  sync_customer_orders: false,
  sync_retail_demands: false,
  sync_purchase_orders: false,
  customer_order_state_id: null,
  customer_order_sales_channel_id: null,
  retail_demand_state_id: null,
  retail_demand_sales_channel_id: null,
  purchase_order_state_id: null,
  purchase_order_sales_channel_id: null,
  supplier_counterparty_id: null,
  target_organization_id: null,
  target_store_id: null,
  target_project_id: null,
  responsible_employee_id: null,
  product_filters_enabled: false,
  product_filters: null,
  product_match_field: 'article',
  create_product_folders: true,
  price_mappings: null,
  attribute_sync_list: null,
  auto_create_attributes: true,
  auto_create_characteristics: true,
  auto_create_price_types: true
})

// Price mappings array for UI
const priceMappings = ref([])

// Attribute sync list for UI
const selectedAttributes = ref([])

// Load extended data
const loadPriceTypes = async () => {
  try {
    loadingPriceTypes.value = true
    const response = await api.syncSettings.getPriceTypes(accountId.value)
    priceTypes.value = response.data
  } catch (err) {
    console.error('Failed to load price types:', err)
  } finally {
    loadingPriceTypes.value = false
  }
}

const loadAttributes = async () => {
  try {
    loadingAttributes.value = true
    const response = await api.syncSettings.getAttributes(accountId.value)
    attributes.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load attributes:', err)
  } finally {
    loadingAttributes.value = false
  }
}

const loadFolders = async () => {
  try {
    loadingFolders.value = true
    const response = await api.syncSettings.getFolders(accountId.value)
    folders.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load folders:', err)
  } finally {
    loadingFolders.value = false
  }
}

// Загрузка настроек
const loadSettings = async () => {
  if (!accountId.value) {
    error.value = 'ID аккаунта не указан'
    return
  }

  try {
    loading.value = true
    error.value = null

    // Загрузить информацию об аккаунте
    const accountResponse = await api.childAccounts.get(accountId.value)
    accountName.value = accountResponse.data.data.account_name || 'Без названия'

    // Загрузить настройки
    const response = await api.syncSettings.get(accountId.value)
    const loadedSettings = response.data.data

    // Заполнить form
    Object.keys(settings.value).forEach(key => {
      if (loadedSettings[key] !== undefined) {
        settings.value[key] = loadedSettings[key]
      }
    })

    // Convert price_mappings from JSON to array
    if (loadedSettings.price_mappings) {
      priceMappings.value = Array.isArray(loadedSettings.price_mappings)
        ? loadedSettings.price_mappings
        : []
    }

    // Convert attribute_sync_list from JSON to array
    if (loadedSettings.attribute_sync_list) {
      selectedAttributes.value = Array.isArray(loadedSettings.attribute_sync_list)
        ? loadedSettings.attribute_sync_list
        : []
    }

    // Load target_objects_meta
    if (loadedSettings.target_objects_meta) {
      targetObjectsMeta.value = loadedSettings.target_objects_meta || {}
    }

    // Load extended data
    await Promise.all([
      loadPriceTypes(),
      loadAttributes(),
      loadFolders()
    ])

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки: ' + (err.response?.data?.error || err.message)
  } finally {
    loading.value = false
  }
}

// Price mappings management
const addPriceMapping = () => {
  priceMappings.value.push({
    main_price_type_id: '',
    child_price_type_id: ''
  })
}

const removePriceMapping = (index) => {
  priceMappings.value.splice(index, 1)
}

// Create price type management
const showCreatePriceTypeForm = (index) => {
  creatingPriceTypeForIndex.value = index
  newPriceTypeName.value = ''
  createPriceTypeError.value = null
}

const hideCreatePriceTypeForm = () => {
  creatingPriceTypeForIndex.value = null
  newPriceTypeName.value = ''
  createPriceTypeError.value = null
}

const createNewPriceType = async (index) => {
  // Валидация
  if (!newPriceTypeName.value || newPriceTypeName.value.trim().length < 2) {
    createPriceTypeError.value = 'Название должно содержать минимум 2 символа'
    return
  }

  try {
    creatingPriceType.value = true
    createPriceTypeError.value = null

    const response = await api.syncSettings.createPriceType(accountId.value, {
      name: newPriceTypeName.value.trim()
    })

    const createdPriceType = response.data.data

    // Добавить в список типов цен дочернего аккаунта
    priceTypes.value.child.push({
      id: createdPriceType.id,
      name: createdPriceType.name
    })

    // Автоматически выбрать созданный тип в текущем маппинге
    priceMappings.value[index].child_price_type_id = createdPriceType.id

    // Скрыть форму
    hideCreatePriceTypeForm()

    // Показать успешное уведомление (можно добавить позже)
    console.log('Price type created successfully:', createdPriceType)

  } catch (err) {
    console.error('Failed to create price type:', err)

    // Обработка ошибок
    if (err.response?.status === 409) {
      createPriceTypeError.value = 'Тип цены с таким названием уже существует'
    } else {
      createPriceTypeError.value = err.response?.data?.error || 'Не удалось создать тип цены'
    }
  } finally {
    creatingPriceType.value = false
  }
}

// Load target objects functions (lazy loading)
const loadOrganizations = async () => {
  if (organizations.value.length > 0) return // Already loaded

  try {
    loadingOrganizations.value = true
    organizationsError.value = null
    const response = await api.syncSettings.getOrganizations(accountId.value)
    organizations.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load organizations:', err)
    organizationsError.value = 'Не удалось загрузить организации'
  } finally {
    loadingOrganizations.value = false
  }
}

const loadStores = async () => {
  if (stores.value.length > 0) return // Already loaded

  try {
    loadingStores.value = true
    storesError.value = null
    const response = await api.syncSettings.getStores(accountId.value)
    stores.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load stores:', err)
    storesError.value = 'Не удалось загрузить склады'
  } finally {
    loadingStores.value = false
  }
}

const loadProjects = async () => {
  if (projects.value.length > 0) return // Already loaded

  try {
    loadingProjects.value = true
    projectsError.value = null
    const response = await api.syncSettings.getProjects(accountId.value)
    projects.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load projects:', err)
    projectsError.value = 'Не удалось загрузить проекты'
  } finally {
    loadingProjects.value = false
  }
}

const loadEmployees = async () => {
  if (employees.value.length > 0) return // Already loaded

  try {
    loadingEmployees.value = true
    employeesError.value = null
    const response = await api.syncSettings.getEmployees(accountId.value)
    employees.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load employees:', err)
    employeesError.value = 'Не удалось загрузить сотрудников'
  } finally {
    loadingEmployees.value = false
  }
}

const loadSalesChannels = async () => {
  if (salesChannels.value.length > 0) return // Already loaded

  try {
    loadingSalesChannels.value = true
    salesChannelsError.value = null
    const response = await api.syncSettings.getSalesChannels(accountId.value)
    salesChannels.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load sales channels:', err)
    salesChannelsError.value = 'Не удалось загрузить каналы продаж'
  } finally {
    loadingSalesChannels.value = false
  }
}

const loadCustomerOrderStates = async () => {
  if (customerOrderStates.value.length > 0) return // Already loaded

  try {
    loadingCustomerOrderStates.value = true
    customerOrderStatesError.value = null
    const response = await api.syncSettings.getStates(accountId.value, 'customerorder')
    customerOrderStates.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load customer order states:', err)
    customerOrderStatesError.value = 'Не удалось загрузить статусы заказов'
  } finally {
    loadingCustomerOrderStates.value = false
  }
}

const loadPurchaseOrderStates = async () => {
  // ВАЖНО: purchaseorder в child → customerorder в main
  // Поэтому загружаем customerorder states вместо purchaseorder
  if (customerOrderStates.value.length > 0) {
    // Если customerOrderStates уже загружены, используем их
    purchaseOrderStates.value = customerOrderStates.value
    return
  }

  try {
    loadingPurchaseOrderStates.value = true
    purchaseOrderStatesError.value = null
    const response = await api.syncSettings.getStates(accountId.value, 'customerorder')
    purchaseOrderStates.value = response.data.data || []
    // Синхронизируем с customerOrderStates для консистентности
    customerOrderStates.value = purchaseOrderStates.value
  } catch (err) {
    console.error('Failed to load purchase order states:', err)
    purchaseOrderStatesError.value = 'Не удалось загрузить статусы заказов'
  } finally {
    loadingPurchaseOrderStates.value = false
  }
}

// Clear handlers (update metadata when clearing)
const clearTargetStore = () => {
  updateTargetObjectMeta('target_store_id', null, null)
}

const clearTargetProject = () => {
  updateTargetObjectMeta('target_project_id', null, null)
}

const clearResponsibleEmployee = () => {
  updateTargetObjectMeta('responsible_employee_id', null, null)
}

const clearCustomerOrderState = () => {
  updateTargetObjectMeta('customer_order_state_id', null, null)
}

const clearCustomerOrderSalesChannel = () => {
  updateTargetObjectMeta('customer_order_sales_channel_id', null, null)
}

const clearRetailDemandState = () => {
  updateTargetObjectMeta('retail_demand_state_id', null, null)
}

const clearRetailDemandSalesChannel = () => {
  updateTargetObjectMeta('retail_demand_sales_channel_id', null, null)
}

const clearPurchaseOrderState = () => {
  updateTargetObjectMeta('purchase_order_state_id', null, null)
}

const clearPurchaseOrderSalesChannel = () => {
  updateTargetObjectMeta('purchase_order_sales_channel_id', null, null)
}

// Update target object metadata helper
const updateTargetObjectMeta = (fieldName, id, name) => {
  if (!targetObjectsMeta.value) {
    targetObjectsMeta.value = {}
  }

  if (id && name) {
    targetObjectsMeta.value[fieldName] = { id, name }
  } else {
    delete targetObjectsMeta.value[fieldName]
  }
}

// Watch for changes in target object IDs and update metadata
watch(() => settings.value.target_organization_id, (newValue) => {
  if (newValue) {
    const org = organizations.value.find(o => o.id === newValue)
    if (org) updateTargetObjectMeta('target_organization_id', org.id, org.name)
  }
})

watch(() => settings.value.target_store_id, (newValue) => {
  if (newValue) {
    const store = stores.value.find(s => s.id === newValue)
    if (store) updateTargetObjectMeta('target_store_id', store.id, store.name)
  }
})

watch(() => settings.value.target_project_id, (newValue) => {
  if (newValue) {
    const project = projects.value.find(p => p.id === newValue)
    if (project) updateTargetObjectMeta('target_project_id', project.id, project.name)
  }
})

watch(() => settings.value.responsible_employee_id, (newValue) => {
  if (newValue) {
    const employee = employees.value.find(e => e.id === newValue)
    if (employee) updateTargetObjectMeta('responsible_employee_id', employee.id, employee.name)
  }
})

watch(() => settings.value.customer_order_state_id, (newValue) => {
  if (newValue) {
    const state = customerOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('customer_order_state_id', state.id, state.name)
  }
})

watch(() => settings.value.customer_order_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('customer_order_sales_channel_id', channel.id, channel.name)
  }
})

watch(() => settings.value.retail_demand_state_id, (newValue) => {
  if (newValue) {
    const state = customerOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('retail_demand_state_id', state.id, state.name)
  }
})

watch(() => settings.value.retail_demand_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('retail_demand_sales_channel_id', channel.id, channel.name)
  }
})

watch(() => settings.value.purchase_order_state_id, (newValue) => {
  if (newValue) {
    const state = purchaseOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('purchase_order_state_id', state.id, state.name)
  }
})

watch(() => settings.value.purchase_order_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('purchase_order_sales_channel_id', channel.id, channel.name)
  }
})

// Modal creation handlers
const handleProjectCreated = async (data) => {
  try {
    createProjectModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createProject(accountId.value, data)
    const created = response.data.data

    // Add to projects list
    projects.value.push(created)

    // Select the newly created project
    settings.value.target_project_id = created.id
    updateTargetObjectMeta('target_project_id', created.id, created.name)

    // Close modal
    showCreateProjectModal.value = false

  } catch (err) {
    console.error('Failed to create project:', err)
    createProjectModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать проект')
  } finally {
    createProjectModalRef.value?.setLoading(false)
  }
}

const handleStoreCreated = async (data) => {
  try {
    createStoreModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createStore(accountId.value, data)
    const created = response.data.data

    // Add to stores list
    stores.value.push(created)

    // Select the newly created store
    settings.value.target_store_id = created.id
    updateTargetObjectMeta('target_store_id', created.id, created.name)

    // Close modal
    showCreateStoreModal.value = false

  } catch (err) {
    console.error('Failed to create store:', err)
    createStoreModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать склад')
  } finally {
    createStoreModalRef.value?.setLoading(false)
  }
}

const handleSalesChannelCreated = async (data) => {
  try {
    createSalesChannelModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createSalesChannel(accountId.value, data)
    const created = response.data.data

    // Add to sales channels list
    salesChannels.value.push(created)

    // Don't auto-select here since it could be for any of the 3 sales channel fields
    // User can select manually after creation

    // Close modal
    showCreateSalesChannelModal.value = false

  } catch (err) {
    console.error('Failed to create sales channel:', err)
    createSalesChannelModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать канал продаж')
  } finally {
    createSalesChannelModalRef.value?.setLoading(false)
  }
}

const handleCustomerOrderStateCreated = async (data) => {
  try {
    createCustomerOrderStateModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to customer order states list
    customerOrderStates.value.push(created)

    // Select the newly created state
    settings.value.customer_order_state_id = created.id
    updateTargetObjectMeta('customer_order_state_id', created.id, created.name)

    // Close modal
    showCreateCustomerOrderStateModal.value = false

  } catch (err) {
    console.error('Failed to create customer order state:', err)
    createCustomerOrderStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createCustomerOrderStateModalRef.value?.setLoading(false)
  }
}

const handleRetailDemandStateCreated = async (data) => {
  try {
    createRetailDemandStateModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to customer order states list (retail demand uses same states)
    if (!customerOrderStates.value.find(s => s.id === created.id)) {
      customerOrderStates.value.push(created)
    }

    // Select the newly created state
    settings.value.retail_demand_state_id = created.id
    updateTargetObjectMeta('retail_demand_state_id', created.id, created.name)

    // Close modal
    showCreateRetailDemandStateModal.value = false

  } catch (err) {
    console.error('Failed to create retail demand state:', err)
    createRetailDemandStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createRetailDemandStateModalRef.value?.setLoading(false)
  }
}

const handlePurchaseOrderStateCreated = async (data) => {
  try {
    createPurchaseOrderStateModalRef.value?.setLoading(true)

    // ВАЖНО: purchaseorder в child → customerorder в main
    // Поэтому создаем customerorder state
    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to both states lists (they share the same states)
    if (!customerOrderStates.value.find(s => s.id === created.id)) {
      customerOrderStates.value.push(created)
    }
    if (!purchaseOrderStates.value.find(s => s.id === created.id)) {
      purchaseOrderStates.value.push(created)
    }

    // Select the newly created state
    settings.value.purchase_order_state_id = created.id
    updateTargetObjectMeta('purchase_order_state_id', created.id, created.name)

    // Close modal
    showCreatePurchaseOrderStateModal.value = false

  } catch (err) {
    console.error('Failed to create purchase order state:', err)
    createPurchaseOrderStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createPurchaseOrderStateModalRef.value?.setLoading(false)
  }
}

// Sync all products action
const syncAllProducts = async () => {
  if (!confirm('Запустить синхронизацию всех товаров? Это может занять продолжительное время.')) {
    return
  }

  try {
    syncing.value = true
    syncProgress.value = 'Запуск синхронизации...'

    const response = await api.syncActions.syncAllProducts(accountId.value)

    syncProgress.value = `Синхронизация запущена! Создано задач: ${response.data.tasks_created}`

    setTimeout(() => {
      syncProgress.value = null
      syncing.value = false
    }, 5000)

  } catch (err) {
    console.error('Failed to sync products:', err)
    alert('Не удалось запустить синхронизацию: ' + (err.response?.data?.error || err.message))
    syncing.value = false
    syncProgress.value = null
  }
}

// Сохранение настроек
const saveSettings = async () => {
  try {
    saving.value = true
    filterJsonError.value = null

    // Convert arrays back to JSON for storage
    settings.value.price_mappings = priceMappings.value.length > 0 ? priceMappings.value : null
    settings.value.attribute_sync_list = selectedAttributes.value.length > 0 ? selectedAttributes.value : null
    settings.value.target_objects_meta = Object.keys(targetObjectsMeta.value).length > 0 ? targetObjectsMeta.value : null

    await api.syncSettings.update(accountId.value, settings.value)

    // Показать сообщение об успехе
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)

  } catch (err) {
    console.error('Failed to save settings:', err)
    alert('Не удалось сохранить настройки: ' + (err.response?.data?.error || err.message))
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>
