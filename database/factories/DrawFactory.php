<?php

namespace Database\Factories;

use App\Models\Draw;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Draw>
 */
class DrawFactory extends Factory
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
            'teams_count' => 2,
            'team_size' => 3,
            'excludes_last_draw_participants' => false,
            'payload' => [
                'teams' => [
                    ['name' => 'Time 1', 'members' => [], 'count' => 0],
                    ['name' => 'Time 2', 'members' => [], 'count' => 0],
                ],
                'bench' => [],
                'meta' => [
                    'capacity' => 6,
                    'active_count' => 6,
                    'drawn_ids' => [],
                    'guaranteed_ids' => [],
                    'fallback_ids' => [],
                    'bench_ids' => [],
                    'last_draw_participant_ids' => [],
                    'excluded_last_draw_participants' => false,
                ],
            ],
        ];
    }
}
