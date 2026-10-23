@extends('careers.layout')
@section('title', 'Estádio e torcida — '.$career->name)
@section('content')
<header class="page-header"><div class="page-header-copy"><a class="breadcrumb" href="{{ route('careers.show', $career) }}">← Voltar à carreira</a><p class="eyebrow">Patrimônio e torcida</p><h1>Estádio — {{ $club->stadium->name }}</h1><p>Administre a casa do {{ $club->name }}, acompanhe a torcida e planeje melhorias de longo prazo.</p></div><div class="page-actions"><a class="btn" href="{{ route('careers.tickets.index', $career) }}">Gerenciar ingressos</a><a class="btn" href="{{ route('careers.finances.show', $career) }}">Painel financeiro</a></div></header>
<section class="stadium-hero" aria-label="Ilustração do estádio"><div><span class="badge">Casa do {{ $club->name }}</span><h2>{{ $club->stadium->name }}</h2><p>{{ number_format($club->stadium->available_capacity, 0, ',', '.') }} lugares disponíveis · Infraestrutura nível {{ $club->stadium->infrastructure_level }}</p></div><span aria-hidden="true">▰</span></section>
<div class="stat-grid"><article class="stat-card"><span>Capacidade total</span><strong>{{ number_format($club->stadium->capacity, 0, ',', '.') }}</strong><small>{{ number_format($club->stadium->available_capacity, 0, ',', '.') }} disponíveis</small></article><article class="stat-card"><span>Público médio</span><strong>{{ number_format($averages->average_attendance ?? 0, 0, ',', '.') }}</strong><small>{{ number_format(($averages->average_occupancy_bps ?? 0) / 100, 1, ',', '.') }}% de ocupação</small></article><article class="stat-card"><span>Torcida potencial</span><strong>{{ number_format($club->support->potential_supporters, 0, ',', '.') }}</strong><small>Fidelidade {{ $club->support->loyalty }}/100 · Engajamento {{ $club->support->engagement }}/100</small></article><article class="stat-card"><span>Receita acumulada de bilheteria</span><strong>R$ {{ number_format($revenue / 100, 2, ',', '.') }}</strong><small>Preço padrão: R$ {{ number_format($club->default_ticket_price_cents / 100, 2, ',', '.') }}</small></article></div>
@if($nextHome)<section class="panel"><div class="panel-header"><div><h2>Próximo jogo em casa</h2><p>{{ $nextHome->scheduled_on->format('d/m/Y') }} contra {{ $nextHome->awayClub->name }}</p></div><a href="{{ route('careers.tickets.index', ['career'=>$career,'match_id'=>$nextHome->id]) }}">Definir ingressos</a></div><p>Previsão atual: <strong>{{ number_format($forecast['forecast'], 0, ',', '.') }} torcedores</strong> · Importância {{ $forecast['importance'] }}/100 · Receita bruta prevista: R$ {{ number_format($forecast['forecast'] * $forecast['input']['price_cents'] / 100, 2, ',', '.') }}.</p></section>@endif
<section class="card" aria-labelledby="stadium-works-title">
    <h2 id="stadium-works-title">Obras e infraestrutura</h2>
    <p>Data interna: {{ $career->current_date->format('d/m/Y') }} · Saldo: R$ {{ number_format($club->balance_cents / 100, 2, ',', '.') }}. Os prazos avançam somente quando você confirma a progressão da carreira.</p>
    @if($activeWork)
        <p role="status"><strong>Em andamento:</strong> {{ $activeWork->type === 'expansion' ? 'Ampliação de capacidade' : 'Modernização' }}. Início: {{ $activeWork->started_on->format('d/m/Y') }} · Prazo: {{ $activeWork->duration_days }} dias internos · Conclusão prevista: {{ $activeWork->due_on->format('d/m/Y') }}. O benefício será aplicado na conclusão.</p>
    @elseif($career->state === \App\Domain\Career\CareerState::Processing || $career->state === \App\Domain\Career\CareerState::AwaitingSeason)
        <p>Não é possível iniciar uma obra enquanto há uma operação pendente ou a temporada está encerrada.</p>
    @else
        @forelse($offers as $offer)
            <div class="card">
                <h3>{{ $offer['label'] }}</h3>
                <p>Investimento: R$ {{ number_format($offer['cost_cents'] / 100, 2, ',', '.') }} · Prazo: {{ $offer['days'] }} dias internos · Previsão se iniciar hoje: {{ $career->current_date->addDays($offer['days'])->format('d/m/Y') }}.</p>
                <p>{{ $offer['capacity_added'] ? '+'.number_format($offer['capacity_added'], 0, ',', '.').' lugares' : '+1 nível de infraestrutura, com efeito na atratividade da torcida' }}.</p>
                @if($club->balance_cents >= $offer['cost_cents'])
                    <form method="post" action="{{ route('careers.stadium.projects.store', $career) }}" data-loading-form data-loading-label="Iniciando obra…">
                        @csrf
                        <input type="hidden" name="type" value="{{ $offer['id'] }}">
                        <input type="hidden" name="revision" value="{{ $career->progression_revision }}">
                        <button class="primary" type="submit">Iniciar {{ strtolower($offer['label']) }}</button>
                    </form>
                @else
                    <p>Saldo insuficiente para esta obra.</p>
                @endif
            </div>
        @empty
            <p>O estádio já atingiu os limites de capacidade e infraestrutura.</p>
        @endforelse
    @endif
</section>
<h2>Histórico de obras</h2>
@if($works->isEmpty())
    <p>Nenhuma obra iniciada nesta carreira.</p>
@else
    <div class="table-scroll"><table><thead><tr><th>Obra</th><th>Início</th><th>Conclusão prevista</th><th>Status</th><th>Investimento</th><th>Benefício</th></tr></thead><tbody>
    @foreach($works as $work)
        <tr><td>{{ $work->type === 'expansion' ? 'Ampliação' : 'Modernização' }}</td><td>{{ $work->started_on->format('d/m/Y') }}</td><td>{{ $work->due_on->format('d/m/Y') }}</td><td>{{ $work->status === 'completed' ? 'Concluída em '.$work->completed_on->format('d/m/Y') : 'Em andamento' }}</td><td>R$ {{ number_format($work->cost_cents / 100, 2, ',', '.') }}</td><td>{{ $work->capacity_added ? '+'.number_format($work->capacity_added, 0, ',', '.').' lugares' : '+'.$work->infrastructure_added.' nível de infraestrutura' }}</td></tr>
    @endforeach
    </tbody></table></div>
    <div class="pagination">{{ $works->links() }}</div>
@endif
<h2>Histórico de jogos oficiais em casa</h2>
@if($history->isEmpty())
<p>Nenhum público oficial registrado nesta carreira.</p>
@else
<div class="table-scroll"><table><thead><tr><th>Data</th><th>Adversário</th><th>Previsão</th><th>Público</th><th>Ocupação</th><th>Importância</th><th>Preço</th><th>Bilheteria</th></tr></thead><tbody>
@foreach($history as $entry)
<tr><td>{{ $entry->played_on->format('d/m/Y') }}</td><td>{{ $entry->match->awayClub->name }}</td><td>{{ number_format($entry->forecast, 0, ',', '.') }}</td><td>{{ number_format($entry->realized, 0, ',', '.') }}</td><td>{{ number_format($entry->occupancy_bps / 100, 1, ',', '.') }}%</td><td>{{ $entry->importance }}/100</td><td>R$ {{ number_format($entry->ticket_price_cents / 100, 2, ',', '.') }}</td><td>{{ isset($receipts[$entry->match_id]) ? 'R$ '.number_format($receipts[$entry->match_id] / 100, 2, ',', '.') : 'Sem lançamento' }}</td></tr>
@endforeach
</tbody></table></div>
<div class="pagination">{{ $history->links() }}</div>
@endif
@if($friendlies->isNotEmpty())
<h2>Amistosos recentes em casa</h2>
@foreach($friendlies as $friendly)
<p><a href="{{ route('careers.friendlies.show', [$career, $friendly]) }}">Amistoso #{{ $friendly->id }}</a>: {{ number_format($friendly->result['attendance']['present'], 0, ',', '.') }} torcedores.</p>
@endforeach
@endif
@endsection
