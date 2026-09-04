<?php

namespace App\Services;

class ContentSanitizer
{
    /**
     * Patterns to detect and redact sensitive data.
     * Each entry: [pattern, replacement description].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PATTERNS = [
        // OpenAI-style API keys (sk-..., sk-proj-..., sk-svcacct-...).
        // The class has to include - and _ : the old pattern stopped at the
        // hyphen in "sk-proj-" and so never matched the shape OpenAI has issued
        // for years. No trailing \b, because a key may end on a character that
        // is not a word boundary.
        ['/\bsk-[a-zA-Z0-9_-]{20,}/', '[REDACTED:API_KEY]'],
        // GitHub personal access tokens (ghp_...)
        ['/\bghp_[a-zA-Z0-9]+\b/', '[REDACTED:GITHUB_TOKEN]'],
        // GitHub OAuth tokens (gho_...)
        ['/\bgho_[a-zA-Z0-9]+\b/', '[REDACTED:GITHUB_TOKEN]'],
        // Aiven-style tokens (AVNS_...)
        ['/\bAVNS_[a-zA-Z0-9]+\b/', '[REDACTED:API_KEY]'],
        // Bearer tokens (long ones only — 20+ chars to avoid false positives)
        ['/\bBearer\s+[a-zA-Z0-9._-]{20,}\b/', '[REDACTED:BEARER_TOKEN]'],
        // Env-style password/secret assignments (redact value only)
        // Uses lookbehind for word char or line start to match DB_PASSWORD, API_SECRET etc.
        ['/(?<=\b|_)(PASSWORD|SECRET|API_SECRET|API_KEY|AUTH_TOKEN|PRIVATE_KEY)\s*=\s*\S+/', '$1=[REDACTED]'],
        // Credentials in URLs: user:pass@host
        ['/\/\/([a-zA-Z0-9._-]+):([a-zA-Z0-9._-]+)@/', '//[REDACTED:CREDENTIALS]@'],
    ];

    /**
     * Sanitize content by redacting sensitive patterns.
     */
    public function sanitize(string $content): string
    {
        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }
}
