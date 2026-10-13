<?php

namespace Tests\Feature\Demo;

use App\Http\Middleware\DemoMode;
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
        $this->assertSame('203.0.113.5', DemoMode::clientKey('203.0.113.5'));
        $this->assertSame('2001:db8:1:2::/64', DemoMode::clientKey('2001:db8:1:2:3:4:5:6'));
    }
}
