// Progressive enhancement: requests remain authorized and idempotent on the server.
(() => {
    for (const form of document.querySelectorAll('form[data-progression]')) {
        let busy = false;
        let action;
        let message;
        const disabled = new Map();

        form.addEventListener('submit', (event) => {
            if (busy) {
                event.preventDefault();
                return;
            }
            busy = true;
            form.setAttribute('aria-busy', 'true');
            // Disabled submit buttons are excluded from POST data; preserve the chosen action first.
            if (event.submitter?.name) {
                action = document.createElement('input');
                action.type = 'hidden';
                action.name = event.submitter.name;
                action.value = event.submitter.value;
                form.append(action);
            }
            for (const button of form.querySelectorAll('button')) {
                disabled.set(button, button.disabled);
                button.disabled = true;
            }
            message = document.createElement('p');
            message.setAttribute('role', 'status');
            message.textContent = 'Processando sua ação. Aguarde a conclusão.';
            form.append(message);
        });

        // A page restored from the back/forward cache must be usable again.
        window.addEventListener('pageshow', () => {
            busy = false;
            form.removeAttribute('aria-busy');
            action?.remove();
            message?.remove();
            for (const [button, wasDisabled] of disabled) button.disabled = wasDisabled;
            disabled.clear();
        });
    }
})();
