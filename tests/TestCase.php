<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $passportKeysEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeys();
        $this->withoutVite();
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
