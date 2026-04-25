<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Collection;

class WikiLintTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'wiki:read';
    }

    private const VALID_FOCUS = ['stale', 'orphans', 'empty', 'low_confidence', 'all'];

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $focus = $params['focus'] ?? 'all';
        if (! in_array($focus, self::VALID_FOCUS, true)) {
            throw McpException::invalidParams(
                'Parameter "focus" must be one of: '.implode(', ', self::VALID_FOCUS)
            );
        }

        // Load all pages once and pass the collection to each detector
        $allPages = WikiPage::all();
        $findings = [];

        if ($focus === 'all' || $focus === 'stale') {
            $this->detectStalePages($findings, $allPages);
        }
        if ($focus === 'all' || $focus === 'orphans') {
            $this->detectOrphanPages($findings, $allPages);
        }
        if ($focus === 'all' || $focus === 'empty') {
            $this->detectEmptyPages($findings, $allPages);
        }
        if ($focus === 'all' || $focus === 'low_confidence') {
            $this->detectLowConfidence($findings, $allPages);
        }

        // Deferred lint detectors (require LLM calls, not yet implemented):
        // - Contradiction detection between wiki pages
        // - Missing concepts referenced in content but without their own page
        // - Missing cross-references (pages that discuss related topics but aren't linked)

        $warnings = count(array_filter($findings, fn ($f) => $f['severity'] === 'warning'));
        $info = count(array_filter($findings, fn ($f) => $f['severity'] === 'info'));

        $result = [
            'findings' => $findings,
            'summary' => [
                'total' => count($findings),
                'warnings' => $warnings,
                'info' => $info,
                'focus' => $focus,
            ],
        ];

        $this->logSession('wiki_lint', $apiKey, $params, count($findings));

        return $result;
    }

    /**
     * @param  Collection<int, WikiPage>  $allPages
     */
    private function detectStalePages(array &$findings, $allPages): void
    {
        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        // Pages with pending drawers
        $pendingPages = $allPages->filter(fn ($p) => $p->pending_drawers_since_compile > 0);
        foreach ($pendingPages as $page) {
            $findings[] = [
                'type' => 'stale',
                'severity' => 'warning',
                'page' => $page->name,
                'description' => "Page has {$page->pending_drawers_since_compile} new drawer(s) since last compile",
                'suggestion' => 'Re-compile this wiki page with recent drawer content',
            ];
        }

        // Pages with old compile dates (exclude those already flagged for pending)
        $pendingIds = $pendingPages->pluck('id')->all();

        $stalePages = $allPages->filter(function ($page) use ($staleThreshold, $pendingIds) {
            if (in_array($page->id, $pendingIds, true)) {
                return false;
            }

            return $page->last_compiled_at === null || $page->last_compiled_at->lt($staleThreshold);
        });

        foreach ($stalePages as $page) {
            $compiled = $page->last_compiled_at?->toIso8601String() ?? 'never';
            $findings[] = [
                'type' => 'stale',
                'severity' => 'warning',
                'page' => $page->name,
                'description' => "Page last compiled: {$compiled} (threshold: {$staleDays} days)",
                'suggestion' => 'Review and re-compile this wiki page',
            ];
        }
    }

    /**
     * @param  Collection<int, WikiPage>  $allPages
     */
    private function detectOrphanPages(array &$findings, $allPages): void
    {
        $excludedNames = ['wiki/index', 'wiki/log'];

        $filteredPages = $allPages->whereNotIn('name', $excludedNames);

        // Collect all page names referenced in any page's 'related' array
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
                    'description' => 'Page is not referenced by any other wiki page\'s related array',
                    'suggestion' => 'Add this page to related arrays of relevant pages, or review if still needed',
                ];
            }
        }
    }

    /**
     * @param  Collection<int, WikiPage>  $allPages
     */
    private function detectEmptyPages(array &$findings, $allPages): void
    {
        foreach ($allPages as $page) {
            $contentLength = mb_strlen(trim($page->content ?? ''));
            if ($contentLength < 50) {
                $findings[] = [
                    'type' => 'empty',
                    'severity' => 'warning',
                    'page' => $page->name,
                    'description' => "Page content is very short ({$contentLength} chars)",
                    'suggestion' => 'Add more content or remove this stub page',
                ];
            }
        }
    }

    /**
     * @param  Collection<int, WikiPage>  $allPages
     */
    private function detectLowConfidence(array &$findings, $allPages): void
    {
        $pages = $allPages->where('confidence', 'low');

        foreach ($pages as $page) {
            $findings[] = [
                'type' => 'low_confidence',
                'severity' => 'info',
                'page' => $page->name,
                'description' => 'Page has low confidence rating',
                'suggestion' => 'Gather more sources or review content accuracy',
            ];
        }
    }
}
