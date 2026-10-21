@extends('careers.layout')
@section('title', 'Amistosos — '.$career->name)
@section('content')
@php
    $formSeed = old('seed', $seed);
    $formSeed = is_string($formSeed) ? $formSeed : $seed;
    $formRequest = old('request_id', $requestId);
    $formRequest = is_string($formRequest) ? $formRequest : $requestId;
@endphp
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a></p>
<h1>Simular amistoso</h1>
<p>Use sua escalação salva para enfrentar outro clube da carreira. O amistoso não avança o calendário nem altera saldo, condição física, suspensões ou resultados da liga.</p>
<p>O adversário usa uma escalação inicial equilibrada. Decisões dinâmicas da IA serão disponibilizadas em uma próxima entrega.</p>
@if(!$hasLineup)
<p>Antes de jogar, <a href="{{ route('careers.squad.edit', $career) }}">salve a escalação e as táticas do seu clube</a>.</p>
@endif
<form method="post" action="{{ route('careers.friendlies.store', $career) }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ $formRequest }}">
    <label for="away_club_id">Adversário</label>
    <select name="away_club_id" id="away_club_id" required>
        @foreach($opponents as $club)
        <option value="{{ $club->id }}" @selected(is_scalar(old('away_club_id')) && (string) old('away_club_id') === (string) $club->id)>{{ $club->name }}</option>
        @endforeach
    </select>
    <label for="seed">Código da simulação (seed)</label>
    <input name="seed" id="seed" value="{{ $formSeed }}" maxlength="64" pattern="[a-zA-Z0-9_-]{1,64}" required>
    <p>A mesma seed, as mesmas equipes e táticas e a mesma versão do motor reproduzem a partida.</p>
    <button type="submit" @disabled(!$hasLineup)>Jogar amistoso</button>
</form>
<h2>Amistosos recentes</h2>
@forelse($simulations as $simulation)
<p><a href="{{ route('careers.friendlies.show', [$career, $simulation]) }}">{{ $simulation->result['home']['name'] }} {{ $simulation->result['home']['stats']['goals'] }} × {{ $simulation->result['away']['stats']['goals'] }} {{ $simulation->result['away']['name'] }}</a></p>
@empty
<p>Nenhum amistoso realizado.</p>
@endforelse
@endsection
