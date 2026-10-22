@extends('careers.dashboard-layout')
@section('title', 'Minhas carreiras — Football Manager Web')
@section('content')
@if($careers->total() === 0)
<section class="career-empty" aria-labelledby="empty-title">
    <div class="career-empty-panel">
        <div class="career-empty-content">
            <div class="career-empty-art" aria-hidden="true">
                <span class="career-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg></span>
                <span class="career-empty-tag">Início de Era</span>
            </div>
            <h1 id="empty-title">Sua jornada à beira do gramado começa agora.</h1>
            <p>Você ainda não possui nenhuma carreira salva. Assuma o controle de um clube, prepare seu elenco e acompanhe cada rodada da temporada.</p>
            <a class="career-button career-button-primary career-empty-cta" href="{{ route('careers.create') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 4v16m8-8H4"/></svg>
                <span>Criar Minha Primeira Carreira</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
        <div class="career-highlights">
            <div class="career-highlight"><span class="career-highlight-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V11M10 19V5M16 19v-9M22 19V7M2 19h20"/></svg></span><div><h2>Simulação Tática</h2><p>Monte formações e escolha as instruções da equipe.</p></div></div>
            <div class="career-highlight"><span class="career-highlight-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l2.8 5.8 6.4.9-4.6 4.5 1.1 6.4L12 17.6l-5.7 3 1.1-6.4-4.6-4.5 6.4-.9z"/></svg></span><div><h2>Campeonato Completo</h2><p>Acompanhe resultados, classificação e artilharia.</p></div></div>
            <div class="career-highlight"><span class="career-highlight-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></span><div><h2>Calendário Dinâmico</h2><p>Seu progresso é salvo e avança quando você joga.</p></div></div>
        </div>
    </div>
</section>
@else
<section class="career-list" aria-labelledby="careers-title">
    <div class="career-section-heading">
        <div>
            <span class="career-eyebrow"><span class="career-dot" aria-hidden="true"></span> Sessão de Gerenciamento</span>
            <h1 id="careers-title">Minhas Carreiras Salvas</h1>
            <p>Retome o comando do seu elenco ou inicie um novo desafio nacional.</p>
        </div>
        <a class="career-button career-button-primary" href="{{ route('careers.create') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 4v16m8-8H4"/></svg> Nova Carreira</a>
    </div>
    @if($careers->isEmpty())
        <div class="career-page-empty"><p>Não há carreiras nesta página.</p><a href="{{ route('careers.index') }}">Voltar à primeira página</a></div>
    @else
    <div class="career-card-grid">
        @foreach($careers as $career)
            @php($club = $career->controlledClub?->club)
            <article class="career-card" style="--club-color: {{ $club?->primary_color ?? '#059669' }}">
                <div class="career-card-top"><span class="career-save-name"><span class="career-dot" aria-hidden="true"></span>{{ $career->name }}</span><span class="career-save-date">Atualizado {{ $career->updated_at->format('d/m/Y') }}</span></div>
                <div class="career-club-info">
                    <span class="career-club-badge" aria-hidden="true">{{ $club?->short_name ?? 'FC' }}</span>
                    <div><h2>{{ $club?->name ?? 'Clube indisponível' }}</h2><p>Treinador: <strong>{{ $career->coach_name }}</strong></p></div>
                </div>
                <dl class="career-metrics">
                    <div><dt>Temporada</dt><dd>{{ $career->current_season_number }}</dd></div>
                    <div><dt>Data no Jogo</dt><dd>{{ $career->current_date->format('d/m/Y') }}</dd></div>
                    <div><dt>Etapa Atual</dt><dd>{{ match($career->state) { \App\Domain\Career\CareerState::PreMatch => 'Pré-jogo', \App\Domain\Career\CareerState::Processing => 'Processando', \App\Domain\Career\CareerState::AwaitingMatch => 'Partida', \App\Domain\Career\CareerState::AwaitingSeason => 'Fim de temporada', default => 'Aguardando decisão' } }}</dd></div>
                    <div><dt>Saldo do Clube</dt><dd>{{ $club ? 'R$ '.number_format($club->balance_cents / 100, 2, ',', '.') : '—' }}</dd></div>
                </dl>
                <a class="career-button career-button-continue" href="{{ route('careers.show', $career) }}">Continuar Carreira <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></a>
            </article>
        @endforeach
    </div>
    @endif
    @if($careers->hasPages())
        {{ $careers->onEachSide(1)->links('careers.pagination') }}
    @endif
</section>
@endif
@endsection
