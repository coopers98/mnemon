# Device Authorization Grant — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Devices running the Layer 2 hooks authenticate via the OAuth device authorization grant (RFC 8628) instead of 90-day personal access tokens, self-renew via refresh tokens, and carry per-device wing restrictions — with every failure mode loud.

**Architecture:** Server side: wing restrictions move from per-token to per-client (new `mcp_client_restrictions` table, written synchronously at consent), the two Passport device screens get bound and built, discovery metadata learns the device grant, and refresh-token rotation is turned off. Client side: `mnemon_call()` in the plugin's `common.sh` gains a locked, retry-once refresh path; a new `mnemon-authorize.sh` enrols a device; the wake hook grows a dead-credential banner and loses the false expiry warning. Ships as plugin 0.3.0.

**Tech Stack:** Laravel 13.6, Passport 13.7.5, laravel/mcp 0.7.0, PostgreSQL 17 + pgvector / SQLite (tests), bash + jq + curl hooks, Python `http.server` fake for the hook harness.

**Spec:** `docs/design/2026-09-02-device-authorization-grant-design.md` (revised after adversarial review). This plan argues from that spec; read both. Where this plan diverges, the divergence is stated inline and collected in the next section.

---

## Divergences from the design, and design errata found while planning

Every claim below was verified against the working tree at `19b2f19` and, where marked, against the live instance on 2026-09-03.

1. **§2 contradicts §4.3 and loses.** §2's "Capturing the wings" paragraph still describes the draft pipeline (cache under `mcp_consent_wings:{userId}:{clientId}`, `AccessTokenCreated` listener, hidden `client_id` field on the device form) that the rewritten §4.3 explicitly deletes. §2 was not updated after the review. This plan follows §4.3: the approve request writes a client-keyed row synchronously; the cache, the listener (`app/Listeners/PersistMcpTokenRestrictions.php`), and the hidden `client_id` field on the device form are all removed. The device approve derives the client id from the session's serialized device code — the same source Passport's own approve controller uses — so no form field is needed or trusted.
2. **§9 row 11 and two §10 test bullets describe the abandoned draft.** "Deny-all row written on a missing restriction after refresh" and "the refreshed token carries the *copied* restriction row" belong to the carry-forward design §4.3 replaced. Under client keying there is no copy and no deny-all fallback. The regression test that replaces them: *a token issued by the `refresh_token` grant is still restricted, because enforcement resolves the client row* (Task 1, Step 1).
3. **§4.1's second "loudness fix" is already shipped.** The design says a JSON-RPC `.error` is "swallowed with a bare `return 1` (`common.sh:123-126`)". In the current `plugins/mnemon/hooks/lib/common.sh` (line 126) that path already logs `rpc error from $endpoint: ...` — it rode along with PR #30. No work item; the plan drops it.
4. **The design's dead-state trigger misses its own kill switch.** §4.2 and §9 row 7 mark a device dead only on `invalid_grant`. But the feature's kill switch is *client revocation* (`TokenRevoker::client`, PR #29), and League rejects a revoked client at `validateClient` — **before** the refresh token is examined — with `invalid_client`. Under the design's rule a revoked device would retry forever and never show the wake banner: the fifteen-defect silence pattern, built into the kill switch itself. This plan treats `invalid_grant`, `invalid_client`, and `unauthorized_client` all as definitive dead states (Task 7).
5. **§8's discovery override is incomplete.** `laravel/mcp` registers not only the exact `/.well-known/oauth-authorization-server` route but also an unconditional wildcard `/.well-known/oauth-authorization-server/{path}` (`vendor/laravel/mcp/src/Server/Registrar.php:105-112`). RFC 8414 path-scoped lookups (e.g. `/.well-known/oauth-authorization-server/mcp`, which clients derive from an issuer with a path) would hit the stale package copy. This plan overrides both routes (Task 4).
6. **§6 says bump "from 0.2.0" — the plugin is at 0.2.1**, and `.claude-plugin/marketplace.json` (repo root) must move in lockstep or `PluginManifestTest::test_plugin_and_marketplace_versions_agree` fails. The design never mentions the marketplace file. Next version: **0.3.0**, bumped in both files (Task 10).
7. **§1.1 step 5 still says the setup script verifies with `tools/list`** — which revision item 4 of the same document rejects as proving nothing. The script verifies with a real `tools/call` of `palace_wake_up` and prints the wing-filtered `active_wings` (Task 9). One caveat the design doesn't state: `active_wings` lists only wings that contain drawers, so a reachable-but-empty wing won't appear; the script says so in its output.
8. **Rotation: recommendation is OFF — `Passport::$revokeRefreshTokenAfterUse = false`** (Task 2). Verified in `vendor/league/oauth2-server/src/Grant/RefreshTokenGrant.php:74-96`: with rotation off, a **new refresh token is still issued on every exchange** (so devices still self-renew and the 90-day window still slides), the old access token is still revoked, and only the revocation of the *old refresh token* is skipped — which removes the deterministic lost-response → `invalid_grant` → browser-re-auth failure the review identified, and demotes the hook lock from correctness mechanism to efficiency mechanism. Consequence the design doesn't spell out: under no-rotation, superseded refresh tokens stay valid until their own expiry, so **the reliable per-device kill switch is revoking the client** (which `TokenRevoker::client` handles); revoking a single token only kills the device if it is the newest one. Documented in Task 10.
9. **Migration shape: a separate `mcp_client_restrictions` table, not a nullable `client_id` column on `mcp_token_restrictions`.** The design (and the task brief) call for widening the existing table. The existing table's primary key *is* `access_token_id` (`database/migrations/2026_04_27_040447_create_mcp_token_restrictions_table.php`), so client-keyed rows in the same table require dropping the PK and making the column nullable — PK surgery that SQLite can only do by table recreation and that Laravel's schema builder does not express portably (`dropPrimary` is unsupported on SQLite). Engine-conditional migrations are exactly the class of divergence that shipped two of this feature's fifteen defects. A new table gives: a natural PK (`client_id`), a native FK to `oauth_clients` with cascade (the restriction dies with the client — the lifecycle §4.3 wants, for free), an untouched legacy table so every existing token-keyed test and Filament column keeps working as the fallback, and a ten-line migration with nothing conditional. Enforcement is unchanged from the design's words: **prefer the client row, fall back to the token row.**
10. **Consent with all-wings unchecked and zero wings selected currently means *unrestricted*** (`PersistMcpTokenRestrictions::storeConsentData`: `empty($wings) ? null`). That is a silent fail-open on the consent screen itself. This plan stores `[]` (deny-all, which `matches()` already implements) and adds a client-side guard so honest users can't submit that shape (Tasks 1 and 3). Deliberate behavior change, tested.
11. **Path erratum in the task brief (not the design):** the shared HTTP entry point is `plugins/mnemon/hooks/lib/common.sh` — there is no `hooks/hooks/lib` nesting. The design doc has it right.
12. **Verified live 2026-09-03:** `GET https://mnemon.example.com/oauth/device` → **500** (design's failure mode 15, confirmed still present); `/.well-known/oauth-authorization-server` lacks `device_authorization_endpoint` and the device grant; `POST /oauth/device/code` with a malformed client id → **401** (PR #28's guard is live).

**Honest uncertainties, so nobody debugs them cold:**

- The device-approve middleware (Task 3) unserializes the session's `deviceCode` with the same `allowed_classes` list Passport's `RetrievesDeviceCodeFromSession` uses. If a future Passport patch changes the serialized shape, the middleware returns null and the consent row is skipped while the approve succeeds — the tests in Task 3 pin the current shape, and the DeviceFlowTest end-to-end assertion (restriction actually bites on `/mcp`) would catch a regression, but only in CI, not at runtime. Accepted; noted in the middleware comment.
- `currentAccessToken()` returns `Laravel\Passport\AccessToken` (JWT attributes) on the `auth:api` path and a `Token` model under `Passport::actingAs`/PAT test paths. Task 1 resolves the client id with an explicit `instanceof` branch and covers both paths with tests; if a third shape exists somewhere, `resolveRestriction` falls back to the token row (fail-safe: at worst a *device* token behaves like today's PAT, i.e. the current behavior, never a widened one — because device tokens always have a client row after consent).
- The stampede test (Task 7) asserts "exactly one refresh" under a 3-way concurrent 401. The lock makes this deterministic in principle; on a pathologically slow runner a waiter could time out (5 s) and log "lock busy" instead of retrying with the winner's token. The test asserts on the fake server's request log (one `/oauth/token` hit), which is stable; the per-hook outcomes are asserted loosely.

---

## Global Constraints

Copied from the brief and the design; every task's requirements include these.

- **TDD, strictly.** Each task states the failing test, runs it to prove the failure, then implements. PHP suite: `php artisan test --compact` (459 tests at baseline). Hook harness: `bash tests/Hooks/run-hook-tests.sh` (30 tests at baseline).
- **PostgreSQL is load-bearing.** Any task touching uuid columns, FKs, or JSON columns runs the PHP suite against the local pgvector container **in addition to** SQLite:
  ```bash
  docker run -d --name mnemon-pg-test -e POSTGRES_USER=mnemon -e POSTGRES_PASSWORD=mnemon \
    -e POSTGRES_DB=mnemon_test -p 55432:5432 pgvector/pgvector:pg17   # once
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
  DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none php artisan test --compact
  ```
  CI runs both legs (`.github/workflows/ci.yml`, matrix `[sqlite, pgsql]`).
- **Loudness.** Every task names where its failure becomes visible. `~/.mnemon/capture-errors.log` alone never counts — the only surfaces that count are: stdout of a hook at SessionStart (the user sees it), stdout/exit code of a script the user is running, an HTTP error in front of the person clicking, a failing test, or a Filament screen.
- **Plugin version bump.** Any change under `plugins/mnemon/` reaches devices only after `plugins/mnemon/.claude-plugin/plugin.json` **and** `.claude-plugin/marketplace.json` bump (0.2.1 → 0.3.0, Task 10). Tasks 7–9 land on one branch and ship as one plugin release.
- **Sabotage checks.** The harness has passed for the wrong reason before. Every new hook-harness assertion includes a step that deliberately breaks the implementation and confirms the test fails, then reverts.
- **Live verification.** The instance is `https://mnemon.example.com`; `ssh forge-atlas` works. Tasks 6 and 11 are live checkpoints.
- **Formatting:** `./vendor/bin/pint` before every commit.
- **Branches/PRs:** one branch per shippable unit as flagged per task; PRs into `main`.

## File structure (what exists where, when this is done)

```
app/Http/Middleware/CaptureConsentWings.php     — writes client-keyed rows synchronously (both consent routes)
app/Models/McpClientRestriction.php             — NEW: client-keyed restriction row
app/Models/Concerns/MatchesWingPatterns.php     — NEW: matches() shared by both restriction models
app/Models/McpTokenRestriction.php              — legacy fallback, matches() moved to trait
app/Mcp/Concerns/RequiresWingAccess.php         — resolves client row first, token row second
app/Listeners/PersistMcpTokenRestrictions.php   — DELETED
app/Console/Commands/CreateDeviceClient.php     — NEW: mnemon:device-client
app/Providers/AppServiceProvider.php            — device view bindings; rotation off; listener wiring removed
database/migrations/2026_09_03_000001_create_mcp_client_restrictions_table.php — NEW
resources/views/mcp/device-user-code.blade.php  — NEW
resources/views/mcp/device-authorize.blade.php  — NEW
routes/ai.php                                   — app-owned discovery metadata (exact + nested) above Mcp::oauthRoutes()
plugins/mnemon/hooks/lib/common.sh              — mnemon_config_write, mnemon_refresh_available, mnemon_refresh, 401 retry
plugins/mnemon/hooks/mnemon-wake.sh             — dead banner, proactive refresh, countdown skip, new unconfigured message
plugins/mnemon/scripts/mnemon-authorize.sh      — NEW: enrolment script
plugins/mnemon/.claude-plugin/plugin.json       — 0.3.0
.claude-plugin/marketplace.json                 — 0.3.0
tests/Feature/Mcp/ClientRestrictionTest.php     — NEW
tests/Feature/Mcp/DeviceFlowTest.php            — NEW
tests/Feature/Console/CreateDeviceClientTest.php— NEW
tests/Hooks/fixtures/server.sh                  — /oauth/token + device-flow modes
tests/Hooks/run-hook-tests.sh                   — refresh, stampede, dead-state, authorize-script tests
docs/USERGUIDE.md                               — install section inverted to the device flow
```

---

## Task 0: Baseline checkpoint

No code. Prove the starting state so later "it broke" bisects have an anchor.

- [ ] **Step 1: Run both suites and record counts**

```bash
cd /root/projects/mnemon && php artisan test --compact
bash tests/Hooks/run-hook-tests.sh
```
Expected: `459 passed` (PHP), `30 passed, 0 failed` (hooks). If not, stop — fix `main` first.

- [ ] **Step 2: Confirm the live 500 and stale metadata (the two things Phase 0 exists to fix)**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://mnemon.example.com/oauth/device
curl -s https://mnemon.example.com/.well-known/oauth-authorization-server | jq '.grant_types_supported, .device_authorization_endpoint'
```
Expected: `500`, then `["authorization_code","refresh_token"]` and `null`. (Confirmed 2026-09-03 while writing this plan.)

---

## Task 1: Client-keyed wing restrictions

**Independently shippable: yes — PR "feat: wing restrictions keyed on the client, not the token".** This closes the verified defect where a refreshed token silently loses its restrictions, and it is a prerequisite for Task 3 (device consent writes client rows).

**Files:**
- Create: `database/migrations/2026_09_03_000001_create_mcp_client_restrictions_table.php`
- Create: `app/Models/McpClientRestriction.php`
- Create: `app/Models/Concerns/MatchesWingPatterns.php`
- Create: `tests/Feature/Mcp/ClientRestrictionTest.php`
- Modify: `app/Models/McpTokenRestriction.php` (matches() → trait)
- Modify: `app/Http/Middleware/CaptureConsentWings.php` (synchronous client-keyed write)
- Modify: `app/Mcp/Concerns/RequiresWingAccess.php` (resolution order)
- Modify: `app/Providers/AppServiceProvider.php` (remove `Event::listen(AccessTokenCreated::class, ...)` and its imports)
- Delete: `app/Listeners/PersistMcpTokenRestrictions.php`
- Modify: `app/Filament/Resources/OauthAccessTokens/Tables/OauthAccessTokensTable.php:43` (prefer client row)
- Modify: `app/Filament/Resources/OauthClients/Tables/OauthClientsTable.php` (show the client's wings)
- Modify: `tests/Feature/Mcp/EndToEndOAuthTest.php` (the refresh regression test)
- Modify: `tests/Feature/Mcp/OAuthFlowTest.php:96-129` (assert client rows, not token rows)

**Interfaces:**
- Consumes: `McpTokenRestriction::matches(string $wingSlug): bool` (existing, moves to trait unchanged).
- Produces (later tasks rely on these exact names):
  - `App\Models\McpClientRestriction` — table `mcp_client_restrictions`, PK `client_id` (string uuid), `wing_patterns` `?array` cast, `matches(string): bool`, `$timestamps = false`.
  - `CaptureConsentWings::consentPatterns(Request): ?array` — `null` = all wings, `[]` = deny-all, else slugs. Task 3 reuses the middleware for the device route.
  - Restriction resolution contract: client row preferred, token row fallback, absence = unrestricted.

- [ ] **Step 1: Write the failing regression test — the single most important test in this plan**

Append to `tests/Feature/Mcp/EndToEndOAuthTest.php` (it already walks consent → token → `/mcp`; add `'oauth/device/authorize'` is not needed here). Also add `'oauth/token'` to the CSRF excepts if not present (it is).

```php
public function test_wing_restriction_survives_a_token_refresh(): void
{
    $work = Wing::factory()->create(['slug' => 'work']);
    $personal = Wing::factory()->create(['slug' => 'personal']);
    $workRoom = Room::factory()->create(['wing_id' => $work->id]);
    $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
    Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-secret']);
    Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-secret']);

    $user = User::factory()->create();
    $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
    $this->actingAs($user);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
        'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'st',
    ]))->assertStatus(200);
    $authToken = app('session.store')->get('authToken');

    $approve = $this->post('/oauth/authorize', [
        'auth_token' => $authToken, 'client_id' => $client->id,
        'scopes' => ['mcp:use'], 'wings' => ['work'],
    ]);
    parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY), $q);

    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'http://localhost/cb', 'code' => $q['code'],
    ])->json();

    // The defect: a refreshed token has a new id, no restriction row existed
    // for it, and a missing row means unrestricted. One hour after consent,
    // a work-only device silently became an all-wings device.
    $refreshed = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        'client_id' => $client->id, 'client_secret' => $client->plainSecret,
        'scope' => 'mcp:use',
    ]);
    $refreshed->assertStatus(200);

    $r = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
    ], ['Authorization' => 'Bearer '.$refreshed->json('access_token')]);

    $contents = collect($r->json('result.structuredContent.results'))->pluck('content')->all();
    $this->assertContains('work-secret', $contents);
    $this->assertNotContains('personal-secret', $contents,
        'a refreshed token must keep the wing restriction consented to — the restriction belongs to the device, not to the hour-long token');
}
```

- [ ] **Step 2: Run it and verify it fails for the right reason**

```bash
php artisan test --compact --filter=test_wing_restriction_survives_a_token_refresh
```
Expected: FAIL with `personal-secret should not be contained` (i.e. `assertNotContains` — the refreshed token sees everything). If it fails earlier (e.g. on the refresh call), stop and diagnose: that would be the §7 "attributed, not proven" refresh 500 resurfacing, and it outranks this task.

- [ ] **Step 3: Write the consent-write failing tests**

Create `tests/Feature/Mcp/ClientRestrictionTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpClientRestriction;
use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class ClientRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        VerifyCsrfToken::except(['oauth/authorize', 'oauth/token']);
    }

    private function approveConsent(array $extra): Client
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
        $this->actingAs($user);
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'st',
        ]))->assertStatus(200);

        $this->post('/oauth/authorize', array_merge([
            'auth_token' => app('session.store')->get('authToken'),
            'client_id' => $client->id, 'scopes' => ['mcp:use'],
        ], $extra))->assertRedirect();

        return $client;
    }

    public function test_approve_writes_a_client_keyed_row_before_any_token_exists(): void
    {
        $client = $this->approveConsent(['wings' => ['work']]);

        $row = McpClientRestriction::find($client->id);
        $this->assertNotNull($row, 'consent must persist synchronously in the approve request, not via a cache handoff');
        $this->assertSame(['work'], $row->wing_patterns);
        $this->assertSame(0, McpTokenRestriction::count(), 'no token-keyed row is written any more');
    }

    public function test_all_wings_writes_a_null_pattern_row(): void
    {
        $client = $this->approveConsent(['all_wings' => '1']);
        $this->assertNull(McpClientRestriction::find($client->id)->wing_patterns);
    }

    public function test_no_selection_without_all_wings_is_deny_all_not_unrestricted(): void
    {
        $client = $this->approveConsent(['wings' => []]);
        $row = McpClientRestriction::find($client->id);
        $this->assertSame([], $row->wing_patterns,
            'an empty selection must fail closed; the old null meant silently unrestricted');
        $this->assertFalse($row->matches('work'));
    }

    public function test_reconsent_updates_the_existing_row(): void
    {
        $client = $this->approveConsent(['wings' => ['work']]);
        // Second consent for the same client widens to all wings.
        $this->approveConsent2($client, ['all_wings' => '1']);
        $this->assertNull(McpClientRestriction::find($client->id)->wing_patterns);
        $this->assertSame(1, McpClientRestriction::count());
    }

    public function test_legacy_token_row_still_enforced_when_no_client_row_exists(): void
    {
        // The fallback: instances that used the auth-code flow before this
        // change have token-keyed rows and no client rows.
        $user = User::factory()->create();
        app(\Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Test', 'users');
        $token = $user->createToken('legacy', ['mcp:use'])->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work']]);

        \Laravel\Passport\Passport::actingAs($user, ['mcp:use']);
        $user->withAccessToken($token);

        $wing = \App\Models\Wing::factory()->create(['slug' => 'personal']);
        $room = \App\Models\Room::factory()->create(['wing_id' => $wing->id]);
        \App\Models\Drawer::factory()->create(['room_id' => $room->id, 'content' => 'personal-secret']);

        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
        ]);
        $contents = collect($r->json('result.structuredContent.results'))->pluck('content')->all();
        $this->assertNotContains('personal-secret', $contents);
    }
}
```

`approveConsent2` is `approveConsent` minus the user/client creation — implement as a second private helper taking the client. (If `Passport::actingAs` + `withAccessToken` in the legacy test fights the `/mcp` guard, follow the pattern `tests/Concerns/MakesMcpRequests.php` already uses to attach a restricted token — that concern exists precisely for this.)

- [ ] **Step 4: Run them; expected failures**

```bash
php artisan test --compact --filter=ClientRestrictionTest
```
Expected: every test errors with `Class "App\Models\McpClientRestriction" not found` — proving the table/model don't exist yet. Nothing passes by accident.

- [ ] **Step 5: Migration**

`database/migrations/2026_09_03_000001_create_mcp_client_restrictions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wing restrictions are a property of the device (= OAuth client),
        // not of whichever hour-long access token it currently holds. Keying
        // on the client is what makes refresh need no restriction logic at
        // all, and makes passport:purge harmless: purging tokens cannot
        // delete a client-keyed row. mcp_token_restrictions stays as the
        // enforcement fallback for legacy token-keyed rows.
        Schema::create('mcp_client_restrictions', function (Blueprint $table) {
            $table->uuid('client_id')->primary();
            $table->json('wing_patterns')->nullable();   // null = all wings
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('client_id')
                ->references('id')->on('oauth_clients')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_client_restrictions');
    }
};
```

**PostgreSQL-specific:** `oauth_clients.id` is a native `uuid` column on PostgreSQL (PR #28's whole subject) and a varchar on SQLite. `$table->uuid()` matches both, but an FK **type mismatch errors only on PostgreSQL** — SQLite would accept anything. This migration is therefore only proven by the pgsql leg; Step 10 runs it locally against the 55432 container, and CI's pgsql leg pins it forever.

- [ ] **Step 6: Trait + models**

`app/Models/Concerns/MatchesWingPatterns.php` — move the body of `McpTokenRestriction::matches()` verbatim:

```php
<?php

namespace App\Models\Concerns;

trait MatchesWingPatterns
{
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

`app/Models/McpClientRestriction.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\MatchesWingPatterns;
use Illuminate\Database\Eloquent\Model;

class McpClientRestriction extends Model
{
    use MatchesWingPatterns;

    protected $table = 'mcp_client_restrictions';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['client_id', 'wing_patterns', 'created_at'];

    protected $casts = ['wing_patterns' => 'array', 'created_at' => 'datetime'];
}
```

`McpTokenRestriction`: delete its inline `matches()`, add `use MatchesWingPatterns;`. Nothing else changes — `tests/Feature/McpTokenRestrictionTest.php` must still pass untouched.

- [ ] **Step 7: Rewrite `CaptureConsentWings` and delete the listener**

```php
<?php

namespace App\Http\Middleware;

use App\Models\McpClientRestriction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists the wing selection from a consent approval, keyed on the OAuth
 * client, synchronously in the approve request. If this write fails, the
 * approve request itself errors — in front of the human who clicked the
 * button, which is the loudest place a failure can occur.
 *
 * There is deliberately no cache handoff and no AccessTokenCreated listener:
 * that pipeline keyed rows on the token id, and a refreshed token has a new
 * id — which silently widened a restricted device to all wings one hour
 * after consent.
 */
class CaptureConsentWings
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->user() && $request->is('oauth/authorize')) {
            $clientId = (string) ($request->input('client_id') ?? '');

            if ($clientId !== '') {
                McpClientRestriction::updateOrCreate(
                    ['client_id' => $clientId],
                    ['wing_patterns' => $this->consentPatterns($request)]
                );
            }
        }

        return $next($request);
    }

    /**
     * null = all wings; [] = deny-all (an empty selection fails closed —
     * it used to mean "unrestricted", which is a silent widening on the
     * consent screen itself); otherwise the selected slugs.
     */
    private function consentPatterns(Request $request): ?array
    {
        if ($request->boolean('all_wings')) {
            return null;
        }

        return array_values((array) $request->input('wings', []));
    }
}
```

Then: delete `app/Listeners/PersistMcpTokenRestrictions.php`; in `AppServiceProvider` remove the `Event::listen(AccessTokenCreated::class, PersistMcpTokenRestrictions::class)` block and the now-unused `Event`, `AccessTokenCreated`, `PersistMcpTokenRestrictions` imports. Confirm nothing else references it:

```bash
grep -rn 'PersistMcpTokenRestrictions' app tests
```
Expected: no output.

Note on trust: `client_id` here comes from the form (the hidden field `resources/views/mcp/authorize.blade.php:73` has always posted). The write is restrict-only — a forged value can only *narrow* some client, never widen — and the route is behind `web` + CSRF + an authenticated session on a single-user instance. The device route (Task 3) uses the session-stored device code instead, which needs no field at all.

- [ ] **Step 8: Resolution order in `RequiresWingAccess`**

Replace `resolveRestriction` (and the two cached properties' types):

```php
private ?string $resolvedTokenId = null;

private McpClientRestriction|McpTokenRestriction|null $resolvedRestriction = null;

private function resolveRestriction(Request $request): McpClientRestriction|McpTokenRestriction|null
{
    $token = $request->user()?->currentAccessToken();
    $tokenId = $token?->id;
    if ($tokenId === null) {
        return null;
    }

    if ($this->resolvedTokenId !== $tokenId) {
        $this->resolvedTokenId = $tokenId;

        // auth:api hands back a Laravel\Passport\AccessToken (JWT claims,
        // client id under oauth_client_id); test/PAT paths hand back the
        // Token model (client_id column). An instanceof branch, not `??`:
        // AccessToken's __get has no __isset twin, so null-coalescing on it
        // would skip attributes that exist.
        $clientId = $token instanceof \Laravel\Passport\AccessToken
            ? $token->oauth_client_id
            : ($token->client_id ?? null);

        $this->resolvedRestriction = ($clientId !== null
                ? McpClientRestriction::find((string) $clientId)
                : null)
            ?? McpTokenRestriction::find($tokenId);
    }

    return $this->resolvedRestriction;
}
```

Add the `use App\Models\McpClientRestriction;` import. `requireWingAccess` and `wingPatternsFor` are unchanged — both models expose `matches()` and `wing_patterns`.

- [ ] **Step 9: Filament visibility (where a device's wings become *visible*)**

`OauthAccessTokensTable.php:43` — prefer the client row so device tokens don't display as unrestricted:

```php
$restriction = \App\Models\McpClientRestriction::find($record->client_id)
    ?? McpTokenRestriction::find($record->id);
```

`OauthClientsTable.php` — add a column after the existing name/grant columns, so the admin can see a device's wings where its kill switch lives:

```php
TextColumn::make('wings')
    ->state(function (Client $record): string {
        $row = \App\Models\McpClientRestriction::find($record->id);

        return match (true) {
            $row === null || $row->wing_patterns === null => 'all wings',
            $row->wing_patterns === [] => 'none (denied)',
            default => implode(', ', $row->wing_patterns),
        };
    }),
```

- [ ] **Step 10: Update `OAuthFlowTest` and run everything, both engines**

`tests/Feature/Mcp/OAuthFlowTest.php:96-129`: the two tests asserting `McpTokenRestriction::first()` now assert `McpClientRestriction::find($client->id)` — and move the assertion to immediately after the approve POST (before the token exchange), pinning the "synchronous, no cache" property.

```bash
php artisan test --compact
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none php artisan test --compact
```
Expected: all green on both, including Step 1's regression test and the untouched `McpTokenRestrictionTest`, `RequiresWingAccessTest`, `DenialAuditTest`, `MakesMcpRequests`-based tool tests (they exercise the token-row fallback).

- [ ] **Step 11: Lint and commit**

```bash
./vendor/bin/pint && git add -A && git commit -m "feat: wing restrictions keyed on the client, not the token"
```

**What could go wrong / where failure is visible:**
- *Synchronous write throws at approve* → HTTP 500 in the browser of the person clicking Authorize. Loud by construction.
- *FK type mismatch* → migration fails on the pgsql leg (CI red) — invisible on SQLite, which is why Step 10's second command is not optional.
- *Enforcement resolution wrong* → Step 1's regression test or `test_legacy_token_row_still_enforced` fails; at runtime a wrongly-denied device now surfaces via PR #30 (`tool error ... does not have access` on the device) plus a `BrainSession` denial row in Filament.
- *A wrongly-widened device* has no runtime tell — that is exactly why Step 1's test exists and must never be weakened.

**Checkpoint:** PR, review, merge. The server still serves PAT devices identically (they have no client row and no token row → unrestricted, as today).

---

## Task 2: Turn refresh-token rotation off

**Independently shippable: yes — small PR "feat: non-rotating refresh tokens".** Do it before the hook work so the hook design is settled against real server behavior.

**Decision (recommendation asked for by the brief): rotation OFF.** Grounds, all verified in `vendor/league/oauth2-server/src/Grant/RefreshTokenGrant.php:74-96`: with `revokeRefreshTokens(false)` the grant still issues a fresh refresh token on every exchange (self-renewal and the sliding 90-day window are preserved) and still revokes the old *access* token; it only stops revoking the old *refresh* token. That removes the deterministic failure the review found: under rotation, a lost token response leaves the device holding an already-consumed refresh token → `invalid_grant` → browser re-auth, guaranteed. Without rotation, a lost response is harmless — the device retries with the old, still-valid token. The hook lock (Task 7) is kept, but demoted to an efficiency measure; its failure mode becomes "one wasted round trip", not "bricked device". Cost accepted: superseded refresh tokens remain valid until their own expiry (≤ 90 days) or `passport:purge`; reuse detection was already out of scope (§11); the per-device kill switch is **client revocation**, which `TokenRevoker::client` already implements and Task 10 documents.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (one statement in `boot()`)
- Modify: `tests/Feature/PassportConfigurationTest.php`
- Modify: `tests/Feature/TokenRevocationTest.php` (one added test)

- [ ] **Step 1: Write the failing behavior test**

Add to `tests/Feature/PassportConfigurationTest.php` a config pin, and to `tests/Feature/TokenRevocationTest.php` the behavior that matters:

```php
// PassportConfigurationTest
public function test_refresh_tokens_do_not_rotate(): void
{
    // Under rotation, a token response lost on the wire leaves the device
    // holding a consumed refresh token: deterministic invalid_grant and a
    // browser re-auth. Non-rotating refresh makes a lost response harmless;
    // each exchange still issues a fresh 90-day refresh token.
    $this->assertFalse(\Laravel\Passport\Passport::$revokeRefreshTokenAfterUse);
}
```

```php
// TokenRevocationTest — full-stack proof, modeled on EndToEndOAuthTest's steps 1-4:
public function test_a_used_refresh_token_can_be_used_again(): void
{
    [$client, $refreshToken] = $this->issueTokensViaAuthCodeFlow(); // reuse/extract the existing flow helper

    $first = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
        'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'mcp:use',
    ]);
    $first->assertStatus(200);
    $this->assertNotEmpty($first->json('refresh_token'), 'a new refresh token is still issued each exchange');

    $second = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
        'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'mcp:use',
    ]);
    $second->assertStatus(200,
        'a lost response must not brick the device: the old refresh token stays valid');
}
```

(If `TokenRevocationTest` has no auth-code flow helper, lift the consent/exchange block from `EndToEndOAuthTest::test_full_flow_from_consent_to_mcp_tool_call` into a private method — it is nine calls.)

- [ ] **Step 2: Run; verify both fail**

```bash
php artisan test --compact --filter='test_refresh_tokens_do_not_rotate|test_a_used_refresh_token_can_be_used_again'
```
Expected: config pin fails on `assertFalse(true)`; reuse test fails on the second exchange returning 400 `invalid_grant`. The 400 (not 500) also re-confirms PR #28's guard on the refresh grant path with a *real* client — the design's §7 caveat.

- [ ] **Step 3: Implement — one line plus the reason**

In `AppServiceProvider::boot()`, next to the TTL calls:

```php
// Non-rotating refresh tokens. Under rotation League revokes the old pair
// before the device has stored the new one, so a response lost on the wire
// leaves the device holding a consumed token: deterministic invalid_grant
// and a browser re-auth. Each exchange still issues a fresh refresh token,
// so devices self-renew; the kill switch is client revocation (TokenRevoker).
Passport::$revokeRefreshTokenAfterUse = false;
```

- [ ] **Step 4: Full suite (both engines — the refresh grant writes `oauth_refresh_tokens`), pint, commit**

```bash
php artisan test --compact && ./vendor/bin/pint
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none php artisan test --compact
git add -A && git commit -m "feat: refresh tokens no longer rotate on use"
```
Expected: green; the existing `TokenRevocationTest` proves revocation still bites (revoked refresh token → `invalid_grant` regardless of rotation).

**What could go wrong / visibility:** this is global — Claude Code's own interactive MCP OAuth also stops rotating. That is RFC-legal and Claude Code handles both modes; if some client misbehaved, it would surface as its own re-auth prompt, not silently. The accumulation of valid-but-superseded refresh tokens is bounded by the 90-day TTL and `passport:purge`.

**Checkpoint:** PR, merge.

---

## Task 3: Device consent screens and device-consent capture

**Independently shippable: yes, after Task 1 — PR "feat: device authorization screens".** Turns the live 500 on `GET /oauth/device` into a page, and wires the device approve into Task 1's client-keyed write.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (two view bindings)
- Create: `resources/views/mcp/device-user-code.blade.php`
- Create: `resources/views/mcp/device-authorize.blade.php`
- Modify: `app/Http/Middleware/CaptureConsentWings.php` (device branch)
- Modify: `resources/views/mcp/authorize.blade.php` (approve-button guard for the empty selection — 6 lines of JS)
- Create: `tests/Feature/Mcp/DeviceFlowTest.php`

**Interfaces:**
- Consumes: `McpClientRestriction`, `CaptureConsentWings::consentPatterns()` (Task 1).
- Produces: the two view names `mcp.device-user-code` / `mcp.device-authorize`; the enrolment endpoints Task 9's script talks to. Route names used: `passport.device`, `passport.device.code`, `passport.device.authorizations.approve`, `passport.device.authorizations.deny` (all exist in `vendor/laravel/passport/routes/web.php`).

- [ ] **Step 1: Failing test pinning today's 500 (design failure mode 15)**

Create `tests/Feature/Mcp/DeviceFlowTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\McpClientRestriction;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class DeviceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        VerifyCsrfToken::except(['oauth/device/authorize', 'oauth/token', 'oauth/device/code']);
    }

    private function deviceClient(): Client
    {
        return app(ClientRepository::class)
            ->createDeviceAuthorizationGrantClient('claude-code@testbox');
    }

    public function test_the_user_code_screen_renders(): void
    {
        // Was a 500: Passport 13 ships no views and Mnemon bound only the
        // auth-code consent view, so resolving DeviceUserCodeViewResponse
        // threw a BindingResolutionException. Confirmed live 2026-09-03.
        $this->get('/oauth/device')
            ->assertStatus(200)
            ->assertSee('code');
    }

    public function test_device_code_response_has_the_documented_shape(): void
    {
        $client = $this->deviceClient();

        $r = $this->postJson('/oauth/device/code', [
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => 'mcp:use',
        ]);

        $r->assertStatus(200);
        $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{8}$/', $r->json('user_code'));
        $this->assertSame(5, $r->json('interval'));
        $this->assertLessThanOrEqual(600, $r->json('expires_in'));
        $this->assertStringContainsString('/oauth/device?user_code=', $r->json('verification_uri_complete'));
        $this->assertNotEmpty($r->json('device_code'));
    }
}
```

- [ ] **Step 2: Run; expected failures**

```bash
php artisan test --compact --filter=DeviceFlowTest
```
Expected: `test_the_user_code_screen_renders` fails with a 500 (`BindingResolutionException` for `DeviceUserCodeViewResponse`) — reproducing the live defect in a test before fixing it. The shape test should already pass (the grant is vendor code); if it fails, that is new information about the grant setup — stop and investigate.

- [ ] **Step 3: Bind the views**

In `AppServiceProvider::boot()`, beside the existing `Passport::authorizationView(...)`:

```php
Passport::deviceUserCodeView('mcp.device-user-code');
Passport::deviceAuthorizationView(fn ($parameters) => view('mcp.device-authorize', array_merge($parameters, [
    'wings' => Wing::orderBy('slug')->get(),
])));
```

- [ ] **Step 4: Build the two views**

Both reuse the exact card chrome of `resources/views/mcp/authorize.blade.php` (head block, fonts, dark-mode script, card container, button classes) — copy it; the differences below are the whole content. Same voice, same wing caveat text.

**`resources/views/mcp/device-user-code.blade.php`** — body of the card:

```blade
<h3 class="text-2xl font-semibold leading-none tracking-tight text-center">Connect a device</h3>

@if (session('status') === 'authorization-approved')
    <div class="rounded-lg border p-4 bg-muted/50 text-center">
        <p class="font-medium text-sm">Device authorized — you can close this tab.</p>
    </div>
@elseif (session('status') === 'authorization-denied')
    <div class="rounded-lg border p-4 bg-muted/50 text-center">
        <p class="font-medium text-sm">Authorization denied. The device was not connected.</p>
    </div>
@else
    <p class="text-sm text-muted-foreground text-center mt-2">
        Enter the code shown on the device you are connecting.
    </p>
    <form method="GET" action="{{ route('passport.device') }}" class="px-6 pb-6 space-y-4">
        <input type="text" name="user_code" value="{{ old('user_code') }}"
               placeholder="BCDF-GHJK" autofocus autocomplete="off"
               class="w-full rounded-md border p-3 text-center font-mono text-lg tracking-widest">
        @if ($errors->has('user_code'))
            <p class="text-sm text-destructive">{{ $errors->first('user_code') }}</p>
        @endif
        <button type="submit" class="...same primary button classes...">Continue</button>
    </form>
@endif
```

(The form is GET on purpose: `DeviceUserCodeController` redirects `?user_code=` to the authorize screen; the status flashes `authorization-approved` / `authorization-denied` are the exact strings Passport's response classes set — verified in `vendor/laravel/passport/src/Http/Responses/ApprovedDeviceAuthorizationResponse.php`.)

**`resources/views/mcp/device-authorize.blade.php`** — a sibling of `mcp/authorize.blade.php`; controller provides `client`, `user`, `scopes`, `request`, `authToken`. Differences from the auth-code view, all deliberate (design §2):

```blade
<h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
    Authorize device: {{ $client->name }}
</h3>
<p class="text-sm text-muted-foreground text-center mt-2">
    Code being approved: <span class="font-mono font-medium">{{ $request->query('user_code') }}</span>
</p>
<p class="text-sm text-muted-foreground text-center mt-2">
    A device just asked for this code. If you didn't run mnemon-authorize on one
    of your machines a moment ago, deny this.
</p>

{{-- Approve form: no client_id field — the server takes the client from the
     device code in the session, the same place its approve controller reads it. --}}
<form method="POST" action="{{ route('passport.device.authorizations.approve') }}" id="authorizeForm">
    @csrf
    <input type="hidden" name="auth_token" value="{{ $authToken }}">
    {{-- ...user info block and the wing block copied verbatim from
         mcp/authorize.blade.php lines 79-138: all-wings master toggle,
         wings[] checkboxes disabled while all-wings is checked, and the
         wiki caveat paragraph... --}}
    {{-- Buttons: Deny + Authorize, same markup, but NO window.close()
         choreography — the user navigated here deliberately, and the
         redirect back to /oauth/device with the status flash is the feedback. --}}
</form>

<form method="POST" action="{{ route('passport.device.authorizations.deny') }}" id="denyForm" class="hidden">
    @csrf
    @method('DELETE')
    <input type="hidden" name="auth_token" value="{{ $authToken }}">
</form>
```

The `<script>` block keeps only `syncWingList()` plus this guard, added to **both** consent views (auth-code one included — Task 1 made the empty shape deny-all, so honest users must not be able to submit it accidentally):

```js
function syncApproveEnabled() {
    var any = Array.prototype.some.call(wingCheckboxes, function (cb) { return cb.checked; });
    button.disabled = !allWingsCheckbox.checked && !any;
}
allWingsCheckbox.addEventListener('change', syncApproveEnabled);
wingCheckboxes.forEach(function (cb) { cb.addEventListener('change', syncApproveEnabled); });
syncApproveEnabled();
```

- [ ] **Step 5: Device branch in `CaptureConsentWings`**

Extend `handle()`'s condition to `($request->is('oauth/authorize') || $request->is('oauth/device/authorize'))` and resolve the client id per-route:

```php
$clientId = $request->is('oauth/device/authorize')
    ? $this->deviceClientId($request)
    : (string) ($request->input('client_id') ?? '');
```

```php
/**
 * The device approve carries no client_id field; the authoritative source
 * is the serialized device code the authorize screen stored in the session
 * — the same value Passport's own approve controller unserializes. Read it
 * non-destructively (the controller pull()s it after us). The deny route is
 * a spoofed DELETE, so the POST match above never fires for it.
 */
private function deviceClientId(Request $request): ?string
{
    $serialized = $request->session()->get('deviceCode');
    if (! is_string($serialized) || $serialized === '') {
        return null;
    }

    $deviceCode = unserialize($serialized, ['allowed_classes' => [
        \Laravel\Passport\Bridge\DeviceCode::class,
        \Laravel\Passport\Bridge\Client::class,
        \Laravel\Passport\Bridge\Scope::class,
        \DateTimeImmutable::class,
    ]]);

    return $deviceCode instanceof \League\OAuth2\Server\Entities\DeviceCodeEntityInterface
        ? (string) $deviceCode->getClient()->getIdentifier()
        : null;
}
```

(When this returns `null` — tampered session — no row is written, and the approve controller itself then fails on the same session value, so approval-without-restriction cannot happen through this gap.)

- [ ] **Step 6: The rest of DeviceFlowTest — the full flow and every poll state**

Add to `DeviceFlowTest` (each is one test; shown compressed):

```php
public function test_wrong_user_code_redirects_back_with_an_error(): void
{
    $this->actingAs(User::factory()->create());
    $this->get('/oauth/device/authorize?user_code=WRONGCOD')
        ->assertRedirect(route('passport.device'))
        ->assertSessionHasErrors('user_code');   // "Incorrect code."
}

public function test_consent_screen_requires_login(): void
{
    $this->deviceClient();
    $this->get('/oauth/device/authorize?user_code=BCDFGHJK')->assertRedirect('/login');
}

public function test_full_device_flow_enforces_the_consented_wings(): void
{
    $work = Wing::factory()->create(['slug' => 'work']);
    $personal = Wing::factory()->create(['slug' => 'personal']);
    Drawer::factory()->create(['room_id' => Room::factory()->create(['wing_id' => $work->id])->id, 'content' => 'work-secret']);
    Drawer::factory()->create(['room_id' => Room::factory()->create(['wing_id' => $personal->id])->id, 'content' => 'personal-secret']);

    $client = $this->deviceClient();
    $device = $this->postJson('/oauth/device/code', [
        'client_id' => $client->id, 'client_secret' => $client->plainSecret, 'scope' => 'mcp:use',
    ])->json();

    // Pending before approval.
    $this->postJson('/oauth/token', $poll = [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device['device_code'],
        'client_id' => $client->id, 'client_secret' => $client->plainSecret,
    ])->assertStatus(400)->assertJsonPath('error', 'authorization_pending');

    // An immediate re-poll is slow_down (interval is 5s).
    $this->postJson('/oauth/token', $poll)->assertStatus(400)->assertJsonPath('error', 'slow_down');

    // Approve with a wing selection; screen names the machine and the code.
    $this->actingAs(User::factory()->create());
    $screen = $this->get('/oauth/device/authorize?user_code='.$device['user_code']);
    $screen->assertStatus(200)->assertSee('claude-code@testbox')->assertSee($device['user_code']);

    $this->post('/oauth/device/authorize', [
        'auth_token' => app('session.store')->get('authToken'),
        'wings' => ['work'],
    ])->assertRedirect(route('passport.device'))
      ->assertSessionHas('status', 'authorization-approved');

    // Consent persisted synchronously, keyed on the client (Task 1's contract).
    $this->assertSame(['work'], McpClientRestriction::find($client->id)->wing_patterns);

    // The next poll (interval elapsed) mints the tokens.
    $this->travel(6)->seconds();
    $tokens = $this->postJson('/oauth/token', $poll);
    $tokens->assertStatus(200);
    $this->assertNotEmpty($tokens->json('refresh_token'));

    // The device code is spent: it cannot be replayed.
    $this->travel(6)->seconds();
    $this->postJson('/oauth/token', $poll)->assertStatus(400);

    // And the token is actually restricted on /mcp.
    $r = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
    ], ['Authorization' => 'Bearer '.$tokens->json('access_token')]);
    $contents = collect($r->json('result.structuredContent.results'))->pluck('content')->all();
    $this->assertContains('work-secret', $contents);
    $this->assertNotContains('personal-secret', $contents);
}

public function test_denied_consent_yields_access_denied_on_the_next_poll(): void
{
    // Same setup through the authorize screen, then the spoofed-DELETE deny:
    // POST /oauth/device/authorize with _method=DELETE and auth_token.
    // Assert redirect flash 'authorization-denied'; assert no
    // McpClientRestriction row was written by the deny; travel 6s; poll →
    // 400 with error access_denied.
}

public function test_expired_device_code_yields_expired_token(): void
{
    // Request a device code, travel 11 minutes, poll → 400, error expired_token.
}
```

Write the deny and expiry tests out fully following the pattern above (the comments state every assertion). Note the deny POST goes to the same URI with `'_method' => 'DELETE'` in the payload — which also proves the middleware's POST-match does not fire on deny (`isMethod('POST')` is false after method spoofing).

- [ ] **Step 7: Run, both engines, pint, commit**

```bash
php artisan test --compact
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none php artisan test --compact
./vendor/bin/pint && git add -A && git commit -m "feat: device authorization screens and device-consent wing capture"
```
The pgsql leg matters here twice over: `oauth_device_codes` timestamps/uuid handling, and the client-restriction FK write from the device approve.

**What could go wrong / visibility:**
- *Views bound but broken* → the feature tests render both screens; live, a broken screen is a 500 in a human's browser (loud).
- *Session unserialize shape drift* (see uncertainties) → consent row skipped; the end-to-end wing assertion in `test_full_device_flow_enforces_the_consented_wings` fails in CI.
- *`travel()` vs `last_polled_at`*: League compares wall-clock `time()` for slow_down; if `travel` doesn't affect it (it does not patch `time()`), the slow_down assertion may need a real `sleep(6)` → if the test flakes, replace `travel(6)->seconds()` with `sleep(6)` and note it, or relax to asserting the second poll is 400 with either pending or slow_down. Do not delete the assertion — it pins RFC 8628 §3.5 behavior.

**Checkpoint:** PR, merge.

---

## Task 4: Discovery metadata

**Independently shippable: yes — small PR "feat: advertise the device grant in discovery metadata".** No dependency on Tasks 1–3 (the grant itself already works server-side).

**Files:**
- Modify: `routes/ai.php`
- Create: `tests/Feature/Mcp/DiscoveryMetadataTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

class DiscoveryMetadataTest extends TestCase
{
    public function test_metadata_advertises_the_device_grant(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200)
            ->assertJsonPath('device_authorization_endpoint', url('/oauth/device/code'))
            ->assertJsonPath('grant_types_supported', [
                'authorization_code', 'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ])
            // The package fields must survive the override — Claude Code's
            // own DCR flow reads these.
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_the_nested_path_variant_matches(): void
    {
        // RFC 8414 path-scoped lookup, e.g. issuer https://host + resource
        // /mcp. laravel/mcp registers this wildcard unconditionally
        // (Registrar.php:105-112), so overriding only the exact route would
        // leave a second, stale copy of the metadata being served.
        $this->getJson('/.well-known/oauth-authorization-server/mcp')
            ->assertStatus(200)
            ->assertJsonPath('device_authorization_endpoint', url('/oauth/device/code'));
    }
}
```

Run: `php artisan test --compact --filter=DiscoveryMetadataTest` — expected: both fail (`device_authorization_endpoint` missing), proving the package route currently answers.

- [ ] **Step 2: Implement in `routes/ai.php`, above `Mcp::oauthRoutes()`**

`Registrar::oauthRoutes()` skips its exact well-known route when one already exists (`Registrar.php:92-103`), and Laravel matches routes in registration order, so the app's wildcard — registered first, same file — wins over the package's wildcard. Guaranteed ordering, no vendor patching:

```php
<?php

use App\Mcp\Servers\MnemonServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// The package hardcodes this document without the device grant
// (vendor/laravel/mcp/src/Server/Registrar.php:119-131). Metadata that
// misdescribes the server is the documentation variant of a silent failure.
// mnemon-authorize.sh deliberately does NOT read this document — a setup
// script that breaks on stale metadata is worse than a derived path on a
// server we control — so this is about honesty, not function.
$authorizationServerMetadata = static fn () => response()->json([
    'issuer' => config('mcp.authorization_server') ?? url('/'),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'device_authorization_endpoint' => route('passport.device.code'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'response_types_supported' => ['code'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ['mcp:use'],
    'grant_types_supported' => [
        'authorization_code',
        'refresh_token',
        'urn:ietf:params:oauth:grant-type:device_code',
    ],
]);

Route::get('/.well-known/oauth-authorization-server', $authorizationServerMetadata);
Route::get('/.well-known/oauth-authorization-server/{path}', $authorizationServerMetadata)
    ->where('path', '.*');

Mcp::oauthRoutes();

Mcp::web('/mcp', MnemonServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
```

- [ ] **Step 3: Run the two tests, then the whole suite (route collisions surface as duplicate-name errors at boot), pint, commit**

```bash
php artisan test --compact && ./vendor/bin/pint
git add -A && git commit -m "feat: advertise the device grant in OAuth discovery metadata"
```

**What could go wrong / visibility:** a route-name collision or ordering regression breaks *every* feature test at boot (loud in CI). Live drift is checked in Task 6 with curl. If a future laravel/mcp version changes its metadata shape, this override silently pins the old shape — the test asserting `registration_endpoint` keeps the two honest.

**Checkpoint:** PR, merge.

---

## Task 5: Client provisioning — `mnemon:device-client`

**Independently shippable: yes — small PR.** `passport:client --device` exists and works, but it is interactive (confirm prompts) and prints only the id/secret. The wrapper is non-interactive, enforces the naming convention, and prints the exact next step — the design's "last SSH ritual" should hand the operator everything they need.

**Files:**
- Create: `app/Console/Commands/CreateDeviceClient.php`
- Create: `tests/Feature/Console/CreateDeviceClientTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class CreateDeviceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_confidential_device_client_and_prints_enrolment_instructions(): void
    {
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])
            ->expectsOutputToContain('claude-code@thinkpad')
            ->expectsOutputToContain('mnemon-authorize.sh')
            ->assertExitCode(0);

        $client = Client::query()->where('name', 'claude-code@thinkpad')->first();
        $this->assertNotNull($client);
        $this->assertTrue($client->confidential());
        $this->assertEqualsCanonicalizing(
            ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
            $client->grant_types,
        );
    }

    public function test_refuses_a_duplicate_active_device_client(): void
    {
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])->assertExitCode(0);
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
    }
}
```

Run: `php artisan test --compact --filter=CreateDeviceClientTest` — expected: `command "mnemon:device-client" is not defined`.

- [ ] **Step 2: Implement**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class CreateDeviceClient extends Command
{
    protected $signature = 'mnemon:device-client {device : Short device name, e.g. thinkpad}';

    protected $description = 'Create the per-device OAuth client for the device authorization grant';

    public function handle(ClientRepository $clients): int
    {
        $name = 'claude-code@'.$this->argument('device');

        if (Client::query()->where('name', $name)->where('revoked', false)->exists()) {
            $this->error("A client named {$name} already exists.");
            $this->line('Revoke it in Filament (OAuth Clients → revoke) first if this device is being re-provisioned.');

            return self::FAILURE;
        }

        // Confidential, with exactly the device_code + refresh_token grants.
        $client = $clients->createDeviceAuthorizationGrantClient($name);

        $this->info("Created device client {$name}");
        $this->newLine();
        $this->line('  client_id:     '.$client->id);
        $this->line('  client_secret: '.$client->plainSecret);
        $this->newLine();
        $this->line('The secret is shown once and stored hashed. On the device, run the');
        $this->line('mnemon plugin\'s enrolment script and paste the secret when prompted:');
        $this->newLine();
        $this->line('  bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | head -1)scripts/mnemon-authorize.sh" \\');
        $this->line('       '.rtrim(config('app.url'), '/').' '.$client->id);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Run tests, pint, commit**

```bash
php artisan test --compact --filter=CreateDeviceClientTest && ./vendor/bin/pint
git add -A && git commit -m "feat: mnemon:device-client provisions the per-device OAuth client"
```

**What could go wrong / visibility:** the secret is printed to the SSH terminal (that is the point — it exists nowhere else in plaintext) and is passed to the device by prompt, not argv (Task 9), so it never lands in `ps` or shell history on either machine. A duplicate name exits 1 with the remedy printed. The plugin cache path in the printed hint is a glob because the marketplace path segment varies; the hint also works if the user just types the path they know.

**Checkpoint:** PR, merge. — *Server work complete; everything so far is invisible to PAT devices.*

---

## Task 6: Deploy Phase 0 and verify live

No code. This is the checkpoint the design calls "Phase 0 — deployable immediately, invisible to PAT devices".

- [ ] **Step 1: Deploy** (after Tasks 1–5 are merged): trigger the usual Forge deploy for `mnemon.example.com`, then:

```bash
ssh forge-atlas 'cd <app-dir> && php artisan migrate --force && php artisan config:clear && php artisan route:clear'
```
Expected: `2026_09_03_000001_create_mcp_client_restrictions_table` runs; nothing else pending.

- [ ] **Step 2: The 500 is a page now**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://mnemon.example.com/oauth/device
```
Expected: `200` (was 500 in Task 0).

- [ ] **Step 3: Metadata is honest**

```bash
curl -s https://mnemon.example.com/.well-known/oauth-authorization-server | jq '.device_authorization_endpoint, .grant_types_supported'
curl -s https://mnemon.example.com/.well-known/oauth-authorization-server/mcp | jq '.device_authorization_endpoint'
```
Expected: the device endpoint URL and the three grants, on both variants.

- [ ] **Step 4: The refresh-grant 500 caveat (§7) — retire it with a real client**

Create a throwaway client (`ssh forge-atlas '... php artisan mnemon:device-client probe'`), then from anywhere:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://mnemon.example.com/oauth/token \
  -d 'grant_type=refresh_token&refresh_token=bogus&client_id=<uuid>&client_secret=<secret>'
```
Expected: `400` or `401` with an OAuth JSON body — **any 500 here is a new stop-the-line defect** (the design's honest caveat: the refresh-path 500 was attributed to the uuid cause, never reproduced with a valid client). Revoke the probe client in Filament afterwards.

- [ ] **Step 5: Existing devices unharmed** — start a Claude Code session on a PAT device; wake output unchanged; `BrainSession` rows still arriving in Filament.

**What could go wrong / visibility:** a migration failure aborts the deploy (Forge surfaces it); Steps 2–4 are direct observations. If Step 5 regresses, the PAT path was touched somewhere it shouldn't have been — `git diff main@{task0}` the hooks directory: it must be empty at this point.

---

## Task 7: Hook-side refresh in `common.sh`

**Shippable: only as part of the 0.3.0 plugin release (Tasks 7–9 + bump in Task 10, one branch, one PR).** Everything here is inert on devices until the version bumps.

**Files:**
- Modify: `plugins/mnemon/hooks/lib/common.sh`
- Modify: `tests/Hooks/fixtures/server.sh`
- Modify: `tests/Hooks/run-hook-tests.sh`

**Interfaces (Tasks 8–9 rely on these exact contracts):**
- `mnemon_config_write` — reads a complete config JSON from **stdin**; validates with `jq -e`; writes `$MNEMON_CONFIG` atomically (temp + `chmod 600` + `mv -f`); returns non-zero and logs without touching the old file on any failure.
- `mnemon_refresh_available` — returns 0 iff credentials come from the config file (not env), the config has non-empty `refresh_token`, `client_id`, `client_secret`, `token_endpoint`, and `auth_state != "dead"`.
- `mnemon_refresh <token_that_401ed>` — echoes a usable access token on success (its own, or a newer one another process wrote); marks `auth_state:"dead"` + `auth_dead_reason` + `auth_dead_at` on `invalid_grant|invalid_client|unauthorized_client`; returns 1 on any failure.
- `mnemon_call` — unchanged signature; on HTTP 401 with refresh available, refreshes and retries **exactly once**.
- Config schema (design §5): existing keys plus `token_endpoint`, `client_id`, `client_secret`, `refresh_token`, `refreshed_at`, `auth_state`; `bearer_token` keeps its name; presence of `refresh_token`+`client_id` is the feature switch.

- [ ] **Step 1: Teach the fake server OAuth** — extend the Python block in `tests/Hooks/fixtures/server.sh`. New env: `FAKE_STATE_DIR` (defaults to a tmpdir), `FAKE_EXPIRE_FIRST=1`, `FAKE_REFRESH_FAIL=` `invalid_grant` | `invalid_client` | `garbage` | `500`. Route on path at the top of `do_POST`:

```python
STATE = os.environ.get("FAKE_STATE_DIR", "")

def state_path(name):
    return os.path.join(STATE, name)

# inside do_POST, before the existing FAKE_STATUS handling:
if self.path.startswith("/oauth/token"):
    if LOG:
        with open(LOG, "ab") as fh:
            fh.write(b"OAUTH_TOKEN " + body + b"\n")
    fail = os.environ.get("FAKE_REFRESH_FAIL", "")
    if fail in ("invalid_grant", "invalid_client"):
        page = ('{"error":"%s","error_description":"nope"}' % fail).encode()
        self._reply(400 if fail == "invalid_grant" else 401, page, "application/json")
        return
    if fail == "garbage":
        self._reply(200, b"<html>proxy says hi</html>", "text/html")
        return
    if fail == "500":
        self._reply(500, b"<html>boom</html>", "text/html")
        return
    open(state_path("refreshed"), "w").close()
    page = b'{"token_type":"Bearer","expires_in":3600,"access_token":"token-2","refresh_token":"refresh-2"}'
    self._reply(200, page, "application/json")
    return

# and for /mcp when FAKE_EXPIRE_FIRST is set:
if os.environ.get("FAKE_EXPIRE_FIRST"):
    auth = self.headers.get("Authorization", "")
    if auth != "Bearer token-2":
        self._reply(401, b'{"message":"Unauthenticated."}', "application/json")
        return
```

Add the small `_reply(self, status, page, ctype)` helper (send_response/headers/write) and use it for the existing branches too if trivial — do not restructure beyond that. `FAKE_STATE_DIR` lets ThreadingHTTPServer requests share "a refresh happened" without globals.

- [ ] **Step 2: Write the failing harness tests** (append to `run-hook-tests.sh`, following its style; each block prints ok/FAIL and bumps PASS/FAIL). A shared preamble builds a refresh-capable config:

```bash
# --- Refresh tests -----------------------------------------------------------
RF_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
RF_STATE=$(mktemp -d)
RF_LOG="$MNEMON_DIR/refresh-requests.log"
: > "$RF_LOG"
FAKE_PORT="$RF_PORT" FAKE_EXPIRE_FIRST=1 FAKE_STATE_DIR="$RF_STATE" FAKE_REQUEST_LOG="$RF_LOG" \
    "$THIS_DIR/fixtures/server.sh" &
RF_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.rf.json"
jq --arg e "http://127.0.0.1:$RF_PORT/mcp" --arg t "http://127.0.0.1:$RF_PORT/oauth/token" \
   '.endpoint=$e | .token_endpoint=$t | .bearer_token="token-1" | .refresh_token="refresh-1"
    | .client_id="cid" | .client_secret="csec" | .recall_timeout_ms=5000' \
   "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
chmod 600 "$MNEMON_DIR/config.json"

# R1: 401 → refresh → retry-once → success; config rewritten atomically.
out=$(printf '{"session_id":"r1","prompt":"a substantive prompt for the refresh happy path"}' \
    | "$HOOKS_DIR/mnemon-recall.sh")
case "$out" in
    *"Mnemon recall:"*) PASS=$((PASS+1)); printf '  ok: refresh: 401 is refreshed and retried once\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: refresh: expected recall output after refresh, got: %s\n' "$out";;
esac
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "refresh: new access token stored"
assert_eq "$(jq -r '.refresh_token' "$MNEMON_DIR/config.json")" "refresh-2" "refresh: new refresh token stored"
assert_eq "$(jq -r '.recall_timeout_ms' "$MNEMON_DIR/config.json")" "5000" "refresh: tunables survive the rewrite"
assert_eq "$(stat -c '%a' "$MNEMON_DIR/config.json")" "600" "refresh: config stays 0600"
assert_eq "$(grep -c 'refreshed access token' "$MNEMON_DIR/capture-errors.log")" "1" "refresh: success is logged"
```

```bash
# R2: stampede — three concurrent 401s produce exactly one refresh.
rm -f "$RF_STATE/refreshed"; : > "$RF_LOG"
jq '.bearer_token="token-1" | .refresh_token="refresh-1"' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
    && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json" && chmod 600 "$MNEMON_DIR/config.json"
for s in rs1 rs2 rs3; do
    printf '{"session_id":"%s","prompt":"a substantive prompt for the stampede test"}' "$s" \
        | "$HOOKS_DIR/mnemon-recall.sh" >/dev/null 2>&1 &
done
wait
assert_eq "$(grep -c '^OAUTH_TOKEN' "$RF_LOG")" "1" "refresh: three concurrent 401s cause exactly one refresh"
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "refresh: stampede converges on the new token"
```

```bash
# R3: invalid_grant marks the device dead and stops further attempts.
# (own server: FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=invalid_grant, fresh port)
#   - run one recall; then assert:
assert_eq "$(jq -r '.auth_state' "$MNEMON_DIR/config.json")" "dead" "refresh: invalid_grant sets auth_state=dead"
assert_eq "$(jq -r '.auth_dead_reason' "$MNEMON_DIR/config.json")" "invalid_grant" "refresh: the reason is recorded"
#   - run a second recall; assert the oauth log gained no second OAUTH_TOKEN line:
assert_eq "$(grep -c '^OAUTH_TOKEN' "$DEAD_LOG")" "1" "refresh: a dead device does not keep retrying"

# R4: invalid_client is also dead — it is what a revoked client (the kill
# switch, TokenRevoker::client) actually returns; the design named only
# invalid_grant, which would have retried the kill switch forever.
# (same as R3 with FAKE_REFRESH_FAIL=invalid_client; assert auth_dead_reason=invalid_client)

# R5: a 500 / non-JSON body is transient, never dead.
# (FAKE_REFRESH_FAIL=garbage, then =500; after each: auth_state is "ok" or absent,
#  refresh_token still "refresh-1", capture-errors.log contains 'refresh failed'.)

# R6: a PAT-shaped config never attempts a refresh.
# (config without refresh fields against FAKE_STATUS=401 server; assert the
#  oauth log is empty and the existing 'token rejected or expired' line appears.)

# R7: MNEMON_TOKEN in the environment disables refresh even alongside a
# refresh-capable config — the livelock guard (design revision 6).
# (MNEMON_ENDPOINT/MNEMON_TOKEN env pointing at the FAKE_EXPIRE_FIRST server,
#  config carries refresh fields; assert zero OAUTH_TOKEN lines.)

# R8: a refresh response missing a token is refused; old config intact.
# (Point token_endpoint at a mode returning {"access_token":""} — add
#  FAKE_REFRESH_FAIL=empty to the fixture returning that — assert bearer_token
#  is still token-1 and an error line was logged.)

# R9: mnemon_config_write refuses garbage and leaves the old file.
bad=$(printf 'not json' | bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_config_write; echo rc=\$?")
case "$bad" in *rc=1*) PASS=$((PASS+1)); printf '  ok: config write refuses invalid JSON\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: config write accepted garbage\n';; esac
assert_eq "$(jq -e . "$MNEMON_DIR/config.json" >/dev/null && echo valid)" "valid" "config: old file untouched after refused write"
```

Write R3–R8 out fully in the same shape as R1/R2 (fresh port + fresh server per mode, restore `config.rf.json` after the block, kill the server PIDs — copy the lifecycle discipline the file already uses everywhere).

- [ ] **Step 3: Run the harness; every new test must FAIL** (no refresh functions exist yet): `bash tests/Hooks/run-hook-tests.sh`. Expected: 30 old pass, all R-tests fail. If any R-test passes now, it is testing nothing — fix it before proceeding.

- [ ] **Step 4: Implement in `common.sh`.** Three new functions and the 401 branch. Full code:

```bash
# Atomically replace config.json with JSON read from stdin. Validates before
# renaming and chmods the temp before the rename (the umask is not trusted):
# the file on disk is always either the old working state or the new working
# state. A zero-byte state file once wedged a session for hours; this is the
# codified cure, same shape as mnemon_session_state_write.
mnemon_config_write() {
    local tmp="$MNEMON_CONFIG.tmp.$$"
    cat > "$tmp" 2>/dev/null || { rm -f "$tmp"; return 1; }
    if ! jq -e . "$tmp" >/dev/null 2>&1; then
        mnemon_log_error "refusing to replace config.json with invalid JSON"
        rm -f "$tmp"
        return 1
    fi
    chmod 600 "$tmp" 2>/dev/null
    mv -f "$tmp" "$MNEMON_CONFIG"
}

# A refresh is possible only when credentials come from the config file. Env
# credentials are static: refreshing would rewrite a file nothing reads, so
# every call 401s, "succeeds" at refreshing, and 401s again -- permanent
# double round trips, converging never (design revision 6).
mnemon_refresh_available() {
    [ -n "${MNEMON_TOKEN:-}${CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN:-}" ] && return 1
    [ -f "$MNEMON_CONFIG" ] || return 1
    jq -e '(.refresh_token // "") != "" and (.client_id // "") != ""
           and (.client_secret // "") != "" and (.token_endpoint // "") != ""
           and ((.auth_state // "ok") != "dead")' "$MNEMON_CONFIG" >/dev/null 2>&1
}

# Exchange the refresh token for a new access/refresh pair. Args: the token
# that just got the 401. Echoes a usable access token on success. Marks the
# device dead only on a definitive OAuth rejection: invalid_grant (token
# expired/revoked) or invalid_client/unauthorized_client (the client was
# revoked -- the Filament kill switch looks exactly like this). A network
# error, 5xx, or proxy HTML is NOT dead: the refresh token is kept and the
# next 401 tries again. Subshell body so the EXIT trap frees the lock.
mnemon_refresh() (
    old_token="$1"
    lock="$MNEMON_DIR/refresh.lock.d"

    # mkdir is atomic on every platform the hooks support (jq+curl+coreutils;
    # no flock on macOS). Deliberately NOT the digest worker's check-then-write
    # PID file, which has a TOCTOU race. Liveness-checked stale recovery;
    # waiters give up after ~5s and fail only this one call.
    waited=0
    while ! mkdir "$lock" 2>/dev/null; do
        pid=$(cat "$lock/pid" 2>/dev/null)
        if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then
            rm -rf "$lock"
            continue
        fi
        if [ "$waited" -ge 25 ]; then
            mnemon_log_error "refresh lock busy >5s; skipping refresh for this call"
            exit 1
        fi
        sleep 0.2
        waited=$((waited + 1))
    done
    echo "$$" > "$lock/pid"
    trap 'rm -rf "$lock"' EXIT

    # Another hook may have refreshed while we waited: if the stored token
    # already changed, use it and do nothing. This is the whole anti-stampede
    # mechanism.
    current=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
    if [ -n "$current" ] && [ "$current" != "$old_token" ]; then
        printf '%s' "$current"
        exit 0
    fi

    token_endpoint=$(jq -r '.token_endpoint // empty' "$MNEMON_CONFIG")
    body=$(mktemp "${TMPDIR:-/tmp}/mnemon-refresh.XXXXXX") || exit 1
    # Secrets travel via --data @file, never argv: argv is visible in ps.
    jq -r '"grant_type=refresh_token" +
           "&refresh_token=\(.refresh_token|@uri)" +
           "&client_id=\(.client_id|@uri)" +
           "&client_secret=\(.client_secret|@uri)" +
           "&scope=mcp%3Ause"' "$MNEMON_CONFIG" > "$body"

    raw=$(curl -s -m 5 -w '\n%{http_code}' -X POST "$token_endpoint" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -H "Accept: application/json" \
        --data @"$body" 2>/dev/null)
    rc=$?
    rm -f "$body"
    if [ $rc -ne 0 ]; then
        mnemon_log_error "refresh failed: curl exit $rc (network); keeping credentials, will retry on next 401"
        exit 1
    fi

    status="${raw##*$'\n'}"
    response="${raw%$'\n'*}"

    if ! printf '%s' "$response" | jq -e . >/dev/null 2>&1; then
        mnemon_log_error "refresh failed: non-JSON response (HTTP $status): $(printf '%s' "$response" | head -c 80 | tr -d '\r\n'); keeping credentials"
        exit 1
    fi

    oauth_error=$(printf '%s' "$response" | jq -r '.error // empty')
    case "$oauth_error" in
        invalid_grant|invalid_client|unauthorized_client)
            jq --arg e "$oauth_error" \
               '. + {auth_state: "dead", auth_dead_reason: $e, auth_dead_at: (now | floor)}' \
               "$MNEMON_CONFIG" | mnemon_config_write
            mnemon_log_error "refresh rejected ($oauth_error): device authorization is dead; wake will show the re-auth banner"
            exit 1
            ;;
    esac

    access=$(printf '%s' "$response" | jq -r '.access_token // empty')
    refresh=$(printf '%s' "$response" | jq -r '.refresh_token // empty')
    if [ "$status" != "200" ] || [ -z "$access" ] || [ -z "$refresh" ]; then
        mnemon_log_error "refresh failed: HTTP $status with token(s) missing; keeping old credentials"
        exit 1
    fi

    jq --arg a "$access" --arg r "$refresh" \
       '. + {bearer_token: $a, refresh_token: $r, refreshed_at: (now | floor), auth_state: "ok"}
        | del(.auth_dead_reason, .auth_dead_at)' \
       "$MNEMON_CONFIG" | mnemon_config_write || exit 1

    mnemon_log_error "refreshed access token; next expiry in ~60 minutes"
    printf '%s' "$access"
)
```

And in `mnemon_call`, replace the opening of the `>= 400` block (currently `common.sh:105`):

```bash
    if [ "${status:-0}" -ge 400 ] 2>/dev/null; then
        # 401 only -- a 403 is a scope or wing denial and refreshing won't fix
        # it. One retry, never a loop: the guard survives into the recursive
        # call's environment.
        if [ "$status" = "401" ] && [ "${MNEMON_RETRIED:-0}" != "1" ] && mnemon_refresh_available; then
            local fresh
            if fresh=$(mnemon_refresh "$token"); then
                MNEMON_RETRIED=1 mnemon_call "$method" "$params" "$timeout_ms" "$endpoint" "$fresh"
                return $?
            fi
        fi
        case "$status" in
        ... (existing cases unchanged) ...
```

- [ ] **Step 5: Run the harness — everything green**

```bash
bash tests/Hooks/run-hook-tests.sh
```
Expected: `30+N passed, 0 failed`. The 30 pre-existing tests **must** still pass — R6 (PAT config) and the untouched 401-logging test are the explicit guards for that.

- [ ] **Step 6: Sabotage checks (constraint: tests here have passed for the wrong reason before)**

Run each, confirm the named test FAILS, revert:
```bash
# 1. Disable the lock: change `while ! mkdir "$lock"` to `while false` → R2 must fail (3 refreshes).
# 2. Break dead-marking: remove invalid_client from the case → R4 must fail.
# 3. Break the retry: set MNEMON_RETRIED=1 unconditionally → R1 must fail.
# 4. Break atomicity: make mnemon_config_write skip jq validation → R9 must fail.
git checkout -- plugins/mnemon/hooks/lib/common.sh   # after re-applying the real implementation
```
(Practically: commit the real implementation first, sabotage in the working tree, observe, `git checkout` — four cycles, two minutes each.)

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(hooks): mnemon_call refreshes on 401, behind a lock, retrying once"
```

**What could go wrong / where failure is visible:**
- *Refresh works* → one `refreshed access token` line per hour per device (in the log, plus observable server-side as new `oauth_access_tokens` rows — Task 11 checks this live).
- *Refresh transiently fails* → the original 401 log line still fires (existing path) and the call fails exactly as today; next 401 retries. Session-visible only as missing recall — same as today, by design.
- *Refresh definitively fails* → `auth_state:"dead"`, and **Task 8's wake banner is the loud surface**; until Task 8 lands the dead state is log-only, which is why 7 and 8 ship in the same release.
- *Lock leak* (killed mid-refresh) → stale-PID recovery clears it on the next attempt; worst case is a 5 s wait and a "lock busy" line.
- *A zero-byte config* can no longer happen: garbage never survives `jq -e` validation before the rename.

---

## Task 8: Wake — the dead banner, proactive refresh, and honest messages

**Same branch as Task 7.**

**Files:**
- Modify: `plugins/mnemon/hooks/lib/common.sh` (extract `mnemon_token_exp`, add `mnemon_token_seconds_left`)
- Modify: `plugins/mnemon/hooks/mnemon-wake.sh`
- Modify: `tests/Hooks/run-hook-tests.sh`

**Interfaces:**
- Consumes: `mnemon_refresh`, `mnemon_refresh_available`, `mnemon_config_value` (existing), Task 7's config schema.
- Produces: `mnemon_token_seconds_left <token>` — echoes integer seconds to `exp`, return 1 if unreadable. `mnemon_token_days_left` keeps its exact contract (existing tests pin it).

- [ ] **Step 1: Failing harness tests** (append; each self-contained per the file's conventions):

```bash
# W1: a dead config banners at session start with the re-auth command, and
# makes no server call at all.
# config: auth_state=dead, auth_dead_reason=invalid_grant + refresh fields.
# run wake; assert stdout matches *"authorization is dead"* and *"mnemon-authorize"*,
# and the request log stayed empty.

# W2: a refresh-capable config never shows the PAT countdown. A device-flow
# access token lives one hour, so days_left is always 0 and every session
# would open with a false "token has expired" banner.
# config: bearer_token=$(make_jwt 1800) (30 min left) + refresh fields; run wake;
# assert stdout does NOT match *expire*.

# W3: a PAT config keeps the countdown exactly as today.
# (This is the existing 0d test's config shape — the existing test already
# covers it; add only an explicit no-refresh-fields assertion comment.)

# W4: wake refreshes proactively when the JWT is within 10 minutes of expiry.
# config: bearer_token=$(make_jwt 300) + refresh fields pointing at the
# FAKE_EXPIRE_FIRST server (which 401s token-1 and accepts token-2 — but the
# proactive path must refresh BEFORE calling, so assert the oauth log has one
# OAUTH_TOKEN line AND the wake call went out with token-2, via FAKE_HEADER_LOG).

# W5: the unconfigured message points at the device flow now.
# rm config; run wake; assert stdout matches *"mnemon-authorize"* and does NOT
# match *"personal access token"* as the primary instruction.
```

Write these out fully in the harness style (ports, servers, config swap/restore). Run: all four fail (wake has none of this yet).

- [ ] **Step 2: Implement.** In `common.sh`, refactor the JWT decode so days and seconds share it:

```bash
# Epoch expiry of a Passport JWT. Args: <token>. Return 1 if unreadable.
mnemon_token_exp() {
    local token="$1" payload pad exp
    case "$token" in *.*.*) ;; *) return 1 ;; esac
    payload=$(printf '%s' "$token" | cut -d. -f2 | tr '_-' '/+')
    pad=$(( (4 - ${#payload} % 4) % 4 ))
    while [ "$pad" -gt 0 ]; do payload="${payload}="; pad=$((pad - 1)); done
    exp=$(printf '%s' "$payload" | base64 -d 2>/dev/null | jq -r '.exp // empty' 2>/dev/null)
    case "$exp" in ''|*[!0-9]*) return 1 ;; esac
    printf '%s' "$exp"
}

mnemon_token_seconds_left() {
    local exp
    exp=$(mnemon_token_exp "$1") || return 1
    printf '%s' "$(( exp - $(date +%s) ))"
}

mnemon_token_days_left() {
    local exp
    exp=$(mnemon_token_exp "$1") || return 1
    printf '%s' "$(( (exp - $(date +%s)) / 86400 ))"
}
```

In `mnemon-wake.sh`: replace the unconfigured message (lines 16–21) and the warning block (lines 30–42):

```bash
pair=$(mnemon_token) || {
    printf 'Mnemon: not connected. Ask the Mnemon owner for a device client (php artisan mnemon:device-client <name>), then run:\n  bash %s/scripts/mnemon-authorize.sh https://<your-instance>\nOr, for agents that cannot refresh: put a personal access token in ~/.mnemon/config.json.\n' \
        "$(dirname "$SCRIPT_DIR")"
    exit 0
}

# A dead credential is the one state that must be impossible to miss: banner
# at every session start until re-authorized, with the exact command.
if [ "$(mnemon_config_value 'auth_state' 'ok')" = "dead" ]; then
    printf 'Mnemon: this device'\''s authorization is dead (%s) — memory is off.\nRe-run: bash %s/scripts/mnemon-authorize.sh https://<your-instance>\n' \
        "$(mnemon_config_value 'auth_dead_reason' 'unknown')" "$(dirname "$SCRIPT_DIR")"
    exit 0
fi

mnemon_session_state "$session_id" > /dev/null

if mnemon_refresh_available; then
    # Session start is where a ~1s refresh is invisible; refresh proactively
    # when the hour-long token is inside its last 10 minutes so the tight
    # per-prompt recall budget almost never pays for one.
    if secs=$(mnemon_token_seconds_left "${pair#*|}") && [ "$secs" -lt 600 ]; then
        if fresh=$(mnemon_refresh "${pair#*|}"); then
            pair="${pair%|*}|$fresh"
        fi
    fi
else
    # PAT configs keep the countdown. Refresh-capable configs must not: their
    # access token always has <1 day left, which would banner "expired" at
    # every session start forever.
    warn_days=$(mnemon_config_value 'token_warn_days' 14)
    if days_left=$(mnemon_token_days_left "${pair#*|}"); then
        if [ "$days_left" -le "$warn_days" ]; then
            if [ "$days_left" -le 0 ]; then
                printf 'Mnemon: the access token has expired — memory is off until it is replaced.\n'
            else
                printf 'Mnemon: the access token expires in %s day(s). Mint a replacement before then or memory stops silently.\n' "$days_left"
            fi
        fi
    fi
fi
```

(Endpoint in the banner: derive the instance URL from the config — `$(mnemon_config_value 'endpoint' '' )` with `/mcp` stripped — rather than the literal `<your-instance>`, when the config exists: `printf` it into the re-run line. Do this; a banner with a placeholder in it is half-loud.)

- [ ] **Step 3: Run the harness; W1–W5 green, all pre-existing wake tests (expiry warning, no-rewind, header) still green.** The existing expiry-warning test uses a config *without* refresh fields, so it exercises the PAT branch — confirm it passes unmodified; if it needed modification, the PAT contract broke: stop.

- [ ] **Step 4: Sabotage check** — make the dead-banner branch check `"deadX"` → W1 must fail; revert. Make the countdown-skip condition inverted → W2 must fail; revert.

- [ ] **Step 5: Commit** — `git commit -m "feat(hooks): dead-credential banner, proactive refresh at wake, honest expiry messaging"`.

**What could go wrong / visibility:** every state this task manages is *defined* by where it is visible: dead → banner every session; near-expiry with refresh → silent proactive refresh (one log line); PAT → countdown as before; unconfigured → instructions. The residual risk is copy drift between the banner's command and the real script path — W1 asserts on `mnemon-authorize` so a rename breaks the test.

---

## Task 9: `mnemon-authorize.sh` — enrolment with rollback

**Same branch as Tasks 7–8.**

**Files:**
- Create: `plugins/mnemon/scripts/mnemon-authorize.sh` (mode 755)
- Modify: `tests/Hooks/fixtures/server.sh` (device-flow modes)
- Modify: `tests/Hooks/run-hook-tests.sh`

**Interfaces:**
- Consumes: `mnemon_config_write`, `mnemon_call`, `mnemon_log_error` from `common.sh` (sourced via `../hooks/lib/common.sh`); server endpoints from Tasks 3/5; `MNEMON_DIR` env override (the harness sandboxes with it).
- Produces: `~/.mnemon/config.json` in Task 7's schema; `~/.mnemon/config.backup-<UTC-timestamp>.json` (0600).

- [ ] **Step 1: Extend the fixture** with `FAKE_DEVICE_MODE` = `success` | `denied` | `expired`:

```python
if self.path.startswith("/oauth/device/code"):
    page = ('{"device_code":"dev-1","user_code":"BCDFGHJK",'
            '"verification_uri":"http://127.0.0.1:%d/oauth/device",'
            '"verification_uri_complete":"http://127.0.0.1:%d/oauth/device?user_code=BCDFGHJK",'
            '"expires_in":600,"interval":1}' % (PORT, PORT)).encode()
    self._reply(200, page, "application/json")
    return

# in the /oauth/token branch, when the body contains the device grant:
if b"device_code" in body:
    mode = os.environ.get("FAKE_DEVICE_MODE", "success")
    polls = state_path("polls")
    n = int(open(polls).read()) if os.path.exists(polls) else 0
    open(polls, "w").write(str(n + 1))
    if mode == "denied":
        self._reply(400, b'{"error":"access_denied"}', "application/json"); return
    if mode == "expired":
        self._reply(400, b'{"error":"expired_token"}', "application/json"); return
    if n == 0:   # success mode: pending once, then tokens
        self._reply(400, b'{"error":"authorization_pending"}', "application/json"); return
    self._reply(200, b'{"token_type":"Bearer","expires_in":3600,'
                     b'"access_token":"token-2","refresh_token":"refresh-2"}', "application/json")
    return
```

- [ ] **Step 2: Failing harness tests**

```bash
# A1 success: MNEMON_CLIENT_SECRET=csec scripts/mnemon-authorize.sh <base-url> cid
#   with a pre-existing PAT config containing recall_timeout_ms=3000 and a
#   bearer_token matching a planted ~/.claude.json-style file (point the
#   script's audit at "$MNEMON_DIR/claude.json" via MNEMON_CLAUDE_JSON for
#   testability). Assert: exit 0; stdout contains the user code "BCDF-GHJK",
#   "Waiting for approval", "Verified", and the backup restore line
#   ("cp .../config.backup-"); config.json has bearer_token=token-2,
#   refresh_token=refresh-2, client_id=cid, token_endpoint set, auth_state=ok,
#   recall_timeout_ms=3000 preserved, mode 0600; a config.backup-*.json exists
#   with the OLD bearer token, mode 0600; stdout warns that ~/.claude.json
#   carries the same token ("also used by your MCP server entry").
# A2 denied: FAKE_DEVICE_MODE=denied → exit 1, stdout contains "denied on the
#   consent screen"; config.json untouched (byte-identical to before).
# A3 expired: FAKE_DEVICE_MODE=expired → exit 1, stdout contains "expired" and
#   "re-run"; config untouched.
# A4 fresh install: no prior config → success writes a complete config, no
#   backup file, no restore line, and the wing report line appears
#   ("wings this device can reach").
```

Run: all fail (`No such file or directory` for the script). 

- [ ] **Step 3: Implement the script.** Complete:

```bash
#!/usr/bin/env bash
# Mnemon device enrolment: RFC 8628 device authorization grant.
# Usage: mnemon-authorize.sh <instance-base-url> [client-id]
# The client secret is read from $MNEMON_CLIENT_SECRET or prompted (never argv).
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/../hooks/lib/common.sh"

CLAUDE_JSON="${MNEMON_CLAUDE_JSON:-$HOME/.claude.json}"

base="${1:-}"
[ -z "$base" ] && { echo "Usage: mnemon-authorize.sh <instance-base-url> [client-id]" >&2; exit 2; }
base="${base%/}"
client_id="${2:-}"
if [ -z "$client_id" ]; then printf 'Client id: '; read -r client_id; fi
client_secret="${MNEMON_CLIENT_SECRET:-}"
if [ -z "$client_secret" ]; then printf 'Client secret: '; read -rs client_secret; echo; fi

mkdir -p "$MNEMON_DIR" && chmod 700 "$MNEMON_DIR"

# Endpoints are derived, not discovered: a setup script that breaks when
# metadata is stale is a worse failure mode than a fixed path on a server we
# control (design §8).
device_endpoint="$base/oauth/device/code"
token_endpoint="$base/oauth/token"
mcp_endpoint="$base/mcp"

req=$(mktemp "${TMPDIR:-/tmp}/mnemon-auth.XXXXXX")
trap 'rm -f "$req"' EXIT
enc() { jq -rn --arg v "$1" '$v|@uri'; }
printf 'client_id=%s&client_secret=%s&scope=mcp%%3Ause' "$(enc "$client_id")" "$(enc "$client_secret")" > "$req"

resp=$(curl -s -m 15 -X POST "$device_endpoint" -H 'Accept: application/json' --data @"$req")
if ! printf '%s' "$resp" | jq -e .device_code >/dev/null 2>&1; then
    echo "Could not start authorization. The server said:"
    printf '%s\n' "$resp" | head -c 300; echo
    echo "Check the client id/secret (server: php artisan mnemon:device-client <name>)."
    exit 1
fi
device_code=$(printf '%s' "$resp" | jq -r .device_code)
user_code=$(printf '%s' "$resp" | jq -r .user_code)
uri_complete=$(printf '%s' "$resp" | jq -r .verification_uri_complete)
uri=$(printf '%s' "$resp" | jq -r .verification_uri)
interval=$(printf '%s' "$resp" | jq -r '.interval // 5')
expires_in=$(printf '%s' "$resp" | jq -r '.expires_in // 600')
pretty_code="$(printf '%s' "$user_code" | sed 's/^\(....\)/\1-/')"

printf '\nOpen:  %s\n' "$uri_complete"
printf 'or go to %s and enter code:  %s\n' "$uri" "$pretty_code"
printf 'Waiting for approval (expires in %s minutes)...\n' "$(( expires_in / 60 ))"

printf 'grant_type=urn%%3Aietf%%3Aparams%%3Aoauth%%3Agrant-type%%3Adevice_code&device_code=%s&client_id=%s&client_secret=%s' \
    "$(enc "$device_code")" "$(enc "$client_id")" "$(enc "$client_secret")" > "$req"

while :; do
    sleep "$interval"
    out=$(curl -s -m 15 -X POST "$token_endpoint" -H 'Accept: application/json' --data @"$req")
    err=$(printf '%s' "$out" | jq -r '.error // empty' 2>/dev/null)
    case "$err" in
        authorization_pending) continue ;;
        slow_down) interval=$((interval + 5)); continue ;;   # RFC 8628 §3.5 — the one quiet path
        access_denied)
            echo "Authorization was denied on the consent screen. Nothing was changed."; exit 1 ;;
        expired_token)
            echo "The code expired before it was approved. Re-run this script for a fresh code."; exit 1 ;;
        "") ;;
        *)  echo "The server refused the exchange ($err). Nothing was changed."; exit 1 ;;
    esac
    access=$(printf '%s' "$out" | jq -r '.access_token // empty' 2>/dev/null)
    refresh=$(printf '%s' "$out" | jq -r '.refresh_token // empty' 2>/dev/null)
    if [ -n "$access" ] && [ -n "$refresh" ]; then break; fi
    echo "Unexpected response while polling; retrying..."
done

# Rollback first, flip second: the PAT's plaintext exists nowhere else, so a
# first-day failure without this backup would mean SSH + tinker on the server
# -- the exact ritual this feature removes (design revision 5).
old_token=""
if [ -f "$MNEMON_CONFIG" ]; then
    old_token=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
    backup="$MNEMON_DIR/config.backup-$(date -u +%Y%m%dT%H%M%SZ).json"
    cp "$MNEMON_CONFIG" "$backup" && chmod 600 "$backup"
    printf 'Backed up the old config. To roll back:\n  cp %s %s\n' "$backup" "$MNEMON_CONFIG"
fi

jq -n --slurpfile old <(cat "$MNEMON_CONFIG" 2>/dev/null || echo '{}') \
   --arg e "$mcp_endpoint" --arg te "$token_endpoint" \
   --arg ci "$client_id" --arg cs "$client_secret" \
   --arg a "$access" --arg r "$refresh" '
   ($old[0] // {}) + {
     endpoint: $e, token_endpoint: $te, client_id: $ci, client_secret: $cs,
     bearer_token: $a, refresh_token: $r,
     refreshed_at: (now|floor), auth_state: "ok"
   } | del(.auth_dead_reason, .auth_dead_at)' | mnemon_config_write \
   || { echo "FAILED to write $MNEMON_CONFIG - the old config (if any) is untouched."; exit 1; }

# Verify with a real tools/call: tools/list would pass on a no-scope or
# deny-all token and report success on a bricked device (design revision 4).
echo "Verifying against $mcp_endpoint ..."
result=$(mnemon_call "tools/call" '{"name":"palace_wake_up","arguments":{}}' 10000 "$mcp_endpoint" "$access")
if [ -z "$result" ]; then
    echo "VERIFICATION FAILED: the token was issued but a real tool call did not succeed."
    echo "Details: $(tail -1 "$MNEMON_ERROR_LOG" 2>/dev/null)"
    echo "The config was written anyway; fix the problem and re-run, or roll back (see above)."
    exit 1
fi
wings=$(printf '%s' "$result" | jq -r '[.structuredContent.active_wings[]?.slug] | join(", ")' 2>/dev/null)
echo "Verified. Wings this device can reach (with content): ${wings:-none yet}"
echo "(A wing with no drawers yet will not appear here even if permitted.)"

# The interactive MCP entry may carry the same PAT; revoking it would break
# that too (design revision 5).
if [ -n "$old_token" ] && [ -f "$CLAUDE_JSON" ]; then
    if grep -qF "$old_token" "$CLAUDE_JSON" 2>/dev/null; then
        echo "NOTE: the old token is also used by your MCP server entry in $CLAUDE_JSON."
        echo "Re-connect that entry before revoking the old token, or leave it to expire."
    fi
fi
echo "Done. This device now renews itself; no more 90-day token ritual."
```

`chmod +x plugins/mnemon/scripts/mnemon-authorize.sh`.

- [ ] **Step 4: Run harness — A1–A4 green, everything else still green.**
- [ ] **Step 5: Sabotage checks** — (1) make the script skip the backup → A1's restore-line assertion must fail; (2) make verification call `tools/list`… not fakeable meaningfully here, instead: make the script ignore `mnemon_call`'s exit code → A1 still passes (verification succeeded) but add **A5**: point verification at a `FAKE_TOOL_ERROR=1` server → script must exit 1 with "VERIFICATION FAILED"; confirm A5 fails under the sabotage, revert.
- [ ] **Step 6: Commit** — `git commit -m "feat(plugin): mnemon-authorize.sh enrols a device via the device grant, with rollback"`.

**What could go wrong / visibility:** every terminal state prints to the terminal of the person running it and exits non-zero — this script is the one place in the system where stdout is guaranteed to have a human behind it. The riskiest moment (config flip) is preceded by a printed, copy-pasteable rollback. A verification failure still leaves both the new config *and* the backup on disk, and says which.

---

## Task 10: Plugin release 0.3.0 and documentation

**Shippable: this is the release of Tasks 7–9 — same PR.**

**Files:**
- Modify: `plugins/mnemon/.claude-plugin/plugin.json` (`"version": "0.3.0"`)
- Modify: `.claude-plugin/marketplace.json` (`"version": "0.3.0"`)
- Modify: `docs/USERGUIDE.md` (install section ~490–560; "Adding a new device" ~490)

- [ ] **Step 1: Failing test first, even here** — `PluginManifestTest::test_plugin_and_marketplace_versions_agree` already exists; bump **only** `plugin.json` and run it:
```bash
php artisan test --compact --filter=PluginManifestTest
```
Expected: FAIL (versions disagree) — proving the lockstep guard works. Then bump `marketplace.json` too; re-run; PASS.

- [ ] **Step 2: Rewrite the USERGUIDE install section.** The current text instructs the opposite of this feature in bold ("**Use a personal access token, not the OAuth flow.**"). New structure:
  1. *Install the plugin* (unchanged marketplace commands).
  2. *Connect the device*: server-side `php artisan mnemon:device-client <name>` (once per device lifetime), then on the device `bash .../scripts/mnemon-authorize.sh https://<instance> <client-id>`, approve in the browser, pick wings, watch the verification line. State: tokens self-renew; the 90-day ritual is gone.
  3. *Revoking a device*: Filament → OAuth Clients → revoke (via `TokenRevoker::client`). **Note explicitly:** refresh tokens do not rotate, so revoking a single access token only stops the device if it is the newest one — the client is the kill switch. The device shows the dead banner at its next session start.
  4. *Personal access tokens* move to a "For other agents (fallback)" subsection, tinker command intact, with the honest framing: right for agents that will never run a refresh loop; the hooks support both, and `MNEMON_TOKEN` env credentials are PAT-shaped (no refresh) by design.
  5. Update the "Adding a new device" numbered list (~line 490) and the stale claim that consent writes `mcp_token_restrictions` — it now writes `mcp_client_restrictions`.

- [ ] **Step 3: Truth-up sweeps** (design release checklist): every relative link in changed docs resolves; no claim of behavior that isn't merged; grep the diff for the falsehood vocabulary conventions used by `docs/design/claim-audit.md`.

- [ ] **Step 4: Full PHP suite + harness + pint; commit; merge the 0.3.0 PR.**

- [ ] **Step 5: Verify the release actually reaches a device** (the defect that motivates constraint 4 happened once already):
```bash
# on either device:
claude plugin update mnemon   # expected output includes 0.3.0, not "already at the latest version"
grep -l 'mnemon_refresh' ~/.claude/plugins/cache/*/mnemon/*/hooks/lib/common.sh
```
Expected: the new `common.sh` is in the cache. If `update` reports up-to-date at 0.2.1, the marketplace entry didn't propagate — stop and fix before Task 11.

---

## Task 11: Enrolment, cutover, and the live kill-switch drill

No repo code — the runbook the design's Phase 2 demands, one device at a time, ~10 minutes each. Do the second device only after the first has run for a day.

- [ ] **Step 1 (server, once per device):**
```bash
ssh forge-atlas 'cd <app-dir> && php artisan mnemon:device-client thinkpad'
```
Expected: client id + secret + the enrolment command. Keep the terminal open.

- [ ] **Step 2 (device):** run the printed `mnemon-authorize.sh` line, approve in the browser **choosing real wings** — the first time these devices have ever been restrictable — and confirm the script prints `Verified. Wings this device can reach ...` and the backup/rollback line. Save the rollback line.

- [ ] **Step 3 (device):** start a real Claude Code session. Expected: wake output normal (no expiry banner — Task 8's W2 behavior, now live). Within the next ~hour of use:
```bash
grep 'refreshed access token' ~/.mnemon/capture-errors.log
```
Expected: at least one line — the first live proof of self-renewal. Server-side cross-check: Filament → OAuth Access Tokens shows fresh tokens for `claude-code@thinkpad`; Brain Sessions rows carry that source (attribution survived the grant change — `agentSource()` falls back to the client name).

- [ ] **Step 4 (server, after a day of normal sessions):** revoke the device's old PAT — Filament → OAuth Access Tokens → revoke. **First** check the script's `~/.claude.json` audit note from Step 2; if the same PAT backs the interactive MCP entry, re-auth that entry first. Deliberately manual: the human confirms the new path works before destroying the old one.

- [ ] **Step 5: The kill-switch drill (do this once, on the first device).** In Filament, revoke the *client* `claude-code@thinkpad`. On the device, start a session after the current access token expires (or wait ~1h). Expected, in order: refresh → `invalid_client` → `auth_state:"dead"` → **wake banner with the re-run command at session start**. This is the end-to-end proof of design failure mode 7 — and of erratum 4's fix; under the design's original `invalid_grant`-only rule this drill would hang silent. Then re-provision (Step 1 with a fresh client) and re-enrol.

- [ ] **Step 6:** repeat Steps 1–4 for the second device. Done: zero PATs in use by hooks; both devices self-renewing, wing-restricted, and loudly mortal.

**What could go wrong / visibility:** every step's failure lands in a terminal or browser in front of the operator; Step 3's grep and Step 5's drill are the two checks that verify the *invisible* machinery. If Step 2's verification fails, the printed rollback restores the PAT config in one command — both devices keep working throughout, as the design promises.

---

## Execution order and PR map

| PR | Tasks | Ships alone? |
|----|-------|--------------|
| 1 | Task 1 — client-keyed restrictions | Yes (fixes a real live defect by itself) |
| 2 | Task 2 — rotation off | Yes |
| 3 | Task 3 — device screens + consent capture | Yes (needs PR 1) |
| 4 | Task 4 — discovery metadata | Yes (any time) |
| 5 | Task 5 — provisioning command | Yes (any time) |
| — | Task 6 — deploy + live verify | Checkpoint, after PRs 1–5 |
| 6 | Tasks 7+8+9+10 — plugin 0.3.0 | One release; inert on devices until the bump |
| — | Task 11 — cutover runbook | Live, manual, last |

Tasks 2, 4, 5 have no dependency on each other or on 1/3 and can be parallelized if desired; the listed order keeps each PR's review context smallest.

## Self-review against the spec

- §1.1/§1.2/§1.3 → Tasks 3, 9 (with erratum 7's `tools/call` correction). §2 → Task 3 (per erratum 1, no hidden client_id; session-derived). §3 → Task 5 (wrapper over the verified `createDeviceAuthorizationGrantClient`; confidential per the design's standing recommendation). §4.1/§4.2 → Task 7 (rotation resolved OFF in Task 2; dead-state widened per erratum 4; the second §4.1 loudness fix already shipped per erratum 3). §4.3 → Task 1 (table shape diverges per erratum 9, behavior identical). §4.4/§4.5 → Task 8. §5 → Tasks 7, 9. §6 → Tasks 10, 11 (rollback per revision 5). §7 → done pre-plan (PR #28); the honest caveat retired in Task 6 Step 4. §8 → Task 4 (extended to the nested route per erratum 5). §9 → every row mapped: 1–2 (Task 9), 3 (Task 3), 4 (Task 9, quiet by RFC), 5–6 (Task 7 R1/R2), 7 (Tasks 7 R3/R4 + 8 W1 + 11 Step 5), 8 (Task 7 R5), 9 (R8), 10 (R9 + `mnemon_config_write`), 11 (obsolete per erratum 2; replaced by the deny-all consent shape, Task 1), 12 (already shipped), 13 (Task 10 Steps 1/5), 14 (done, re-pinned in Task 2 Step 2), 15 (Task 3 Step 1). §10 → tests distributed as listed per task, each suite named. §11 → nothing out-of-scope was added (the Filament wings column is display of existing data, not client CRUD).
