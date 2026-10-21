@extends('careers.layout')
@section('title', $career->name.' — Football Manager')
@section('content')
@php($club = $career->controlledClub->club)
<h1>{{ $career->name }}</h1>
<p>Treinador: {{ $career->coach_name }} · Dados-base {{ $career->version->version }}</p>
<section class="card" aria-label="Resumo da carreira">
    <h2>{{ $club->name }}</h2>
    <p>Data interna: <strong>{{ $career->current_date->format('d/m/Y') }}</strong> · Temporada {{ $career->current_season_number }}</p>
    <p>Estado: {{ match($career->state) {
        \App\Domain\Career\CareerState::AwaitingDecision => 'Aguardando decisão',
        \App\Domain\Career\CareerState::Processing => 'Processando ação autorizada',
        \App\Domain\Career\CareerState::PreMatch => 'Pré-jogo',
        \App\Domain\Career\CareerState::AwaitingMatch => 'Aguardando partida',
        \App\Domain\Career\CareerState::AwaitingSeason => 'Aguardando próxima temporada',
    } }}</p>
    <p>Saldo: R$ {{ number_format($club->balance_cents / 100, 2, ',', '.') }}</p>
    <p>{{ $club->stadium->name }} · {{ number_format($club->stadium->available_capacity, 0, ',', '.') }} lugares disponíveis</p>
    <p>Seu progresso confirmado fica salvo. Os comandos para avançar o calendário serão disponibilizados em uma próxima entrega.</p>
</section>
<h2>Elenco</h2>
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Posição</th><th>Idade</th><th>Condição física</th><th>Moral</th></tr></thead><tbody>
@foreach($club->players as $player)
<tr><td>{{ $player->name }}</td><td>{{ $player->primary_position }}</td><td>{{ $player->age }}</td><td>{{ $player->fitness }}</td><td>{{ $player->morale }}</td></tr>
@endforeach
</tbody></table></div>
<h2>Calendário da liga</h2>
<div class="table-scroll"><table><thead><tr><th>Data</th><th>Rodada</th><th>Mandante</th><th>Visitante</th></tr></thead><tbody>
@foreach($matches as $match)
<tr><td>{{ $match->scheduled_on->format('d/m/Y') }}</td><td>{{ $match->round }}</td><td>{{ $match->homeClub->name }}</td><td>{{ $match->awayClub->name }}</td></tr>
@endforeach
</tbody></table></div>
{{ $matches->links() }}
@endsection
