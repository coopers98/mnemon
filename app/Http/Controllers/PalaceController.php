<?php

namespace App\Http\Controllers;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\View\View;

class PalaceController extends Controller
{
    public function index(): View
    {
        $wings = Wing::withCount('drawers', 'rooms')->orderBy('name')->get();

        return view('palace.index', compact('wings'));
    }

    public function wing(string $wingSlug): View
    {
        $wing = Wing::where('slug', $wingSlug)->firstOrFail();

        $rooms = $wing->rooms()->withCount('drawers')->orderBy('name')->get();

        // Check if a wiki page exists for this wing
        $wikiPage = WikiPage::where('name', 'like', '%'.$wing->slug.'%')->first();

        return view('palace.wing', compact('wing', 'rooms', 'wikiPage'));
    }

    public function room(string $wingSlug, string $roomSlug): View
    {
        $wing = Wing::where('slug', $wingSlug)->firstOrFail();
        $room = Room::where('wing_id', $wing->id)->where('slug', $roomSlug)->firstOrFail();

        $drawers = $room->drawers()->orderByDesc('created_at')->paginate(20);

        return view('palace.room', compact('wing', 'room', 'drawers'));
    }

    public function drawer(Drawer $drawer): View
    {
        $drawer->load('room.wing');

        // Find wiki pages that reference this drawer
        $referencedBy = WikiPage::whereNotNull('sources')
            ->get()
            ->filter(fn (WikiPage $page) => in_array($drawer->id, $page->sources ?? []))
            ->values();

        return view('palace.drawer', compact('drawer', 'referencedBy'));
    }
}
