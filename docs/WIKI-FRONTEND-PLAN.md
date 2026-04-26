# Mnemon — Wiki Frontend & Landing Page Implementation Plan

**Date:** 2026-04-26
**Status:** Draft
**Depends on:** Design system (can build structure in parallel, apply styling later)

---

## Goals

1. **Wiki frontend** — browsable, interlinked wiki pages at `/wiki` (authenticated)
2. **Landing page** — replace default Laravel welcome at `/`
3. **Clean separation** — structural logic decoupled from visual styling for design system integration

---

## Architecture Decisions

### Rendering Stack
- **Blade + Tailwind CSS** — no SPA framework needed for a read-heavy wiki
- **Markdown rendering** — `league/commonmark` with custom extensions for wikilinks
- **Auth** — Laravel's built-in session auth, shared with Filament (same users table)
- **No API dependency** — wiki frontend reads directly from Eloquent, not through MCP

### URL Structure
```
/                           → Landing page (public)
/login                      → Auth (Laravel Breeze or simple form)
/wiki                       → Wiki index (authenticated)
/wiki/{name}                → Wiki page view (e.g., /wiki/project:atlas-abs)
/wiki/{name}/history        → Page revision history
/wiki/search?q=...          → Search results
/palace                     → Palace browser (authenticated)
/palace/{wing}              → Wing view with rooms
/palace/{wing}/{room}       → Room view with drawers
/palace/drawer/{id}         → Single drawer view
/admin                      → Filament admin (existing)
```

### Wikilink Rendering
Wiki pages use `[[page-name]]` syntax (Karpathy convention). Custom CommonMark extension:
- `[[project:atlas-abs]]` → `<a href="/wiki/project:atlas-abs">Atlas Abs</a>`
- `[[person:cooper|Cooper Sellers]]` → `<a href="/wiki/person:cooper">Cooper Sellers</a>`
- Broken links (page doesn't exist) → render with `class="broken-link"` styling + tooltip

---

## Sprint Breakdown

### Sprint 1: Foundation & Routing

**Controllers:**
- `WikiController` — index, show, search, history
- `PalaceController` — wings, rooms, drawers
- `LandingController` — home page

**Middleware:**
- Reuse Laravel's `auth` middleware for `/wiki` and `/palace` routes
- Landing page is public

**Routes** (`routes/web.php`):
```php
Route::get('/', [LandingController::class, 'index']);
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth')->group(function () {
    Route::get('/wiki', [WikiController::class, 'index'])->name('wiki.index');
    Route::get('/wiki/search', [WikiController::class, 'search'])->name('wiki.search');
    Route::get('/wiki/{name}', [WikiController::class, 'show'])->name('wiki.show')
        ->where('name', '[a-z0-9:_-]+');
    Route::get('/wiki/{name}/history', [WikiController::class, 'history'])->name('wiki.history')
        ->where('name', '[a-z0-9:_-]+');

    Route::get('/palace', [PalaceController::class, 'index'])->name('palace.index');
    Route::get('/palace/{wing}', [PalaceController::class, 'wing'])->name('palace.wing');
    Route::get('/palace/{wing}/{room}', [PalaceController::class, 'room'])->name('palace.room');
    Route::get('/palace/drawer/{drawer}', [PalaceController::class, 'drawer'])->name('palace.drawer');
});
```

**Markdown Service:**
- `App\Services\MarkdownRenderer` wrapping `league/commonmark`
- Custom `WikilinkExtension` that converts `[[...]]` to HTML links
- Validate target pages exist (broken link detection)
- Support for `[[name|display text]]` alias syntax

**Tests:**
- Route access (auth required, redirects to login)
- WikiController returns correct page by name
- 404 for nonexistent wiki pages
- Search returns results
- Wikilink rendering (valid links, broken links, aliases)

### Sprint 2: Wiki Views (Blade Templates)

**Layout** (`layouts/wiki.blade.php`):
- Header: Mnemon logo/name, search bar, user menu, link to admin
- Sidebar: navigation grouped by page type (Projects, People, Concepts, Decisions)
- Main content area: rendered markdown
- Footer: minimal

**Wiki Index** (`wiki/index.blade.php`):
- Stats bar: total pages, total drawers, last activity
- Pages grouped by type with counts
- Each page shows: name, description, confidence badge, last compiled date
- Quick search at top

**Wiki Page View** (`wiki/show.blade.php`):
- Breadcrumb: Wiki > Type > Page Name
- Page title (derived from name)
- Confidence badge + last compiled timestamp
- Rendered markdown content (with wikilinks as clickable links)
- Sidebar metadata:
  - Type badge
  - Related pages (as links)
  - Source drawers (expandable, shows first 200 chars each)
  - Revision count + link to history
  - Pending updates indicator (if pending_drawers_since_compile > 0)
- "View in Admin" link for editing

**Wiki History** (`wiki/history.blade.php`):
- Timeline of revisions
- Each revision: date, agent, content hash, diff indicator
- Click to view revision content

**Wiki Search** (`wiki/search.blade.php`):
- Search input (persistent)
- Results: wiki pages + palace drawers, sorted by relevance
- Each result: content snippet with highlighted matches, source location, score

### Sprint 3: Palace Browser Views

**Palace Index** (`palace/index.blade.php`):
- Grid/list of wings with drawer counts, last activity
- Visual indicator of wing type (project, person, decision, session)

**Wing View** (`palace/wing.blade.php`):
- Wing name + description
- Rooms listed with drawer counts
- Link to associated wiki page (if exists)

**Room View** (`palace/room.blade.php`):
- Paginated drawer list, newest first
- Each drawer: content preview, source, created date, tier badge
- Click to expand full content

**Drawer View** (`palace/drawer.blade.php`):
- Full verbatim content
- Metadata: source, tier, quality score, retention score
- Wing/room breadcrumb
- "Referenced by" — wiki pages that cite this drawer

### Sprint 4: Landing Page

**Landing page** (`landing/index.blade.php`):
- Hero section: Mnemon tagline + brief description
- Feature highlights: Palace (verbatim storage), Wiki (compiled knowledge), MCP (agent interface)
- Optional: live stats (drawer count, wiki pages, wings) — fetched from DB
- Login button → `/login`
- Footer: "Built with Laravel" + links

### Sprint 5: Polish & Design System Integration

- Apply Cooper's design system tokens (colors, typography, spacing, components)
- Dark mode support (if design system includes it)
- Responsive layout (mobile wiki reading)
- Favicon + meta tags
- Open Graph tags for sharing

---

## Design System Integration Points

These are the touch points where the design system will be applied:

| Component | Files | What to Style |
|-----------|-------|--------------|
| Layout shell | `layouts/wiki.blade.php` | Header, sidebar, footer, content area |
| Navigation | `components/wiki-nav.blade.php` | Sidebar groups, active states, icons |
| Page cards | `components/wiki-card.blade.php` | Index page listings |
| Confidence badges | `components/confidence-badge.blade.php` | High/medium/low color coding |
| Tier badges | `components/tier-badge.blade.php` | Raw/reviewed/consolidated |
| Search bar | `components/search-bar.blade.php` | Input styling, results |
| Markdown content | `wiki.css` or Tailwind prose | Headings, links, code blocks, tables |
| Wikilinks | Custom CSS class | Normal vs broken link styling |
| Landing hero | `landing/index.blade.php` | Full page design |
| Buttons/forms | Blade components | Login, search, navigation |

**The structural sprints (1-3) can proceed now.** Sprint 4 (landing) and Sprint 5 (polish) benefit from the design system being ready.

---

## Dependencies

- `league/commonmark` — Markdown → HTML (already a Laravel ecosystem standard)
- `tailwindcss/typography` — `@tailwindcss/typography` prose plugin for rendered markdown
- No new JS frameworks — Alpine.js (already included via Filament) for any interactivity

---

## Migration Notes

No new database tables needed — all data already exists:
- WikiPage model (with confidence, sources, related, revisions)
- Drawer model (with tiers, quality scores)
- Wing/Room models
- User model + auth

Only new code: controllers, views, routes, markdown service, wikilink extension.

---

## Open Questions for Cooper

1. **Auth style** — Simple login form, or add a "remember me" / magic link option?
2. **Palace browser priority** — Build it in Sprint 3, or defer and focus on wiki first?
3. **Public pages** — Should any wiki pages be publicly viewable without login?
4. **Search scope** — Wiki-only search, or unified search across wiki + palace?
5. **Mobile** — Priority for responsive design, or desktop-first?
