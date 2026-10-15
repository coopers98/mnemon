<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * The wing-restriction invariant, checked across every MCP surface at once.
 *
 * A token restricted to `work` must not, through any tool, resource or prompt:
 * read another wing's content or metadata, write to another wing, or learn that
 * another wing's item exists. Everything belonging to the hidden wing carries a
 * marker -- "personal", "PSECRET" or the room slug "diary" -- so a single regex
 * over the raw JSON response catches a leak wherever it surfaces.
 *
 * Rows that name a hidden item compare the response with the one for an item
 * that does not exist at all: unreadable must be indistinguishable from missing.
 */
class WingIsolationTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    private const RESTRICTED = ['work', 'work-*'];

    private const LEAK = '/personal|psecret|diary/i';

    private Drawer $workDrawer;

    private Drawer $personalDrawer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);

        $work = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $workRoom = Room::create(['wing_id' => $work->id, 'name' => 'Notes', 'slug' => 'notes']);
        $this->workDrawer = Drawer::create(['content' => 'alpha work drawer body', 'room_id' => $workRoom->id]);

        $personal = Wing::create(['name' => 'Personal', 'slug' => 'personal']);
        $diary = Room::create(['wing_id' => $personal->id, 'name' => 'Diary', 'slug' => 'diary']);
        $this->personalDrawer = Drawer::create(['content' => 'alpha PSECRET drawer body', 'room_id' => $diary->id]);

        // Readable pages. `work` points at hidden things: a related page and a
        // source drawer, as an unrestricted writer is free to record.
        WikiPage::create([
            'name' => 'work',
            'type' => 'synthesis',
            'content' => 'alpha synthesis of the work wing, long enough not to be a stub page at all.',
            'related' => ['personal', 'work:thing'],
            'sources' => [$this->workDrawer->id, $this->personalDrawer->id],
            'source_count' => 2,
            'last_compiled_at' => now(),
        ]);
        WikiPage::create([
            'name' => 'work:thing',
            'type' => 'synthesis',
            'content' => 'alpha a work thing, long enough not to be a stub page in any lint run.',
            'related' => ['work'],
            'last_compiled_at' => now(),
        ]);

        // Hidden pages.
        WikiPage::create([
            'name' => 'personal',
            'type' => 'synthesis',
            'content' => 'alpha PSECRET page body that a work token must never read in any form.',
            'revision_count' => 3,
            'last_compiled_at' => now()->subYear(),
            'confidence_score' => 0.1,
        ]);
        WikiPage::create([
            'name' => 'personal:journal',
            'type' => 'synthesis',
            'content' => 'alpha PSECRET journal with pending drawers waiting for a compile run.',
            'pending_drawers_since_compile' => 3,
            'related' => ['personal:gone'],
        ]);
        WikiPage::create(['name' => 'personal:stub', 'type' => 'synthesis', 'content' => 'x']);
        WikiPage::create(['name' => 'personal:trashed', 'type' => 'synthesis', 'content' => 'PSECRET trashed'])->delete();
        WikiPage::create(['name' => 'wiki:index', 'type' => 'synthesis', 'content' => '- `personal` - `work`']);

        EntityRelationship::create(['from_page' => 'work', 'to_page' => 'personal', 'edge_type' => 'references']);
        EntityRelationship::create(['from_page' => 'work', 'to_page' => 'work:thing', 'edge_type' => 'references']);
    }

    // ---------------------------------------------------------------- reads

    /**
     * Calls that do not name a hidden item. Nothing of the hidden wing may
     * appear anywhere in the response.
     */
    public static function readRows(): array
    {
        return [
            'drawer_search' => ['tool', 'drawer_search', ['query' => 'alpha']],
            'drawer_get own' => ['tool', 'drawer_get', ['id' => '{work_drawer}']],
            'brain_status' => ['tool', 'brain_status', []],
            'palace_wake_up' => ['tool', 'palace_wake_up', []],
            'recall' => ['tool', 'recall', ['prompt' => 'alpha PSECRET page body journal pending drawers']],
            'context_get own' => ['tool', 'context_get', ['name' => 'work']],
            'context_list' => ['tool', 'context_list', []],
            'wiki_lint' => ['tool', 'wiki_lint', []],
            'wiki_compile own' => ['tool', 'wiki_compile', ['name' => 'work']],
            'wiki_history own' => ['tool', 'wiki_history', ['name' => 'work']],
            'wiki_graph own' => ['tool', 'wiki_graph', ['start_page' => 'work']],
            'context_set own' => ['tool', 'context_set', ['name' => 'work:new', 'content' => 'a new work page']],
            'drawer_add own' => ['tool', 'drawer_add', ['wing' => 'work', 'room' => 'notes', 'content' => 'a new work note']],
            'resource drawer own' => ['resource', 'mnemon://drawer/{work_drawer}', []],
            'resource wiki own' => ['resource', 'mnemon://wiki/work', []],
            'resource wing own' => ['resource', 'mnemon://wing/work', []],
            'prompt synthesize_wing' => ['prompt', 'synthesize_wing', ['wing_slug' => 'work']],
            'prompt find_stale_wiki_pages' => ['prompt', 'find_stale_wiki_pages', []],
            'prompt drawer_to_wiki' => ['prompt', 'drawer_to_wiki', ['drawer_id' => '{work_drawer}']],
        ];
    }

    #[DataProvider('readRows')]
    public function test_restricted_token_reads_nothing_from_another_wing(string $kind, string $target, array $args): void
    {
        $res = $this->call_($kind, $target, $args, self::RESTRICTED);

        $res->assertOk();
        $this->assertDoesNotMatchRegularExpression(self::LEAK, $res->getContent());
    }

    /**
     * Calls that name a hidden item, paired with the same call naming an item
     * that does not exist. After masking the argument itself, the two
     * responses must be byte-identical.
     */
    public static function oracleRows(): array
    {
        return [
            'drawer_get' => ['tool', 'drawer_get', 'id', '{personal_drawer}', 999999, []],
            'context_get' => ['tool', 'context_get', 'name', 'personal', 'personal-ghost', []],
            'wiki_history' => ['tool', 'wiki_history', 'name', 'personal', 'personal-ghost', []],
            'wiki_graph' => ['tool', 'wiki_graph', 'start_page', 'personal', 'personal-ghost', []],
            'wiki_compile' => ['tool', 'wiki_compile', 'name', 'personal', 'personal-ghost', []],
            'drawer_search wing' => ['tool', 'drawer_search', 'wing', 'personal', 'personal-ghost', ['query' => 'alpha']],
            'recall wing' => ['tool', 'recall', 'wing', 'personal', 'personal-ghost', ['prompt' => 'alpha']],
            'context_set' => ['tool', 'context_set', 'name', 'personal', 'personal-ghost', ['content' => 'overwrite']],
            'context_set conflict' => ['tool', 'context_set', 'name', 'personal', 'personal-ghost', ['content' => 'x', 'expected_revision' => 1]],
            'context_set trashed' => ['tool', 'context_set', 'name', 'personal:trashed', 'personal:ghost', ['content' => 'x']],
            'drawer_add' => ['tool', 'drawer_add', 'wing', 'personal', 'personal-ghost', ['room' => 'diary', 'content' => 'x']],
            'resource drawer' => ['resource', 'mnemon://drawer/{arg}', null, '{personal_drawer}', 999999, []],
            'resource wiki' => ['resource', 'mnemon://wiki/{arg}', null, 'personal', 'personal-ghost', []],
            'resource wing' => ['resource', 'mnemon://wing/{arg}', null, 'personal', 'personal-ghost', []],
            'prompt synthesize_wing' => ['prompt', 'synthesize_wing', 'wing_slug', 'personal', 'personal-ghost', []],
        ];
    }

    #[DataProvider('oracleRows')]
    public function test_hidden_items_are_indistinguishable_from_missing_ones(
        string $kind, string $target, ?string $key, string|int $hidden, string|int $missing, array $extra,
    ): void {
        $before = $this->snapshot();

        $hidden = $this->resolve($hidden);
        $responses = [];

        foreach ([$hidden, $missing] as $value) {
            $args = $key === null ? $extra : $extra + [$key => $value];
            $uri = str_replace('{arg}', (string) $value, $target);
            $res = $this->call_($kind, $uri, $args, self::RESTRICTED);
            $res->assertOk();

            $body = $this->mask($res->getContent(), (string) $value);
            $this->assertDoesNotMatchRegularExpression(self::LEAK, $body);
            $responses[] = $body;
        }

        $this->assertSame($responses[0], $responses[1], 'a hidden item must look exactly like a missing one');
        $this->assertSame($before, $this->snapshot(), 'a refused call must not change another wing');
    }

    // --------------------------------------------------------------- writes

    public function test_wiki_lint_auto_fix_is_refused_for_a_restricted_token(): void
    {
        $before = $this->snapshot();

        $res = $this->mcpCall('wiki_lint', ['auto_fix' => true], ['mcp:use'], self::RESTRICTED);

        $res->assertOk();
        $this->assertTrue($res->json('result.isError') ?? false, 'auto_fix must be refused');
        $this->assertDoesNotMatchRegularExpression(self::LEAK, $res->getContent());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_wiki_lint_counts_only_readable_findings(): void
    {
        $res = $this->mcpCall('wiki_lint', [], ['mcp:use'], self::RESTRICTED);

        $findings = $res->json('result.structuredContent.findings');
        $this->assertSame(count($findings), $res->json('result.structuredContent.summary.total'));
    }

    public function test_brain_status_counts_only_readable_items(): void
    {
        // The hidden drawer is the newest, so an unscoped last_write would name its time.
        Drawer::whereKey($this->workDrawer->id)->update(['created_at' => now()->subDay()]);
        Drawer::whereKey($this->personalDrawer->id)->update(['created_at' => now()]);

        $res = $this->mcpCall('brain_status', [], ['mcp:use'], self::RESTRICTED);

        $status = $res->json('result.structuredContent');
        $this->assertSame(2, $status['wiki_pages'], 'work + work:thing');
        $this->assertSame(1, $status['wings']);
        $this->assertSame(1, $status['rooms']);
        $this->assertSame(1, $status['drawers']);
        $this->assertSame(['raw' => 1], $status['drawers_by_tier']);
        $this->assertSame(1, $status['embedding']['unembedded_drawers']);
        $this->assertSame($this->workDrawer->fresh()->created_at->toIso8601String(), $status['last_write']);
    }

    public function test_context_get_lists_only_readable_sources(): void
    {
        $res = $this->mcpCall('context_get', ['name' => 'work'], ['mcp:use'], self::RESTRICTED);

        $page = $res->json('result.structuredContent');
        $this->assertSame([$this->workDrawer->id], $page['sources']);
        $this->assertSame(1, $page['source_count'], 'a count of hidden sources is still a disclosure');
        $this->assertSame(['work:thing'], $page['related']);
    }

    // C1: a search limit or score normalisation that sees hidden rows turns the
    // shape of the result into an oracle on hidden content.
    public function test_drawer_search_limit_is_not_spent_on_hidden_drawers(): void
    {
        $res = $this->mcpCall('drawer_search', ['query' => 'alpha PSECRET', 'limit' => 1], ['mcp:use'], self::RESTRICTED);

        $this->assertSame([$this->workDrawer->id], array_column($res->json('result.structuredContent.results'), 'id'));
    }

    public function test_recall_drawer_leg_is_not_crowded_out_by_hidden_drawers(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Drawer::create(['content' => "alpha PSECRET drawer {$i}", 'room_id' => $this->personalDrawer->room_id]);
        }

        $res = $this->mcpCall('recall', ['prompt' => 'alpha PSECRET'], ['mcp:use'], self::RESTRICTED);

        $this->assertSame([$this->workDrawer->id], array_column($res->json('result.structuredContent.drawers'), 'id'));
    }

    public function test_recall_wiki_leg_is_not_crowded_out_by_hidden_pages(): void
    {
        for ($i = 0; $i < 30; $i++) {
            WikiPage::create(['name' => "personal:g{$i}", 'type' => 'synthesis', 'content' => 'gamma delta hidden']);
        }
        WikiPage::create(['name' => 'work:gamma', 'type' => 'synthesis', 'content' => 'gamma for the work wing']);

        $res = $this->mcpCall('recall', ['prompt' => 'gamma delta'], ['mcp:use'], self::RESTRICTED);

        $this->assertSame(['work:gamma'], array_column($res->json('result.structuredContent.wiki'), 'slug'));
    }

    // C3
    public function test_wiki_lint_orphans_ignore_references_from_hidden_pages(): void
    {
        WikiPage::create([
            'name' => 'work:lonely',
            'type' => 'synthesis',
            'content' => 'a work page that only a hidden page links to, long enough to not be a stub.',
        ]);
        WikiPage::where('name', 'personal')->first()->update(['related' => ['work:lonely']]);

        $res = $this->mcpCall('wiki_lint', ['focus' => 'orphans'], ['mcp:use'], self::RESTRICTED);

        $this->assertContains('work:lonely', array_column($res->json('result.structuredContent.findings'), 'page'));
    }

    public function test_drawer_add_files_a_kind_colon_wing_into_the_existing_wing(): void
    {
        $atlas = Wing::create(['name' => 'project:atlas', 'slug' => 'project-atlas']);
        $wings = Wing::count();

        $res = $this->mcpCall('drawer_add', ['wing' => 'project:atlas', 'room' => 'notes', 'content' => 'atlas note'], ['mcp:use'], ['project-atlas']);

        $this->assertFalse($res->json('result.isError') ?? false, $res->getContent());
        $this->assertSame($wings, Wing::count(), 'no new wing');
        $drawer = Drawer::find($res->json('result.structuredContent.id'));
        $this->assertSame($atlas->id, $drawer->room->wing_id);
    }

    // I1: `%` and `_` are wildcards to SQL LIKE but literal characters in a pattern.
    public function test_like_wildcards_in_a_pattern_match_nothing_extra(): void
    {
        $res = $this->mcpCall('palace_wake_up', [], ['mcp:use'], ['%']);

        $this->assertSame([], $res->json('result.structuredContent.recent_drawers'));
        $this->assertSame([], $res->json('result.structuredContent.active_wings'));
    }

    public function test_like_wildcards_do_not_widen_context_set_sources(): void
    {
        $res = $this->mcpCall('context_set', [
            'name' => 'work',
            'content' => 'x',
            'sources' => [$this->personalDrawer->id],
        ], ['mcp:use'], ['work', '%']);

        $this->assertTrue($res->json('result.isError') ?? false);
        $this->assertSame('raw', $this->personalDrawer->fresh()->tier);
    }

    public function test_drawer_add_slugifies_the_wing(): void
    {
        $res = $this->mcpCall('drawer_add', ['wing' => 'My_Notes%', 'room' => 'notes', 'content' => 'hello'], ['mcp:use']);

        $res->assertOk();
        $this->assertFalse($res->json('result.isError') ?? false, $res->getContent());
        $this->assertTrue(Wing::where('slug', 'my-notes')->exists());
        $this->assertFalse(Wing::where('slug', 'like', '%\_%')->orWhere('slug', 'like', '%\%%')->exists());
    }

    // C2 + I4
    public function test_session_digest_treats_a_hidden_wing_like_a_missing_one(): void
    {
        Wing::create(['name' => 'project:work', 'slug' => 'project-work']);
        $patterns = ['work', 'work-*', 'project-work'];
        $persisted = [];

        foreach (['personal', 'personal-ghost'] as $i => $slug) {
            $this->mockDigest([
                ['content' => "note {$i}", 'wing_slug' => $slug, 'room_slug' => 'notes', 'confidence' => 0.9],
            ], new \ArrayObject);

            $res = $this->mcpCall('session_digest', [
                'session_id' => "sess-c2-{$i}",
                'harness' => 'claude-code',
                'turn_range' => ['start' => 0, 'end' => 3],
                'transcript' => 'anything',
                'project' => 'work',
            ], ['mcp:use'], $patterns);

            $persisted[] = array_column($res->json('result.structuredContent.persisted'), 'wing');
        }

        $this->assertSame([['project-work'], ['project-work']], $persisted);
    }

    public function test_session_digest_files_a_kind_colon_wing_name_into_its_slug(): void
    {
        Wing::create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        $proposal = ['content' => 'a mnemon note', 'wing_slug' => 'project:mnemon', 'room_slug' => 'notes', 'confidence' => 0.9];

        $runs = [
            'restricted' => [['project-mnemon'], []],
            'unrestricted, model filing' => [null, ['project_filing' => 'model']],
        ];

        foreach ($runs as $label => [$patterns, $extra]) {
            $this->app['auth']->forgetGuards();
            $this->mockDigest([$proposal], new \ArrayObject);

            $res = $this->mcpCall('session_digest', [
                'session_id' => "sess-slug-{$label}",
                'harness' => 'claude-code',
                'turn_range' => ['start' => 0, 'end' => 3],
                'transcript' => 'anything',
            ] + $extra, ['mcp:use'], $patterns);

            $body = $res->json('result.structuredContent');
            $this->assertSame(['project-mnemon'], array_column($body['persisted'], 'wing'), $label);
            $this->assertSame(0, $body['dropped_outside_wings'], $label);
        }

        $this->assertSame(1, Wing::where('slug', 'like', 'project%')->count());
    }

    public function test_session_digest_reports_a_count_of_dropped_proposals(): void
    {
        $this->mockDigest([
            ['content' => 'one', 'wing_slug' => 'personal', 'room_slug' => 'diary', 'confidence' => 0.9],
            ['content' => 'two', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9],
        ], new \ArrayObject);

        $res = $this->mcpCall('session_digest', [
            'session_id' => 'sess-i4',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 3],
            'transcript' => 'anything',
        ], ['mcp:use'], self::RESTRICTED);

        $body = $res->json('result.structuredContent');
        $this->assertSame(1, $body['dropped_outside_wings']);
        $this->assertSame(['work'], array_column($body['persisted'], 'wing'));
    }

    public function test_session_digest_never_files_into_another_wing(): void
    {
        $captured = new \ArrayObject;
        $this->mockDigest([
            ['content' => 'PSECRET into existing', 'wing_slug' => 'personal', 'room_slug' => 'diary', 'confidence' => 0.9],
            ['content' => 'PSECRET into new', 'wing_slug' => 'personal-two', 'room_slug' => 'notes', 'confidence' => 0.9, 'propose_new_wing' => true],
            ['content' => 'fine work note', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9],
        ], $captured);

        $before = $this->personalDrawer->room->drawers()->count();

        $res = $this->mcpCall('session_digest', [
            'session_id' => 'sess-r',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 3],
            'transcript' => 'anything',
            'recent_drawer_ids' => [$this->workDrawer->id, $this->personalDrawer->id],
        ], ['mcp:use'], self::RESTRICTED);

        $res->assertOk();
        $body = $res->json('result.structuredContent');
        $this->assertSame(['work'], array_column($body['persisted'], 'wing'));
        $this->assertSame([], $body['pending_wings']);
        $this->assertSame(2, $body['dropped_outside_wings']);
        $this->assertSame($before, $this->personalDrawer->room->drawers()->count());
        $this->assertSame(0, WikiPendingWing::count());
        $this->assertSame(1, Wing::where('slug', 'like', 'personal%')->count());
        $this->assertDoesNotMatchRegularExpression(self::LEAK, json_encode($captured['context']['recent_drawers']));
    }

    public function test_session_digest_replay_does_not_report_another_wings_drawers(): void
    {
        $captured = new \ArrayObject;
        $this->mockDigest([
            ['content' => 'filed by an unrestricted device', 'wing_slug' => 'personal', 'room_slug' => 'diary', 'confidence' => 0.9],
        ], $captured);

        $args = [
            'session_id' => 'sess-shared',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 3],
            'transcript' => 'anything',
        ];

        $this->mcpCall('session_digest', $args, ['mcp:use'])->assertOk();

        // The guard caches the user, and with it the first request's token.
        $this->app['auth']->forgetGuards();

        $res = $this->mcpCall('session_digest', $args, ['mcp:use'], self::RESTRICTED);

        $res->assertOk();
        $this->assertSame([], $res->json('result.structuredContent.persisted'));
        $this->assertDoesNotMatchRegularExpression(self::LEAK, $res->getContent());
    }

    // ---------------------------------------------------- unrestricted sanity

    public static function unrestrictedRows(): array
    {
        return [
            'drawer_search' => ['tool', 'drawer_search', ['query' => 'alpha'], 'PSECRET'],
            'drawer_get' => ['tool', 'drawer_get', ['id' => '{personal_drawer}'], 'PSECRET'],
            'drawer_add' => ['tool', 'drawer_add', ['wing' => 'personal', 'room' => 'diary', 'content' => 'new'], '"id"'],
            'brain_status' => ['tool', 'brain_status', [], 'personal:journal'],
            'palace_wake_up' => ['tool', 'palace_wake_up', [], 'personal:journal'],
            'recall' => ['tool', 'recall', ['prompt' => 'alpha PSECRET page body journal pending drawers'], 'PSECRET'],
            'context_get' => ['tool', 'context_get', ['name' => 'work'], 'PSECRET drawer body'],
            'context_set' => ['tool', 'context_set', ['name' => 'personal', 'content' => 'rewritten'], '"updated"'],
            'context_list' => ['tool', 'context_list', [], 'personal:journal'],
            'wiki_lint' => ['tool', 'wiki_lint', [], 'personal:stub'],
            'wiki_lint auto_fix' => ['tool', 'wiki_lint', ['auto_fix' => true], 'archive_empty'],
            'wiki_compile' => ['tool', 'wiki_compile', ['name' => 'personal'], 'PSECRET drawer body'],
            'wiki_history' => ['tool', 'wiki_history', ['name' => 'personal'], '"revision_count":3'],
            'wiki_graph' => ['tool', 'wiki_graph', ['start_page' => 'work'], 'personal'],
            'resource drawer' => ['resource', 'mnemon://drawer/{personal_drawer}', [], 'PSECRET'],
            'resource wiki' => ['resource', 'mnemon://wiki/personal', [], 'PSECRET'],
            'resource wing' => ['resource', 'mnemon://wing/personal', [], 'diary'],
            'prompt synthesize_wing' => ['prompt', 'synthesize_wing', ['wing_slug' => 'personal'], 'personal'],
            'prompt find_stale_wiki_pages' => ['prompt', 'find_stale_wiki_pages', [], 'context_list'],
            'prompt drawer_to_wiki' => ['prompt', 'drawer_to_wiki', ['drawer_id' => '{personal_drawer}'], 'drawer_get'],
        ];
    }

    #[DataProvider('unrestrictedRows')]
    public function test_unrestricted_token_still_sees_everything(string $kind, string $target, array $args, string $expect): void
    {
        $res = $this->call_($kind, $target, $args, null);

        $res->assertOk();
        $this->assertFalse($res->json('result.isError') ?? false, $res->getContent());
        $this->assertStringContainsString($expect, $res->getContent());
    }

    public function test_unrestricted_session_digest_files_into_any_wing(): void
    {
        $this->mockDigest([
            ['content' => 'personal note', 'wing_slug' => 'personal', 'room_slug' => 'diary', 'confidence' => 0.9],
        ], new \ArrayObject);

        $res = $this->mcpCall('session_digest', [
            'session_id' => 'sess-u',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 3],
            'transcript' => 'anything',
        ], ['mcp:use']);

        $this->assertSame(['personal'], array_column($res->json('result.structuredContent.persisted'), 'wing'));
    }

    // -------------------------------------------------------------- helpers

    private function call_(string $kind, string $target, array $args, ?array $patterns): TestResponse
    {
        $target = $this->resolve($target);
        $args = array_map(fn ($v) => is_string($v) ? $this->resolve($v) : $v, $args);

        return match ($kind) {
            'tool' => $this->mcpCall($target, $args, ['mcp:use'], $patterns),
            'resource' => $this->mcpResourceRead($target, ['mcp:use'], $patterns),
            'prompt' => $this->mcpPromptGet($target, $args, ['mcp:use'], $patterns),
        };
    }

    private function resolve(string|int $value): string|int
    {
        if (! is_string($value)) {
            return $value;
        }

        $resolved = str_replace(
            ['{work_drawer}', '{personal_drawer}'],
            [(string) $this->workDrawer->id, (string) $this->personalDrawer->id],
            $value
        );

        return ctype_digit($resolved) ? (int) $resolved : $resolved;
    }

    /** Replace the caller's own argument, wherever it is echoed, with a placeholder. */
    private function mask(string $body, string $value): string
    {
        // Tools that map a page name to its wing echo the slugified form too.
        foreach (array_unique([$value, Wing::slugify($value)]) as $echo) {
            $body = preg_replace('/(?<![\w-])'.preg_quote($echo, '/').'(?![\w.:-])/', '<ARG>', $body);
        }

        return $body;
    }

    /** Everything a refused write could have touched. */
    private function snapshot(): array
    {
        return [
            'drawers' => Drawer::withTrashed()->orderBy('id')->get(['id', 'room_id', 'content', 'tier', 'deleted_at'])->toArray(),
            'rooms' => Room::orderBy('id')->pluck('slug', 'id')->all(),
            'wings' => Wing::orderBy('id')->pluck('slug', 'id')->all(),
            'pages' => WikiPage::withTrashed()->orderBy('id')
                ->get(['id', 'name', 'content', 'related', 'revision_count', 'deleted_at'])->toArray(),
        ];
    }

    private function mockDigest(array $proposals, \ArrayObject $captured): void
    {
        $this->app->bind(SessionDigestService::class, function ($app) use ($proposals, $captured) {
            $llm = new class($proposals, $captured)
            {
                public function __construct(public array $p, public \ArrayObject $captured) {}

                public function digest(string $t, array $c): array
                {
                    $this->captured['context'] = $c;

                    return $this->p;
                }
            };

            return new SessionDigestService($llm, $app->make(DrawerWriteService::class));
        });
    }
}
