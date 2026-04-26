<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SyncOpenclawCommand extends Command
{
    protected $signature = 'mnemon:sync-openclaw
        {path : Path to the OpenClaw workspace directory}
        {--no-sanitize : Skip content sanitization}';

    protected $description = 'Convenience wrapper: import memory, report new content, run lint';

    public function handle(): int
    {
        $path = $this->argument('path');
        $noSanitize = $this->option('no-sanitize');

        if (! file_exists($path)) {
            $this->error("Path does not exist: {$path}");

            return self::FAILURE;
        }

        // Step 1: Import memory
        $this->info('Step 1/3: Importing memory files...');
        $importArgs = ['path' => $path];
        if ($noSanitize) {
            $importArgs['--no-sanitize'] = true;
        }
        Artisan::call('mnemon:import-memory', $importArgs, $this->output);

        // Step 2: Report what's new (compile stale candidates)
        $this->newLine();
        $this->info('Step 2/3: Checking for stale wiki pages...');
        Artisan::call('mnemon:auto-compile-stale', [], $this->output);

        // Step 3: Run lint
        $this->newLine();
        $this->info('Step 3/3: Running wiki lint...');
        Artisan::call('mnemon:auto-lint', [], $this->output);

        $this->newLine();
        $this->info('Sync complete.');

        return self::SUCCESS;
    }
}
