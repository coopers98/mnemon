<?php

namespace App\Services;

class RecallService
{
    public function __construct(
        protected DrawerSearchService $drawerSearch,
        protected WikiSearchService $wikiSearch,
    ) {}

    /**
     * @param  array<string>|null  $allowedWingPatterns  null = unrestricted
     */
    public function run(
        string $prompt,
        int $tokenBudget,
        ?string $wing,
        ?array $allowedWingPatterns,
    ): array {
        $floor = (float) config('mnemon.recall.confidence_floor', 0.45);

        $wikiHits = $this->wikiSearch->search($prompt, limit: 3);
        $drawerHits = $this->drawerSearch->run(
            query: $prompt,
            limit: 10,
            wing: $wing,
            allowedWingPatterns: $allowedWingPatterns,
        );

        $wiki = collect($wikiHits)
            ->map(fn ($w) => [
                'slug' => $w->name,
                'title' => $w->title,
                'content' => $w->content,
                'confidence' => (float) ($w->score ?? 0),
            ])
            ->filter(fn ($w) => $w['confidence'] >= $floor)
            ->sortByDesc('confidence')
            ->values()
            ->all();

        $drawers = collect($drawerHits)
            ->map(fn ($d) => [
                'id' => $d['id'],
                'wing' => $d['wing_slug'] ?? $d['wing'],
                'room' => $d['room_slug'] ?? $d['room'],
                'snippet' => mb_substr($d['content'], 0, 240),
                'confidence' => (float) ($d['score'] ?? 0),
            ])
            ->filter(fn ($d) => $d['confidence'] >= $floor)
            ->sortByDesc('confidence')
            ->values()
            ->all();

        if (empty($wiki) && empty($drawers)) {
            return [
                'found' => false,
                'summary' => 'No relevant context found.',
                'wiki' => [],
                'drawers' => [],
                'tokens_used' => 0,
            ];
        }

        $packed = $this->pack($wiki, $drawers, $tokenBudget);

        $count = count($packed['wiki']) + count($packed['drawers']);
        $summary = sprintf(
            'Found %d wiki page%s and %d drawer%s relevant to this prompt.',
            count($packed['wiki']),
            count($packed['wiki']) === 1 ? '' : 's',
            count($packed['drawers']),
            count($packed['drawers']) === 1 ? '' : 's',
        );

        return [
            'found' => $count > 0,
            'summary' => $summary,
            'wiki' => $packed['wiki'],
            'drawers' => $packed['drawers'],
            'tokens_used' => $packed['tokens_used'],
        ];
    }

    /**
     * Greedy-pack wiki then drawers into the token budget.
     * Rough proxy: 1 token ≈ 4 chars.
     */
    protected function pack(array $wiki, array $drawers, int $tokenBudget): array
    {
        $tokensUsed = 0;
        $packedWiki = [];
        $packedDrawers = [];

        foreach ($wiki as $w) {
            $excerpt = $w['content'];
            $excerptTokens = (int) ceil(mb_strlen($excerpt) / 4);

            if ($excerptTokens > 600) {
                $excerpt = mb_substr($excerpt, 0, 600 * 4)
                    ."\n\n…[truncated, full page at mnemon://wiki/{$w['slug']}]";
                $excerptTokens = 600;
            }

            if ($tokensUsed + $excerptTokens > $tokenBudget) {
                break;
            }

            $packedWiki[] = [
                'slug' => $w['slug'],
                'title' => $w['title'],
                'content' => $excerpt,
                'confidence' => $w['confidence'],
            ];
            $tokensUsed += $excerptTokens;
        }

        foreach ($drawers as $d) {
            $tokens = (int) ceil(mb_strlen($d['snippet']) / 4);
            if ($tokensUsed + $tokens > $tokenBudget) {
                break;
            }
            $packedDrawers[] = $d;
            $tokensUsed += $tokens;
        }

        return [
            'wiki' => $packedWiki,
            'drawers' => $packedDrawers,
            'tokens_used' => $tokensUsed,
        ];
    }
}
