<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

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
        $path = storage_path('admin-password.txt');

        // Create the file restrictive-first, then write: file_put_contents()
        // on a brand-new path creates it at the process umask (commonly 644,
        // world-readable), and chmod-ing afterward would leave the plaintext
        // password briefly exposed. Persist the credential before creating
        // the user, too — if any step here fails we throw and never create
        // the admin, so a retried seeder run (e.g. the next container boot)
        // starts over instead of leaving an admin with no recoverable
        // password.
        if (! touch($path)) {
            throw new RuntimeException("Could not create {$path} to store the generated admin password.");
        }

        if (! File::chmod($path, 0600)) {
            throw new RuntimeException("Could not set {$path} to mode 0600.");
        }

        if (File::put($path, $password.PHP_EOL) === false) {
            throw new RuntimeException("Could not write the generated admin password to {$path}.");
        }

        User::create([
            'email' => 'admin@mnemon.local',
            'name' => 'Admin',
            'password' => Hash::make($password),
        ]);

        $this->command?->info('Admin user created: admin@mnemon.local');
        $this->command?->info("Password written to {$path} (gitignored) — store it somewhere safe and delete the file.");
        $this->command?->warn('There is no password reset flow. Losing it means resetting via `php artisan tinker`.');
    }
}
