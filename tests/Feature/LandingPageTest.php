<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Mnemon')
            ->assertSee('self-hosted second brain')
            ->assertSee('The memory');
    }

    public function test_landing_page_includes_design_system_stylesheet(): void
    {
        $this->get('/')->assertSee('styles/mnemon.css', escape: false);
    }
}
