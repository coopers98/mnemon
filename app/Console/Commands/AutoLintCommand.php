<?php

namespace App\Console\Commands;

use App\Models\WikiPage;
use Illuminate\Console\Command;

class AutoLintCommand extends Command
{
    protected $signature = 'mnemon:auto-lint';

    protected $description = 'Run wiki lint and output findings as JSON (for cron/automation)';

    public function handle(): int
    {
        $allPages = WikiPage::all();
        $findings = [];

        $this->detectStalePages($findings, $allPages);
        $this->detectOrphanPages($findings, $allPages);
        $this->detectEmptyPages($findings, $allPages);
        $this->detectLowConfidence($findings, $allPages);

        $this->line(json_encode([
            'findings' => $findings,
            'summary' => [
                'total' => count($findings),
                'warnings' => count(array_filter($findings, fn ($f) => $f['severity'] === 'warning')),
                'info' => count(array_filter($findings, fn ($f) => $f['severity'] === 'info')),
            ],
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function detectStalePages(array &$findings, $allPages): void
    {
        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        $pendingPages = $allPages->filter(fn ($p) => $p->pending_drawers_since_compile > 0);
        foreach ($pendingPages as $page) {
            $findings[] = [
                'type' => 'stale',
                'severity' => 'warning',
                'page' => $page->name,
                'description' => "Has {$page->pending_drawers_since_compile} new drawer(s) since last compile",
            ];
        }

        $pendingIds = $pendingPages->pluck('id')->all();
        $stalePages = $allPages->filter(function ($page) use ($staleThreshold, $pendingIds) {
            if (in_array($page->id, $pendingIds, true)) {
                return false;
            }

            return $page->last_compiled_at === null || $page->last_compiled_at->lt($staleThreshold);
        });

        foreach ($stalePages as $page) {
            $findings[] = [
                'type' => 'stale',
                'severity' => 'warning',
                'page' => $page->name,
                'description' => 'Last compiled: '.($page->last_compiled_at?->toIso8601String() ?? 'never'),
            ];
        }
    }

    private function detectOrphanPages(array &$findings, $allPages): void
    {
        $excludedNames = ['wiki/index', 'wiki/log'];
        $filteredPages = $allPages->whereNotIn('name', $excludedNames);

        $referencedNames = collect();
        foreach ($filteredPages as $page) {
            if (! empty($page->related)) {
                $referencedNames = $referencedNames->merge($page->related);
            }
        }
        $referencedNames = $referencedNames->unique();

        foreach ($filteredPages as $page) {
            if (! $referencedNames->contains($page->name)) {
                $findings[] = [
                    'type' => 'orphan',
                    'severity' => 'info',
                    'page' => $page->name,
                    'description' => 'Not referenced by any other wiki page',
                ];
            }
        }
    }

    private function detectEmptyPages(array &$findings, $allPages): void
    {
        foreach ($allPages as $page) {
            $contentLength = mb_strlen(trim($page->content ?? ''));
            if ($contentLength < 50) {
                $findings[] = [
                    'type' => 'empty',
                    'severity' => 'warning',
                    'page' => $page->name,
                    'description' => "Content is very short ({$contentLength} chars)",
                ];
            }
        }
    }

    private function detectLowConfidence(array &$findings, $allPages): void
    {
        foreach ($allPages as $page) {
            if ($page->confidence_score !== null && $page->confidence_score < 0.3) {
                $findings[] = [
                    'type' => 'low_confidence',
                    'severity' => 'warning',
                    'page' => $page->name,
                    'description' => sprintf('Confidence score: %.2f', $page->confidence_score),
                ];
            } elseif ($page->confidence === 'low') {
                $findings[] = [
                    'type' => 'low_confidence',
                    'severity' => 'info',
                    'page' => $page->name,
                    'description' => 'Low confidence rating',
                ];
            }
        }
    }
}
