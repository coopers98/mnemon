<?php

namespace App\Http\Controllers;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\View\View;

class ExplainController extends Controller
{
    /**
     * The long-form explainer for someone deciding whether to run this.
     *
     * Counts come from the live instance rather than being written into the
     * copy, because a page that explains the architecture is the last place
     * that should quietly go stale. The edge figures in particular are there
     * to be honest about how little of the relationship graph is populated —
     * hard-coding them would defeat the point the section is making.
     */
    public function howItWorks(): View
    {
        $edges = EntityRelationship::count();

        return view('explain.how-it-works', [
            'stats' => [
                'drawers' => Drawer::count(),
                'wiki_pages' => WikiPage::count(),
                'wings' => Wing::count(),
                'edges' => $edges,
                'generic_edges' => EntityRelationship::where('edge_type', 'references')->count(),
                'edge_types_used' => EntityRelationship::distinct()->count('edge_type'),
                'edge_types_available' => count(EntityRelationship::EDGE_TYPES),
            ],
        ]);
    }
}
