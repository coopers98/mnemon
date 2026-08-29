<?php

namespace Tests\Unit;

use Tests\TestCase;

class AppTimezoneConfigTest extends TestCase
{
    /**
     * config/app.php used to hard-code 'timezone' => 'UTC', which makes
     * APP_TIMEZONE in .env.docker.example decorative — nothing ever reads
     * it. By the time a test method runs, the app's config repository is
     * already booted from whatever environment existed at process start, so
     * this loads the raw config file directly (with APP_TIMEZONE forced into
     * the environment first) to check what the file itself produces,
     * independent of the already-booted repository.
     */
    public function test_app_timezone_config_reads_the_env_variable(): void
    {
        $previousPutenv = getenv('APP_TIMEZONE');
        $previousEnv = $_ENV['APP_TIMEZONE'] ?? null;
        $previousServer = $_SERVER['APP_TIMEZONE'] ?? null;

        putenv('APP_TIMEZONE=America/Denver');
        $_ENV['APP_TIMEZONE'] = 'America/Denver';
        $_SERVER['APP_TIMEZONE'] = 'America/Denver';

        try {
            $config = require base_path('config/app.php');

            $this->assertSame(
                'America/Denver',
                $config['timezone'],
                'config/app.php ignored APP_TIMEZONE — the env var is decorative'
            );
        } finally {
            if ($previousPutenv === false) {
                putenv('APP_TIMEZONE');
            } else {
                putenv('APP_TIMEZONE='.$previousPutenv);
            }

            if ($previousEnv === null) {
                unset($_ENV['APP_TIMEZONE']);
            } else {
                $_ENV['APP_TIMEZONE'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['APP_TIMEZONE']);
            } else {
                $_SERVER['APP_TIMEZONE'] = $previousServer;
            }
        }
    }
}
