<?php

use App\Actions\DrawTeamsAction;
use App\Models\Draw;
use App\Models\Participant;
use App\Models\Room;
use App\Models\Scoreboard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    use \Flux\Concerns\InteractsWithComponents;

    public int $roomId;

    public string $editorPin = '';

    public string $participantName = '';

    public int $teamsCount = 2;

    public int $teamSize = 4;

    public bool $excludeLastDrawParticipants = false;

    /** @var array<int, int|string> */
    public array $guaranteedParticipantIds = [];

    public string $quickLeftTeamName = '';

    public string $quickRightTeamName = '';

    /** @var array<string, mixed>|null */
    public ?array $drawResult = null;

    public function mount(Room $room): void
    {
        $this->roomId = $room->getKey();
    }

    public function render(): View
    {
        $room = Room::query()
            ->with([
                'participants' => fn($query) => $query->orderByDesc('is_active')->orderBy('name'),
                'draws' => fn($query) => $query->with([
                    'scoreboards' => fn($scoreboards) => $scoreboards->latest(),
                ])->latest()->limit(8),
                'scoreboards' => fn($query) => $query->latest()->limit(8),
            ])
            ->findOrFail($this->roomId);

        return $this->view([
            'room' => $room,
            'isEditable' => $this->roomIsEditable($room),
            'lastDraw' => $room->draws->first(),
        ])->extends('layouts.public')
            ->title($room->name);
    }

    public function unlockRoom(): void
    {
        $room = $this->roomModel();

        $validated = $this->validateWithToast([
            'editorPin' => ['required', 'digits_between:4,8'],
        ], attributes: [
            'editorPin' => 'editor pin',
        ]);

        if (!Hash::check($validated['editorPin'], $room->getRawOriginal('editor_pin'))) {
            $this->addError('editorPin', 'PIN invalido.');
            $this->toast(
                text: 'PIN invalido.',
                heading: 'Nao foi possivel liberar este aparelho',
                duration: 5000,
                variant: 'danger',
                position: 'top end',
            );

            return;
        }

        $editableRooms = collect(session('editable_rooms', []))
            ->push($room->getKey())
            ->unique()
            ->values()
            ->all();

        session()->put('editable_rooms', $editableRooms);

        $this->reset('editorPin');
        $this->toast('Edicao liberada neste aparelho.', 'Tudo certo', 4000, 'success', 'top end');
    }

    public function addParticipant(): void
    {
        $room = $this->editableRoomModel();

        $this->participantName = Str::of($this->participantName)
            ->squish()
            ->value();

        $validated = $this->validateWithToast([
            'participantName' => ['required', 'string', 'max:80'],
        ], attributes: [
            'participantName' => 'name',
        ]);

        $room->participants()->create([
            'name' => $validated['participantName'],
        ]);

        $this->reset('participantName');
        $this->toast('Participante adicionado.', 'Tudo certo', 3500, 'success', 'top end');
        $this->dispatch('bora-focus-target', selector: "input[name='participant_name']");
    }

    public function toggleParticipant(int $participantId): void
    {
        $participant = $this->participantModel($participantId, editable: true);

        $participant->update([
            'is_active' => !$participant->is_active,
        ]);

        $this->toast(
            $participant->fresh()->is_active ? 'Participante marcado como ativo.' : 'Participante marcado como inativo.',
            'Participante atualizado',
            3000,
            'success',
            'top end',
        );
    }

    public function removeParticipant(int $participantId): void
    {
        $participant = $this->participantModel($participantId, editable: true);

        $participant->delete();

        $this->toast('Participante removido.', 'Tudo certo', 3500, 'success', 'top end');
    }

    public function runDraw(DrawTeamsAction $drawTeams): void
    {
        $room = $this->editableRoomModel();

        $validated = $this->validateWithToast([
            'teamsCount' => ['required', 'integer', 'min:2', 'max:8'],
            'teamSize' => ['required', 'integer', 'min:2', 'max:12'],
            'excludeLastDrawParticipants' => ['nullable', 'boolean'],
            'guaranteedParticipantIds' => ['nullable', 'array'],
            'guaranteedParticipantIds.*' => [
                'integer',
                'distinct',
                Rule::exists('participants', 'id')->where(
                    fn($query) => $query->where('room_id', $room->id),
                ),
            ],
        ], attributes: [
            'teamsCount' => 'teams count',
            'teamSize' => 'team size',
            'guaranteedParticipantIds' => 'guaranteed participants',
        ]);

        $previousDraw = Draw::query()
            ->whereBelongsTo($room)
            ->latest()
            ->first();

        $payload = $drawTeams->handle(
            participants: $room->participants()->get(),
            teamsCount: (int) $validated['teamsCount'],
            teamSize: (int) $validated['teamSize'],
            guaranteedParticipantIds: array_map('intval', $validated['guaranteedParticipantIds'] ?? []),
            lastDrawParticipantIds: $previousDraw?->drawnParticipantIds() ?? [],
            excludeLastDrawParticipants: (bool) ($validated['excludeLastDrawParticipants'] ?? false),
        );

        $draw = $room->draws()->create([
            'teams_count' => (int) $validated['teamsCount'],
            'team_size' => (int) $validated['teamSize'],
            'excludes_last_draw_participants' => (bool) ($validated['excludeLastDrawParticipants'] ?? false),
            'payload' => $payload,
        ]);

        $scoreboard = null;

        if (count($payload['teams']) === 2) {
            $scoreboard = $room->scoreboards()->create([
                'draw_id' => $draw->id,
                'title' => null,
                'left_team_name' => Scoreboard::defaultLeftTeamName(),
                'right_team_name' => Scoreboard::defaultRightTeamName(),
                'is_quick' => false,
                'meta' => [
                    'origin' => 'draw',
                ],
            ]);
        }

        $this->drawResult = [
            'draw_id' => $draw->id,
            'teams_count' => $draw->teams_count,
            'team_size' => $draw->team_size,
            'teams' => data_get($payload, 'teams', []),
            'bench' => data_get($payload, 'bench', []),
            'scoreboard_url' => $scoreboard ? route('rooms.scoreboards.show', [$room, $scoreboard]) : null,
        ];

        $this->guaranteedParticipantIds = [];
        $this->toast('Os times ja estao prontos para conferir.', 'Sorteio concluido', 4500, 'success', 'top end');
        $this->dispatch('bora-draw-result-opened');
    }

    public function closeDrawResult(): void
    {
        $this->drawResult = null;
        $this->dispatch('bora-draw-result-closed');
    }

    public function createQuickScoreboard(): void
    {
        $room = $this->editableRoomModel();

        $validated = $this->validatePayloadWithToast([
            'quickLeftTeamName' => $this->normalizeValidationString($this->quickLeftTeamName),
            'quickRightTeamName' => $this->normalizeValidationString($this->quickRightTeamName),
        ], [
            'quickLeftTeamName' => ['nullable', 'string', 'max:80', 'different:quickRightTeamName'],
            'quickRightTeamName' => ['nullable', 'string', 'max:80'],
        ], [], [
            'quickLeftTeamName' => 'left team name',
            'quickRightTeamName' => 'right team name',
        ]);

        $scoreboard = $room->scoreboards()->create([
            'title' => null,
            'left_team_name' => $this->normalizeOptionalString($validated['quickLeftTeamName'] ?? null, Scoreboard::defaultLeftTeamName()),
            'right_team_name' => $this->normalizeOptionalString($validated['quickRightTeamName'] ?? null, Scoreboard::defaultRightTeamName()),
            'is_quick' => true,
        ]);

        $this->reset('quickLeftTeamName', 'quickRightTeamName');
        $this->flashToast('O placar rapido ja esta pronto para jogo.', 'Placar criado', 4500, 'success');
        $this->redirectRoute('rooms.scoreboards.show', [$room, $scoreboard], navigate: true);
    }

    public function createScoreboardFromDraw(int $drawId): void
    {
        $room = $this->editableRoomModel();

        $draw = Draw::query()
            ->whereBelongsTo($room)
            ->findOrFail($drawId);

        $scoreboard = Scoreboard::query()
            ->whereBelongsTo($room)
            ->where('draw_id', $draw->id)
            ->latest()
            ->first();

        if (!$scoreboard) {
            $scoreboard = $room->scoreboards()->create([
                'draw_id' => $draw->id,
                'title' => null,
                'left_team_name' => Scoreboard::defaultLeftTeamName(),
                'right_team_name' => Scoreboard::defaultRightTeamName(),
                'is_quick' => false,
                'meta' => [
                    'origin' => 'draw',
                ],
            ]);
        }

        $this->flashToast('O placar deste sorteio foi aberto.', 'Placar criado', 4500, 'success');
        $this->redirectRoute('rooms.scoreboards.show', [$room, $scoreboard], navigate: true);
    }

    private function roomIsEditable(Room $room): bool
    {
        return in_array($room->getKey(), session()->get('editable_rooms', []), true);
    }

    private function roomModel(): Room
    {
        return Room::query()->findOrFail($this->roomId);
    }

    private function editableRoomModel(): Room
    {
        $room = $this->roomModel();

        abort_unless($this->roomIsEditable($room), 403);

        return $room;
    }

    private function participantModel(int $participantId, bool $editable = false): Participant
    {
        $room = $editable ? $this->editableRoomModel() : $this->roomModel();

        return Participant::query()
            ->whereBelongsTo($room)
            ->findOrFail($participantId);
    }

    private function normalizeOptionalString(mixed $value, ?string $default = null): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : $default;
        }

        return $value ?? $default;
    }

    private function normalizeValidationString(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function validateWithToast(array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return $this->validate($rules, $messages, $attributes);
        } catch (ValidationException $exception) {
            $this->toast(
                text: $exception->validator->errors()->first() ?: 'Confira os campos e tente novamente.',
                heading: 'Nao foi possivel concluir a acao',
                duration: 5000,
                variant: 'danger',
                position: 'top end',
            );

            throw $exception;
        }
    }

    private function validatePayloadWithToast(array $payload, array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return validator($payload, $rules, $messages, $attributes)->validate();
        } catch (ValidationException $exception) {
            $this->toast(
                text: $exception->validator->errors()->first() ?: 'Confira os campos e tente novamente.',
                heading: 'Nao foi possivel concluir a acao',
                duration: 5000,
                variant: 'danger',
                position: 'top end',
            );

            throw $exception;
        }
    }

    private function flashToast(
        string $text,
        ?string $heading = null,
        int $duration = 5000,
        ?string $variant = null,
        ?string $position = 'top end',
    ): void {
        $payload = [
            'duration' => $duration,
            'slots' => [
                'text' => $text,
            ],
            'dataset' => [],
        ];

        if ($heading) {
            $payload['slots']['heading'] = $heading;
        }

        if ($variant) {
            $payload['dataset']['variant'] = $variant;
        }

        if ($position) {
            $payload['dataset']['position'] = $position;
        }

        session()->flash('flux_toast', $payload);
    }
};
?>
<?php ($lastDrawnIds = collect(data_get($lastDraw?->payload, 'meta.drawn_ids', []))); ?>
<?php ($drawResultTeams = collect(data_get($drawResult, 'teams', []))); ?>
<?php ($drawResultBench = collect(data_get($drawResult, 'bench', []))); ?>

<div class="relative z-10 mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8 lg:py-7"
    data-room-page>
    <header class="glass-panel room-hero">
        <div class="room-hero-grid">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?php echo e(route('home')); ?>" class="glass-chip glass-chip-soft" wire:navigate>Nova
                        sala</a>
                    <button type="button" class="glass-chip cursor-pointer glass-chip-cyan"
                        data-copy-text="<?php echo e($room->code); ?>" data-copy-label="Copiar nome publico">Copiar nome
                        publico</button>

                    <?php if ($isEditable): ?>
                    <span class="glass-chip glass-chip-success">Edicao liberada neste aparelho</span>
                    <?php endif; ?>
                </div>

                <div>
                    <p class="eyebrow">Sala compartilhada</p>
                    <h1 class="section-title text-4xl font-black text-white sm:text-5xl lg:text-[4rem]">
                        <?php echo e($room->name); ?></h1>
                    <p class="mt-3 max-w-3xl text-sm text-zinc-100/74 sm:text-base">
                        O nome que voce deu virou o nome publico da sala. Compartilhe esse atalho com quem vai
                        acompanhar o jogo e destrave a edicao com o PIN neste aparelho.
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.7fr)_repeat(3,minmax(0,1fr))]">
                <article class="metric-card metric-card-public min-h-0">
                    <p class="metric-label">Nome publico</p>
                    <p class="public-code-value"><?php echo e($room->code); ?></p>
                </article>

                <article class="metric-card min-h-0">
                    <p class="metric-label">Ativos</p>
                    <p class="section-title text-3xl font-black text-cyan-100">
                        <?php echo e($room->participants->where('is_active', true)->count()); ?></p>
                </article>

                <article class="metric-card min-h-0">
                    <p class="metric-label">Sorteios</p>
                    <p class="section-title text-3xl font-black text-white"><?php echo e($room->draws->count()); ?></p>
                </article>

                <article class="metric-card min-h-0">
                    <p class="metric-label">Placares</p>
                    <p class="section-title text-3xl font-black text-orange-100">
                        <?php echo e($room->scoreboards->count()); ?></p>
                </article>
            </div>
        </div>
    </header>

    <?php if ($drawResult): ?>
    <div class="draw-result-modal" data-draw-result-modal>
        <button type="button" class="draw-result-backdrop" wire:click="closeDrawResult"
            aria-label="Fechar resultado do sorteio"></button>

        <section class="draw-result-panel glass-panel">
            <div class="draw-result-header">
                <div>
                    <p class="eyebrow">Sorteio concluido</p>
                    <h2 class="section-title text-3xl font-black text-white sm:text-4xl">
                        <?php    echo e(data_get($drawResult, 'teams_count')); ?> times ·
                        <?php    echo e(data_get($drawResult, 'team_size')); ?> por time
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-zinc-100/72">
                        Confira os times antes de seguir. O resultado tambem ficou salvo no historico logo abaixo.
                    </p>
                </div>

                <button type="button" class="glass-chip glass-chip-soft cursor-pointer" wire:click="closeDrawResult">
                    Fechar
                </button>
            </div>

            <div class="draw-result-grid scroll-area-soft">
                <?php    $__currentLoopData = $drawResultTeams;
    $__env->addLoop($__currentLoopData);
    foreach ($__currentLoopData as $team):
        $__env->incrementLoopIndices();
        $loop = $__env->getLastLoop(); ?>
                <article class="draw-result-team list-card">
                    <div class="draw-result-team-header">
                        <p class="section-title text-lg font-bold text-white"><?php        echo e($team['name']); ?></p>
                        <span class="glass-chip px-2.5 py-1 text-xs"><?php        echo e(count($team['members'])); ?>
                            jogadores</span>
                    </div>

                    <div class="scroll-area-soft mt-3 grid max-h-44 gap-2 overflow-y-auto pr-1">
                        <?php        $__currentLoopData = $team['members'];
        $__env->addLoop($__currentLoopData);
        foreach ($__currentLoopData as $member):
            $__env->incrementLoopIndices();
            $loop = $__env->getLastLoop(); ?>
                        <div class="draw-result-member">
                            <span><?php            echo e($member['name']); ?></span>

                            <div class="flex flex-wrap gap-2 text-[11px] text-zinc-200/68">
                                <?php            if ($member['guaranteed']): ?>
                                <span class="glass-chip px-2 py-1">Garantido</span>
                                <?php            endif; ?>

                                <?php            if ($member['from_last_draw_fallback']): ?>
                                <span class="glass-chip px-2 py-1">Voltou do ultimo sorteio</span>
                                <?php            endif; ?>
                            </div>
                        </div>
                        <?php        endforeach;
        $__env->popLoop();
        $loop = $__env->getLastLoop(); ?>
                    </div>
                </article>
                <?php    endforeach;
    $__env->popLoop();
    $loop = $__env->getLastLoop(); ?>
            </div>

            <?php    if ($drawResultBench->isNotEmpty()): ?>
            <div class="draw-result-bench">
                <p class="section-title text-base font-bold text-white">Banco / reserva</p>

                <div class="scroll-area-soft mt-3 flex max-h-28 flex-wrap content-start gap-2 overflow-y-auto pr-1">
                    <?php        $__currentLoopData = $drawResultBench;
        $__env->addLoop($__currentLoopData);
        foreach ($__currentLoopData as $member):
            $__env->incrementLoopIndices();
            $loop = $__env->getLastLoop(); ?>
                    <span class="glass-chip"><?php            echo e($member['name']); ?></span>
                    <?php        endforeach;
        $__env->popLoop();
        $loop = $__env->getLastLoop(); ?>
                </div>
            </div>
            <?php    endif; ?>

            <div class="draw-result-actions">
                <button type="button" class="glass-chip glass-chip-soft cursor-pointer" wire:click="closeDrawResult">
                    Continuar na sala
                </button>

                <?php    if (data_get($drawResult, 'scoreboard_url')): ?>
                <a href="<?php        echo e(data_get($drawResult, 'scoreboard_url')); ?>" class="glass-chip glass-chip-cyan"
                    wire:navigate data-draw-result-primary>
                    Ir para o placar
                </a>
                <?php    else: ?>
                <span class="draw-result-note">
                    O placar automatico aparece quando o sorteio fecha exatamente 2 times.
                </span>
                <?php    endif; ?>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <?php if (!($isEditable)): ?>
    <section class="glass-panel p-5 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="eyebrow">PIN deste aparelho</p>
                <h2 class="section-title text-2xl font-black text-white sm:text-3xl">Desbloquear edicao</h2>
                <p class="mt-2 text-sm text-zinc-200/75">
                    Este aparelho pode acompanhar a sala, mas ainda precisa do PIN para cadastrar participantes, sortear
                    e mexer no placar.
                </p>
            </div>

            <form wire:submit="unlockRoom" class="flex w-full max-w-md flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <?php    if (isset($component)) {
        $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
    } ?>
                    <?php    if (isset($attributes)) {
        $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
    } ?>
                    <?php    $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'editorPin', 'name' => 'editor_pin', 'label' => 'PIN de edicao', 'type' => 'password', 'inputmode' => 'numeric', 'maxlength' => '8', 'placeholder' => '1234', 'required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                    <?php    $component->withName('flux::input'); ?>
                    <?php    if ($component->shouldRender()): ?>
                    <?php        $__env->startComponent($component->resolveView(), $component->data()); ?>
                    <?php        if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                    <?php            $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                    <?php        endif; ?>
                    <?php        $component->withAttributes(['wire:model' => 'editorPin', 'name' => 'editor_pin', 'label' => 'PIN de edicao', 'type' => 'password', 'inputmode' => 'numeric', 'maxlength' => '8', 'placeholder' => '1234', 'required' => true]); ?>
                    <?php        \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                    <?php        echo $__env->renderComponent(); ?>
                    <?php    endif; ?>
                    <?php    if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                    <?php        $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                    <?php        unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                    <?php    endif; ?>
                    <?php    if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                    <?php        $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                    <?php        unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                    <?php    endif; ?>
                </div>

                <?php    if (isset($component)) {
        $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component;
    } ?>
                <?php    if (isset($attributes)) {
        $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes;
    } ?>
                <?php    $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index', 'data' => ['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                <?php    $component->withName('flux::button'); ?>
                <?php    if ($component->shouldRender()): ?>
                <?php        $__env->startComponent($component->resolveView(), $component->data()); ?>
                <?php        if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                <?php            $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                <?php        endif; ?>
                <?php        $component->withAttributes(['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']); ?>
                <?php        \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                Liberar
                <?php        echo $__env->renderComponent(); ?>
                <?php    endif; ?>
                <?php    if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                <?php        $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                <?php        unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                <?php    endif; ?>
                <?php    if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                <?php        $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                <?php        unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                <?php    endif; ?>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <div class="surface-grid">
        <div class="grid gap-6">
            <section class="glass-panel p-5 sm:p-6 lg:p-7">
                <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div class="space-y-2">
                        <p class="eyebrow">Elenco da sala</p>
                        <h2 class="section-title text-3xl font-black text-white">Participantes prontos para o proximo
                            sorteio</h2>
                        <p class="max-w-2xl text-sm text-zinc-100/72">Ative ou pause nomes sem recarregar o fluxo mental
                            de quem esta organizando a rodada.</p>
                    </div>

                    <div class="grid w-full gap-3 sm:max-w-sm sm:grid-cols-2">
                        <article class="metric-card min-h-0">
                            <p class="metric-label">Cadastrados</p>
                            <p class="section-title text-3xl font-black text-white">
                                <?php echo e($room->participants->count()); ?></p>
                        </article>

                        <article class="metric-card min-h-0">
                            <p class="metric-label">Ativos</p>
                            <p class="section-title text-3xl font-black text-cyan-100">
                                <?php echo e($room->participants->where('is_active', true)->count()); ?></p>
                        </article>
                    </div>
                </div>

                <form wire:submit="addParticipant" class="mb-5">
                    <fieldset class="flex flex-col gap-3 sm:flex-row sm:items-end" <?php if (!$isEditable):
    echo 'disabled';
endif; ?>>
                        <div class="flex-1">
                            <?php if (isset($component)) {
    $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
} ?>
                            <?php if (isset($attributes)) {
    $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
} ?>
                            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'participantName', 'name' => 'participant_name', 'label' => 'Novo participante', 'placeholder' => 'Nome do jogador', 'required' => true, 'dataParticipantNameInput' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php $component->withName('flux::input'); ?>
                            <?php if ($component->shouldRender()): ?>
                            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php    endif; ?>
                            <?php    $component->withAttributes(['wire:model' => 'participantName', 'name' => 'participant_name', 'label' => 'Novo participante', 'placeholder' => 'Nome do jogador', 'required' => true, 'data-participant-name-input' => true]); ?>
                            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <?php    echo $__env->renderComponent(); ?>
                            <?php endif; ?>
                            <?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($component)) {
    $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component;
} ?>
                        <?php if (isset($attributes)) {
    $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes;
} ?>
                        <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index', 'data' => ['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                        <?php $component->withName('flux::button'); ?>
                        <?php if ($component->shouldRender()): ?>
                        <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                        <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                        <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                        <?php    endif; ?>
                        <?php    $component->withAttributes(['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']); ?>
                        <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        Adicionar
                        <?php    echo $__env->renderComponent(); ?>
                        <?php endif; ?>
                        <?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                        <?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                    </fieldset>
                </form>

                <div class="scroll-area-soft grid max-h-[24rem] gap-3 overflow-y-auto pr-1 sm:pr-2">
                    <?php $__empty_1 = true;
$__currentLoopData = $room->participants;
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $participant):
    $__env->incrementLoopIndices();
    $loop = $__env->getLastLoop();
    $__empty_1 = false; ?>
                    <div class="participant-card" <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'participant-' . e($participant->id) . ''; ?>wire:key="participant-<?php    echo e($participant->id); ?>">
                        <div class="space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-white"><?php    echo e($participant->name); ?></p>

                                <?php    if ($lastDrawnIds->contains($participant->id)): ?>
                                <span class="glass-chip px-2 py-1 text-xs">Saiu no ultimo sorteio</span>
                                <?php    endif; ?>
                            </div>

                            <p class="text-sm text-zinc-100/68">
                                <?php    echo e($participant->is_active ? 'Participante ativo na proxima rodada.' : 'Fora do sorteio ate ser reativado.'); ?>
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="toggleParticipant(<?php    echo e($participant->id); ?>)"
                                class="glass-chip cursor-pointer <?php    echo e($participant->is_active ? 'glass-chip-success' : 'glass-chip-soft'); ?>"
                                <?php    if (!$isEditable):
        echo 'disabled';
    endif; ?>>
                                <?php    echo e($participant->is_active ? 'Ativo' : 'Inativo'); ?>
                            </button>

                            <button type="button" class="glass-chip cursor-pointer border-rose-300/30 text-rose-100"
                                <?php    if (!$isEditable):
        echo 'disabled';
    endif; ?>
                                data-confirm-title="Remover participante?"
                                data-confirm-message="<?php    echo e($participant->name); ?> sai da sala agora."
                                data-confirm-confirm-label="Remover" data-confirm-tone="danger"
                                data-livewire-confirm-method="removeParticipant"
                                data-livewire-confirm-args='<?php    echo json_encode([$participant->id], 15, 512) ?>'>
                                Remover
                            </button>
                        </div>
                    </div>
                    <?php endforeach;
$__env->popLoop();
$loop = $__env->getLastLoop();
if ($__empty_1): ?>
                    <div class="glass-panel p-5 text-sm text-zinc-200/70">
                        Nenhum participante cadastrado ainda.
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="glass-panel p-5 sm:p-6 lg:p-7">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="eyebrow">Memoria recente</p>
                        <h2 class="section-title text-3xl font-black text-white">Historico de sorteios</h2>
                        <p class="mt-1 text-sm text-zinc-200/70">Os resultados mais recentes ficam salvos para consulta,
                            repeticao do fluxo e abertura de placar.</p>
                    </div>
                    <span class="glass-chip"><?php echo e($room->draws->count()); ?> sorteios</span>
                </div>

                <div class="scroll-area-soft grid max-h-[40rem] gap-4 overflow-y-auto pr-1 sm:pr-2">
                    <?php $__empty_1 = true;
$__currentLoopData = $room->draws;
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $draw):
    $__env->incrementLoopIndices();
    $loop = $__env->getLastLoop();
    $__empty_1 = false; ?>
                    <?php    ($teams = data_get($draw->payload, 'teams', [])); ?>
                    <?php    ($bench = data_get($draw->payload, 'bench', [])); ?>
                    <?php    ($linkedScoreboard = $draw->scoreboards->first()); ?>

                    <details class="glass-panel p-4" <?php    if ($loop->first): ?> open <?php    endif; ?> <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'draw-' . e($draw->id) . ''; ?>wire:key="draw-<?php    echo e($draw->id); ?>">
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-semibold text-white"><?php    echo e($draw->teams_count); ?> times ·
                                        <?php    echo e($draw->team_size); ?> por time</p>
                                    <p class="text-sm text-zinc-200/65">
                                        <?php    echo e($draw->created_at->format('d/m/Y H:i')); ?></p>
                                </div>

                                <div class="flex flex-wrap gap-2 text-xs text-zinc-200/70">
                                    <?php    if ($draw->excludes_last_draw_participants): ?>
                                    <span class="glass-chip">Filtro do ultimo sorteio ativo</span>
                                    <?php    endif; ?>

                                    <?php    if (count(data_get($draw->payload, 'meta.fallback_ids', [])) > 0): ?>
                                    <span class="glass-chip">Teve preenchimento por fallback</span>
                                    <?php    endif; ?>
                                </div>
                            </div>
                        </summary>

                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <?php    $__currentLoopData = $teams;
    $__env->addLoop($__currentLoopData);
    foreach ($__currentLoopData as $team):
        $__env->incrementLoopIndices();
        $loop = $__env->getLastLoop(); ?>
                            <article class="glass-panel flex h-full flex-col p-4">
                                <p class="section-title text-lg font-bold text-white"><?php        echo e($team['name']); ?>
                                </p>

                                <div class="scroll-area-soft mt-3 grid max-h-56 gap-2 overflow-y-auto pr-1">
                                    <?php        $__currentLoopData = $team['members'];
        $__env->addLoop($__currentLoopData);
        foreach ($__currentLoopData as $member):
            $__env->incrementLoopIndices();
            $loop = $__env->getLastLoop(); ?>
                                    <div class="list-card px-3 py-2 text-sm text-zinc-100">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span><?php            echo e($member['name']); ?></span>

                                            <div class="flex flex-wrap gap-2 text-[11px] text-zinc-200/70">
                                                <?php            if ($member['guaranteed']): ?>
                                                <span class="glass-chip px-2 py-1">Garantido</span>
                                                <?php            endif; ?>

                                                <?php            if ($member['from_last_draw_fallback']): ?>
                                                <span class="glass-chip px-2 py-1">Voltou do ultimo sorteio</span>
                                                <?php            endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php        endforeach;
        $__env->popLoop();
        $loop = $__env->getLastLoop(); ?>
                                </div>
                            </article>
                            <?php    endforeach;
    $__env->popLoop();
    $loop = $__env->getLastLoop(); ?>
                        </div>

                        <?php    if (count($bench) > 0): ?>
                        <div class="mt-4 rounded-[1.6rem] border border-white/10 bg-white/6 p-4">
                            <p class="section-title text-base font-bold text-white">Banco / reserva</p>
                            <div
                                class="scroll-area-soft mt-3 flex max-h-32 flex-wrap content-start gap-2 overflow-y-auto pr-1">
                                <?php        $__currentLoopData = $bench;
        $__env->addLoop($__currentLoopData);
        foreach ($__currentLoopData as $member):
            $__env->incrementLoopIndices();
            $loop = $__env->getLastLoop(); ?>
                                <span class="glass-chip"><?php            echo e($member['name']); ?></span>
                                <?php        endforeach;
        $__env->popLoop();
        $loop = $__env->getLastLoop(); ?>
                            </div>
                        </div>
                        <?php    endif; ?>

                        <?php    if (count($teams) === 2): ?>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <?php        if ($linkedScoreboard): ?>
                            <a href="<?php            echo e(route('rooms.scoreboards.show', [$room, $linkedScoreboard])); ?>"
                                class="glass-chip glass-chip-cyan" wire:navigate>
                                Abrir placar deste sorteio
                            </a>
                            <?php        elseif ($isEditable): ?>
                            <?php            if (isset($component)) {
                $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component;
            } ?>
                            <?php            if (isset($attributes)) {
                $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes;
            } ?>
                            <?php            $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index', 'data' => ['variant' => 'primary', 'type' => 'button', 'wire:click' => 'createScoreboardFromDraw(' . e($draw->id) . ')', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php            $component->withName('flux::button'); ?>
                            <?php            if ($component->shouldRender()): ?>
                            <?php                $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php                if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php                    $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php                endif; ?>
                            <?php                $component->withAttributes(['variant' => 'primary', 'type' => 'button', 'wire:click' => 'createScoreboardFromDraw(' . e($draw->id) . ')', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20 sm:w-auto']); ?>
                            <?php                \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            Criar placar deste sorteio
                            <?php                echo $__env->renderComponent(); ?>
                            <?php            endif; ?>
                            <?php            if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                            <?php                $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                            <?php                unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                            <?php            endif; ?>
                            <?php            if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                            <?php                $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                            <?php                unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                            <?php            endif; ?>
                            <?php        endif; ?>
                        </div>
                        <?php    endif; ?>
                    </details>
                    <?php endforeach;
$__env->popLoop();
$loop = $__env->getLastLoop();
if ($__empty_1): ?>
                    <div class="glass-panel p-5 text-sm text-zinc-200/70">
                        Nenhum sorteio registrado ainda.
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="grid gap-6 xl:sticky xl:top-6 xl:self-start">
            <section class="glass-panel p-5 sm:p-6 lg:p-7">
                <div class="mb-5">
                    <p class="eyebrow">Motor de sorteio</p>
                    <h2 class="section-title text-3xl font-black text-white">Monte a rodada</h2>
                    <p class="mt-1 text-sm text-zinc-200/70">
                        Os garantidos entram primeiro. O filtro do ultimo sorteio vale na primeira passada e so volta a
                        usar esse grupo se faltarem vagas.
                    </p>
                </div>

                <form wire:submit="runDraw">
                    <fieldset class="space-y-5" <?php if (!$isEditable):
    echo 'disabled';
endif; ?>>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <?php if (isset($component)) {
    $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
} ?>
                            <?php if (isset($attributes)) {
    $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
} ?>
                            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'teamsCount', 'name' => 'teams_count', 'type' => 'number', 'min' => '2', 'max' => '8', 'label' => 'Numero de times', 'required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php $component->withName('flux::input'); ?>
                            <?php if ($component->shouldRender()): ?>
                            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php    endif; ?>
                            <?php    $component->withAttributes(['wire:model' => 'teamsCount', 'name' => 'teams_count', 'type' => 'number', 'min' => '2', 'max' => '8', 'label' => 'Numero de times', 'required' => true]); ?>
                            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <?php    echo $__env->renderComponent(); ?>
                            <?php endif; ?>
                            <?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($component)) {
    $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
} ?>
                            <?php if (isset($attributes)) {
    $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
} ?>
                            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'teamSize', 'name' => 'team_size', 'type' => 'number', 'min' => '2', 'max' => '12', 'label' => 'Jogadores por time', 'required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php $component->withName('flux::input'); ?>
                            <?php if ($component->shouldRender()): ?>
                            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php    endif; ?>
                            <?php    $component->withAttributes(['wire:model' => 'teamSize', 'name' => 'team_size', 'type' => 'number', 'min' => '2', 'max' => '12', 'label' => 'Jogadores por time', 'required' => true]); ?>
                            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <?php    echo $__env->renderComponent(); ?>
                            <?php endif; ?>
                            <?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                        </div>

                        <div class="grid gap-3">
                            <label class="selection-card flex items-start gap-3 text-sm text-zinc-100">
                                <input wire:model="excludeLastDrawParticipants" type="checkbox"
                                    name="exclude_last_draw_participants" value="1"
                                    class="mt-1 size-4 rounded border-white/20 bg-white/10">

                                <span>
                                    <span class="block font-semibold text-white">Nao considerar quem saiu no ultimo
                                        sorteio</span>
                                    <span class="mt-1 block text-zinc-200/70">O filtro vale na primeira passada e o
                                        sistema so volta a esse grupo se faltar vaga.</span>
                                </span>
                            </label>
                        </div>

                        <div class="space-y-3">
                            <p class="section-title text-lg font-bold text-white">Garantidos desta rodada</p>

                            <div
                                class="scroll-area-soft grid max-h-[18rem] gap-3 overflow-y-auto pr-1 sm:grid-cols-2 sm:pr-2">
                                <?php $__empty_1 = true;
$__currentLoopData = $room->participants->where('is_active', true);
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $participant):
    $__env->incrementLoopIndices();
    $loop = $__env->getLastLoop();
    $__empty_1 = false; ?>
                                <label class="selection-card flex items-start gap-3 p-4 text-sm text-zinc-100" <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'guaranteed-' . e($participant->id) . ''; ?>wire:key="guaranteed-<?php    echo e($participant->id); ?>">
                                    <input wire:model="guaranteedParticipantIds" type="checkbox"
                                        name="guaranteed_participant_ids[]" value="<?php    echo e($participant->id); ?>"
                                        class="mt-1 size-4 rounded border-white/20 bg-white/10">

                                    <span>
                                        <span
                                            class="font-semibold text-white"><?php    echo e($participant->name); ?></span>

                                        <?php    if ($lastDrawnIds->contains($participant->id)): ?>
                                        <span class="mt-1 block text-xs text-zinc-300/60">Saiu no ultimo sorteio</span>
                                        <?php    endif; ?>
                                    </span>
                                </label>
                                <?php endforeach;
$__env->popLoop();
$loop = $__env->getLastLoop();
if ($__empty_1): ?>
                                <div class="glass-panel p-4 text-sm text-zinc-200/70">
                                    Ative participantes para liberar o sorteio.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (isset($component)) {
    $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component;
} ?>
                        <?php if (isset($attributes)) {
    $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes;
} ?>
                        <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index', 'data' => ['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-orange-300 !text-slate-950 shadow-lg shadow-orange-500/20']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                        <?php $component->withName('flux::button'); ?>
                        <?php if ($component->shouldRender()): ?>
                        <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                        <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                        <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                        <?php    endif; ?>
                        <?php    $component->withAttributes(['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-orange-300 !text-slate-950 shadow-lg shadow-orange-500/20']); ?>
                        <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        Sortear agora
                        <?php    echo $__env->renderComponent(); ?>
                        <?php endif; ?>
                        <?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                        <?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                    </fieldset>
                </form>
            </section>

            <section class="glass-panel p-5 sm:p-6 lg:p-7">
                <div class="mb-5">
                    <p class="eyebrow">Modo rapido</p>
                    <h2 class="section-title text-3xl font-black text-white">Placar rapido</h2>
                    <p class="mt-1 text-sm text-zinc-200/70">Crie um placar avulso sem definir titulo. Ele abre como
                        Azul x Laranja e voce so coloca apelidos se quiser.</p>
                </div>

                <form wire:submit="createQuickScoreboard">
                    <fieldset class="space-y-5" <?php if (!$isEditable):
    echo 'disabled';
endif; ?>>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <?php if (isset($component)) {
    $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
} ?>
                            <?php if (isset($attributes)) {
    $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
} ?>
                            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'quickLeftTeamName', 'name' => 'left_team_name', 'label' => 'Apelido opcional do azul', 'placeholder' => '' . e(\App\Models\Scoreboard::defaultLeftTeamName()) . '']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php $component->withName('flux::input'); ?>
                            <?php if ($component->shouldRender()): ?>
                            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php    endif; ?>
                            <?php    $component->withAttributes(['wire:model' => 'quickLeftTeamName', 'name' => 'left_team_name', 'label' => 'Apelido opcional do azul', 'placeholder' => '' . e(\App\Models\Scoreboard::defaultLeftTeamName()) . '']); ?>
                            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <?php    echo $__env->renderComponent(); ?>
                            <?php endif; ?>
                            <?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>

                            <?php if (isset($component)) {
    $__componentOriginal26c546557cdc09040c8dd00b2090afd0 = $component;
} ?>
                            <?php if (isset($attributes)) {
    $__attributesOriginal26c546557cdc09040c8dd00b2090afd0 = $attributes;
} ?>
                            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::input.index', 'data' => ['wire:model' => 'quickRightTeamName', 'name' => 'right_team_name', 'label' => 'Apelido opcional do laranja', 'placeholder' => '' . e(\App\Models\Scoreboard::defaultRightTeamName()) . '']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php $component->withName('flux::input'); ?>
                            <?php if ($component->shouldRender()): ?>
                            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php    endif; ?>
                            <?php    $component->withAttributes(['wire:model' => 'quickRightTeamName', 'name' => 'right_team_name', 'label' => 'Apelido opcional do laranja', 'placeholder' => '' . e(\App\Models\Scoreboard::defaultRightTeamName()) . '']); ?>
                            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <?php    echo $__env->renderComponent(); ?>
                            <?php endif; ?>
                            <?php if (isset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $attributes = $__attributesOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__attributesOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                            <?php if (isset($__componentOriginal26c546557cdc09040c8dd00b2090afd0)): ?>
                            <?php    $component = $__componentOriginal26c546557cdc09040c8dd00b2090afd0; ?>
                            <?php    unset($__componentOriginal26c546557cdc09040c8dd00b2090afd0); ?>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($component)) {
    $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component;
} ?>
                        <?php if (isset($attributes)) {
    $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes;
} ?>
                        <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index', 'data' => ['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                        <?php $component->withName('flux::button'); ?>
                        <?php if ($component->shouldRender()): ?>
                        <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
                        <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                        <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                        <?php    endif; ?>
                        <?php    $component->withAttributes(['variant' => 'primary', 'type' => 'submit', 'class' => 'w-full justify-center rounded-full !bg-cyan-300 !text-slate-950 shadow-lg shadow-cyan-500/20']); ?>
                        <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        Criar placar
                        <?php    echo $__env->renderComponent(); ?>
                        <?php endif; ?>
                        <?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                        <?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
                        <?php    $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
                        <?php    unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
                        <?php endif; ?>
                    </fieldset>
                </form>
            </section>

            <section class="glass-panel p-5 sm:p-6 lg:p-7">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="eyebrow">Acesso rapido</p>
                        <h2 class="section-title text-3xl font-black text-white">Placares salvos</h2>
                        <p class="mt-1 text-sm text-zinc-200/70">Os ultimos placares ficam prontos para reabrir em
                            qualquer aparelho.</p>
                    </div>
                    <span class="glass-chip"><?php echo e($room->scoreboards->count()); ?> placares</span>
                </div>

                <div class="scroll-area-soft grid max-h-[26rem] gap-3 overflow-y-auto pr-1 sm:pr-2">
                    <?php $__empty_1 = true;
$__currentLoopData = $room->scoreboards;
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $scoreboard):
    $__env->incrementLoopIndices();
    $loop = $__env->getLastLoop();
    $__empty_1 = false; ?>
                    <a href="<?php    echo e(route('rooms.scoreboards.show', [$room, $scoreboard])); ?>"
                        class="glass-panel block p-4 transition-transform duration-200 hover:-translate-y-0.5"
                        wire:navigate <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'scoreboard-' . e($scoreboard->id) . ''; ?>wire:key="scoreboard-<?php    echo e($scoreboard->id); ?>">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-white">
                                    <?php    echo e($scoreboard->title ?: 'Placar em andamento'); ?></p>
                                <p class="mt-1 text-sm text-zinc-200/70"><?php    echo e($scoreboard->left_team_name); ?>
                                    <?php    echo e($scoreboard->left_score); ?> ·
                                    <?php    echo e($scoreboard->right_score); ?>
                                    <?php    echo e($scoreboard->right_team_name); ?></p>
                            </div>

                            <span
                                class="glass-chip"><?php    echo e($scoreboard->is_quick ? 'Rapido' : 'Ligado ao sorteio'); ?></span>
                        </div>
                    </a>
                    <?php endforeach;
$__env->popLoop();
$loop = $__env->getLastLoop();
if ($__empty_1): ?>
                    <div class="glass-panel p-5 text-sm text-zinc-200/70">
                        Nenhum placar criado ainda.
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>