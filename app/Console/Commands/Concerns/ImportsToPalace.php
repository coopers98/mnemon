<?php

namespace App\Console\Commands\Concerns;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\ContentSanitizer;
use Illuminate\Support\Str;

/**
 * Shared logic for commands that import files into the Memory Palace.
 *
 * Provides counter management, progress/summary display, dedup checking,
 * drawer creation, slug generation, and file safety guards.
 */
trait ImportsToPalace
{
    private int $scanned = 0;

    private int $imported = 0;

    private int $skipped = 0;

    private int $errors = 0;

    private bool $sanitizeContent = true;

    /**
     * Sanitize content using ContentSanitizer if enabled.
     */
    private function maybeSanitize(string $content): string
    {
        if (! $this->sanitizeContent) {
            return $content;
        }

        return app(ContentSanitizer::class)->sanitize($content);
    }

    /**
     * Reset all counters to zero. Call at the top of handle().
     */
    private function resetCounters(): void
    {
        $this->scanned = 0;
        $this->imported = 0;
        $this->skipped = 0;
        $this->errors = 0;
    }

    /**
     * Run a progress bar over items, calling $callback for each.
     */
    private function processWithProgress(array $items, callable $callback): void
    {
        $bar = $this->output->createProgressBar(count($items));
        $bar->start();

        foreach ($items as $item) {
            $bar->advance();
            $this->scanned++;

            try {
                $callback($item);
            } catch (\Throwable $e) {
                $this->errors++;
                report($e);
                $this->warn('Error processing file — see logs for details.');
            }
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Display the import/ingest summary table.
     */
    private function printSummaryTable(string $label = 'Import Summary'): void
    {
        $this->info($label.':');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Files scanned', $this->scanned],
                ['Imported', $this->imported],
                ['Skipped (duplicate)', $this->skipped],
                ['Errors', $this->errors],
            ]
        );
    }

    /**
     * Generate a URL-safe slug that preserves colon-separated namespaces as hyphens.
     */
    private function makeSlug(string $value): string
    {
        return Str::slug(str_replace(':', '-', $value));
    }

    /**
     * Check whether a drawer with the given content hash already exists in the specified wing.
     *
     * Dedup is intentionally wing-scoped (not room-scoped) so that the same content
     * is never imported twice into any room within a wing, even if the file is moved
     * between rooms.
     */
    private function isDuplicateInWing(string $wingSlug, string $contentHash): bool
    {
        $existingWing = Wing::where('slug', $wingSlug)->first();

        if (! $existingWing) {
            return false;
        }

        return Drawer::whereHas('room', function ($query) use ($existingWing) {
            $query->where('wing_id', $existingWing->id);
        })
            ->whereJsonContains('metadata->content_hash', $contentHash)
            ->exists();
    }

    /**
     * Create (or find) a wing and room, then insert a drawer.
     *
     * @return Drawer The created drawer instance.
     */
    private function createDrawer(
        string $wingName,
        string $wingSlug,
        string $roomName,
        string $content,
        string $contentHash,
        string $source,
        string $relativePath,
    ): Drawer {
        $wing = Wing::firstOrCreate(
            ['slug' => $wingSlug],
            ['name' => $wingName, 'slug' => $wingSlug]
        );

        $roomSlug = Str::slug($roomName);
        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => $roomSlug],
            ['name' => $roomName, 'wing_id' => $wing->id]
        );

        return Drawer::create([
            'content' => $this->maybeSanitize($content),
            'room_id' => $room->id,
            'source' => $source,
            'metadata' => [
                'content_hash' => $contentHash,
                'original_path' => $relativePath,
            ],
        ]);
    }

    /**
     * Validate a file path for symlink safety and size limits.
     *
     * Returns the resolved real path if the file is safe to process, or false otherwise.
     * Callers MUST use the returned path for subsequent reads to avoid TOCTOU races.
     */
    private function validateFilePath(string $filePath, string $basePath): string|false
    {
        // Symlink safety: ensure resolved path is within the base directory
        $realPath = realpath($filePath);
        $realBase = rtrim(realpath($basePath) ?: '', DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($realPath === false || $realBase === DIRECTORY_SEPARATOR || ! str_starts_with($realPath, $realBase)) {
            $relativeName = $this->relativePath($filePath, $basePath);
            $this->warn("Skipping file outside base directory: {$relativeName}");
            $this->errors++;

            return false;
        }

        // File size guard: skip files larger than 10 MB
        if (filesize($realPath) > 10 * 1024 * 1024) {
            $relativeName = $this->relativePath($filePath, $basePath);
            $this->warn("Skipping oversized file: {$relativeName}");
            $this->errors++;

            return false;
        }

        return $realPath;
    }

    /**
     * Calculate a relative path from a base directory.
     */
    /**
     * Calculate a relative path from a base directory.
     *
     * Used for display purposes and metadata storage — never for file I/O.
     */
    private function relativePath(string $filePath, string $basePath): string
    {
        $realPath = realpath($filePath) ?: $filePath;
        $realBase = rtrim(realpath($basePath) ?: $basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($realPath, $realBase)) {
            return substr($realPath, strlen($realBase));
        }

        return $filePath;
    }
}
