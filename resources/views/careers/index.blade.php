@extends('careers.layout')
@section('title', 'Minhas carreiras — Football Manager')
@section('content')
<h1>Minhas carreiras</h1>
<p><a href="{{ route('careers.create') }}">Criar nova carreira</a></p>
@forelse($careers as $career)
<article class="card">
    <h2><a href="{{ route('careers.show', $career) }}">{{ $career->name }}</a></h2>
    <p>{{ $career->coach_name }} · {{ $career->controlledClub?->club->name }} · Temporada {{ $career->current_season_number }} · {{ $career->current_date->format('d/m/Y') }}</p>
    <a href="{{ route('careers.show', $career) }}">Retomar carreira</a>
</article>
@empty
<p>Você ainda não possui carreiras.</p>
@endforelse
{{ $careers->links() }}
@endsection
