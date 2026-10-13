<?php

namespace Database\Seeders;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\KnowledgeGraphService;
use App\Services\WikiPageWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the public demo's synthetic brain from database/demo
 * (docs/design/2026-10-13-public-demo-design.md §3).
 *
 * Wiki pages go through WikiPageWriter, the path context_set uses, so the
 * demo's revisions, scores, consolidation and graph edges are what an agent
 * would have produced. Dates come from each fixture's days_ago, so the demo
 * always looks recent. Run only by mnemon:demo-reset, which points the default
 * connection at a fresh file first.
 */
class DemoSeeder extends Seeder
{
    /** Marks a database as built by this seeder; mnemon:demo-reset replaces nothing else. */
    public const MARKER_TABLE = 'demo_marker';

    public const AGENT = 'demo-seeder';

    public function run(WikiPageWriter $writer, KnowledgeGraphService $graph): void
    {
        if (! config('mnemon.demo.enabled')) {
            throw new RuntimeException('DemoSeeder runs only in demo mode (MNEMON_DEMO=true).');
        }

        $dir = (string) config('mnemon.demo.fixtures');
        $drawerIds = $this->seedPalace(Yaml::parseFile("{$dir}/palace.yaml"));

        foreach ($this->pages("{$dir}/wiki") as $page) {
            $this->seedPage($writer, $page, $drawerIds);
        }

        // A page's related links become edges only to pages that exist, and
        // pages are written one at a time, so link them once all exist.
        foreach (WikiPage::all() as $page) {
            $graph->syncReferencesFromRelated($page);
        }

        Schema::create(self::MARKER_TABLE, fn ($table) => $table->timestamp('seeded_at'));
        DB::table(self::MARKER_TABLE)->insert(['seeded_at' => now()]);
    }

    /**
     * @param  array{wings: list<array<string, mixed>>}  $palace
     * @return array<string, int> drawer fixture key => drawer id
     */
    private function seedPalace(array $palace): array
    {
        $ids = [];

        foreach ($palace['wings'] as $w) {
            $wing = Wing::create(['slug' => $w['slug'], 'name' => $w['name'], 'description' => $w['description'] ?? null]);

            foreach ($w['rooms'] as $r) {
                $room = Room::create(['wing_id' => $wing->id, 'slug' => $r['slug'], 'name' => $r['name']]);

                foreach ($r['drawers'] as $d) {
                    if (isset($ids[$d['key']])) {
                        throw new RuntimeException("Duplicate drawer key {$d['key']}");
                    }
                    $at = now()->subDays((int) $d['days_ago'])->subMinutes(crc32($d['key']) % 600);
                    // forceCreate: created_at is not fillable, and create() would stamp "now".
                    $ids[$d['key']] = Drawer::forceCreate([
                        'room_id' => $room->id,
                        'content' => trim($d['content']),
                        'source' => $d['source'],
                        'metadata' => $d['metadata'] ?? [],
                        'created_at' => $at,
                        'updated_at' => $at,
                    ])->id;
                }
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function pages(string $dir): array
    {
        $pages = [];

        foreach (File::files($dir) as $file) {
            if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $file->getContents(), $m)) {
                throw new RuntimeException("{$file->getFilename()} has no front matter");
            }
            $pages[] = Yaml::parse($m[1]) + ['body' => trim($m[2])];
        }

        // Oldest first, so later pages can relate to earlier ones in order.
        usort($pages, fn ($a, $b) => $b['days_ago'] <=> $a['days_ago']);

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, int>  $drawerIds
     */
    private function seedPage(WikiPageWriter $writer, array $page, array $drawerIds): void
    {
        $sources = array_map(
            fn (string $key) => $drawerIds[$key] ?? throw new RuntimeException("{$page['name']} cites unknown drawer {$key}"),
            $page['sources'] ?? [],
        );
        $versions = [...($page['revisions'] ?? []), ['content' => $page['body'], 'days_ago' => $page['days_ago']]];

        foreach ($versions as $version) {
            $saved = $writer->write(
                name: $page['name'],
                content: trim($version['content']),
                agent: self::AGENT,
                description: $page['description'] ?? null,
                confidence: $page['confidence'] ?? null,
                sources: $sources,
                related: $page['related'] ?? [],
            );
            DB::table('wiki_page_revisions')
                ->where('page_name', $saved->name)->where('revision', $saved->revision_count)
                ->update(['written_at' => $at = now()->subDays((int) $version['days_ago']), 'created_at' => $at, 'updated_at' => $at]);
        }

        // Base query builder: Eloquent would overwrite updated_at with "now".
        DB::table('wiki_pages')->where('id', $saved->id)->update([
            'title' => $page['title'],
            'created_at' => now()->subDays((int) $versions[0]['days_ago']),
            'updated_at' => $last = now()->subDays((int) $page['days_ago']),
            'last_compiled_at' => $last,
        ]);
    }
}
