<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ImportsToPalace;
use Illuminate\Console\Command;

class IngestSessionsCommand extends Command
{
    use ImportsToPalace;

    protected $signature = 'mnemon:ingest-sessions
        {path : Path to the directory containing session transcript files}
        {--dry-run : Report what would be ingested without writing}
        {--no-sanitize : Skip content sanitization (store raw content)}';

    protected $description = 'Ingest OpenClaw session transcript files as palace drawers';

    public function handle(): int
    {
        $this->resetCounters();
        $this->sanitizeContent = ! $this->option('no-sanitize');

        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');

        if (! is_dir($path)) {
            $this->error("Directory does not exist: {$path}");

            return self::FAILURE;
        }

        $files = $this->collectSessionFiles($path);

        if (empty($files)) {
            $this->info('No session files found to ingest.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Ingesting '.count($files).' session file(s)...');

        $basePath = $path;

        $this->processWithProgress($files, function (string $filePath) use ($dryRun, $basePath) {
            $this->processSessionFile($filePath, $dryRun, $basePath);
        });

        $this->printSummaryTable('Ingest Summary');

        return self::SUCCESS;
    }

    /**
     * Collect all markdown session files from the directory.
     *
     * @return array<int, string>
     */
    private function collectSessionFiles(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.md');

        return $files !== false ? $files : [];
    }

    /**
     * Process a single session transcript file.
     */
    private function processSessionFile(string $filePath, bool $dryRun, string $basePath): void
    {
        // Validate file safety (symlink traversal + size guard)
        $resolvedPath = $this->validateFilePath($filePath, $basePath);
        if ($resolvedPath === false) {
            return;
        }

        $content = file_get_contents($resolvedPath);

        // Skip empty files
        if (trim($content) === '') {
            $this->skipped++;

            return;
        }

        $contentHash = hash('sha256', $content);
        $room = $this->inferRoomFromFilename($filePath);
        $wingSlug = 'session-daily';

        // Dedup is intentionally wing-scoped (not room-scoped) — same content
        // should not exist twice in any room within a wing.
        if ($this->isDuplicateInWing($wingSlug, $contentHash)) {
            $this->skipped++;

            return;
        }

        if ($dryRun) {
            $dryRunPath = $this->relativePath($filePath, $basePath);
            $this->info("[DRY RUN] Would ingest: {$dryRunPath} → wing:session:daily, room:{$room}");
            $this->imported++;

            return;
        }

        $relativePath = $this->relativePath($filePath, $basePath);

        $this->createDrawer(
            wingName: 'session:daily',
            wingSlug: $wingSlug,
            roomName: $room,
            content: $content,
            contentHash: $contentHash,
            source: 'openclaw',
            relativePath: $relativePath,
        );

        $this->imported++;
    }

    /**
     * Infer the YYYY-MM room from the filename.
     * Tries to extract a date pattern; falls back to current month.
     */
    private function inferRoomFromFilename(string $filePath): string
    {
        $basename = basename($filePath, '.md');

        // Try YYYY-MM-DD pattern
        if (preg_match('/(\d{4}-\d{2})-\d{2}/', $basename, $matches)) {
            return $matches[1];
        }

        // Try YYYY-MM pattern
        if (preg_match('/(\d{4}-\d{2})/', $basename, $matches)) {
            return $matches[1];
        }

        // Fallback to current month
        return now()->format('Y-m');
    }
}
