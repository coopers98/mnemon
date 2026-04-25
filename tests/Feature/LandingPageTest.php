<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Mnemon')
            ->assertSee('self-hosted second brain')
            ->assertSee('Open admin');
    }

    public function test_landing_page_links_to_admin(): void
    {
        $this->get('/')->assertSee('href="/admin"', escape: false);
    }
}
