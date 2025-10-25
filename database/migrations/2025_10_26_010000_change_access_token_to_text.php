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
     * Изменяет тип колонки access_token с VARCHAR(255) на TEXT для поддержки
     * зашифрованных токенов, которые занимают 260-400 символов.
     *
     * ВАЖНО: Эта миграция должна быть запущена ПЕРЕД шифрованием токенов
     * командой php artisan accounts:encrypt-tokens
     *
     * CONTEXT:
     * - Laravel Encryption добавляет IV + MAC + base64 encoding
     * - Зашифрованный токен: {"iv":"...","value":"...","mac":"...","tag":""}
     * - Результат: 260-400 символов (не помещается в VARCHAR(255))
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Изменить тип колонки с VARCHAR(255) на TEXT
            // TEXT поддерживает строки до 1GB в PostgreSQL
            $table->text('access_token')->change();
        });

        echo "✓ Changed access_token column type from VARCHAR(255) to TEXT\n";
        echo "  Now supports encrypted tokens (260-400 characters)\n";
        echo "  Next step: php artisan accounts:encrypt-tokens\n";
    }

    /**
     * Reverse the migrations.
     *
     * ВНИМАНИЕ: Откат может упасть если в БД есть зашифрованные токены
     * длиннее 255 символов. Используйте только в development/testing.
     */
    public function down(): void
    {
        // Проверить есть ли токены длиннее 255 символов
        $longTokensCount = DB::table('accounts')
            ->whereNotNull('access_token')
            ->whereRaw('LENGTH(access_token) > 255')
            ->count();

        if ($longTokensCount > 0) {
            throw new \Exception(
                "Cannot rollback: {$longTokensCount} account(s) have access_token longer than 255 characters. " .
                "Decrypt tokens first using: php artisan accounts:encrypt-tokens (with modified command to decrypt)"
            );
        }

        Schema::table('accounts', function (Blueprint $table) {
            // Откатить обратно к VARCHAR(255)
            $table->string('access_token', 255)->change();
        });

        echo "⚠ Rolled back access_token column type to VARCHAR(255)\n";
        echo "  IMPORTANT: This means you can no longer store encrypted tokens!\n";
    }
};
