<?php

use App\Models\Draw;
use App\Models\Participant;
use App\Models\Room;
use App\Models\Scoreboard;
use Livewire\Livewire;

test('draw stores guaranteed participants inside the generated teams', function () {
    $room = Room::factory()->create();
    $participants = Participant::factory()->count(12)->create([
        'room_id' => $room->id,
    ]);
    $guaranteedParticipants = $participants->take(2);

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('teamsCount', 2)
        ->set('teamSize', 6)
        ->set('guaranteedParticipantIds', $guaranteedParticipants->pluck('id')->all())
        ->call('runDraw')
        ->assertHasNoErrors();

    $draw = Draw::query()->whereBelongsTo($room)->latest()->first();

    expect($draw)->not->toBeNull();
    expect(data_get($draw->payload, 'meta.guaranteed_ids'))
        ->toEqualCanonicalizing($guaranteedParticipants->pluck('id')->all());

    $teamMembers = collect(data_get($draw->payload, 'teams', []))
        ->flatMap(fn(array $team) => $team['members']);

    expect($teamMembers->where('guaranteed', true)->pluck('id')->all())
        ->toEqualCanonicalizing($guaranteedParticipants->pluck('id')->all());
});

test('draw excludes the last draw first and uses fallback participants when needed', function () {
    $room = Room::factory()->create();
    $participants = Participant::factory()->count(8)->create([
        'room_id' => $room->id,
    ]);

    Draw::factory()->create([
        'room_id' => $room->id,
        'teams_count' => 2,
        'team_size' => 3,
        'payload' => [
            'teams' => [],
            'bench' => [],
            'meta' => [
                'drawn_ids' => $participants->take(6)->pluck('id')->all(),
                'guaranteed_ids' => [],
                'fallback_ids' => [],
                'bench_ids' => [],
                'last_draw_participant_ids' => [],
                'excluded_last_draw_participants' => false,
            ],
        ],
    ]);

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('teamsCount', 2)
        ->set('teamSize', 3)
        ->set('excludeLastDrawParticipants', true)
        ->call('runDraw')
        ->assertHasNoErrors();

    $draw = Draw::query()->whereBelongsTo($room)->latest()->first();

    expect($draw)->not->toBeNull();
    expect(data_get($draw->payload, 'meta.excluded_last_draw_participants'))->toBeTrue();
    expect(data_get($draw->payload, 'meta.fallback_ids'))->toHaveCount(4);
    expect(data_get($draw->payload, 'meta.drawn_ids'))->toHaveCount(6);
    expect(data_get($draw->payload, 'meta.drawn_ids'))
        ->toContain($participants[6]->id, $participants[7]->id);
});

test('draw can create a scoreboard with default color names and keep the user on the room page', function () {
    $room = Room::factory()->create();

    Participant::factory()->count(12)->create([
        'room_id' => $room->id,
    ]);

    session()->put('editable_rooms', [$room->id]);

    $component = Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('teamsCount', 2)
        ->set('teamSize', 6)
        ->call('runDraw')
        ->assertHasNoErrors();

    $scoreboard = Scoreboard::query()->whereBelongsTo($room)->latest()->first();

    expect($scoreboard)->not->toBeNull()
        ->and($scoreboard?->draw_id)->not->toBeNull()
        ->and($scoreboard?->title)->toBeNull()
        ->and($scoreboard?->left_team_name)->toBe(Scoreboard::defaultLeftTeamName())
        ->and($scoreboard?->right_team_name)->toBe(Scoreboard::defaultRightTeamName())
        ->and($scoreboard?->is_quick)->toBeFalse()
        ->and(data_get($scoreboard?->meta, 'origin'))->toBe('draw');

    $component
        ->assertSee('data-draw-result-modal', false)
        ->assertSee('Ir para o placar')
        ->assertSee('Abrir placar deste sorteio')
        ->assertSee(route('rooms.scoreboards.show', [$room, $scoreboard]), false);
});
