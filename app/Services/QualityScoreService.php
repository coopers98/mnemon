<?php

namespace App\Services;

class QualityScoreService
{
    /**
     * Score content on a 0.0–1.0 scale using heuristics.
     *
     * Components:
     *  - Length score (0–0.4): longer = better, up to min_length
     *  - Structure score (0–0.3): headings/bullets present
     *  - Citation score (0–0.2): numeric refs or links
     *  - Vagueness penalty (-0.0 to -0.3): common filler phrases
     */
    public function score(string $content): float
    {
        $content = trim($content);
        $length = mb_strlen($content);

        $minLength = (int) config('mnemon.quality.min_length', 100);
        $vaguePhrases = (array) config('mnemon.quality.vague_phrases', []);

        // Length score: 0.0 → 0.4 scaling to minLength
        $lengthScore = min(0.4, ($length / max(1, $minLength)) * 0.4);

        // Structure: headings (##) or bullets (* / -)
        $hasHeadings = (bool) preg_match('/^#{1,6}\s/m', $content);
        $hasBullets = (bool) preg_match('/^[\*\-]\s/m', $content);
        $structureScore = 0.0;
        if ($hasHeadings) {
            $structureScore += 0.2;
        }
        if ($hasBullets) {
            $structureScore += 0.1;
        }

        // Citation score: markdown links [text](url) or numeric refs [1]
        $linkCount = preg_match_all('/\[.+?\]\(.+?\)/', $content);
        $numericRefCount = preg_match_all('/\[\d+\]/', $content);
        $citationScore = min(0.2, (($linkCount + $numericRefCount) / 3) * 0.2);

        // Vagueness penalty
        $lowerContent = mb_strtolower($content);
        $vagueCount = 0;
        foreach ($vaguePhrases as $phrase) {
            if (str_contains($lowerContent, $phrase)) {
                $vagueCount++;
            }
        }
        $vaguePenalty = min(0.3, $vagueCount * 0.05);

        $score = $lengthScore + $structureScore + $citationScore - $vaguePenalty;

        return round(max(0.0, min(1.0, $score)), 4);
    }
}
