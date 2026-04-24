<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReembedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reembed_command_runs_without_error_with_null_driver(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);
        $room = Room::create(['name' => 'Test Room', 'slug' => 'test-room', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'Test drawer', 'room_id' => $room->id]);
        WikiPage::create([
            'name' => 'test-page',
            'type' => 'concept',
            'title' => 'Test',
            'content' => 'Test wiki',
        ]);

        $this->artisan('mnemon:reembed')
            ->assertExitCode(0)
            ->expectsOutput('Re-embedding drawers...')
            ->expectsOutput('Re-embedding wiki pages...')
            ->expectsOutput('Re-embedding complete!');
    }

    public function test_reembed_command_with_model_option_drawer(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);
        $room = Room::create(['name' => 'Test Room', 'slug' => 'test-room', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'Test drawer', 'room_id' => $room->id]);

        $this->artisan('mnemon:reembed', ['--model' => 'drawer'])
            ->assertExitCode(0)
            ->expectsOutput('Re-embedding drawers...');
    }

    public function test_reembed_command_with_model_option_wiki(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        WikiPage::create([
            'name' => 'test-page',
            'type' => 'concept',
            'title' => 'Test',
            'content' => 'Test wiki',
        ]);

        $this->artisan('mnemon:reembed', ['--model' => 'wiki'])
            ->assertExitCode(0)
            ->expectsOutput('Re-embedding wiki pages...');
    }
}
