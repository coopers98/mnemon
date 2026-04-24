<?php

namespace App\Console\Commands;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Services\EmbeddingManager;
use Illuminate\Console\Command;

class ReembedCommand extends Command
{
    protected $signature = 'mnemon:reembed {--model=all : Which model to reembed (drawer|wiki|all)} {--batch=100 : Batch size}';

    protected $description = 'Re-embed all drawers and wiki pages using the current driver';

    public function __construct(
        private EmbeddingManager $embeddingManager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $model = $this->option('model');
        $batchSize = (int) $this->option('batch');

        $stats = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
        ];

        if (in_array($model, ['drawer', 'all'])) {
            $this->info('Re-embedding drawers...');
            $drawerStats = $this->reembedModel(Drawer::class, $batchSize);
            $stats['total'] += $drawerStats['total'];
            $stats['successful'] += $drawerStats['successful'];
            $stats['failed'] += $drawerStats['failed'];
        }

        if (in_array($model, ['wiki', 'all'])) {
            $this->info('Re-embedding wiki pages...');
            $wikiStats = $this->reembedModel(WikiPage::class, $batchSize);
            $stats['total'] += $wikiStats['total'];
            $stats['successful'] += $wikiStats['successful'];
            $stats['failed'] += $wikiStats['failed'];
        }

        $this->newLine();
        $this->info('Re-embedding complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total processed', $stats['total']],
                ['Successful', $stats['successful']],
                ['Failed', $stats['failed']],
            ]
        );

        return self::SUCCESS;
    }

    protected function reembedModel(string $modelClass, int $batchSize): array
    {
        $driver = $this->embeddingManager->driver();
        $driverName = config('mnemon.embedding.driver');

        $stats = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
        ];

        $query = $modelClass::query();
        $total = $query->count();

        if ($total === 0) {
            $this->warn("No {$modelClass} records found.");

            return $stats;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $supportsVector = config('database.default') !== 'sqlite';

        $query->chunk($batchSize, function ($records) use ($driver, $driverName, &$stats, $bar, $supportsVector) {
            foreach ($records as $record) {
                $stats['total']++;

                if ($driverName === 'none') {
                    if ($supportsVector) {
                        $record->embedding = null;
                        $record->saveQuietly();
                    }
                    $stats['successful']++;
                    $bar->advance();

                    continue;
                }

                $embedding = $driver->embed($record->content ?? '');

                if ($embedding !== null) {
                    if ($supportsVector) {
                        $record->embedding = $embedding;
                        $record->saveQuietly();
                    }
                    $stats['successful']++;
                } else {
                    $stats['failed']++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return $stats;
    }
}
