<?php

namespace App\Http\Controllers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Services\MarkdownRenderer;
use App\Services\PalaceSearchService;
use App\Services\WikiSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WikiController extends Controller
{
    public function __construct(
        protected MarkdownRenderer $markdownRenderer,
        protected WikiSearchService $wikiSearchService,
        protected PalaceSearchService $palaceSearchService,
    ) {}

    public function index(): View
    {
        $pages = WikiPage::orderBy('type')->orderBy('title')->get();

        $grouped = $pages->groupBy('type');

        $stats = [
            'total_pages' => $pages->count(),
            'total_drawers' => Drawer::count(),
            'last_compiled' => $pages->max('last_compiled_at'),
        ];

        return view('wiki.index', compact('grouped', 'stats'));
    }

    public function show(string $name): View
    {
        $page = WikiPage::where('name', $name)->firstOrFail();

        $renderedContent = $this->markdownRenderer->render($this->withoutDuplicateTitle($page->content ?? '', $page->title));

        // Get related pages
        $relatedPages = collect($page->related ?? [])
            ->map(fn (string $pageName) => WikiPage::where('name', $pageName)->first())
            ->filter();

        // Backlinks: other pages whose `related` list names this one. Counted in
        // PHP because `related` is a json column and SQL JSON matching differs
        // between SQLite and Postgres; a LIKE would also match name prefixes.
        $backlinkCount = WikiPage::where('name', '!=', $page->name)
            ->get(['related'])
            ->filter(fn (WikiPage $other) => in_array($page->name, $other->related ?? [], true))
            ->count();

        // Get source drawers
        $sourceDrawerIds = collect($page->sources ?? []);
        $sourceDrawers = $sourceDrawerIds->isNotEmpty()
            ? Drawer::whereIn('id', $sourceDrawerIds)->with('room.wing')->get()
            : collect();

        return view('wiki.show', compact('page', 'renderedContent', 'relatedPages', 'sourceDrawers', 'backlinkCount'));
    }

    /**
     * The page view already prints the title as its heading; drop a leading
     * `# Title` from the rendered copy when it only repeats it.
     */
    private function withoutDuplicateTitle(string $content, ?string $title): string
    {
        if (preg_match('/\A\s*#[ \t]+(.+?)[ \t#]*\R/', $content."\n", $m)
            && mb_strtolower(trim($m[1])) === mb_strtolower(trim((string) $title))) {
            return ltrim(substr($content."\n", strlen($m[0])));
        }

        return $content;
    }

    public function history(string $name): View
    {
        $page = WikiPage::where('name', $name)->firstOrFail();

        $revisions = $page->revisions()->orderByDesc('revision')->paginate(20);

        return view('wiki.history', compact('page', 'revisions'));
    }

    public function search(Request $request): View
    {
        // `q[]=x` makes q an array; string() would cast it and throw.
        $q = $request->query('q');
        $query = is_string($q) ? trim($q) : '';

        $wikiResults = collect();
        $drawerResults = collect();

        if ($query !== '') {
            $wikiResults = $this->wikiSearchService->search($query, limit: 20);
            $drawerResults = $this->palaceSearchService->search($query, limit: 10, mode: 'fulltext');
        }

        return view('wiki.search', compact('query', 'wikiResults', 'drawerResults'));
    }
}
