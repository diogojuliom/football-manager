@extends('careers.layout')
@section('title', 'Pré-jogo — '.$career->name)
@section('content')
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a></p>
<h1>Pré-jogo: {{ $match->homeClub->name }} × {{ $match->awayClub->name }}</h1>
<p>{{ $career->current_date->format('d/m/Y') }} · Rodada {{ $match->round }}</p>
<p>Revise os titulares, reservas, condição física e táticas antes de confirmar. A partida só começa com sua confirmação.</p>
<p><a href="{{ route('careers.squad.edit', $career) }}">Revisar escalação e táticas</a></p>
<p>A confirmação registra o resultado oficial, aplica desgaste e suspensões e conclui os outros jogos desta data. A carreira permanece no mesmo dia após a partida.</p>
<p>{{ $forecast['stadium_name'] }} · Capacidade disponível: {{ number_format($forecast['input']['capacity'], 0, ',', '.') }} · Importância: {{ $forecast['importance'] }}/100.</p>
<p>Previsão de público: <strong>{{ number_format($forecast['forecast'], 0, ',', '.') }}</strong>. O público realizado e a bilheteria serão registrados ao confirmar a partida.</p>
@if($match->home_club_id === $career->controlledClub->career_club_id)
    @include('careers.partials.match-ticket-form')
@else
    <p>O mandante define os ingressos desta partida. Seu clube joga como visitante.</p>
@endif
@if($decision)
<p>Confirme primeiro o acontecimento pendente no dashboard.</p>
@else
<form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ $requestId }}">
    <input type="hidden" name="revision" value="{{ $career->progression_revision }}">
    <input type="hidden" name="match_id" value="{{ $match->id }}">
    <button class="primary" name="action" value="PLAY_MATCH" type="submit">Confirmar e jogar partida</button>
</form>
@endif
@endsection
