<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Создаем схемы
        DB::statement('CREATE SCHEMA IF NOT EXISTS app');
        DB::statement('CREATE SCHEMA IF NOT EXISTS dw');
    }

    public function down()
    {
        DB::statement('DROP SCHEMA IF EXISTS dw CASCADE');
        DB::statement('DROP SCHEMA IF EXISTS app CASCADE');
    }
};