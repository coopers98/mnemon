<?php

namespace App\Console\Commands;

use App\Models\Drawer;
use Illuminate\Console\Command;

/**
 * Item 14: Retention / Forgetting Curves.
 *
 * Recomputes retention_score for every drawer based on tier-specific exponential decay.
 * Drawers with retention_score < soft_delete_threshold are soft-deleted.
 */
class ApplyRetentionCommand extends Command
{
    protected $signature = 'mnemon:apply-retention {--dry-run : Show changes without applying} {--force : Skip confirmation prompt}';

    protected $description = 'Recompute drawer retention scores using exponential decay; soft-delete low-retention items';

    public function handle(): int
    {
        $threshold = (float) config('mnemon.retention.soft_delete_threshold', 0.05);
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('This will update retention scores and soft-delete low-retention drawers. Continue?')) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $updated = 0;
        $deleted = 0;

        Drawer::query()->chunkById(200, function ($drawers) use (&$updated, &$deleted, $threshold, $dryRun) {
            foreach ($drawers as $drawer) {
                /** @var Drawer $drawer */
                $score = $drawer->computeRetentionScore();

                if (! $dryRun) {
                    $drawer->update(['retention_score' => $score]);
                }
                $updated++;

                if ($score < $threshold) {
                    if (! $dryRun) {
                        $drawer->delete(); // SoftDeletes
                    }
                    $deleted++;
                }
            }
        });

        $verb = $dryRun ? 'Would update' : 'Updated';
        $deletedVerb = $dryRun ? 'Would soft-delete' : 'Soft-deleted';

        $this->info("{$verb} retention scores for {$updated} drawer(s).");
        $this->info("{$deletedVerb} {$deleted} drawer(s) below threshold {$threshold}.");

        return self::SUCCESS;
    }
}
