@extends('careers.dashboard-layout')
@section('title', 'Nova carreira — Football Manager Web')
@section('content')
<section class="career-create" aria-labelledby="create-title">
    <div class="career-create-heading">
        <a class="career-back" href="{{ route('careers.index') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Voltar para Minhas Carreiras</a>
        <h1 id="create-title">Iniciar Nova Carreira</h1>
        <p>Configure o perfil da sua temporada e selecione o clube que vai comandar.</p>
    </div>

    @if($clubs->isEmpty())
        <div class="career-errors" role="alert">Não há clubes disponíveis para iniciar uma carreira. Tente novamente mais tarde.</div>
    @else
    <form class="career-create-form" method="post" action="{{ route('careers.store') }}" data-career-form>
        @csrf
        <input type="hidden" name="request_id" value="{{ is_string(old('request_id')) ? old('request_id') : $requestId }}">

        <section class="career-form-panel" aria-labelledby="profile-title">
            <h2 id="profile-title"><span class="career-step" aria-hidden="true">1</span> Dados do Treinador &amp; Salvo</h2>
            <div class="career-fields">
                <div class="career-field">
                    <label for="name">Nome da Carreira <span>(Identificação do Save)</span></label>
                    <div class="career-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
                        <input id="name" name="name" type="text" maxlength="100" value="{{ is_string(old('name')) ? old('name') : '' }}" placeholder="Ex: Rumo ao Topo" autocomplete="off" @if($errors->has('name')) aria-describedby="name-error" aria-invalid="true" @endif required>
                    </div>
                    @error('name')<p class="career-field-error" id="name-error">{{ $message }}</p>@enderror
                </div>
                <div class="career-field">
                    <label for="coach_name">Nome do Treinador</label>
                    <div class="career-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <input id="coach_name" name="coach_name" type="text" maxlength="100" value="{{ is_string(old('coach_name')) ? old('coach_name') : '' }}" placeholder="Ex: Ana Silva" autocomplete="name" @if($errors->has('coach_name')) aria-describedby="coach-error" aria-invalid="true" @endif required>
                    </div>
                    @error('coach_name')<p class="career-field-error" id="coach-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <fieldset class="career-form-panel career-club-fieldset" @if($errors->has('club_template_id')) aria-describedby="club-error" @endif>
            <legend class="career-sr-only">Escolha seu Clube Inicial</legend>
            <div class="career-club-heading">
                <div><h2><span class="career-step" aria-hidden="true">2</span> Escolha seu Clube Inicial</h2><p>Selecione uma das equipes disponíveis no futebol brasileiro para iniciar o projeto.</p></div>
                <span class="career-club-count">{{ $clubs->count() }} clubes disponíveis</span>
            </div>
            @error('club_template_id')<p class="career-field-error" id="club-error">{{ $message }}</p>@enderror
            <div class="career-club-grid">
                @foreach($clubs as $club)
                    <label class="career-club-option" style="--club-color: {{ $club->primary_color }}">
                        <input type="radio" name="club_template_id" value="{{ $club->id }}" @checked(is_scalar(old('club_template_id')) && (string) old('club_template_id') === (string) $club->id) @if($loop->first) required @endif>
                        <span class="career-selected-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg></span>
                        <span class="career-club-option-head"><span class="career-club-badge" aria-hidden="true">{{ $club->short_name }}</span><span><strong>{{ $club->name }}</strong><small>{{ $club->city }}</small></span></span>
                        <span class="career-club-option-details"><span><span>Estádio</span><strong>{{ $club->stadium?->name ?? 'Não informado' }}</strong></span><span><span>Capacidade</span><strong>{{ $club->stadium ? number_format($club->stadium->capacity, 0, ',', '.') : '—' }}</strong></span><span><span>Saldo inicial</span><strong>R$ {{ number_format($club->initial_balance_cents / 100, 2, ',', '.') }}</strong></span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <p class="career-disclaimer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span><strong>Universo Independente:</strong> Cada carreira possui dados próprios da versão {{ $version->version }}. O calendário começa em {{ \Carbon\CarbonImmutable::parse(config('careers.initial_date'))->format('d/m/Y') }} e só avança quando você joga. Os demais clubes são controlados pela IA.</span></p>

        <div class="career-form-actions">
            <a class="career-button career-button-secondary" href="{{ route('careers.index') }}">Cancelar</a>
            <button class="career-button career-button-primary career-submit" type="submit">
                <span class="career-spinner" aria-hidden="true" hidden></span>
                <span data-submit-label>Iniciar Minha Carreira</span>
                <svg class="career-submit-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </button>
        </div>
    </form>
    @endif
</section>
@endsection
