<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    private static bool $passportKeysEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeys();
        $this->withoutVite();
        $this->preventOutboundHttp();
    }

    /**
     * No test may reach the network.
     *
     * Until CI grew a PostgreSQL leg this was accidental: the embedding
     * observers bail out unless the connection is pgsql, so on SQLite nothing
     * could call a provider. On pgsql they can, and so can
     * `PalaceSearchService::semanticSearch()`, which embeds the *query*. A
     * misconfigured driver would then turn a hundred-odd fixture creations
     * into live POSTs to api.openai.com — slow, flaky, and billable.
     *
     * `Http::fake()` in an individual test still wins; this only rejects
     * requests nothing has stubbed. Note the embedding drivers catch and log
     * every exception, so a stray request there is blocked and logged rather
     * than surfaced as a failure — the network call is still prevented, which
     * is the point.
     */
    private function preventOutboundHttp(): void
    {
        Http::preventStrayRequests();
    }

    /**
     * A fresh clone has no Passport signing keys, and every test that mints a
     * token fails with "Invalid key supplied" — which reads as a broken
     * checkout rather than a missing setup step. Generate them once per
     * process rather than making every contributor discover this.
     *
     * Guard on both files, not just the private key: `passport:keys` writes
     * the public key before the private key, so a prior run interrupted
     * between those two writes (CI timeout, OOM kill) can leave a partial
     * pair in either direction. `--force` is required to regenerate over a
     * partial pair, because the command refuses to touch either file that
     * already exists otherwise — but we only ever reach that call when at
     * least one file is missing, so a complete, healthy pair is never
     * overwritten and a developer's existing tokens stay valid. The flag
     * latches only after re-checking both files exist post-call, so a
     * silent failure (the command returns without throwing) doesn't leave
     * every later test in the process skipping generation forever.
     */
    private function ensurePassportKeys(): void
    {
        if (self::$passportKeysEnsured) {
            return;
        }

        $privateKey = storage_path('oauth-private.key');
        $publicKey = storage_path('oauth-public.key');

        if (! file_exists($privateKey) || ! file_exists($publicKey)) {
            Artisan::call('passport:keys', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }

        self::$passportKeysEnsured = file_exists($privateKey) && file_exists($publicKey);
    }
}
