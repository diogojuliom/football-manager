@extends('careers.layout')
@section('title', 'Partida ao vivo — '.$career->name)
@section('page-label', 'Central da partida')
@section('page-context', 'Rodada '.$match->round)
@section('content')
@php($humanSide = $session->meta['human_side'])
<div class="live-match" data-live-match
    data-control-url="{{ route('careers.matches.live.control', [$career, $match]) }}"
    data-advance-url="{{ route('careers.matches.live.advance', [$career, $match]) }}"
    data-decision-url="{{ route('careers.matches.live.decision', [$career, $match]) }}"
    data-finish-url="{{ route('careers.matches.live.finish', [$career, $match]) }}"
    data-human-side="{{ $humanSide }}">
    <script type="application/json" data-live-payload>@json($payload)</script>
    <script type="application/json" data-live-tactics>@json($tacticalModel)</script>

    <header class="live-scoreboard">
        <div class="live-team home"><span>{{ collect(explode(' ', $match->homeClub->name))->map(fn($word) => mb_substr($word,0,1))->take(3)->implode('') }}</span><strong>{{ $match->homeClub->name }}</strong></div>
        <div class="live-score"><time data-live-clock>0'</time><div><b data-home-score>0</b><em>×</em><b data-away-score>0</b></div><small data-live-phase>Partida pronta</small></div>
        <div class="live-team away"><span>{{ collect(explode(' ', $match->awayClub->name))->map(fn($word) => mb_substr($word,0,1))->take(3)->implode('') }}</span><strong>{{ $match->awayClub->name }}</strong></div>
    </header>

    <section class="live-hydration" data-live-hydration hidden role="status" aria-live="assertive">
        <span aria-hidden="true">💧</span>
        <div><strong>Parada técnica para hidratação</strong><p><b data-live-hydration-minute>30'</b> · Pausa regulamentar de três minutos. Os jogadores estão se hidratando e recebendo orientações.</p></div>
    </section>

    <section class="live-controls" aria-label="Controles da simulação">
        <button type="button" class="primary" data-live-toggle>▶ Continuar</button>
        <label for="live-speed">Velocidade<select id="live-speed" data-live-speed>@foreach(['0.5','0.75','1','1.25','1.5','1.75','2'] as $speed)<option value="{{ $speed }}" @selected($speed==='1')>{{ str_replace('.', ',', $speed) }}×</option>@endforeach</select></label>
        <button type="button" data-live-finish>Simular até o final</button>
        <span role="status" aria-live="polite" data-live-status>Partida pausada. Faça ajustes ou continue.</span>
    </section>

    <div class="live-main-grid">
        <section class="live-pitch-panel">
            <header><div><h2>Seu time em campo</h2><p>Clique em dois titulares para trocar funções ou em titular e reserva para substituir.</p></div><span data-live-formation>{{ $payload['state']['teams'][$humanSide]['tactics']['formation'] }}</span></header>
            <div class="live-pitch" data-live-pitch aria-label="Campo tático do seu clube">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><rect x="1" y="1" width="98" height="98" rx="1"/><path d="M1 50h98M29 1v15h42V1M39 1v7h22V1M29 99V84h42v15M39 99v-7h22v7"/><ellipse cx="50" cy="50" rx="11" ry="8.25"/><circle cx="50" cy="50" r=".65"/></svg>
                <div data-live-lineup></div>
            </div>
            <div class="live-bench"><h3>Banco de reservas</h3><div data-live-bench></div></div>
        </section>

        <aside class="live-side-panel">
            <section class="live-card"><header><h2>Instruções táticas</h2><small>Aplicadas apenas aos minutos seguintes</small></header><form data-live-tactics-form>
                <label>Formação<select name="formation">@foreach(\App\Domain\Tactics\Tactics::FORMATIONS as $formation=>$roles)<option value="{{ $formation }}">{{ $formation }}</option>@endforeach</select></label>
                @foreach($liveOptions as $field=>$options)<label>{{ \App\Domain\Tactics\Tactics::LIVE_LABELS[$field] }}<select name="{{ $field }}">@foreach($options as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>@endforeach
                <button type="submit">Aplicar instruções</button>
            </form></section>
            <section class="live-card"><header><h2>Estatísticas</h2><small>Atualizadas pelo backend</small></header><div class="live-stats" data-live-stats></div></section>
        </aside>
    </div>

    <div class="live-bottom-grid">
        <section class="live-card"><header><h2>Linha do tempo</h2><small>Acontecimentos confirmados</small></header><ol class="live-timeline" data-live-events></ol></section>
        <section class="live-card"><header><h2>Outros jogos da rodada</h2><small>Resultados disponíveis</small></header><div class="round-results">@foreach($sameRound as $roundMatch)<article><span>{{ $roundMatch->homeClub->name }}</span><b>@if($roundMatch->result){{ $roundMatch->result->home_goals }} × {{ $roundMatch->result->away_goals }}@elseif($roundMatch->id===$match->id)<span data-round-live>0 × 0</span>@else— × —@endif</b><span>{{ $roundMatch->awayClub->name }}</span></article>@endforeach</div></section>
    </div>

    <form class="live-finalize" method="post" action="{{ route('careers.matches.live.finalize', [$career, $match]) }}" data-live-finalize {{ $session->status !== 'finished' ? 'hidden' : '' }}>@csrf<button class="primary" type="submit">Registrar resultado e voltar à carreira</button></form>
</div>
@endsection
