<?php

use App\Actions\DrawTeamsAction;
use App\Models\Participant;
use Illuminate\Support\Collection;

test('guaranteed participants are always present in the generated teams', function () {
    $payload = app(DrawTeamsAction::class)->handle(
        participants: fakeParticipants(8),
        teamsCount: 2,
        teamSize: 3,
        guaranteedParticipantIds: [1, 2],
    );

    $members = collect($payload['teams'])->flatMap(fn(array $team): array => $team['members']);

    expect($payload['meta']['guaranteed_ids'])->toEqualCanonicalizing([1, 2]);
    expect($members->pluck('id')->all())->toContain(1, 2);
    expect($members->where('guaranteed', true)->pluck('id')->all())
        ->toEqualCanonicalizing([1, 2]);
});

test('last draw filtering falls back to previous participants only when needed', function () {
    $payload = app(DrawTeamsAction::class)->handle(
        participants: fakeParticipants(8),
        teamsCount: 2,
        teamSize: 3,
        guaranteedParticipantIds: [],
        lastDrawParticipantIds: [1, 2, 3, 4, 5, 6],
        excludeLastDrawParticipants: true,
    );

    expect($payload['meta']['fallback_ids'])->toHaveCount(4);
    expect($payload['meta']['drawn_ids'])->toHaveCount(6);
    expect($payload['meta']['drawn_ids'])->toContain(7, 8);
});

test('guaranteed participants override the last draw exclusion rule', function () {
    $payload = app(DrawTeamsAction::class)->handle(
        participants: fakeParticipants(8),
        teamsCount: 2,
        teamSize: 3,
        guaranteedParticipantIds: [1],
        lastDrawParticipantIds: [1, 2, 3, 4, 5, 6],
        excludeLastDrawParticipants: true,
    );

    $members = collect($payload['teams'])->flatMap(fn(array $team): array => $team['members']);

    expect($members->pluck('id')->all())->toContain(1);
    expect($members->where('guaranteed', true)->pluck('id')->all())->toContain(1);
});

/**
 * @return Collection<int, Participant>
 */
function fakeParticipants(int $total): Collection
{
    return collect(range(1, $total))->map(function (int $id): Participant {
        $participant = new Participant();
        $participant->id = $id;
        $participant->name = "Participante {$id}";
        $participant->is_active = true;

        return $participant;
    });
}
