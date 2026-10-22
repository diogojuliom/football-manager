@extends('careers.layout')
@section('title', 'Estádio e torcida — '.$career->name)
@section('content')
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a></p>
<h1>Estádio e torcida — {{ $club->name }}</h1>
<section class="card">
    <h2>{{ $club->stadium->name }}</h2>
    <p>Capacidade total: {{ number_format($club->stadium->capacity, 0, ',', '.') }} · Lugares disponíveis: {{ number_format($club->stadium->available_capacity, 0, ',', '.') }} · Infraestrutura: nível {{ $club->stadium->infrastructure_level }}.</p>
    <p>Torcida potencial: {{ number_format($club->support->potential_supporters, 0, ',', '.') }} · Fidelidade: {{ $club->support->loyalty }}/100 · Engajamento: {{ $club->support->engagement }}/100.</p>
    <p>Temporada atual — partidas oficiais em casa: {{ $averages->games }} · Público médio: {{ number_format($averages->average_attendance ?? 0, 0, ',', '.') }} · Ocupação média: {{ number_format(($averages->average_occupancy_bps ?? 0) / 100, 1, ',', '.') }}%.</p>
    @if($nextHome)
    <p>Próximo jogo em casa: {{ $nextHome->scheduled_on->format('d/m/Y') }} contra {{ $nextHome->awayClub->name }}. Previsão atual: {{ number_format($forecast['forecast'], 0, ',', '.') }} torcedores; importância {{ $forecast['importance'] }}/100.</p>
    @endif
</section>
<h2>Histórico de jogos oficiais em casa</h2>
@if($history->isEmpty())
<p>Nenhum público oficial registrado nesta carreira.</p>
@else
<div class="table-scroll"><table><thead><tr><th>Data</th><th>Adversário</th><th>Previsão</th><th>Público</th><th>Ocupação</th><th>Importância</th><th>Preço-base</th></tr></thead><tbody>
@foreach($history as $entry)
<tr><td>{{ $entry->played_on->format('d/m/Y') }}</td><td>{{ $entry->match->awayClub->name }}</td><td>{{ number_format($entry->forecast, 0, ',', '.') }}</td><td>{{ number_format($entry->realized, 0, ',', '.') }}</td><td>{{ number_format($entry->occupancy_bps / 100, 1, ',', '.') }}%</td><td>{{ $entry->importance }}/100</td><td>R$ {{ number_format($entry->ticket_price_cents / 100, 2, ',', '.') }}</td></tr>
@endforeach
</tbody></table></div>
{{ $history->links() }}
@endif
@if($friendlies->isNotEmpty())
<h2>Amistosos recentes em casa</h2>
@foreach($friendlies as $friendly)
<p><a href="{{ route('careers.friendlies.show', [$career, $friendly]) }}">Amistoso #{{ $friendly->id }}</a>: {{ number_format($friendly->result['attendance']['present'], 0, ',', '.') }} torcedores.</p>
@endforeach
@endif
<p>Receitas de ingressos, custos e intervenções no estádio serão tratados nas Entregas 08 e 09.</p>
@endsection
