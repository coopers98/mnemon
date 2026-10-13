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
        if (config('mnemon.demo.enabled')) {
            $this->error('Refusing to run in demo mode: the demo brain is rebuilt nightly by mnemon:demo-reset.');

            return self::FAILURE;
        }

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
