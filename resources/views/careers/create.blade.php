@extends('careers.layout')
@section('title', 'Nova carreira — Football Manager')
@section('content')
<h1>Nova carreira</h1>
@if($clubs->isEmpty())
<p>Não há clubes disponíveis para iniciar uma carreira. Tente novamente mais tarde.</p>
@else
<p>Escolha seu clube. Os demais serão controlados pela IA. O calendário começa em {{ \Carbon\CarbonImmutable::parse(config('careers.initial_date'))->format('d/m/Y') }} e não avança durante sua ausência.</p>
<form method="post" action="{{ route('careers.store') }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
    <label for="name">Nome da carreira</label><input id="name" name="name" maxlength="100" value="{{ old('name') }}" required>
    <label for="coach_name">Nome do treinador</label><input id="coach_name" name="coach_name" maxlength="100" value="{{ old('coach_name') }}" required>
    <label for="club_template_id">Clube</label>
    <select id="club_template_id" name="club_template_id" required>
        <option value="">Selecione um clube</option>
        @foreach($clubs as $club)
        <option value="{{ $club->id }}" @selected((string) old('club_template_id') === (string) $club->id)>{{ $club->name }} — {{ $club->city }}</option>
        @endforeach
    </select>
    <p>Dados-base: {{ $version->version }}. Os dados desta carreira serão independentes das demais.</p>
    <button type="submit">Criar carreira</button>
</form>
@endif
@endsection
