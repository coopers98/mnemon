# Design: Device Authorization Grant (RFC 8628) for Layer 2 Devices

> **Dated design rationale, 2026-09-02.** This document records what was designed and
> believed at that date. It is not maintained as current documentation, and it may
> describe behaviour the code no longer has. Check the code before relying on it.

**Status:** designed, not implemented.
**Depends on:** the 4xx fixes in §7 (piece 0). Everything else is additive.

## Goal

The two devices that run the Layer 2 hooks stop authenticating with personal
access tokens and start authenticating with tokens minted by the OAuth device
authorization grant. When the change is done:

- A device renews itself with a refresh token; nobody SSHes to the server to
  run tinker every 90 days.
- Device tokens carry per-token wing restrictions, captured on a consent
  screen, enforced by `mcp_token_restrictions` — the isolation mechanism PATs
  bypass entirely.
- Every way the new machinery can fail produces a message somewhere a person
  will see it. This feature has shipped fifteen defects and every one failed
  silently; the design below treats "fails loudly" as a requirement equal to
  "works".

## Locked decisions

| Decision | Choice |
|---|---|
| Grant | Device authorization grant, RFC 8628. Passport 13.7.5 ships it enabled; the routes and `oauth_device_codes` table already exist. |
| Client topology | **One OAuth client per device**, confidential, named `claude-code@<device>`. Argued in §3. |
| Refresh location | Inside `mnemon_call()` in `plugins/mnemon/hooks/lib/common.sh`, behind a portable lock. Argued in §4. |
| Credential file | `~/.mnemon/config.json` keeps the key `bearer_token` for the live access token and gains refresh fields. A PAT config is the same file minus those fields. §5. |
| Restriction continuity | Wing restrictions are copied forward to each refreshed token by the `AccessTokenCreated` listener; a missing source row fails **closed** (deny-all) and logs, never open. §4.3. |
| The 500s | Fixed first, as piece 0. One guard fixes all three. §7. |
| PATs | Retained as a documented fallback for non-Claude-Code agents; the two devices' PATs are revoked manually after cutover. §6. |

## What already exists (verified in code)

Passport 13.7.5 registers the device grant whenever the route exists and the
flag is on — both are true here:

- `Passport::$deviceCodeGrantEnabled` defaults to `true`
  (`vendor/laravel/passport/src/Passport.php:36`), and the grant is enabled at
  `vendor/laravel/passport/src/PassportServiceProvider.php:171-175`.
- The grant is built with a **10-minute device-code TTL**, verification URI
  `route('passport.device')`, a **5-second poll interval**, a 90-day refresh
  TTL, and both `verification_uri_complete` and `interval` included in the
  response (`vendor/laravel/passport/src/PassportServiceProvider.php:238-250`).
- Routes are live (`vendor/laravel/passport/routes/web.php:18-30` and
  `:49-64`): `POST /oauth/device/code` (throttled, unauthenticated),
  `GET /oauth/device` (web), and `GET|POST|DELETE /oauth/device/authorize`
  (web + auth).
- `oauth_device_codes` is migrated
  (`database/migrations/2026_04_27_034509_create_oauth_device_codes_table.php`)
  and applied on the live instance.

What does **not** exist:

- Any client with the device grant. All 10 live clients are `personal_access`.
- View bindings for the two device screens. Passport 13 ships no Blade views
  at all, and binds the view-response contracts only when the app calls
  `Passport::deviceAuthorizationView()` / `deviceUserCodeView()`
  (`vendor/laravel/passport/src/Passport.php:655-667`). Mnemon binds only the
  auth-code consent view (`app/Providers/AppServiceProvider.php:57-59`), so
  resolving `DeviceUserCodeViewResponse` today should throw a container
  `BindingResolutionException` — i.e. `GET /oauth/device` is a 500 right now.
  (Code-derived; not yet confirmed against the live instance.) The approve and
  deny *response* contracts are bound by Passport itself
  (`vendor/laravel/passport/src/PassportServiceProvider.php:128-129`), so only
  the two views need registering.
- Wing-restriction capture on the device approve POST
  (`app/Http/Middleware/CaptureConsentWings.php:24-27` matches only
  `oauth/authorize`).
- Any refresh logic in the hooks.
- Device-flow entries in the discovery metadata
  (`vendor/laravel/mcp/src/Server/Registrar.php:123-130`).

## 1. The flow, concretely

### 1.1 What the device does

A new script, `plugins/mnemon/scripts/mnemon-authorize.sh`, run once per
device (and again only if authorization dies):

1. Reads the instance base URL from an argument or the existing
   `~/.mnemon/config.json` endpoint; reads `client_id`/`client_secret` from
   arguments or a prompt.
2. `POST /oauth/device/code` with `client_id`, `client_secret`, and
   `scope=mcp:use`. The scope must be sent explicitly:
   `Passport::$defaultScope` is empty (`Passport.php:51`), so an unscoped
   request yields a token with no scopes, which the `mcp:use` requirement on
   every tool then rejects. Response
   (`vendor/league/oauth2-server/src/ResponseTypes/DeviceCodeResponse.php:39-49`):

   ```json
   {
     "device_code": "<opaque, ~10 min>",
     "user_code": "BCDFGHJK",
     "verification_uri": "https://mnemon.example.com/oauth/device",
     "verification_uri_complete": ".../oauth/device?user_code=BCDFGHJK",
     "expires_in": 600,
     "interval": 5
   }
   ```

3. Prints, unmissably:

   ```
   Open:  https://mnemon.example.com/oauth/device?user_code=BCDF-GHJK
   or go to https://mnemon.example.com/oauth/device
   and enter code:  BCDF-GHJK
   Waiting for approval (expires in 10 minutes)...
   ```

4. Polls `POST /oauth/token` with
   `grant_type=urn:ietf:params:oauth:grant-type:device_code`, `device_code`,
   `client_id`, `client_secret`, every `interval` seconds, adding 5 seconds
   whenever the server answers `slow_down` (RFC 8628 §3.5). The poll outcomes
   (`vendor/league/oauth2-server/src/Grant/DeviceCodeGrant.php`):
   - `authorization_pending` (:159) — keep polling.
   - `slow_down` (:156) — polled faster than the interval; back off.
   - `access_denied` (:163) — the user denied. Terminal: print it and exit 1.
   - `expired_token` (:209) — the 10 minutes ran out. Terminal: say so, tell
     the user to re-run the script.
   - Success — access token (1 hour, `app/Providers/AppServiceProvider.php:53`),
     refresh token (90 days, `:54`). The device code is revoked on redemption,
     so it cannot be replayed.
5. Writes `~/.mnemon/config.json` atomically (§5), then **verifies** by making
   one authenticated `tools/list` call against `/mcp` and prints the result
   either way. A setup path that declares success without a round trip is how
   D-series defects are born.

User codes are 8 characters from the 20-consonant alphabet
`BCDFGHJKLMNPQRSTVWXZ` (`DeviceCodeGrant.php:303`) — no vowels, so no
accidental words; no digits, so no 0/O confusion. Display them hyphenated
(`BCDF-GHJK`); the server strips hyphens on entry
(`vendor/laravel/passport/src/Http/Controllers/DeviceAuthorizationController.php:40`).

### 1.2 What the user sees

1. Opens `verification_uri_complete` on any browser-equipped machine. If not
   logged in, the `auth` middleware bounces through `/login`
   (`routes/web.php:15`) and back.
2. If they used the bare URI, they land on the **user-code entry screen**
   (`GET /oauth/device`) and type the code. A wrong code redirects back with
   "Incorrect code." (`DeviceAuthorizationController.php:44-48`).
3. They land on the **device consent screen**
   (`GET /oauth/device/authorize?user_code=...`): which client is asking, the
   code being approved, and the wing checkboxes (§2).
4. Approve or deny. Either way Passport redirects back to `/oauth/device` with
   a `status` flash (`vendor/laravel/passport/src/Http/Responses/ApprovedDeviceAuthorizationResponse.php:17-18`),
   which the user-code view renders as "Device authorized — you can close
   this tab" or "Authorization denied".

### 1.3 Where the codes live

`oauth_device_codes`: hashed-length `id` char(80), plaintext `user_code`
char(8) unique, `client_id`, `scopes`, `user_approved_at`, `last_polled_at`,
`expires_at`. Approval persists the user id and approved flag
(`DeviceCodeGrant.php:117-134`); the *next poll* — at most `interval` seconds
later — mints the tokens. Nothing long-lived is stored server-side beyond the
ordinary `oauth_access_tokens` / `oauth_refresh_tokens` rows.

## 2. The consent screen

Two new Blade views, registered in `AppServiceProvider::boot()` beside the
existing `authorizationView` call:

```php
Passport::deviceUserCodeView('mcp.device-user-code');
Passport::deviceAuthorizationView(fn ($p) => view('mcp.device-authorize', array_merge($p, [
    'wings' => Wing::orderBy('slug')->get(),
])));
```

**`resources/views/mcp/device-user-code.blade.php`** — a single code input
with the `$errors->first('user_code')` slot and a `session('status')` slot for
the approved/denied flashes. Same card chrome as `mcp/authorize.blade.php`.

**`resources/views/mcp/device-authorize.blade.php`** — a sibling of
`mcp/authorize.blade.php`, same voice, same wing block copied verbatim: the
all-wings master toggle, per-wing checkboxes named `wings[]` disabled while
all-wings is checked, and the same caveat text that restrictions cover palace
content but not the wiki. The controller hands it `client`, `user`, `scopes`,
`request`, `authToken` (`DeviceAuthorizationController.php:57-70`). The
differences, all deliberate:

- **Identity.** The heading is "Authorize device: {{ $client->name }}". With
  one client per device (§3) that renders as `claude-code@thinkpad` — the
  screen names the actual machine, not a generic app. It also echoes the user
  code being approved so the person can match it against their terminal, and
  carries one warning line: *"A device just asked for this code. If you
  didn't run mnemon-authorize on one of your machines a moment ago, deny
  this."* That sentence is the whole anti-phishing story for a single-user
  instance and it costs one `<p>`.
- **Form targets.** Approve POSTs to `passport.device.authorizations.approve`
  with hidden `auth_token` (the session-bound CSRF-alike Passport checks in
  `RetrievesDeviceCodeFromSession`) and — added by us — a hidden
  `client_id`, because `CaptureConsentWings` keys its cache on it. Deny is the
  spoofed-DELETE form to `passport.device.authorizations.deny`.
- **No `window.close()` choreography.** The auth-code view self-closes because
  a client opened it; here the user navigated deliberately, and the
  post-approval redirect to `/oauth/device` with a status flash is the
  feedback.

**Capturing the wings.** `CaptureConsentWings` extends its match from
`oauth/authorize` to also cover `oauth/device/authorize`
(`app/Http/Middleware/CaptureConsentWings.php:24-27`); it already runs on the
whole web group (`bootstrap/app.php:16-19`). The existing pipeline then works
unchanged: the approve POST caches `{patterns}` under
`mcp_consent_wings:{userId}:{clientId}` for 10 minutes
(`app/Listeners/PersistMcpTokenRestrictions.php:30-40`), the device's next
poll (≤ 5 s later) issues the token, `AccessTokenCreated` fires, and the
listener writes the `mcp_token_restrictions` row (`:52-55`). The 10-minute
cache TTL dwarfs the 5-second poll gap.

## 3. Client provisioning: one confidential client per device

Created by the owner, over SSH, once per device *lifetime*:

```bash
php artisan passport:client --device --name="claude-code@thinkpad"
```

That grants `['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token']`
(`vendor/laravel/passport/src/ClientRepository.php:161-169`) and is
confidential by default (`vendor/laravel/passport/src/Console/ClientCommand.php:130-137`;
`--public` exists but we don't use it). This is the last SSH-and-artisan
ritual in the story — it replaces a per-90-days chore with a per-device-ever
one.

**One client per device, not one shared client.** Four reasons, three of them
load-bearing:

1. **Attribution survives the grant change.** Grant-issued tokens have no
   `name`; `agentSource()` falls back to the client name
   (`app/Mcp/Concerns/ResolvesAgentSource.php:18-20`), as does
   `BrainSessionLogger`. With per-device clients every `BrainSession` row
   still says which machine acted. With a shared client, all devices collapse
   into one source — a regression against what per-device PAT names give
   today.
2. **Restriction carry-forward needs an unambiguous key.** §4.3 copies wing
   restrictions to refreshed tokens by "latest restricted token of this
   (user, client)". One client per device makes that exact; a shared client
   would let two devices with different wing grants cross-contaminate.
3. **The consent cache is keyed on `userId:clientId`**
   (`PersistMcpTokenRestrictions.php:18-21`). Two devices authorizing
   concurrently under a shared client would overwrite each other's pending
   wings. Unlikely at n=2; impossible with per-device clients.
4. **Revocation granularity.** Revoking the client kills that device's ability
   to refresh without touching the other device.

**Confidential, not public.** The instance is on the public internet. A public
client means anyone can initiate device flows against it and spam the owner
with plausible-looking consent screens; a confidential client gates flow
initiation on a secret. The secret lives in the same 0600 file as the refresh
token, so it creates no exposure class the refresh token hasn't already
created. The cost is one more field in `config.json`.

DCR stays as it is: laravel/mcp's register endpoint creates auth-code clients
with `enableDeviceFlow: false`
(`vendor/laravel/mcp/src/Server/Http/Controllers/OAuthRegisterController.php:79-85`),
which is correct — anonymous dynamic registration of device-grant clients on a
public endpoint would undo the previous paragraph.

## 4. Hook-side refresh

All of it lives in `plugins/mnemon/hooks/lib/common.sh`, so wake, recall,
capture, and the digest worker inherit it by sourcing.

### 4.1 Where the 401 is caught, and what happens

`mnemon_call()` already separates status from body and logs 401/403
distinctly (`common.sh:104-111`). The change: when the status is **401** (not
403 — that's a scope or wing denial and refreshing won't fix it) *and* the
config carries refresh credentials, call `mnemon_refresh`, and on success
retry the original request exactly once with the new token. One retry, never a
loop.

Two adjacent loudness fixes ride along because they are three lines each:

- A JSON-RPC `.error` payload is currently swallowed with a bare `return 1`
  (`common.sh:123-126`) — the last silent path in `mnemon_call`. Log it to
  `capture-errors.log` like every other failure.
- Every successful refresh writes one log line ("refreshed access token,
  next expiry ~HH:MM"). Refresh working is a fact worth being able to see.

### 4.2 The exchange, and what stops a stampede

`mnemon_refresh`:

1. **Take the lock.** A `mkdir`-based lock at `~/.mnemon/refresh.lock.d`
   containing a PID file, with liveness-checked stale recovery — the exact
   pattern the digest worker already uses
   (`plugins/mnemon/hooks/lib/digest-worker.sh:14-25`). Not `flock`: it is
   util-linux, absent on macOS, and the hooks promise only jq + curl +
   coreutils. Waiters poll for the lock for up to ~5 s, then log and fail
   this one call rather than breaking a live holder's lock.
2. **Re-read the config under the lock and compare.** If `bearer_token` no
   longer equals the token that just got the 401, another hook already
   refreshed — return the new token and do nothing. This is the whole
   anti-stampede mechanism: three hooks 401 at once, one wins the lock and
   refreshes, the other two find a changed token and retry with it. The
   lost-update risk to `config.json` is closed by the same lock plus the
   atomic write in §5.
3. **Exchange.** `POST` to the stored `token_endpoint` with
   `grant_type=refresh_token`, `refresh_token`, `client_id`,
   `client_secret`, `scope=mcp:use` — request body via `--data @file`, never
   argv, so secrets don't appear in `ps`. Passport rotates on use:
   the old access token and old refresh token are both revoked the moment the
   new pair is issued (`Passport::$revokeRefreshTokenAfterUse = true`,
   `vendor/laravel/passport/src/Passport.php:31`;
   `vendor/league/oauth2-server/src/Grant/RefreshTokenGrant.php:75-79`). This
   is why step 2 matters: a second use of the same refresh token is
   `invalid_grant`, full stop.
4. **Validate before writing.** `jq -e` the response; require non-empty
   `access_token` *and* `refresh_token`; then the atomic write of §5. A
   refresh that would store an empty token is a failure, loudly logged, and
   the old (still possibly usable) state is kept.

**Distinguish "dead" from "unlucky".** Only a definitive OAuth
`invalid_grant` (refresh token expired, revoked, or already used by a writer
whose config update was lost) marks the device dead: set
`auth_state: "dead"` plus a reason and timestamp in the config, log it, and
stop attempting refreshes. A network error, a 5xx, or a non-JSON body (the
proxy-HTML shape that once surfaced as "Invalid numeric literal") is **not**
dead: keep the stored refresh token, log the failure, and let the next 401
try again. Marking a device dead on a flaky connection would be a
self-inflicted outage; retrying a dead refresh token forever would be the
15-defect silence pattern.

### 4.3 Wing restrictions must survive refresh

This is the bug this design exists to not ship. `RequiresWingAccess` treats a
missing restriction row as unrestricted
(`app/Mcp/Concerns/RequiresWingAccess.php:18`) — that is how PATs get full
access. A refreshed token has a brand-new token id, `AccessTokenCreated`
fires with no cached consent, and the listener returns early
(`app/Listeners/PersistMcpTokenRestrictions.php:47-50`). Untouched, every
restricted device silently becomes an all-wings device **one hour after
consent**, and nothing anywhere would say so.

Fix, in `PersistMcpTokenRestrictions::handle()`: when there is no cached
consent *and the token's client has the device grant* (the personal-access
client does not, so PAT behaviour is untouched):

- Copy `wing_patterns` from the newest earlier token of the same
  `(user, client)` that has a restriction row. Note that an all-wings consent
  stores a row with `wing_patterns = null` (`:34-36`), so every consented
  device token has a row — the chain never legitimately starts empty.
- If no source row exists, write `wing_patterns: []` — which
  `McpTokenRestriction::matches()` evaluates as deny-all
  (`app/Models/McpTokenRestriction.php:26-49`) — and log an error. Denials
  are additionally audited per-call by `BrainSessionLogger::logDenial`
  (`RequiresWingAccess.php:23`), so the failure is visible in the admin panel
  the moment the device tries anything. Fail closed and loud; never open and
  silent.

### 4.4 When refresh happens, relative to the hooks' budgets

- **Wake (SessionStart, 10 s hook timeout)** refreshes *proactively*: if the
  access JWT's `exp` is within 10 minutes (reusing `mnemon_token_days_left`'s
  decoding, at second granularity), refresh before calling `palace_wake_up`.
  Session starts are where a ~1 s refresh is invisible, so the mid-prompt
  path almost never pays it.
- **Recall (UserPromptSubmit, 10 s hook timeout)** keeps its tight
  `recall_timeout_ms` budget for the recall call itself; a 401 triggers the
  refresh (own ~5 s timeout) and one retry. Worst case this happens once per
  hour per device and costs one extra round trip.
- **Capture / digest worker** get it for free via `mnemon_call`; the worker's
  60 s call timeout dwarfs a refresh.

### 4.5 The expiry warning must change

`mnemon-wake.sh:33-42` warns when the JWT has ≤ `token_warn_days` left — and
declares the token **expired** at `days_left ≤ 0`. A device-flow access token
lives one hour, so `mnemon_token_days_left` returns 0 and every session would
open with a false "the access token has expired" banner. When the config
carries refresh credentials, that warning is skipped entirely; the loud paths
that replace it are §4.2's `auth_state: "dead"` banner (printed by wake at
every session start until re-authorized, with the exact re-run command) and
the refresh failure log. PAT configs keep the old countdown unchanged. There
is deliberately no "refresh token is ageing" countdown: each refresh issues a
new 90-day refresh token, so expiry only bites a device unused for 90 straight
days, and that device gets the dead-state banner on its first wake back.

The unconfigured message (`mnemon-wake.sh:17-21`) — which today steers people
*toward* PATs because "OAuth access tokens expire in an hour" — inverts to
point at `mnemon-authorize.sh`. Its rationale was the absence of refresh
logic; this design removes the rationale.

## 5. Credential storage

`~/.mnemon/config.json`, still mode 0600, after device authorization:

```json
{
  "endpoint": "https://mnemon.example.com/mcp",
  "token_endpoint": "https://mnemon.example.com/oauth/token",
  "client_id": "<uuid>",
  "client_secret": "<secret>",
  "bearer_token": "<current 1-hour access JWT>",
  "refresh_token": "<opaque rotating token>",
  "refreshed_at": 1756800000,
  "auth_state": "ok"
}
```

plus whatever tunables were already there (`recall_timeout_ms`,
`max_digest_bytes`, ...), which the writer must preserve by merging, not
replacing.

- **`bearer_token` keeps its name.** Every reader — `mnemon_token()`
  (`common.sh:29-33`), the installer's merge, the env-var fallback — stays
  valid, and a PAT config is simply this file minus the refresh fields.
  Presence of `refresh_token` + `client_id` is the feature switch: no
  version field, no migration of old files, nothing to get wrong.
- **Atomic writes, always.** The zero-byte state file that wedged a session
  for hours came from a non-atomic write; the cure is already codified in
  `mnemon_session_state_write` (`common.sh` temp + `mv -f`). Config writes
  use the same shape with two additions: `chmod 600` on the temp file
  *before* the rename (the umask is not trusted), and `jq -e` validation of
  the temp file before the rename — a good config is never replaced by
  garbage, and an interrupted write leaves only an orphaned `.tmp.$$` that
  the next write ignores.
- **Never write empties.** A refresh response missing either token aborts the
  write (§4.2). The file on disk is always either the old working state or
  the new working state.

Environment-variable credentials (`MNEMON_ENDPOINT`/`MNEMON_TOKEN`,
`common.sh:17-25`) remain supported but are static by nature — no refresh —
so they stay a PAT-shaped mechanism and the docs will say so.

## 6. Migration off PATs

Both devices keep working throughout; every phase is additive until the final,
deliberate revocation.

**Phase 0 — server (deployable immediately, invisible to PAT devices).** The
4xx guard (§7), the two view bindings and views (§2), the
`CaptureConsentWings` extension, the restriction carry-forward (§4.3), the
discovery override (§8), and their tests. PATs don't touch any of these paths.

**Phase 1 — plugin 0.3.0.** Refresh-aware `common.sh`, `mnemon-authorize.sh`,
the wake-message changes. The version string in
`plugins/mnemon/.claude-plugin/plugin.json` **must** bump from 0.2.0 —
a plugin only re-fetches when its version changes, so shipping this under
0.2.0 would deploy to zero devices while looking shipped.

**Phase 2 — per device, ~2 minutes each, one device at a time.**

1. On the server, once: `php artisan passport:client --device --name="claude-code@<device>"`.
2. On the device: `mnemon-authorize.sh`, approve on the consent screen
   (choosing wings — the first time these devices have ever been
   restrictable), watch the script's verification call succeed.
3. The script's single atomic config write flips the device from PAT to
   device tokens. There is no dual-credential window to manage and no outage:
   the PAT remains valid in the database, just unreferenced.
4. After a day of normal sessions, revoke that device's PAT in Filament
   (OAuth Access Tokens → revoke,
   `app/Filament/Resources/OauthAccessTokens/Tables/OauthAccessTokensTable.php`).
   Deliberately manual — the human confirms the new path works before
   destroying the old one. A forgotten PAT self-expires within 90 days
   anyway.

**Docs.** `docs/USERGUIDE.md:504-521` currently instructs the opposite of
this design in bold ("Use a personal access token, not the OAuth flow") — the
install section is rewritten around the device flow, and the PAT instructions
move to a "for other agents / fallback" subsection. The tinker path stays
documented: it is still the right tool for non-Claude-Code agents that will
never run a refresh loop.

## 7. The 500s — fixed first

Three observed 500s, one root cause, verified in code:
`ClientRepository::find()` runs the raw id straight into a query against the
UUID primary key (`vendor/laravel/passport/src/ClientRepository.php:18`), and
PostgreSQL raises on a malformed UUID before any OAuth validation runs. Every
client lookup funnels through this method — the authorize endpoint (D15,
`docs/design/2026-08-28-release-roadmap.md:349-355`), the device-code
endpoint, and the token endpoint (via
`Bridge\ClientRepository::getClientEntity`/`validateClient` →
`findActive` → `find`).

**Fix:** an app-level subclass — `find()` returns `null` when the id is not a
valid UUID — bound over the singleton in `AppServiceProvider::register()`
(Passport binds it at `PassportServiceProvider.php:115`; app providers
register later and win). League then produces the correct RFC responses on
its own: `invalid_client` with 401 on `POST /oauth/token` and
`POST /oauth/device/code` (RFC 6749 §5.2, RFC 8628 §3.2), and Passport's
`OAuthServerException` rendering for `GET /oauth/authorize`.

**This is a prerequisite, not a cleanup.** The setup script and the refresh
logic branch on HTTP status and OAuth `error` strings. A 500 carrying a
Laravel HTML error page is precisely the shape that once reached `jq` as
"Invalid numeric literal" — an unparseable non-answer at the exact moment a
device is trying to report why it can't authenticate. Loud failure requires
the server to speak OAuth when it rejects.

One honest caveat: the observed refresh-grant 500 is *attributed* to this
cause, not proven — it cannot have been tested with a valid refresh-capable
client, because none exists on the live instance. After the guard lands,
piece 0 includes reproducing the refresh call with a real client; any 500
that survives is a new stop-the-line defect. Note also that the UUID cast
does not throw on SQLite, so the regression tests for these are only
meaningful on the CI matrix's pgsql leg (`.github/workflows/ci.yml:24`).

## 8. Discovery metadata

`/.well-known/oauth-authorization-server` currently advertises only
`authorization_code` and `refresh_token`, with no
`device_authorization_endpoint`
(`vendor/laravel/mcp/src/Server/Registrar.php:119-131` — hardcoded). Per
RFC 8628 §4 / RFC 8414 the server should add:

```json
"device_authorization_endpoint": "https://.../oauth/device/code",
"grant_types_supported": ["authorization_code", "refresh_token",
                          "urn:ietf:params:oauth:grant-type:device_code"]
```

The mechanism is already provided: `Registrar::oauthRoutes()` skips
registering the well-known route if one exists (`Registrar.php:92-103`), and
`routes/ai.php` is app-owned — so Mnemon defines its own
`GET /.well-known/oauth-authorization-server` route in `routes/ai.php`
**above** the `Mcp::oauthRoutes()` call (`routes/ai.php:6`), returning the
package's shape plus the two additions. Guaranteed ordering, no
vendor patching.

Who reads it: Claude Code's own MCP OAuth (authorization-code + DCR —
unaffected either way) and any future RFC-compliant device client.
`mnemon-authorize.sh` does *not* depend on it — it derives
`/oauth/device/code` and `/oauth/token` from the instance URL, because a
setup script that breaks when metadata is stale is a worse failure mode than
a hardcoded path on a server we control. So this piece is about honesty, not
function: metadata that misdescribes the server is the documentation variant
of a silent failure, and the fix costs a dozen lines.

## 9. Failure modes, each with how it becomes visible

| # | Failure | How it becomes visible |
|---|---|---|
| 1 | User denies consent | Poll returns `access_denied`; script prints "authorization denied on the consent screen" and exits 1. |
| 2 | Device code expires (10 min) | Poll returns `expired_token`; script says so and says "re-run this script". |
| 3 | Wrong user code typed | Consent page redirects back with "Incorrect code." (`DeviceAuthorizationController.php:44-48`). |
| 4 | Script polls too fast | `slow_down`; script backs off 5 s and says nothing — the one intentionally quiet path, per RFC. |
| 5 | Access token expires mid-session | 401 → locked refresh → retry; one "refreshed access token" line in `capture-errors.log`. |
| 6 | Three hooks 401 at once | One refreshes; the others find the changed token under the lock and retry with it. Lock wait timeout logs "refresh lock busy". |
| 7 | Refresh token expired / revoked / lost the rotation race | `invalid_grant` → `auth_state:"dead"` + reason in config, error log line, **and a wake banner with the re-auth command at every session start** until re-authorized. |
| 8 | Server unreachable / 5xx / proxy HTML during refresh | Logged with the status and first 80 bytes; refresh token kept; retried on next 401. Never marks the device dead. |
| 9 | Refresh response missing a token | Write refused, error logged, old state kept. |
| 10 | Config write interrupted | Temp-file + validate + rename: disk always holds old-good or new-good; orphaned `.tmp.$$` files are inert. |
| 11 | Restriction row absent after refresh | Deny-all row written + error logged (§4.3); every subsequent tool call is denied *and audited* via `BrainSessionLogger::logDenial`. Loud stop, not silent widening. |
| 12 | JSON-RPC tool error | Now logged in `mnemon_call` (§4.1) instead of the current bare `return 1` (`common.sh:123-126`). |
| 13 | Plugin change not picked up | Version bump 0.2.0 → 0.3.0 is a release checklist item; without it the deploy reaches zero devices. |
| 14 | Bogus/malformed client id anywhere | Proper OAuth 4xx JSON (§7) that scripts can branch on, instead of a 500 HTML page. |
| 15 | `GET /oauth/device` before views land | Currently a 500 (unbound view contract); phase 0 makes it a page. A feature test pins it. |

## 10. Testing strategy

**PHP suite** (`tests/`, both CI legs, `.github/workflows/ci.yml:24`) — the
server halves, as real HTTP against the app:

- `DeviceFlowTest` (Feature/Mcp, modeled on `EndToEndOAuthTest`): create a
  device client; `POST /oauth/device/code` and assert the response shape
  (8-char user code, `interval: 5`, `expires_in ≤ 600`,
  `verification_uri_complete`); render both screens; approve with a wing
  selection; poll the token endpoint; assert the `mcp_token_restrictions` row;
  call an `/mcp` tool and assert wing enforcement actually bites.
- Poll-state tests: pending before approval, `slow_down` on an immediate
  re-poll, `access_denied` after deny, `expired_token` after time travel past
  10 minutes, device code unusable after redemption.
- Refresh tests: exchange rotates both tokens; old refresh token reuse is
  `invalid_grant`; **the refreshed token carries the copied restriction row**
  (the §4.3 regression test — the single most important test in this design);
  a device-client token with no source row gets the deny-all row; a PAT
  still gets no row and full access.
- The 4xx guard: malformed `client_id` on `GET /oauth/authorize`,
  `POST /oauth/device/code`, and `POST /oauth/token` (both grant types)
  returns OAuth-shaped 4xx JSON. Meaningful on the pgsql leg only; runs on
  both.
- Discovery: the well-known document advertises the device endpoint and
  grant, and the app route wins over the package route.
- View wiring: `GET /oauth/device` renders (pinning the fix to today's 500),
  the consent view renders the wings list.

**Bash harness** (`tests/Hooks/run-hook-tests.sh`, CI at
`.github/workflows/ci.yml:114`) — the client halves, against the fake server
(`tests/Hooks/fixtures/server.sh`, Python http.server, easy to extend):

- Teach the fake server `POST /oauth/token` and two env modes:
  `FAKE_EXPIRE_FIRST` (401 every `/mcp` call until a refresh is seen, then
  accept the new token) and `FAKE_REFRESH_FAIL=invalid_grant`.
- Refresh happy path: a hook gets 401, refreshes, retries once, succeeds;
  `config.json` holds the new pair, is valid JSON, and is still mode 0600.
- Stampede: launch three hook invocations concurrently against
  `FAKE_EXPIRE_FIRST`; the request log shows **exactly one** refresh.
- Dead state: `invalid_grant` sets `auth_state:"dead"`; wake prints the
  re-auth banner; no further refresh attempts are made.
- Network-vs-dead distinction: a 500/non-JSON refresh response leaves the
  refresh token in place and does not set dead.
- Legacy config: a PAT-shaped config (no refresh keys) never attempts a
  refresh and keeps today's 401 log message and expiry countdown.
- `mnemon-authorize.sh` poll loop against faked device endpoints: pending →
  success writes config and verifies; denied and expired exit non-zero with
  the right words on stdout.
- Atomicity: a config write interrupted between temp and rename leaves the
  old config intact.

## 11. Explicitly out of scope

- **Claude Code's own MCP OAuth.** The authorization-code + DCR path the
  interactive client uses is untouched, including its consent screen.
- **DCR for device clients** — deliberately excluded (§3).
- **Admin UI for creating device clients.** `passport:client --device` is
  fine at n=2 devices; Filament CRUD for clients is not this change.
- **Multi-user consent semantics.** One user exists; nothing here designs for
  tenant separation beyond what wing restrictions already do.
- **Encrypted-at-rest credential storage.** The 0600-file model stands;
  keychain/keyring integration is a different feature.
- **D16 (reverse-proxy trust)** and the rest of the D-list except the D15
  class fixed in §7.
- **Automatic PAT revocation** at cutover — manual by design (§6).
- **Refresh-token theft detection / family revocation.** Rotation-on-use is
  what Passport gives (`Passport.php:31`); building reuse-detection beyond
  `invalid_grant` is not warranted for a single-owner instance.

## Open questions

1. **The refresh-grant 500 is attributed, not reproduced.** All ten live
   clients are `personal_access`, so the refresh grant has never been
   exercised with a valid client. Piece 0 must reproduce it after the UUID
   guard lands; a surviving 500 is a new defect that blocks §4.
2. **Confidential vs public device client.** Recommended confidential (§3);
   the cost is a client secret on each device's disk. If the owner prefers
   fewer moving parts over the flow-initiation gate, public works with two
   fields removed — the rest of the design is unchanged.
3. **Fail-closed deny-all** (§4.3) means a bug in the carry-forward listener
   hard-stops a device's memory (loudly) rather than widening its access
   (silently). This design says that trade is correct; confirm.
4. **What OS is the second device?** The mkdir-lock was chosen because
   `flock` doesn't exist on macOS. If both devices are Linux, `flock -n`
   would be simpler and worth the swap.
5. **Does `mnemon:install-claude-code-hooks` learn the device flow**, or does
   the plugin's `mnemon-authorize.sh` become the only supported entry point?
   Two install paths exist today (artisan installer and plugin); this design
   only commits the plugin path.
6. **The `GET /oauth/device` 500 is code-derived** (unbound
   `DeviceUserCodeViewResponse`), consistent with — but not identical to —
   the confirmed 500 on `POST /oauth/device/code`. Confirm against the live
   instance before any release note claims the device screens "already
   existed".
