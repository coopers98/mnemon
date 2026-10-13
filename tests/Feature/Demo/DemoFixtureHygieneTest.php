<?php

namespace Tests\Feature\Demo;

use App\Services\ContentSanitizer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DemoFixtureHygieneTest extends TestCase
{
    /** Real people, hosts, projects and paths that must never appear in the demo. */
    private const DENY = [
        '/\bcooper\b/i', '/cooperhosting/i', '/coopers98/i', '/atlas-abs/i', '/samwise/i',
        '/ananke/i', '/openclaw/i', '#/root/#', '#/home/#', '/sellers/i',
    ];

    public function test_fixtures_hold_nothing_real_or_secret(): void
    {
        $files = File::allFiles(database_path('demo'));
        $this->assertNotEmpty($files);
        $sanitizer = app(ContentSanitizer::class);

        foreach ($files as $file) {
            $text = $file->getContents();
            $this->assertSame($text, $sanitizer->sanitize($text), "{$file->getRelativePathname()} holds something token-shaped");
            foreach (self::DENY as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $text, "{$file->getRelativePathname()} matches {$pattern}");
            }
            preg_match_all('/[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})/i', $text, $m);
            foreach ($m[1] as $domain) {
                $this->assertSame('example.com', strtolower($domain), "{$file->getRelativePathname()} has a non-example email");
            }
        }
    }
}
