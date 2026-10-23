import './bootstrap';

const shell = document.querySelector('[data-game-shell]');
const toggle = document.querySelector('[data-menu-toggle]');
const closeMenu = () => { shell?.classList.remove('menu-open'); toggle?.setAttribute('aria-expanded', 'false'); };
toggle?.addEventListener('click', () => { const open = shell.classList.toggle('menu-open'); toggle.setAttribute('aria-expanded', String(open)); });
document.querySelector('[data-menu-close]')?.addEventListener('click', closeMenu);
document.querySelectorAll('.game-nav a').forEach((link) => link.addEventListener('click', closeMenu));
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });

const prepareLoadingForm = (form, event) => {
    if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
    if (!form.checkValidity()) return;
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    const chosen = event.submitter;
    if (chosen?.name) {
        const field = document.createElement('input'); field.type = 'hidden'; field.name = chosen.name; field.value = chosen.value; field.dataset.submittedAction = 'true'; form.append(field);
    }
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => { button.dataset.originalLabel = button.value || button.textContent; button.disabled = true; });
    if (chosen) {
        const label = form.dataset.loadingLabel || chosen.dataset.loadingLabel || 'Processando…';
        if (chosen.tagName === 'INPUT') chosen.value = label; else chosen.textContent = label;
        chosen.classList.add('is-loading');
    }
    const status = document.createElement('span'); status.className = 'submit-status'; status.dataset.submitStatus = 'true'; status.setAttribute('role', 'status'); status.textContent = 'Aguarde a conclusão da ação.'; form.append(status);
};
document.querySelectorAll('form[data-progression], form[data-loading-form]').forEach((form) => form.addEventListener('submit', (event) => prepareLoadingForm(form, event)));
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
        form.dataset.submitting = 'false'; form.removeAttribute('aria-busy');
        form.querySelectorAll('[data-submitted-action], [data-submit-status]').forEach((node) => node.remove());
        form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
            button.disabled = button.hasAttribute('data-initially-disabled');
            if (button.dataset.originalLabel) { if (button.tagName === 'INPUT') button.value = button.dataset.originalLabel; else button.textContent = button.dataset.originalLabel; }
            button.classList.remove('is-loading');
        });
    });
});
document.querySelectorAll('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('[data-dismissible]')?.remove()));

document.querySelectorAll('[data-starter-select]').forEach((select) => {
    const marker = document.querySelector(`[data-pitch-player="${select.dataset.starterSelect}"] [data-pitch-name]`);
    if (!marker) return;
    select.addEventListener('change', () => {
        marker.textContent = select.selectedOptions[0]?.dataset.playerName || 'Escolha atleta';
    });
});

document.querySelectorAll('[data-ticket-preview]').forEach((form) => {
    const input = form.querySelector('[name="price"]');
    const output = form.querySelector('[data-ticket-estimate]');
    const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const number = new Intl.NumberFormat('pt-BR');
    let timer;
    let request;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        request?.abort();
        if (!input.checkValidity()) {
            output.textContent = `Informe um preço válido entre ${money.format(Number(input.min))} e ${money.format(Number(input.max))}.`;
            return;
        }
        output.textContent = 'Calculando estimativa…';
        timer = setTimeout(async () => {
            request = new AbortController();
            try {
                const url = new URL(form.dataset.previewUrl, window.location.href);
                url.searchParams.set('price', input.value);
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
                if (!response.ok) throw new Error('Falha na previsão');
                const estimate = await response.json();
                output.textContent = `Estimativa: ${number.format(estimate.forecast)} torcedores · ${money.format(estimate.gross_revenue_cents / 100)} brutos · ${money.format(estimate.estimated_net_cents / 100)} líquidos · saldo projetado ${money.format(estimate.projected_balance_cents / 100)}. Salve para aplicar.`;
            } catch (error) {
                if (error.name !== 'AbortError') output.textContent = 'Não foi possível calcular a previsão. Tente novamente.';
            }
        }, 250);
    });
});
