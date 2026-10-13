<?php

namespace Tests\Feature\Demo;

use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DemoResetCommandTest extends TestCase
{
    private string $dir;

    private string $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/demo-reset');
        File::deleteDirectory($this->dir);
        File::ensureDirectoryExists($this->dir);
        $this->live = "{$this->dir}/demo.sqlite";

        config([
            'mnemon.demo.enabled' => true,
            'database.default' => 'demo_live',
            'database.connections.demo_live' => [
                'driver' => 'sqlite', 'database' => $this->live, 'prefix' => '',
                'foreign_key_constraints' => true, 'url' => null,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('demo_live');
        DB::purge('demo_build');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function leftovers(): array
    {
        return array_values(array_filter(
            File::files($this->dir),
            fn ($f) => ! in_array($f->getFilename(), ['demo.sqlite', 'demo.sqlite.lock'], true),
        ));
    }

    public function test_first_reset_creates_the_live_file(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        $this->assertFileExists($this->live);
        DB::purge('demo_live');
        $this->assertGreaterThanOrEqual(10, WikiPage::count());
        $this->assertSame([], $this->leftovers());
    }

    public function test_second_reset_replaces_the_first(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        DB::purge('demo_live');
        WikiPage::query()->update(['title' => 'tampered']);
        DB::purge('demo_live');

        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        DB::purge('demo_live');
        $this->assertSame(0, WikiPage::where('title', 'tampered')->count());
    }

    public function test_failed_build_leaves_the_live_file_untouched(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        DB::purge('demo_live');
        $before = md5_file($this->live);

        config(['mnemon.demo.fixtures' => "{$this->dir}/missing"]);
        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
        $this->assertSame([], $this->leftovers());
    }

    public function test_leftover_building_file_is_replaced(): void
    {
        File::put("{$this->live}.building", 'garbage from a crashed run');
        File::put("{$this->live}.building-journal", 'x');

        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        $this->assertSame([], $this->leftovers());
    }

    public function test_refuses_when_demo_mode_is_off(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        $before = md5_file($this->live);
        config(['mnemon.demo.enabled' => false]);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
    }

    public function test_refuses_a_non_sqlite_connection(): void
    {
        config(['database.connections.demo_live.driver' => 'pgsql']);
        $this->artisan('mnemon:demo-reset')->assertFailed();
        $this->assertFileDoesNotExist($this->live);
    }

    public function test_refuses_a_file_outside_storage(): void
    {
        $outside = sys_get_temp_dir().'/mnemon-demo-reset-'.uniqid().'.sqlite';
        config(['database.connections.demo_live.database' => $outside]);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertFileDoesNotExist($outside);
    }

    public function test_refuses_to_replace_a_database_it_did_not_build(): void
    {
        $pdo = new \PDO("sqlite:{$this->live}");
        $pdo->exec('CREATE TABLE drawers (id INTEGER PRIMARY KEY, content TEXT)');
        $pdo->exec("INSERT INTO drawers (content) VALUES ('a real memory')");
        $pdo = null;
        $before = md5_file($this->live);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
    }
}
