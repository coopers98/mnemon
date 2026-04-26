<?php

namespace App\Console\Commands;

use App\Models\WikiPage;
use Illuminate\Console\Command;

class DecayConfidenceCommand extends Command
{
    protected $signature = 'mnemon:decay-confidence';

    protected $description = 'Recalculate confidence scores for all wiki pages based on age and source count';

    public function handle(): int
    {
        $pages = WikiPage::all();

        if ($pages->isEmpty()) {
            $this->info('No wiki pages found.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($pages as $page) {
            $newScore = $page->calculateConfidenceScore();
            $page->update(['confidence_score' => $newScore]);
            $updated++;
        }

        $this->info("Recalculated confidence scores for {$updated} wiki page(s).");

        return self::SUCCESS;
    }
}
