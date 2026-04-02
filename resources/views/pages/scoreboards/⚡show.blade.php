<?php

use App\Models\Room;
use App\Models\Scoreboard;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

new class extends Component {
    use \Flux\Concerns\InteractsWithComponents;

    public int $roomId;

    public int $scoreboardId;

    public string $scoreboardTitle = '';

    public string $leftTeamName = '';

    public string $rightTeamName = '';

    public bool $showMetaEditor = false;

    public function mount(Room $room, Scoreboard $scoreboard): void
    {
        abort_unless($scoreboard->room_id === $room->id, 404);

        $this->roomId = $room->getKey();
        $this->scoreboardId = $scoreboard->getKey();
        $this->fillEditorFields($scoreboard);
    }

    public function render(): View
    {
        $room = Room::query()->findOrFail($this->roomId);
        $scoreboard = Scoreboard::query()
            ->whereBelongsTo($room)
            ->findOrFail($this->scoreboardId);

        return $this->view([
            'room' => $room,
            'scoreboard' => $scoreboard,
            'isEditable' => $this->roomIsEditable($room),
        ])->extends('layouts.public')
            ->title($scoreboard->displayTitle());
    }

    public function saveScoreboardMeta(): void
    {
        $scoreboard = $this->editableScoreboardModel();
        $this->showMetaEditor = true;

        $validated = $this->validatePayloadWithToast([
            'scoreboardTitle' => $this->normalizeValidationString($this->scoreboardTitle),
            'leftTeamName' => $this->normalizeValidationString($this->leftTeamName),
            'rightTeamName' => $this->normalizeValidationString($this->rightTeamName),
        ], [
            'scoreboardTitle' => ['nullable', 'string', 'max:80'],
            'leftTeamName' => ['nullable', 'string', 'max:80', 'different:rightTeamName'],
            'rightTeamName' => ['nullable', 'string', 'max:80'],
        ], [], [
            'scoreboardTitle' => 'title',
            'leftTeamName' => 'left team name',
            'rightTeamName' => 'right team name',
        ]);

        $scoreboard->update([
            'title' => $this->normalizeOptionalString($validated['scoreboardTitle'] ?? null),
            'left_team_name' => $this->normalizeOptionalString($validated['leftTeamName'] ?? null, Scoreboard::defaultLeftTeamName()),
            'right_team_name' => $this->normalizeOptionalString($validated['rightTeamName'] ?? null, Scoreboard::defaultRightTeamName()),
        ]);

        $this->fillEditorFields($scoreboard->fresh());
        $this->showMetaEditor = false;
        $this->toast('Os nomes do placar foram salvos.', 'Placar atualizado', 3500, 'success', 'top end');
    }

    public function syncScoreboard(int $leftScore, int $rightScore): void
    {
        $scoreboard = $this->editableScoreboardModel();

        $validated = validator([
            'left_score' => $leftScore,
            'right_score' => $rightScore,
        ], [
            'left_score' => ['required', 'integer', 'min:0'],
            'right_score' => ['required', 'integer', 'min:0'],
        ])->validate();

        $scoreboard->update($validated);

        $this->skipRender();
    }

    public function resetScoreboard(): void
    {
        $this->syncScoreboard(0, 0);
        $this->toast('Os pontos voltaram para zero.', 'Placar resetado', 3500, 'success', 'top end');
    }

    public function toggleMetaEditor(): void
    {
        $this->showMetaEditor = !$this->showMetaEditor;
    }

    private function roomIsEditable(Room $room): bool
    {
        return in_array($room->getKey(), session()->get('editable_rooms', []), true);
    }

    private function roomModel(): Room
    {
        return Room::query()->findOrFail($this->roomId);
    }

    private function scoreboardModel(): Scoreboard
    {
        return Scoreboard::query()
            ->whereBelongsTo($this->roomModel())
            ->findOrFail($this->scoreboardId);
    }

    private function editableScoreboardModel(): Scoreboard
    {
        $room = $this->roomModel();

        abort_unless($this->roomIsEditable($room), 403);

        return Scoreboard::query()
            ->whereBelongsTo($room)
            ->findOrFail($this->scoreboardId);
    }

    private function fillEditorFields(Scoreboard $scoreboard): void
    {
        $this->scoreboardTitle = $scoreboard->title ?? '';
        $this->leftTeamName = $scoreboard->left_team_name === Scoreboard::defaultLeftTeamName()
            ? ''
            : $scoreboard->left_team_name;
        $this->rightTeamName = $scoreboard->right_team_name === Scoreboard::defaultRightTeamName()
            ? ''
            : $scoreboard->right_team_name;
    }

    private function normalizeOptionalString(mixed $value, ?string $default = null): mixed
    {
        if (is_string($value)) {
            $value = Str::of($value)->trim()->value();

            return $value !== '' ? $value : $default;
        }

        return $value ?? $default;
    }

    private function normalizeValidationString(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = Str::of($value)->trim()->value();

        return $value !== '' ? $value : null;
    }

    private function validatePayloadWithToast(array $payload, array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return validator($payload, $rules, $messages, $attributes)->validate();
        } catch (ValidationException $exception) {
            $this->toast(
                text: $exception->validator->errors()->first() ?: 'Confira os campos e tente novamente.',
                heading: 'Nao foi possivel atualizar o placar',
                duration: 5000,
                variant: 'danger',
                position: 'top end',
            );

            throw $exception;
        }
    }
};
?>
<?php $__env->startPush('head'); ?>
<link rel="stylesheet" href="/scoreboard-fullscreen.css?v=3" data-spa-head>
<?php $__env->stopPush(); ?>

<div class="scoreboard-screen relative z-10 mx-auto flex w-full flex-col" data-scoreboard-root
    data-scoreboard-id="<?php echo e($scoreboard->id); ?>"
    data-can-edit="<?php echo e($isEditable ? 'true' : 'false'); ?>"
    data-left-score="<?php echo e($scoreboard->left_score); ?>"
    data-right-score="<?php echo e($scoreboard->right_score); ?>">
    <div class="score-stage">
        <button type="button" class="score-panel score-panel-left" data-score-panel="left"
            data-editable="<?php echo e($isEditable ? 'true' : 'false'); ?>">
            <div class="score-panel-meta">
                <div>
                    <p class="eyebrow text-cyan-100/70">Lado esquerdo</p>
                    <span class="score-label text-cyan-50"><?php echo e($scoreboard->left_team_name); ?></span>
                </div>

                <span class="score-chip">+1 no toque</span>
            </div>

            <div class="score-value-wrap">
                <span class="score-value text-white"
                    data-score-value="left"><?php echo e($scoreboard->left_score); ?></span>
            </div>

            <div class="score-footer-line">
                <p class="score-hint">Arraste para baixo para tirar 1 ponto.</p>
                <span class="score-swipe">Swipe down</span>
            </div>
        </button>

        <button type="button" class="score-panel score-panel-right" data-score-panel="right"
            data-editable="<?php echo e($isEditable ? 'true' : 'false'); ?>">
            <div class="score-panel-meta">
                <div>
                    <p class="eyebrow text-orange-100/70">Lado direito</p>
                    <span class="score-label text-orange-50"><?php echo e($scoreboard->right_team_name); ?></span>
                </div>

                <span class="score-chip">+1 no toque</span>
            </div>

            <div class="score-value-wrap">
                <span class="score-value text-white"
                    data-score-value="right"><?php echo e($scoreboard->right_score); ?></span>
            </div>

            <div class="score-footer-line">
                <p class="score-hint">Arraste para baixo para tirar 1 ponto.</p>
                <span class="score-swipe">Swipe down</span>
            </div>
        </button>
    </div>

    <aside class="scoreboard-dock" aria-label="Controles do placar">
        <div class="scoreboard-dock-controls">
            <?php if (isset($component)) {
    $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component;
} ?>
            <?php if (isset($attributes)) {
    $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes;
} ?>
            <?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index', 'data' => ['content' => 'Voltar para a sala', 'position' => 'top']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
            <?php $component->withName('flux::tooltip'); ?>
            <?php if ($component->shouldRender()): ?>
            <?php    $__env->startComponent($component->resolveView(), $component->data()); ?>
            <?php    if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
            <?php        $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
            <?php    endif; ?>
            <?php    $component->withAttributes(['content' => 'Voltar para a sala', 'position' => 'top']); ?>
            <?php    \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

            <a href="<?php    echo e(route('rooms.show', $room)); ?>" class="scoreboard-dock-button" wire:navigate
                aria-label="Voltar para a sala">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" />
                </svg>
            </a>
            <?php    echo $__env->renderComponent(); ?>
            <?php endif; ?>
            <?php if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
            <?php    $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
            <?php    unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
            <?php endif; ?>
            <?php if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
            <?php    $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
            <?php    unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
            <?php endif; ?>

            <span
                class="scoreboard-dock-indicator <?php echo e($isEditable ? 'scoreboard-dock-indicator-live' : 'scoreboard-dock-indicator-locked'); ?>"
                data-sync-indicator data-sync-state
                title="<?php echo e($isEditable ? 'Sincronizado' : 'Edicao bloqueada neste aparelho'); ?>"
                aria-label="<?php echo e($isEditable ? 'Sincronizado' : 'Edicao bloqueada neste aparelho'); ?>">
                <?php if ($isEditable): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 6L9 17l-5-5" />
                </svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="9" rx="2" />
                    <path d="M8 11V8a4 4 0 018 0v3" />
                </svg>
                <?php endif; ?>
            </span>

            <?php if ($isEditable): ?>
            <div class="scoreboard-dock-details">
                <?php    if (isset($component)) {
        $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component;
    } ?>
                <?php    if (isset($attributes)) {
        $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes;
    } ?>
                <?php    $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index', 'data' => ['content' => 'Editar nomes dos times', 'position' => 'top']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                <?php    $component->withName('flux::tooltip'); ?>
                <?php    if ($component->shouldRender()): ?>
                <?php        $__env->startComponent($component->resolveView(), $component->data()); ?>
                <?php        if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                <?php            $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                <?php        endif; ?>
                <?php        $component->withAttributes(['content' => 'Editar nomes dos times', 'position' => 'top']); ?>
                <?php        \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                <button type="button" class="scoreboard-dock-button" wire:click="toggleMetaEditor"
                    aria-label="Editar nomes dos times"
                    aria-expanded="<?php        echo e($showMetaEditor ? 'true' : 'false'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 20h9" />
                        <path d="M16.5 3.5a2.1 2.1 0 113 3L7 19l-4 1 1-4 12.5-12.5z" />
                    </svg>
                </button>
                <?php        echo $__env->renderComponent(); ?>
                <?php    endif; ?>
                <?php    if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
                <?php        $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
                <?php        unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
                <?php    endif; ?>
                <?php    if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
                <?php        $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
                <?php        unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
                <?php    endif; ?>

                <?php    if ($showMetaEditor): ?>
                <div class="scoreboard-dock-panel glass-panel">
                    <form wire:submit="saveScoreboardMeta" class="scoreboard-dock-form">
                        <fieldset class="space-y-3">
                            <label class="scoreboard-dock-field">
                                <span>Azul</span>
                                <input wire:model="leftTeamName" type="text" name="left_team_name"
                                    placeholder="<?php        echo e(\App\Models\Scoreboard::defaultLeftTeamName()); ?>">
                            </label>

                            <label class="scoreboard-dock-field">
                                <span>Laranja</span>
                                <input wire:model="rightTeamName" type="text" name="right_team_name"
                                    placeholder="<?php        echo e(\App\Models\Scoreboard::defaultRightTeamName()); ?>">
                            </label>

                            <?php        if (isset($component)) {
            $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component;
        } ?>
                            <?php        if (isset($attributes)) {
            $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes;
        } ?>
                            <?php        $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index', 'data' => ['content' => 'Salvar nomes', 'position' => 'top']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
                            <?php        $component->withName('flux::tooltip'); ?>
                            <?php        if ($component->shouldRender()): ?>
                            <?php            $__env->startComponent($component->resolveView(), $component->data()); ?>
                            <?php            if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
                            <?php                $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
                            <?php            endif; ?>
                            <?php            $component->withAttributes(['content' => 'Salvar nomes', 'position' => 'top']); ?>
                            <?php            \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <button type="submit" class="scoreboard-dock-save" aria-label="Salvar nomes">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20 6L9 17l-5-5" />
                                </svg>
                            </button>
                            <?php            echo $__env->renderComponent(); ?>
                            <?php        endif; ?>
                            <?php        if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
                            <?php            $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
                            <?php            unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
                            <?php        endif; ?>
                            <?php        if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
                            <?php            $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
                            <?php            unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
                            <?php        endif; ?>
                        </fieldset>
                    </form>
                </div>
                <?php    endif; ?>
            </div>

            <?php    if (isset($component)) {
        $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component;
    } ?>
            <?php    if (isset($attributes)) {
        $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes;
    } ?>
            <?php    $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index', 'data' => ['content' => 'Resetar placar', 'position' => 'top']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
            <?php    $component->withName('flux::tooltip'); ?>
            <?php    if ($component->shouldRender()): ?>
            <?php        $__env->startComponent($component->resolveView(), $component->data()); ?>
            <?php        if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
            <?php            $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
            <?php        endif; ?>
            <?php        $component->withAttributes(['content' => 'Resetar placar', 'position' => 'top']); ?>
            <?php        \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

            <button type="button" class="scoreboard-dock-button scoreboard-dock-button-danger"
                data-confirm-title="Resetar placar?"
                data-confirm-message="Os dois lados voltam para zero imediatamente."
                data-confirm-confirm-label="Resetar" data-confirm-tone="danger"
                data-livewire-confirm-method="resetScoreboard" aria-label="Resetar placar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 12a9 9 0 109-9" />
                    <path d="M3 3v6h6" />
                </svg>
            </button>
            <?php        echo $__env->renderComponent(); ?>
            <?php    endif; ?>
            <?php    if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
            <?php        $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
            <?php        unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
            <?php    endif; ?>
            <?php    if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
            <?php        $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
            <?php        unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
            <?php    endif; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>