<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'name' => fake()->firstName(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the participant is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn(): array => [
            'is_active' => false,
        ]);
    }
}
