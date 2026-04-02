<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Quadra ' . fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'code' => Str::slug($name),
            'editor_pin' => '1234',
        ];
    }
}
