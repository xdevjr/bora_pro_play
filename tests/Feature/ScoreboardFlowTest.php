<?php

use App\Models\Room;
use App\Models\Scoreboard;
use Livewire\Livewire;

test('quick scoreboard can be created from the room with default color names', function () {
    $room = Room::factory()->create();

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->call('createQuickScoreboard');

    $scoreboard = Scoreboard::query()->whereBelongsTo($room)->first();

    expect($scoreboard)->not->toBeNull()
        ->and($scoreboard?->title)->toBeNull()
        ->and($scoreboard?->left_team_name)->toBe(Scoreboard::defaultLeftTeamName())
        ->and($scoreboard?->right_team_name)->toBe(Scoreboard::defaultRightTeamName());

    expect(session('flux_toast.slots.heading'))->toBe('Placar criado')
        ->and(session('flux_toast.dataset.variant'))->toBe('success');

    $this->get(route('rooms.scoreboards.show', [$room, $scoreboard]))
        ->assertSuccessful()
        ->assertSee('wire:snapshot', false)
        ->assertSee('Placar rapido')
        ->assertSee(Scoreboard::defaultLeftTeamName())
        ->assertSee(Scoreboard::defaultRightTeamName());
});

test('quick scoreboard still accepts optional custom team names', function () {
    $room = Room::factory()->create();

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('quickLeftTeamName', 'Os Relampagos')
        ->set('quickRightTeamName', 'As Brasas')
        ->call('createQuickScoreboard');

    $scoreboard = Scoreboard::query()->whereBelongsTo($room)->latest()->first();

    expect($scoreboard)->not->toBeNull()
        ->and($scoreboard?->title)->toBeNull()
        ->and($scoreboard?->left_team_name)->toBe('Os Relampagos')
        ->and($scoreboard?->right_team_name)->toBe('As Brasas');

});

test('scoreboard scores can be updated through livewire actions', function () {
    $room = Room::factory()->create();
    $scoreboard = Scoreboard::factory()->create([
        'room_id' => $room->id,
    ]);

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::scoreboards.show', ['room' => $room, 'scoreboard' => $scoreboard])
        ->call('syncScoreboard', 21, 19)
        ->assertHasNoErrors();

    expect($scoreboard->fresh()->left_score)->toBe(21)
        ->and($scoreboard->fresh()->right_score)->toBe(19);
});

test('scoreboard labels can be updated inline and cleared back to the defaults', function () {
    $room = Room::factory()->create();
    $scoreboard = Scoreboard::factory()->create([
        'room_id' => $room->id,
        'title' => null,
    ]);

    session()->put('editable_rooms', [$room->id]);

    Livewire::test('pages::scoreboards.show', ['room' => $room, 'scoreboard' => $scoreboard])
        ->set('scoreboardTitle', 'Final da noite')
        ->set('leftTeamName', 'Os Raios')
        ->set('rightTeamName', 'As Brasas')
        ->call('saveScoreboardMeta')
        ->assertHasNoErrors();

    expect($scoreboard->fresh()->title)->toBe('Final da noite')
        ->and($scoreboard->fresh()->left_team_name)->toBe('Os Raios')
        ->and($scoreboard->fresh()->right_team_name)->toBe('As Brasas');

    Livewire::test('pages::scoreboards.show', ['room' => $room, 'scoreboard' => $scoreboard])
        ->set('scoreboardTitle', '')
        ->set('leftTeamName', '')
        ->set('rightTeamName', '')
        ->call('saveScoreboardMeta')
        ->assertHasNoErrors();

    expect($scoreboard->fresh()->title)->toBeNull()
        ->and($scoreboard->fresh()->left_team_name)->toBe(Scoreboard::defaultLeftTeamName())
        ->and($scoreboard->fresh()->right_team_name)->toBe(Scoreboard::defaultRightTeamName());
});

test('scoreboard page includes the custom confirmation dialog shell', function () {
    $room = Room::factory()->create();
    $scoreboard = Scoreboard::factory()->create([
        'room_id' => $room->id,
    ]);

    $this->withSession(['editable_rooms' => [$room->id]])
        ->get(route('rooms.scoreboards.show', [$room, $scoreboard]))
        ->assertSuccessful()
        ->assertSee('data-confirm-dialog', false)
        ->assertSee('/confirm-dialog.css?v=1', false)
        ->assertSee('/confirm-dialog.js?v=1', false)
        ->assertSee('ui-toast-group', false)
        ->assertSee('data-flux-tooltip', false)
        ->assertSee('data-spa-head', false)
        ->assertSee('data-livewire-confirm-method="resetScoreboard"', false)
        ->assertDontSee('data-spa-link', false)
        ->assertDontSee('data-spa-form', false)
        ->assertDontSee('status-banner', false);
});
