<?php

namespace Tests\Unit\Services;

use App\Services\ContentSanitizer;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sanitizer is the last thing between a session transcript and permanent
 * storage, and it had no test file of its own — its only coverage was a single
 * assertion inside DrawerAddToolTest, using a key shape that happened to match.
 */
class ContentSanitizerTest extends TestCase
{
    private ContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ContentSanitizer;
    }

    public function test_it_redacts_a_modern_openai_project_key(): void
    {
        // The pattern was sk-[a-zA-Z0-9]{20,}, which stops dead at the hyphen in
        // "sk-proj-" and so never matched the shape OpenAI has issued for years.
        // The one existing assertion used sk-abc123... with no hyphens, so it
        // asserted the buggy shape and the gap survived.
        $key = 'sk-proj-Ab3dEf6hIj9lMn2pQr5tUv8xYz1cD4fG7hJ0kL3nO6qR9sT2vW5y';

        $out = $this->sanitizer->sanitize("the env has {$key} in it");

        $this->assertStringNotContainsString($key, $out);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $out);
    }

    public function test_it_redacts_a_service_account_key(): void
    {
        $key = 'sk-svcacct-Ab3dEf6hIj9lMn2pQr5tUv8xYz1cD4fG7hJ0kL3n';

        $this->assertStringNotContainsString($key, $this->sanitizer->sanitize("key: {$key}"));
    }

    public function test_it_still_redacts_the_classic_key_shape(): void
    {
        $key = 'sk-abc123def456ghi789jkl012mno345pqr678stu901vwx234yz';

        $this->assertStringNotContainsString($key, $this->sanitizer->sanitize("key {$key}"));
    }

    public function test_it_leaves_ordinary_hyphenated_text_alone(): void
    {
        // Broadening the character class must not start eating prose.
        $text = 'the sk-learn approach and a some-long-hyphenated-identifier-here';

        $this->assertSame($text, $this->sanitizer->sanitize($text));
    }

    public function test_it_redacts_github_and_bearer_tokens(): void
    {
        $out = $this->sanitizer->sanitize(
            'ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa and Bearer eyJhbGciOiJSUzI1NiJ9.abcdefghij'
        );

        $this->assertStringNotContainsString('ghp_aaaa', $out);
        $this->assertStringNotContainsString('eyJhbGciOiJSUzI1NiJ9', $out);
    }

    public function test_it_redacts_credentials_embedded_in_a_url(): void
    {
        $out = $this->sanitizer->sanitize('https://user:hunter2@example.com/repo.git');

        $this->assertStringNotContainsString('hunter2', $out);
    }

    public function test_it_redacts_env_style_assignments_without_eating_the_key_name(): void
    {
        $out = $this->sanitizer->sanitize('DB_PASSWORD=s3cr3tvalue');

        $this->assertStringNotContainsString('s3cr3tvalue', $out);
        $this->assertStringContainsString('PASSWORD=[REDACTED]', $out);
    }

    /**
     * The client cleaner learned these; the server set must agree with it, or
     * text the plugin leaves alone is mangled here anyway.
     */
    public function test_it_leaves_lookalikes_alone(): void
    {
        foreach ([
            'the prefix ghp_short is documented',
            'github_pat_short is a prefix',
            'slack xoxb-1, not a token',
            'assert PASSWORD==expected',
            'connect to //host:8080@x',
        ] as $text) {
            $this->assertSame($text, $this->sanitizer->sanitize($text), $text);
        }
    }

    public function test_it_redacts_what_the_client_redacts(): void
    {
        $secrets = [
            'AKIAABCDEFGHIJKLMNOP',
            'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXNlY3JldA',
            'ghs_'.str_repeat('b', 36),
            'github_pat_11'.str_repeat('C', 80),
            'xoxb-1234-SECRETxoxb',
            "-----BEGIN RSA PRIVATE KEY-----\nSECRETpem".str_repeat('Ab9+', 16)."\nTail==\n-----END RSA PRIVATE KEY-----",
        ];

        foreach ($secrets as $secret) {
            $out = $this->sanitizer->sanitize("before {$secret} after");
            $this->assertStringNotContainsString($secret, $out);
            $this->assertStringStartsWith('before ', $out);
            $this->assertStringEndsWith(' after', $out);
        }
    }

    public function test_a_note_that_mentions_a_pem_header_is_left_alone(): void
    {
        $text = 'Paste the key that starts with -----BEGIN RSA PRIVATE KEY----- into the vault, not here.';

        $this->assertSame($text, $this->sanitizer->sanitize($text));
    }

    public function test_a_pem_key_with_a_body_is_redacted_whole(): void
    {
        $body = implode("\n", [str_repeat('MIIEow', 10).'AbCd', str_repeat('Qx9+', 16), 'shortTail==']);
        $out = $this->sanitizer->sanitize("before\n-----BEGIN RSA PRIVATE KEY-----\n{$body}\n-----END RSA PRIVATE KEY-----\nafter");

        $this->assertStringNotContainsString('MIIEow', $out);
        $this->assertStringNotContainsString('shortTail', $out);
        $this->assertStringNotContainsString('END RSA', $out);
        $this->assertStringStartsWith('before', $out);
        $this->assertStringEndsWith('after', $out);
    }

    public function test_a_digit_only_password_in_a_url_is_redacted_but_a_port_is_not(): void
    {
        $this->assertStringNotContainsString('123456', $this->sanitizer->sanitize('mysql://admin:123456@db.internal/app'));
        $this->assertSame('connect to //host:8080@x', $this->sanitizer->sanitize('connect to //host:8080@x'));
        $this->assertSame('see //TODO:fix@later', $this->sanitizer->sanitize('see //TODO:fix@later'));
    }

    public function test_a_key_name_inside_a_longer_word_is_left_alone(): void
    {
        $this->assertSame('NOSECRET=visible', $this->sanitizer->sanitize('NOSECRET=visible'));
        $this->assertStringContainsString('DB_PASSWORD=[REDACTED]', $this->sanitizer->sanitize('DB_PASSWORD=hunter2'));
    }

    /**
     * Common shapes a secret takes in a shell session or a config file. Each
     * passed both sanitizers: the name list was short, a suffix after the
     * keyword (SECRET_KEY_BASE) or a prefix glued to it (PGPASSWORD) did not
     * match, the match was case-sensitive, and a JSON or YAML key was never
     * looked at.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secretAssignments(): array
    {
        return [
            'export AWS_SECRET_ACCESS_KEY' => ['export AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYzzSECRET1', 'wJalrXUtnFEMI'],
            'PGPASSWORD' => ['PGPASSWORD=pgS3cretValue psql -h db', 'pgS3cretValue'],
            'MYSQL_PWD' => ['MYSQL_PWD=myS3cretValue mysql', 'myS3cretValue'],
            'SECRET_KEY_BASE' => ['SECRET_KEY_BASE=a1b2c3d4e5f6railsSecret', 'a1b2c3d4e5f6railsSecret'],
            'GITHUB_TOKEN, not ghp_' => ['GITHUB_TOKEN=v1.0123456789abcdefInstallTok', 'v1.0123456789abcdefInstallTok'],
            'lowercase password=' => ['password=lowerS3cret', 'lowerS3cret'],
            '--password=' => ['mysqldump --password=dashS3cret db', 'dashS3cret'],
            'quoted value' => ['DB_PASSWORD="quotedS3cret"', 'quotedS3cret'],
            'JSON password' => ['{"password": "jsonS3cret", "user": "x"}', 'jsonS3cret'],
            'JSON api_key' => ['{"api_key":"jsonKeyS3cret"}', 'jsonKeyS3cret'],
            'YAML token' => ["auth:\n  token: yamlTokS3cret\n", 'yamlTokS3cret'],
            'YAML Secret' => ['Secret: yamlS3cret', 'yamlS3cret'],
            'JSON-escaped in a transcript' => ['{"text":"set {\\"passwd\\": \\"escS3cret\\"} now"}', 'escS3cret'],
        ];
    }

    #[DataProvider('secretAssignments')]
    public function test_a_secret_assignment_is_redacted(string $text, string $secret): void
    {
        $out = $this->sanitizer->sanitize("before {$text} after");

        $this->assertStringNotContainsString($secret, $out);
        $this->assertStringContainsString('[REDACTED', $out);
        $this->assertStringStartsWith('before ', $out);
        $this->assertStringEndsWith(' after', $out);
    }

    public function test_a_redacted_value_in_a_json_transcript_does_not_swallow_what_follows(): void
    {
        // The value ran to the next whitespace, so in a JSON transcript it
        // took the closing quote and the next record's fields with it.
        $json = json_encode([['role' => 'user', 'text' => 'DB_PASSWORD=hunter2'], ['role' => 'assistant', 'text' => 'NEXT-TURN kept']]);

        $out = $this->sanitizer->sanitize($json);

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertNotNull(json_decode($out), 'the transcript stays parseable');
        $this->assertStringContainsString('NEXT-TURN kept', $out);
    }

    /**
     * The key-value rule redacted prose on every write path: 24 of 26 texts it
     * hit in a real corpus held no secret. Assignments now need an opaque
     * value, and an unquoted "token:" needs one that mixes letters and digits.
     *
     * @return array<string, array{0: string}>
     */
    public static function proseThatIsNotASecret(): array
    {
        $cases = [
            'token: the refresh flow works like this', 'Secret: keep it out of git', '**Token:** rotated hourly',
            'Password: must be 12 chars', 'the api_key: is required', 'sort by token: asc',
            'next_page_token: none', 'TOKEN_LIMIT=4096', 'secret=true', 'access_token=$ACCESS_TOKEN',
            'token=${GITHUB_TOKEN}', 'Token = refresh.token', 'MNEMON_TOKEN= (unset)', 'max_tokens=4096',
            'MAX_TOKENS=8000', 'tokens: 1234 used', 'NOTAPASSWORD=visible',
        ];

        return array_combine($cases, array_map(fn ($c) => [$c], $cases));
    }

    #[DataProvider('proseThatIsNotASecret')]
    public function test_prose_that_names_a_secret_is_left_alone(string $text): void
    {
        $this->assertSame($text, $this->sanitizer->sanitize($text));
    }

    /**
     * An assignment with no value used to take the JSON around it as its
     * value: the closing quote and brackets, or an escaped line break.
     *
     * @return array<string, array{0: string}>
     */
    public static function valuelessAssignmentsInJson(): array
    {
        return [
            'at the end of the transcript' => ['[{"text":"set DB_PASSWORD="}]'],
            'at the end of a turn' => ['[{"role":"user","text":"export GITHUB_TOKEN="},{"role":"assistant","text":"ok"}]'],
            'before an escaped line break' => ['[{"text":"put DB_PASSWORD=\\nthen"}]'],
            'a key before an escaped line break' => ['[{"text":"Secret:\\nnext"}]'],
            'an empty quoted value' => ['[{"text":"DB_PASSWORD=\\"\\""}]'],
        ];
    }

    #[DataProvider('valuelessAssignmentsInJson')]
    public function test_a_valueless_assignment_leaves_a_json_transcript_intact(string $json): void
    {
        $this->assertNotNull(json_decode($json), 'fixture is JSON');

        $this->assertSame($json, $this->sanitizer->sanitize($json));
    }

    public function test_more_lookalikes_are_left_alone(): void
    {
        foreach ([
            'NOSECRET=visible',
            'PWD=/root/projects/mnemon',
            'OLDPWD=/tmp',
            'max_tokens: 4096',
            '"token_type": "Bearer"',
            'see //TODO:fix@later',
            'assert PASSWORD==expected',
            'connect to //host:8080@x',
        ] as $text) {
            $this->assertSame($text, $this->sanitizer->sanitize($text), $text);
        }
    }

    /**
     * preg_replace returns null on a PCRE failure. Assigning that back stored
     * an empty drawer.
     */
    public function test_a_regex_failure_keeps_the_text_rather_than_emptying_it(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        // The logger's own formatter runs a regex too, which this limit
        // would break; what matters here is that the failure is reported.
        Log::shouldReceive('warning')->atLeast()->once();

        try {
            $out = $this->sanitizer->sanitize('some text with sk-'.str_repeat('a', 40).' in it');
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertNotSame('', $out);
    }

    private const BODY_LINE = 'MIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun';

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateKeys(): array
    {
        $line = self::BODY_LINE;

        return [
            'ordinary PEM' => ["-----BEGIN RSA PRIVATE KEY-----\n{$line}\n{$line}\nVGFpbA==\n-----END RSA PRIVATE KEY-----"],
            'encrypted PEM with headers' => ["-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789ABCDEF0123456789ABCDEF\n\n{$line}\nVGFpbA==\n-----END RSA PRIVATE KEY-----"],
            'JSON-escaped with literal \n' => ['-----BEGIN OPENSSH PRIVATE KEY-----\n'.$line.'\n'.$line.'\n-----END OPENSSH PRIVATE KEY-----'],
            'JSON-escaped, no END' => ['-----BEGIN OPENSSH PRIVATE KEY-----\r\n'.$line.'\r\n'.$line],
            'truncated, no END' => ["-----BEGIN EC PRIVATE KEY-----\n{$line}\n{$line}"],
            'encrypted, truncated' => ["-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789ABCDEF\n\n{$line}"],
        ];
    }

    #[DataProvider('privateKeys')]
    public function test_a_private_key_is_redacted_in_every_shape(string $key): void
    {
        $out = $this->sanitizer->sanitize("BEFORE-KEY {$key}".(str_contains($key, '-----END') ? ' AFTER-KEY' : ''));

        $this->assertStringNotContainsString('MIIEowIBAAKCAQEA', $out);
        $this->assertStringNotContainsString('VGFpbA', $out);
        $this->assertStringNotContainsString('DEK-Info', $out);
        $this->assertStringStartsWith('BEFORE-KEY ', $out);
        if (str_contains($key, '-----END')) {
            $this->assertStringEndsWith(' AFTER-KEY', $out);
        }
    }

    /**
     * A JSON transcript: a key header named in one turn and an END line in a
     * later one must not be read as one key, swallowing the turns between.
     */
    public function test_a_key_does_not_span_turns_of_a_json_transcript(): void
    {
        $json = json_encode([
            ['role' => 'user', 'text' => 'the -----BEGIN RSA PRIVATE KEY----- header goes first'],
            ['role' => 'assistant', 'text' => 'MIDDLE-TURN-KEPT'],
            ['role' => 'user', 'text' => 'and -----END RSA PRIVATE KEY----- goes last'],
        ]);

        $out = $this->sanitizer->sanitize($json);

        $this->assertStringContainsString('MIDDLE-TURN-KEPT', $out);
        $this->assertNotNull(json_decode($out), 'the transcript stays parseable');
    }

    public function test_a_key_inside_one_json_turn_is_still_redacted(): void
    {
        $key = "-----BEGIN RSA PRIVATE KEY-----\n".self::BODY_LINE."\n".self::BODY_LINE."\n-----END RSA PRIVATE KEY-----";
        $json = json_encode([['role' => 'user', 'text' => "before {$key} after"], ['role' => 'assistant', 'text' => 'NEXT']]);

        $out = $this->sanitizer->sanitize($json);

        $this->assertStringNotContainsString('MIIEowIBAAKCAQEA', $out);
        $this->assertStringContainsString('after', $out);
        $this->assertStringContainsString('NEXT', $out);
        $this->assertNotNull(json_decode($out));
    }

    public function test_a_megabyte_after_a_begin_line_finishes_quickly_and_is_kept(): void
    {
        $prose = '-----BEGIN RSA PRIVATE KEY----- '.str_repeat('just some words ', 65536);

        $start = microtime(true);
        $out = $this->sanitizer->sanitize($prose);

        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertSame($prose, $out);
    }
}
