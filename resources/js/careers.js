import '../css/careers.css';

const form = document.querySelector('[data-career-form]');

if (form) {
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
        submit.querySelector('[data-submit-label]').textContent = 'Criando carreira...';
        submit.querySelector('.career-spinner').hidden = false;
        submit.querySelector('.career-submit-arrow').hidden = true;
    });
}
