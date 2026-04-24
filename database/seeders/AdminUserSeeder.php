<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@mnemon.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        $this->command->info('Admin user created: admin@mnemon.local / password');
    }
}
