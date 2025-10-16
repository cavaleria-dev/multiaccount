<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создать администратора для админ-панели';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Создание администратора для админ-панели');
        $this->newLine();

        // Получить данные от пользователя
        $name = $this->ask('Имя администратора');
        $email = $this->ask('Email');
        $password = $this->secret('Пароль (минимум 8 символов)');
        $passwordConfirm = $this->secret('Подтвердите пароль');

        // Валидация
        if ($password !== $passwordConfirm) {
            $this->error('Пароли не совпадают!');
            return 1;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            $this->error('Ошибка валидации:');
            foreach ($validator->errors()->all() as $error) {
                $this->error('  - ' . $error);
            }
            return 1;
        }

        // Создать администратора
        try {
            $admin = AdminUser::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            $this->newLine();
            $this->info('✓ Администратор успешно создан!');
            $this->newLine();
            $this->table(
                ['ID', 'Имя', 'Email'],
                [[$admin->id, $admin->name, $admin->email]]
            );
            $this->newLine();
            $this->info('Теперь вы можете войти на /admin/login');

            return 0;

        } catch (\Exception $e) {
            $this->error('Ошибка при создании администратора: ' . $e->getMessage());
            return 1;
        }
    }
}
