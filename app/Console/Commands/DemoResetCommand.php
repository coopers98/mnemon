<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Rebuilds the public demo's brain (docs/design/2026-10-13-public-demo-design.md §4).
 *
 * The same command on a real install would erase the brain, so it refuses
 * unless demo mode is on, the database is a SQLite file inside storage/, and
 * any existing file is one this command built. It builds beside the live file
 * and renames over it, so a visitor sees the old brain or the new one, never
 * an empty or half-seeded one.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'mnemon:demo-reset';

    protected $description = 'Rebuild the public demo brain from its fixtures and swap it in (demo mode only)';

    private const SIDECARS = ['-journal', '-wal', '-shm'];

    public function handle(): int
    {
        if (($refusal = $this->refusal()) !== null) {
            $this->error("Refusing to reset: {$refusal}.");

            return self::FAILURE;
        }

        $live = (string) config('database.connections.'.config('database.default').'.database');
        $building = "{$live}.building";
        $lock = fopen("{$live}.lock", 'c');
        flock($lock, LOCK_EX);

        try {
            $this->remove($building);
            touch($building);

            config(['database.connections.demo_build' => array_merge(
                config('database.connections.'.config('database.default')),
                ['database' => $building, 'url' => null, 'journal_mode' => 'delete'],
            )]);

            $this->runStep('migrate', ['--database' => 'demo_build', '--force' => true]);
            // db:seed --database makes demo_build the default connection while
            // the seeder runs; calling the seeder directly would write to the live file.
            $this->runStep('db:seed', ['--class' => DemoSeeder::class, '--database' => 'demo_build', '--force' => true]);

            DB::purge('demo_build');
            DB::purge(config('database.default'));
            if (! rename($building, $live)) {
                throw new RuntimeException("could not move {$building} over {$live}");
            }
            foreach (self::SIDECARS as $suffix) {
                @unlink($live.$suffix);
            }

            $this->callSilently('cache:clear');
        } catch (Throwable $e) {
            DB::purge('demo_build');
            $this->remove($building);
            $this->error('Demo reset failed; the live demo is unchanged: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->info('Demo brain rebuilt.');

        return self::SUCCESS;
    }

    /** Why this database must not be replaced, or null if it may be. */
    private function refusal(): ?string
    {
        if (! config('mnemon.demo.enabled')) {
            return 'demo mode is off (MNEMON_DEMO is not true), and this would erase the brain';
        }

        $connection = (array) config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'sqlite') {
            return 'the default database connection is not SQLite';
        }

        $live = (string) ($connection['database'] ?? '');
        $dir = $live === '' || $live === ':memory:' ? false : realpath(dirname($live));
        $storage = realpath(storage_path());
        // realpath on both sides: zero-downtime releases reach storage/ through a symlink.
        if ($dir === false || $storage === false || ! str_starts_with($dir.DIRECTORY_SEPARATOR, $storage.DIRECTORY_SEPARATOR)) {
            return 'the database file is not inside storage/';
        }

        if (is_file($live) && ! $this->builtByUs($live)) {
            return 'the existing database was not built by mnemon:demo-reset (it has no '.DemoSeeder::MARKER_TABLE.' table)';
        }

        return null;
    }

    private function builtByUs(string $path): bool
    {
        try {
            $pdo = new PDO("sqlite:{$path}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $found = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '".DemoSeeder::MARKER_TABLE."'")->fetchColumn();

            return $found !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function runStep(string $command, array $arguments): void
    {
        if ($this->call($command, $arguments) !== self::SUCCESS) {
            throw new RuntimeException("{$command} failed");
        }
    }

    private function remove(string $path): void
    {
        foreach (['', ...self::SIDECARS] as $suffix) {
            @unlink($path.$suffix);
        }
    }
}
