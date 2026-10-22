<section class="card" aria-label="Ingressos da partida">
    <h2>Ingressos desta partida</h2>
    <p>Preço atual: R$ {{ number_format($forecast['input']['price_cents'] / 100, 2, ',', '.') }}. Previsão de {{ number_format($forecast['forecast'], 0, ',', '.') }} torcedores e receita bruta de R$ {{ number_format($forecast['forecast'] * $forecast['input']['price_cents'] / 100, 2, ',', '.') }}.</p>
    <form method="post" action="{{ route('careers.matches.tickets.update', [$career, $match]) }}" data-ticket-preview data-preview-url="{{ route('careers.matches.tickets.preview', [$career, $match]) }}">
        @csrf
        @method('PUT')
        <label for="match-price-{{ $match->id }}">Preço por ingresso (R$)</label>
        <input id="match-price-{{ $match->id }}" name="price" type="number" min="{{ number_format(config('tickets.min_price_cents') / 100, 2, '.', '') }}" max="{{ number_format(config('tickets.max_price_cents') / 100, 2, '.', '') }}" step="0.01" inputmode="decimal" value="{{ number_format($forecast['input']['price_cents'] / 100, 2, '.', '') }}" required>
        @php($estimatedCost = $forecast['input']['fixed_cost_cents'] + $forecast['forecast'] * $forecast['input']['variable_cost_cents'])
        <p role="status" aria-live="polite" data-ticket-estimate>Estimativa: {{ number_format($forecast['forecast'], 0, ',', '.') }} torcedores · R$ {{ number_format($forecast['forecast'] * $forecast['input']['price_cents'] / 100, 2, ',', '.') }} de receita bruta · R$ {{ number_format(($forecast['forecast'] * $forecast['input']['price_cents'] - $estimatedCost) / 100, 2, ',', '.') }} líquidos antes da variação do público realizado.</p>
        <button type="submit">Salvar preço desta partida</button>
    </form>
    @if($match->ticketPolicy)
    <form method="post" action="{{ route('careers.matches.tickets.clear', [$career, $match]) }}">
        @csrf
        @method('DELETE')
        <button type="submit">Voltar ao preço padrão</button>
    </form>
    @endif
    <p>A estimativa é recalculada no servidor conforme o preço digitado. O valor só entra em vigor após salvar; a confirmação da partida congela o preço e a previsão.</p>
</section>
@once
<script src="{{ asset('js/tickets.js') }}" defer></script>
@endonce
