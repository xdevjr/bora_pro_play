<?php

namespace Database\Factories;

use App\Models\Draw;
use App\Models\Room;
use App\Models\Scoreboard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scoreboard>
 */
class ScoreboardFactory extends Factory
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
            'draw_id' => null,
            'title' => fake()->randomElement(['Placar rapido', 'Jogo da noite', 'Final amistosa']),
            'left_team_name' => Scoreboard::defaultLeftTeamName(),
            'right_team_name' => Scoreboard::defaultRightTeamName(),
            'left_score' => 0,
            'right_score' => 0,
            'is_quick' => true,
            'meta' => null,
        ];
    }

    /**
     * Indicate that the scoreboard originated from a draw.
     */
    public function fromDraw(Draw $draw, string $leftTeamName, string $rightTeamName): static
    {
        return $this->state(fn(): array => [
            'room_id' => $draw->room_id,
            'draw_id' => $draw->id,
            'left_team_name' => $leftTeamName,
            'right_team_name' => $rightTeamName,
            'is_quick' => false,
        ]);
    }
}
