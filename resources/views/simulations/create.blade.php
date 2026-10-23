@extends('careers.layout')
@section('title', 'Amistosos — '.$career->name)
@section('content')
@php
    $formSeed = old('seed', $seed);
    $formSeed = is_string($formSeed) ? $formSeed : $seed;
    $formRequest = old('request_id', $requestId);
    $formRequest = old('mode', 'user_vs_ai') === 'user_vs_ai' && is_string($formRequest) ? $formRequest : $requestId;
    $aiFormRequest = old('mode') === 'ai_vs_ai' && is_string(old('request_id')) ? old('request_id') : $aiRequestId;
@endphp
<header class="page-header"><div class="page-header-copy"><a class="breadcrumb" href="{{ route('careers.show', $career) }}">← Voltar à carreira</a><p class="eyebrow">Laboratório tático</p><h1>Simular amistoso</h1><p>Teste sua escalação ou acompanhe duas equipes da IA. Amistosos não avançam o calendário nem alteram saldo, condição física, suspensões ou a liga.</p></div></header>
@if(!$hasLineup)
<p>Para jogar com seu clube, <a href="{{ route('careers.squad.edit', $career) }}">salve a escalação e as táticas do seu clube</a>.</p>
@endif
<div class="content-grid equal"><section class="panel"><div class="panel-header"><div><h2>Seu clube contra a IA</h2><p>A IA adapta a estratégia ao placar, expulsões e desgaste</p></div></div><form method="post" action="{{ route('careers.friendlies.store', $career) }}" data-loading-form data-loading-label="Simulando amistoso…">
    @csrf
    <input type="hidden" name="mode" value="user_vs_ai">
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
</form></section><section class="panel"><div class="panel-header"><div><h2>IA contra IA</h2><p>Observe duas equipes autônomas em ação</p></div></div>
<p>Acompanhe dois clubes da IA em uma partida completa. Não exige uma escalação salva do seu clube.</p>
<form method="post" action="{{ route('careers.friendlies.store', $career) }}" data-loading-form data-loading-label="Simulando IA…">
    @csrf
    <input type="hidden" name="mode" value="ai_vs_ai">
    <input type="hidden" name="request_id" value="{{ $aiFormRequest }}">
    <label for="ai_home_club_id">Mandante da IA</label>
    <select name="home_club_id" id="ai_home_club_id" required>
        @foreach($aiClubs->values() as $index => $club)
        <option value="{{ $club->id }}" @selected(is_scalar(old('home_club_id')) ? (string) old('home_club_id') === (string) $club->id : $index === 0)>{{ $club->name }}</option>
        @endforeach
    </select>
    <label for="ai_away_club_id">Visitante da IA</label>
    <select name="away_club_id" id="ai_away_club_id" required>
        @foreach($aiClubs->values() as $index => $club)
        <option value="{{ $club->id }}" @selected(old('mode') === 'ai_vs_ai' && is_scalar(old('away_club_id')) ? (string) old('away_club_id') === (string) $club->id : $index === 1)>{{ $club->name }}</option>
        @endforeach
    </select>
    <label for="ai_seed">Código da simulação (seed)</label>
    <input name="seed" id="ai_seed" value="{{ $formSeed }}" maxlength="64" pattern="[a-zA-Z0-9_-]{1,64}" required>
    <button type="submit">Jogar IA contra IA</button>
</form></section></div>
<section class="panel"><div class="panel-header"><div><h2>Amistosos recentes</h2><p>Resultados de treinamento preservados na carreira</p></div></div>
@forelse($simulations as $simulation)
<p><a href="{{ route('careers.friendlies.show', [$career, $simulation]) }}">{{ $simulation->result['home']['name'] }} {{ $simulation->result['home']['stats']['goals'] }} × {{ $simulation->result['away']['stats']['goals'] }} {{ $simulation->result['away']['name'] }}</a></p>
@empty
<p>Nenhum amistoso realizado.</p>
@endforelse
</section>
@endsection
