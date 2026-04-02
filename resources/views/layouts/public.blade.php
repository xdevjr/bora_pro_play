@php($pageTitle = filled($title ?? null) ? trim((string) $title) : (trim($__env->yieldContent('title')) ?: null))
@php($bodyClass = trim($__env->yieldContent('body_class')) ?: 'min-h-screen bg-zinc-950 text-zinc-50 antialiased')

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head', ['title' => $pageTitle])
    <link rel="stylesheet" href="/confirm-dialog.css?v=1">
    @stack('head')
</head>

<body class="public-theme dark {{ $bodyClass }}" data-public-theme="dark">
    <div class="public-shell" data-public-shell>
        @yield('content')
    </div>

    <flux:toast.group position="top end">
        <flux:toast />
    </flux:toast.group>

    @if (session('flux_toast'))
        <div hidden data-flux-toast='@json(session('flux_toast'))'></div>
    @endif

    <div class="confirm-dialog" data-confirm-dialog hidden>
        <div class="confirm-dialog-backdrop" data-confirm-cancel></div>

        <div class="confirm-dialog-panel glass-panel" role="alertdialog" aria-modal="true"
            aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
            <div class="confirm-dialog-icon" data-confirm-icon aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M12 9v4" />
                    <path d="M12 17h.01" />
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
            </div>

            <div class="confirm-dialog-copy">
                <p class="confirm-dialog-eyebrow">Confirmacao</p>
                <h2 class="confirm-dialog-title" id="confirm-dialog-title" data-confirm-title>Confirmar acao</h2>
                <p class="confirm-dialog-message" id="confirm-dialog-message" data-confirm-message>Tem certeza?</p>
            </div>

            <div class="confirm-dialog-actions">
                <button type="button" class="confirm-dialog-button confirm-dialog-button-secondary" data-confirm-cancel>
                    Cancelar
                </button>
                <button type="button" class="confirm-dialog-button confirm-dialog-button-danger" data-confirm-accept>
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    @stack('scripts')
    <script src="/confirm-dialog.js?v=1"></script>
    @fluxScripts
</body>

</html>