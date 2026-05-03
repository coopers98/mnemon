<?php

namespace Database\Factories;

use App\Models\WikiPendingWing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiPendingWing>
 */
class WikiPendingWingFactory extends Factory
{
    protected $model = WikiPendingWing::class;

    public function definition(): array
    {
        return [
            'wing_slug' => $this->faker->slug(2),
            'wing_name' => $this->faker->words(2, true),
            'rationale' => $this->faker->sentence(),
            'drawer_payload' => [
                'content' => $this->faker->paragraph(),
                'room_slug' => 'notes',
                'source' => 'claude-code:session_digest',
            ],
            'status' => WikiPendingWing::STATUS_PENDING,
        ];
    }
}
