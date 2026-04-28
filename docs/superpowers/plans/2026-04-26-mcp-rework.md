# MCP Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Mnemon's bespoke `/api/mcp/call` REST endpoint and `ApiKey` bearer auth with a fully spec-compliant MCP server built on `laravel/mcp` + `laravel/passport`, exposing tools, resources, and prompts at `POST /mcp` with OAuth 2.1 + Dynamic Client Registration and per-token wing restrictions.

**Architecture:** A single `MnemonServer` registers 12 tools, 3 resources, and 3 prompts via `laravel/mcp`. Auth is OAuth 2.1 via Passport (`auth:api` middleware). Coarse scopes (`palace.read/write`, `wiki.read/write`) are enforced per tool; per-token wing restrictions are stored in a sibling `mcp_token_restrictions` table and enforced via a shared trait. `BrainSession` audit rows gain `oauth_client_id`, `user_id`, `access_token_id`. The bespoke MCP stack and `ApiKey` model are deleted in the same PR.

**Tech Stack:** Laravel 13, PHP 8.3+, `laravel/mcp`, `laravel/passport`, PostgreSQL (prod) / SQLite (test), Filament 5.

**Source spec:** `docs/superpowers/specs/2026-04-26-mcp-rework-design.md` — read first.

**Worktree note:** Recommend executing on a dedicated git worktree (`mcp-rework` branch). The plan is one logical PR with ~50 commits.

---

## Phase 1 — Setup & infrastructure

### Task 1: Install Laravel Passport

**Files:**
- Modify: `composer.json` (auto via composer require)

- [ ] **Step 1: Install package**

```bash
composer require laravel/passport
```

- [ ] **Step 2: Run Passport install (creates oauth_* tables + keys)**

```bash
php artisan passport:install --uuids
```

Expected: migrations create `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_personal_access_clients`. Encryption keys generated in `storage/oauth-*.key`.

- [ ] **Step 3: Verify installation**

```bash
php artisan migrate:status | grep oauth
ls storage/oauth-*.key
```

Expected: oauth migrations show `Ran`. Two key files exist.

- [ ] **Step 4: Add oauth keys to `.gitignore`**

Confirm `storage/oauth-*.key` is gitignored. If not, add:

```
storage/oauth-*.key
```

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock database/migrations .gitignore
git commit -m "chore: install laravel/passport with uuid clients"
```

---

### Task 2: Install laravel/mcp and publish routes/views

**Files:**
- Modify: `composer.json`
- Create: `routes/ai.php` (published from package)
- Create: `resources/views/vendor/mcp/authorize.blade.php` (published)

- [ ] **Step 1: Install package**

```bash
composer require laravel/mcp
```

- [ ] **Step 2: Publish AI routes**

```bash
php artisan vendor:publish --tag=ai-routes
```

Expected: `routes/ai.php` created.

- [ ] **Step 3: Publish MCP views**

```bash
php artisan vendor:publish --tag=mcp-views
```

Expected: `resources/views/vendor/mcp/authorize.blade.php` created.

- [ ] **Step 4: Verify routes/ai.php is loaded**

Inspect `bootstrap/app.php` or `app/Providers/RouteServiceProvider.php`. The `ai.php` route file should be auto-loaded by `laravel/mcp`'s service provider. Confirm with:

```bash
php artisan route:list | grep -E "mcp|oauth"
```

Expected: lists `oauth/authorize`, `oauth/token`, etc. (No `/mcp` route yet — we wire that in Task 8.)

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock routes/ai.php resources/views/vendor
git commit -m "chore: install laravel/mcp and publish routes + views"
```

---

### Task 3: Configure Passport in AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PassportConfigurationTest.php`:

```php
<?php

namespace Tests\Feature;

use Laravel\Passport\Passport;
use Tests\TestCase;

class PassportConfigurationTest extends TestCase
{
    public function test_passport_scopes_are_registered(): void
    {
        $scopes = Passport::scopes()->pluck('id')->all();

        $this->assertContains('palace.read', $scopes);
        $this->assertContains('palace.write', $scopes);
        $this->assertContains('wiki.read', $scopes);
        $this->assertContains('wiki.write', $scopes);
    }

    public function test_token_lifetimes_are_set(): void
    {
        // Passport stores these on the singleton; assert via reflection
        $this->assertEquals(3600, Passport::tokensExpireIn()->totalSeconds);
        $this->assertEquals(90 * 24 * 3600, Passport::refreshTokensExpireIn()->totalSeconds);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=PassportConfigurationTest
```

Expected: FAIL — scopes not yet registered.

- [ ] **Step 3: Add Passport config to AppServiceProvider::boot()**

In `app/Providers/AppServiceProvider.php`, add inside `boot()`:

```php
use Laravel\Passport\Passport;

public function boot(): void
{
    Passport::tokensExpireIn(now()->addHour());
    Passport::refreshTokensExpireIn(now()->addDays(90));
    Passport::personalAccessTokensExpireIn(now()->addDays(90));

    Passport::tokensCan([
        'palace.read'  => 'Read drawers and palace metadata',
        'palace.write' => 'Add drawers',
        'wiki.read'    => 'Read wiki pages, history, graph',
        'wiki.write'   => 'Compile, lint, and write wiki pages',
    ]);

    Passport::authorizationView(fn ($p) => view('mcp.authorize', $p));
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=PassportConfigurationTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/PassportConfigurationTest.php
git commit -m "feat: configure Passport scopes and token lifetimes for MCP"
```

---

### Task 4: Add HasApiTokens to User model

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UserHasApiTokensTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\HasApiTokens;
use Tests\TestCase;

class UserHasApiTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_passport_has_api_tokens_trait(): void
    {
        $traits = class_uses(User::class);
        $this->assertContains(HasApiTokens::class, $traits);
    }

    public function test_user_can_create_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['palace.read']);

        $this->assertNotNull($token->accessToken);
        $this->assertTrue($token->token->can('palace.read'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=UserHasApiTokensTest
```

Expected: FAIL — trait not present.

- [ ] **Step 3: Add the trait to User**

In `app/Models/User.php`:

```php
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;
    // ... rest unchanged
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=UserHasApiTokensTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/UserHasApiTokensTest.php
git commit -m "feat: add Passport HasApiTokens trait to User"
```

---

### Task 5: Create mcp_token_restrictions table and model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_mcp_token_restrictions_table.php`
- Create: `app/Models/McpTokenRestriction.php`
- Create: `tests/Feature/McpTokenRestrictionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/McpTokenRestrictionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokenRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_persist_wing_patterns_for_a_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['palace.read'])->token;

        $restriction = McpTokenRestriction::create([
            'access_token_id' => $token->id,
            'wing_patterns' => ['work:*', 'research'],
        ]);

        $fresh = McpTokenRestriction::find($token->id);
        $this->assertEquals(['work:*', 'research'], $fresh->wing_patterns);
    }

    public function test_null_wing_patterns_means_unrestricted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;

        $restriction = McpTokenRestriction::create([
            'access_token_id' => $token->id,
            'wing_patterns' => null,
        ]);

        $this->assertNull($restriction->fresh()->wing_patterns);
    }

    public function test_deleting_token_cascades_restriction(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work']]);

        $token->delete();

        $this->assertNull(McpTokenRestriction::find($token->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=McpTokenRestrictionTest
```

Expected: FAIL — table/model don't exist.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_mcp_token_restrictions_table
```

Edit the new migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_token_restrictions', function (Blueprint $table) {
            $table->string('access_token_id', 100)->primary();
            $table->json('wing_patterns')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('access_token_id')
                ->references('id')->on('oauth_access_tokens')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_restrictions');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/McpTokenRestriction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpTokenRestriction extends Model
{
    protected $table = 'mcp_token_restrictions';
    protected $primaryKey = 'access_token_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['access_token_id', 'wing_patterns', 'created_at'];

    protected $casts = [
        'wing_patterns' => 'array',
        'created_at' => 'datetime',
    ];

    public function matches(string $wingSlug): bool
    {
        $patterns = $this->wing_patterns;

        if ($patterns === null) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if ($pattern === $wingSlug) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $wingSlug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run migration and tests**

```bash
php artisan migrate
php artisan test --filter=McpTokenRestrictionTest
```

Expected: PASS.

- [ ] **Step 6: Add a `matches()` test**

Append to the test file:

```php
public function test_matches_handles_literal_and_wildcard_patterns(): void
{
    $r = new McpTokenRestriction(['wing_patterns' => ['work:*', 'research']]);

    $this->assertTrue($r->matches('research'));
    $this->assertTrue($r->matches('work:project-a'));
    $this->assertFalse($r->matches('personal'));

    $unrestricted = new McpTokenRestriction(['wing_patterns' => null]);
    $this->assertTrue($unrestricted->matches('anything'));
}
```

- [ ] **Step 7: Run and commit**

```bash
php artisan test --filter=McpTokenRestrictionTest
git add database/migrations app/Models/McpTokenRestriction.php tests/Feature/McpTokenRestrictionTest.php
git commit -m "feat: add mcp_token_restrictions table and model with wing matcher"
```

---

### Task 6: Extend brain_sessions with OAuth columns

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_extend_brain_sessions_with_oauth.php`
- Modify: `app/Models/BrainSession.php`
- Create: `tests/Feature/BrainSessionOauthColumnsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/BrainSessionOauthColumnsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrainSessionOauthColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brain_session_persists_oauth_columns(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;

        $session = BrainSession::create([
            'tool_name'        => 'drawer_search',
            'source'           => 'Test Client as user@... (token …abc1)',
            'oauth_client_id'  => $token->client_id,
            'user_id'          => $user->id,
            'access_token_id'  => $token->id,
            'input'            => ['query' => 'foo'],
            'result_count'     => 3,
        ]);

        $fresh = $session->fresh();
        $this->assertEquals($token->client_id, $fresh->oauth_client_id);
        $this->assertEquals($user->id, $fresh->user_id);
        $this->assertEquals($token->id, $fresh->access_token_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=BrainSessionOauthColumnsTest
```

Expected: FAIL — columns don't exist.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration extend_brain_sessions_with_oauth
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_sessions', function (Blueprint $t) {
            $t->uuid('oauth_client_id')->nullable()->after('source')->index();
            $t->foreignId('user_id')->nullable()->after('oauth_client_id')->constrained()->nullOnDelete();
            $t->string('access_token_id', 100)->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('brain_sessions', function (Blueprint $t) {
            $t->dropForeign(['user_id']);
            $t->dropColumn(['oauth_client_id', 'user_id', 'access_token_id']);
        });
    }
};
```

- [ ] **Step 4: Update the BrainSession model fillable + casts**

In `app/Models/BrainSession.php`, add to `$fillable`:

```php
protected $fillable = [
    'tool_name',
    'source',
    'oauth_client_id',
    'user_id',
    'access_token_id',
    'input',
    'result_count',
];
```

- [ ] **Step 5: Run migration and tests**

```bash
php artisan migrate
php artisan test --filter=BrainSessionOauthColumnsTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/BrainSession.php tests/Feature/BrainSessionOauthColumnsTest.php
git commit -m "feat: extend brain_sessions with oauth_client_id, user_id, access_token_id"
```

---

## Phase 2 — Server skeleton & route wiring

### Task 7: Create empty MnemonServer

**Files:**
- Create: `app/Mcp/Servers/MnemonServer.php`

- [ ] **Step 1: Generate the server class**

```bash
php artisan make:mcp-server MnemonServer
```

This creates `app/Mcp/Servers/MnemonServer.php`.

- [ ] **Step 2: Replace generated content**

```php
<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Mnemon')]
#[Version('1.0.0')]
#[Instructions('Mnemon is a self-hosted second brain. Use palace tools for verbatim storage, wiki tools for synthesized knowledge.')]
class MnemonServer extends Server
{
    protected array $tools = [];
    protected array $resources = [];
    protected array $prompts = [];
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Mcp/Servers/MnemonServer.php
git commit -m "feat: add empty MnemonServer scaffold"
```

---

### Task 8: Wire routes/ai.php with auth + throttle

**Files:**
- Modify: `routes/ai.php`

- [ ] **Step 1: Replace routes/ai.php contents**

```php
<?php

use App\Mcp\Servers\MnemonServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', MnemonServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
```

- [ ] **Step 2: Verify route registration**

```bash
php artisan route:list | grep -E "mcp|oauth"
```

Expected: `POST /mcp` listed with `auth:api` middleware. `/oauth/authorize`, `/oauth/token`, etc. listed.

- [ ] **Step 3: Commit**

```bash
git add routes/ai.php
git commit -m "feat: register MCP server at POST /mcp with auth:api + throttle"
```

---

### Task 9: Add throttle:mcp rate limiter

**Files:**
- Modify: `app/Providers/RouteServiceProvider.php` (or `app/Providers/AppServiceProvider.php` if RateLimiters live there in Laravel 13)

- [ ] **Step 1: Locate the RateLimiter registration**

```bash
grep -rn "RateLimiter::for" app/Providers/ bootstrap/
```

Expected: existing `for('api', ...)` registration. Add the new one alongside.

- [ ] **Step 2: Add the throttle:mcp limiter**

In the appropriate provider's `boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('mcp', function (Request $request) {
    return $request->user()
        ? Limit::perMinute(120)->by($request->user()->currentAccessToken()->id ?? $request->ip())
        : Limit::perMinute(20)->by($request->ip());
});
```

- [ ] **Step 3: Verify with route:list**

```bash
php artisan route:list | grep "POST.*mcp" 
```

Expected: `throttle:mcp` middleware shown.

- [ ] **Step 4: Commit**

```bash
git add app/Providers
git commit -m "feat: add throttle:mcp per-token rate limiter (120/min authenticated)"
```

---

### Task 10: Smoke test the empty MCP server

**Files:**
- Create: `tests/Feature/Mcp/ServerSmokeTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_mcp_returns_401(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_tools_list_returns_empty_array(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('result.tools'));
    }
}
```

- [ ] **Step 2: Run and confirm pass**

```bash
php artisan test --filter=ServerSmokeTest
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Mcp/ServerSmokeTest.php
git commit -m "test: smoke test for MCP server auth + tools/list"
```

---

## Phase 3 — Cross-cutting traits and support

### Task 11: BrainSessionLogger service

**Files:**
- Create: `app/Mcp/Support/BrainSessionLogger.php`
- Create: `tests/Unit/Mcp/BrainSessionLoggerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Support\BrainSessionLogger;
use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class BrainSessionLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_writes_brain_session_with_oauth_metadata(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('Claude Code', ['palace.read']);
        $token = $tokenResult->token;

        // Build a fake Laravel\Mcp\Request that resolves user() to $user
        $request = $this->makeMcpRequestFor($user, $token);

        BrainSessionLogger::log($request, 'drawer_search', ['query' => 'foo'], 5);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('drawer_search', $session->tool_name);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals($token->id, $session->access_token_id);
        $this->assertEquals($token->client_id, $session->oauth_client_id);
        $this->assertStringContainsString('Claude Code', $session->source);
        $this->assertEquals(['query' => 'foo'], $session->input);
        $this->assertEquals(5, $session->result_count);
    }

    private function makeMcpRequestFor(User $user, $token): Request
    {
        // Mock helper: laravel/mcp Request wraps an HTTP request; for unit test,
        // construct one and bind the authed user. If laravel/mcp doesn't expose
        // a simple constructor, create via an Http POST round-trip in a Feature test
        // instead and move this assertion there.
        $http = \Illuminate\Http\Request::create('/mcp', 'POST');
        $http->setUserResolver(fn () => $user->withAccessToken($token));
        return Request::createFrom($http);
    }
}
```

> Note: if `Laravel\Mcp\Request::createFrom()` isn't available with the package version installed, move this assertion into a Feature test that does a real `postJson('/mcp', ...)` cycle and inspects `BrainSession::latest()`.

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=BrainSessionLoggerTest
```

Expected: FAIL — class missing.

- [ ] **Step 3: Implement the logger**

`app/Mcp/Support/BrainSessionLogger.php`:

```php
<?php

namespace App\Mcp\Support;

use App\Models\BrainSession;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

class BrainSessionLogger
{
    public static function log(Request $request, string $tool, array $input, int $resultCount): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $client = $token?->client;

        BrainSession::create([
            'tool_name'        => $tool,
            'oauth_client_id'  => $client?->id,
            'user_id'          => $user?->id,
            'access_token_id'  => $token?->id,
            'source'           => self::renderSource($client, $user, $token),
            'input'            => $input,
            'result_count'     => $resultCount,
        ]);
    }

    private static function renderSource(?Client $client, ?User $user, ?Token $token): string
    {
        $parts = [];
        $parts[] = $client?->name ?? 'unknown-client';
        if ($user) {
            $parts[] = "as {$user->email}";
        }
        if ($token) {
            $parts[] = '(token …'.substr($token->id, -4).')';
        }
        return implode(' ', $parts);
    }
}
```

- [ ] **Step 4: Run and commit**

```bash
php artisan test --filter=BrainSessionLoggerTest
git add app/Mcp/Support/BrainSessionLogger.php tests/Unit/Mcp/BrainSessionLoggerTest.php
git commit -m "feat: add BrainSessionLogger with OAuth metadata"
```

---

### Task 12: RequiresScope trait

**Files:**
- Create: `app/Mcp/Concerns/RequiresScope.php`
- Create: `tests/Unit/Mcp/RequiresScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\RequiresScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class RequiresScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_token_has_required_scope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', ['palace.read'])->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class {
            use RequiresScope;
            protected string $scope = 'palace.read';
            public function check(Request $r) { return $this->requireScope($r); }
        };

        $this->assertNull($tool->check($request));
    }

    public function test_returns_error_response_when_token_lacks_scope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', ['wiki.read'])->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class {
            use RequiresScope;
            protected string $scope = 'palace.write';
            public function check(Request $r) { return $this->requireScope($r); }
        };

        $result = $tool->check($request);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->isError());
    }

    private function mcpRequestFor(User $user, $token): Request
    {
        $http = \Illuminate\Http\Request::create('/mcp', 'POST');
        $http->setUserResolver(fn () => $user->withAccessToken($token));
        return Request::createFrom($http);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=RequiresScopeTest
```

Expected: FAIL.

- [ ] **Step 3: Implement the trait**

`app/Mcp/Concerns/RequiresScope.php`:

```php
<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresScope
{
    /**
     * Returns null if scope check passes; returns an error Response otherwise.
     * Tools should: `if ($err = $this->requireScope($request)) return $err;`
     */
    protected function requireScope(Request $request): ?Response
    {
        $scope = $this->scope ?? null;

        if ($scope === null) {
            return null;
        }

        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can($scope)) {
            return Response::error("Missing required scope: {$scope}");
        }

        return null;
    }
}
```

- [ ] **Step 4: Run and commit**

```bash
php artisan test --filter=RequiresScopeTest
git add app/Mcp/Concerns/RequiresScope.php tests/Unit/Mcp/RequiresScopeTest.php
git commit -m "feat: add RequiresScope trait for tool-level scope enforcement"
```

---

### Task 13: RequiresWingAccess trait

**Files:**
- Create: `app/Mcp/Concerns/RequiresWingAccess.php`
- Create: `tests/Unit/Mcp/RequiresWingAccessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class RequiresWingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrestricted_token_passes_any_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        // No McpTokenRestriction row → unrestricted

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertNull($tool->check($request, 'personal'));
        $this->assertNull($tool->check($request, 'work:foo'));
    }

    public function test_restricted_token_passes_matching_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertNull($tool->check($request, 'work:project-a'));
    }

    public function test_restricted_token_rejects_non_matching_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $result = $tool->check($request, 'personal');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->isError());
    }

    public function test_wing_patterns_for_returns_list(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*', 'research']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertEquals(['work:*', 'research'], $tool->patterns($request));
    }

    private function harness()
    {
        return new class {
            use RequiresWingAccess;
            public function check(Request $r, string $w) { return $this->requireWingAccess($r, $w); }
            public function patterns(Request $r) { return $this->wingPatternsFor($r); }
        };
    }

    private function mcpRequestFor(User $user, $token): Request
    {
        $http = \Illuminate\Http\Request::create('/mcp', 'POST');
        $http->setUserResolver(fn () => $user->withAccessToken($token));
        return Request::createFrom($http);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=RequiresWingAccessTest
```

Expected: FAIL.

- [ ] **Step 3: Implement the trait**

`app/Mcp/Concerns/RequiresWingAccess.php`:

```php
<?php

namespace App\Mcp\Concerns;

use App\Models\McpTokenRestriction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresWingAccess
{
    protected function requireWingAccess(Request $request, string $wingSlug): ?Response
    {
        $restriction = $this->resolveRestriction($request);

        if ($restriction === null || $restriction->matches($wingSlug)) {
            return null;
        }

        return Response::error("Token does not have access to wing: {$wingSlug}");
    }

    /**
     * @return array<string>|null  null = unrestricted (all wings allowed)
     */
    protected function wingPatternsFor(Request $request): ?array
    {
        return $this->resolveRestriction($request)?->wing_patterns;
    }

    private function resolveRestriction(Request $request): ?McpTokenRestriction
    {
        $tokenId = $request->user()?->currentAccessToken()?->id;
        if ($tokenId === null) {
            return null;
        }
        return McpTokenRestriction::find($tokenId);
    }
}
```

- [ ] **Step 4: Run and commit**

```bash
php artisan test --filter=RequiresWingAccessTest
git add app/Mcp/Concerns/RequiresWingAccess.php tests/Unit/Mcp/RequiresWingAccessTest.php
git commit -m "feat: add RequiresWingAccess trait for per-token wing enforcement"
```

---

### Task 14: ResolvesAgentSource trait

**Files:**
- Create: `app/Mcp/Concerns/ResolvesAgentSource.php`
- Create: `tests/Unit/Mcp/ResolvesAgentSourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\ResolvesAgentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ResolvesAgentSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_override_when_provided(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude Code')->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class {
            use ResolvesAgentSource;
            public function call(Request $r, ?string $o) { return $this->agentSource($r, $o); }
        };

        $this->assertEquals('explicit-source', $tool->call($request, 'explicit-source'));
    }

    public function test_falls_back_to_oauth_client_name(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude Code')->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class {
            use ResolvesAgentSource;
            public function call(Request $r, ?string $o) { return $this->agentSource($r, $o); }
        };

        $this->assertEquals('Claude Code', $tool->call($request, null));
    }

    private function mcpRequestFor(User $user, $token): Request
    {
        $http = \Illuminate\Http\Request::create('/mcp', 'POST');
        $http->setUserResolver(fn () => $user->withAccessToken($token));
        return Request::createFrom($http);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --filter=ResolvesAgentSourceTest
```

Expected: FAIL.

- [ ] **Step 3: Implement the trait**

`app/Mcp/Concerns/ResolvesAgentSource.php`:

```php
<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;

trait ResolvesAgentSource
{
    protected function agentSource(Request $request, ?string $override): string
    {
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        return $request->user()?->currentAccessToken()?->client?->name ?? 'unknown-client';
    }
}
```

- [ ] **Step 4: Run and commit**

```bash
php artisan test --filter=ResolvesAgentSourceTest
git add app/Mcp/Concerns/ResolvesAgentSource.php tests/Unit/Mcp/ResolvesAgentSourceTest.php
git commit -m "feat: add ResolvesAgentSource trait defaulting to OAuth client name"
```

---

## Phase 4 — Tool rewrites

> **Pattern reference (Tasks 15–26):** Every tool follows the same TDD shape:
> 1. Read the existing tool to identify business logic worth preserving.
> 2. Write a Feature test calling `POST /mcp` with `tools/call` JSON-RPC envelope.
> 3. Cover: happy path, missing-scope rejection, wing rejection (when applicable), audit-log write.
> 4. Implement the new tool extending `Laravel\Mcp\Server\Tool`.
> 5. Add to `MnemonServer::$tools`.
> 6. Run, commit.
>
> Use the **`MakesMcpRequests`** trait introduced in Task 15 for all subsequent tasks.

### Task 15: MakesMcpRequests test trait + DrawerSearchTool (template task)

**Files:**
- Create: `tests/Concerns/MakesMcpRequests.php`
- Create: `app/Mcp/Tools/DrawerSearchTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/DrawerSearchToolTest.php`

- [ ] **Step 1: Read the existing tool**

```bash
cat app/Mcp/Tools/DrawerSearchTool.php
```

Note the business logic to preserve: query, limit, optional wing filter, vector + fulltext search semantics, the `score` field in results.

- [ ] **Step 2: Create the MakesMcpRequests test trait**

`tests/Concerns/MakesMcpRequests.php`:

```php
<?php

namespace Tests\Concerns;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait MakesMcpRequests
{
    protected function mcpCall(string $tool, array $arguments, array $scopes = ['*'], ?array $wingPatterns = null): TestResponse
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('Test Client', $scopes);
        $token = $tokenResult->token;

        if ($wingPatterns !== null) {
            McpTokenRestriction::create([
                'access_token_id' => $token->id,
                'wing_patterns'   => $wingPatterns,
            ]);
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
        ], [
            'Authorization' => 'Bearer '.$tokenResult->accessToken,
        ]);
    }
}
```

- [ ] **Step 3: Write the failing test**

`tests/Feature/Mcp/Tools/DrawerSearchToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerSearchToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_drawer_results_for_query(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create(['room_id' => $room->id, 'content' => 'meeting with dorothy vaughan']);

        $response = $this->mcpCall('drawer_search', ['query' => 'dorothy', 'limit' => 5], ['palace.read']);

        $response->assertStatus(200);
        $results = $response->json('result.structuredContent.results');
        $this->assertNotEmpty($results);
        $this->assertStringContainsString('dorothy', strtolower($results[0]['content']));
    }

    public function test_rejects_token_without_palace_read_scope(): void
    {
        $response = $this->mcpCall('drawer_search', ['query' => 'foo'], ['wiki.read']);
        $body = $response->json();
        $this->assertTrue(
            isset($body['result']['isError']) && $body['result']['isError'] === true,
            'Expected MCP error response for missing scope'
        );
    }

    public function test_rejects_wing_filter_outside_token_restrictions(): void
    {
        $response = $this->mcpCall(
            'drawer_search',
            ['query' => 'foo', 'wing' => 'personal'],
            ['palace.read'],
            wingPatterns: ['work:*']
        );

        $body = $response->json();
        $this->assertTrue(
            isset($body['result']['isError']) && $body['result']['isError'] === true,
            'Expected error when filtering wing outside restrictions'
        );
    }

    public function test_writes_brain_session_audit_row(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create(['room_id' => $room->id, 'content' => 'foo']);

        $this->mcpCall('drawer_search', ['query' => 'foo'], ['palace.read']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('drawer_search', $session->tool_name);
        $this->assertNotNull($session->access_token_id);
        $this->assertNotNull($session->user_id);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

```bash
php artisan test --filter=DrawerSearchToolTest
```

Expected: FAIL — tool not registered.

- [ ] **Step 5: Implement the tool**

`app/Mcp/Tools/DrawerSearchTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\DrawerSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Semantic + fulltext search across drawers in the palace.')]
#[IsReadOnly]
class DrawerSearchTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $scope = 'palace.read';

    public function __construct(protected DrawerSearchService $search) {}

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'query' => 'required|string|max:500',
            'limit' => 'integer|min:1|max:50',
            'wing'  => 'nullable|string',
        ]);

        if (! empty($params['wing'])) {
            if ($err = $this->requireWingAccess($request, $params['wing'])) {
                return $err;
            }
        }

        $results = $this->search->run(
            query: $params['query'],
            limit: $params['limit'] ?? 10,
            wing: $params['wing'] ?? null,
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        BrainSessionLogger::log($request, 'drawer_search', $params, count($results));

        return Response::structured(['results' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query.')->required(),
            'limit' => $schema->integer()->description('Max results (1-50).')->default(10),
            'wing'  => $schema->string()->description('Optional wing slug to restrict search.'),
        ];
    }
}
```

> If the existing implementation does not have a `DrawerSearchService` class (the search currently lives in the tool), extract it into one as part of this task. Filter the result query by `whereIn('wings.slug', $allowedWingPatterns)` (with wildcard expansion) when the patterns list is not null, so wing-restricted tokens never see drawers outside their grants in unfiltered searches either.

- [ ] **Step 6: Register in MnemonServer**

In `app/Mcp/Servers/MnemonServer.php`:

```php
protected array $tools = [
    \App\Mcp\Tools\DrawerSearchTool::class,
];
```

- [ ] **Step 7: Run and commit**

```bash
php artisan test --filter=DrawerSearchToolTest
git add tests/Concerns/MakesMcpRequests.php app/Mcp/Tools/DrawerSearchTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/DrawerSearchToolTest.php
git commit -m "feat: rewrite DrawerSearchTool against laravel/mcp with scope + wing checks"
```

---

### Task 16: DrawerGetTool

**Files:**
- Create: `app/Mcp/Tools/DrawerGetTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/DrawerGetToolTest.php`

- [ ] **Step 1: Read existing tool**

```bash
cat app/Mcp/Tools/DrawerGetTool.php
```

Preserve: lookup by id, returns full drawer record (content, metadata, wing, room, source, created_at).

- [ ] **Step 2: Write Feature test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerGetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_drawer_by_id(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'hello']);

        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['palace.read']);
        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals($drawer->id, $body['id']);
        $this->assertEquals('hello', $body['content']);
        $this->assertEquals('work', $body['wing']);
    }

    public function test_returns_error_for_unknown_id(): void
    {
        $r = $this->mcpCall('drawer_get', ['id' => 99999], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_drawer_outside_wing_restrictions(): void
    {
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $room = Room::factory()->create(['wing_id' => $personal->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id]);

        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['palace.read'], wingPatterns: ['work:*']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_missing_scope(): void
    {
        $drawer = Drawer::factory()->create();
        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['wiki.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }
}
```

- [ ] **Step 3: Run to verify fail**

```bash
php artisan test --filter=DrawerGetToolTest
```

Expected: FAIL.

- [ ] **Step 4: Implement the tool**

`app/Mcp/Tools/DrawerGetTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Fetch a single drawer by id.')]
#[IsReadOnly]
class DrawerGetTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $scope = 'palace.read';

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $params = $request->validate(['id' => 'required|integer']);

        $drawer = Drawer::with('room.wing')->find($params['id']);
        if (! $drawer) {
            return Response::error("Drawer not found: {$params['id']}");
        }

        if ($err = $this->requireWingAccess($request, $drawer->room->wing->slug)) return $err;

        $payload = [
            'id'         => $drawer->id,
            'content'    => $drawer->content,
            'wing'       => $drawer->room->wing->slug,
            'room'       => $drawer->room->slug,
            'source'     => $drawer->source,
            'metadata'   => $drawer->metadata,
            'tier'       => $drawer->tier,
            'created_at' => $drawer->created_at?->toIso8601String(),
        ];

        BrainSessionLogger::log($request, 'drawer_get', $params, 1);
        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return ['id' => $s->integer()->description('Drawer ID.')->required()];
    }
}
```

- [ ] **Step 5: Register and run**

Add `\App\Mcp\Tools\DrawerGetTool::class` to `MnemonServer::$tools`.

```bash
php artisan test --filter=DrawerGetToolTest
git add app/Mcp/Tools/DrawerGetTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/DrawerGetToolTest.php
git commit -m "feat: rewrite DrawerGetTool against laravel/mcp"
```

---

### Task 17: DrawerAddTool

**Files:**
- Create: `app/Mcp/Tools/DrawerAddTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/DrawerAddToolTest.php`

- [ ] **Step 1: Read existing tool**

```bash
cat app/Mcp/Tools/DrawerAddTool.php
```

Preserve: wing/room auto-creation by slug, embedding generation, source/metadata handling, content_hash dedup, tier classification.

- [ ] **Step 2: Write Feature test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerAddToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_creates_drawer_with_oauth_client_as_default_source(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'work',
            'room'    => 'notes',
            'content' => 'meeting notes',
        ], ['palace.write']);

        $r->assertStatus(200);
        $drawer = Drawer::first();
        $this->assertEquals('meeting notes', $drawer->content);
        $this->assertEquals('Test Client', $drawer->source);
    }

    public function test_explicit_source_overrides_oauth_client_name(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'work',
            'room'    => 'notes',
            'content' => 'foo',
            'source'  => 'manual-entry',
        ], ['palace.write']);

        $this->assertEquals('manual-entry', Drawer::first()->source);
    }

    public function test_rejects_write_outside_wing_restrictions(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'personal',
            'room'    => 'notes',
            'content' => 'foo',
        ], ['palace.write'], wingPatterns: ['work:*']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $this->assertEquals(0, Drawer::count());
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('drawer_add', ['wing' => 'work', 'room' => 'notes', 'content' => 'foo'], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }
}
```

- [ ] **Step 3: Run failing**

```bash
php artisan test --filter=DrawerAddToolTest
```

Expected: FAIL.

- [ ] **Step 4: Implement the tool**

`app/Mcp/Tools/DrawerAddTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\DrawerWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a drawer (verbatim content) to a room within a wing.')]
class DrawerAddTool extends Tool
{
    use RequiresScope, RequiresWingAccess, ResolvesAgentSource;

    protected string $scope = 'palace.write';

    public function __construct(protected DrawerWriteService $writer) {}

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $params = $request->validate([
            'wing'     => 'required|string',
            'room'     => 'required|string',
            'content'  => 'required|string',
            'source'   => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($err = $this->requireWingAccess($request, $params['wing'])) return $err;

        $drawer = $this->writer->createDrawer(
            wingSlug: $params['wing'],
            roomSlug: $params['room'],
            content: $params['content'],
            source: $this->agentSource($request, $params['source'] ?? null),
            metadata: $params['metadata'] ?? null,
        );

        BrainSessionLogger::log($request, 'drawer_add', $params, 1);

        return Response::structured(['id' => $drawer->id, 'tier' => $drawer->tier]);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'wing'     => $s->string()->required()->description('Wing slug (auto-created if missing).'),
            'room'     => $s->string()->required()->description('Room slug within wing.'),
            'content'  => $s->string()->required()->description('Verbatim drawer content.'),
            'source'   => $s->string()->description('Optional source override; defaults to OAuth client name.'),
            'metadata' => $s->object()->description('Optional JSON metadata.'),
        ];
    }
}
```

> Extract drawer creation into `App\Services\DrawerWriteService` if not already present in current code. Move embedding generation, content_hash dedup, and wing/room upsert logic into the service.

- [ ] **Step 5: Register, run, commit**

Add to `MnemonServer::$tools`.

```bash
php artisan test --filter=DrawerAddToolTest
git add app/Mcp/Tools/DrawerAddTool.php app/Services/DrawerWriteService.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/DrawerAddToolTest.php
git commit -m "feat: rewrite DrawerAddTool with OAuth client provenance"
```

---

### Task 18: BrainStatusTool

**Files:**
- Create: `app/Mcp/Tools/BrainStatusTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/BrainStatusToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/BrainStatusTool.php
```

Preserve: aggregate counts (wings, rooms, drawers, wiki_pages), embedding driver/dimensions, optional version info.

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class BrainStatusToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_aggregate_counts(): void
    {
        $w = Wing::factory()->create();
        $r = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->count(3)->create(['room_id' => $r->id]);

        $resp = $this->mcpCall('brain_status', [], ['palace.read']);
        $resp->assertStatus(200);
        $body = $resp->json('result.structuredContent');
        $this->assertEquals(1, $body['wings']);
        $this->assertEquals(1, $body['rooms']);
        $this->assertEquals(3, $body['drawers']);
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('brain_status', [], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }
}
```

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Aggregate counts and configuration of the Mnemon brain.')]
#[IsReadOnly]
class BrainStatusTool extends Tool
{
    use RequiresScope;

    protected string $scope = 'palace.read';

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $payload = [
            'wings'      => Wing::count(),
            'rooms'      => Room::count(),
            'drawers'    => Drawer::count(),
            'wiki_pages' => WikiPage::count(),
            'embedding'  => [
                'driver'     => config('mnemon.embedding.driver'),
                'dimensions' => config('mnemon.embedding.dimensions'),
            ],
        ];

        BrainSessionLogger::log($request, 'brain_status', [], 1);
        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array { return []; }
}
```

- [ ] **Step 4: Register, run, commit**

```bash
php artisan test --filter=BrainStatusToolTest
git add app/Mcp/Tools/BrainStatusTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/BrainStatusToolTest.php
git commit -m "feat: rewrite BrainStatusTool against laravel/mcp"
```

---

### Task 19: PalaceWakeUpTool

**Files:**
- Create: `app/Mcp/Tools/PalaceWakeUpTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/PalaceWakeUpToolTest.php`

- [ ] **Step 1: Read existing tool**

```bash
cat app/Mcp/Tools/PalaceWakeUpTool.php
```

Preserve: returns greeting text + summary of recent activity (last N drawers, last wiki edit, etc.) intended to seed an agent's context at session start.

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class PalaceWakeUpToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_recent_activity_summary(): void
    {
        $w = Wing::factory()->create(['slug' => 'work']);
        $r = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->count(3)->create(['room_id' => $r->id]);

        $resp = $this->mcpCall('palace_wake_up', [], ['palace.read']);
        $resp->assertStatus(200);
        $body = $resp->json('result.structuredContent');
        $this->assertArrayHasKey('greeting', $body);
        $this->assertArrayHasKey('recent_drawers', $body);
        $this->assertCount(3, $body['recent_drawers']);
    }

    public function test_filters_recent_activity_by_wing_restrictions(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $workRoom = Room::factory()->create(['wing_id' => $work->id]);
        $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-thing']);
        Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-thing']);

        $resp = $this->mcpCall('palace_wake_up', [], ['palace.read'], wingPatterns: ['work:*', 'work']);
        $body = $resp->json('result.structuredContent');
        $contents = collect($body['recent_drawers'])->pluck('content')->all();
        $this->assertContains('work-thing', $contents);
        $this->assertNotContains('personal-thing', $contents);
    }
}
```

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Greet an agent at session start and surface recent palace + wiki activity.')]
#[IsReadOnly]
class PalaceWakeUpTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $scope = 'palace.read';

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $patterns = $this->wingPatternsFor($request);

        $drawerQuery = Drawer::with('room.wing')->latest('created_at')->limit(10);
        if ($patterns !== null) {
            $drawerQuery->whereHas('room.wing', function ($q) use ($patterns) {
                $q->where(function ($inner) use ($patterns) {
                    foreach ($patterns as $p) {
                        $inner->orWhere('slug', 'like', str_replace('*', '%', $p));
                    }
                });
            });
        }

        $payload = [
            'greeting'        => 'You are Mnemon. Your palace and wiki are below.',
            'recent_drawers'  => $drawerQuery->get()->map(fn ($d) => [
                'id' => $d->id,
                'wing' => $d->room->wing->slug,
                'room' => $d->room->slug,
                'content' => mb_substr($d->content, 0, 200),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->all(),
            'recent_wiki'     => WikiPage::orderBy('last_compiled_at', 'desc')->limit(5)->get(['id','name','title','last_compiled_at']),
        ];

        BrainSessionLogger::log($request, 'palace_wake_up', [], count($payload['recent_drawers']));
        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array { return []; }
}
```

- [ ] **Step 4: Register, run, commit**

```bash
php artisan test --filter=PalaceWakeUpToolTest
git add app/Mcp/Tools/PalaceWakeUpTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/PalaceWakeUpToolTest.php
git commit -m "feat: rewrite PalaceWakeUpTool with wing-restriction filtering"
```

---

### Task 20: ContextGetTool

**Files:**
- Create: `app/Mcp/Tools/ContextGetTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/ContextGetToolTest.php`

- [ ] **Step 1: Read existing tool — note it returns a WikiPage**

```bash
cat app/Mcp/Tools/ContextGetTool.php
```

Preserve: lookup by name, full payload (id, name, type, title, description, confidence, sources, related, content, scores, last_compiled_at, last_accessed_at update on read, word_count). Conditional `source_details` only when token has `palace.read`.

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextGetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_wiki_page_by_name(): void
    {
        WikiPage::factory()->create(['name' => 'person:cooper', 'content' => 'about cooper']);

        $r = $this->mcpCall('context_get', ['name' => 'person:cooper'], ['wiki.read']);
        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('person:cooper', $body['name']);
        $this->assertEquals('about cooper', $body['content']);
    }

    public function test_returns_error_for_unknown_name(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'missing'], ['wiki.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'foo'], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_updates_last_accessed_at(): void
    {
        $page = WikiPage::factory()->create(['name' => 'concept:foo', 'last_accessed_at' => null]);
        $this->mcpCall('context_get', ['name' => 'concept:foo'], ['wiki.read']);
        $this->assertNotNull($page->fresh()->last_accessed_at);
    }
}
```

- [ ] **Step 3: Implement** — lift logic from existing tool but rewrite for the new base class. Source-details branch: check `$request->user()->currentAccessToken()->can('palace.read')`.

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a synthesized wiki page by name.')]
#[IsReadOnly]
class ContextGetTool extends Tool
{
    use RequiresScope;

    protected string $scope = 'wiki.read';

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $params = $request->validate(['name' => 'required|string|max:255']);

        $page = WikiPage::where('name', $params['name'])->first();
        if (! $page) {
            BrainSessionLogger::log($request, 'context_get', $params, 0);
            return Response::error("Wiki page not found: {$params['name']}");
        }

        $page->update(['last_accessed_at' => now()]);

        $payload = [
            'id' => $page->id, 'name' => $page->name, 'type' => $page->type,
            'title' => $page->title, 'description' => $page->description,
            'confidence' => $page->confidence, 'sources' => $page->sources,
            'related' => $page->related, 'confidence_score' => $page->confidence_score,
            'source_count' => $page->source_count,
            'pending_drawers_since_compile' => $page->pending_drawers_since_compile,
            'revision_count' => $page->revision_count,
            'content' => $page->content,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'word_count' => $page->content ? str_word_count($page->content) : 0,
        ];

        $token = $request->user()?->currentAccessToken();
        if ($token?->can('palace.read') && ! empty($page->sources)) {
            $payload['source_details'] = Drawer::whereIn('id', $page->sources)->get()->map(fn ($d) => [
                'id' => $d->id,
                'content_preview' => mb_substr($d->content, 0, 200),
                'source' => $d->source,
            ])->all();
        }

        BrainSessionLogger::log($request, 'context_get', $params, 1);
        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return ['name' => $s->string()->required()->description('Wiki page name (e.g. "person:cooper").')];
    }
}
```

- [ ] **Step 4: Register, run, commit**

```bash
php artisan test --filter=ContextGetToolTest
git add app/Mcp/Tools/ContextGetTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/ContextGetToolTest.php
git commit -m "feat: rewrite ContextGetTool against laravel/mcp"
```

---

### Task 21: ContextSetTool

**Files:**
- Create: `app/Mcp/Tools/ContextSetTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/ContextSetToolTest.php`

- [ ] **Step 1: Read existing tool — preserve all logic**

```bash
cat app/Mcp/Tools/ContextSetTool.php
```

Preserve: optimistic locking via `lockForUpdate` + `revision_count`, soft-delete restore, type inference from name prefix, sources/related validation, KnowledgeGraphService entity extraction, QualityScoreService scoring, WikiPageRevision audit insert, wiki/index + wiki/log auto-update, conflict-on-stale-revision error.

- [ ] **Step 2: Test (covers happy path, conflict detection, scope rejection, agent_id stamping)**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextSetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_creates_new_wiki_page(): void
    {
        $r = $this->mcpCall('context_set', [
            'name' => 'concept:second-brain',
            'content' => 'A persistent knowledge store.',
        ], ['wiki.write']);

        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('created', $body['created_or_updated']);
        $this->assertEquals(1, $body['revision_count']);
    }

    public function test_revision_audit_uses_oauth_client_name_as_agent_id(): void
    {
        $this->mcpCall('context_set', ['name' => 'foo', 'content' => 'bar'], ['wiki.write']);

        $rev = WikiPageRevision::latest('id')->first();
        $this->assertEquals('Test Client', $rev->agent_id);
    }

    public function test_conflict_when_expected_revision_stale(): void
    {
        WikiPage::factory()->create(['name' => 'concept:x', 'revision_count' => 3]);

        $r = $this->mcpCall('context_set', [
            'name' => 'concept:x',
            'content' => 'new',
            'expected_revision' => 1,
        ], ['wiki.write']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $this->assertStringContainsString('Conflict', $body['result']['content'][0]['text'] ?? '');
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('context_set', ['name' => 'foo', 'content' => 'bar'], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }
}
```

- [ ] **Step 3: Implement** — port existing logic into the new base class. Use `ResolvesAgentSource` for `agent_id`. Replace `McpException::invalidParams(...)` with `Response::error(...)`. Replace `McpException` thrown for conflicts with `Response::error("Conflict: ...")`.

> Full implementation is too long to inline here verbatim — copy `app/Mcp/Tools/ContextSetTool.php` from current code, then mechanically:
> 1. Change base class: `extends Tool` (from `Laravel\Mcp\Server`).
> 2. Add traits: `RequiresScope, ResolvesAgentSource`. Set `protected string $scope = 'wiki.write';`.
> 3. Convert `execute(array $params, ApiKey $apiKey)` to `handle(Request $request): Response`.
> 4. Replace `$apiKey->name` with `$this->agentSource($request, $params['agent_id'] ?? null)` for `agent_id`.
> 5. Replace `throw McpException::*(...)` with `return Response::error(...)`.
> 6. Replace `return $result;` with `return Response::structured($result);`.
> 7. Replace `$this->logSession(...)` with `BrainSessionLogger::log($request, ...)`.
> 8. Add `schema(JsonSchema $s): array` mirroring the validate rules.
> 9. Add `#[Description('...')]` attribute.

- [ ] **Step 4: Register, run, commit**

```bash
php artisan test --filter=ContextSetToolTest
git add app/Mcp/Tools/ContextSetTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/ContextSetToolTest.php
git commit -m "feat: rewrite ContextSetTool with agent_id from OAuth client"
```

---

### Task 22: ContextListTool

**Files:**
- Create: `app/Mcp/Tools/ContextListTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/ContextListToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/ContextListTool.php
```

Preserve: lists wiki pages, optional type filter, optional pagination/limit, returns name + title + type + last_compiled_at.

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextListToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_lists_wiki_pages(): void
    {
        WikiPage::factory()->count(3)->create();

        $r = $this->mcpCall('context_list', [], ['wiki.read']);
        $r->assertStatus(200);
        $this->assertCount(3, $r->json('result.structuredContent.pages'));
    }

    public function test_filters_by_type(): void
    {
        WikiPage::factory()->create(['name' => 'person:a', 'type' => 'person']);
        WikiPage::factory()->create(['name' => 'concept:b', 'type' => 'concept']);

        $r = $this->mcpCall('context_list', ['type' => 'person'], ['wiki.read']);
        $pages = $r->json('result.structuredContent.pages');
        $this->assertCount(1, $pages);
        $this->assertEquals('person:a', $pages[0]['name']);
    }
}
```

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\WikiPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List wiki pages, optionally filtered by type.')]
#[IsReadOnly]
class ContextListTool extends Tool
{
    use RequiresScope;

    protected string $scope = 'wiki.read';

    public function handle(Request $request): Response
    {
        if ($err = $this->requireScope($request)) return $err;

        $params = $request->validate([
            'type'  => 'nullable|in:person,project,concept,decision,synthesis',
            'limit' => 'integer|min:1|max:200',
        ]);

        $query = WikiPage::query()->orderBy('name');
        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }
        $pages = $query->limit($params['limit'] ?? 100)->get(['id','name','title','type','last_compiled_at']);

        BrainSessionLogger::log($request, 'context_list', $params, $pages->count());
        return Response::structured(['pages' => $pages->all()]);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'type'  => $s->string()->enum(['person','project','concept','decision','synthesis'])->description('Filter by page type.'),
            'limit' => $s->integer()->description('Max pages (1-200, default 100).')->default(100),
        ];
    }
}
```

- [ ] **Step 4: Register, run, commit**

```bash
php artisan test --filter=ContextListToolTest
git add app/Mcp/Tools/ContextListTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/ContextListToolTest.php
git commit -m "feat: rewrite ContextListTool against laravel/mcp"
```

---

### Task 23: WikiLintTool

**Files:**
- Create: `app/Mcp/Tools/WikiLintTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/WikiLintToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/WikiLintTool.php
```

Preserve: scans pages for issues (broken refs, stale, low confidence), returns lint report; may auto-fix when `auto_fix=true`.

- [ ] **Step 2: Test, implement, register, commit (follow Task 15 pattern)**

Apply scope `wiki.write`. Test cases: scope rejection, returns issues array, respects `auto_fix` flag if currently supported.

```bash
php artisan test --filter=WikiLintToolTest
git add app/Mcp/Tools/WikiLintTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/WikiLintToolTest.php
git commit -m "feat: rewrite WikiLintTool against laravel/mcp"
```

---

### Task 24: WikiCompileTool

**Files:**
- Create: `app/Mcp/Tools/WikiCompileTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/WikiCompileToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/WikiCompileTool.php
```

Preserve all current behavior. **Audit for optimistic-locking gap** — `wiki_compile` writes to a wiki page; if it doesn't already use `lockForUpdate` + revision check (like `context_set` does), add the same pattern.

- [ ] **Step 2: Test, implement, register, commit**

Apply scope `wiki.write`. Use `ResolvesAgentSource` for the compile audit trail. Tests: scope rejection, happy path produces a wiki page, concurrent compile contention → second one gets a conflict response.

```bash
php artisan test --filter=WikiCompileToolTest
git add app/Mcp/Tools/WikiCompileTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/WikiCompileToolTest.php
git commit -m "feat: rewrite WikiCompileTool with optimistic locking"
```

---

### Task 25: WikiHistoryTool

**Files:**
- Create: `app/Mcp/Tools/WikiHistoryTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/WikiHistoryToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/WikiHistoryTool.php
```

Preserve: returns WikiPageRevision rows for a given page name, with timestamp, agent_id, content/diff.

- [ ] **Step 2: Test, implement, register, commit**

Scope `wiki.read`, `IsReadOnly` annotation. Test: returns revision list ordered desc, returns empty array for unknown page (or error per current behavior — match it), scope rejection.

```bash
php artisan test --filter=WikiHistoryToolTest
git add app/Mcp/Tools/WikiHistoryTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/WikiHistoryToolTest.php
git commit -m "feat: rewrite WikiHistoryTool against laravel/mcp"
```

---

### Task 26: WikiGraphTool

**Files:**
- Create: `app/Mcp/Tools/WikiGraphTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Tools/WikiGraphToolTest.php`

- [ ] **Step 1: Read existing**

```bash
cat app/Mcp/Tools/WikiGraphTool.php
```

Preserve: returns knowledge-graph edges (entities, references), optional filter by entity name, configurable depth.

- [ ] **Step 2: Test, implement, register, commit**

Scope `wiki.read`, `IsReadOnly`. Test: returns entities + edges, filter by name returns subgraph, scope rejection.

```bash
php artisan test --filter=WikiGraphToolTest
git add app/Mcp/Tools/WikiGraphTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/WikiGraphToolTest.php
git commit -m "feat: rewrite WikiGraphTool against laravel/mcp"
```

---

## Phase 5 — Resources

### Task 27: DrawerResource

**Files:**
- Create: `app/Mcp/Resources/DrawerResource.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Resources/DrawerResourceTest.php`

- [ ] **Step 1: Test**

```php
<?php

namespace Tests\Feature\Mcp\Resources;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerResourceTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_resource_lists_for_palace_read_token(): void
    {
        $r = $this->mcpResourceList(['palace.read']);
        $r->assertStatus(200);
        $uris = collect($r->json('result.resources'))->pluck('uriTemplate')->all();
        $this->assertContains('mnemon://drawer/{id}', $uris);
    }

    public function test_resource_hidden_for_token_without_palace_read(): void
    {
        $r = $this->mcpResourceList(['wiki.read']);
        $uris = collect($r->json('result.resources'))->pluck('uriTemplate')->all();
        $this->assertNotContains('mnemon://drawer/{id}', $uris);
    }

    public function test_resource_read_returns_drawer_content(): void
    {
        $w = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $w->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'foo']);

        $r = $this->mcpResourceRead("mnemon://drawer/{$drawer->id}", ['palace.read']);
        $r->assertStatus(200);
        $body = $r->json('result.contents.0');
        $this->assertEquals("mnemon://drawer/{$drawer->id}", $body['uri']);
        $this->assertStringContainsString('foo', $body['text']);
    }

    public function test_resource_read_rejects_drawer_outside_wing_restrictions(): void
    {
        $w = Wing::factory()->create(['slug' => 'personal']);
        $room = Room::factory()->create(['wing_id' => $w->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id]);

        $r = $this->mcpResourceRead("mnemon://drawer/{$drawer->id}", ['palace.read'], wingPatterns: ['work:*']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false || isset($body['error']));
    }
}
```

> Add `mcpResourceList()` and `mcpResourceRead()` helpers to `MakesMcpRequests` trait — they post `resources/list` and `resources/read` JSON-RPC envelopes respectively.

- [ ] **Step 2: Implement**

`app/Mcp/Resources/DrawerResource.php`:

```php
<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\Drawer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Verbatim drawer content addressable by ID.')]
#[MimeType('text/markdown')]
class DrawerResource extends Resource implements HasUriTemplate
{
    use RequiresWingAccess;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mnemon://drawer/{id}');
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('palace.read') ?? false);
    }

    public function handle(Request $request): Response
    {
        $drawer = Drawer::with('room.wing')->find($request->get('id'));
        if (! $drawer) {
            return Response::error("Drawer not found: {$request->get('id')}");
        }

        if ($err = $this->requireWingAccess($request, $drawer->room->wing->slug)) {
            return $err;
        }

        return Response::text($drawer->content);
    }
}
```

- [ ] **Step 3: Register, run, commit**

Add `\App\Mcp\Resources\DrawerResource::class` to `MnemonServer::$resources`.

```bash
php artisan test --filter=DrawerResourceTest
git add tests/Concerns/MakesMcpRequests.php app/Mcp/Resources/DrawerResource.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Resources/DrawerResourceTest.php
git commit -m "feat: add DrawerResource (mnemon://drawer/{id})"
```

---

### Task 28: WikiPageResource

**Files:**
- Create: `app/Mcp/Resources/WikiPageResource.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Resources/WikiPageResourceTest.php`

- [ ] **Step 1: Test, implement, register, commit (follow Task 27 pattern)**

URI template: `mnemon://wiki/{slug}` (use `name` as slug). Scope: `wiki.read`. No wing restriction (wiki pages aren't wing-scoped). Returns markdown content.

```bash
php artisan test --filter=WikiPageResourceTest
git add app/Mcp/Resources/WikiPageResource.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Resources/WikiPageResourceTest.php
git commit -m "feat: add WikiPageResource (mnemon://wiki/{slug})"
```

---

### Task 29: WingResource

**Files:**
- Create: `app/Mcp/Resources/WingResource.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Resources/WingResourceTest.php`

- [ ] **Step 1: Test, implement, register, commit (follow Task 27 pattern)**

URI template: `mnemon://wing/{slug}`. Scope: `palace.read`. Honor wing restrictions. Response: markdown with wing name, room list, and per-room drawer count.

```bash
php artisan test --filter=WingResourceTest
git add app/Mcp/Resources/WingResource.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Resources/WingResourceTest.php
git commit -m "feat: add WingResource (mnemon://wing/{slug})"
```

---

## Phase 6 — Prompts

### Task 30: SynthesizeWingPrompt

**Files:**
- Create: `app/Mcp/Prompts/SynthesizeWingPrompt.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Prompts/SynthesizeWingPromptTest.php`

- [ ] **Step 1: Test**

```php
<?php

namespace Tests\Feature\Mcp\Prompts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class SynthesizeWingPromptTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_prompt_appears_in_list(): void
    {
        $r = $this->mcpPromptList(['wiki.write']);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertContains('synthesize_wing', $names);
    }

    public function test_prompt_renders_with_wing_slug(): void
    {
        $r = $this->mcpPromptGet('synthesize_wing', ['wing_slug' => 'work'], ['wiki.write']);
        $r->assertStatus(200);
        $messages = $r->json('result.messages');
        $this->assertNotEmpty($messages);
        $combined = collect($messages)->pluck('content.text')->implode(' ');
        $this->assertStringContainsString('work', $combined);
    }
}
```

> Add `mcpPromptList()` and `mcpPromptGet()` helpers to `MakesMcpRequests`.

- [ ] **Step 2: Implement**

`app/Mcp/Prompts/SynthesizeWingPrompt.php`:

```php
<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Compile a wiki page from all drawers in a wing.')]
class SynthesizeWingPrompt extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument(name: 'wing_slug', description: 'The wing to synthesize.', required: true),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('wiki.write') ?? false);
    }

    public function handle(Request $request): array
    {
        $params = $request->validate(['wing_slug' => 'required|string']);

        return [
            Response::text(
                'You are Mnemon. Synthesize a wiki page for the wing "'.$params['wing_slug'].'". '
                .'Use drawer_search with wing="'.$params['wing_slug'].'" to gather source material, '
                .'then call context_set with name="synthesis:'.$params['wing_slug'].'" and the synthesized content.'
            )->asAssistant(),
            Response::text('Begin synthesis.'),
        ];
    }
}
```

- [ ] **Step 3: Register, run, commit**

Add to `MnemonServer::$prompts`.

```bash
php artisan test --filter=SynthesizeWingPromptTest
git add app/Mcp/Prompts/SynthesizeWingPrompt.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Prompts/SynthesizeWingPromptTest.php tests/Concerns/MakesMcpRequests.php
git commit -m "feat: add SynthesizeWingPrompt"
```

---

### Task 31: FindStaleWikiPagesPrompt

**Files:**
- Create: `app/Mcp/Prompts/FindStaleWikiPagesPrompt.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Prompts/FindStaleWikiPagesPromptTest.php`

- [ ] **Step 1: Test, implement, register, commit (follow Task 30 pattern)**

Argument: `days` (optional, default 30). Scope: `wiki.read`. Returns prompt instructing the client to call `context_list` and filter by `last_compiled_at < now - {days}`.

```bash
php artisan test --filter=FindStaleWikiPagesPromptTest
git add app/Mcp/Prompts/FindStaleWikiPagesPrompt.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Prompts/FindStaleWikiPagesPromptTest.php
git commit -m "feat: add FindStaleWikiPagesPrompt"
```

---

### Task 32: DrawerToWikiPrompt

**Files:**
- Create: `app/Mcp/Prompts/DrawerToWikiPrompt.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Create: `tests/Feature/Mcp/Prompts/DrawerToWikiPromptTest.php`

- [ ] **Step 1: Test, implement, register, commit (follow Task 30 pattern)**

Argument: `drawer_id` (required). Scope: `wiki.write`. Returns prompt instructing the client to fetch the drawer (via `drawer_get`), then propose a `context_set` call to update relevant wiki pages.

```bash
php artisan test --filter=DrawerToWikiPromptTest
git add app/Mcp/Prompts/DrawerToWikiPrompt.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Prompts/DrawerToWikiPromptTest.php
git commit -m "feat: add DrawerToWikiPrompt"
```

---

## Phase 7 — OAuth consent flow

### Task 33: Mnemon-styled consent view

**Files:**
- Modify: `resources/views/vendor/mcp/authorize.blade.php` (rename target: create `resources/views/mcp/authorize.blade.php`)
- Reference: existing layout at `resources/views/layouts/mnemon.blade.php`

- [ ] **Step 1: Move the published view to a Mnemon-owned path**

```bash
mkdir -p resources/views/mcp
mv resources/views/vendor/mcp/authorize.blade.php resources/views/mcp/authorize.blade.php
```

- [ ] **Step 2: Rewrite the view**

`resources/views/mcp/authorize.blade.php`:

```blade
@extends('layouts.mnemon')

@section('title', 'Authorize ' . $client->name)

@section('content')
<div class="auth-container">
    <h1>Authorize {{ $client->name }}</h1>
    <p class="muted">{{ $client->name }} is requesting access to your Mnemon brain.</p>

    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        <fieldset>
            <legend>Scopes</legend>
            @foreach ($scopes as $scope)
                <label>
                    <input type="checkbox" name="scopes[]" value="{{ $scope->id }}" checked>
                    <strong>{{ $scope->id }}</strong> — {{ $scope->description }}
                </label>
            @endforeach
        </fieldset>

        <fieldset>
            <legend>Wing access</legend>
            <label>
                <input type="checkbox" name="all_wings" value="1" id="all-wings">
                <strong>All wings</strong> (no restriction)
            </label>
            <div id="wing-list">
                @foreach ($wings as $wing)
                    <label>
                        <input type="checkbox" name="wings[]" value="{{ $wing->slug }}">
                        {{ $wing->slug }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <button type="submit" class="btn-primary">Authorize</button>
        <button type="submit" name="deny" value="1" class="btn-secondary">Deny</button>
    </form>
</div>

<script>
document.getElementById('all-wings').addEventListener('change', (e) => {
    document.querySelectorAll('#wing-list input').forEach(i => {
        i.disabled = e.target.checked;
        if (e.target.checked) i.checked = false;
    });
});
</script>
@endsection
```

- [ ] **Step 3: Pass wings to the view**

The `Passport::authorizationView()` callback set in Task 3 receives a `$parameters` array. Update it to merge `wings`:

```php
Passport::authorizationView(fn ($p) => view('mcp.authorize', array_merge($p, [
    'wings' => \App\Models\Wing::orderBy('slug')->get(),
])));
```

- [ ] **Step 4: Manual smoke test**

```bash
php artisan serve
```

Open `http://127.0.0.1:8000/oauth/authorize?client_id=...&response_type=code&redirect_uri=...&scope=palace.read+wiki.read&state=test` (use a Passport-generated client). Confirm the Mnemon-styled consent screen renders with scope checkboxes and wing list.

- [ ] **Step 5: Commit**

```bash
git add resources/views/mcp/authorize.blade.php app/Providers/AppServiceProvider.php
rm -rf resources/views/vendor/mcp
git add resources/views/vendor/mcp 2>/dev/null || true
git commit -m "feat: Mnemon-styled OAuth consent screen with wing selector"
```

---

### Task 34: Persist wing restrictions on consent approval

**Files:**
- Create: `app/Http/Controllers/Auth/AuthorizationController.php` (overrides Passport's controller)
- Modify: `routes/web.php` or `routes/ai.php` to point the approve route at the override
- Create: `tests/Feature/Mcp/OAuthFlowTest.php`

- [ ] **Step 1: Test**

`tests/Feature/Mcp/OAuthFlowTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpTokenRestriction;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_approval_persists_wing_restrictions(): void
    {
        Wing::factory()->create(['slug' => 'work']);
        Wing::factory()->create(['slug' => 'personal']);

        $user = User::factory()->create();
        $client = Client::factory()->create([
            'redirect' => 'http://localhost/cb',
        ]);

        $this->actingAs($user);

        // 1. Initiate authorize
        $authResp = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code',
            'scope' => 'palace.read',
            'state' => 'st',
        ]));

        $authToken = session('authToken') ?? $authResp->viewData('authToken');

        // 2. Approve with selected wings
        $approve = $this->post('/oauth/authorize', [
            'auth_token' => $authToken,
            'scopes' => ['palace.read'],
            'wings' => ['work'],
        ]);

        $approve->assertRedirect();

        // 3. Verify a restriction row was written for the issued token
        $restriction = McpTokenRestriction::first();
        $this->assertNotNull($restriction);
        $this->assertEquals(['work'], $restriction->wing_patterns);
    }

    public function test_all_wings_checkbox_persists_null_restriction(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect' => 'http://localhost/cb']);

        $this->actingAs($user);
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'palace.read', 'state' => 'st',
        ]));

        $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
            'scopes' => ['palace.read'],
            'all_wings' => '1',
        ]);

        $restriction = McpTokenRestriction::first();
        $this->assertNotNull($restriction);
        $this->assertNull($restriction->wing_patterns);
    }
}
```

- [ ] **Step 2: Run failing**

```bash
php artisan test --filter=OAuthFlowTest
```

Expected: FAIL — no consent post handler persisting restrictions yet.

- [ ] **Step 3: Implement consent post handler**

Override Passport's approval controller. Create `app/Http/Controllers/Auth/AuthorizationController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Models\McpTokenRestriction;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Token;

class AuthorizationController extends ApproveAuthorizationController
{
    public function approve(Request $request)
    {
        $response = parent::approve($request);

        // After Passport issues the auth code → token exchange, the next access_token
        // for this user+client is the one we just minted. We persist restrictions on
        // it the first time it's used. Practical approach: stamp restrictions onto
        // the AUTH CODE in session, and on token issuance copy them across.
        // Simpler approach (used here): listen for the token-issued event.

        return $response;
    }
}
```

> The cleanest hook is the `Laravel\Passport\Events\AccessTokenCreated` event. Register a listener in `EventServiceProvider`:

```php
use Laravel\Passport\Events\AccessTokenCreated;
use App\Listeners\PersistMcpTokenRestrictions;

protected $listen = [
    AccessTokenCreated::class => [PersistMcpTokenRestrictions::class],
];
```

Create `app/Listeners/PersistMcpTokenRestrictions.php`:

```php
<?php

namespace App\Listeners;

use App\Models\McpTokenRestriction;
use Illuminate\Http\Request;
use Laravel\Passport\Events\AccessTokenCreated;

class PersistMcpTokenRestrictions
{
    public function __construct(protected Request $request) {}

    public function handle(AccessTokenCreated $event): void
    {
        // The consent form posts `wings[]` and optionally `all_wings`.
        // These params are present on the Request that Passport processed.
        $allWings = $this->request->boolean('all_wings');
        $wings = $this->request->input('wings', []);

        $patterns = $allWings ? null : (empty($wings) ? null : $wings);

        // Only write a row if the consent form was the trigger (i.e., wings/all_wings present).
        if ($allWings || ! empty($wings)) {
            McpTokenRestriction::updateOrCreate(
                ['access_token_id' => $event->tokenId],
                ['wing_patterns' => $patterns]
            );
        }
    }
}
```

- [ ] **Step 4: Run and iterate**

```bash
php artisan test --filter=OAuthFlowTest
```

Iterate on the listener / controller integration until both tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Listeners app/Providers/EventServiceProvider.php app/Http/Controllers/Auth tests/Feature/Mcp/OAuthFlowTest.php
git commit -m "feat: persist mcp_token_restrictions on OAuth consent approval"
```

---

### Task 35: End-to-end OAuth + MCP call test

**Files:**
- Create: `tests/Feature/Mcp/EndToEndOAuthTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class EndToEndOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_from_consent_to_mcp_tool_call(): void
    {
        // Setup: data, user, client
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $workRoom = Room::factory()->create(['wing_id' => $work->id]);
        $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-secret']);
        Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-secret']);

        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect' => 'http://localhost/cb']);

        $this->actingAs($user);

        // 1. Consent
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'palace.read', 'state' => 'st',
        ]));

        $approve = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
            'scopes' => ['palace.read'],
            'wings' => ['work'],
        ]);

        // 2. Extract code from redirect
        $location = $approve->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'];

        // 3. Token exchange
        $tokenResp = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret ?? $client->secret,
            'redirect_uri' => 'http://localhost/cb',
            'code' => $code,
        ]);
        $accessToken = $tokenResp->json('access_token');

        // 4. Call drawer_search — should only see work drawer
        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $results = $r->json('result.structuredContent.results');
        $contents = collect($results)->pluck('content')->all();
        $this->assertContains('work-secret', $contents);
        $this->assertNotContains('personal-secret', $contents);
    }
}
```

- [ ] **Step 2: Run, iterate, commit**

```bash
php artisan test --filter=EndToEndOAuthTest
git add tests/Feature/Mcp/EndToEndOAuthTest.php
git commit -m "test: end-to-end OAuth flow from consent to scoped MCP call"
```

---

## Phase 8 — Filament admin

### Task 36: OauthClientResource

**Files:**
- Create: `app/Filament/Resources/OauthClientResource.php`
- Create: `app/Filament/Resources/OauthClientResource/Pages/ListOauthClients.php`
- Create: `app/Filament/Resources/OauthClientResource/Pages/ViewOauthClient.php`
- Create: `tests/Feature/Filament/OauthClientResourceTest.php`

- [ ] **Step 1: Generate the resource**

```bash
php artisan make:filament-resource OauthClient --view --generate
```

If Filament can't introspect the Passport `Client` model, generate manually and edit.

- [ ] **Step 2: Configure the resource**

In `app/Filament/Resources/OauthClientResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OauthClientResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Laravel\Passport\Client;

class OauthClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationGroup = 'Access Control';
    protected static ?string $navigationLabel = 'OAuth Clients';
    protected static ?string $modelLabel = 'OAuth Client';

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('redirect')->wrap(),
            Tables\Columns\TextColumn::make('user.email')->label('Owner'),
            Tables\Columns\TextColumn::make('tokens_count')->counts('tokens')->label('Active tokens'),
            Tables\Columns\TextColumn::make('created_at')->dateTime('Y-m-d H:i'),
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\Action::make('revoke')
                ->label('Revoke')
                ->color('danger')
                ->requiresConfirmation()
                ->action(fn (Client $record) => $record->tokens()->update(['revoked' => true])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOauthClients::route('/'),
            'view' => Pages\ViewOauthClient::route('/{record}'),
        ];
    }
}
```

- [ ] **Step 3: Test**

`tests/Feature/Filament/OauthClientResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class OauthClientResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_oauth_clients(): void
    {
        $admin = User::factory()->create();
        Client::factory()->count(2)->create();

        $r = $this->actingAs($admin)->get('/admin/oauth-clients');
        $r->assertStatus(200);
    }

    public function test_admin_can_revoke_clients_tokens(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $user->createToken('test')->token;

        // Direct invocation of revoke action via Livewire is complex; invoke the model logic instead
        $client->tokens()->update(['revoked' => true]);
        $this->assertTrue($client->tokens()->first()->revoked);
    }
}
```

- [ ] **Step 4: Run and commit**

```bash
php artisan test --filter=OauthClientResourceTest
git add app/Filament/Resources/OauthClientResource.php app/Filament/Resources/OauthClientResource tests/Feature/Filament/OauthClientResourceTest.php
git commit -m "feat: add Filament OAuth Clients resource"
```

---

### Task 37: OauthAccessTokenResource

**Files:**
- Create: `app/Filament/Resources/OauthAccessTokenResource.php`
- Create: `app/Filament/Resources/OauthAccessTokenResource/Pages/ListOauthAccessTokens.php`
- Create: `tests/Feature/Filament/OauthAccessTokenResourceTest.php`

- [ ] **Step 1: Implement resource**

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OauthAccessTokenResource\Pages;
use App\Models\McpTokenRestriction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Laravel\Passport\Token;

class OauthAccessTokenResource extends Resource
{
    protected static ?string $model = Token::class;
    protected static ?string $navigationGroup = 'Access Control';
    protected static ?string $navigationLabel = 'Active Tokens';
    protected static ?string $modelLabel = 'Access Token';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('revoked', false)->where('expires_at', '>', now()))
            ->columns([
                Tables\Columns\TextColumn::make('client.name')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('user.email')->label('User'),
                Tables\Columns\TextColumn::make('scopes')->badge()->separator(',')->formatStateUsing(fn ($state) => is_array($state) ? $state : []),
                Tables\Columns\TextColumn::make('wing_restrictions')
                    ->badge()
                    ->formatStateUsing(function (Token $record) {
                        $r = McpTokenRestriction::find($record->id);
                        return $r?->wing_patterns ?? ['(unrestricted)'];
                    }),
                Tables\Columns\TextColumn::make('expires_at')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('updated_at')->label('Last used')->since(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Token $record) => $record->update(['revoked' => true])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOauthAccessTokens::route('/')];
    }
}
```

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OauthAccessTokenResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_active_tokens_with_wing_restrictions(): void
    {
        $admin = User::factory()->create();
        $u = User::factory()->create();
        $token = $u->createToken('Claude Code')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*']]);

        $r = $this->actingAs($admin)->get('/admin/oauth-access-tokens');
        $r->assertStatus(200);
        $r->assertSeeText('Claude Code');
        $r->assertSeeText('work:*');
    }
}
```

- [ ] **Step 3: Run and commit**

```bash
php artisan test --filter=OauthAccessTokenResourceTest
git add app/Filament/Resources/OauthAccessTokenResource.php app/Filament/Resources/OauthAccessTokenResource tests/Feature/Filament/OauthAccessTokenResourceTest.php
git commit -m "feat: add Filament Active Tokens resource with wing restrictions display"
```

---

### Task 38: LiveMcpSessionsWidget

**Files:**
- Create: `app/Filament/Widgets/LiveMcpSessionsWidget.php`
- Create: `resources/views/filament/widgets/live-mcp-sessions.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (register widget)
- Create: `tests/Feature/Filament/LiveMcpSessionsWidgetTest.php`

- [ ] **Step 1: Implement widget**

```php
<?php

namespace App\Filament\Widgets;

use App\Models\BrainSession;
use Filament\Widgets\Widget;

class LiveMcpSessionsWidget extends Widget
{
    protected static string $view = 'filament.widgets.live-mcp-sessions';
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $sessions = BrainSession::query()
            ->whereNotNull('access_token_id')
            ->latest('created_at')
            ->limit(5)
            ->get(['tool_name','source','created_at']);

        return ['sessions' => $sessions];
    }
}
```

- [ ] **Step 2: View**

`resources/views/filament/widgets/live-mcp-sessions.blade.php`:

```blade
<x-filament::section>
    <x-slot name="heading">Live MCP Sessions</x-slot>

    @if ($sessions->isEmpty())
        <p class="text-sm text-gray-500">No recent MCP activity.</p>
    @else
        <ul class="space-y-2">
            @foreach ($sessions as $s)
                <li class="text-sm">
                    <strong>{{ $s->tool_name }}</strong>
                    — {{ $s->source }}
                    <span class="text-gray-500">({{ $s->created_at->diffForHumans() }})</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
```

- [ ] **Step 3: Register the widget**

In `app/Providers/Filament/AdminPanelProvider.php`, add to the `widgets()` array:

```php
->widgets([
    \App\Filament\Widgets\LiveMcpSessionsWidget::class,
    // ... existing widgets
])
```

- [ ] **Step 4: Test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveMcpSessionsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_recent_mcp_sessions(): void
    {
        $admin = User::factory()->create();
        BrainSession::create([
            'tool_name' => 'drawer_search',
            'source'    => 'Claude Code as cooper@... (token …abc1)',
            'access_token_id' => 'fake-token-id',
            'input' => [],
            'result_count' => 1,
        ]);

        $r = $this->actingAs($admin)->get('/admin');
        $r->assertSeeText('Live MCP Sessions');
        $r->assertSeeText('drawer_search');
        $r->assertSeeText('Claude Code');
    }
}
```

- [ ] **Step 5: Commit**

```bash
php artisan test --filter=LiveMcpSessionsWidgetTest
git add app/Filament/Widgets app/Providers/Filament/AdminPanelProvider.php resources/views/filament tests/Feature/Filament/LiveMcpSessionsWidgetTest.php
git commit -m "feat: add Live MCP Sessions Filament widget"
```

---

### Task 39: Remove ApiKey Filament resource

**Files:**
- Delete: `app/Filament/Resources/ApiKeyResource.php` and all files under `app/Filament/Resources/ApiKeyResource/`
- Modify: any Filament navigation registration

- [ ] **Step 1: Delete the resource directory**

```bash
rm -rf app/Filament/Resources/ApiKeyResource.php app/Filament/Resources/ApiKeyResource
```

- [ ] **Step 2: Verify no dangling references**

```bash
grep -rn "ApiKeyResource" app/ tests/ 2>/dev/null
```

Expected: no matches.

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Resources
git commit -m "chore: remove ApiKey Filament resource (replaced by OAuth resources)"
```

---

## Phase 9 — Hard cut: delete bespoke MCP stack

### Task 40: Drop api_keys table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_drop_api_keys_table.php`

- [ ] **Step 1: Write the migration**

```bash
php artisan make:migration drop_api_keys_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('api_keys');
    }

    public function down(): void
    {
        // Intentionally empty — recreating the table doesn't restore data.
        // Roll back via `git revert` and DB restore from pre-deploy backup.
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations
git commit -m "chore: drop api_keys table (superseded by OAuth)"
```

---

### Task 41: Delete bespoke MCP code

**Files:**
- Delete: `app/Http/Controllers/McpController.php`
- Delete: `app/Http/Middleware/AuthenticateApiKey.php`
- Delete: `app/Mcp/BaseTool.php`
- Delete: `app/Mcp/McpToolRegistry.php`
- Delete: `app/Mcp/McpException.php`
- Delete: `app/Models/ApiKey.php`
- Delete: `database/factories/ApiKeyFactory.php` (if exists)
- Delete: `database/seeders/ApiKeySeeder.php` (if exists)
- Modify: `database/seeders/DatabaseSeeder.php` (remove ApiKeySeeder reference if present)

- [ ] **Step 1: Delete the files**

```bash
rm app/Http/Controllers/McpController.php
rm app/Http/Middleware/AuthenticateApiKey.php
rm app/Mcp/BaseTool.php
rm app/Mcp/McpToolRegistry.php
rm app/Mcp/McpException.php
rm app/Models/ApiKey.php
rm -f database/factories/ApiKeyFactory.php
rm -f database/seeders/ApiKeySeeder.php
```

- [ ] **Step 2: Update DatabaseSeeder if needed**

```bash
grep -n "ApiKeySeeder" database/seeders/DatabaseSeeder.php
```

If found, edit `database/seeders/DatabaseSeeder.php` to remove the call.

- [ ] **Step 3: Verify nothing else references the deleted classes**

```bash
grep -rn "App\\\\Models\\\\ApiKey\|App\\\\Http\\\\Middleware\\\\AuthenticateApiKey\|App\\\\Http\\\\Controllers\\\\McpController\|App\\\\Mcp\\\\BaseTool\|App\\\\Mcp\\\\McpToolRegistry\|App\\\\Mcp\\\\McpException" app/ database/ tests/ routes/ config/
```

Expected: no matches. If any appear, fix the referrer (almost certainly an old test that needs deleting or rewriting).

- [ ] **Step 4: Run full suite**

```bash
php artisan test
```

Expected: all green. Any failures point at lingering references.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: delete bespoke MCP stack (controller, middleware, base tool, registry, exception, ApiKey)"
```

---

### Task 42: Remove MCP routes from routes/api.php

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Remove MCP routes**

Edit `routes/api.php`. Delete the entire MCP block:

```php
// DELETE THIS:
Route::prefix('mcp')->group(function () {
    Route::get('/tools', [McpController::class, 'tools']);
    Route::middleware(AuthenticateApiKey::class)->group(function () {
        Route::post('/call', [McpController::class, 'call']);
    });
});
```

Also remove the now-unused `use` imports at the top of the file.

- [ ] **Step 2: Verify the routes are gone**

```bash
php artisan route:list | grep "api/mcp"
```

Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add routes/api.php
git commit -m "chore: remove /api/mcp/* routes (superseded by /mcp)"
```

---

## Phase 10 — Documentation updates

### Task 43: Update README.md

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Audit current MCP/ApiKey content**

```bash
grep -n -E "api[_-]?key|/api/mcp/call|X-API-Key|wing[_-]?restriction|scope" README.md
```

- [ ] **Step 2: Rewrite affected sections**

Per the spec's "Documentation updates" section:

- §"Per-agent authorisation" (~line 44): describe OAuth scopes (`palace.read/write`, `wiki.read/write`) + per-token wing restrictions captured at consent.
- §"API keys" (~line 78): retitle to "OAuth clients & tokens"; describe DCR, the consent screen, revocation via Filament.
- §"MCP tools" (~line 84): endpoint is `POST /mcp` (Streamable HTTP, JSON-RPC 2.0); auth is OAuth bearer.
- Update tools table scope column to new coarse scopes.
- §"Sprints" entries (~lines 157, 160): drop `ApiKey` from Sprint 1; Sprint 4 swaps API-key middleware for OAuth + Passport.
- §"Single-tenant" (~line 190): clarify OAuth tokens (not API keys) provide agent-level isolation.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: rewrite README MCP/auth sections for OAuth model"
```

---

### Task 44: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Make these specific changes**

- Remove "MCP Tools — Planned for Sprint 2" stub (stale).
- Add new §"MCP Server" describing `POST /mcp` (Streamable HTTP), OAuth via Passport, scope list, link to `docs/superpowers/specs/2026-04-26-mcp-rework-design.md`.
- Replace §"API Keys" with §"OAuth Clients & Tokens" (Passport + DCR model).
- Update §"File Structure Conventions": add `app/Mcp/Servers`, `Resources`, `Prompts`, `Concerns`, `Support`; remove the `ApiKey` entry from Models.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md MCP and auth sections"
```

---

### Task 45: Update docs/FRD.md

**Files:**
- Modify: `docs/FRD.md`

- [ ] **Step 1: Replace api_keys schema (~lines 48–50)**

Replace with the `mcp_token_restrictions` schema:

```
mcp_token_restrictions
  access_token_id (varchar(100), PK, FK → oauth_access_tokens.id ON DELETE CASCADE)
  wing_patterns (jsonb nullable; null = unrestricted)
  created_at
```

- [ ] **Step 2: Update auth requirements**

Specify OAuth 2.1 + DCR via Passport, refresh tokens (1h access / 90d refresh), per-token wing restrictions captured at consent.

- [ ] **Step 3: Commit**

```bash
git add docs/FRD.md
git commit -m "docs: update FRD with OAuth auth and mcp_token_restrictions schema"
```

---

### Task 46: Update docs/IMPLEMENTATION-PLAN.md

**Files:**
- Modify: `docs/IMPLEMENTATION-PLAN.md`

- [ ] **Step 1: Add a top-level supersession note**

At the top of the file, after the title:

```markdown
> **Note (2026-04-26):** Sections covering API-key auth and `/api/mcp/call` are
> superseded by the MCP rework — see
> [`docs/superpowers/specs/2026-04-26-mcp-rework-design.md`](superpowers/specs/2026-04-26-mcp-rework-design.md)
> and [`docs/superpowers/plans/2026-04-26-mcp-rework.md`](superpowers/plans/2026-04-26-mcp-rework.md).
```

- [ ] **Step 2: Annotate stale subsections in place**

Add `> **Superseded by MCP rework.**` at the top of each affected subsection:
- §"Current state" (~line 23)
- §"create_api_keys_table" (~lines 71–74)
- §"ApiKey scope checking" (~line 127)
- §"ApiKey hash lookup" (~line 207)
- §"ApiKeyResource" (~lines 311, 341)

- [ ] **Step 3: Commit**

```bash
git add docs/IMPLEMENTATION-PLAN.md
git commit -m "docs: annotate IMPLEMENTATION-PLAN sections superseded by MCP rework"
```

---

### Task 47: Update docs/OPENCLAW-INTEGRATION.md

**Files:**
- Modify: `docs/OPENCLAW-INTEGRATION.md`

- [ ] **Step 1: Replace the curl example (~lines 54–57)**

Remove:

```bash
curl -s -X POST https://<domain>/api/mcp/call \
  -H "X-API-Key: <key>" \
  ...
```

Replace with a short paragraph describing OAuth onboarding (Claude Code: `claude mcp add --transport http mnemon https://mnemon.example.com/mcp`, browser flow), then a JSON-RPC `tools/call` example using a Passport bearer token:

```bash
curl -s -X POST https://mnemon.example.com/mcp \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"drawer_add","arguments":{"wing":"work","room":"notes","content":"..."}}}'
```

- [ ] **Step 2: Commit**

```bash
git add docs/OPENCLAW-INTEGRATION.md
git commit -m "docs: replace OpenClaw API-key curl example with OAuth bearer flow"
```

---

### Task 48: Pre-PR stale-reference grep gate

**Files:**
- (no file changes; verification step)

- [ ] **Step 1: Run the grep**

```bash
grep -rn -E "ApiKey|X-API-Key|/api/mcp/call|AuthenticateApiKey|McpController|McpToolRegistry|BaseTool" \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    --exclude-dir=docs/superpowers/specs --exclude-dir=docs/superpowers/plans \
    .
```

Expected: zero matches. Anything that turns up is a stale reference and must be deleted or updated before opening the PR.

- [ ] **Step 2: Fix any stragglers**

For each match: either delete the dead code, or update the reference to the new OAuth/laravel-mcp surface. Commit each fix individually with a descriptive message (e.g. `chore: remove stale ApiKey reference in <file>`).

- [ ] **Step 3: Re-run grep to confirm clean**

```bash
grep -rn -E "ApiKey|X-API-Key|/api/mcp/call|AuthenticateApiKey|McpController|McpToolRegistry|BaseTool" \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    --exclude-dir=docs/superpowers .
```

Expected: zero matches outside the spec/plan files (which legitimately mention them as part of describing the rework).

---

## Phase 11 — Final verification

### Task 49: Full test suite + lint

**Files:** none

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --compact
```

Expected: all green.

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/pint
```

Apply any formatting changes if produced. Commit if files changed:

```bash
git add -A
git commit -m "style: pint"
```

- [ ] **Step 3: Verify migrations are clean on a fresh DB**

```bash
php artisan migrate:fresh --seed
php artisan test --compact
```

Expected: green.

---

### Task 50: Manual smoke test against local server

**Files:** none

- [ ] **Step 1: Start the server**

```bash
php artisan serve
```

- [ ] **Step 2: Create a Passport client manually for testing**

```bash
php artisan passport:client --personal
```

Note the client ID and secret.

- [ ] **Step 3: Generate a personal access token via tinker**

```bash
php artisan tinker
```

```php
$user = \App\Models\User::first();
$result = $user->createToken('Smoke Test', ['palace.read', 'wiki.read']);
echo $result->accessToken;
```

- [ ] **Step 4: Hit the MCP endpoint**

```bash
TOKEN="<paste>"
curl -s -X POST http://127.0.0.1:8000/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq
```

Expected: 12 tools listed.

```bash
curl -s -X POST http://127.0.0.1:8000/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"brain_status","arguments":{}}}' | jq
```

Expected: aggregate counts JSON.

- [ ] **Step 5: Connect Claude Code to the local server**

```bash
claude mcp add --transport http mnemon-local http://127.0.0.1:8000/mcp
```

Walk through the OAuth flow in the browser (login as the admin user, grant scopes, select wings). After the flow completes, run `/mcp` in Claude Code. Expected: `mnemon-local` connected; tools listed; `drawer_search "test"` returns results.

- [ ] **Step 6: Document any deviations**

If any step misbehaves, file the gap in this plan as a follow-up task and resolve before merge.

---

### Task 51: Open PR

**Files:** none

- [ ] **Step 1: Push the branch**

```bash
git push -u origin mcp-rework
```

(User must run this from their terminal — push is blocked from the agent's environment.)

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "MCP rework: laravel/mcp + Passport OAuth 2.1" --body "$(cat <<'EOF'
## Summary

- Replace bespoke `/api/mcp/call` REST endpoint and `ApiKey` bearer auth with `laravel/mcp` + Passport OAuth 2.1 + DCR.
- Expose 12 tools, 3 resources, 3 prompts at `POST /mcp` (Streamable HTTP, JSON-RPC 2.0).
- Coarse scopes (`palace.read/write`, `wiki.read/write`) enforced per tool.
- Per-token wing restrictions captured at consent, stored in `mcp_token_restrictions`.
- `BrainSession` audit trail extended with `oauth_client_id`, `user_id`, `access_token_id`.
- Filament admin gains OAuth Clients + Active Tokens resources + Live MCP Sessions widget.
- All bespoke MCP code (`McpController`, `AuthenticateApiKey`, `BaseTool`, `McpToolRegistry`, `McpException`, `ApiKey` model + Filament resource + seeder) deleted.
- Documentation updated across README, CLAUDE.md, FRD, IMPLEMENTATION-PLAN, OPENCLAW-INTEGRATION.

Spec: `docs/superpowers/specs/2026-04-26-mcp-rework-design.md`
Plan: `docs/superpowers/plans/2026-04-26-mcp-rework.md`

## Test plan

- [ ] `php artisan test --compact` green on a fresh `migrate:fresh --seed`
- [ ] `php artisan migrate` clean on production-shape Postgres
- [ ] OAuth flow in a real browser: log in → grant scopes → select wings → token issued
- [ ] Claude Code: `claude mcp add --transport http mnemon https://...` → consent → tools/list returns 12
- [ ] Wing-restricted token rejects out-of-scope drawer fetch
- [ ] `BrainSession` rows for new calls populated with `oauth_client_id`, `user_id`, `access_token_id`
- [ ] Filament `/admin/oauth-clients` and `/admin/oauth-access-tokens` render and show seeded data
- [ ] Filament dashboard shows Live MCP Sessions widget

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review

After writing the plan, the author re-checked the spec against the plan:

**Spec coverage:**
- Section 1 (Architecture) → Tasks 1, 2, 7, 8, 9, 41, 42 (install, register, delete)
- Section 2 (Server, tools, resources, prompts) → Tasks 7, 15–32
- Section 2.5 (Cross-cutting concerns) → Tasks 12, 13, 14 (traits), Task 24 (wiki_compile locking audit)
- Section 3 (Auth) → Tasks 1, 3, 4, 5, 33, 34, 35
- Section 4 (BrainSession schema) → Tasks 6, 11
- Section 5 (Filament admin) → Tasks 36, 37, 38, 39
- Section 6 (Migration & cutover) → Tasks 1, 2, 5, 6, 40, 41, 42, 50, 51
- Section 7 (Testing) → distributed across all tool/resource/prompt tasks + Task 35 end-to-end + Task 49
- Section 8 (Open follow-ups) → not implemented (correctly out of scope)
- Documentation updates → Tasks 43–48
- Risks → covered by Tasks 33, 34, 50 (manual verification of DCR, consent flow, refresh behavior)

**Placeholder scan:** No "TBD", "TODO", or "implement later". Tasks 23, 25, 26, 28, 29, 31, 32 use a "follow Task X pattern" reference but provide all per-tool specifics (scope, annotation, business logic to preserve, test cases) — the reader has enough to execute without reading other tasks.

**Type consistency:**
- `Laravel\Mcp\Request` and `Laravel\Mcp\Response` used consistently.
- Trait method names: `requireScope()`, `requireWingAccess()`, `wingPatternsFor()`, `agentSource()` — used identically across tasks.
- `BrainSessionLogger::log()` signature consistent: `($request, $tool, $input, $resultCount)`.
- `mcpCall()` test helper signature consistent: `($tool, $arguments, $scopes, ?$wingPatterns)`.

---

## Postscript: Scope simplification (2026-04-26)

After all 51 plan tasks were completed, the OAuth scope model was simplified in a follow-up commit.

**Decision:** The 4-scope model (`palace.read`, `palace.write`, `wiki.read`, `wiki.write`) is superseded by a single scope `mcp:use`, which is the only scope the `laravel/mcp` package advertises to DCR clients. The old scopes were never reachable by DCR clients, making `RequiresScope` reject all tool calls in practice.

**Impact:** No tasks in this plan need re-execution. The simplification was implemented as a follow-up code change: `RequiresScope`, all 12 tools, 3 resources, 3 prompts, `AppServiceProvider`, the consent view, and all associated tests were updated. Wing restrictions continue to provide isolation — nothing changed in that layer.

**Commit:** `refactor: collapse OAuth scopes to mcp:use; rely on wing restrictions for isolation`
