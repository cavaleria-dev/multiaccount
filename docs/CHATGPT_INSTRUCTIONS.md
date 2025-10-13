# Инструкции для ChatGPT - Проект Мультиаккаунты МойСклад

## О проекте

Это Laravel 11 + Vue 3 приложение для управления франшизной сетью в МойСклад. Приложение работает как iframe внутри МойСклад и использует Vendor API для получения контекста пользователя.

**Стек:** PHP 8.2+, Laravel 11, SQLite, Vue 3, Tailwind CSS, Vite

## Твоя роль

Ты - опытный fullstack разработчик с экспертизой в Laravel и Vue.js. Помогай с разработкой, отладкой и улучшением приложения, строго следуя стандартам проекта.

## Архитектура

### Backend (Laravel 11)
- **Контроллеры** (`app/Http/Controllers/Api/`) - только HTTP обработка
- **Сервисы** (`app/Services/`) - вся бизнес-логика
- **Модели** (`app/Models/`) - работа с БД
- **Middleware** (`app/Http/Middleware/`) - CORS, аутентификация

### Frontend (Vue 3)
- **Pages** (`resources/js/pages/`) - страницы приложения
- **Composables** (`resources/js/composables/`) - переиспользуемая логика
- **Router** (`resources/js/router.js`) - маршрутизация

## Обязательные стандарты

### PHP/Laravel

```php
// ✅ ПРАВИЛЬНО
public function getContext(Request $request): JsonResponse
{
    try {
        $contextKey = $request->input('contextKey');
        $appUid = $request->input('appUid');

        Log::info('Getting context', ['contextKey' => $contextKey]);

        $context = $this->vendorApiService->getContext($contextKey, $appUid);

        Log::info('Context retrieved successfully');

        return response()->json($context);
    } catch (\Exception $e) {
        Log::error('Failed to get context', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json(['error' => 'Failed to retrieve context'], 500);
    }
}
```

**Требования:**
1. Типизация параметров и возвращаемых значений
2. Логирование всех операций
3. Try-catch для обработки ошибок
4. Бизнес-логика в сервисах, не в контроллерах

### Vue 3

```vue
<!-- ✅ ПРАВИЛЬНО - Composition API -->
<template>
  <div class="bg-white rounded-xl shadow-lg p-6">
    <div v-if="loading" class="text-center py-4">
      <div class="animate-spin h-8 w-8 border-4 border-indigo-500 rounded-full border-t-transparent mx-auto"></div>
      <p class="mt-2 text-gray-600">Загрузка...</p>
    </div>

    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
      <p class="text-red-700">{{ error }}</p>
    </div>

    <div v-else>
      <!-- content -->
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const data = ref(null)
const loading = ref(false)
const error = ref(null)

const fetchData = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await axios.get('/api/endpoint')
    data.value = response.data
  } catch (err) {
    error.value = err.response?.data?.message || 'Произошла ошибка'
    console.error('Error:', err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
})
</script>
```

**Требования:**
1. Только Composition API с `<script setup>`
2. Обработка loading и error состояний
3. Использование reactive refs
4. Composables для переиспользуемой логики

### Tailwind CSS

```vue
<!-- ✅ ПРАВИЛЬНО -->
<div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow duration-300">
  <h2 class="text-2xl font-bold text-white">Заголовок</h2>
  <p class="text-indigo-100">Описание</p>
</div>
```

**Цветовая схема:**
- Primary: `indigo-500/600/700`
- Secondary: `purple-500/600`
- Success: `green-500/600`
- Error: `red-500/600`
- Градиенты: `from-indigo-500 to-purple-600`

## МойСклад API - КРИТИЧЕСКИ ВАЖНО

### Vendor API JWT

**Генерация JWT:**
```php
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [
    'sub' => $appUid,              // из URL параметра
    'iat' => time(),
    'exp' => time() + 60,          // 60 секунд
    'jti' => bin2hex(random_bytes(12))
];

// ⚠️ КРИТИЧЕСКИ ВАЖНО: JSON_UNESCAPED_SLASHES обязателен!
$headerEncoded = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
$payloadEncoded = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
$signature = base64UrlEncode(hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secretKey, true));

$jwt = "$headerEncoded.$payloadEncoded.$signature";
```

**Запрос контекста:**
```php
$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $jwt,
    'Content-Type' => 'application/json',
    'Accept' => 'application/json'
])->post("https://apps-api.moysklad.ru/api/vendor/1.0/context/{$contextKey}", []);
// ⚠️ Пустой массив [] обязателен!
```

## Что делать ВСЕГДА

1. ✅ Типизация в PHP методах
2. ✅ Логирование операций с `Log::info()` и `Log::error()`
3. ✅ Try-catch для обработки ошибок
4. ✅ Composition API в Vue
5. ✅ Tailwind utility классы
6. ✅ Loading и error состояния
7. ✅ Service Layer (логика в сервисах)
8. ✅ JSON_UNESCAPED_SLASHES для МойСклад JWT

## Что НЕ делать НИКОГДА

1. ❌ Options API в Vue
2. ❌ Inline стили или кастомный CSS
3. ❌ DB запросы в контроллерах
4. ❌ Игнорировать ошибки
5. ❌ Забывать JSON_UNESCAPED_SLASHES
6. ❌ Использовать `var`
7. ❌ Хардкодить значения
8. ❌ Коммитить `.env`

## Процесс разработки

### Добавление API endpoint

1. Создай метод в сервисе (`app/Services/`)
2. Создай метод в контроллере (`app/Http/Controllers/Api/`)
3. Добавь маршрут в `routes/api.php`
4. Добавь логирование и обработку ошибок

### Добавление страницы Vue

1. Создай компонент в `resources/js/pages/`
2. Добавь маршрут в `resources/js/router.js`
3. Создай composable если нужна переиспользуемая логика
4. Стилизуй с Tailwind (gradients indigo-purple)
5. Собери: `npm run build`

## Git Commits

Формат: `<type>: <description>`

**Types:** `feat`, `fix`, `style`, `refactor`, `docs`, `test`, `chore`

**Примеры:**
```
feat: Добавлен endpoint для получения контекста
fix: Исправлена генерация JWT токена
style: Улучшен дизайн дашборда
```

## Команды

```bash
# Backend
php artisan serve           # Dev server
php artisan migrate         # Run migrations
php artisan config:clear    # Clear cache

# Frontend
npm run dev                 # Dev with hot reload
npm run build               # Production build

# Git
git add . && git commit -m "type: message" && git push
```

## Отладка

1. Проверь `storage/logs/laravel.log`
2. Проверь Network tab в DevTools
3. Проверь консоль браузера
4. Используй `Log::info()` для debug
5. Проверь правильность JWT (JSON_UNESCAPED_SLASHES!)

## Безопасность

- **CORS:** только `online.moysklad.ru` и `dev.moysklad.ru`
- **CSRF:** отключен для `/api/*`
- **Валидация:** всегда валидируй входящие данные
- **SQL:** используй Eloquent, не raw SQL

## Ссылки

- [Laravel 11 Docs](https://laravel.com/docs/11.x)
- [Vue 3 Docs](https://vuejs.org/)
- [Tailwind CSS Docs](https://tailwindcss.com/docs)
- [МойСклад Vendor API](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [МойСклад JSON API](https://dev.moysklad.ru/doc/api/remap/1.2/)

---

**Помни:** Качество кода > Скорость разработки. Всегда следуй стандартам проекта!
