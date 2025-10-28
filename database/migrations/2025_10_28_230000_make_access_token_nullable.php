<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Изменяет поле access_token на nullable для поддержки паттерна:
     * 1. Создать запись Account (без токена)
     * 2. Установить access_token через setter (автоматическое шифрование)
     * 3. Сохранить запись с зашифрованным токеном
     *
     * КОНТЕКСТ:
     * - access_token защищен от mass assignment (не в $fillable)
     * - При первой установке Account::create() пытается создать запись БЕЗ токена
     * - PostgreSQL выбрасывает NOT NULL constraint violation
     * - Токен должен устанавливаться через $account->access_token = $value (после create)
     *
     * БЕЗОПАСНОСТЬ:
     * - Токены остаются зашифрованными (encrypted cast в модели)
     * - Mass assignment защита не меняется (access_token не в fillable)
     * - Валидация на уровне приложения (токен обязателен для Install/Resume)
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Изменить NOT NULL на NULL
            // PostgreSQL: ALTER TABLE accounts ALTER COLUMN access_token DROP NOT NULL
            $table->text('access_token')->nullable()->change();
        });

        echo "✓ Changed access_token from NOT NULL to NULL\n";
        echo "  Now supports pattern: create() → set token → save()\n";
        echo "  Security: Tokens still encrypted via 'encrypted' cast\n";
    }

    /**
     * Reverse the migrations.
     *
     * ВНИМАНИЕ: Откат возможен только если в БД НЕТ записей с NULL токеном.
     * Проверяем перед откатом для безопасности.
     */
    public function down(): void
    {
        // Проверить есть ли записи с NULL токеном
        $nullTokenCount = DB::table('accounts')
            ->whereNull('access_token')
            ->count();

        if ($nullTokenCount > 0) {
            throw new \Exception(
                "Cannot rollback: {$nullTokenCount} account(s) have NULL access_token. " .
                "This would violate NOT NULL constraint. Please fix these records first."
            );
        }

        Schema::table('accounts', function (Blueprint $table) {
            // Откатить обратно к NOT NULL
            $table->text('access_token')->nullable(false)->change();
        });

        echo "⚠ Rolled back access_token to NOT NULL\n";
        echo "  WARNING: Account::create() will fail without token!\n";
    }
};
