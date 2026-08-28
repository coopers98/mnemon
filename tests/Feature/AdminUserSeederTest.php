<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $passwordPath;

    /**
     * Point the seeder at a throwaway file.
     *
     * storage_path() is not redirected under APP_ENV=testing, so the shipped
     * default is the very file a native install writes — and tearDown() below
     * deletes it. A contributor who runs `migrate --seed` per USERGUIDE.md and
     * then `php artisan test` before reading the file would permanently lose
     * the only copy of their admin password: the panel is ->login() only, with
     * no reset flow.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->passwordPath = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'mnemon-admin-password-'.Str::random(16).'.txt';

        config(['mnemon.admin_password_path' => $this->passwordPath]);
    }

    protected function tearDown(): void
    {
        File::delete($this->passwordPath);
        parent::tearDown();
    }

    public function test_the_shipped_default_path_is_the_storage_file_the_docs_name(): void
    {
        // Every other test here overrides the path, so without this nothing
        // would notice the default breaking — and the default is what the
        // USERGUIDE, the .gitignore entry, and the group 2 Docker entrypoint
        // all rely on. Read the file rather than the container, precisely
        // because setUp() has overridden the container.
        $shipped = require config_path('mnemon.php');

        $this->assertSame(
            storage_path('admin-password.txt'),
            $shipped['admin_password_path'],
        );
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

        $path = $this->passwordPath;
        $this->assertFileExists($path, 'the credential must be recoverable — nobody watches `compose up -d`');

        $password = trim(File::get($path));
        $admin = User::where('email', 'admin@mnemon.local')->first();

        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_generated_password_file_is_not_world_readable(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX file permissions are not meaningful on Windows.');
        }

        $this->seed(AdminUserSeeder::class);

        $path = $this->passwordPath;
        $mode = fileperms($path) & 0777;

        $this->assertSame(
            0600,
            $mode,
            sprintf('expected mode 0600, got %o — the credential file must never be group/world readable', $mode)
        );
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
