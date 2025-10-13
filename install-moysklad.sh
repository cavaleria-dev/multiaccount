#!/bin/bash

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== Установка приложения Франшиза МойСклад ===${NC}"

# Проверка что запущено из корня проекта
if [ ! -f "artisan" ]; then
    echo -e "${RED}Ошибка: запустите скрипт из корня Laravel проекта${NC}"
    exit 1
fi

# 1. Создание миграций
echo -e "${YELLOW}Шаг 1: Создание файлов миграций...${NC}"

# Создание миграции accounts
cat > database/migrations/2025_10_13_000001_create_accounts_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('app_id');
            $table->uuid('account_id')->unique();
            $table->string('access_token');
            $table->string('status', 50)->default('activating');
            $table->string('account_type', 20)->nullable();
            $table->string('subscription_status', 50)->nullable();
            $table->string('tariff_name', 100)->nullable();
            $table->decimal('price_per_month', 10, 2)->default(0);
            $table->string('cause', 50)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('status');
            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
EOF

# Создание миграции child_accounts
cat > database/migrations/2025_10_13_000002_create_child_accounts_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('invitation_code', 100)->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamps();
            
            $table->foreign('parent_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
                  
            $table->foreign('child_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->unique(['parent_account_id', 'child_account_id']);
            $table->index('parent_account_id');
            $table->index('child_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_accounts');
    }
};
EOF

# Создание миграции sync_settings
cat > database/migrations/2025_10_13_000003_create_sync_settings_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->boolean('sync_catalog')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->boolean('sync_prices')->default(true);
            $table->boolean('sync_stock')->default(true);
            $table->boolean('sync_images_all')->default(false);
            $table->string('schedule', 100)->nullable();
            $table->json('catalog_filters')->nullable();
            $table->json('price_types')->nullable();
            $table->json('warehouses')->nullable();
            $table->string('product_match_field', 50)->default('article');
            $table->timestamps();
            
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_settings');
    }
};
EOF

# Создание миграции sync_logs
cat > database/migrations/2025_10_13_000004_create_sync_logs_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('sync_type', 50);
            $table->string('direction', 50);
            $table->string('status', 50);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->integer('items_total')->default(0);
            $table->integer('items_processed')->default(0);
            $table->integer('items_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('sync_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
EOF

# Создание миграции entity_mappings
cat > database/migrations/2025_10_13_000005_create_entity_mappings_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('entity_type', 50);
            $table->string('parent_entity_id');
            $table->string('child_entity_id');
            $table->string('entity_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['parent_account_id', 'child_account_id']);
            $table->index('entity_type');
            $table->unique(['parent_account_id', 'child_account_id', 'entity_type', 'parent_entity_id'], 'entity_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_mappings');
    }
};
EOF

# Создание миграции webhooks
cat > database/migrations/2025_10_13_000006_create_webhooks_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('webhook_id')->nullable();
            $table->string('entity_type', 50);
            $table->string('action', 50);
            $table->boolean('enabled')->default(true);
            $table->string('url');
            $table->timestamps();
            
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->index('account_id');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
EOF

# Создание миграции accounts_archive
cat > database/migrations/2025_10_13_000007_create_accounts_archive_table.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_archive', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->json('data');
            $table->timestamp('deleted_at');
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_archive');
    }
};
EOF

echo -e "${GREEN}✓ Миграции созданы${NC}"

# 2. Создание конфигурационного файла
echo -e "${YELLOW}Шаг 2: Создание конфигурации...${NC}"

mkdir -p config

cat > config/moysklad.php << 'EOF'
<?php

return [
    // UUID вашего приложения из ЛК разработчика
    'app_id' => env('MOYSKLAD_APP_ID', ''),
    
    // Секретный ключ (Secret Key) из ЛК разработчика
    'secret_key' => env('MOYSKLAD_SECRET_KEY', ''),
    
    // URL для работы с API
    'api_url' => env('MOYSKLAD_API_URL', 'https://api.moysklad.ru/api/remap/1.2'),
    
    // Таймауты
    'timeout' => env('MOYSKLAD_TIMEOUT', 30),
    'retry_times' => env('MOYSKLAD_RETRY_TIMES', 3),
    
    // URL вебхука для получения событий
    'webhook_url' => env('APP_URL') . '/api/webhooks/moysklad',
];
EOF

echo -e "${GREEN}✓ Конфигурация создана${NC}"

# 3. Запуск миграций
echo -e "${YELLOW}Шаг 3: Запуск миграций...${NC}"
php artisan migrate

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Миграции выполнены успешно${NC}"
else
    echo -e "${RED}✗ Ошибка при выполнении миграций${NC}"
    exit 1
fi

# 4. Проверка .env
echo -e "${YELLOW}Шаг 4: Проверка конфигурации .env...${NC}"

if ! grep -q "MOYSKLAD_APP_ID" .env; then
    echo "" >> .env
    echo "# МойСклад API" >> .env
    echo "MOYSKLAD_APP_ID=" >> .env
    echo "MOYSKLAD_SECRET_KEY=" >> .env
    echo -e "${YELLOW}⚠ Добавлены переменные MOYSKLAD в .env${NC}"
    echo -e "${YELLOW}⚠ Заполните MOYSKLAD_APP_ID и MOYSKLAD_SECRET_KEY из личного кабинета разработчика${NC}"
else
    echo -e "${GREEN}✓ Переменные MOYSKLAD уже существуют в .env${NC}"
fi

# 5. Очистка кеша
echo -e "${YELLOW}Шаг 5: Очистка кеша...${NC}"
php artisan config:clear
php artisan cache:clear

echo -e "${GREEN}✓ Кеш очищен${NC}"

# 6. Установка прав
echo -e "${YELLOW}Шаг 6: Установка прав доступа...${NC}"
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache

echo -e "${GREEN}✓ Права установлены${NC}"

echo ""
echo -e "${GREEN}=== Установка завершена! ===${NC}"
echo ""
echo -e "${YELLOW}Следующие шаги:${NC}"
echo "1. Откройте .env и заполните:"
echo "   MOYSKLAD_APP_ID=ваш-app-id"
echo "   MOYSKLAD_SECRET_KEY=ваш-secret-key"
echo ""
echo "2. Запустите: php artisan config:cache"
echo ""
echo "3. Проверьте таблицы: php artisan tinker"
echo "   >>> DB::table('accounts')->count();"
echo ""
echo "4. Создайте контроллеры в app/Http/Controllers/Api/"
echo "   - MoySkladController.php"
echo "   - WebhookController.php"
echo ""
echo "5. Протестируйте эндпоинты:"
echo "   curl https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/{appId}/{accountId}/status"`�2`�2`�2`�2`�2`�2
