<?php

namespace Tests\Unit\Services;

use App\Services\ContentSanitizer;
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
            "-----BEGIN RSA PRIVATE KEY-----\nSECRETpem\n-----END RSA PRIVATE KEY-----",
        ];

        foreach ($secrets as $secret) {
            $out = $this->sanitizer->sanitize("before {$secret} after");
            $this->assertStringNotContainsString($secret, $out);
            $this->assertStringStartsWith('before ', $out);
            $this->assertStringEndsWith(' after', $out);
        }
    }
}
