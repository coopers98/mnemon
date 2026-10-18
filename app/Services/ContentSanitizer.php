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
        // PEM private keys. With an END line, everything from BEGIN to it --
        // Proc-Type/DEK-Info headers of an encrypted key, line breaks escaped
        // as a literal \n. Non-greedy and bounded, so a stray BEGIN cannot
        // run away; and never across a double quote, which a key never holds:
        // in a JSON transcript that is the end of the turn's string, and a
        // header named in one turn with an END line in a later one swallowed
        // every turn between.
        ['/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[^"]{0,20000}?-----END [A-Z0-9 ]*PRIVATE KEY-----/', '[REDACTED:PRIVATE_KEY]'],
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
        // Secret assignments and keys -- three rules, the same as the
        // plugin's cleaner. They redact a value that looks like a secret, not
        // every mention of one: a broader rule hit prose on every write path
        // ("token: the refresh flow", "**Token:** rotated hourly",
        // TOKEN_LIMIT=4096), 24 of the 26 texts it touched in a real corpus.
        //
        // A. An env or flag assignment: DB_PASSWORD=, password=,
        // --password=, PGPASSWORD=, MYSQL_PWD=, AWS_SECRET_ACCESS_KEY=,
        // SECRET_KEY_BASE=, GITHUB_TOKEN=. The name ends in the keyword (or
        // keyword_KEY, keyword_KEY_BASE) and starts a word or follows "_" or
        // the glued PG: NOSECRET=, TOKEN_LIMIT= and a bare PWD= are not
        // secrets. "=" takes no spaces around it unless a quote follows. The
        // value is 4+ characters and not a reference ($X, ${X}, <x>, %X%), a
        // boolean, null/none, a number or another "=" (PASSWORD==x is a
        // comparison). It stops at whitespace, a quote, a backslash or , } ]
        // -- so in a JSON transcript it never takes the closing quote, the
        // brackets after it or an escaped line break.
        ['/(?<![A-Za-z0-9])(?!(?i:pwd)=)((?:[A-Za-z0-9]+_)*(?:PG)?(?i:PASSWORD|PASSWD|PWD|SECRET|TOKEN|ACCESS_KEY|API_KEY|API_SECRET|AUTH_TOKEN|PRIVATE_KEY)(?i:_KEY(?:_BASE)?)?)(?:=|[ \t]*=[ \t]*(?=\\\\?["\']))((?:\\\\?["\'])?)(?![$<%{=]|\[REDACTED|(?i:true|false|null|none)\b|\d+\b)[^\s"\'\\\\,}\]:][^\s"\'\\\\]{3,}/', '$1=$2[REDACTED]'],
        // B. A quoted JSON key ending in the keyword ("password",
        // "db_password", "api-key", escaped or not): any quoted value that is
        // not a reference.
        ['/((?<![A-Za-z0-9])\\\\?"(?:[A-Za-z0-9]+[_-])*(?i:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key)\\\\?"[ \t]*:[ \t]*\\\\?")(?![$<%{,}\]]|\[REDACTED)[^"\\\\]+/', '$1[REDACTED]'],
        // C. An unquoted YAML or prose key ("token: ...", "Secret: ..."): only
        // an opaque value -- one token of 8+ characters on the same line,
        // mixing letters and digits.
        ['/((?<![A-Za-z0-9])(?:[A-Za-z0-9]+_)*(?i:password|passwd|secret|token|api[_-]?key)[ \t]*:[ \t]*)(?=[A-Za-z0-9+\/=._~!@#%^&*-]*\d)(?=[A-Za-z0-9+\/=._~!@#%^&*-]*[A-Za-z])(?![$<%{]|\[REDACTED)[A-Za-z0-9+\/=._~!@#%^&*-]{8,}(?=[\s"\',}\]\\\\]|$)/', '$1[REDACTED]'],
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
