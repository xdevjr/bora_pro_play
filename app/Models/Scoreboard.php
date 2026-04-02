<?php

namespace App\Models;

use Database\Factories\ScoreboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'draw_id', 'title', 'left_team_name', 'right_team_name', 'left_score', 'right_score', 'is_quick', 'meta'])]
class Scoreboard extends Model
{
    public const DEFAULT_LEFT_TEAM_NAME = 'Azul';

    public const DEFAULT_RIGHT_TEAM_NAME = 'Laranja';

    /** @use HasFactory<ScoreboardFactory> */
    use HasFactory;

    public static function defaultLeftTeamName(): string
    {
        return self::DEFAULT_LEFT_TEAM_NAME;
    }

    public static function defaultRightTeamName(): string
    {
        return self::DEFAULT_RIGHT_TEAM_NAME;
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->draw_id ? 'Placar do sorteio' : 'Placar rapido');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ScoreboardFactory
    {
        return ScoreboardFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_quick' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * Get the room that owns the scoreboard.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the draw that originated the scoreboard.
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }
}
