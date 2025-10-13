#!/bin/bash

echo "🧪 Тестирование с реальными UUID"

# Генерация UUID (замените первый на реальный из ЛК)
APP_ID="3a753831-5040-4e5d-a1d6-119bf1748847"
ACCOUNT_ID=$(uuidgen | tr '[:upper:]' '[:lower:]')

echo "APP_ID: $APP_ID"
echo "ACCOUNT_ID: $ACCOUNT_ID"
echo ""

# Тест 1: NotFound
echo "✓ Тест 1: Статус (должен быть NotFound)"
curl -s "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}/status" | jq
echo ""

# Тест 2: Установка
echo "✓ Тест 2: Установка приложения"
curl -s -X PUT "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "access_token": "test_token_12345",
    "cause": "Install",
    "subscription": {
      "status": "Active",
      "tariff": {"name": "Главный", "price": 1000},
      "pricePerMonth": 1000
    }
  }' | jq
echo ""

# Тест 3: Статус после установки
echo "✓ Тест 3: Статус после установки"
curl -s "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}/status" | jq
echo ""

# Тест 4: Проверка в БД
echo "✓ Тест 4: Проверка в БД"
cd /var/www/app.cavaleria.ru
php artisan tinker --execute="echo json_encode(DB::table('accounts')->where('account_id', '${ACCOUNT_ID}')->first(), JSON_PRETTY_PRINT);"
echo ""

# Тест 5: Удаление
echo "✓ Тест 5: Удаление приложения"
curl -s -X DELETE "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}" \
  -H "Content-Type: application/json" \
  -d '{"cause":"Uninstall"}' | jq

echo ""
echo "✅ Тестирование завершено!"
