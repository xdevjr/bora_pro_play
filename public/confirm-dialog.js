(() => {
    const dialog = document.querySelector('[data-confirm-dialog]');

    if (!dialog) {
        return;
    }

    const panel = dialog.querySelector('.confirm-dialog-panel');
    const titleElement = dialog.querySelector('[data-confirm-title]');
    const messageElement = dialog.querySelector('[data-confirm-message]');
    const acceptButton = dialog.querySelector('[data-confirm-accept]');
    const cancelButtons = dialog.querySelectorAll('[data-confirm-cancel]');
    const icon = dialog.querySelector('[data-confirm-icon]');
    const defaultIcon = icon?.innerHTML ?? '';
    const dangerIcon = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>`;
    const neutralIcon = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 12l2 2 4-4" />
            <path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z" />
        </svg>`;

    let resolver = null;
    let previouslyFocusedElement = null;

    const closeDialog = (accepted) => {
        if (resolver) {
            resolver(accepted);
            resolver = null;
        }

        dialog.hidden = true;
        panel?.removeAttribute('data-tone');

        if (previouslyFocusedElement instanceof HTMLElement) {
            previouslyFocusedElement.focus();
        }
    };

    window.openConfirmDialog = ({
        title = 'Confirmar acao',
        message = 'Tem certeza?',
        confirmLabel = 'Confirmar',
        cancelLabel = 'Cancelar',
        tone = 'danger',
    } = {}) => new Promise((resolve) => {
        resolver = resolve;
        previouslyFocusedElement = document.activeElement;

        if (titleElement) {
            titleElement.textContent = title;
        }

        if (messageElement) {
            messageElement.textContent = message;
        }

        if (acceptButton) {
            acceptButton.textContent = confirmLabel;
        }

        cancelButtons.forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.textContent = cancelLabel;
            }
        });

        if (panel) {
            panel.dataset.tone = tone;
        }

        if (icon) {
            icon.innerHTML = tone === 'danger' ? dangerIcon : neutralIcon || defaultIcon;
        }

        dialog.hidden = false;
        acceptButton?.focus();
    });

    acceptButton?.addEventListener('click', () => closeDialog(true));

    cancelButtons.forEach((button) => {
        button.addEventListener('click', () => closeDialog(false));
    });

    document.addEventListener('keydown', (event) => {
        if (dialog.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog(false);
        }
    });

    document.addEventListener('submit', async (event) => {
        const target = event.target;

        if (!(target instanceof HTMLFormElement) || !target.hasAttribute('data-confirm-message')) {
            return;
        }

        if (target.dataset.confirmApproved === 'true') {
            target.dataset.confirmApproved = 'false';

            return;
        }

        event.preventDefault();

        const confirmed = await window.openConfirmDialog({
            title: target.dataset.confirmTitle,
            message: target.dataset.confirmMessage,
            confirmLabel: target.dataset.confirmConfirmLabel,
            cancelLabel: target.dataset.confirmCancelLabel,
            tone: target.dataset.confirmTone || 'danger',
        });

        if (!confirmed) {
            return;
        }

        target.dataset.confirmApproved = 'true';
        target.requestSubmit(event.submitter ?? undefined);
    });
})();