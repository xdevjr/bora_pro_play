<?php

use App\Models\Room;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    use \Flux\Concerns\InteractsWithComponents;

    public string $createRoomName = '';

    public string $createRoomEditorPin = '';

    public string $createRoomEditorPinConfirmation = '';

    public string $enterRoomCode = '';

    public function render(): View
    {
        return $this->view()
            ->extends('layouts.public')
            ->title('Bora Pro Play');
    }

    public function createRoom(): void
    {
        $this->createRoomName = Str::of($this->createRoomName)
            ->squish()
            ->value();

        $validated = $this->validateWithToast([
            'createRoomName' => ['required', 'string', 'max:80'],
            'createRoomEditorPin' => ['required', 'digits_between:4,8', 'same:createRoomEditorPinConfirmation'],
        ], attributes: [
            'createRoomName' => 'name',
            'createRoomEditorPin' => 'editor pin',
        ]);

        $room = Room::query()->create([
            'name' => $validated['createRoomName'],
            'editor_pin' => $validated['createRoomEditorPin'],
        ]);

        $this->grantEditAccess($room);

        $this->flashToast(
            text: 'O PIN deste aparelho ja esta liberado para edicao.',
            heading: 'Sala criada',
            variant: 'success',
        );

        $this->redirectRoute('rooms.show', $room, navigate: true);
    }

    public function enterRoom(): void
    {
        $value = trim($this->enterRoomCode);

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $path = trim((string) parse_url($value, PHP_URL_PATH), '/');
            $value = (string) Str::of($path)->afterLast('/');
        }

        $this->enterRoomCode = (string) Str::of($value)
            ->trim()
            ->slug('-');

        $this->validateWithToast([
            'enterRoomCode' => ['required', 'string', 'max:120', Rule::exists('rooms', 'code')],
        ], attributes: [
            'enterRoomCode' => 'code',
        ]);

        $room = Room::query()
            ->where('code', $this->enterRoomCode)
            ->firstOrFail();

        $this->redirectRoute('rooms.show', $room, navigate: true);
    }

    private function grantEditAccess(Room $room): void
    {
        $editableRooms = collect(session('editable_rooms', []))
            ->push($room->getKey())
            ->unique()
            ->values()
            ->all();

        session()->put('editable_rooms', $editableRooms);
    }

    private function validateWithToast(array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return $this->validate($rules, $messages, $attributes);
        } catch (ValidationException $exception) {
            $this->toast(
                text: $exception->validator->errors()->first() ?: 'Confira os campos e tente novamente.',
                heading: 'Nao foi possivel continuar',
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
<div class="relative z-10 mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-8 px-4 py-5 sm:px-6 lg:px-8 lg:py-8">
    <header class="glass-panel p-6 sm:p-8 lg:p-10">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1.4fr)_minmax(22rem,0.9fr)] lg:items-end">
            <div class="space-y-5">
                <div class="flex flex-wrap gap-2">
                    <span class="glass-chip glass-chip-cyan">PWA instalavel</span>
                    <span class="glass-chip glass-chip-amber">Feito para a beira da quadra</span>
                </div>

                <div class="space-y-4">
                    <p class="eyebrow">Bora Pro Play</p>
                    <h1
                        class="section-title max-w-4xl text-5xl font-black tracking-tight text-white sm:text-6xl lg:text-[4.6rem]">
                        Sorteie os times e abra o placar sem sair do ritmo do jogo.
                    </h1>
                    <p class="max-w-3xl text-base text-zinc-100/78 sm:text-lg">
                        Crie uma sala publica, compartilhe o nome com o grupo, controle quem entra no sorteio e deixe o
                        placar pronto para toque rapido em qualquer celular.
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                <article class="metric-card min-h-0">
                    <p class="metric-label">Sorteio</p>
                    <p class="metric-value text-cyan-100">Smart</p>
                    <p class="metric-copy">Garantidos entram primeiro e o sistema fecha o restante.</p>
                </article>

                <article class="metric-card min-h-0">
                    <p class="metric-label">Placar</p>
                    <p class="metric-value text-orange-100">Touch</p>
                    <p class="metric-copy">Um toque soma e o reset continua protegido por confirmacao.</p>
                </article>

                <article class="metric-card min-h-0">
                    <p class="metric-label">Fluxo</p>
                    <p class="metric-value text-fuchsia-100">Publico</p>
                    <p class="metric-copy">Sem login, sem dashboard e sem passo sobrando.</p>
                </article>
            </div>
        </div>
    </header>

    <section class="glass-panel p-6 sm:p-8">
        <div class="grid gap-4 md:grid-cols-3">
            <article class="metric-card min-h-0">
                <p class="metric-label">1. Crie ou abra uma sala</p>
                <p class="mt-3 text-sm text-zinc-100/76">
                    O nome da sala vira o atalho publico para voltar ao mesmo ambiente em qualquer aparelho.
                </p>
            </article>

            <article class="metric-card min-h-0">
                <p class="metric-label">2. Cadastre o elenco</p>
                <p class="mt-3 text-sm text-zinc-100/76">
                    Ative e pause participantes sem recarregar a pagina e sem perder o contexto do sorteio.
                </p>
            </article>

            <article class="metric-card min-h-0">
                <p class="metric-label">3. Sorteie e marque</p>
                <p class="mt-3 text-sm text-zinc-100/76">
                    Quando fechar em dois times, o placar pode nascer pronto para jogo.
                </p>
            </article>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.92fr)] lg:items-start">
        <section class="glass-panel p-6 sm:p-7">
            <div class="mb-6 space-y-2">
                <p class="eyebrow">Nova sala</p>
                <h2 class="section-title text-2xl font-black text-white sm:text-3xl">Criar sala</h2>
                <p class="text-sm text-zinc-200/75">
                    Escolha um nome e um PIN de edicao para liberar alteracoes neste aparelho.
                </p>
            </div>

            <form wire:submit="createRoom" class="space-y-5">
                <flux:input wire:model="createRoomName" name="create_room_name" label="Nome da sala"
                    placeholder="Volei de quinta" required />

                <flux:input wire:model="createRoomEditorPin" name="create_room_editor_pin" label="PIN de edicao"
                    type="password" inputmode="numeric" maxlength="8" placeholder="1234" required />

                <flux:input wire:model="createRoomEditorPinConfirmation" name="create_room_editor_pin_confirmation"
                    label="Confirmar PIN" type="password" inputmode="numeric" maxlength="8" placeholder="1234"
                    required />

                <flux:button variant="primary" type="submit"
                    class="w-full justify-center rounded-full bg-cyan-300! text-slate-950! shadow-lg shadow-cyan-500/20">
                    Criar sala e comecar
                </flux:button>
            </form>
        </section>

        <section class="glass-panel p-6 sm:p-7">
            <div class="mb-6 space-y-2">
                <p class="eyebrow">Entrar pelo nome</p>
                <h2 class="section-title text-2xl font-black text-white sm:text-3xl">Abrir sala existente</h2>
                <p class="text-sm text-zinc-200/75">
                    Digite o nome da sala para acompanhar o sorteio, abrir o historico ou destravar a edicao com o PIN.
                </p>
            </div>

            <form wire:submit="enterRoom" class="space-y-5">
                <flux:input wire:model="enterRoomCode" name="enter_room_code" label="Nome da sala"
                    placeholder="Volei de quinta" maxlength="120" required />

                <flux:button variant="primary" type="submit"
                    class="w-full justify-center rounded-full bg-orange-300! text-slate-950! shadow-lg shadow-orange-500/20">
                    Entrar na sala
                </flux:button>
            </form>
        </section>
    </div>
</div>