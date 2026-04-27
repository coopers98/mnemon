<?php

namespace Database\Factories;

use App\Models\Wing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name'    => ucwords($name),
            'slug'    => Str::slug($name),
            'wing_id' => Wing::factory(),
        ];
    }
}
