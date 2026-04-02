<?php

namespace App\Models;

use Database\Factories\DrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['room_id', 'teams_count', 'team_size', 'excludes_last_draw_participants', 'payload'])]
class Draw extends Model
{
    /** @use HasFactory<DrawFactory> */
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DrawFactory
    {
        return DrawFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'excludes_last_draw_participants' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * Get the room that owns the draw.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the scoreboards created from this draw.
     */
    public function scoreboards(): HasMany
    {
        return $this->hasMany(Scoreboard::class);
    }

    /**
     * Get the participant IDs assigned to teams in this draw.
     *
     * @return array<int, int>
     */
    public function drawnParticipantIds(): array
    {
        return collect(data_get($this->payload, 'meta.drawn_ids', []))
            ->map(fn($id): int => (int) $id)
            ->all();
    }
}
