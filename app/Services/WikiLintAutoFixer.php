<?php

namespace App\Services;

use App\Models\WikiLintAction;
use App\Models\WikiPage;

/**
 * Item 13: Self-Healing Lint — applies safe auto-fixes based on lint findings.
 *
 * Safe operations only:
 *  - Prune broken cross-references (related entries pointing at non-existent pages)
 *  - Soft-delete empty stub pages
 *  - Queue orphan pages for manual index review (recorded as an action; no destructive change)
 */
class WikiLintAutoFixer
{
    /**
     * Apply safe fixes for the supplied findings.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{applied: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function fix(array $findings): array
    {
        $applied = [];

        foreach ($findings as $finding) {
            $type = $finding['type'] ?? null;
            $pageName = $finding['page'] ?? null;
            if ($pageName === null) {
                continue;
            }

            $page = WikiPage::where('name', $pageName)->first();
            if ($page === null) {
                continue;
            }

            switch ($type) {
                case 'orphan':
                    $applied[] = $this->queueOrphanForReview($page);
                    break;
                case 'empty':
                    $action = $this->archiveEmptyPage($page);
                    if ($action !== null) {
                        $applied[] = $action;
                    }
                    break;
            }
        }

        // Pass 2: prune broken related references on every page (independent of findings)
        $prunedActions = $this->pruneBrokenRelatedRefs();
        foreach ($prunedActions as $action) {
            $applied[] = $action;
        }

        $summary = [
            'queued_orphans' => count(array_filter($applied, fn ($a) => $a['action'] === 'queue_orphan')),
            'archived_empty' => count(array_filter($applied, fn ($a) => $a['action'] === 'archive_empty')),
            'pruned_related' => count(array_filter($applied, fn ($a) => $a['action'] === 'prune_related')),
            'total' => count($applied),
        ];

        return [
            'applied' => $applied,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueOrphanForReview(WikiPage $page): array
    {
        WikiLintAction::create([
            'page_id' => $page->id,
            'action' => 'queue_orphan',
            'before' => $page->name,
            'after' => 'queued for index.md review',
            'performed_at' => now(),
        ]);

        return [
            'action' => 'queue_orphan',
            'page' => $page->name,
            'page_id' => $page->id,
            'note' => 'Added to manual index.md review queue',
        ];
    }

    /**
     * Soft-delete an empty page (skip wiki/index, wiki/log).
     *
     * @return array<string, mixed>|null
     */
    private function archiveEmptyPage(WikiPage $page): ?array
    {
        if (in_array($page->name, ['wiki/index', 'wiki/log'], true)) {
            return null;
        }

        $beforeContent = $page->content ?? '';

        WikiLintAction::create([
            'page_id' => $page->id,
            'action' => 'archive_empty',
            'before' => $beforeContent,
            'after' => '[archived stub]',
            'performed_at' => now(),
        ]);

        // SoftDeletes trait on WikiPage — this sets deleted_at instead of hard-deleting
        $page->delete();

        return [
            'action' => 'archive_empty',
            'page' => $page->name,
            'page_id' => $page->id,
            'note' => 'Empty stub page soft-deleted',
        ];
    }

    /**
     * Walk every page's `related` array and drop entries that don't resolve to a real page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pruneBrokenRelatedRefs(): array
    {
        $existingNames = WikiPage::pluck('name')->all();
        $existingSet = array_flip($existingNames);

        $applied = [];
        $pages = WikiPage::query()->whereNotNull('related')->get();

        foreach ($pages as $page) {
            $related = $page->related ?? [];
            if (! is_array($related) || empty($related)) {
                continue;
            }

            $kept = [];
            $removed = [];
            foreach ($related as $name) {
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                if (isset($existingSet[$name])) {
                    $kept[] = $name;
                } else {
                    $removed[] = $name;
                }
            }

            if (empty($removed)) {
                continue;
            }

            $page->update(['related' => array_values($kept)]);

            WikiLintAction::create([
                'page_id' => $page->id,
                'action' => 'prune_related',
                'before' => implode(', ', $related),
                'after' => implode(', ', $kept),
                'performed_at' => now(),
            ]);

            $applied[] = [
                'action' => 'prune_related',
                'page' => $page->name,
                'page_id' => $page->id,
                'removed' => $removed,
                'kept' => $kept,
            ];
        }

        return $applied;
    }
}
