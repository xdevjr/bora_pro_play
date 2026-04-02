<?php

namespace App\Actions;

use App\Models\Participant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DrawTeamsAction
{
    /**
     * Generate balanced volleyball teams for the current room state.
     *
     * @param  Collection<int, Participant>  $participants
     * @param  array<int, int|string>  $guaranteedParticipantIds
     * @param  array<int, int|string>  $lastDrawParticipantIds
     * @return array<string, mixed>
     */
    public function handle(
        Collection $participants,
        int $teamsCount,
        int $teamSize,
        array $guaranteedParticipantIds = [],
        array $lastDrawParticipantIds = [],
        bool $excludeLastDrawParticipants = false,
    ): array {
        $activeParticipants = $participants
            ->where('is_active', true)
            ->keyBy('id');

        $capacity = $teamsCount * $teamSize;

        if ($activeParticipants->count() < $capacity) {
            throw ValidationException::withMessages([
                'teams_count' => 'Nao ha participantes ativos suficientes para completar todos os times.',
            ]);
        }

        $guaranteedIds = collect($guaranteedParticipantIds)
            ->map(fn($id): int => (int) $id)
            ->unique()
            ->values();

        if ($guaranteedIds->count() > $capacity) {
            throw ValidationException::withMessages([
                'guaranteed_participant_ids' => 'A quantidade de participantes garantidos excede o total de vagas do sorteio.',
            ]);
        }

        if ($guaranteedIds->diff($activeParticipants->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'guaranteed_participant_ids' => 'Somente participantes ativos da sala podem ser garantidos.',
            ]);
        }

        $lastDrawIds = collect($lastDrawParticipantIds)
            ->map(fn($id): int => (int) $id)
            ->unique()
            ->values();

        $guaranteedParticipants = $activeParticipants
            ->only($guaranteedIds->all())
            ->shuffle()
            ->values();

        $remainingParticipants = $activeParticipants
            ->except($guaranteedIds->all())
            ->values();

        $primaryPool = $remainingParticipants;
        $fallbackPool = collect();

        if ($excludeLastDrawParticipants) {
            $fallbackPool = $remainingParticipants
                ->filter(fn(Participant $participant): bool => $lastDrawIds->contains($participant->id))
                ->values();

            $primaryPool = $remainingParticipants
                ->reject(fn(Participant $participant): bool => $lastDrawIds->contains($participant->id))
                ->values();
        }

        $slotsAfterGuaranteed = $capacity - $guaranteedParticipants->count();

        $primarySelected = $primaryPool
            ->shuffle()
            ->take($slotsAfterGuaranteed)
            ->values();

        $missingSlots = $slotsAfterGuaranteed - $primarySelected->count();

        $fallbackSelected = $fallbackPool
            ->shuffle()
            ->take($missingSlots)
            ->values();

        $teams = collect(range(1, $teamsCount))
            ->map(fn(int $position): array => [
                'name' => "Time {$position}",
                'members' => [],
                'count' => 0,
            ])
            ->all();

        foreach ($guaranteedParticipants as $participant) {
            $this->assignParticipant(
                teams: $teams,
                teamSize: $teamSize,
                participant: $participant,
                guaranteed: true,
                fromLastDrawFallback: false,
            );
        }

        $fallbackIds = $fallbackSelected
            ->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->all();

        foreach ($primarySelected->concat($fallbackSelected)->shuffle()->values() as $participant) {
            $this->assignParticipant(
                teams: $teams,
                teamSize: $teamSize,
                participant: $participant,
                guaranteed: false,
                fromLastDrawFallback: in_array($participant->id, $fallbackIds, true),
            );
        }

        $drawnIds = collect($teams)
            ->flatMap(fn(array $team): Collection => collect($team['members'])->pluck('id'))
            ->map(fn($id): int => (int) $id)
            ->values();

        $bench = $activeParticipants
            ->except($drawnIds->all())
            ->values()
            ->map(fn(Participant $participant): array => $this->formatParticipant($participant))
            ->all();

        return [
            'teams' => collect($teams)
                ->map(fn(array $team): array => [
                    'name' => $team['name'],
                    'members' => $team['members'],
                    'count' => count($team['members']),
                ])
                ->all(),
            'bench' => $bench,
            'meta' => [
                'capacity' => $capacity,
                'active_count' => $activeParticipants->count(),
                'drawn_ids' => $drawnIds->all(),
                'guaranteed_ids' => $guaranteedIds->all(),
                'fallback_ids' => $fallbackIds,
                'bench_ids' => collect($bench)->pluck('id')->map(fn($id): int => (int) $id)->all(),
                'last_draw_participant_ids' => $lastDrawIds->all(),
                'excluded_last_draw_participants' => $excludeLastDrawParticipants,
            ],
        ];
    }

    /**
     * Assign a participant to the least populated team with available space.
     *
     * @param  array<int, array{name: string, members: array<int, array<string, mixed>>, count: int}>  $teams
     */
    private function assignParticipant(
        array &$teams,
        int $teamSize,
        Participant $participant,
        bool $guaranteed,
        bool $fromLastDrawFallback,
    ): void {
        $availableTeamIndexes = collect($teams)
            ->filter(fn(array $team): bool => $team['count'] < $teamSize)
            ->keys();

        if ($availableTeamIndexes->isEmpty()) {
            return;
        }

        $smallestTeamSize = $availableTeamIndexes
            ->map(fn(int $index): int => $teams[$index]['count'])
            ->min();

        $selectedIndex = (int) $availableTeamIndexes
            ->filter(fn(int $index): bool => $teams[$index]['count'] === $smallestTeamSize)
            ->shuffle()
            ->first();

        $teams[$selectedIndex]['members'][] = $this->formatParticipant(
            participant: $participant,
            guaranteed: $guaranteed,
            fromLastDrawFallback: $fromLastDrawFallback,
        );
        $teams[$selectedIndex]['count']++;
    }

    /**
     * Convert a participant model into a lightweight draw payload item.
     *
     * @return array{id: int, name: string, guaranteed: bool, from_last_draw_fallback: bool}
     */
    private function formatParticipant(
        Participant $participant,
        bool $guaranteed = false,
        bool $fromLastDrawFallback = false,
    ): array {
        return [
            'id' => $participant->id,
            'name' => $participant->name,
            'guaranteed' => $guaranteed,
            'from_last_draw_fallback' => $fromLastDrawFallback,
        ];
    }
}
