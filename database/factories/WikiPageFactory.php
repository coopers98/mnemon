<?php

namespace Database\Factories;

use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiPage>
 */
class WikiPageFactory extends Factory
{
    public function definition(): array
    {
        $type = $this->faker->randomElement(['person', 'project', 'concept', 'decision', 'synthesis']);

        return [
            'name' => $type.':'.$this->faker->unique()->slug(2),
            'type' => $type,
            'title' => $this->faker->sentence(3),
            'content' => $this->faker->paragraphs(3, true),
            'description' => $this->faker->sentence(),
            'confidence' => $this->faker->randomElement(['high', 'medium', 'low']),
            'confidence_score' => $this->faker->randomFloat(4, 0, 1),
            'source_count' => 0,
            'sources' => [],
            'related' => [],
            'pending_drawers_since_compile' => 0,
            'revision_count' => 0,
            'last_compiled_at' => now(),
            'last_accessed_at' => null,
        ];
    }
}
