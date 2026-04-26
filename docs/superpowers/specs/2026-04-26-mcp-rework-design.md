# MCP Rework — Design Spec

**Date:** 2026-04-26
**Status:** Approved (ready for implementation plan)
**Author:** Cooper + Claude (brainstorm)

## Goal

Replace Mnemon's bespoke MCP-shaped REST endpoint (`/api/mcp/call` + `App\Models\ApiKey` bearer auth) with a fully spec-compliant MCP server built on `laravel/mcp` and `laravel/passport`. The result must let any MCP client — Claude Code, Claude.ai, ChatGPT desktop, Cursor, ad-hoc agents — connect to `https://mnemon.example.com/mcp`, complete an OAuth 2.1 flow with Dynamic Client Registration, and call the existing 12 tools plus new resources and prompts. Mnemon becomes a single, common second brain across every device and agent install.

## Non-goals

- Stdio transport (out of scope; HTTP only).
- Cross-device ephemeral session state (e.g. "current task") — flagged as a separate future feature.
- Renaming the misnamed `context_*` tools — flagged as a separate breaking-change ticket.
- Backups & disaster recovery — separate operational ticket.
- Multi-user invitations / collaboration UX — single-user today; OAuth model already supports more users when needed.

## Decisions (locked during brainstorm)

| Decision | Choice |
|---|---|
| Auth model | OAuth 2.1 + Dynamic Client Registration via Laravel Passport |
| OAuth login backing | Existing Filament admin login (`User` model) |
| Scope shape | Coarse: `palace.read`, `palace.write`, `wiki.read`, `wiki.write` |
| Wing restrictions | Per-token, captured in OAuth consent screen, stored in sibling table |
| Transport | HTTP only (`POST /mcp`, Streamable HTTP) |
| MCP capabilities | Tools + Resources + Prompts |
| Backwards compatibility | Hard cut. Delete `/api/mcp/call`, `ApiKey`, middleware, registry, base tool, exception, Filament resource, seeder. |
| `BrainSession` source | Add `oauth_client_id` + `user_id` + `access_token_id` columns; keep `source` as derived display string |
| Filament admin | New OAuth Clients + Active Tokens resources + Live Sessions dashboard widget |

## Architecture

### Packages

Add: `laravel/mcp`, `laravel/passport`.
Remove (no replacement needed): the entire bespoke MCP stack (see "Removed" below).

### Endpoint surface (post-rework)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/mcp` | `auth:api` (Passport bearer) | Streamable HTTP MCP — JSON-RPC for `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get` |
| `GET`/`POST` | `/oauth/authorize`, `/oauth/token`, `/oauth/clients`, … | varies | Passport OAuth 2.1 + DCR endpoints, registered via `Mcp::oauthRoutes()` |
| `/admin/*` | (unchanged Filament) | Filament login | Admin UI; gains OAuth Client and Token management |

### File layout (target)

```
routes/
  ai.php                                 # Mcp::oauthRoutes(); Mcp::web('/mcp', MnemonServer::class)
  api.php                                # MCP routes removed
app/Mcp/
  Servers/MnemonServer.php
  Tools/                                 # 12 tools rewritten
  Resources/
    DrawerResource.php                   # mnemon://drawer/{id}
    WikiPageResource.php                 # mnemon://wiki/{slug}
    WingResource.php                     # mnemon://wing/{slug}
  Prompts/
    SynthesizeWingPrompt.php
    FindStaleWikiPagesPrompt.php
    DrawerToWikiPrompt.php
  Concerns/
    RequiresScope.php                    # tokenCan() helper
    RequiresWingAccess.php               # reads sibling table, enforces patterns
    ResolvesAgentSource.php              # auto-stamps drawer.source / revision.agent_id
  Support/
    BrainSessionLogger.php               # replaces BaseTool::logSession()
app/Filament/Resources/
  OauthClientResource.php
  OauthAccessTokenResource.php
app/Filament/Widgets/
  LiveMcpSessionsWidget.php
resources/views/mcp/
  authorize.blade.php                    # OAuth consent (Mnemon-styled)
database/migrations/
  YYYY_MM_DD_install_passport_tables.php           # via passport:install
  YYYY_MM_DD_create_mcp_token_restrictions.php     # sibling table
  YYYY_MM_DD_extend_brain_sessions_with_oauth.php  # add oauth_client_id, user_id, access_token_id
  YYYY_MM_DD_drop_api_keys_table.php               # explicit data drop
```

### Removed

- `app/Http/Controllers/McpController.php`
- `app/Http/Middleware/AuthenticateApiKey.php`
- `app/Mcp/BaseTool.php`
- `app/Mcp/McpToolRegistry.php`
- `app/Mcp/McpException.php`
- `app/Models/ApiKey.php` and its factory
- `app/Filament/Resources/ApiKeyResource*` (and pages)
- `database/seeders/ApiKeySeeder.php` (and any reference from `DatabaseSeeder`)
- `database/migrations/*_create_api_keys_table.php` superseded by drop migration
- MCP routes from `routes/api.php`

## Server, Tools, Resources, Prompts

### `MnemonServer`

```php
#[Name('Mnemon')]
#[Version('1.0.0')]
#[Instructions('Mnemon is a self-hosted second brain. Use palace tools for verbatim storage, wiki tools for synthesized knowledge.')]
class MnemonServer extends Laravel\Mcp\Server
{
    protected array $tools = [
        BrainStatusTool::class, PalaceWakeUpTool::class,
        DrawerAddTool::class, DrawerSearchTool::class, DrawerGetTool::class,
        ContextGetTool::class, ContextSetTool::class, ContextListTool::class,
        WikiLintTool::class, WikiCompileTool::class, WikiHistoryTool::class, WikiGraphTool::class,
    ];

    protected array $resources = [
        DrawerResource::class, WikiPageResource::class, WingResource::class,
    ];

    protected array $prompts = [
        SynthesizeWingPrompt::class, FindStaleWikiPagesPrompt::class, DrawerToWikiPrompt::class,
    ];
}
```

### Tool rewrite pattern

Every tool follows this shape:

```php
#[Description('...')]
#[IsReadOnly]   // or #[IsDestructive] / #[IsIdempotent] as appropriate
class DrawerSearchTool extends Laravel\Mcp\Server\Tool
{
    use RequiresScope, RequiresWingAccess;
    protected string $scope = 'palace.read';

    public function __construct(protected DrawerSearchService $search) {}

    public function handle(Laravel\Mcp\Request $request): Laravel\Mcp\Response
    {
        $this->requireScope($request);
        $params = $request->validate([
            'query' => 'required|string|max:500',
            'limit' => 'integer|min:1|max:50',
            'wing'  => 'nullable|string',
        ]);

        if (! empty($params['wing'])) {
            $this->requireWingAccess($request, $params['wing']);
        }

        $results = $this->search->run($params, $request->user(), $this->wingPatternsFor($request));
        BrainSessionLogger::log($request, 'drawer_search', $params, count($results));

        return Laravel\Mcp\Response::structured(['results' => $results]);
    }

    public function schema(JsonSchema $s): array { /* mirrors validate() */ }
}
```

### Scope mapping

| Scope | Tools |
|---|---|
| `palace.read` | `brain_status`, `palace_wake_up`, `drawer_search`, `drawer_get` |
| `palace.write` | `drawer_add` |
| `wiki.read` | `wiki_history`, `wiki_graph`, `context_get`, `context_list` |
| `wiki.write` | `wiki_lint`, `wiki_compile`, `context_set` |

`context_*` are wiki ops in disguise (already implement WikiPage CRUD with optimistic locking + revision tracking + knowledge-graph extraction). They get wiki scopes, not invented `context.*` scopes.

### Resources

- `mnemon://drawer/{id}` → drawer content + metadata. Requires `palace.read` and wing access.
- `mnemon://wiki/{slug}` → compiled wiki page markdown. Requires `wiki.read`.
- `mnemon://wing/{slug}` → wing index (rooms + drawer counts). Requires `palace.read` and wing access.

`shouldRegister(Request)` returns false when the token lacks the relevant scope so the resource is hidden from clients that can't use it.

### Prompts

- `SynthesizeWingPrompt(wing_slug)` — instructs the client to compile a wiki page from a wing's drawers.
- `FindStaleWikiPagesPrompt(days?)` — surfaces wiki pages older than N days (default 30).
- `DrawerToWikiPrompt(drawer_id)` — proposes wiki edits from a single drawer.

## Authentication & authorization

### Setup

```bash
composer require laravel/passport laravel/mcp
php artisan passport:install --uuids
php artisan vendor:publish --tag=ai-routes
php artisan vendor:publish --tag=mcp-views
```

### `AppServiceProvider::boot()`

```php
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
// DCR is exposed by laravel/mcp via Mcp::oauthRoutes() (see routes/ai.php below).
// During implementation, verify the exact enablement surface (Passport flag vs.
// laravel/mcp wiring) — see Risks section.
```

`User` model adds `Laravel\Passport\HasApiTokens`. Login flow reuses Filament's admin login (`/admin/login`); Passport's authorize endpoint redirects unauthenticated users there and back.

### `routes/ai.php`

```php
use App\Mcp\Servers\MnemonServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', MnemonServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
```

### Consent screen

`resources/views/mcp/authorize.blade.php` (Mnemon-styled) shows:

- Client name (auto-registered via DCR; displayed alongside the redirect URI's host so the user can tell `Claude Code @ work-laptop` from `Claude Code @ home`).
- Requested scopes as checkboxes — user can drop scopes before granting.
- Wing access selector — list populated from `Wing::orderBy('slug')->get()` with one checkbox per wing, plus an "All wings (no restriction)" master checkbox that disables the per-wing list when set. Default state: nothing checked — user must explicitly opt in to grant wing access.

On submit, granted wing patterns are persisted in `mcp_token_restrictions`:

```sql
CREATE TABLE mcp_token_restrictions (
  access_token_id VARCHAR(100) PRIMARY KEY REFERENCES oauth_access_tokens(id) ON DELETE CASCADE,
  wing_patterns JSONB,                  -- e.g. ["work:*", "research"]; null = unrestricted
  created_at TIMESTAMPTZ DEFAULT now()
);
```

A sibling table (not a Passport schema modification) so future Passport upgrades stay clean.

### Per-token throttling

`RouteServiceProvider`:

```php
RateLimiter::for('mcp', fn (Request $r) =>
    $r->user()
        ? Limit::perMinute(120)->by($r->user()->currentAccessToken()->id)
        : Limit::perMinute(20)->by($r->ip())
);
```

## Cross-cutting traits

- **`RequiresScope`** — `requireScope(Request)` calls `$request->user()->tokenCan($this->scope)`; throws `Response::error()` on miss.
- **`RequiresWingAccess`** — reads `mcp_token_restrictions.wing_patterns` for the active token. `requireWingAccess(Request, string $wingSlug)` matches the slug against patterns (literal + `*` wildcard, same logic as today's `ApiKey::canAccessWing`). `wingPatternsFor(Request)` returns the raw list so query tools can filter result sets server-side instead of just rejecting.
- **`ResolvesAgentSource`** — `agentSource(Request, ?string $override)` returns `$override ?? $request->user()->currentAccessToken()->client->name`. Used by `DrawerAddTool` (defaults `drawer.source`) and `ContextSetTool` (defaults `revision.agent_id`).

## Audit trail

### `brain_sessions` migration

```php
Schema::table('brain_sessions', function (Blueprint $t) {
    $t->uuid('oauth_client_id')->nullable()->index()->after('source');
    $t->foreignId('user_id')->nullable()->after('oauth_client_id')->constrained()->nullOnDelete();
    $t->string('access_token_id')->nullable()->index()->after('user_id');
});
```

`source` column is preserved but its meaning shifts: it becomes a derived display string written by `BrainSessionLogger`, e.g. `"Claude Code as cooper@... (token …f4a2)"`. No backfill — historical rows keep their old `source` values.

### `BrainSessionLogger`

```php
class BrainSessionLogger
{
    public static function log(Request $request, string $tool, array $input, int $resultCount): void
    {
        $token = $request->user()?->currentAccessToken();
        $client = $token?->client;

        BrainSession::create([
            'tool_name'        => $tool,
            'oauth_client_id'  => $client?->id,
            'user_id'          => $request->user()?->id,
            'access_token_id'  => $token?->id,
            'source'           => self::renderSource($client, $request->user(), $token),
            'input'            => $input,
            'result_count'     => $resultCount,
        ]);
    }

    private static function renderSource(?Client $client, ?User $user, ?Token $token): string
    {
        // "Claude Code as cooper@... (token …f4a2)"
    }
}
```

## Filament admin

- **OAuth Clients resource** (`app/Filament/Resources/OauthClientResource.php`) — list/show registered Passport clients (DCR-created or manual). Columns: name, redirect URIs, created_at, active token count, revoke action (cascades to tokens).
- **Active Tokens resource** (`app/Filament/Resources/OauthAccessTokenResource.php`) — list non-revoked, non-expired tokens. Columns: client name, user, scopes (badges), wing restrictions (badges from sibling table), last_used_at (joined from latest `BrainSession`), expires_at, revoke action. Default sort by last_used_at desc.
- **Live MCP Sessions widget** (`app/Filament/Widgets/LiveMcpSessionsWidget.php`) — top 5 most recently used tokens with client name + user + last tool call + relative time.

The existing `ApiKeyResource` is removed in the same PR.

## Migration & cutover

Single PR, single deploy:

1. `composer require laravel/passport laravel/mcp`
2. `php artisan passport:install --uuids` (Passport migrations, key generation)
3. `php artisan vendor:publish --tag=ai-routes` and `--tag=mcp-views`
4. New migrations: `mcp_token_restrictions`, extend `brain_sessions`
5. Migration: drop `api_keys` table (explicit, single-user prod has one key)
6. Code deletions per "Removed" list
7. New code: `MnemonServer`, 12 rewritten tools, 3 resources, 3 prompts, traits, logger, consent view, Filament resources/widget
8. `AppServiceProvider::boot()` Passport configuration
9. Rewrite existing `tests/Feature/Mcp*Test.php` against the new tool surface
10. Update `CLAUDE.md` MCP section (Sprint 2 → done; document `/mcp` endpoint, OAuth flow, scope list)

**Cooper's cutover steps:**

1. Deploy.
2. In Claude Code: remove the existing X-API-Key MCP entry. Run `claude mcp add --transport http mnemon https://mnemon.example.com/mcp` and complete the browser OAuth flow.
3. Repeat on every other agent install (one-time per device/client).

**Rollback:** revert PR and restore `api_keys` table from pre-deploy DB backup. Any palace writes made through the new path between deploy and rollback are lost — don't sit on a rollback decision.

## Testing strategy

Use `RefreshDatabase` on SQLite (existing convention). Add minimal Passport bootstrapping in test setup (factory for `OauthClient`, helper to issue tokens with given scopes + wing restrictions).

1. **OAuth flow** — simulate `authorize` → `token` exchange; assert access token issued with correct scopes and wing restrictions persisted to sibling table.
2. **Tool dispatch (per tool)** — happy path response shape; missing-scope rejection (token without `palace.write` calling `drawer_add` → MCP error); wing rejection (token restricted to `work:*` reading a `personal:*` wing → 403-style error).
3. **Provenance auto-stamp** — `drawer_add` without explicit `source` → `drawer.source` equals OAuth client name. `context_set` → revision row has `agent_id` equal to client name.
4. **Audit trail** — every tool call writes a `BrainSession` row with `oauth_client_id`, `user_id`, `access_token_id` populated.
5. **Resources & prompts** — list and read each of the 3 resources; list and render each of the 3 prompts.
6. **Concurrency** — keep existing `context_set` revision-conflict tests (they already verify `lockForUpdate` behavior). During implementation, audit `wiki_compile` for the same pattern; add tests if locking is added there.
7. **Throttle** — burst 130 calls in a minute with the same token → last 10 receive 429.

Existing `Mcp*Test.php` files are rewritten (not deleted — preserve assertion intent).

## Documentation updates

Every doc that references the old API-key auth, the `/api/mcp/call` endpoint, the `ApiKey` model, or the "Sprint 2 — MCP planned" stub must be updated in the same PR. The implementation isn't done until the docs match the code.

| File | Updates required |
|---|---|
| `README.md` | Rewrite §"Per-agent authorisation" (lines ~44) to describe OAuth scopes + per-token wing restrictions. Rewrite §"API keys" (lines ~78) as §"OAuth clients & tokens" — describe DCR, the consent screen, revocation via Filament. Rewrite §"MCP tools" intro (lines ~84) — endpoint is `POST /mcp` (Streamable HTTP / JSON-RPC), auth is OAuth bearer. Update tools table scope column to new coarse scopes. Update §"Sprints" entries (lines ~157, ~160) — Sprint 1 no longer mentions ApiKeys; Sprint 4 description swaps API-key middleware for OAuth + Passport. Update §"Single-tenant" (line ~190) — clarify that OAuth tokens (not API keys) provide agent-level isolation. |
| `CLAUDE.md` | Remove the "MCP Tools — Planned for Sprint 2" stub (it's stale — tools shipped). Replace with current state: `POST /mcp` (Streamable HTTP), OAuth via Passport, scope list, link to consent flow. Remove `ApiKey` from §"API Keys" entirely; replace with §"OAuth Clients & Tokens" describing the Passport + DCR model. Update §"File Structure Conventions" to reflect new `app/Mcp/Servers`, `app/Mcp/Resources`, `app/Mcp/Prompts`, `app/Mcp/Concerns`, `app/Mcp/Support` layout and removed paths. Update §"Models" line to drop `ApiKey`. |
| `docs/FRD.md` | Replace the `api_keys` table schema (lines ~48–50) with the `mcp_token_restrictions` schema. Update auth requirements section to specify OAuth 2.1 + DCR via Passport, refresh tokens, per-token wing restrictions captured at consent. |
| `docs/IMPLEMENTATION-PLAN.md` | Mark relevant sections as superseded by this spec — add a top-level note linking to `docs/superpowers/specs/2026-04-26-mcp-rework-design.md`. Specifically: §"Current state" (line ~23) drop ApiKey from the resource list; §"create_api_keys_table" block (lines ~71–74) strike through with a "superseded by Passport + mcp_token_restrictions" note; §"ApiKey scope checking" (line ~127) note replaced by `tokenCan` + `RequiresWingAccess`; §"ApiKey hash lookup" (line ~207) note replaced by `auth:api`; §"ApiKeyResource" (lines ~311, ~341) note replaced by `OauthClientResource` + `OauthAccessTokenResource`. |
| `docs/OPENCLAW-INTEGRATION.md` | Replace the `curl -X POST /api/mcp/call -H "X-API-Key: …"` example (lines ~54–57) with the new flow: short paragraph on registering an OAuth client (or running through the Claude `claude mcp add` flow), then a JSON-RPC `tools/call` example using a Passport bearer token. Note that `OPENAI_API_KEY` (line ~199) is unrelated and stays. |
| `AGENTS.md` | No changes (pointer file, no MCP-specific content). |
| `docs/GAP-ANALYSIS.md`, `docs/discovery.md`, `docs/WIKI-FRONTEND-PLAN.md`, `docs/design_system.md` | Spot-check during implementation; likely no changes (historical/unrelated). If any reference `ApiKey` or `/api/mcp/call`, fix them. |

Add a final task to the implementation plan: **before the PR is opened, grep the entire repo (excluding `vendor/`, `node_modules/`, and `docs/superpowers/specs/`) for `ApiKey`, `X-API-Key`, `/api/mcp/call`, `AuthenticateApiKey`, `McpController`, `McpToolRegistry`, and `BaseTool`.** Anything that turns up outside the deletion list is a stale reference that needs updating or deleting.

## Open follow-ups (out of scope for this rework)

1. **Cross-device ephemeral session state** — new `session_get/set/list` tools tied to `user_id` (not token) for "current task" continuity across agent installs. Genuinely useful for the multi-device second-brain story; deferred because it's a new feature, not a rework.
2. **Rename `context_*` tools** to `wiki_set` / `wiki_get` / `wiki_list` for clarity. Breaking change; needs its own ticket once OAuth migration has settled.
3. **Backups & DR** — daily Postgres + pgvector dump, off-site. Becomes critical once N agents depend on this server.
4. **Tool-contract versioning policy** — codify "no new required params; version tool name on real breaks" once external consumers exist beyond Cooper.
5. **Stdio transport** — only if a real consumer appears that can't speak HTTP MCP.

## Risks

- **Passport DCR maturity** — Dynamic Client Registration is relatively new in Passport. Verify behavior end-to-end during implementation; have a manual-client-registration fallback path documented in case DCR misbehaves with a specific MCP client.
- **Filament + Passport route collision** — confirm `/oauth/*` doesn't collide with anything Filament uses. Spot-check before merge.
- **Refresh-token UX in Claude clients** — clients that don't refresh proactively will surface auth prompts at expiry; 1h is short. If this is annoying in practice, lengthen access token lifetime to 24h after observing behavior.
- **Wing restrictions on resources** — `shouldRegister()` runs at registration time, but per-resource wing checks must also run at read time. Easy to forget; covered by tests in section "Tool dispatch."
