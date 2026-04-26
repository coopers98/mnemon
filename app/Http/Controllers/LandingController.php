<?php

namespace App\Http\Controllers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function index(): View
    {
        $stats = [
            'drawers' => Drawer::count(),
            'wiki_pages' => WikiPage::count(),
            'wings' => Wing::count(),
        ];

        return view('landing.index', compact('stats'));
    }
}
