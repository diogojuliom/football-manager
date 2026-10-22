@extends('careers.layout')
@section('title', 'Ingressos — '.$career->name)
@section('content')
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a> · <a href="{{ route('careers.stadium.show', $career) }}">Estádio e torcida</a> · <a href="{{ route('careers.finances.show', $career) }}">Finanças</a></p>
<h1>Ingressos — {{ $club->name }}</h1>
<section class="card">
    <h2>Preço padrão</h2>
    <p>Usado automaticamente em futuras partidas em casa sem preço específico. Atual: R$ {{ number_format($club->default_ticket_price_cents / 100, 2, ',', '.') }}.</p>
    <form method="post" action="{{ route('careers.tickets.default', $career) }}">
        @csrf
        @method('PATCH')
        <label for="default-price">Novo preço padrão (R$)</label>
        <input id="default-price" name="price" type="number" min="{{ number_format(config('tickets.min_price_cents') / 100, 2, '.', '') }}" max="{{ number_format(config('tickets.max_price_cents') / 100, 2, '.', '') }}" step="0.01" inputmode="decimal" value="{{ number_format($club->default_ticket_price_cents / 100, 2, '.', '') }}" required>
        <button type="submit">Salvar preço padrão</button>
    </form>
</section>
@if($match)
    <h2>Partidas futuras em casa</h2>
    <div class="table-scroll"><table><thead><tr><th>Data</th><th>Rodada</th><th>Adversário</th><th>Ingressos</th></tr></thead><tbody>
    @foreach($homeMatches as $homeMatch)
    <tr><td>{{ $homeMatch->scheduled_on->format('d/m/Y') }}</td><td>{{ $homeMatch->round }}</td><td>{{ $homeMatch->awayClub->name }}</td><td><a href="{{ route('careers.tickets.index', ['career' => $career, 'match_id' => $homeMatch->id]) }}">{{ $homeMatch->id === $match->id ? 'Selecionada' : 'Definir preço' }}</a></td></tr>
    @endforeach
    </tbody></table></div>
    <p>Partida selecionada: {{ $match->scheduled_on->format('d/m/Y') }} contra {{ $match->awayClub->name }}.</p>
    @include('careers.partials.match-ticket-form')
@else
    <p>Não há partidas futuras em casa nesta temporada.</p>
@endif
@endsection
