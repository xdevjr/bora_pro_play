<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'code', 'editor_pin'])]
#[Hidden(['editor_pin'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'editor_pin' => 'hashed',
        ];
    }

    /**
     * Configure model events.
     */
    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            if (blank($room->code)) {
                $room->code = static::generateCode($room->name);
            }
        });
    }

    /**
     * Use the room code for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Get all participants for the room.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * Get only active participants for the room.
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->where('is_active', true);
    }

    /**
     * Get the room draws ordered from newest to oldest.
     */
    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class)->latest();
    }

    /**
     * Get the room scoreboards ordered from newest to oldest.
     */
    public function scoreboards(): HasMany
    {
        return $this->hasMany(Scoreboard::class)->latest();
    }

    /**
     * Generate a unique public room code from the room name.
     */
    public static function generateCode(?string $name = null): string
    {
        $baseCode = (string) Str::of((string) $name)
            ->squish()
            ->slug('-');

        if ($baseCode === '') {
            $baseCode = Str::lower(Str::random(10));
        }

        $code = $baseCode;
        $suffix = 2;

        while (static::query()->where('code', $code)->exists()) {
            $code = "{$baseCode}-{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
