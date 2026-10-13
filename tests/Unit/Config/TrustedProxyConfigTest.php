<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class TrustedProxyConfigTest extends TestCase
{
    private function load(?string $value): mixed
    {
        $old = getenv('TRUSTED_PROXIES');
        $value === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$value}");
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        try {
            return (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            $old === false ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$old}");
        }
    }

    public function test_unset_keeps_the_frameworks_default(): void
    {
        $this->assertNull($this->load(null));
        $this->assertNull($this->load(' , '));
    }

    public function test_set_is_the_trimmed_list(): void
    {
        $this->assertSame(['10.0.0.0/8', '2400:cb00::/32'], $this->load('10.0.0.0/8, 2400:cb00::/32,'));
    }
}
