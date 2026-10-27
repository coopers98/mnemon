<?php

namespace Tests\Feature\Mcp;

use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * A hidden wing must not change what a project name resolves to for a token
 * that cannot see it: the answer has to equal the one given when the hidden
 * wing does not exist at all.
 */
class ProjectShadowingTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    /** Hidden project-foo versus visible project-bar aliased "foo"; and a shared alias. */
    public static function shadows(): array
    {
        return [
            'exact slug' => [
                ['slug' => 'project-foo', 'aliases' => null],
                ['slug' => 'project-bar', 'aliases' => ['foo']],
            ],
            'shared alias' => [
                ['slug' => 'project-aaa', 'aliases' => ['foo']],
                ['slug' => 'project-bar', 'aliases' => ['foo']],
            ],
        ];
    }

    private function observe(array $visible, ?array $hidden): array
    {
        Wing::query()->forceDelete();
        // Hidden first, so it also wins any "first match" ordering.
        if ($hidden !== null) {
            Wing::create(['name' => $hidden['slug']] + $hidden);
        }
        Wing::create(['name' => $visible['slug']] + $visible);
        $patterns = [$visible['slug']];

        $this->app['auth']->forgetGuards();
        $status = $this->mcpCall('brain_status', ['projects' => ['foo']], ['mcp:use'], $patterns)
            ->json('result.structuredContent.digest.projects.foo');

        $this->app['auth']->forgetGuards();
        $add = $this->mcpCall('drawer_add', ['project' => 'foo', 'room' => 'notes', 'content' => 'note'], ['mcp:use'], $patterns)
            ->json('result.structuredContent.wing');

        $captured = new \ArrayObject;
        $this->app->bind(SessionDigestService::class, function ($app) use ($captured) {
            $llm = new class($captured)
            {
                public function __construct(public \ArrayObject $captured) {}

                public function digest(string $t, array $c): array
                {
                    $this->captured['wing'] = $c['session_wing'];

                    return [];
                }
            };

            return new SessionDigestService($llm, $app->make(DrawerWriteService::class));
        });
        $this->app['auth']->forgetGuards();
        $this->mcpCall('session_digest', [
            'session_id' => 's'.uniqid(), 'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 1], 'transcript' => 'x', 'project' => 'foo',
        ], ['mcp:use'], $patterns)->assertOk();

        return [$status, $add, $captured['wing']];
    }

    #[DataProvider('shadows')]
    public function test_a_hidden_wing_does_not_shadow_a_visible_one(array $hidden, array $visible): void
    {
        $with = $this->observe($visible, $hidden);
        $without = $this->observe($visible, null);

        $this->assertSame($visible['slug'], $without[0], 'sanity: the visible wing resolves');
        $this->assertSame($without, $with);
    }
}
