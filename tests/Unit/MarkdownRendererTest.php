<?php

namespace Tests\Unit;

use App\Models\WikiPage;
use App\Services\MarkdownRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownRendererTest extends TestCase
{
    use RefreshDatabase;

    protected MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MarkdownRenderer;
    }

    public function test_renders_basic_markdown(): void
    {
        $result = $this->renderer->render('# Hello World');

        $this->assertStringContainsString('<h1>Hello World</h1>', $result);
    }

    public function test_renders_valid_wikilink(): void
    {
        WikiPage::create([
            'name' => 'project:atlas-abs',
            'type' => 'project',
            'title' => 'Atlas Abs',
            'content' => 'Test content.',
        ]);

        $result = $this->renderer->render('See [[project:atlas-abs]] for details.');

        $this->assertStringContainsString('class="wiki-link"', $result);
        $this->assertStringContainsString('href="/wiki/project%3Aatlas-abs"', $result);
        $this->assertStringContainsString('>Atlas Abs</a>', $result);
    }

    public function test_renders_broken_wikilink(): void
    {
        $result = $this->renderer->render('See [[nonexistent-page]] here.');

        $this->assertStringContainsString('class="wiki-link-broken"', $result);
        $this->assertStringContainsString('href="/wiki/nonexistent-page"', $result);
        $this->assertStringContainsString('>Nonexistent Page</a>', $result);
    }

    public function test_renders_wikilink_with_alias(): void
    {
        WikiPage::create([
            'name' => 'person:cooper',
            'type' => 'person',
            'title' => 'Cooper',
            'content' => 'A person.',
        ]);

        $result = $this->renderer->render('Talk to [[person:cooper|Cooper Sellers]] about it.');

        $this->assertStringContainsString('class="wiki-link"', $result);
        $this->assertStringContainsString('>Cooper Sellers</a>', $result);
    }

    public function test_renders_broken_wikilink_with_alias(): void
    {
        $result = $this->renderer->render('See [[missing-page|Nice Label]].');

        $this->assertStringContainsString('class="wiki-link-broken"', $result);
        $this->assertStringContainsString('>Nice Label</a>', $result);
    }

    public function test_strips_type_prefix_from_display_name(): void
    {
        $result = $this->renderer->render('Check [[project:my-cool-thing]].');

        // Should display "My Cool Thing" not "Project:My Cool Thing"
        $this->assertStringContainsString('>My Cool Thing</a>', $result);
    }

    public function test_renders_multiple_wikilinks(): void
    {
        WikiPage::create([
            'name' => 'concept:testing',
            'type' => 'concept',
            'title' => 'Testing',
            'content' => 'Testing content.',
        ]);

        $result = $this->renderer->render('Read [[concept:testing]] and [[missing-concept]].');

        $this->assertStringContainsString('class="wiki-link"', $result);
        $this->assertStringContainsString('class="wiki-link-broken"', $result);
    }

    public function test_handles_content_with_no_wikilinks(): void
    {
        $result = $this->renderer->render('Just regular **markdown** content.');

        $this->assertStringContainsString('<strong>markdown</strong>', $result);
        $this->assertStringNotContainsString('wiki-link', $result);
    }
}
