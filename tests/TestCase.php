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
     */
    private function ensurePassportKeys(): void
    {
        if (self::$passportKeysEnsured) {
            return;
        }

        self::$passportKeysEnsured = true;

        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
    }
}
