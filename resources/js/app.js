function focusTarget(selector) {
    if (!selector) {
        return;
    }

    const element = document.querySelector(selector);

    if (!(element instanceof HTMLElement)) {
        return;
    }

    element.focus({
        preventScroll: true,
    });

    if ((element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) && element.value.length > 0) {
        element.select();
    }
}

function showFluxToast(payload) {
    if (!payload || typeof payload !== 'object') {
        return;
    }

    const text = payload.slots?.text ?? payload.text;
    const heading = payload.slots?.heading ?? payload.heading;

    if (!text && !heading) {
        return;
    }

    const detail = {
        duration: payload.duration ?? 5000,
        slots: {
            ...(heading ? { heading } : {}),
            ...(text ? { text } : {}),
        },
        dataset: {
            ...(payload.dataset ?? {}),
        },
    };

    document.dispatchEvent(new CustomEvent('toast-show', { detail }));
}

function consumeFlashToasts() {
    if (!window.Flux) {
        return;
    }

    document.querySelectorAll('[data-flux-toast]').forEach((element) => {
        const rawPayload = element.getAttribute('data-flux-toast');

        if (!rawPayload) {
            element.remove();

            return;
        }

        try {
            showFluxToast(JSON.parse(rawPayload));
        } catch {
            // Ignore malformed toast payloads.
        }

        element.remove();
    });
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Intentionally silent: the app should continue working without the service worker.
        });
    });
}

function setupClipboardButtons() {
    document.querySelectorAll('[data-copy-text]').forEach((button) => {
        if (button.dataset.clipboardReady === 'true') {
            return;
        }

        button.dataset.clipboardReady = 'true';
        const idleLabel = button.getAttribute('data-copy-label') ?? button.textContent?.trim() ?? 'Copiar';

        button.setAttribute('data-copy-label', idleLabel);

        button.addEventListener('click', async () => {
            const text = button.getAttribute('data-copy-text');

            if (!text || !navigator.clipboard) {
                return;
            }

            try {
                await navigator.clipboard.writeText(text);
                button.setAttribute('data-copy-state', 'done');
                button.textContent = 'Copiado';

                window.setTimeout(() => {
                    button.setAttribute('data-copy-state', 'idle');
                    button.textContent = idleLabel;
                }, 1800);
            } catch {
                // Silent fallback.
            }
        });
    });
}

function getLivewireRoot(element) {
    if (!(element instanceof Element)) {
        return null;
    }

    if (element.matches('[wire\\:id]')) {
        return element;
    }

    return element.closest('[wire\\:id]');
}

function getScoreboardRoot() {
    const root = document.querySelector('[data-scoreboard-root]');

    return root instanceof HTMLElement ? root : null;
}

function getScoreboardStorageKey(root) {
    const scoreboardId = root.getAttribute('data-scoreboard-id');

    return scoreboardId ? `bora-pro-play:scoreboard:${scoreboardId}` : null;
}

function getScoreboardState(root) {
    return {
        left_score: Number(root.getAttribute('data-left-score') ?? 0),
        right_score: Number(root.getAttribute('data-right-score') ?? 0),
    };
}

function setSyncState(root, message) {
    const syncStateElement = root.querySelector('[data-sync-state]');

    if (!(syncStateElement instanceof HTMLElement)) {
        return;
    }

    if (syncStateElement.hasAttribute('data-sync-indicator')) {
        const normalizedState = (() => {
            if (message.toLowerCase().includes('sincron')) {
                return 'synced';
            }

            if (message.toLowerCase().includes('offline')) {
                return 'offline';
            }

            if (message.toLowerCase().includes('bloque')) {
                return 'locked';
            }

            return 'syncing';
        })();

        syncStateElement.dataset.state = normalizedState;
    } else {
        syncStateElement.textContent = message;
    }

    syncStateElement.setAttribute('title', message);
    syncStateElement.setAttribute('aria-label', message);
}

function renderScoreboardState(root, state) {
    root.dataset.leftScore = String(state.left_score);
    root.dataset.rightScore = String(state.right_score);

    const leftValueElement = root.querySelector('[data-score-value="left"]');
    const rightValueElement = root.querySelector('[data-score-value="right"]');

    if (leftValueElement instanceof HTMLElement) {
        leftValueElement.textContent = String(state.left_score);
    }

    if (rightValueElement instanceof HTMLElement) {
        rightValueElement.textContent = String(state.right_score);
    }
}

function persistOfflineScoreboardState(root, state) {
    const storageKey = getScoreboardStorageKey(root);

    if (!storageKey) {
        return;
    }

    localStorage.setItem(storageKey, JSON.stringify(state));
    setSyncState(root, 'Salvo offline neste aparelho');
}

async function syncScoreboardState(root, state) {
    if (root.getAttribute('data-can-edit') !== 'true') {
        return false;
    }

    const livewireRoot = getLivewireRoot(root);
    const wire = livewireRoot?.$wire;

    if (!wire) {
        return false;
    }

    try {
        setSyncState(root, 'Sincronizando...');
        await wire.$call('syncScoreboard', state.left_score, state.right_score);

        const storageKey = getScoreboardStorageKey(root);

        if (storageKey) {
            localStorage.removeItem(storageKey);
        }

        setSyncState(root, 'Sincronizado');

        return true;
    } catch {
        persistOfflineScoreboardState(root, state);

        return false;
    }
}

function hydrateScoreboard() {
    const root = getScoreboardRoot();

    if (!root) {
        return;
    }

    const storageKey = getScoreboardStorageKey(root);
    let state = getScoreboardState(root);

    if (storageKey) {
        const cachedState = localStorage.getItem(storageKey);

        if (cachedState) {
            try {
                state = JSON.parse(cachedState);
            } catch {
                localStorage.removeItem(storageKey);
            }
        }
    }

    renderScoreboardState(root, state);

    if (root.getAttribute('data-can-edit') !== 'true') {
        setSyncState(root, 'Edicao bloqueada neste aparelho');

        return;
    }

    if (storageKey && localStorage.getItem(storageKey) && navigator.onLine) {
        void syncScoreboardState(root, state);

        return;
    }

    setSyncState(root, 'Sincronizado');
}

function focusDrawResultModal() {
    const modal = document.querySelector('[data-draw-result-modal]');

    if (!(modal instanceof HTMLElement)) {
        document.body.style.overflow = '';

        return;
    }

    document.body.style.overflow = 'hidden';

    queueMicrotask(() => {
        const primaryButton = modal.querySelector('[data-draw-result-primary]');

        if (primaryButton instanceof HTMLElement) {
            primaryButton.focus({ preventScroll: true });

            return;
        }

        const fallbackButton = modal.querySelector('button');

        if (fallbackButton instanceof HTMLElement) {
            fallbackButton.focus({ preventScroll: true });
        }
    });
}

function initializePage() {
    setupClipboardButtons();
    hydrateScoreboard();
    focusDrawResultModal();
    consumeFlashToasts();
}

let activeScoreGesture = null;

document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (!target) {
        return;
    }

    const confirmTrigger = target.closest('[data-livewire-confirm-method]');

    if (confirmTrigger instanceof HTMLElement) {
        event.preventDefault();

        const livewireRoot = getLivewireRoot(confirmTrigger);
        const wire = livewireRoot?.$wire;

        if (!wire) {
            return;
        }

        const confirmed = typeof window.openConfirmDialog === 'function'
            ? await window.openConfirmDialog({
                title: confirmTrigger.dataset.confirmTitle,
                message: confirmTrigger.dataset.confirmMessage,
                confirmLabel: confirmTrigger.dataset.confirmConfirmLabel,
                cancelLabel: confirmTrigger.dataset.confirmCancelLabel,
                tone: confirmTrigger.dataset.confirmTone,
            })
            : window.confirm(confirmTrigger.dataset.confirmMessage ?? 'Confirmar acao?');

        if (!confirmed) {
            return;
        }

        let args = [];

        try {
            args = JSON.parse(confirmTrigger.dataset.livewireConfirmArgs ?? '[]');
        } catch {
            args = [];
        }

        await wire.$call(confirmTrigger.dataset.livewireConfirmMethod, ...args);
    }
});

document.addEventListener('pointerdown', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-score-panel]') : null;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    const root = target.closest('[data-scoreboard-root]');

    if (!(root instanceof HTMLElement) || root.getAttribute('data-can-edit') !== 'true') {
        return;
    }

    activeScoreGesture = {
        panel: target,
        startX: event.clientX,
        startY: event.clientY,
    };
});

document.addEventListener('pointercancel', () => {
    activeScoreGesture = null;
});

document.addEventListener('pointerup', (event) => {
    if (!activeScoreGesture) {
        return;
    }

    const panel = activeScoreGesture.panel;
    const root = panel.closest('[data-scoreboard-root]');
    const side = panel.getAttribute('data-score-panel');
    const deltaX = event.clientX - activeScoreGesture.startX;
    const deltaY = event.clientY - activeScoreGesture.startY;

    activeScoreGesture = null;

    if (!(root instanceof HTMLElement) || (side !== 'left' && side !== 'right')) {
        return;
    }

    const state = {
        left_score: Number(root.dataset.leftScore ?? 0),
        right_score: Number(root.dataset.rightScore ?? 0),
    };

    const field = `${side}_score`;
    const delta = Math.abs(deltaY) > Math.abs(deltaX) && deltaY > 36 ? -1 : 1;
    const nextValue = Math.max(0, state[field] + delta);

    if (nextValue === state[field]) {
        return;
    }

    state[field] = nextValue;
    renderScoreboardState(root, state);
    void syncScoreboardState(root, state);
});

document.addEventListener('keydown', async (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    const modal = document.querySelector('[data-draw-result-modal]');

    if (!(modal instanceof HTMLElement)) {
        return;
    }

    const livewireRoot = getLivewireRoot(modal);
    const wire = livewireRoot?.$wire;

    if (!wire) {
        return;
    }

    event.preventDefault();
    await wire.$call('closeDrawResult');
});

document.addEventListener('bora-focus-target', (event) => {
    focusTarget(event.detail?.selector ?? '');
});

document.addEventListener('bora-draw-result-opened', () => {
    focusDrawResultModal();
});

document.addEventListener('bora-draw-result-closed', () => {
    document.body.style.overflow = '';
});

document.addEventListener('livewire:initialized', () => {
    initializePage();
});

document.addEventListener('livewire:navigated', () => {
    initializePage();
});

window.addEventListener('online', () => {
    hydrateScoreboard();
});

registerServiceWorker();
initializePage();
