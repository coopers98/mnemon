<?php

namespace Database\Factories;

use App\Models\WikiPageRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WikiPageRevision>
 */
class WikiPageRevisionFactory extends Factory
{
    protected $model = WikiPageRevision::class;

    public function definition(): array
    {
        return [
            'page_name' => 'person:unknown',
            'revision' => 1,
            'content' => $this->faker->paragraph(),
            'content_hash' => hash('sha256', Str::random(32)),
            'agent_id' => 'test-agent',
            'written_at' => now(),
        ];
    }
}
