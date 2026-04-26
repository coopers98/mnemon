<?php

namespace App\Console\Commands;

use App\Models\WikiPage;
use Illuminate\Console\Command;

class AutoCompileStaleCommand extends Command
{
    protected $signature = 'mnemon:auto-compile-stale';

    protected $description = 'Find wiki pages with pending drawers since last compile and output their names';

    public function handle(): int
    {
        $pages = WikiPage::where('pending_drawers_since_compile', '>', 0)
            ->orderByDesc('pending_drawers_since_compile')
            ->get();

        if ($pages->isEmpty()) {
            $this->line(json_encode([
                'candidates' => [],
                'count' => 0,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $candidates = $pages->map(fn ($p) => [
            'name' => $p->name,
            'pending_drawers' => $p->pending_drawers_since_compile,
            'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
        ])->values()->all();

        $this->line(json_encode([
            'candidates' => $candidates,
            'count' => count($candidates),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
