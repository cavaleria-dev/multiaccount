# GitHub Copilot Instructions - Мультиаккаунты МойСклад

## Контекст проекта

Это Laravel 11 + Vue 3 приложение для управления франшизной сетью в МойСклад.
Приложение работает как iframe внутри интерфейса МойСклад и использует Vendor API для получения контекста пользователя.

**Стек:** PHP 8.2+, Laravel 11, SQLite, Vue 3 Composition API, Tailwind CSS

## Стандарты кода

### PHP/Laravel - всегда используй
- Типизацию: `public function method(Request $request): JsonResponse`
- Логирование: `Log::info('Operation', ['data' => $data])`
- Try-catch для обработки ошибок
- Service Layer Pattern (бизнес-логика в сервисах)

### Vue 3 - всегда используй
- Composition API с `<script setup>`
- Reactive refs: `const data = ref(null)`
- Composables для переиспользуемой логики
- Обработку loading и error состояний

### Tailwind CSS - всегда используй
- Только utility классы (никакого кастомного CSS)
- Цвета: indigo-500/600/700, purple-500/600, green/red для success/error
- Градиенты: `bg-gradient-to-r from-indigo-500 to-purple-600`
- Скругления: `rounded-xl`, `rounded-2xl`
- Transitions для hover: `transition-shadow duration-300`

## МойСклад API специфика

### Vendor API JWT - КРИТИЧЕСКИ ВАЖНО
```php
// При генерации JWT ОБЯЗАТЕЛЬНО использовать JSON_UNESCAPED_SLASHES
$header = json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES);
$payload = json_encode([
    'sub' => $appUid,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => bin2hex(random_bytes(12))
], JSON_UNESCAPED_SLASHES);

// POST запрос с пустым массивом в теле
Http::post($url, []);  // Не null, а именно пустой массив!
```

## Что делать ВСЕГДА
1. ✅ Типизация в PHP
2. ✅ Логирование операций
3. ✅ Try-catch для ошибок
4. ✅ Composition API в Vue
5. ✅ Tailwind utility классы
6. ✅ JSON_UNESCAPED_SLASHES для МойСклад JWT

## Что НЕ делать НИКОГДА
1. ❌ Options API в Vue
2. ❌ Inline стили или кастомный CSS
3. ❌ DB запросы в контроллерах
4. ❌ Игнорировать ошибки
5. ❌ Забывать JSON_UNESCAPED_SLASHES
6. ❌ Использовать `var`
7. ❌ Коммитить `.env`

## Архитектура

```
Backend:
app/Http/Controllers/Api/ - HTTP обработка
app/Services/            - бизнес-логика
app/Models/              - Eloquent модели

Frontend:
resources/js/pages/      - страницы
resources/js/composables/ - переиспользуемая логика
resources/js/router.js   - Vue Router
```

## Команды

```bash
php artisan serve        # Dev server
php artisan migrate      # Migrations
npm run dev              # Frontend dev
npm run build            # Production build
```
