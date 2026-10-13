# Public Demo (trymnemon.com) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship trymnemon.com: the landing page, the explainer, and a read-only,
no-login wiki and palace over a synthetic brain, served by the same codebase
with `MNEMON_DEMO=true`.

**Architecture:**
- **The gate.** A global `DemoMode` middleware allowlists `GET`/`HEAD` to five
  paths and refuses everything else with a bare 404. `auth.unless-demo` opens
  the wiki and palace.
- **The data.** A synthetic brain is written as YAML/Markdown fixtures. It is
  loaded through the same `WikiPageWriter` that `context_set` uses.
- **The reset.** `mnemon:demo-reset` builds the brain in a separate SQLite file
  and swaps it in atomically, nightly and on every deploy.
- **The site.** An isolated Forge site, reachable only through Cloudflare, runs
  it.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit (class-style tests,
`RefreshDatabase`), SQLite, symfony/yaml (new), Forge API, Cloudflare API.

**Spec:** `docs/design/2026-10-13-public-demo-design.md`. Read it before any
task; it is the source of every requirement here.

## Global Constraints

- **Off by default.** Demo mode is off unless `MNEMON_DEMO=true`. With it off,
  every existing behaviour is unchanged, and no response carries an
  `X-Mnemon-Demo` header.
- **Refusals.** A refused request gets an empty body, a bare status
  (404/429/503), and the header `X-Mnemon-Demo: refused`. Use
  `response('', $status)`, never `abort()`.
- **Allowlist.** In demo mode only `GET`/`HEAD` (by `getRealMethod()`) to these
  paths is allowed: `/`, `how-it-works`, `wiki`, `wiki/*`, `palace`, `palace/*`,
  `up`.
- **Rate limit.** `mnemon.demo.rate_limit` (default 120) requests a minute per
  client. The key is IPv4 as-is, or the IPv6 /64. Refused requests count.
- **No network.** The demo makes no network calls: no OpenAI, no mail.
- **Fixtures.** They contain no real people, projects, hosts, paths or
  credentials. Emails are `@example.com` only.
- **Commits.** Commit to `main` (repo rule: no branches). Never push unless the
  user says so; Task 9 pushes only after the user approves it.
- **Commit trailer.** Every commit message ends with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- **Style.** Run `./vendor/bin/pint` before each commit, and run
  `php artisan test --compact` before each commit. The whole suite must pass.

## Review Focus

1. **`HEAD` requests.** A `HEAD` to an allowed page must be served (200), not
   refused. Crawlers and link checkers send `HEAD`. Covered in Task 1
   (`test_head_on_allowed_page_is_served`).
2. **Odd paths.**
   - An encoded path such as `/%61dmin` must be refused.
   - A trailing slash on an allowed path (`/wiki/`) must still be served.
   - Covered in Task 1 (`test_encoded_and_trailing_slash_paths`).
3. **A missing wiki page or drawer.** It is a normal 404 page from the router,
   not a refusal and not a 500. Covered in Task 1
   (`test_missing_page_is_a_router_404_not_a_refusal`).
4. **First deploy.** No live database file exists yet; the reset must create
   one. Covered in Task 6 (`test_first_reset_creates_the_live_file`).
5. **Search punctuation.** SQL wildcards and quotes (`%`, `_`, `'`) in search
   on SQLite must return 200. Covered in Task 2
   (`test_search_tolerates_sql_punctuation`).

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `config/mnemon.php` | modify | `demo` block (enabled, host, rate_limit, url, fixtures) |
| `config/trustedproxy.php` | create | `proxies` from `TRUSTED_PROXIES` |
| `app/Http/Middleware/DemoMode.php` | create | the request gate |
| `app/Http/Middleware/AuthenticateUnlessDemo.php` | create | `auth` that steps aside in demo mode |
| `bootstrap/app.php` | modify | append `DemoMode`, alias `auth.unless-demo` |
| `routes/web.php` | modify | wiki and palace group uses `auth.unless-demo` |
| `app/Http/Controllers/WikiController.php` | modify | `q` must be a string |
| `app/Console/Commands/DecayConfidenceCommand.php` | modify | refuse in demo mode |
| `app/Console/Commands/ApplyRetentionCommand.php` | modify | refuse in demo mode |
| `app/Services/WikiPageWriter.php` | create | one wiki write, the same for agents and the seeder |
| `app/Mcp/Tools/ContextSetTool.php` | modify | delegate to `WikiPageWriter` |
| `database/demo/palace.yaml` | create | wings, rooms, drawers |
| `database/demo/wiki/*.md` | create | ten wiki pages |
| `database/seeders/DemoSeeder.php` | create | loads the fixtures |
| `app/Console/Commands/DemoResetCommand.php` | create | guarded, locked, atomic rebuild |
| `routes/console.php` | modify | schedule by mode |
| `app/Providers/AppServiceProvider.php` | modify | `@demo` and `@browsable` Blade conditions |
| `resources/views/partials/colophon.blade.php` | modify | banner, navigation, sign-in, demo link |
| `resources/views/landing/index.blade.php` | modify | calls to action, contact, footer navigation |
| `resources/views/palace/index.blade.php` | modify | hide "Open admin" |
| `resources/views/explain/how-it-works.blade.php` | modify | "This demo holds…" |
| `app/Http/Controllers/PalaceController.php` | modify | wing → wiki lookup |
| `README.md`, `CHANGELOG.md`, `.env.example`, `docs/USERGUIDE.md` | modify | docs |
| `tests/Feature/Demo/*.php` | create | all demo tests |

---

### Task 1: The demo-mode gate

**Files:**
- Modify: `config/mnemon.php` (add a `demo` key at the end of the returned array)
- Create: `config/trustedproxy.php`
- Create: `app/Http/Middleware/DemoMode.php`
- Create: `app/Http/Middleware/AuthenticateUnlessDemo.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php` (the `Route::middleware('auth')` group)
- Test: `tests/Feature/Demo/DemoModeTest.php`

**Interfaces:**
- **Produces:**
  - `config('mnemon.demo.enabled'|'host'|'rate_limit'|'url'|'fixtures')`
  - `App\Http\Middleware\DemoMode::REFUSED_HEADER` (`'X-Mnemon-Demo'`)
  - `DemoMode::clientKey(string $ip): string`
  - the middleware alias `auth.unless-demo`

- [ ] **Step 1: Add config**

In `config/mnemon.php`, add before the final `];`:

```php
    // The public, read-only demo (trymnemon.com). Off unless MNEMON_DEMO=true.
    // See docs/design/2026-10-13-public-demo-design.md.
    'demo' => [
        'enabled' => (bool) env('MNEMON_DEMO', false),
        // The one hostname the demo answers to; any other Host gets a 404.
        'host' => env('MNEMON_DEMO_HOST'),
        // Requests per minute per client (per IPv4 address, per IPv6 /64).
        'rate_limit' => (int) env('MNEMON_DEMO_RATE_LIMIT', 120),
        // Set on a real install to link its pages to the public demo.
        'url' => env('MNEMON_DEMO_URL'),
        // Where DemoSeeder reads the synthetic brain from.
        'fixtures' => database_path('demo'),
    ],
```

Create `config/trustedproxy.php`:

```php
<?php

return [
    // Proxies whose X-Forwarded-* headers are believed, as comma-separated
    // addresses or CIDR ranges. Unset trusts none. The public demo sets this to
    // Cloudflare's ranges so the rate limit sees visitors, not Cloudflare.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
];
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Demo/DemoModeTest.php`:

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED = ['/', 'how-it-works', 'wiki', 'wiki/*', 'palace', 'palace/*', 'up'];

    private function demo(array $extra = []): void
    {
        config(array_merge([
            'mnemon.demo.enabled' => true,
            'mnemon.demo.host' => null,
            'mnemon.demo.rate_limit' => 1000,
            'app.debug' => false,
        ], $extra));
    }

    private function assertRefused(TestResponse $r, int $status = 404): void
    {
        $r->assertStatus($status);
        $this->assertSame('refused', $r->headers->get('X-Mnemon-Demo'));
        $this->assertSame('', $r->getContent());
    }

    private function assertServed(TestResponse $r): void
    {
        $this->assertNull($r->headers->get('X-Mnemon-Demo'));
        $r->assertOk();
    }

    /** @return array{wing: Wing, room: Room, drawer: Drawer, page: WikiPage} */
    private function brain(): array
    {
        $wing = Wing::factory()->create(['slug' => 'project-alpha', 'name' => 'project:alpha']);
        $room = Room::factory()->create(['wing_id' => $wing->id, 'slug' => 'notes', 'name' => 'Notes']);
        $drawer = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'Alpha uses SQLite.']);
        $page = WikiPage::factory()->create(['name' => 'project:alpha', 'content' => '# Alpha', 'sources' => [$drawer->id]]);

        return compact('wing', 'room', 'drawer', 'page');
    }

    public function test_allowed_pages_are_served_without_login(): void
    {
        $this->demo();
        ['drawer' => $drawer] = $this->brain();

        foreach (['/', '/how-it-works', '/wiki', '/wiki/project:alpha', '/wiki/project:alpha/history',
            '/wiki/search?q=alpha', '/palace', '/palace/project-alpha', '/palace/project-alpha/notes',
            "/palace/drawer/{$drawer->id}", '/up'] as $uri) {
            $this->assertServed($this->get($uri));
        }
    }

    public function test_head_on_allowed_page_is_served(): void
    {
        $this->demo();
        $this->assertServed($this->call('HEAD', '/wiki'));
    }

    public function test_encoded_and_trailing_slash_paths(): void
    {
        $this->demo();
        $this->assertRefused($this->get('/%61dmin'));
        $this->assertServed($this->get('/wiki/'));
    }

    public function test_missing_page_is_a_router_404_not_a_refusal(): void
    {
        $this->demo();
        $r = $this->get('/wiki/project:nope');
        $r->assertNotFound();
        $this->assertNull($r->headers->get('X-Mnemon-Demo'));
        $this->get('/palace/drawer/999999')->assertNotFound();
    }

    public function test_closed_paths_are_refused(): void
    {
        $this->demo();
        foreach (['/login', '/install', '/admin', '/admin/login', '/oauth/authorize',
            '/.well-known/oauth-authorization-server', '/mcp', '/storage/x', '/livewire/update'] as $uri) {
            $this->assertRefused($this->get($uri));
        }
        foreach (['/contact', '/login', '/mcp', '/logout'] as $uri) {
            $this->assertRefused($this->post($uri));
        }
    }

    public function test_every_route_outside_the_allowlist_is_refused(): void
    {
        $this->demo();
        $refused = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', 'x', $route->uri()), '/');

            foreach ($route->methods() as $method) {
                $readable = in_array($method, ['GET', 'HEAD'], true);
                if ($readable && Request::create($uri)->is(...self::ALLOWED)) {
                    continue;
                }
                $r = $this->call($method, $uri);
                $this->assertSame('refused', $r->headers->get('X-Mnemon-Demo'), "{$method} {$uri} was not refused");
                $refused[] = "{$method} {$uri}";
            }
        }

        // Guard against a sweep that silently found nothing to check.
        foreach (['GET /admin', 'POST /mcp', 'GET /oauth/authorize', 'POST /contact', 'GET /login'] as $expected) {
            $this->assertContains($expected, $refused);
        }
    }

    public function test_writes_to_allowed_paths_are_refused(): void
    {
        $this->demo();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertRefused($this->call($method, '/wiki'));
            $this->assertRefused($this->call($method, '/palace/x'));
        }
    }

    public function test_method_override_does_not_bypass(): void
    {
        $this->demo();
        $this->assertRefused($this->post('/wiki', ['_method' => 'GET']));
        $this->assertRefused($this->withHeader('X-HTTP-METHOD-OVERRIDE', 'GET')->post('/wiki'));
    }

    public function test_other_hosts_are_refused(): void
    {
        $this->demo(['mnemon.demo.host' => 'trymnemon.com']);
        $this->assertRefused($this->get('http://atlas-x.on-forge.com/'));
        $this->assertServed($this->get('http://trymnemon.com/'));
    }

    public function test_debug_mode_fails_closed(): void
    {
        $this->demo(['app.debug' => true]);
        $this->assertRefused($this->get('/'), 503);
    }

    public function test_rate_limit_counts_refused_requests(): void
    {
        $this->demo(['mnemon.demo.rate_limit' => 3]);
        $this->get('/admin');
        $this->get('/admin');
        $this->get('/');
        $this->assertRefused($this->get('/'), 429);
    }

    public function test_ipv6_clients_share_a_slash_64(): void
    {
        $this->demo(['mnemon.demo.rate_limit' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::1'])->get('/');
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::ffff'])->get('/');
        $this->assertRefused($this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:aaaa::9'])->get('/'), 429);
        $this->assertServed($this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get('/'));
    }

    public function test_trusted_proxy_clients_are_counted_separately(): void
    {
        $this->demo(['mnemon.demo.rate_limit' => 1, 'trustedproxy.proxies' => ['10.0.0.0/8']]);
        $edge = ['REMOTE_ADDR' => '10.1.1.1'];
        $this->withServerVariables($edge)->withHeader('X-Forwarded-For', '203.0.113.5')->get('/');
        $this->assertServed($this->withServerVariables($edge)->withHeader('X-Forwarded-For', '203.0.113.6')->get('/'));
        $this->assertRefused($this->withServerVariables($edge)->withHeader('X-Forwarded-For', '203.0.113.5')->get('/'), 429);
    }

    public function test_untrusted_sender_cannot_choose_its_ip(): void
    {
        $this->demo(['mnemon.demo.rate_limit' => 1, 'trustedproxy.proxies' => ['10.0.0.0/8']]);
        $direct = ['REMOTE_ADDR' => '198.51.100.7'];
        $this->withServerVariables($direct)->withHeader('X-Forwarded-For', '203.0.113.5')->get('/');
        $this->assertRefused($this->withServerVariables($direct)->withHeader('X-Forwarded-For', '203.0.113.99')->get('/'), 429);
    }

    public function test_wiki_and_palace_are_not_indexed(): void
    {
        $this->demo();
        $this->assertSame('noindex', $this->get('/wiki')->headers->get('X-Robots-Tag'));
        $this->assertSame('noindex', $this->get('/palace')->headers->get('X-Robots-Tag'));
        $this->assertNull($this->get('/')->headers->get('X-Robots-Tag'));
    }

    public function test_demo_off_keeps_real_install_behaviour(): void
    {
        config(['mnemon.demo.enabled' => false]);

        $wiki = $this->get('/wiki');
        $wiki->assertRedirect('/login');
        $this->assertNull($wiki->headers->get('X-Mnemon-Demo'));
        $this->get('/palace')->assertRedirect('/login');
        $this->assertNull($this->get('/admin/login')->headers->get('X-Mnemon-Demo'));
    }

    public function test_client_key(): void
    {
        $this->assertSame('203.0.113.5', \App\Http\Middleware\DemoMode::clientKey('203.0.113.5'));
        $this->assertSame('2001:db8:1:2::/64', \App\Http\Middleware\DemoMode::clientKey('2001:db8:1:2:3:4:5:6'));
    }
}
```

The sweep only reaches registered routes, and the wiki and palace have no
write routes. `test_writes_to_allowed_paths_are_refused` covers writes to those
paths explicitly.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=DemoModeTest`
Expected: most tests FAIL. `/wiki` redirects to login, and no `X-Mnemon-Demo`
header is sent. `test_demo_off_keeps_real_install_behaviour` passes already.

- [ ] **Step 4: Implement the middleware**

Create `app/Http/Middleware/DemoMode.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an install into the public, read-only demo when mnemon.demo.enabled is
 * set (docs/design/2026-10-13-public-demo-design.md §1).
 *
 * Global middleware runs before routing, so this also covers the routes that
 * Filament, Passport, laravel/mcp and Livewire register for themselves. It
 * allows GET and HEAD to a short list of paths and refuses everything else
 * with a bare response: no view is rendered, so a flood of refusals is cheap.
 * An allowlist means a route a package adds later is closed by default.
 */
class DemoMode
{
    public const REFUSED_HEADER = 'X-Mnemon-Demo';

    /** Matched against Request::path(), which is '/' for the root. */
    private const ALLOWED = ['/', 'how-it-works', 'wiki', 'wiki/*', 'palace', 'palace/*', 'up'];

    private const NOT_INDEXED = ['wiki', 'wiki/*', 'palace', 'palace/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mnemon.demo.enabled')) {
            return $next($request);
        }

        // A public demo must never render a stack trace.
        if (config('app.debug')) {
            return $this->refuse(503);
        }

        // Counted before the allowlist, so probing closed paths is limited too.
        $key = 'demo:'.self::clientKey((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, (int) config('mnemon.demo.rate_limit', 120))) {
            return $this->refuse(429);
        }
        RateLimiter::hit($key, 60);

        $host = config('mnemon.demo.host');
        if ($host && strcasecmp($request->getHost(), $host) !== 0) {
            return $this->refuse(404);
        }

        // The real method: getMethod() honours _method and X-HTTP-METHOD-OVERRIDE.
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return $this->refuse(404);
        }

        if (! $request->is(...self::ALLOWED)) {
            return $this->refuse(404);
        }

        $response = $next($request);

        // A fictional person's notes should not be indexed as if they were real.
        if ($request->is(...self::NOT_INDEXED)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * The rate-limit identity of a client: an IPv4 address as-is, an IPv6
     * address by its /64, since one host commonly holds a whole /64.
     */
    public static function clientKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return inet_ntop(substr(inet_pton($ip), 0, 8).str_repeat("\0", 8)).'/64';
        }

        return $ip;
    }

    private function refuse(int $status): Response
    {
        return response('', $status)->header(self::REFUSED_HEADER, 'refused');
    }
}
```

Create `app/Http/Middleware/AuthenticateUnlessDemo.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;

/**
 * `auth` that steps aside in demo mode, where the wiki and palace are public.
 *
 * Checked per request, not in routes/web.php: routes are registered once and
 * baked in by route:cache, so a route-file condition could not be changed by
 * config at runtime or in tests.
 */
class AuthenticateUnlessDemo extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (config('mnemon.demo.enabled')) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
```

In `bootstrap/app.php`, replace the `withMiddleware` closure body with:

```php
        // Capture OAuth consent wing data before the approve controller runs.
        $middleware->web(append: [
            CaptureConsentWings::class,
        ]);

        // The public demo's gate. Appended so it runs after TrustProxies and
        // sees the real client IP; global, so it runs before routing.
        $middleware->append(DemoMode::class);

        $middleware->alias([
            'auth.unless-demo' => AuthenticateUnlessDemo::class,
        ]);
```

Then add these imports at the top:

```php
use App\Http\Middleware\AuthenticateUnlessDemo;
use App\Http\Middleware\DemoMode;
```

In `routes/web.php`, change `Route::middleware('auth')->group(function () {`
to `Route::middleware('auth.unless-demo')->group(function () {`.

- [ ] **Step 5: Run the tests and make them pass**

Run: `php artisan test --compact --filter=DemoModeTest`
Expected: PASS.

If `test_trusted_proxy_clients_are_counted_separately` fails, check
`vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php`. The
framework must fall back to `config('trustedproxy.proxies')` when no proxies
are set in code. If it does not, call
`$middleware->trustProxies(at: config('trustedproxy.proxies'))` from a
`booted` callback, and re-run. Do not set proxies inside `DemoMode`, because
`TrustProxies` overwrites them.

If `/wiki/` (trailing slash) is refused, normalise with
`rtrim($request->path(), '/') ?: '/'`. In that case use
`Request::create('/'.$path)->is(...)` on a request whose path has no trailing
slash, and keep the tests unchanged.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: all PASS. The existing `WikiFrontendTest` auth tests still redirect,
because demo mode is off by default.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add config/mnemon.php config/trustedproxy.php app/Http/Middleware/DemoMode.php \
  app/Http/Middleware/AuthenticateUnlessDemo.php bootstrap/app.php routes/web.php \
  tests/Feature/Demo/DemoModeTest.php
git commit -m "feat: demo mode gates every request to a read-only allowlist

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Search input that cannot 500

**Files:**
- Modify: `app/Http/Controllers/WikiController.php` (`search()`, the first line)
- Test: `tests/Feature/Demo/SearchInputTest.php`

**Interfaces:** none new.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_query_is_treated_as_empty_when_signed_in(): void
    {
        $this->actingAs(User::factory()->create())->get('/wiki/search?q[]=x')->assertOk();
    }

    public function test_array_query_is_treated_as_empty_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true, 'app.debug' => false]);
        $this->get('/wiki/search?q[]=x')->assertOk();
    }

    public function test_search_tolerates_sql_punctuation(): void
    {
        config(['mnemon.demo.enabled' => true, 'app.debug' => false]);
        foreach (['%', '_', "'", '"', '\\', '%%_\'--'] as $q) {
            $this->get('/wiki/search?q='.urlencode($q))->assertOk();
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SearchInputTest`
Expected: the two array-query tests FAIL with 500 (array to string
conversion).

- [ ] **Step 3: Fix**

In `WikiController::search()`, replace
`$query = $request->string('q')->trim()->toString();` with:

```php
        // `q[]=x` makes q an array; string() would cast it and throw.
        $q = $request->query('q');
        $query = is_string($q) ? trim($q) : '';
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=SearchInputTest`
Expected: PASS. If a punctuation case fails, fix it in the search service with
a regression test before moving on; do not weaken the test.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Http/Controllers/WikiController.php tests/Feature/Demo/SearchInputTest.php
git commit -m "fix: an array search query no longer returns 500

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Data-changing commands refuse in demo mode

**Files:**
- Modify: `app/Console/Commands/DecayConfidenceCommand.php` (top of `handle()`)
- Modify: `app/Console/Commands/ApplyRetentionCommand.php` (top of `handle()`)
- Test: `tests/Feature/Demo/DemoCommandGuardTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_decay_confidence_refuses_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $page = WikiPage::factory()->create(['confidence_score' => 0.987]);

        $this->artisan('mnemon:decay-confidence')->assertFailed();

        $this->assertSame(0.987, (float) $page->fresh()->confidence_score);
    }

    public function test_apply_retention_refuses_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $drawer = Drawer::factory()->create(['retention_score' => 0.0, 'created_at' => now()->subYears(5)]);

        $this->artisan('mnemon:apply-retention --force')->assertFailed();

        $this->assertNotSoftDeleted($drawer);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=DemoCommandGuardTest`
Expected: FAIL. The commands succeed.

- [ ] **Step 3: Implement**

Add at the very top of each `handle()`:

```php
        if (config('mnemon.demo.enabled')) {
            $this->error('Refusing to run in demo mode: the demo brain is rebuilt nightly by mnemon:demo-reset.');

            return self::FAILURE;
        }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=DemoCommandGuardTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Console/Commands/DecayConfidenceCommand.php app/Console/Commands/ApplyRetentionCommand.php tests/Feature/Demo/DemoCommandGuardTest.php
git commit -m "feat: decay and retention refuse to run in demo mode

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `WikiPageWriter`, one write path for agents and the seeder

**Files:**
- Create: `app/Services/WikiPageWriter.php`
- Modify: `app/Mcp/Tools/ContextSetTool.php`:
  - replace the block from `$attributes = [` through the
    `syncReferencesFromRelated` call;
  - replace `inferType()`.
- Test: `tests/Feature/Services/WikiPageWriterTest.php`

**Interfaces:**
- **Produces:**
  - `WikiPageWriter::write(string $name, string $content, string $agent,
    ?string $description = null, ?string $confidence = null,
    ?array $sources = null, ?array $related = null): WikiPage`
  - `WikiPageWriter::typeFor(string $name): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Services\WikiPageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_writes_make_two_revisions_and_consolidate_sources(): void
    {
        $drawer = Drawer::factory()->create(['tier' => 'raw']);
        WikiPage::factory()->create(['name' => 'concept:beta']);
        $writer = app(WikiPageWriter::class);

        $writer->write('project:alpha', 'First body, long enough to score.', 'tester', sources: [$drawer->id]);
        $page = $writer->write('project:alpha', 'Second body, long enough to score.', 'tester',
            description: 'Alpha', confidence: 'high', sources: [$drawer->id], related: ['concept:beta']);

        $this->assertSame('project', $page->type);
        $this->assertSame(2, $page->revision_count);
        $this->assertSame(2, WikiPageRevision::where('page_name', 'project:alpha')->count());
        $this->assertSame('Second body, long enough to score.',
            WikiPageRevision::where('page_name', 'project:alpha')->orderByDesc('revision')->value('content'));
        $this->assertSame(hash('sha256', 'First body, long enough to score.'), $page->previous_content_hash);
        $this->assertSame(1, $page->source_count);
        $this->assertNotNull($page->confidence_score);
        $this->assertNotNull($page->quality_score);
        $this->assertSame('consolidated', $drawer->fresh()->tier);
        $this->assertTrue(EntityRelationship::where('from_page', 'project:alpha')->where('to_page', 'concept:beta')->exists());
    }

    public function test_type_for(): void
    {
        $this->assertSame('person', WikiPageWriter::typeFor('person:rowan'));
        $this->assertSame('decision', WikiPageWriter::typeFor('decision:x'));
        $this->assertSame('synthesis', WikiPageWriter::typeFor('lessons'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=WikiPageWriterTest`
Expected: FAIL, "Class App\Services\WikiPageWriter not found".

- [ ] **Step 3: Create the service**

Move the code out of `ContextSetTool`; do not rewrite it. Create
`app/Services/WikiPageWriter.php`:

```php
<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Support\Facades\DB;

/**
 * Writes one revision of a wiki page: the page row and its scores, a revision
 * audit row, the consolidation of its source drawers, and its graph edges.
 *
 * Moved out of ContextSetTool so the demo seeder builds pages exactly the way
 * agents do. The tool keeps what is MCP-specific: validation, wing access, the
 * expected_revision check, the wiki index and log pages, and session logging.
 */
class WikiPageWriter
{
    public function __construct(
        protected QualityScoreService $quality,
        protected KnowledgeGraphService $graph,
    ) {}

    /**
     * @param  array<int>|null  $sources  Drawer ids the caller has already validated.
     * @param  array<string>|null  $related  Related page names.
     *
     * @throws \RuntimeException when another writer changed the page concurrently.
     */
    public function write(
        string $name,
        string $content,
        string $agent,
        ?string $description = null,
        ?string $confidence = null,
        ?array $sources = null,
        ?array $related = null,
    ): WikiPage {
        $existing = WikiPage::where('name', $name)->first();

        $attributes = [
            'title' => $existing?->title ?? $name,
            'content' => $content,
            'type' => self::typeFor($name),
            'description' => $description ?? ($existing?->description),
            'last_compiled_at' => now(),
            'pending_drawers_since_compile' => 0,
        ];

        if ($confidence !== null) {
            $attributes['confidence'] = $confidence;
        }
        if ($sources !== null) {
            $attributes['sources'] = $sources;
            $attributes['source_count'] = count($sources);
        }
        if ($related !== null) {
            $attributes['related'] = $related;
        }

        // Supersession — track content hash and revision count
        if ($existing !== null) {
            $attributes['previous_content_hash'] = hash('sha256', $existing->content ?? '');
            $attributes['revision_count'] = ($existing->revision_count ?? 1) + 1;
        } else {
            // Initialize revision_count=1 on new pages for consistent state
            $attributes['revision_count'] = 1;
        }

        // Wrap optimistic locking + write + revision insert in a transaction
        // to prevent concurrent agents from writing the same revision number
        $page = DB::transaction(function () use ($name, $attributes, $content, $agent, $sources, $existing) {
            if ($existing !== null) {
                $freshRevision = WikiPage::where('name', $name)->lockForUpdate()->value('revision_count') ?? 1;
                $expectedPrev = $attributes['revision_count'] - 1;
                if ($freshRevision !== $expectedPrev) {
                    throw new \RuntimeException(
                        "Conflict: page '{$name}' was modified concurrently (expected revision {$expectedPrev}, found {$freshRevision}). "
                        .'Fetch the current page with context_get, merge your changes, then retry.'
                    );
                }
            }

            $page = WikiPage::updateOrCreate(['name' => $name], $attributes);

            $page->update(['confidence_score' => $page->calculateConfidenceScore()]);
            $page->update(['quality_score' => $this->quality->score($content)]);

            if (! empty($sources)) {
                Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated']);
            }

            WikiPageRevision::create([
                'page_name' => $page->name,
                'revision' => $page->revision_count ?? 1,
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'agent_id' => $agent,
                'written_at' => now(),
            ]);

            return $page;
        });

        // Extract entity from page name prefix (e.g., person:cooper → Entity type=person)
        $this->graph->extractEntity($page);

        // Wire related array into knowledge graph edges
        if (! empty($page->related)) {
            $this->graph->syncReferencesFromRelated($page);
        }

        return $page;
    }

    /** The page type a name's prefix implies; no prefix means synthesis. */
    public static function typeFor(string $name): string
    {
        foreach (['person:' => 'person', 'project:' => 'project', 'concept:' => 'concept', 'decision:' => 'decision'] as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return 'synthesis';
    }
}
```

Two points to check while moving the code:

- **The in-transaction check.** In the tool, the transaction re-checked
  `isset($attributes['revision_count'])`. That key is always set, so
  `$existing !== null` is the same condition.
- **Edge cases.** Compare the moved lines against the original with `git diff`
  before deleting them. Keep every original behaviour, including how
  `description` and `title` fall back to the existing page.

- [ ] **Step 4: Make the tool delegate**

In `ContextSetTool::handle()`:

1. Replace `$type = $this->inferType($name);` with
   `$type = WikiPageWriter::typeFor($name);`.
2. Delete everything from `$attributes = [` down to and including the
   `if (! empty($page->related)) { … syncReferencesFromRelated … }` block, and
   put this in its place. `$resolvedAgent` is still used by
   `appendToWikiLog()` below.

   ```php
           // Resolve agent string once (used in revision row and wiki log)
           $resolvedAgent = $this->agentSource($request, $agentId);

           $page = app(WikiPageWriter::class)->write(
               name: $name,
               content: $content,
               agent: $resolvedAgent,
               description: $description,
               confidence: $confidence,
               sources: $sources,
               related: $related,
           );
   ```
3. Delete the private `inferType()` method.
4. Remove imports that are no longer used (`DB`, `WikiPageRevision`,
   `KnowledgeGraphService`, `QualityScoreService`, and `Drawer` if unused), and
   add `use App\Services\WikiPageWriter;`.

The expected_revision check, the trashed-page restore, `updateWikiIndex()`,
`appendToWikiLog()`, the result array and `BrainSessionLogger::log` stay in the
tool, unchanged.

- [ ] **Step 5: Run the writer and tool tests**

Run: `php artisan test --compact --filter='WikiPageWriterTest|ContextSetToolTest'`
Expected: PASS. Every existing `ContextSetToolTest` must pass without edits.

- [ ] **Step 6: Full suite, then commit**

```bash
php artisan test --compact
./vendor/bin/pint
git add app/Services/WikiPageWriter.php app/Mcp/Tools/ContextSetTool.php tests/Feature/Services/WikiPageWriterTest.php
git commit -m "refactor: wiki writes go through WikiPageWriter

The demo seeder must build pages exactly as agents do; moving the write
out of ContextSetTool gives both one path.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The synthetic brain and `DemoSeeder`

**Files:**
- Modify: `composer.json`, `composer.lock` (add `symfony/yaml`)
- Create: `database/demo/palace.yaml`
- Create: `database/demo/wiki/*.md` (ten files, listed below)
- Create: `database/seeders/DemoSeeder.php`
- Test: `tests/Feature/Demo/DemoSeederTest.php`
- Test: `tests/Feature/Demo/DemoFixtureHygieneTest.php`

**Interfaces:**
- **Consumes:**
  - `WikiPageWriter::write(...)` and `::typeFor()` (Task 4)
  - `config('mnemon.demo.fixtures')` (Task 1)
- **Produces:**
  - `Database\Seeders\DemoSeeder` with the constants `MARKER_TABLE = 'demo_marker'`
    and `AGENT = 'demo-seeder'`
  - the `demo_marker` table in a seeded database

- [ ] **Step 1: Add the YAML parser**

Run: `composer require symfony/yaml`
Expected: it installs a version compatible with the installed Symfony
components (7.4.x at the time of writing).

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Demo/DemoSeederTest.php`:

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Models\Wing;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_outside_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => false]);
        $this->expectException(\RuntimeException::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_seeded_brain_matches_what_the_real_write_path_produces(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $this->seed(DemoSeeder::class);

        $this->assertTrue(Schema::hasTable(DemoSeeder::MARKER_TABLE));
        $this->assertGreaterThanOrEqual(100, Drawer::count());
        $this->assertGreaterThanOrEqual(10, WikiPage::count());
        $this->assertEqualsCanonicalizing(['person', 'project', 'concept', 'decision', 'synthesis'],
            WikiPage::distinct()->pluck('type')->all());

        foreach (WikiPage::all() as $page) {
            $revisions = $page->revisions()->orderBy('revision')->get();
            $this->assertSame($revisions->count(), $page->revision_count, $page->name);
            $this->assertSame($page->content, $revisions->last()->content, $page->name);
            $this->assertSame(count($page->sources ?? []), $page->source_count, $page->name);
            $this->assertNotEmpty($page->sources, "{$page->name} cites no drawers");
            $this->assertNotSame($page->name, $page->title, "{$page->name} has no title");
            $this->assertTrue($page->last_compiled_at->gt(now()->subDays(config('mnemon.wiki.stale_days'))), "{$page->name} is stale");
            foreach ($page->sources as $id) {
                $this->assertSame('consolidated', Drawer::find($id)?->tier, "{$page->name} source {$id}");
            }
            foreach ($page->related ?? [] as $name) {
                $this->assertTrue(WikiPage::where('name', $name)->exists(), "{$page->name} relates to missing {$name}");
            }
        }

        $this->assertGreaterThan(0, EntityRelationship::count());
        $this->assertTrue(WikiPage::where('revision_count', '>', 1)->exists(), 'no page has history');

        $dates = Drawer::pluck('created_at');
        $this->assertGreaterThan(30, $dates->min()->diffInDays($dates->max()));

        foreach (Wing::all() as $wing) {
            if (Str::startsWith($wing->slug, ['project-', 'person-'])) {
                $this->assertTrue(WikiPage::where('name', Str::replaceFirst('-', ':', $wing->slug))->exists(), "{$wing->slug} has no wiki page");
            }
        }
    }
}
```

`tests/Feature/Demo/DemoFixtureHygieneTest.php`:

```php
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
```

If `ContentSanitizer::sanitize()` normalises whitespace or line endings, so that
clean text still comes back different, compare `trim()`med and newline-
normalised strings instead. The point is that no token pattern fires. Check the
class before deciding.

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --compact --filter='DemoSeederTest|DemoFixtureHygieneTest'`
Expected: FAIL. `DemoSeeder` does not exist, and `database/demo` is missing.

- [ ] **Step 4: Write `DemoSeeder`**

```php
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
```

- [ ] **Step 5: Write the fixtures**

This is the content of the demo, so write it with care. It is what every
visitor reads.

**`database/demo/palace.yaml`** has exactly these wings and rooms. The page
names at the end of each line are the wiki pages that wing must have.

| Wing slug | Wing name | Rooms (slug: name) | Drawers | Wiki page |
|---|---|---|---|---|
| `person-rowan` | `person:rowan` | `preferences: Preferences`, `notes: Notes` | 14 | `person:rowan` |
| `project-tidepool` | `project:tidepool` | `decisions: Decisions`, `bugs: Bugs`, `notes: Notes` | 30 | `project:tidepool` |
| `project-ledgerline` | `project:ledgerline` | `decisions: Decisions`, `bugs: Bugs`, `customers: Customers`, `notes: Notes` | 34 | `project:ledgerline` |
| `project-quarry` | `project:quarry` | `notes: Notes`, `releases: Releases` | 18 | `project:quarry` |
| `decisions` | `decisions` | `architecture: Architecture`, `tooling: Tooling` | 16 | (none) |
| `unfiled` | `unfiled` | `notes: Notes` | 8 | (none) |

**The persona.**
- **Rowan Vale** is a solo developer who works from a cabin and ships small
  products. They prefer boring technology, write terse commit messages, and run
  everything on one VPS.
- **The products:**
  - **Tidepool:** an offline-first tide-chart PWA for sea kayakers, on SQLite
    with service workers.
  - **Ledgerline:** invoicing for freelancers, with Stripe billing and a few
    dozen paying customers.
  - **Quarry:** a Rust CLI that searches shell history.
- **The collaborators:**
  - **Priya Nair** designs Ledgerline as a contractor.
  - **Tomás Ferreira** is a friend who helps with operations.

**Each drawer** is 1–4 sentences written the way a session digest records
things: a decision with its reason, a bug with its root cause, a preference, a
customer request, or a release note. Each one should be specific (numbers, file
names, error messages), and some should be dull, the way real notes are.

Per drawer:
- `days_ago` runs from 1 to 95, spread across the range.
- `source` is `claude-code:session_digest` for most drawers. Use
  `codex:session_digest` for about 20 and `manual` for about 10.
- `metadata` is `{captured_via: session_digest, harness: <claude-code|codex>,
  confidence: 0.6–0.99}`. Manual drawers use `{captured_via: manual}`.
- Unfiled drawers also carry `suggested_wing`.
- `key` is kebab-case, prefixed by the project: `tp-…`, `ll-…`, `qy-…`,
  `rowan-…`, `dec-…`, `unf-…`.

Two drawers, in the exact shape (the rest follow it):

```yaml
wings:
  - slug: project-tidepool
    name: "project:tidepool"
    description: Offline-first tide charts for sea kayakers.
    rooms:
      - slug: bugs
        name: Bugs
        drawers:
          - key: tp-sw-stale-tides
            days_ago: 41
            source: claude-code:session_digest
            metadata: {captured_via: session_digest, harness: claude-code, confidence: 0.91}
            content: |
              Tide tables went stale for a week after the v0.9 release: the service worker
              cached /api/tides with cache-first and no max-age. Switched that route to
              stale-while-revalidate with a 6-hour ceiling; the app shell stays cache-first.
          - key: tp-ios-install-prompt
            days_ago: 12
            source: codex:session_digest
            metadata: {captured_via: session_digest, harness: codex, confidence: 0.74}
            content: |
              iOS Safari never fires beforeinstallprompt, so the "Install" button did nothing
              for half the beta testers. Replaced it on iOS with a two-line Share → Add to Home
              Screen hint.
```

**`database/demo/wiki/`** has exactly these ten files:

| File | `name` | `related` | Revisions |
|---|---|---|---|
| `person-rowan.md` | `person:rowan` | `project:tidepool`, `project:ledgerline`, `project:quarry`, `concept:boring-tech-budget` | 2 earlier |
| `person-priya-nair.md` | `person:priya-nair` | `project:ledgerline` | none |
| `project-tidepool.md` | `project:tidepool` | `concept:offline-first`, `decision:sqlite-for-tidepool`, `person:rowan` | 2 earlier |
| `project-ledgerline.md` | `project:ledgerline` | `decision:stripe-billing-portal`, `person:priya-nair`, `person:rowan` | 1 earlier |
| `project-quarry.md` | `project:quarry` | `person:rowan`, `concept:boring-tech-budget` | none |
| `concept-offline-first.md` | `concept:offline-first` | `project:tidepool` | none |
| `concept-boring-tech-budget.md` | `concept:boring-tech-budget` | `decision:sqlite-for-tidepool`, `person:rowan` | 1 earlier |
| `decision-sqlite-for-tidepool.md` | `decision:sqlite-for-tidepool` | `project:tidepool`, `concept:boring-tech-budget` | none |
| `decision-stripe-billing-portal.md` | `decision:stripe-billing-portal` | `project:ledgerline` | none |
| `lessons-from-three-launches.md` | `lessons-from-three-launches` | `project:tidepool`, `project:ledgerline`, `project:quarry` | 1 earlier |

**Each page:**
- reads like a compiled Mnemon page: a one-paragraph summary, sections
  (`## Key facts`, `## Decisions`, `## Open items`, `## Timeline`), and dates
  that agree with its drawers' `days_ago`;
- cites 4–12 drawers that actually support it;
- has a current `days_ago` between 1 and 20;
- puts each earlier revision's `days_ago` 10–40 days before the next, with a
  shorter or older body;
- gives `confidence` as `high`, `medium` or `low`.

One page, in the exact shape:

```markdown
---
name: "decision:sqlite-for-tidepool"
title: SQLite for Tidepool
description: Why Tidepool's server keeps tide data in SQLite rather than Postgres.
confidence: high
days_ago: 9
sources: [dec-tp-sqlite, tp-tide-import-size, tp-backup-litestream, tp-read-only-api]
related: ["project:tidepool", "concept:boring-tech-budget"]
---
# SQLite for Tidepool

Tidepool's server stores tide predictions in a single SQLite file. The data is
written once a day by an import job and read by everyone else, which is the
workload SQLite is best at.

## Why
- The full dataset is 380 MB for 3,100 stations and grows by about 2 MB a month.
- Writes happen in one nightly import; there is no concurrent writer to plan for.
- Backups are continuous with Litestream to object storage, restored in under a
  minute in the last drill.

## What would change it
User accounts with synced trip logs would add concurrent writes. That is the
point to revisit, not before.

## Timeline
- **74 days ago:** decided, after the Postgres instance cost more than the VPS.
- **9 days ago:** the restore drill passed.
```

Write the remaining 9 pages and about 120 drawers in total. Every `sources` key
must exist in `palace.yaml`. Run the hygiene test as you go.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter='DemoSeederTest|DemoFixtureHygieneTest'`
Expected: PASS. If a fidelity assertion fails, fix the fixture or the seeder.
Never relax the assertion.

- [ ] **Step 7: Read the demo as a visitor**

Look at the result in a browser before committing:

```bash
touch /tmp/claude-0/-root-projects-mnemon/91bac7ad-a872-454d-a9c0-ef6acf995888/scratchpad/demo-preview.sqlite
MNEMON_DEMO=true APP_DEBUG=false DB_CONNECTION=sqlite \
  DB_DATABASE=/tmp/claude-0/-root-projects-mnemon/91bac7ad-a872-454d-a9c0-ef6acf995888/scratchpad/demo-preview.sqlite \
  php artisan migrate --force && \
MNEMON_DEMO=true DB_CONNECTION=sqlite \
  DB_DATABASE=/tmp/claude-0/-root-projects-mnemon/91bac7ad-a872-454d-a9c0-ef6acf995888/scratchpad/demo-preview.sqlite \
  php artisan db:seed --class='Database\Seeders\DemoSeeder' --force
```

Serve it with the same env and `php artisan serve --port=8099`. Then check
`/wiki`, every page, the history views, a few drawers, and `/palace`. Fix any
dates that disagree, sources that don't support their page, or prose that reads
as filler.

- [ ] **Step 8: Commit**

```bash
php artisan test --compact
./vendor/bin/pint
git add composer.json composer.lock database/demo database/seeders/DemoSeeder.php tests/Feature/Demo/DemoSeederTest.php tests/Feature/Demo/DemoFixtureHygieneTest.php
git commit -m "feat: a synthetic demo brain loaded through the real write path

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `mnemon:demo-reset` and the schedule

**Files:**
- Create: `app/Console/Commands/DemoResetCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Demo/DemoResetCommandTest.php`
- Check: `tests/Feature/ScheduleTimezoneTest.php`, which may need its expected
  events updated for the demo branch.

**Interfaces:**
- **Consumes:** `DemoSeeder`, `DemoSeeder::MARKER_TABLE` (Task 5), and
  `config('mnemon.demo.fixtures')`.
- **Produces:** the artisan command `mnemon:demo-reset`, which exits 0 on
  success and 1 on refusal or failure.

- [ ] **Step 1: Write the failing tests**

These tests use a real file database under `storage/framework/testing`, not
`RefreshDatabase`.

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DemoResetCommandTest extends TestCase
{
    private string $dir;

    private string $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/demo-reset');
        File::deleteDirectory($this->dir);
        File::ensureDirectoryExists($this->dir);
        $this->live = "{$this->dir}/demo.sqlite";

        config([
            'mnemon.demo.enabled' => true,
            'database.default' => 'demo_live',
            'database.connections.demo_live' => [
                'driver' => 'sqlite', 'database' => $this->live, 'prefix' => '',
                'foreign_key_constraints' => true, 'url' => null,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('demo_live');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function leftovers(): array
    {
        return array_values(array_filter(
            File::files($this->dir),
            fn ($f) => ! in_array($f->getFilename(), ['demo.sqlite', 'demo.sqlite.lock'], true),
        ));
    }

    public function test_first_reset_creates_the_live_file(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        $this->assertFileExists($this->live);
        DB::purge('demo_live');
        $this->assertGreaterThanOrEqual(10, WikiPage::count());
        $this->assertSame([], $this->leftovers());
    }

    public function test_second_reset_replaces_the_first(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        DB::purge('demo_live');
        WikiPage::query()->update(['title' => 'tampered']);
        DB::purge('demo_live');

        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        DB::purge('demo_live');
        $this->assertSame(0, WikiPage::where('title', 'tampered')->count());
    }

    public function test_failed_build_leaves_the_live_file_untouched(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        DB::purge('demo_live');
        $before = md5_file($this->live);

        config(['mnemon.demo.fixtures' => "{$this->dir}/missing"]);
        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
        $this->assertSame([], $this->leftovers());
    }

    public function test_leftover_building_file_is_replaced(): void
    {
        File::put("{$this->live}.building", 'garbage from a crashed run');
        File::put("{$this->live}.building-journal", 'x');

        $this->artisan('mnemon:demo-reset')->assertSuccessful();

        $this->assertSame([], $this->leftovers());
    }

    public function test_refuses_when_demo_mode_is_off(): void
    {
        $this->artisan('mnemon:demo-reset')->assertSuccessful();
        $before = md5_file($this->live);
        config(['mnemon.demo.enabled' => false]);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
    }

    public function test_refuses_a_non_sqlite_connection(): void
    {
        config(['database.connections.demo_live.driver' => 'pgsql']);
        $this->artisan('mnemon:demo-reset')->assertFailed();
        $this->assertFileDoesNotExist($this->live);
    }

    public function test_refuses_a_file_outside_storage(): void
    {
        $outside = sys_get_temp_dir().'/mnemon-demo-reset-'.uniqid().'.sqlite';
        config(['database.connections.demo_live.database' => $outside]);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertFileDoesNotExist($outside);
    }

    public function test_refuses_to_replace_a_database_it_did_not_build(): void
    {
        $pdo = new \PDO("sqlite:{$this->live}");
        $pdo->exec('CREATE TABLE drawers (id INTEGER PRIMARY KEY, content TEXT)');
        $pdo->exec("INSERT INTO drawers (content) VALUES ('a real memory')");
        $pdo = null;
        $before = md5_file($this->live);

        $this->artisan('mnemon:demo-reset')->assertFailed();

        $this->assertSame($before, md5_file($this->live));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=DemoResetCommandTest`
Expected: FAIL, because the command `mnemon:demo-reset` is not defined.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Rebuilds the public demo's brain (docs/design/2026-10-13-public-demo-design.md §4).
 *
 * The same command on a real install would erase the brain, so it refuses
 * unless demo mode is on, the database is a SQLite file inside storage/, and
 * any existing file is one this command built. It builds beside the live file
 * and renames over it, so a visitor sees the old brain or the new one, never
 * an empty or half-seeded one.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'mnemon:demo-reset';

    protected $description = 'Rebuild the public demo brain from its fixtures and swap it in (demo mode only)';

    private const SIDECARS = ['-journal', '-wal', '-shm'];

    public function handle(): int
    {
        if (($refusal = $this->refusal()) !== null) {
            $this->error("Refusing to reset: {$refusal}.");

            return self::FAILURE;
        }

        $live = (string) config('database.connections.'.config('database.default').'.database');
        $building = "{$live}.building";
        $lock = fopen("{$live}.lock", 'c');
        flock($lock, LOCK_EX);

        try {
            $this->remove($building);
            touch($building);

            config(['database.connections.demo_build' => array_merge(
                config('database.connections.'.config('database.default')),
                ['database' => $building, 'url' => null, 'journal_mode' => 'delete'],
            )]);

            $this->runStep('migrate', ['--database' => 'demo_build', '--force' => true]);
            // db:seed --database makes demo_build the default connection while
            // the seeder runs; calling the seeder directly would write to the live file.
            $this->runStep('db:seed', ['--class' => DemoSeeder::class, '--database' => 'demo_build', '--force' => true]);

            DB::purge('demo_build');
            DB::purge(config('database.default'));
            if (! rename($building, $live)) {
                throw new RuntimeException("could not move {$building} over {$live}");
            }
            foreach (self::SIDECARS as $suffix) {
                @unlink($live.$suffix);
            }

            $this->callSilently('cache:clear');
        } catch (Throwable $e) {
            DB::purge('demo_build');
            $this->remove($building);
            $this->error('Demo reset failed; the live demo is unchanged: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->info('Demo brain rebuilt.');

        return self::SUCCESS;
    }

    /** Why this database must not be replaced, or null if it may be. */
    private function refusal(): ?string
    {
        if (! config('mnemon.demo.enabled')) {
            return 'demo mode is off (MNEMON_DEMO is not true), and this would erase the brain';
        }

        $connection = (array) config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'sqlite') {
            return 'the default database connection is not SQLite';
        }

        $live = (string) ($connection['database'] ?? '');
        $dir = $live === '' || $live === ':memory:' ? false : realpath(dirname($live));
        $storage = realpath(storage_path());
        // realpath on both sides: zero-downtime releases reach storage/ through a symlink.
        if ($dir === false || $storage === false || ! str_starts_with($dir.DIRECTORY_SEPARATOR, $storage.DIRECTORY_SEPARATOR)) {
            return 'the database file is not inside storage/';
        }

        if (is_file($live) && ! $this->builtByUs($live)) {
            return 'the existing database was not built by mnemon:demo-reset (it has no '.DemoSeeder::MARKER_TABLE.' table)';
        }

        return null;
    }

    private function builtByUs(string $path): bool
    {
        try {
            $pdo = new PDO("sqlite:{$path}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $found = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '".DemoSeeder::MARKER_TABLE."'")->fetchColumn();

            return $found !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function runStep(string $command, array $arguments): void
    {
        if ($this->call($command, $arguments) !== self::SUCCESS) {
            throw new RuntimeException("{$command} failed");
        }
    }

    private function remove(string $path): void
    {
        foreach (['', ...self::SIDECARS] as $suffix) {
            @unlink($path.$suffix);
        }
    }
}
```

The helper is `runStep()`, not `run()`, because Symfony's `Command` already
defines `run()`.

If `db:seed --database` does not switch the default connection in this Laravel
version, the test that checks the live file holds the brain fails. Check
`vendor/laravel/framework/src/Illuminate/Database/Console/Seeds/SeedCommand.php`.
If it doesn't switch, wrap the seeding in
`$previous = DB::getDefaultConnection(); DB::setDefaultConnection('demo_build');
try { $this->runStep('db:seed', ['--class' => DemoSeeder::class, '--force' => true]); }
finally { DB::setDefaultConnection($previous); }`.

- [ ] **Step 4: Schedule by mode**

In `routes/console.php`, wrap the two existing `Schedule::command(...)`
statements (decay-confidence and apply-retention, keeping the comments between
them) in an `if (! config('mnemon.demo.enabled')) { … }` block. Then add:

```php
// The public demo (MNEMON_DEMO=true) changes no data on a schedule: decay and
// retention refuse to run there. Its brain is rebuilt from fixtures each night
// instead, which also resets timestamps and the rate limiter's cache.
if (config('mnemon.demo.enabled')) {
    Schedule::command('mnemon:demo-reset')
        ->dailyAt('04:00')
        ->timezone(config('app.timezone'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/scheduled.log'));
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter='DemoResetCommandTest|ScheduleTimezoneTest'`
Expected: PASS. Demo mode is off in tests by default, so `ScheduleTimezoneTest`
still sees the two production events.

- [ ] **Step 6: Commit**

```bash
php artisan test --compact
./vendor/bin/pint
git add app/Console/Commands/DemoResetCommand.php routes/console.php tests/Feature/Demo/DemoResetCommandTest.php
git commit -m "feat: mnemon:demo-reset rebuilds the demo brain and swaps it in atomically

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: The demo's UI

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`)
- Modify: `resources/views/partials/colophon.blade.php`
- Modify: `resources/views/landing/index.blade.php` (lines ~142, ~388, ~603, ~647, ~675)
- Modify: `resources/views/palace/index.blade.php` (the "Open admin" link, ~line 41)
- Modify: `resources/views/explain/how-it-works.blade.php` (~line 191 and the
  `<caption>` at ~311)
- Modify: `app/Http/Controllers/PalaceController.php` (`wing()`)
- Test: `tests/Feature/Demo/DemoUiTest.php`

**Interfaces:**
- **Produces:** the Blade conditions `@demo … @enddemo` and
  `@browsable … @endbrowsable`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Demo;

use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUiTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): void
    {
        config(['mnemon.demo.enabled' => true, 'mnemon.demo.host' => null, 'app.debug' => false]);
    }

    public function test_landing_in_demo_mode(): void
    {
        $this->demo();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('read-only sample brain', $html);
        $this->assertStringContainsString('href="'.route('wiki.index').'"', $html);
        $this->assertStringContainsString('href="'.route('palace.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
        $this->assertStringNotContainsString('action="'.route('contact.store').'"', $html);
        $this->assertStringContainsString('github.com/coopers98/mnemon/issues', $html);
    }

    public function test_palace_index_hides_admin_in_demo_mode(): void
    {
        $this->demo();
        Wing::factory()->create();
        $this->get('/palace')->assertOk()->assertDontSee('Open admin');
    }

    public function test_how_it_works_says_demo(): void
    {
        $this->demo();
        $this->get('/how-it-works')->assertOk()->assertSee('This demo holds')->assertDontSee('This instance currently holds');
    }

    public function test_wing_links_to_its_wiki_page(): void
    {
        $this->demo();
        $wing = Wing::factory()->create(['slug' => 'project-alpha', 'name' => 'project:alpha']);
        Room::factory()->create(['wing_id' => $wing->id]);
        WikiPage::factory()->create(['name' => 'project:alpha']);
        WikiPage::factory()->create(['name' => 'project:alpha-two']);

        $this->get('/palace/project-alpha')->assertOk()
            ->assertSee('href="'.route('wiki.show', 'project:alpha').'"', false);
    }

    public function test_real_install_is_unchanged_and_links_the_demo_when_configured(): void
    {
        config(['mnemon.demo.enabled' => false, 'mnemon.demo.url' => 'https://trymnemon.com']);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('read-only sample brain', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('action="'.route('contact.store').'"', $html);
        $this->assertStringContainsString('href="https://trymnemon.com"', $html);
        $this->get('/how-it-works')->assertSee('This instance currently holds');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=DemoUiTest`
Expected: FAIL.

- [ ] **Step 3: Blade conditions**

In `AppServiceProvider::boot()` add, with `use Illuminate\Support\Facades\Blade;`:

```php
        // The public demo: @demo for demo-only markup, @browsable where a
        // signed-in user and a demo visitor both get the wiki and palace.
        Blade::if('demo', fn () => (bool) config('mnemon.demo.enabled'));
        Blade::if('browsable', fn () => auth()->check() || (bool) config('mnemon.demo.enabled'));
```

- [ ] **Step 4: Colophon**

In `partials/colophon.blade.php`:

1. Insert at the top, before `<header class="colophon">`:

   ```blade
   @demo
       <div role="note" style="background:var(--ink);color:var(--paper);font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;text-align:center;padding:0.5rem 16px;">
           Demo · a read-only sample brain.
           <a href="{{ route('landing') }}#install" style="color:inherit;border-bottom:1px solid currentColor;">Run your own →</a>
       </div>
   @enddemo
   ```

2. In the nav, change `@auth` … `@else` … `@endauth` (the Wiki/Palace block) to
   `@browsable` … `@else` … `@endbrowsable`.
3. After the "Self-host" link in the nav, add:

   ```blade
               @if (! config('mnemon.demo.enabled') && config('mnemon.demo.url'))
                   <a href="{{ config('mnemon.demo.url') }}">Demo</a>
               @endif
   ```

4. Replace the whole `<div class="nav-meta">…</div>` contents with:

   ```blade
               @demo
                   <span>MIT · read-only demo</span>
               @else
                   @auth
                       <span><span class="rubric">●</span> {{ auth()->user()->name }}</span>
                       <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                           @csrf
                           <button type="submit" class="btn-bare">log out</button>
                       </form>
                   @else
                       <span>MIT · self-hosted</span>
                       <a class="btn-bare" href="{{ route('login') }}">sign in</a>
                   @endauth
               @enddemo
   ```

- [ ] **Step 5: Landing**

In `landing/index.blade.php`:

1. **Front CTA** (`<div class="front-cta">`, ~line 142): change `@auth` /
   `@endauth` to `@browsable` / `@endbrowsable`. Demo visitors then get "Open
   the wiki" and "Walk the palace", the same as a signed-in user.
2. **Palace section CTA** (~line 388): the same change.
3. **Closing CTA** (~line 647): the same change.
4. **Footer "Pages" list** (~line 675): the same change.
5. **Contact form** (~line 599): wrap from `@if (session('contact_success'))`
   through the closing `</form>` like this:

   ```blade
                   @demo
                       <p style="font-family:var(--serif);font-size:1.1rem;line-height:1.65;color:var(--ink-soft);">
                           This demo is read-only, so it takes no messages. For questions, ideas and bugs, open an
                           issue on <a href="https://github.com/coopers98/mnemon/issues" rel="noopener noreferrer" style="color:var(--rubric);border-bottom:1px solid var(--rubric);">GitHub</a>.
                       </p>
                   @else
                       {{-- existing success message and <form> unchanged --}}
                   @enddemo
   ```

6. In the guest branch of the front CTA (the `@else` of `@browsable`), add after
   the "Sign in" button:

   ```blade
                               @if (config('mnemon.demo.url'))
                                   <a class="btn btn-ghost" href="{{ config('mnemon.demo.url') }}">
                                       <span>See the demo</span><span class="arrow">→</span>
                                   </a>
                               @endif
   ```

- [ ] **Step 6: Palace index, how-it-works, wing link**

1. **`palace/index.blade.php`:** wrap the `<a class="btn" href="/admin" …>Open
   admin…</a>` in `@unless (config('mnemon.demo.enabled'))` …
   `@endunless`.
2. **`explain/how-it-works.blade.php`:**
   - Replace `<p>This instance currently holds` with:

     ```blade
                     <p>{{ config('mnemon.demo.enabled') ? 'This demo holds' : 'This instance currently holds' }}
     ```

     and, just before that paragraph's closing `</p>`, add:

     ```blade
                         @demo The brain is synthetic: a made-up developer's notes, written for this demo. @enddemo
     ```

   - Replace `<caption>This instance, live</caption>` with
     `<caption>{{ config('mnemon.demo.enabled') ? 'This demo, live' : 'This instance, live' }}</caption>`.
3. **`PalaceController::wing()`:** replace the `like` lookup with:

   ```php
           // A wing's page is named like the wing with its first '-' as ':'
           // (project-x → project:x). The old `like '%project-x%'` never
           // matched a page name, so the link never appeared.
           $wikiPage = WikiPage::where('name', Str::replaceFirst('-', ':', $wing->slug))->first();
   ```

   and add `use Illuminate\Support\Str;`.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact`
Expected: all PASS, including `LandingPageTest`, `ExplainPageTest` and
`WikiFrontendTest`. If an existing palace test relied on the old `like`
matching, update its fixture to use a `project:x` page name, and say so in the
commit message.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add app/Providers/AppServiceProvider.php resources/views app/Http/Controllers/PalaceController.php tests/Feature/Demo/DemoUiTest.php
git commit -m "feat: the demo's UI: banner, open navigation, no sign-in or contact form

Also fixes the wing page's wiki link, which never matched a page name.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Documentation

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `.env.example`, `docs/USERGUIDE.md`
- Modify: `docs/design/2026-10-13-public-demo-design.md`. Two lines must match
  what was built:
  - §2: the landing calls to action show "Open the wiki" and "Walk the
    palace".
  - §3: the page type comes from the name prefix, so there is no `type` front
    matter.

- [ ] **Step 1: Write the docs**

- **`README.md`:** add after the first paragraph:
  `**Try it:** a read-only demo with a sample brain is at https://trymnemon.com.`
- **`CHANGELOG.md`:** under the unreleased section, add these entries in the
  file's existing style:
  - **Added:** demo mode (`MNEMON_DEMO`), `mnemon:demo-reset`, `TRUSTED_PROXIES`,
    and `MNEMON_DEMO_URL`.
  - **Changed:** wiki writes go through `WikiPageWriter`.
  - **Fixed:** `/wiki/search?q[]=` returned 500, and the wing page's wiki link
    never appeared.
- **`.env.example`:** add commented lines:

  ```dotenv
  # Public read-only demo (see docs/USERGUIDE.md, "Running a public demo").
  # MNEMON_DEMO=false
  # MNEMON_DEMO_HOST=
  # MNEMON_DEMO_URL=
  # Comma-separated proxy addresses/CIDRs whose X-Forwarded-For is trusted.
  # TRUSTED_PROXIES=
  ```

- **`docs/USERGUIDE.md`:** add a short section, "Running a public demo". It
  should say:
  - what demo mode does;
  - that it needs SQLite inside `storage/`, `APP_DEBUG=false` and
    `MNEMON_DEMO_HOST`;
  - that the deploy runs `mnemon:demo-reset` in place of `migrate`;
  - that the fixtures live in `database/demo/`.

- [ ] **Step 2: Commit**

```bash
git add README.md CHANGELOG.md .env.example docs/USERGUIDE.md docs/design/2026-10-13-public-demo-design.md
git commit -m "docs: the public demo

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Deploy trymnemon.com

This is operations, not code. It runs only after the user has approved pushing
Tasks 1–8 to `main`.

All API calls read keys from `~/.mnemon/deploy-tokens.env`. **Never print
them.**
- Forge base: `https://forge.laravel.com/api/orgs/cooper-sellers/servers/1153034`.
  Send these headers: `Authorization: Bearer $FORGE_TOKEN`,
  `Accept: application/json`, `Content-Type: application/json`,
  `User-Agent: curl/8.5.0`.
- Pipe every Forge response through `jq` to the fields you need: site objects
  contain a deploy-trigger token.
- The OpenAPI spec is at https://forge.laravel.com/api/docs.openapi. Check each
  request body there before sending it.

- [ ] **Step 1: Preconditions**

- `php artisan test --compact` passes on `main`, and `git status` is clean.
- The user has approved `git push origin main`. Push, then confirm the
  production deploy of mnemon.cooperhosting.net still serves `/` (200) and
  `/wiki` (redirects to login).

- [ ] **Step 2: Create the isolated site on a hostname with no DNS**

`build.trymnemon.com` has no DNS record, so nothing public can reach it while
it is unconfigured. Send `POST /sites` with:

```json
{"type":"laravel","domain_mode":"custom","name":"build.trymnemon.com","www_redirect_type":"none",
 "allow_wildcard_subdomains":false,"php_version":"php84","zero_downtime_deployments":true,
 "shared_paths":[{"from":"storage","to":"storage"}],"is_isolated":true,"isolated_user":"trymnemon",
 "source_control_provider":"github","repository":"coopers98/mnemon","branch":"main",
 "push_to_deploy":false,"install_composer_dependencies":false}
```

- **Record:** the new site id. Poll the site list until its status is
  `installed`.
- **If Forge deployed on creation:** that deploy ran with no demo `.env`. It is
  unreachable (no DNS), and Step 6 replaces it, so carry on. Still, record that
  it happened.
- **Web directory:** check the site's `web_directory`. If it is not
  `…/current/public`, set it with `PUT /sites/{id}` and `{"directory":"/public"}`.

- [ ] **Step 3: Environment**

Generate a key locally: `php artisan key:generate --show`. Get the current
Cloudflare ranges:

```bash
curl -s https://www.cloudflare.com/ips-v4; curl -s https://www.cloudflare.com/ips-v6
```

Join them with commas, and record the date. Then
`PUT /sites/{id}/environment` with `{"environment": "<content>"}`, where the
content is `.env.example` with these lines changed or added:

```dotenv
APP_NAME=Mnemon
APP_ENV=production
APP_KEY=<generated>
APP_DEBUG=false
APP_URL=https://trymnemon.com
LOG_STACK=daily
LOG_DAILY_DAYS=7
LOG_LEVEL=warning
DB_CONNECTION=sqlite
DB_DATABASE=/home/trymnemon/build.trymnemon.com/storage/demo.sqlite
SESSION_DRIVER=cookie
CACHE_STORE=file
MNEMON_DEMO=true
MNEMON_DEMO_HOST=build.trymnemon.com
MNEMON_EMBEDDING_DRIVER=none
TRUSTED_PROXIES=<comma-joined Cloudflare ranges>
```

Also remove every `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` line,
any `OPENAI_API_KEY`, and every `MAIL_` credential. Confirm the site's actual
directory with
`ssh forge@165.245.153.92 'sudo -n true' || ls /home/trymnemon`. If the forge
user cannot list it, read the root directory from the site's API object, and
fix `DB_DATABASE` to `<root>/storage/demo.sqlite`, where `<root>` is the
directory above `current`.

- [ ] **Step 4: Deploy script**

`PUT /sites/{id}/deployments/script`. Check the body field name in the
OpenAPI spec. The content is:

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci || npm install
npm run build
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link

# The demo has no migrate step: demo-reset builds the schema and the brain in a
# fresh SQLite file and swaps it in, right before the release goes live.
$FORGE_PHP artisan mnemon:demo-reset --no-interaction

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

- [ ] **Step 5: nginx, FPM and scheduler**

- **nginx allowlist.** `GET /sites/{id}/nginx`, and save the original to the
  scratchpad. In every `server {` block for this site, after `server_name`,
  insert `allow 127.0.0.1;`, one `allow <range>;` per Cloudflare range, and
  `deny all;`. Then `PUT` it back. Expect Forge to reload nginx. Verify:
  - from this machine (not Cloudflare),
    `curl -sk -o /dev/null -w '%{http_code}' -H 'Host: build.trymnemon.com' https://165.245.153.92/`
    returns 403;
  - over SSH from the server, `curl -H 'Host: build.trymnemon.com' http://127.0.0.1/`
    is not 403.
- **FPM cap.** Create a recipe (`POST /orgs/cooper-sellers/recipes`,
  `user: root`) with this script:

  ```bash
  f=$(grep -l '^user = trymnemon' /etc/php/8.4/fpm/pool.d/*.conf)
  sed -i 's/^pm.max_children = .*/pm.max_children = 4/' "$f"
  grep '^pm.max_children' "$f"
  service php8.4-fpm reload
  ```

  Run it on server 1153034, and read the run's output. It must print
  `pm.max_children = 4`.
- **Scheduler.** `POST /sites/{id}/integrations/laravel-scheduler`.

- [ ] **Step 6: Deploy and verify on the hidden hostname**

- `POST /sites/{id}/deployments`, then poll `/deployments/status` and read the
  deployment log. The `mnemon:demo-reset` step must print
  `Demo brain rebuilt.`
- Then, over SSH on the server:

  ```bash
  H='Host: build.trymnemon.com'
  for p in / /how-it-works /wiki /palace /up; do echo "$p $(curl -s -o /dev/null -w '%{http_code}' -H "$H" http://127.0.0.1$p)"; done
  for p in /admin /login /mcp /oauth/authorize; do curl -s -D - -o /dev/null -H "$H" http://127.0.0.1$p | grep -iE '^HTTP|x-mnemon-demo'; done
  curl -s -D - -o /dev/null -X POST -H "$H" http://127.0.0.1/wiki | grep -iE '^HTTP|x-mnemon-demo'
  ```

  Expected: the first loop prints 200 for each path. Every other request prints
  404 with `X-Mnemon-Demo: refused`. HTTP may redirect to HTTPS; if so, use
  `-k https://127.0.0.1` with the same `Host` header.

- [ ] **Step 7: Move trymnemon.com onto the new site**

1. **Delete the placeholder site:** `DELETE /sites/3425569`. This frees the
   domain, and trymnemon.com is down from here until step 6.
2. **Add the domain:** `POST /sites/{id}/domains` with
   `{"name":"trymnemon.com","www_redirect_type":"from-www","allow_wildcard_subdomains":false}`.
   Look up `POST …/domains/{domainRecord}/actions` in the OpenAPI spec to make
   it primary, then remove `build.trymnemon.com`.
3. **Certificate:** `POST …/domains/{domainRecord}/certificates` with
   `{"type":"letsencrypt","letsencrypt":{"verification_method":"dns-01","key_type":"ecdsa"},"enable":true}`.
   Read the DNS record Forge asks for (`GET …/certificates/{cert}`), then create
   it in Cloudflare with the DNS-edit token. The zone id is
   `b5547b3b954834bf13d44699b817706e`, and the record must be DNS-only. Poll
   until the certificate is `installed`.
   - If dns-01 is not workable, use the fallback. Set both A records to
     DNS-only. Add `location ^~ /.well-known/acme-challenge/ { allow all; }` to
     the nginx config. Issue with http-01. Then turn the proxy back on.
4. **Point the env at the real host:** `MNEMON_DEMO_HOST=trymnemon.com`, and
   `DB_DATABASE` unchanged (the directory keeps its build name). `PUT` the
   environment.
5. **Push-to-deploy:** turn it on (`POST …/deployments/push-to-deploy`).
6. **Redeploy:** `POST …/deployments`, then confirm that demo-reset succeeded.

- [ ] **Step 8: Verify through Cloudflare**

From this machine:

```bash
for p in / /how-it-works /wiki /palace; do echo "$p $(curl -s -o /dev/null -w '%{http_code}' https://trymnemon.com$p)"; done
curl -sI https://trymnemon.com/wiki | grep -iE '^server|x-robots-tag'
curl -s -D - -o /dev/null https://trymnemon.com/admin | grep -iE '^HTTP|x-mnemon-demo'
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' https://www.trymnemon.com/
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: trymnemon.com' -k https://165.245.153.92/
curl -s -o /dev/null -w '%{http_code}\n' https://mnemon.cooperhosting.net/
```

Expected:
- each page returns 200;
- `/wiki` shows `server: cloudflare` and `x-robots-tag: noindex`;
- `/admin` returns 404 with the refused header;
- `www` returns 301 to the apex;
- a direct origin request returns 403;
- production returns 200.

Open https://trymnemon.com in a browser, and walk the wiki and palace once.

- [ ] **Step 9: Record and hand over**

- **Memory.** Update `mnemon-release-state.md` with the new site id, the
  directory, the cert, and the date of the Cloudflare range list. Remove
  `releases/000-placeholder` references from the spec's §4 in a docs commit.
- **Tell the user** what is left for them in the Cloudflare dashboard:
  - SSL mode Full (strict);
  - Always Use HTTPS;
  - one rate-limiting rule (for example, 300 requests per 10 seconds per IP on
    `trymnemon.com/*`).
