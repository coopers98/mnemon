<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ImportsToPalace;
use App\Models\WikiPage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportMemoryCommand extends Command
{
    use ImportsToPalace;

    protected $signature = 'mnemon:import-memory
        {path : Path to the memory directory or MEMORY.md file}
        {--dry-run : Report what would be imported without writing}
        {--wing= : Only import specific wing type (daily, project, person, decision, memory-index)}';

    protected $description = 'Import markdown memory files as palace drawers';

    public function handle(): int
    {
        $this->resetCounters();

        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');
        $wingFilter = $this->option('wing');

        if (! file_exists($path)) {
            $this->error("Path does not exist: {$path}");

            return self::FAILURE;
        }

        $files = $this->collectFiles($path, $wingFilter);

        if (empty($files)) {
            $this->info('No files found to import.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Importing '.count($files).' file(s)...');

        $basePath = is_file($path) ? dirname($path) : $path;

        $this->processWithProgress($files, function (array $file) use ($dryRun, $basePath) {
            $this->processFile($file, $dryRun, $basePath);
        });

        $this->printSummaryTable('Import Summary');

        return self::SUCCESS;
    }

    /**
     * Collect all files to process based on path and wing filter.
     *
     * @return array<int, array{path: string, wing: string, room: string, wiki_name: string|null, wiki_type: string|null}>
     */
    private function collectFiles(string $path, ?string $wingFilter): array
    {
        $files = [];

        // If path is a single MEMORY.md file
        if (is_file($path) && basename($path) === 'MEMORY.md') {
            if ($wingFilter === null || $wingFilter === 'memory-index') {
                $files[] = [
                    'path' => $path,
                    'wing' => 'memory-index',
                    'room' => 'index',
                    'wiki_name' => null,
                    'wiki_type' => null,
                ];
            }

            return $files;
        }

        if (! is_dir($path)) {
            return $files;
        }

        // Check for MEMORY.md at the root of the path
        $memoryMdPath = rtrim($path, '/').'/MEMORY.md';
        if (file_exists($memoryMdPath) && ($wingFilter === null || $wingFilter === 'memory-index')) {
            $files[] = [
                'path' => $memoryMdPath,
                'wing' => 'memory-index',
                'room' => 'index',
                'wiki_name' => null,
                'wiki_type' => null,
            ];
        }

        // memory/YYYY-MM-DD.md → wing: session:daily, room: YYYY-MM
        $memoryDir = is_dir($path.'/memory') ? $path.'/memory' : $path;
        $dailyDir = $memoryDir;
        if ($wingFilter === null || $wingFilter === 'daily') {
            $dailyFiles = glob($dailyDir.'/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].md');
            foreach (($dailyFiles ?: []) as $file) {
                $basename = basename($file, '.md');
                $room = substr($basename, 0, 7); // YYYY-MM
                $files[] = [
                    'path' => $file,
                    'wing' => 'session:daily',
                    'room' => $room,
                    'wiki_name' => null,
                    'wiki_type' => null,
                ];
            }
        }

        // memory/projects/*.md → wing: project:<name>, compile as wiki
        $projectsDir = $memoryDir.'/projects';
        if (is_dir($projectsDir) && ($wingFilter === null || $wingFilter === 'project')) {
            $projectFiles = glob($projectsDir.'/*.md');
            foreach (($projectFiles ?: []) as $file) {
                $name = basename($file, '.md');
                $files[] = [
                    'path' => $file,
                    'wing' => 'project:'.$name,
                    'room' => 'notes',
                    'wiki_name' => 'project:'.$name,
                    'wiki_type' => 'project',
                ];
            }
        }

        // memory/people/*.md → wing: person:<name>, compile as wiki
        $peopleDir = $memoryDir.'/people';
        if (is_dir($peopleDir) && ($wingFilter === null || $wingFilter === 'person')) {
            $peopleFiles = glob($peopleDir.'/*.md');
            foreach (($peopleFiles ?: []) as $file) {
                $name = basename($file, '.md');
                $files[] = [
                    'path' => $file,
                    'wing' => 'person:'.$name,
                    'room' => 'notes',
                    'wiki_name' => 'person:'.$name,
                    'wiki_type' => 'person',
                ];
            }
        }

        // memory/decisions/*.md → wing: decision, room by filename slug
        $decisionsDir = $memoryDir.'/decisions';
        if (is_dir($decisionsDir) && ($wingFilter === null || $wingFilter === 'decision')) {
            $decisionFiles = glob($decisionsDir.'/*.md');
            foreach (($decisionFiles ?: []) as $file) {
                $slug = Str::slug(basename($file, '.md'));
                $files[] = [
                    'path' => $file,
                    'wing' => 'decision',
                    'room' => $slug,
                    'wiki_name' => null,
                    'wiki_type' => null,
                ];
            }
        }

        return $files;
    }

    /**
     * Process a single file: create wing, room, drawer (with dedup), and optionally a wiki page.
     */
    private function processFile(array $file, bool $dryRun, string $basePath): void
    {
        // Validate file safety (symlink traversal + size guard)
        $resolvedPath = $this->validateFilePath($file['path'], $basePath);
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
        $wingSlug = $this->makeSlug($file['wing']);

        // Dedup is intentionally wing-scoped (not room-scoped) — same content
        // should not exist twice in any room within a wing.
        if ($this->isDuplicateInWing($wingSlug, $contentHash)) {
            $this->skipped++;

            return;
        }

        if ($dryRun) {
            $dryRunPath = $this->relativePath($file['path'], $basePath);
            $this->info("[DRY RUN] Would import: {$dryRunPath} → wing:{$file['wing']}, room:{$file['room']}");
            $this->imported++;

            return;
        }

        $relativePath = $this->relativePath($file['path'], $basePath);

        $this->createDrawer(
            wingName: $file['wing'],
            wingSlug: $wingSlug,
            roomName: $file['room'],
            content: $content,
            contentHash: $contentHash,
            source: 'openclaw-import',
            relativePath: $relativePath,
        );

        // Create wiki page if applicable (project/person files)
        if ($file['wiki_name'] !== null) {
            $this->createOrUpdateWikiPage($file['wiki_name'], $file['wiki_type'], $content);
        }

        $this->imported++;
    }

    /**
     * Create or update a wiki page from imported content.
     */
    private function createOrUpdateWikiPage(string $name, string $type, string $content): void
    {
        WikiPage::updateOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'content' => $content,
                'last_compiled_at' => now(),
            ]
        );
    }
}
