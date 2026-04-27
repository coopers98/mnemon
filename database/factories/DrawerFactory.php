<?php

namespace Database\Factories;

use App\Models\Drawer;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drawer>
 */
class DrawerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'content' => $this->faker->paragraph(),
            'source' => $this->faker->optional()->url(),
            'metadata' => null,
            'tier' => 'raw',
            'retention_score' => 1.0,
        ];
    }
}
