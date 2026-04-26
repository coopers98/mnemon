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

        $renderedContent = $this->markdownRenderer->render($page->content ?? '');

        // Get related pages
        $relatedPages = collect($page->related ?? [])
            ->map(fn (string $pageName) => WikiPage::where('name', $pageName)->first())
            ->filter();

        // Get source drawers
        $sourceDrawerIds = collect($page->sources ?? []);
        $sourceDrawers = $sourceDrawerIds->isNotEmpty()
            ? Drawer::whereIn('id', $sourceDrawerIds)->with('room.wing')->get()
            : collect();

        return view('wiki.show', compact('page', 'renderedContent', 'relatedPages', 'sourceDrawers'));
    }

    public function history(string $name): View
    {
        $page = WikiPage::where('name', $name)->firstOrFail();

        $revisions = $page->revisions()->orderByDesc('revision')->paginate(20);

        return view('wiki.history', compact('page', 'revisions'));
    }

    public function search(Request $request): View
    {
        $query = $request->string('q')->trim()->toString();

        $wikiResults = collect();
        $drawerResults = collect();

        if ($query !== '') {
            $wikiResults = $this->wikiSearchService->search($query, limit: 20);
            $drawerResults = $this->palaceSearchService->search($query, limit: 10, mode: 'fulltext');
        }

        return view('wiki.search', compact('query', 'wikiResults', 'drawerResults'));
    }
}
