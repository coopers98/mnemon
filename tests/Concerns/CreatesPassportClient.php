<?php

namespace Tests\Concerns;

use Laravel\Passport\ClientRepository;

trait CreatesPassportClient
{
    protected function setUpPassportClient(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test Client', 'users');
    }
}
