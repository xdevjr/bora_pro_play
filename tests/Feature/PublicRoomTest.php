<?php

use App\Models\Room;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('public home page is displayed', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Bora Pro Play')
        ->assertSee('wire:snapshot', false)
        ->assertSee('data-public-theme="dark"', false)
        ->assertSee('class="public-theme dark min-h-screen bg-zinc-950 text-zinc-50 antialiased"', false)
        ->assertSee('data-public-shell', false)
        ->assertSee('ui-toast-group', false)
        ->assertSee('ui-toast', false)
        ->assertSee('wire:submit="createRoom"', false)
        ->assertSee('wire:submit="enterRoom"', false)
        ->assertDontSee('data-spa-form', false);
});

test('room can be created and current device receives edit access', function () {
    Livewire::test('pages::home')
        ->set('createRoomName', 'Volei de quinta')
        ->set('createRoomEditorPin', '1234')
        ->set('createRoomEditorPinConfirmation', '1234')
        ->call('createRoom');

    $room = Room::query()->first();

    expect($room)->not->toBeNull();

    expect(session('editable_rooms'))->toContain($room->id);
    expect(session('flux_toast.slots.heading'))->toBe('Sala criada')
        ->and(session('flux_toast.slots.text'))->toBe('O PIN deste aparelho ja esta liberado para edicao.')
        ->and(session('flux_toast.dataset.variant'))->toBe('success');

    $this->followingRedirects()
        ->get(route('rooms.show', $room))
        ->assertSuccessful()
        ->assertSee('Volei de quinta')
        ->assertSee('volei-de-quinta');

    expect($room->code)->toBe('volei-de-quinta');
});

test('room public code stays unique when names repeat', function () {
    $firstRoom = Room::query()->create([
        'name' => 'Volei de quinta',
        'editor_pin' => '1234',
    ]);

    $secondRoom = Room::query()->create([
        'name' => 'Volei de quinta',
        'editor_pin' => '1234',
    ]);

    expect($firstRoom->code)->toBe('volei-de-quinta')
        ->and($secondRoom->code)->toBe('volei-de-quinta-2');
});

test('room can be entered using the typed room name', function () {
    $room = Room::factory()->create([
        'name' => 'Volei de quinta',
        'code' => Str::slug('Volei de quinta'),
    ]);

    Livewire::test('pages::home')
        ->set('enterRoomCode', '  Volei de quinta  ')
        ->call('enterRoom')
        ->assertRedirect(route('rooms.show', $room));
});

test('participant mutations require edit access on the current device', function () {
    $room = Room::factory()->create();

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('participantName', 'Joao')
        ->call('addParticipant')
        ->assertStatus(403);

    expect($room->participants()->count())->toBe(0);
});

test('room can be unlocked with the correct pin', function () {
    $room = Room::factory()->create([
        'editor_pin' => '9876',
    ]);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('editorPin', '9876')
        ->call('unlockRoom')
        ->assertHasNoErrors();

    expect(session('editable_rooms'))->toContain($room->id);

    Livewire::test('pages::rooms.show', ['room' => $room])
        ->set('participantName', 'Maria')
        ->call('addParticipant')
        ->assertHasNoErrors();

    expect($room->participants()->where('name', 'Maria')->exists())->toBeTrue();
});

test('participant removal uses the custom confirmation modal attributes', function () {
    $room = Room::factory()->create();
    $participant = $room->participants()->create([
        'name' => 'Maria',
        'is_active' => true,
    ]);

    $this->withSession(['editable_rooms' => [$room->id]])
        ->get(route('rooms.show', $room))
        ->assertSuccessful()
        ->assertSee('data-confirm-title="Remover participante?"', false)
        ->assertSee('data-confirm-confirm-label="Remover"', false)
        ->assertSee('data-confirm-message="Maria sai da sala agora."', false)
        ->assertSee('data-livewire-confirm-method="removeParticipant"', false)
        ->assertDontSee('return confirm(', false);

    expect($participant->exists)->toBeTrue();
});

test('room page exposes livewire participant controls and refocus target', function () {
    $room = Room::factory()->create();

    $this->withSession(['editable_rooms' => [$room->id]])
        ->get(route('rooms.show', $room))
        ->assertSuccessful()
        ->assertSee('wire:snapshot', false)
        ->assertSee('ui-toast-group', false)
        ->assertSee('wire:navigate', false)
        ->assertSee('wire:submit="addParticipant"', false)
        ->assertSee('data-participant-name-input', false)
        ->assertDontSee('data-spa-link', false)
        ->assertDontSee('data-spa-form', false)
        ->assertDontSee('status-banner', false);
});

test('room page renders the public code card with wrapping support', function () {
    $room = Room::factory()->create([
        'name' => 'Sala com nome muito longo para validar o card',
        'code' => 'sala-com-nome-publico-bem-longo-para-validar-quebra-no-card',
    ]);

    $this->get(route('rooms.show', $room))
        ->assertSuccessful()
        ->assertSee('metric-card-public', false)
        ->assertSee('public-code-value', false)
        ->assertSee('xl:grid-cols-[minmax(0,1.7fr)_repeat(3,minmax(0,1fr))]', false)
        ->assertSee('sala-com-nome-publico-bem-longo-para-validar-quebra-no-card');
});

test('room page no longer shows the automatic scoreboard toggle', function () {
    $room = Room::factory()->create();

    $this->withSession(['editable_rooms' => [$room->id]])
        ->get(route('rooms.show', $room))
        ->assertSuccessful()
        ->assertDontSee('Abrir placar automatico para 2 times');
});

test('room page renders scroll areas for player-heavy sections', function () {
    $room = Room::factory()->create();

    $response = $this->withSession(['editable_rooms' => [$room->id]])
        ->get(route('rooms.show', $room));

    $response->assertSuccessful()
        ->assertSee('scroll-area-soft', false)
        ->assertSee('max-h-[24rem]', false)
        ->assertSee('max-h-[18rem]', false)
        ->assertSee('max-h-[40rem]', false)
        ->assertSee('max-h-[26rem]', false);

    expect(substr_count($response->getContent(), 'scroll-area-soft'))->toBeGreaterThanOrEqual(4);
});

test('service worker uses network first for html spa requests', function () {
    $serviceWorker = file_get_contents(base_path('public/sw.js'));

    expect($serviceWorker)
        ->toContain("const CACHE_NAME = 'bora-pro-play-v2';")
        ->toContain("request.headers.get('X-Requested-With') === 'XMLHttpRequest'")
        ->toContain("accept.includes('text/html')")
        ->toContain("event.request.mode === 'navigate' || isSpaPageRequest(event.request)");
});

test('base stylesheet gives pointer cursor to interactive buttons', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain("button,")
        ->toContain("[type='button'],")
        ->toContain("[type='submit'],")
        ->toContain("[type='reset'],")
        ->toContain("[role='button']")
        ->toContain('cursor: pointer;')
        ->toContain('button:disabled,')
        ->toContain('cursor: not-allowed;');
});
