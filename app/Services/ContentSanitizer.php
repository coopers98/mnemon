<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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
        // PEM private keys. With an END line, everything from BEGIN to it,
        // whatever lies between -- Proc-Type/DEK-Info headers of an encrypted
        // key, line breaks escaped as a literal \n. Non-greedy and bounded,
        // so a stray BEGIN cannot run away.
        ['/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]{0,20000}?-----END [A-Z0-9 ]*PRIVATE KEY-----/', '[REDACTED:PRIVATE_KEY]'],
        // Without one (a key cut off), from BEGIN on when a body follows: a
        // 20+ character base64 run, after any header lines, with real or
        // escaped (\n, \r\n) line breaks. A header named in prose has no body
        // and is left alone. It ends where the text does -- or where the
        // enclosing JSON string does, so a transcript stays parseable.
        ['/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----(?:(?:\s|\\\\[rn])*+(?:Proc-Type|DEK-Info):[^\r\n\\\\]*+)*+(?:\s|\\\\[rn])*+[A-Za-z0-9+\/=]{20,}(?:[^"\\\\]++|\\\\.)*+/', '[REDACTED:PRIVATE_KEY]'],
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
        // The name starts a word, or follows a prefix ending in "_"
        // (DB_PASSWORD): NOSECRET= is not a secret.
        ['/(?<![A-Za-z0-9])((?:[A-Za-z0-9]+_)*)(PASSWORD|SECRET|API_SECRET|API_KEY|AUTH_TOKEN|PRIVATE_KEY)\s*=\s*[^=\s]\S*/', '$1$2=[REDACTED]'],
        // Credentials in a URL: scheme://user:pass@. The scheme is what tells
        // admin:123456@ (a password, redacted) from a bare //host:8080@ or
        // //TODO:fix@ (not a URL credential, left alone).
        ['/\b([a-zA-Z][a-zA-Z0-9+.-]*):\/\/[^\s\/@:]+:[^\s\/@]+@/', '$1://[REDACTED:CREDENTIALS]@'],
    ];

    /**
     * Sanitize content by redacting sensitive patterns.
     */
    public function sanitize(string $content): string
    {
        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $next = preg_replace($pattern, $replacement, $content);

            // null is a PCRE failure, not "nothing matched". Assigning it
            // stored an empty string.
            if ($next === null) {
                Log::warning('mnemon: sanitizer pattern failed; keeping the text it was given', [
                    'pattern' => $pattern,
                    'error' => preg_last_error_msg(),
                ]);

                continue;
            }

            $content = $next;
        }

        return $content;
    }
}
