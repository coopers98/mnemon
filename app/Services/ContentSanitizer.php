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
        // The same set, with the same minimum lengths, as the plugin's
        // transcript cleaner (mnemon_clean_transcript), so text the client
        // leaves alone -- a token prefix named in prose, a comparison, a
        // host:port -- is not mangled here either.
        //
        // PEM private keys, through the END line or, cut off, to the end.
        ['/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\z)/s', '[REDACTED:PRIVATE_KEY]'],
        // AWS access key ids.
        ['/AKIA[0-9A-Z]{16}/', '[REDACTED:AWS_KEY]'],
        // JWTs.
        ['/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/', '[REDACTED:JWT]'],
        // Bearer tokens (long ones only — 20+ chars to avoid false positives)
        ['/\bBearer\s+[A-Za-z0-9._~+\/=-]{20,}/', '[REDACTED:BEARER_TOKEN]'],
        // OpenAI-style API keys (sk-..., sk-proj-..., sk-svcacct-...).
        // The class has to include - and _ : the old pattern stopped at the
        // hyphen in "sk-proj-" and so never matched the shape OpenAI has issued
        // for years. No trailing \b, because a key may end on a character that
        // is not a word boundary.
        ['/\bsk-[a-zA-Z0-9_-]{20,}/', '[REDACTED:API_KEY]'],
        // GitHub fine-grained tokens, then classic/OAuth/app tokens (36+ chars).
        ['/\bgithub_pat_[A-Za-z0-9_]{60,}/', '[REDACTED:GITHUB_TOKEN]'],
        ['/\bgh[pousr]_[A-Za-z0-9]{36,}/', '[REDACTED:GITHUB_TOKEN]'],
        // Slack tokens.
        ['/\bxox[abprs]-[A-Za-z0-9-]{10,}/', '[REDACTED:SLACK_TOKEN]'],
        // Aiven-style tokens (AVNS_...)
        ['/\bAVNS_[a-zA-Z0-9]+/', '[REDACTED:API_KEY]'],
        // Env-style password/secret assignments (redact value only). A value
        // is required: PASSWORD==expected is a comparison.
        ['/(PASSWORD|SECRET|API_SECRET|API_KEY|AUTH_TOKEN|PRIVATE_KEY)\s*=\s*[^=\s]\S*/', '$1=[REDACTED]'],
        // Credentials in URLs: user:pass@host. A "password" of digits only
        // is a port.
        ['/\/\/[^\s\/@:]+:(?=[^\s\/@]*[^\s\/@0-9])[^\s\/@]+@/', '//[REDACTED:CREDENTIALS]@'],
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
