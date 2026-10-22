import '../css/auth.css';

for (const button of document.querySelectorAll('[data-password-toggle]')) {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.getAttribute('aria-controls'));
        if (!field) return;

        const visible = field.type === 'password';
        field.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
    });
}

const password = document.querySelector('#password[autocomplete="new-password"]');
if (password) {
    const hints = document.querySelector('#password-hints');
    const updateHints = () => {
        const checks = {
            length: password.value.length >= 8,
            uppercase: /[A-Z]/.test(password.value),
            number: /[0-9]/.test(password.value),
            special: /[^A-Za-z0-9]/.test(password.value),
        };
        for (const [name, met] of Object.entries(checks)) {
            hints.querySelector(`[data-strength="${name}"]`).dataset.met = String(met);
        }
    };
    password.addEventListener('input', updateHints);
    updateHints();
}

for (const form of document.querySelectorAll('[data-auth-form]')) {
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        if (!form.checkValidity()) return;

        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        submit.querySelector('[data-submit-label]').textContent = form.dataset.loadingText;
        submit.querySelector('.auth-spinner').hidden = false;
        submit.querySelector('.auth-submit-arrow').hidden = true;
    });
}
