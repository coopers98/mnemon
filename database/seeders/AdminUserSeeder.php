<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('email', 'admin@mnemon.local')->first();

        if ($existing !== null) {
            $this->command?->info('Admin user already exists — leaving it alone.');

            return;
        }

        $password = Str::password(24);

        User::create([
            'email' => 'admin@mnemon.local',
            'name' => 'Admin',
            'password' => Hash::make($password),
        ]);

        // Written to a file rather than printed: nobody is watching the output
        // of `docker compose up -d`, and stdout would persist the credential in
        // `docker logs` forever.
        $path = storage_path('admin-password.txt');
        File::put($path, $password.PHP_EOL);
        chmod($path, 0600);

        $this->command?->info('Admin user created: admin@mnemon.local');
        $this->command?->info("Password written to {$path} (gitignored) — store it somewhere safe and delete the file.");
        $this->command?->warn('There is no password reset flow. Losing it means resetting via `php artisan tinker`.');
    }
}
