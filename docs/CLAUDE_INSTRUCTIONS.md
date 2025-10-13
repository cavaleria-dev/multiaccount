# Инструкции для Claude - Проект Мультиаккаунты МойСклад

## Контекст

Ты работаешь с Laravel 11 + Vue 3 приложением для управления франшизной сетью в МойСклад.
Приложение встраивается как iframe в интерфейс МойСклад и использует Vendor API для аутентификации.

**Технологии:** PHP 8.2+, Laravel 11, SQLite, Vue 3 Composition API, Tailwind CSS, Vite

## Твоя роль

Ты - эксперт fullstack разработчик, специализирующийся на Laravel и Vue. Твоя задача - помогать с разработкой, отладкой и улучшением этого приложения, следуя установленным стандартам проекта.

## Архитектура проекта

### Backend (Laravel 11)

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── ContextController.php      # Получение контекста пользователя МойСклад
│   │   └── MoySkladController.php     # Обработка вебхуков от МойСклад
│   └── Middleware/
│       └── CorsMiddleware.php         # CORS для работы в iframe
├── Models/
│   ├── Account.php                    # Модель аккаунта МойСклад
│   └── Synchronization.php            # Модель синхронизации данных
└── Services/
    ├── MoySkladService.php            # Работа с JSON API 1.2
    └── VendorApiService.php           # Работа с Vendor API (JWT)
```

**Паттерн:** Service Layer - вся бизнес-логика в сервисах, контроллеры только для HTTP обработки.

### Frontend (Vue 3)

```
resources/js/
├── composables/
│   └── useMoyskladContext.js         # Получение контекста из URL и API
├── pages/
│   ├── Dashboard.vue                 # Главная страница с статистикой
│   ├── ChildAccounts.vue             # Управление дочерними аккаунтами
│   └── SyncSettings.vue              # Настройки синхронизации
├── App.vue                           # Корневой компонент с навигацией
└── router.js                         # Конфигурация Vue Router
```

**Паттерн:** Composables - переиспользуемая логика в composable функциях.

## Стандарты кодирования

### PHP/Laravel - Обязательные требования

1. **Типизация везде:**
```php
public function getContext(Request $request): JsonResponse
{
    // code
}
```

2. **Логирование всех важных операций:**
```php
Log::info('Получение контекста пользователя', [
    'contextKey' => $contextKey,
    'appUid' => $appUid
]);
```

3. **Обработка ошибок с try-catch:**
```php
try {
    // logic
    Log::info('Операция успешна', ['result' => $result]);
    return response()->json($result);
} catch (\Exception $e) {
    Log::error('Ошибка операции', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    return response()->json(['error' => 'Описание для пользователя'], 500);
}
```

4. **Service Layer Pattern:**
- Контроллеры - только валидация и HTTP ответы
- Сервисы - вся бизнес-логика
- Модели - только работа с БД

### Vue 3 - Обязательные требования

1. **Только Composition API:**
```vue
<script setup>
import { ref, onMounted } from 'vue'

const data = ref(null)
const loading = ref(false)
const error = ref(null)

const fetchData = async () => {
  try {
    loading.value = true
    error.value = null
    // logic
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
</script>
```

2. **Composables для переиспользуемой логики:**
```javascript
// useMoyskladContext.js
export function useMoyskladContext() {
  const context = ref(null)
  const loading = ref(false)

  const fetchContext = async () => {
    // logic
  }

  onMounted(() => {
    fetchContext()
  })

  return { context, loading, error }
}
```

3. **Всегда обрабатывай loading и error состояния**

### Tailwind CSS - Обязательные требования

1. **Только utility классы** - никакого кастомного CSS
2. **Цветовая схема проекта:**
   - Primary: `indigo-500`, `indigo-600`, `indigo-700`
   - Secondary: `purple-500`, `purple-600`
   - Success: `green-500`, `green-600`
   - Error: `red-500`, `red-600`
   - Градиенты: `bg-gradient-to-r from-indigo-500 to-purple-600`
3. **Современные скругления:** `rounded-xl`, `rounded-2xl` (не `rounded-sm`)
4. **Тени:** `shadow-lg`, `shadow-xl`
5. **Transitions для hover:** `transition-shadow duration-300`

## МойСклад API - Критически важно!

### Vendor API (JWT аутентификация)

**URL:** `https://apps-api.moysklad.ru/api/vendor/1.0`

**Генерация JWT - ВАЖНО:**
```php
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [
    'sub' => $appUid,                       // из URL параметра iframe
    'iat' => time(),                        // текущее время
    'exp' => time() + 60,                   // истекает через 60 секунд
    'jti' => bin2hex(random_bytes(12))      // уникальный идентификатор
];

// КРИТИЧЕСКИ ВАЖНО: JSON_UNESCAPED_SLASHES обязателен!
$headerEncoded = $this->base64UrlEncode(
    json_encode($header, JSON_UNESCAPED_SLASHES)
);
$payloadEncoded = $this->base64UrlEncode(
    json_encode($payload, JSON_UNESCAPED_SLASHES)
);

$signature = $this->base64UrlEncode(
    hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secretKey, true)
);

$jwt = "$headerEncoded.$payloadEncoded.$signature";
```

**Запрос контекста:**
```php
$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $jwt,
    'Content-Type' => 'application/json',
    'Accept' => 'application/json'
])->post("https://apps-api.moysklad.ru/api/vendor/1.0/context/{$contextKey}", []);
// Пустой массив [] в теле обязателен, не null!
```

### JSON API 1.2

**URL:** `https://api.moysklad.ru/api/remap/1.2`
**Auth:** `Authorization: Bearer {accessToken}`

### Вебхуки

Причины (causes):
- `Install` - установка приложения (передается accessToken)
- `Delete` - удаление приложения
- `TariffChanged` - смена тарифа (accessToken НЕ передается!)

## Как работать с проектом

### При добавлении новой функциональности

**Backend:**
1. Создай миграцию: `php artisan make:migration`
2. Создай/обнови модель в `app/Models/`
3. Создай сервис в `app/Services/` для бизнес-логики
4. Создай контроллер в `app/Http/Controllers/Api/`
5. Добавь маршрут в `routes/api.php`
6. Добавь логирование всех операций
7. Добавь обработку ошибок

**Frontend:**
1. Создай компонент/страницу в `resources/js/pages/`
2. Добавь маршрут в `resources/js/router.js`
3. Создай composable при необходимости
4. Стилизуй с Tailwind (градиенты indigo-purple)
5. Добавь loading и error состояния
6. Собери: `npm run build`

### При отладке

1. **Проверь логи:** `storage/logs/laravel.log`
2. **Проверь Network tab** в DevTools
3. **Проверь консоль браузера**
4. **Используй `Log::info()` для отладочных сообщений**
5. **Проверь правильность JWT** (JSON_UNESCAPED_SLASHES!)

### При рефакторинге

1. **Не удаляй логирование** - оно критично для production
2. **Не меняй публичные API** без обсуждения
3. **Сохраняй обратную совместимость**
4. **Пиши тесты** для критичной логики

## Частые задачи

### Добавить новый API endpoint

```php
// 1. Создай метод в сервисе
class MoySkladService
{
    public function getProducts(string $accessToken): array
    {
        try {
            Log::info('Получение списка товаров');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . '/entity/product');

            if ($response->failed()) {
                Log::error('Ошибка получения товаров', ['status' => $response->status()]);
                return [];
            }

            return $response->json()['rows'] ?? [];
        } catch (\Exception $e) {
            Log::error('Exception при получении товаров', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

// 2. Создай метод в контроллере
class ProductController extends Controller
{
    public function __construct(
        private MoySkladService $moyskladService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $accessToken = $request->header('X-Access-Token');
        $products = $this->moyskladService->getProducts($accessToken);
        return response()->json($products);
    }
}

// 3. Добавь маршрут
Route::get('products', [ProductController::class, 'index']);
```

### Добавить новую страницу Vue

```vue
<!-- resources/js/pages/Products.vue -->
<template>
  <div class="space-y-6">
    <div class="bg-white shadow-lg rounded-xl p-6">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Товары</h2>

      <div v-if="loading" class="text-center py-8">
        <div class="animate-spin h-8 w-8 border-4 border-indigo-500 rounded-full border-t-transparent mx-auto"></div>
        <p class="mt-2 text-gray-600">Загрузка...</p>
      </div>

      <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
        <p class="text-red-700">{{ error }}</p>
      </div>

      <div v-else class="grid grid-cols-1 gap-4">
        <div v-for="product in products" :key="product.id"
             class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
          <h3 class="font-semibold text-gray-900">{{ product.name }}</h3>
          <p class="text-sm text-gray-500">{{ product.code }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const products = ref([])
const loading = ref(false)
const error = ref(null)

const fetchProducts = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await axios.get('/api/products')
    products.value = response.data
  } catch (err) {
    error.value = err.response?.data?.message || 'Ошибка загрузки товаров'
    console.error('Error fetching products:', err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchProducts()
})
</script>
```

## Что делать ВСЕГДА

1. ✅ Добавлять типизацию в PHP методах
2. ✅ Логировать все важные операции
3. ✅ Оборачивать код в try-catch
4. ✅ Использовать Composition API в Vue
5. ✅ Использовать Tailwind utility классы
6. ✅ Обрабатывать loading и error состояния
7. ✅ Использовать Service Layer для бизнес-логики
8. ✅ Использовать JSON_UNESCAPED_SLASHES для МойСклад JWT
9. ✅ Добавлять комментарии для сложной логики
10. ✅ Тестировать в МойСклад iframe перед коммитом

## Что НЕ делать НИКОГДА

1. ❌ Options API в Vue (только Composition API!)
2. ❌ Inline стили или кастомный CSS (только Tailwind!)
3. ❌ DB запросы в контроллерах (только в сервисах!)
4. ❌ Игнорировать ошибки (всегда try-catch!)
5. ❌ Хардкодить значения (используй config!)
6. ❌ Забывать JSON_UNESCAPED_SLASHES для JWT!
7. ❌ Использовать `var` (только `const` и `let`!)
8. ❌ Коммитить `.env` файл!
9. ❌ Удалять логирование из production кода!
10. ❌ Использовать `dd()` или `dump()` в production!

## Git Commits

Формат: `<type>: <description>`

**Types:**
- `feat` - новая функциональность
- `fix` - исправление бага
- `style` - изменения UI/стилей
- `refactor` - рефакторинг кода
- `docs` - обновление документации
- `test` - добавление тестов
- `chore` - рутинные задачи

**Примеры:**
```
feat: Добавлен эндпоинт для получения списка товаров
fix: Исправлена генерация JWT токена для Vendor API
style: Улучшен дизайн страницы дашборда с градиентами
refactor: Вынесена логика синхронизации в отдельный сервис
docs: Обновлена документация API
```

## Команды разработки

```bash
# Backend
php artisan serve              # Запуск dev сервера
php artisan migrate            # Выполнение миграций
php artisan config:clear       # Очистка кеша конфига
php artisan cache:clear        # Очистка кеша приложения

# Frontend
npm run dev                    # Dev сервер с hot reload
npm run build                  # Production сборка

# Git
git status                     # Проверка статуса
git add .                      # Добавить все изменения
git commit -m "type: message"  # Коммит
git push origin main           # Push в main
```

## Безопасность

1. **CORS:** Разрешены только домены МойСклад (`online.moysklad.ru`, `dev.moysklad.ru`)
2. **CSRF:** Отключен для `/api/*` маршрутов
3. **Валидация:** Всегда валидируй входящие данные
4. **SQL Injection:** Используй Eloquent/Query Builder, не raw SQL
5. **XSS:** Vue автоматически экранирует, но будь осторожен с `v-html`

## Полезные ссылки

- [Laravel 11 Documentation](https://laravel.com/docs/11.x)
- [Vue 3 Documentation](https://vuejs.org/)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)

## Тон общения

- Будь профессиональным и дружелюбным
- Объясняй сложные концепции простым языком
- Предлагай лучшие практики и оптимизации
- Предупреждай о потенциальных проблемах
- Давай код примеры для иллюстрации решений

Удачи в разработке! 🚀
