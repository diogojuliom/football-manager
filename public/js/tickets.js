for (const form of document.querySelectorAll('[data-ticket-preview]')) {
    const input = form.querySelector('[name="price"]');
    const output = form.querySelector('[data-ticket-estimate]');
    const formatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const number = new Intl.NumberFormat('pt-BR');
    let timer;
    let request;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        request?.abort();
        if (!input.checkValidity()) {
            output.textContent = `Informe um preço válido entre ${formatter.format(Number(input.min))} e ${formatter.format(Number(input.max))}.`;
            return;
        }
        output.textContent = 'Calculando estimativa...';
        timer = setTimeout(async () => {
            request = new AbortController();
            try {
                const url = new URL(form.dataset.previewUrl, window.location.href);
                url.searchParams.set('price', input.value);
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
                if (!response.ok) throw new Error('Falha na previsão');
                const estimate = await response.json();
                output.textContent = `Estimativa: ${number.format(estimate.forecast)} torcedores · ${formatter.format(estimate.gross_revenue_cents / 100)} brutos · ${formatter.format(estimate.estimated_net_cents / 100)} líquidos · saldo projetado ${formatter.format(estimate.projected_balance_cents / 100)}. Salve para aplicar.`;
            } catch (error) {
                if (error.name !== 'AbortError') output.textContent = 'Não foi possível calcular a previsão. Tente novamente.';
            }
        }, 250);
    });
}
