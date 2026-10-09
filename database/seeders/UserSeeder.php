<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * ログイン確認用の一般ユーザーを登録する。
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'user1@example.com'],
            [
                'name' => 'user1',
                'password' => '123456789',
                'email_verified_at' => now(),
            ],
        );
    }
}
