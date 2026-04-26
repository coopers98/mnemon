<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\WikiPage;
use App\Services\WikiLintAutoFixer;
use Illuminate\Database\Eloquent\Collection;

class WikiLintTool extends BaseTool
{
    public function __construct(private readonly WikiLintAutoFixer $autoFixer) {}

    public function requiredScope(): string
    {
        return 'wiki:read';
    }

    private const VALID_FOCUS = ['stale', 'orphans', 'empty', 'low_confidence', 'low_quality', 'all'];

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $focus = $params['focus'] ?? 'all';
        if (! in_array($focus, self::VALID_FOCUS, true)) {
            throw McpException::invalidParams(
                'Parameter "focus" must be one of: '.implode(', ', self::VALID_FOCUS)
            );
        }

        $autoFix = (bool) ($params['auto_fix'] ?? false);
        if ($autoFix) {
            // Auto-fix mutates the wiki — escalate scope requirement
            $this->requireScope($apiKey, 'wiki:write');
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
        if ($focus === 'all' || $focus === 'low_quality') {
            $this->detectLowQuality($findings, $allPages);
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

        // Item 13: Self-Healing Lint — apply safe auto-fixes
        if ($autoFix) {
            $result['auto_fixes'] = $this->autoFixer->fix($findings, $apiKey);
        }

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
                'page_id' => $page->id,
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
                'page_id' => $page->id,
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
                    'page_id' => $page->id,
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
                    'page_id' => $page->id,
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
        // Use numeric confidence_score when available (threshold: < 0.3)
        foreach ($allPages as $page) {
            if ($page->confidence_score !== null && $page->confidence_score < 0.3) {
                $findings[] = [
                    'type' => 'low_confidence',
                    'severity' => 'warning',
                    'page' => $page->name,
                    'page_id' => $page->id,
                    'description' => sprintf(
                        'Page has low confidence score: %.2f (threshold: 0.30)',
                        $page->confidence_score
                    ),
                    'suggestion' => 'Gather more sources or re-compile to strengthen confidence',
                ];

                continue;
            }

            // Fallback to categorical confidence for pages without a numeric score
            if ($page->confidence === 'low') {
                $findings[] = [
                    'type' => 'low_confidence',
                    'severity' => 'info',
                    'page' => $page->name,
                    'page_id' => $page->id,
                    'description' => 'Page has low confidence rating',
                    'suggestion' => 'Gather more sources or review content accuracy',
                ];
            }
        }
    }

    /**
     * Item 12: Quality Scoring — flag pages with quality_score below threshold.
     *
     * @param  Collection<int, WikiPage>  $allPages
     */
    private function detectLowQuality(array &$findings, $allPages): void
    {
        $threshold = (float) config('mnemon.quality.low_threshold', 0.4);

        foreach ($allPages as $page) {
            if ($page->quality_score === null) {
                continue;
            }
            if ($page->quality_score >= $threshold) {
                continue;
            }

            $findings[] = [
                'type' => 'low_quality',
                'severity' => 'warning',
                'page' => $page->name,
                'page_id' => $page->id,
                'description' => sprintf(
                    'Page has low quality score: %.2f (threshold: %.2f)',
                    $page->quality_score,
                    $threshold
                ),
                'suggestion' => 'Add structure (headings, citations, examples) or re-write with more specifics',
            ];
        }
    }
}
