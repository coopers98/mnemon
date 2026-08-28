<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::delete(storage_path('admin-password.txt'));
        parent::tearDown();
    }

    public function test_seeded_admin_password_is_not_the_literal_string_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@mnemon.local')->first();

        $this->assertNotNull($admin);
        $this->assertFalse(
            Hash::check('password', $admin->password),
            'the seeder must not ship a known password'
        );
    }

    public function test_generated_password_is_written_to_a_file_and_works(): void
    {
        $this->seed(AdminUserSeeder::class);

        $path = storage_path('admin-password.txt');
        $this->assertFileExists($path, 'the credential must be recoverable — nobody watches `compose up -d`');

        $password = trim(File::get($path));
        $admin = User::where('email', 'admin@mnemon.local')->first();

        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_reseeding_does_not_replace_an_existing_admin(): void
    {
        $this->seed(AdminUserSeeder::class);
        $original = User::where('email', 'admin@mnemon.local')->first()->password;

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(
            $original,
            User::where('email', 'admin@mnemon.local')->first()->password,
            'the entrypoint runs on every boot; reseeding must not rotate the password'
        );
        $this->assertSame(1, User::where('email', 'admin@mnemon.local')->count());
    }
}
